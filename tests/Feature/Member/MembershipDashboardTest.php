<?php

namespace Tests\Feature\Member;

use App\Actions\V1\Member\MemberAnalyticsStatsAction;
use App\Http\Requests\V1\Member\MemberAnalyticsQueryRequest;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 18 P18-F4: the membership analytics screen over HTTP, and the aggregate
 * behind it against a known population.
 *
 * The fixture half of the render gate lives in `MemberUiRenderTest`, which needs
 * no database. This file is the part that can only be proven with rows: which
 * window each range reads, what each figure counts, and that a cache hit answers
 * with real numbers rather than `__PHP_Incomplete_Class`.
 */
class MembershipDashboardTest extends TestCase
{
    use RefreshDatabase;

    private MemberTier $tier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Feature::flushCache();

        $this->tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));
        $this->actingAs($user);

        return $user;
    }

    private function member(string $number, int $joinedDaysAgo, ?int $signedInDaysAgo): Member
    {
        return Member::factory()->create([
            'member_number' => $number,
            'joined_at' => now()->subDays($joinedDaysAgo),
            'user_id' => User::factory()->create([
                'last_activity_at' => $signedInDaysAgo === null ? null : now()->subDays($signedInDaysAgo),
            ]),
        ]);
    }

    private function period(
        Member $member,
        int $startedDaysAgo,
        int $expiresInDays,
        string $status = MemberSubscription::STATUS_ACTIVE,
        ?int $pausedDaysAgo = null,
    ): void {
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $this->tier->id,
            'status' => $status,
            'starts_at' => now()->subDays($startedDaysAgo),
            // A negative `expiresInDays` is a period that already ended.
            'expires_at' => now()->subDays($expiresInDays),
            'paused_at' => $pausedDaysAgo === null ? null : now()->subDays($pausedDaysAgo),
        ]);
    }

    /**
     * Five members, five different answers to the same questions:
     *
     * | Who  | joined | last sign-in | periods                                        |
     * |------|--------|--------------|------------------------------------------------|
     * | ANN  | 3d     | today        | live since 3d                                  |
     * | BOB  | 40d    | 40d ago      | ended 10d ago, renewed 5d ago → renewal + risk |
     * | CIT  | 200d   | 10d ago      | live since 200d                                |
     * | DAE  | 60d    | never        | live since 60d but PAUSED → counted nowhere    |
     * | EVE  | 400d   | 300d ago     | ended 200d ago → outside the 90d window        |
     *
     * @return array<string, Member>
     */
    private function population(): array
    {
        $ann = $this->member('MBR-ANN', 3, 0);
        $bob = $this->member('MBR-BOB', 40, 40);
        $cit = $this->member('MBR-CIT', 200, 10);
        $dae = $this->member('MBR-DAE', 60, null);
        $eve = $this->member('MBR-EVE', 400, 300);

        $this->period($ann, 3, -362);
        $this->period($bob, 405, 10, MemberSubscription::STATUS_EXPIRED);
        $this->period($bob, 5, -360);
        $this->period($cit, 200, -165);
        $this->period($dae, 60, -305, MemberSubscription::STATUS_ACTIVE, 10);
        $this->period($eve, 400, 200, MemberSubscription::STATUS_EXPIRED);

        return ['ANN' => $ann, 'BOB' => $bob, 'CIT' => $cit, 'DAE' => $dae, 'EVE' => $eve];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(string $range, int $days): array
    {
        return (new MemberAnalyticsStatsAction())->run([
            'range' => $range,
            'from' => now()->subDays($days),
            'to' => now(),
        ]);
    }

    /**
     * Only scalars survive the Redis store; an object comes back incomplete.
     *
     * @param  array<mixed>  $payload
     */
    private function assertPrimitives(array $payload, string $path = 'payload'): void
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $this->assertPrimitives($value, $path.'.'.$key);
                continue;
            }

            $this->assertIsScalar($value, $path.'.'.$key.' is '.get_debug_type($value));
        }
    }

    /** @return list<int> */
    private function column(array $stats, string $column): array
    {
        return array_column($stats['series'], $column);
    }

    public function test_a_reader_without_members_view_is_refused_and_a_disabled_flag_too(): void
    {
        $this->signIn('staff');
        $this->get('/members/dashboard')->assertForbidden();

        $this->signIn('superadmin');
        Feature::deactivate('member_loyalty');
        // D-1: a disabled flag answers 403, not the brief's 404.
        $this->get('/members/dashboard')->assertForbidden();
    }

    public function test_the_screen_renders_the_analytics_sections_with_real_rows(): void
    {
        $this->population();
        $this->signIn('superadmin');

        $this->get('/members/dashboard?range=30d')
            ->assertOk()
            ->assertSee('Membership Analytics', false)
            ->assertSee('New Members vs Expiring Subscriptions', false)
            ->assertSee('Length of Membership', false)
            ->assertSee('Inactive Members', false)
            ->assertSee('MBR-BOB')
            ->assertSee('dateRangeForm', false)
            ->assertDontSee('No active member has been inactive', false);
    }

    public function test_the_cards_answer_from_the_population(): void
    {
        $this->population();
        $this->signIn('superadmin');

        // Renewal 100%: one period ended in the window (BOB's) and one started
        // in it after an expired one (BOB's renewal). Engagement 50%: two members
        // signed in within a month (ANN today, CIT ten days ago), one within a day.
        $this->get('/members/dashboard?range=30d')
            ->assertOk()
            ->assertSee('Renewal Rate', false)
            ->assertSee('100%', false)
            ->assertSee('Daily Engagement', false)
            ->assertSee('50%', false);
    }

    public function test_each_range_reads_its_own_window(): void
    {
        $this->population();

        //          window => [new members, expiring periods]
        $expected = [
            '7d' => [1, 0],
            '30d' => [1, 1],
            '90d' => [3, 1],
            '1y' => [4, 2],
        ];

        foreach ($expected as $range => [$new, $expired]) {
            $stats = $this->stats($range, MemberAnalyticsQueryRequest::PRESETS[$range]);
            $this->assertSame($new, array_sum($this->column($stats, 'new')), $range.' read the wrong joins');
            $this->assertSame($expired, array_sum($this->column($stats, 'expired')), $range.' read the wrong endings');
        }

        // Bucket granularity follows the window: a week is read day by day, a
        // quarter week by week, a year month by month. The bucket COUNT is not
        // asserted — the window ends at `now()`, so a microsecond either side of
        // a boundary adds or drops one bucket.
        $week = $this->stats('7d', 7);
        $quarter = $this->stats('90d', 90);
        $year = $this->stats('1y', 365);

        $this->assertMatchesRegularExpression('/^\d\d \w{3}$/', $week['series'][0]['label']);
        $this->assertMatchesRegularExpression('/^w\/c \d\d \w{3}$/', $quarter['series'][0]['label']);
        $this->assertMatchesRegularExpression('/^\w{3} \d{4}$/', $year['series'][0]['label']);
        $this->assertLessThanOrEqual(14, count($year['series']), 'a year should not be plotted day by day');
    }

    public function test_the_figures_count_a_member_once_and_drop_paused_periods(): void
    {
        $this->population();

        $stats = $this->stats('1y', 365);
        $tenure = array_column($stats['tenure'], 'count', 'label');

        // Every member lands in exactly one tenure band, and EVE's 400-day-old
        // profile is the only one past a year.
        $this->assertSame(5, array_sum($tenure));
        $this->assertSame(
            ['Under 1 month' => 1, '1 – 3 months' => 2, '3 – 6 months' => 0, '6 – 12 months' => 1, 'Over 1 year' => 1],
            $tenure
        );

        // At risk is the live-and-dormant intersection: BOB only. DAE is dormant
        // but paused, so `currentlyActive` — the definition the expiring table
        // already uses — takes them out.
        $this->assertSame(1, $stats['at_risk_count']);
        $this->assertSame(['MBR-BOB'], array_column($stats['at_risk'], 'member_number'));
        $this->assertSame(now()->subDays(40)->format('Y-m-d'), $stats['at_risk'][0]['last_activity']);
    }

    public function test_the_renewal_figure_is_a_renewal_and_not_any_new_period(): void
    {
        $this->population();

        $stats = $this->stats('1y', 365);
        $metrics = array_column($stats['metrics'], 'value', 'label');

        // ANN and CIT also started periods inside the year, but neither followed
        // an expired one: only BOB renewed, out of the two periods that ended.
        $this->assertSame('50%', $metrics['Renewal Rate']);
    }

    public function test_a_period_that_overlaps_the_one_it_replaces_is_not_a_renewal(): void
    {
        // UPG started a new period 40 days ago while the old one still ran until
        // 20 days ago: the two overlapped, so it is an upgrade, not a renewal.
        // Drop the `prior.expires_at <= starts_at` join and this row turns into
        // a renewal — the reason the assertion below is the sabotage gate F4 asks
        // for, and not a restatement of the one above.
        $upg = $this->member('MBR-UPG', 100, 100);
        $this->period($upg, 100, 20, MemberSubscription::STATUS_EXPIRED);
        $this->period($upg, 40, -325);

        $metrics = array_column($this->stats('1y', 365)['metrics'], 'value', 'label');

        $this->assertSame('0%', $metrics['Renewal Rate']);
    }

    public function test_the_engagement_figure_reads_sign_ins_not_membership_rows(): void
    {
        $this->population();

        $metrics = array_column($this->stats('30d', 30)['metrics'], 'value', 'label');

        $this->assertSame('50%', $metrics['Daily Engagement']);
        $this->assertSame('1', $metrics['Inactive Members']);
    }

    public function test_a_cache_hit_returns_the_same_numbers_without_touching_the_database(): void
    {
        $this->population();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $cold = $this->stats('30d', 30);
        $coldQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $warm = $this->stats('30d', 30);
        $warmQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($cold, $warm, 'the cache hit changed the figures');
        $this->assertSame(0, $warmQueries, 'the cached read still queried the database');
        $this->assertPrimitives($cold);
        $this->assertGreaterThan(0, $coldQueries, 'the cold read issued no queries at all');
    }

    public function test_the_keys_the_reader_writes_are_the_keys_the_invalidator_forgets(): void
    {
        $this->population();

        $written = [];
        foreach (array_keys(MemberAnalyticsQueryRequest::PRESETS) as $range) {
            $written[] = 'member_analytics.'.$range;
            $this->stats($range, MemberAnalyticsQueryRequest::PRESETS[$range]);
        }

        $this->assertSame(
            $written,
            MemberAnalyticsStatsAction::cacheKeys(),
            'bustCache() would flush keys nothing reads — or leave the ones that are read'
        );

        foreach ($written as $key) {
            $this->assertTrue(Cache::has($key), $key.' was never written.');
        }
    }

    public function test_an_unknown_or_incomplete_range_never_reaches_the_query(): void
    {
        $this->signIn('superadmin');

        // The Form Request owns the window: a typo is refused rather than read as
        // "no filter", and never interpolated into the aggregate.
        foreach ([
            'range=1000d',
            'range=custom',
            'range=custom&date_from=2026-01-10&date_to=2026-01-01',
            'range=%27%3BDELETE',
        ] as $query) {
            $this->get('/members/dashboard?'.$query)->assertRedirect();
        }

        $this->assertTrue(session()->has('errors'));

        $this->get('/members/dashboard?range=custom&date_from='.now()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertSee('Window: '.now()->toDateString());
    }

    public function test_the_read_is_measured_not_promised(): void
    {
        // Sixty members with a period each: enough that a per-bucket loop would
        // show up, small enough to build inside one test.
        foreach (range(1, 60) as $i) {
            $member = $this->member(
                'MBR-F'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                random_int(1, 300),
                random_int(0, 60)
            );
            $this->period($member, random_int(1, 300), random_int(-300, 300));
        }

        $action = new MemberAnalyticsStatsAction();
        $window = ['range' => '1y', 'from' => now()->subDays(365), 'to' => now()];
        $samples = [];

        foreach (range(1, 10) as $run) {
            Cache::forget('member_analytics.1y');
            $start = hrtime(true);
            $action->run($window);
            $samples[] = (hrtime(true) - $start) / 1e6;
        }

        sort($samples);
        $p95 = $samples[count($samples) - 1];

        $start = hrtime(true);
        $action->run($window);
        $warm = (hrtime(true) - $start) / 1e6;

        fwrite(STDERR, sprintf(
            "\nP18-F2 measured on %d members: cold p95 %.1f ms (brief target 100 ms), cached read %.3f ms\n",
            Member::count(),
            $p95,
            $warm
        ));

        // The figure above goes into the tracker row; what has to hold on every
        // machine is that the cached read is the cheap path.
        $this->assertLessThan(50.0, $warm, 'the cached read was not the fast path');
    }
}
