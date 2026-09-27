<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Self-registration performance.
 *
 * Registration is the one unauthenticated write endpoint an attacker can aim
 * at, so this measures the whole POST rather than the action alone: form
 * validation, the unique checks, hashing, the audit log and the notification
 * dispatch all sit inside it.
 *
 * The query ceiling is a real assertion. The existing tests/Benchmark files
 * only time the code and write JSON, which tells you where the time went but
 * fails on nothing — a change that quietly triples the query count still
 * passes, and tests/Benchmark is not in phpunit.xml so it does not even run in
 * CI. Count is also the stable signal here: latency on an in-memory sqlite
 * says more about the machine than about the code, while query count is
 * deterministic. The latency figures are reported, never asserted.
 *
 * Lives in tests/Feature rather than alongside those files so it actually runs.
 *
 * The notification is faked, so the mail transport is not measured; it is
 * ShouldQueue, so a real send is the queue worker's cost, not the request's.
 */
class SelfRegistrationPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ceiling for the happy path. Measured at 9; the headroom absorbs the
     * activity_log and password_history writes without absorbing an N+1.
     */
    private const MAX_QUERIES = 20;

    /** @var array<int, array<string, mixed>> */
    private static array $results = [];

    private function measure(string $label, callable $fn): array
    {
        DB::enableQueryLog();
        $started = hrtime(true);

        $fn();

        $latencyMs = round((hrtime(true) - $started) / 1_000_000, 2);
        $queries = DB::getQueryLog();

        $result = [
            'queries' => count($queries),
            'latency_ms' => $latencyMs,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'slowest' => $this->slowest($queries),
        ];

        DB::disableQueryLog();
        self::$results[$label] = $result;

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $queries
     * @return array<int, array<string, mixed>>
     */
    private function slowest(array $queries): array
    {
        usort($queries, fn ($a, $b) => $b['time'] <=> $a['time']);

        return array_map(
            fn (array $q) => ['ms' => $q['time'], 'sql' => $q['query']],
            array_slice($queries, 0, 5)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $seed = 0): array
    {
        $n = $seed === 0 ? '' : (string) $seed;

        return [
            'name' => 'Budi Santoso'.$n,
            'username' => 'budi'.$n,
            'email' => 'budi'.$n.'@example.test',
            'password' => 'Str0ng!Passw0rd'.$n,
            'password_confirmation' => 'Str0ng!Passw0rd'.$n,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->seed(SystemSettingSeeder::class);

        // The seeder ships registration off on purpose — a base project should
        // not hand every install a public sign-up form. The benchmark needs it
        // on to measure the flow at all.
        SystemSetting::set('registration_enabled', 'true');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/self_registration_benchmark.json',
            json_encode(self::$results, JSON_PRETTY_PRINT)
        );

        parent::tearDownAfterClass();
    }

    #[Test]
    public function test_web_registration_happy_path(): void
    {
        $result = $this->measure('Web POST /register', function () {
            $this->post(route('register.submit'), $this->payload())->assertRedirect();
        });

        $this->assertLessThanOrEqual(
            self::MAX_QUERIES,
            $result['queries'],
            sprintf(
                'Self-registration took %d queries (measured at 9 on the current flow). %s',
                $result['queries'],
                json_encode($result['slowest'])
            )
        );
    }

    /**
     * A duplicate username is the cheapest possible attack: same request, no
     * work done. It must not cost more than the happy path.
     */
    #[Test]
    public function test_rejected_registration_costs_no_more_than_the_happy_path(): void
    {
        User::factory()->create(['username' => 'taken', 'email' => 'taken@example.test']);

        $this->post(route('register.submit'), [
            'name' => 'Impostor',
            'username' => 'taken',
            'email' => 'impostor@example.test',
            'password' => 'Str0ng!Passw0rdX',
            'password_confirmation' => 'Str0ng!Passw0rdX',
        ])->assertSessionHasErrors('username');

        $result = $this->measure('Rejected POST /register', function () {
            $this->post(route('register.submit'), [
                'name' => 'Impostor2',
                'username' => 'taken',
                'email' => 'impostor2@example.test',
                'password' => 'Str0ng!Passw0rdX',
                'password_confirmation' => 'Str0ng!Passw0rdX',
            ])->assertSessionHasErrors('username');
        });

        $this->assertLessThanOrEqual(
            self::MAX_QUERIES,
            $result['queries'],
            "A rejected registration must be cheaper than an accepted one, not dearer."
        );
    }

    /**
     * Hashing is the one deliberately expensive step. The test suite lowers
     * BCRYPT_ROUNDS to 4, so this only proves the work is present and paid
     * once — the production cost is the configured rounds, not this number.
     */
    #[Test]
    public function test_password_hashing_happens_once_per_registration(): void
    {
        $hashes = 0;
        DB::listen(function ($query) use (&$hashes) {
            if (str_contains($query->sql, 'insert into "users"')) {
                $hashes++;
            }
        });

        $this->post(route('register.submit'), $this->payload())->assertRedirect();

        $this->assertSame(1, $hashes, 'registration must insert the user exactly once');

        $user = User::where('username', 'budi')->firstOrFail();
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $user->password));
    }

    /**
     * The endpoint is unauthenticated, so sustained throughput is the number
     * that matters. Reported, not asserted: the absolute value belongs to the
     * machine, and asserting it would make this a flaky test.
     */
    #[Test]
    public function test_sustained_throughput(): void
    {
        // The rate limiter is the point of the next test, not this one: left on,
        // it caps the run at registration_rate_limit_per_minute and the loop
        // measures rejections instead of registrations.
        $this->withoutMiddleware(ThrottleRequests::class);

        $run = 25;
        $started = hrtime(true);

        for ($i = 1; $i <= $run; $i++) {
            $this->post(route('register.submit'), $this->payload($i))->assertRedirect();
        }

        $totalMs = (hrtime(true) - $started) / 1_000_000;
        $perRequest = $totalMs / $run;

        self::$results['Throughput'] = [
            'requests' => $run,
            'total_ms' => round($totalMs, 2),
            'per_request_ms' => round($perRequest, 2),
            'per_second' => (int) round(1000 / max($perRequest, 0.001)),
        ];

        $this->assertSame(
            $run,
            User::where('username', 'like', 'budi%')->where('username', '!=', 'budi')->count(),
            'every request in the run must have created its own account'
        );
    }

    /**
     * A public write endpoint that sends mail is a target, so the ceiling is
     * part of its performance behaviour, not a detail. Left untested, raising
     * registration_rate_limit_per_minute would look like a free speed-up.
     */
    #[Test]
    public function test_the_rate_limit_caps_registrations_per_minute(): void
    {
        $limit = SystemSetting::getInt('registration_rate_limit_per_minute', 3);
        $created = 0;

        // One past the limit: the first $limit must get through, the rest must not.
        for ($i = 1; $i <= $limit + 2; $i++) {
            $this->post(route('register.submit'), $this->payload(100 + $i));
            $created += User::where('username', 'budi'.(100 + $i))->exists() ? 1 : 0;
        }

        $this->assertSame(
            $limit,
            $created,
            sprintf('registration must create exactly %d accounts per minute per IP, no more', $limit)
        );
    }
}
