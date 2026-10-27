<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 13 performance measurement — what the security layer costs per request.
 *
 * Follows the Phase 8/9 shape (`SettingsBenchmarkTest`, `NotificationBenchmarkTest`):
 * a warm-up run outside the measured window, then wall clock per arm, results
 * written to `/tmp` for a before/after diff.
 *
 * ## What is measured, and why these four pages
 *
 * Phase 13 added or configured four controls that run on a real request, and each
 * one runs on a different surface:
 *
 *   1. **Headers + CSP** (`AddSecurityHeaders`, global) — every response, and it
 *      generates a nonce per request. `GET /dashboard` is the page a logged-in
 *      user actually loads, so it is the cost that repeats.
 *   2. **CORS** (`HandleCors`, global) — every request, but its work is done only
 *      for a configured origin on an `api/*` path. `GET /api/v1/health` is the
 *      cheapest route that reaches it.
 *   3. **CSRF** (`VerifyCsrfToken`, in the `web` group) — every state-changing
 *      web write. `POST /login` is the accepted-write path with a real query
 *      count behind it.
 *   4. **Throttle** (`ThrottleRequests`, route middleware) — the auth routes, and
 *      the only Phase 13 control that touches a store rather than the DB.
 *
 * Each arms A/B/A: the full stack, the same request with that one middleware
 * replaced by a pass-through, then the full stack again. The second full pass is
 * the noise floor — a delta smaller than the gap between the two full passes is
 * not a measurement, and is reported as such rather than as a win.
 *
 * ## The assertion is the query count, never the clock
 *
 * Latency on in-memory SQLite measures the machine, so it is printed and not
 * asserted (same rule as Phase 8/9). What IS asserted is the property the layer
 * must keep: **it adds no database work**. A security control that reads the DB
 * per request (an IP blocklist, a per-user nonce row, a throttle backed by a
 * table) would put its cost on every page; the count makes that fail here.
 *
 * Each arm is guarded against a vacuous pass: the stripped arm proves the
 * middleware really was removed (no CSP, no allow-origin header, a tokenless
 * write reaching its action), and the counted page must show at least one query
 * so a dead query log cannot read as "free".
 *
 * ## Run it with Xdebug off
 *
 * The machine here loads Xdebug in `debug,develop` mode, which multiplies the
 * cost of every function call — this dev box reported `HandleCors` at 206 µs
 * with it on and 27 µs with it off. Compare numbers only against a run started
 * the same way (`XDEBUG_MODE=off php artisan test …`).
 */
class SecurityOverheadBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** Measured iterations per arm, after one warm-up request. */
    private const RUNS = 10;

    /** @var array<string, array<string, mixed>> */
    protected static array $results = [];

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/phase13_security_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );

        fwrite(STDERR, "\n  → /tmp/phase13_security_benchmark.json\n");
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---------------------------------------------------------------------
    // 1. Headers + CSP — global, every response
    // ---------------------------------------------------------------------

    public function test_the_header_and_csp_layer_costs_nothing_on_an_authenticated_page(): void
    {
        fwrite(STDERR, "\n[Phase 13] headers + CSP — every response\n");

        $this->actingAs(User::factory()->create(['email_verified_at' => now(), 'is_active' => true]));

        $page = fn () => $this->get('/dashboard');

        $full = $this->arm($page);
        $stripped = $this->stripped(AddSecurityHeaders::class, fn () => $this->arm($page));
        $again = $this->arm($page);

        // Control: the strip removed the control being measured, not something else.
        $this->assertTrue($page()->headers->has('Content-Security-Policy'), 'the full stack lost its policy');
        $this->stripped(AddSecurityHeaders::class, function () use ($page): void {
            $this->assertFalse($page()->headers->has('Content-Security-Policy'), 'the stripped arm still had the policy');
        });

        $this->record('headers+csp GET /dashboard full', $full);
        $this->record('headers+csp GET /dashboard stripped', $stripped);
        $this->record('headers+csp GET /dashboard full (2nd)', $again);

        $this->assertGreaterThan(0, $full['queries'], 'the query log is dead, so the count proves nothing');
        $this->assertSame($full['queries'], $stripped['queries'], 'the header layer started touching the database');
    }

    // ---------------------------------------------------------------------
    // 2. CORS — global, but only answers a configured origin on api/*
    // ---------------------------------------------------------------------

    public function test_the_cors_layer_costs_nothing_on_an_api_request(): void
    {
        fwrite(STDERR, "\n[Phase 13] CORS — api/* only\n");

        config(['cors.allowed_origins' => ['https://app.example.com']]);
        $this->withHeader('Origin', 'https://app.example.com');

        $request = fn () => $this->getJson('/api/v1/health');

        $full = $this->arm($request);
        $stripped = $this->stripped(HandleCors::class, fn () => $this->arm($request));
        $again = $this->arm($request);

        // Control: a listed origin gets the grant with the middleware, nothing without it.
        $this->assertSame(
            'https://app.example.com',
            $this->getJson('/api/v1/health')->headers->get('Access-Control-Allow-Origin'),
            'the grant is missing, so the stripped arm proves nothing'
        );
        $this->stripped(HandleCors::class, function (): void {
            $this->assertNull(
                $this->getJson('/api/v1/health')->headers->get('Access-Control-Allow-Origin'),
                'the stripped arm still answered with a grant'
            );
        });

        $this->record('cors GET /api/v1/health full', $full);
        $this->record('cors GET /api/v1/health stripped', $stripped);
        $this->record('cors GET /api/v1/health full (2nd)', $again);

        // Non-vacuity here is the response, not a count: /health is cached to zero
        // queries by design (Phase 11), so a count assertion would be dead.
        $this->assertSame(200, $full['status'], 'the measured request did not succeed');
        $this->assertSame($full['queries'], $stripped['queries'], 'the CORS layer started touching the database');
    }

    // ---------------------------------------------------------------------
    // 3. CSRF — the web group, on an accepted write
    // ---------------------------------------------------------------------

    public function test_the_csrf_layer_costs_nothing_on_an_accepted_write(): void
    {
        fwrite(STDERR, "\n[Phase 13] CSRF — accepted web write\n");

        $write = fn () => $this->loginAttempt('bench-'.uniqid().'@example.com');

        $full = $this->arm($write);
        $stripped = $this->stripped(VerifyCsrfToken::class, fn () => $this->arm($write));
        $again = $this->arm($write);

        // Control: the middleware is what refuses a tokenless write.
        $this->post('/login', ['identifier' => 'nobody@example.com', 'password' => 'x'])->assertStatus(419);
        $this->stripped(VerifyCsrfToken::class, function (): void {
            $this->assertNotSame(419, $this->post('/login', [
                'identifier' => 'nobody@example.com',
                'password' => 'x',
            ])->getStatusCode(), 'the stripped arm still refused a tokenless write');
        });

        $this->record('csrf POST /login full', $full);
        $this->record('csrf POST /login stripped', $stripped);
        $this->record('csrf POST /login full (2nd)', $again);

        $this->assertSame([302], $full['statuses'], 'the measured write did not reach its action');
        $this->assertGreaterThan(0, $full['queries'], 'the query log is dead, so the count proves nothing');
        $this->assertSame($full['queries'], $stripped['queries'], 'CSRF verification started touching the database');
    }

    // ---------------------------------------------------------------------
    // 4. Throttle — route middleware, the one control with a store behind it
    // ---------------------------------------------------------------------

    public function test_the_throttle_layer_costs_nothing_in_the_database(): void
    {
        fwrite(STDERR, "\n[Phase 13] throttle — login attempt\n");

        $attempt = fn () => $this->loginAttempt('bench-'.uniqid().'@example.com');

        $full = $this->arm($attempt);
        $stripped = $this->stripped(ThrottleRequests::class, fn () => $this->arm($attempt));
        $again = $this->arm($attempt);

        // Control: the throttle is what refuses the sixth attempt on one bucket —
        // and the web branch answers `back()->with('rate_limit_seconds')`, NOT a
        // 429, so a status check would pass whether or not the limiter ran. Read
        // the flash the limiter attaches, in both arms.
        $live = $this->sameBucket();
        $this->assertTrue($live->getSession()->has('rate_limit_seconds'), 'the throttle never fired, so the strip control is vacuous');

        $strippedOnce = $this->stripped(ThrottleRequests::class, fn () => $this->sameBucket());
        $this->assertFalse($strippedOnce->getSession()->has('rate_limit_seconds'), 'the stripped arm was still rate limited');

        $this->record('throttle POST /login full', $full);
        $this->record('throttle POST /login stripped', $stripped);
        $this->record('throttle POST /login full (2nd)', $again);

        // A throttle is allowed to cost cache round trips; it is not allowed to
        // add a query per attempt, which is what a table-backed limiter would do.
        $this->assertNotContains(429, $full['statuses'], 'the measurement was truncated by the limiter, not measured');
        $this->assertGreaterThan(0, $full['queries'], 'the query log is dead, so the count proves nothing');
        $this->assertSame($full['queries'], $stripped['queries'], 'the throttle started touching the database');
    }

    // ---------------------------------------------------------------------
    // 5. The same two controls, measured in isolation
    // ---------------------------------------------------------------------

    /**
     * The request-level arms can only say "under the noise floor". This turns
     * that into a number: run each middleware's own `handle()` in a tight loop,
     * so the per-request cost of the two GLOBAL controls is reported in µs and
     * can be compared against the page it is attached to.
     *
     * A loop of 5000 is enough that the fixed cost of the closure around it is
     * negligible, and the µs figure is a ceiling: a real request also pays for
     * the app's own dispatch, which this deliberately leaves out.
     */
    public function test_the_isolated_per_request_cost_of_the_global_middlewares(): void
    {
        fwrite(STDERR, "\n[Phase 13] isolated handle() cost\n");

        // A fresh response per call: `CorsService` APPENDS its `Vary` value, so
        // reusing one makes every iteration copy a longer header list and the
        // loop reports a quadratic artefact as the middleware's cost.
        $response = new Response('ok');
        $pass = fn () => new Response('ok');
        $control = fn () => $response;

        $request = Request::create('/dashboard', 'GET');
        $headers = app(AddSecurityHeaders::class);
        $headers->handle($request, $control);
        $this->assertTrue($response->headers->has('Content-Security-Policy'), 'the measured middleware did not run');

        config(['cors.allowed_origins' => ['https://app.example.com']]);
        $corsRequest = Request::create('/api/v1/health', 'GET', server: ['HTTP_ORIGIN' => 'https://app.example.com']);
        $cors = app(HandleCors::class);
        $cors->handle($corsRequest, $control);
        $this->assertSame(
            'https://app.example.com',
            $response->headers->get('Access-Control-Allow-Origin'),
            'the measured middleware did not run'
        );

        $baseline = $this->us($pass);
        static::$results['isolated baseline'] = ['usPerCall' => $baseline];
        fwrite(STDERR, sprintf("  %-38s %6.2f µs/request\n", 'isolated baseline (new Response)', $baseline));

        foreach ([
            'isolated AddSecurityHeaders' => fn () => $headers->handle($request, $pass),
            'isolated HandleCors' => fn () => $cors->handle($corsRequest, $pass),
        ] as $label => $call) {
            $us = $this->us($call);
            static::$results[$label] = ['usPerCall' => $us];

            fwrite(STDERR, sprintf("  %-38s %6.2f µs/request\n", $label, $us));
        }
    }

    /**
     * Microseconds per call, over a loop long enough that the closure's own fixed
     * cost is noise. The baseline (`new Response`) is measured alongside so the
     * net cost of the middleware can be read off instead of guessed.
     */
    private function us(callable $fn, int $runs = 5000): float
    {
        $fn();

        $start = hrtime(true);

        for ($i = 0; $i < $runs; $i++) {
            $fn();
        }

        return round((hrtime(true) - $start) / 1000 / $runs, 2);
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    /** Gives every measured login attempt its own IP, so no bucket is shared. */
    private int $attempts = 0;

    /**
     * A failed web login on a fresh bucket.
     *
     * The login route carries `throttle:login` (identifier + IP) AND
     * `throttle:login-ip` (IP alone), and the web throttle answers 302 with an
     * error bag rather than 429 — so a repeated identifier would be throttled
     * after five attempts and every later "measurement" would be a redirect that
     * never reached the controller, reported as six queries becoming zero.
     * A fresh address per attempt keeps both buckets empty.
     */
    private function loginAttempt(string $identifier): TestResponse
    {
        $this->attempts++;

        return $this->withServerVariables([
            'REMOTE_ADDR' => '10.'.($this->attempts % 250).'.'.intdiv($this->attempts, 250).'.7',
        ])->withSession(['_token' => 'bench-token'])->post('/login', [
            '_token' => 'bench-token',
            'identifier' => $identifier,
            'password' => 'wrong-on-purpose',
        ]);
    }

    /**
     * One arm: a warm-up outside the window, then RUNS clocked requests, then one
     * logged request for the query count.
     *
     * The count is taken outside the timed loop because enabling the query log
     * inside it would charge both arms for the logger and hide a real difference
     * behind the instrumentation.
     *
     * @return array{minMs: float, medianMs: float, queries: int, status: int, statuses: array<int, int>}
     */
    private function arm(callable $request): array
    {
        $request();

        $times = [];
        $statuses = [];

        for ($i = 0; $i < self::RUNS; $i++) {
            $start = microtime(true);
            $statuses[] = $request()->getStatusCode();
            $times[] = (microtime(true) - $start) * 1000;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $status = $request()->getStatusCode();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        sort($times);

        return [
            'minMs' => round($times[0], 2),
            'medianMs' => round($times[intdiv(count($times), 2)], 2),
            'queries' => $queries,
            'status' => $status,
            'statuses' => array_values(array_unique($statuses)),
        ];
    }

    /**
     * Run a closure with one middleware replaced by a pass-through, then put the
     * real one back — including when the closure throws, so a failed assertion
     * cannot leave the rest of the test measuring a mutant.
     */
    private function stripped(string $middleware, callable $fn): mixed
    {
        $this->app->instance($middleware, new class () {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });

        try {
            return $fn();
        } finally {
            $this->app->forgetInstance($middleware);
        }
    }

    /**
     * Six attempts on ONE identifier from one address, returning the last
     * response. The `login` bucket holds 5 per minute, so the sixth is the one
     * the limiter refuses.
     */
    private function sameBucket(): TestResponse
    {
        $response = null;

        for ($i = 0; $i < 6; $i++) {
            $response = $this->withSession(['_token' => 'bench-token'])->post('/login', [
                '_token' => 'bench-token',
                'identifier' => 'bench-same-bucket@example.com',
                'password' => 'wrong-on-purpose',
            ]);
        }

        return $response;
    }

    /**
     * @param  array{minMs: float, medianMs: float, queries: int, status: int, statuses: array<int, int>}  $r
     */
    private function record(string $label, array $r): void
    {
        static::$results[$label] = $r;

        fwrite(STDERR, sprintf(
            "  %-38s min %6.2f ms  median %6.2f ms  %2d queries\n",
            $label,
            $r['minMs'],
            $r['medianMs'],
            $r['queries'],
        ));
    }
}
