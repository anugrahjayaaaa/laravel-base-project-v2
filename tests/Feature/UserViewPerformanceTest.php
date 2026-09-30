<?php

namespace Tests\Feature;

use App\Models\FailedLoginAttempt;
use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The user pages must not grow a query per row.
 *
 * Measured against the real dev stack (MySQL, database cache/session) the pages
 * cost 6-7 queries and 13-30 ms, so the slowness that prompted this is client
 * side: 656 KB of assets, four of them render-blocking, two from a CDN. That is
 * a bundling problem, not a query problem.
 *
 * These tests therefore pin the thing that WOULD make it slow server-side and
 * is not currently true — an N+1 on the index or the role picker. Query counts
 * are asserted as GROWTH between two dataset sizes rather than as a ceiling, so
 * a ceiling can never be met by a small N+1.
 *
 * `preventLazyLoading` stays ON for the duration: a relation touched in a view
 * without being eager loaded throws instead of quietly costing a query, which is
 * what turns an N+1 into a 500 on the page you are trying to measure.
 */
class UserViewPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventLazyLoading(true);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($this->admin, 'web');
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /**
     * Run a request and return its query count.
     *
     * The throwaway first call pays lazy Blade compilation and the Spatie
     * permission cache refill. Both are one-off, and counting them would make
     * the numbers incomparable between runs.
     */
    private function countQueries(string $uri): int
    {
        $this->get($uri); // warm-up, discarded

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get($uri)->assertOk();

        return $queries;
    }

    /**
     * The index renders a row per user. Ten rows costing the same as thirty is
     * what "no N+1" actually means — an absolute ceiling of, say, 10 queries
     * would happily pass a five-query N+1.
     */
    public function test_the_user_index_query_count_does_not_grow_with_row_count(): void
    {
        User::factory()->count(10)->create();
        $ten = $this->countQueries(route('users.index'));

        User::factory()->count(20)->create();
        $thirty = $this->countQueries(route('users.index'));

        $this->assertSame(
            $ten,
            $thirty,
            "users.index grew from {$ten} to {$thirty} queries — that is an N+1"
        );
    }

    /**
     * The role picker renders a checkbox per role, and a page of 80 roles is 80
     * rows of markup. The roles must be fetched once, not once per role.
     */
    public function test_the_user_edit_form_query_count_does_not_grow_with_role_count(): void
    {
        $subject = User::factory()->create();

        foreach (range(1, 5) as $i) {
            AppRole::create([
                'name' => "Few{$i}",
                'guard_name' => RoleLookup::guard(),
            ]);
        }
        $few = $this->countQueries(route('users.edit', $subject));

        foreach (range(1, 45) as $i) {
            AppRole::create([
                'name' => "Many{$i}",
                'guard_name' => RoleLookup::guard(),
            ]);
        }
        $many = $this->countQueries(route('users.edit', $subject));

        $this->assertSame(
            $few,
            $many,
            "users.edit grew from {$few} to {$many} queries as the picker filled"
        );
    }

    /**
     * The edit page carries one extra read — the failed-login tally shown on it.
     * It is one query on purpose; the point is that it stays one while the
     * attempted-login rows grow underneath it.
     */
    public function test_the_failed_login_tally_does_not_scale_with_attempt_rows(): void
    {
        $subject = User::factory()->create();
        $subject->assignRole($this->makeRole('Alpha'));

        $before = $this->countQueries(route('users.edit', $subject));

        for ($i = 0; $i < 25; $i++) {
            FailedLoginAttempt::for($subject->email, '127.0.0.1')
                ->increment('attempts');
        }

        $after = $this->countQueries(route('users.edit', $subject));

        $this->assertSame(
            $before,
            $after,
            'the tally must stay a single SUM, not grow with the number of rows'
        );
    }

    private function makeRole(string $name): AppRole
    {
        return AppRole::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }
}
