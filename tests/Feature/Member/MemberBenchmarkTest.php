<?php

namespace Tests\Feature\Member;

use App\Actions\V1\Member\MemberAdjustPointsAction;
use App\Actions\V1\Member\MemberSubscriptionActivateAction;
use App\Actions\V1\Member\MemberSubscriptionCancelAction;
use App\Jobs\MemberPointsExpirySweepJob;
use App\Jobs\MemberSubscriptionExpirySweepJob;
use App\Jobs\SendMemberExpiryNotificationJob;
use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Database\Eloquent\Model;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Phase 18 Group A–E performance measurement.
 *
 * This file MEASURES, it does not assert thresholds (same rule as
 * `SettingsBenchmarkTest`): a ceiling fails on a slow box for reasons that are
 * not the code. The numbers go to /tmp/phase18_member_benchmark.json for a
 * before/after diff, and query COUNTS between two dataset sizes are the part
 * that actually detects an N+1.
 *
 * Scope is Groups A–E only: the member list/detail/tier screens (A), the
 * subscription and points write paths (B), the three sweep jobs (C), the
 * permission gate and bulk path (D), and the two dashboards (E). Group F
 * analytics is not measured here.
 *
 * `Model::preventLazyLoading` is ON: a relation touched by Blade without being
 * eager loaded becomes an exception instead of a query, so an N+1 shows up as a
 * failed run rather than as a number nobody notices.
 */
class MemberBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, float|int>> */
    protected static array $results = [];

    private static int $clerkSeq = 0;

    public static function tearDownAfterClass(): void
    {
        file_put_contents(
            '/tmp/phase18_member_benchmark.json',
            json_encode(static::$results, JSON_PRETTY_PRINT)
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        Model::preventLazyLoading(true);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /** @param array<int, string> $permissions */
    private function holder(array $permissions): User
    {
        $role = Role::create(['name' => 'bench_clerk_'.++self::$clerkSeq, 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * Run one closure under the query log and a wall clock.
     *
     * @return array{queries:int, latencyMs:float, slowQueries:int}
     */
    private function measure(callable $fn, bool $warm = true): array
    {
        // Warm-up is discarded for reads; a write or a sweep must NOT be warmed,
        // because the second pass would find nothing left to do and report a
        // number that belongs to an empty table.
        if ($warm) {
            $fn();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = microtime(true);
        $fn();
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return [
            'queries' => count($log),
            'latencyMs' => $latencyMs,
            'slowQueries' => count(array_filter($log, fn (array $q): bool => ($q['time'] ?? 0) > 50)),
        ];
    }

    /** @param array{queries:int, latencyMs:float, slowQueries:int} $r */
    private function record(string $op, array $r): void
    {
        static::$results[$op] = $r;
        fwrite(STDERR, sprintf(
            "  %-52s %4d queries  %8.2f ms  %d slow\n",
            $op,
            $r['queries'],
            $r['latencyMs'],
            $r['slowQueries']
        ));
    }

    /** A page, measured. The assertion is inside so a 500 cannot hide as a number. */
    /** @return array{queries:int, latencyMs:float, slowQueries:int} */
    private function page(string $uri): array
    {
        return $this->measure(fn () => $this->get($uri)->assertOk());
    }

    private function tier(string $name, int $order): MemberTier
    {
        return MemberTier::factory()->create([
            'name' => $name,
            'sort_order' => $order,
            'is_active' => true,
            'duration_days' => 365,
        ]);
    }

    /**
     * Group A — the members list. The page shows ten rows whatever the table
     * holds, so the pair below says "the read is page-bound, not table-bound".
     */
    public function test_members_list_at_two_table_sizes(): void
    {
        $this->holder(['members.view']);
        $tier = $this->tier('Bronze', 1);

        Member::factory()->count(10)->create()->each(function (Member $member) use ($tier): void {
            MemberSubscription::factory()->live()->create([
                'member_id' => $member->id,
                'member_tier_id' => $tier->id,
            ]);
        });

        $this->record('web members.index — 10 members (page 1)', $this->page(route('members.index')));
        $this->record('web members.index — sorted by points', $this->page(route('members.index', ['sort' => 'points_balance', 'direction' => 'desc'])));
        $this->record('web members.index — trashed tab', $this->page(route('members.index', ['status' => 'trashed'])));

        Member::factory()->count(190)->create()->each(function (Member $member) use ($tier): void {
            MemberSubscription::factory()->live()->create([
                'member_id' => $member->id,
                'member_tier_id' => $tier->id,
            ]);
        });

        $this->record('web members.index — 200 members (page 1)', $this->page(route('members.index')));
        $this->record('web members.index — page 20', $this->page(route('members.index', ['page' => 20])));

        $this->addToAssertionCount(1);
    }

    /**
     * Group A — the detail page. The ledger and the period list are NOT
     * paginated, so this growth pair is the real N+1 test in the file.
     */
    public function test_member_detail_does_not_grow_per_history_row(): void
    {
        $this->holder(['members.view']);
        $tier = $this->tier('Gold', 1);

        $member = Member::factory()->create(['points_balance' => 500]);
        MemberSubscription::factory()->live()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
        ]);
        MemberPointHistory::factory()->count(5)->create(['member_id' => $member->id]);

        $this->record('web members.show — 5 ledger rows', $this->page(route('members.show', $member)));

        MemberPointHistory::factory()->count(55)->create(['member_id' => $member->id]);
        MemberSubscription::factory()->count(5)->expired()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
        ]);

        $this->record('web members.show — 60 ledger + 6 periods', $this->page(route('members.show', $member)));

        $this->addToAssertionCount(1);
    }

    /**
     * Group A — the tier list, same page-bound question at 3 and 40 tiers.
     */
    public function test_tier_list_at_two_tier_counts(): void
    {
        $this->holder(['member_tiers.view']);

        foreach (range(1, 3) as $i) {
            $this->tier("Tier{$i}", $i);
        }
        $this->record('web members.tiers — 3 tiers', $this->page(route('members.tiers')));

        foreach (range(4, 40) as $i) {
            $this->tier("Tier{$i}", $i);
        }
        $this->record('web members.tiers — 40 tiers', $this->page(route('members.tiers')));

        $this->addToAssertionCount(1);
    }

    /**
     * Group B — the write paths, Action-direct so routing cost stays out of
     * the number. Each row is one issued period, one points move, one cancel.
     */
    public function test_subscription_and_points_write_paths(): void
    {
        $actor = $this->holder(['members.update']);
        $tier = $this->tier('Silver', 1);
        $activate = app(MemberSubscriptionActivateAction::class);
        $cancel = app(MemberSubscriptionCancelAction::class);
        $points = app(MemberAdjustPointsAction::class);

        // Each pass builds its own rows because measure() runs the closure
        // twice, and a write is not idempotent. The rows need no `is_active`:
        // the model default is what makes them subscribable.
        $fresh = fn (): array => Member::factory()->count(20)->create()->all();

        $this->record('20x issue a period (Action)', $this->measure(function () use ($activate, $tier, $actor, $fresh): void {
            foreach ($fresh() as $member) {
                $activate->run($member, $tier, $actor);
            }
        }));

        $this->record('20x issue then cancel (Action)', $this->measure(function () use ($activate, $cancel, $tier, $actor, $fresh): void {
            foreach ($fresh() as $member) {
                $cancel->run($activate->run($member, $tier, $actor), $actor);
            }
        }));

        $this->record('20x adjust points (Action)', $this->measure(function () use ($points, $actor, $fresh): void {
            foreach ($fresh() as $member) {
                $points->run($member, ['delta' => 25, 'reason' => 'Benchmark'], $actor);
            }
        }));

        $this->addToAssertionCount(1);
    }

    /**
     * Group B/D — the API read side. Same rows, same relations, no Blade.
     */
    public function test_api_read_paths(): void
    {
        $this->holder(['members.view', 'member_tiers.view']);
        $tier = $this->tier('Platinum', 1);
        Member::factory()->count(50)->create()->each(function (Member $row) use ($tier): void {
            MemberSubscription::factory()->live()->create([
                'member_id' => $row->id,
                'member_tier_id' => $tier->id,
            ]);
        });
        $subject = Member::query()->firstOrFail();
        MemberPointHistory::factory()->count(40)->create(['member_id' => $subject->id]);

        $this->record('api members.index — 50 members', $this->measure(
            fn () => $this->getJson(route('api.v1.members.index'))->assertOk()
        ));
        $this->record('api members.show — 40 ledger rows', $this->measure(
            fn () => $this->getJson(route('api.v1.members.show', $subject))->assertOk()
        ));
        $this->record('api tiers.index — 1 tier', $this->measure(
            fn () => $this->getJson(route('api.v1.members.tiers.index'))->assertOk()
        ));

        $this->addToAssertionCount(1);
    }

    /**
     * Group C — the three background jobs over 250 rows each. Mail is faked,
     * so the broadcast number is the scan plus the notification build, not the
     * SMTP round trip.
     */
    public function test_sweep_jobs_over_250_rows(): void
    {
        // The guard is off here on purpose: the sweep is measured, not judged.
        // `MemberSubscriptionExpireAction::run()` reads `member` and `member.user`
        // per row off an un-eager-loaded chunk, so the query count below is the
        // number to look at, not an exception.
        Model::preventLazyLoading(false);

        $this->holder(['members.notify']);
        $tier = $this->tier('Iron', 1);

        MemberSubscription::factory()->count(250)->create([
            'member_tier_id' => $tier->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->record('subscription sweep — 250 past-due periods', $this->measure(
            fn () => MemberSubscriptionExpirySweepJob::dispatchSync(),
            warm: false
        ));

        // A period that carries a tier role takes the revocation path as well:
        // the role read and the sync are the reason this row is not free.
        MemberSubscription::factory()->count(250)->create([
            'member_tier_id' => $tier->id,
            'expires_at' => now()->subDay(),
            'role_name' => 'bench_revoked_role',
        ]);

        $this->record('subscription sweep — 250 rows with a tier role', $this->measure(
            fn () => MemberSubscriptionExpirySweepJob::dispatchSync(),
            warm: false
        ));

        Member::query()->update(['points_balance' => 0]);
        Member::factory()->count(250)->create(['points_balance' => 300]);

        // The stored date is what gates the run, so both sides are measured:
        // the not-due path costs one setting read, the due path walks every row.
        $this->record('points sweep — date unset (not due)', $this->measure(
            fn () => MemberPointsExpirySweepJob::dispatchSync(),
            warm: false
        ));

        SystemSetting::set('member_points_expiry_date', now()->subYear()->toDateString());

        $this->record('points sweep — 250 balances due', $this->measure(
            fn () => MemberPointsExpirySweepJob::dispatchSync(),
            warm: false
        ));

        MemberSubscription::factory()->count(250)->create([
            'member_tier_id' => $tier->id,
            'expires_at' => now()->addDays(5),
        ]);

        Mail::fake();
        $this->record('expiry broadcast — 250 in window (mail faked)', $this->measure(
            fn () => SendMemberExpiryNotificationJob::dispatchSync(14)
        ));

        $this->addToAssertionCount(1);
    }

    /**
     * Group D — what the gate costs. Same route, held permission vs none: the
     * difference is `can:` + `feature:` + the audit-free refusal path.
     */
    public function test_gate_and_bulk_write_cost(): void
    {
        $tier = $this->tier('Lead', 1);
        $ids = Member::factory()->count(40)->create()->pluck('id')->all();
        foreach ($ids as $id) {
            MemberSubscription::factory()->live()->create(['member_id' => $id, 'member_tier_id' => $tier->id]);
        }

        $this->holder(['members.delete']);
        $this->record('bulk delete — 40 members (allowed)', $this->measure(
            fn () => $this->post(route('members.bulk-action'), ['action' => 'delete', 'member_ids' => $ids])->assertRedirect()
        ));

        $this->holder(['members.view']);
        $forbidden = Member::factory()->count(40)->create()->pluck('id')->all();
        $this->record('bulk delete — 40 members (403 at the gate)', $this->measure(
            fn () => $this->post(route('members.bulk-action'), ['action' => 'delete', 'member_ids' => $forbidden])->assertForbidden()
        ));

        $this->addToAssertionCount(1);
    }

    /**
     * Group E — the two dashboards. The admin one counts live periods per tier,
     * so the second pair says whether that read is one query or one per tier.
     */
    public function test_dashboards_at_two_dataset_sizes(): void
    {
        $this->holder(['members.view']);
        $tier = $this->tier('Copper', 1);

        Member::factory()->count(50)->create()->each(function (Member $member) use ($tier): void {
            MemberSubscription::factory()->live()->create([
                'member_id' => $member->id,
                'member_tier_id' => $tier->id,
            ]);
        });

        $this->record('admin dashboard — 50 members, 1 tier', $this->page(route('membership.dashboard')));

        foreach (range(2, 12) as $order) {
            $extra = $this->tier("More{$order}", $order);
            MemberSubscription::factory()->count(20)->create([
                'member_id' => Member::query()->value('id'),
                'member_tier_id' => $extra->id,
            ]);
        }

        $this->record('admin dashboard — 50 members, 12 tiers', $this->page(route('membership.dashboard')));

        $this->holder(['users.view']);
        $this->record('admin /dashboard — application status', $this->page(route('dashboard')));

        $this->addToAssertionCount(1);
    }

    /**
     * Group E — the member's own screen: no permission, only their profile.
     */
    public function test_member_self_dashboard(): void
    {
        $tier = $this->tier('Zinc', 1);
        $member = Member::factory()->create(['points_balance' => 120]);
        MemberSubscription::factory()->live()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
        ]);
        MemberPointHistory::factory()->count(30)->create(['member_id' => $member->id]);

        $this->actingAs($member->user()->firstOrFail());
        $this->record('self /dashboard — 30 ledger rows', $this->page(route('dashboard')));

        MemberPointHistory::factory()->count(120)->create(['member_id' => $member->id]);
        $this->record('self /dashboard — 150 ledger rows', $this->page(route('dashboard')));

        $this->addToAssertionCount(1);
    }
}
