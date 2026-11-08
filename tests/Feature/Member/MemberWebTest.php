<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Models\MemberPointHistory;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\RoleLookup;
use App\Models\User;
use App\Http\Middleware\VerifyCsrfToken;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\TestCase;

/**
 * Phase 18 read-only slice over HTTP: the member screens answer with real
 * data behind the real gates.
 *
 * The Group A suite renders fixtures; this proves the routes, the `can:`
 * gates, the `feature:member_loyalty` kill switch and the sidebar visibility
 * all agree — which is what D-4 withheld until the routes existed.
 */
class MemberWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The same four the standing authorization suites seed: roles and
        // permissions for the gate, the settings the web middleware stack
        // reads (without them the password-expiry guard redirects every
        // request before the member routes are reached), and the flags.
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        Feature::flushCache();
    }

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));
        $this->actingAs($user);

        return $user;
    }

    /**
     * The sanction fields are the member page's own. They live in the confirm
     * modal, and that modal partial is rendered by `layouts/app.blade.php` for
     * every page — which is how a membership-only control ended up in the
     * feature-flag and notification screens' HTML and turned two read-only
     * render gates red (P18-E2). The fields arrive by `@push`, so the gate is
     * "here, and nowhere else".
     */
    public function test_the_deactivation_fields_ship_only_on_the_member_list(): void
    {
        $this->signIn('superadmin');

        $this->get('/members')->assertOk()->assertSee('confirmModalViolation', false);

        $this->get('/users')->assertOk()->assertDontSee('confirmModalViolation', false);
        $this->get(route('features.index'))->assertOk()->assertDontSee('confirmModalViolation', false);
    }

    /** @return array{0: Member, 1: MemberTier} */
    private function memberWithHistory(): array
    {
        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $member = Member::factory()->create(['member_number' => 'MBR-000100', 'points_balance' => 250]);
        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
            'role_name' => 'gold-member',
        ]);
        MemberPointHistory::factory()->create([
            'member_id' => $member->id,
            'delta' => 250,
            'balance_after' => 250,
            'reason' => 'Welcome bonus',
        ]);

        return [$member, $tier];
    }

    public function test_superadmin_reaches_every_member_screen_with_real_data(): void
    {
        [$member] = $this->memberWithHistory();
        $this->signIn('superadmin');

        $this->get('/members')->assertOk()->assertSee('MBR-000100');
        $this->get('/members/tiers')->assertOk()->assertSee('Gold');
        $this->get('/members/dashboard')->assertOk()->assertSee('Points Issued');
        $this->get('/members/'.$member->id)->assertOk()->assertSee('Welcome bonus');
    }

    public function test_the_bulk_and_trash_affordances_are_live(): void
    {
        [$member] = $this->memberWithHistory();
        $this->signIn('superadmin');

        // A superadmin passes every Gate — so the only proof the guards are
        // URL-driven, not permission-driven, is taken at full privilege.
        $this->get('/members')
            ->assertOk()
            ->assertSee('Create Member')
            ->assertSee('data-bulk-route', false);
        $this->get('/members/tiers')->assertOk()->assertSee('Create Tier');
        $this->get('/members/dashboard')->assertOk()->assertSee('Notify Expiring Members');
        // Points have no web form by owner decision 2026-11-06: Phase 19's
        // checkout moves the balance. This member holds a live period, so the
        // subscribe card is correctly absent too — the points card is the one
        // that must never come back.
        $this->get('/members/'.$member->id)->assertOk()->assertDontSee('Adjust Points');
    }

    /**
     * P18-D1's acceptance, whole: staff holds zero permissions, so every
     * address the module registers — reads, writes, bulk, trash — must 403.
     * A hand-checked screen proves one route; the loop proves the table.
     */
    public function test_a_caller_with_no_permission_is_refused_on_every_member_route(): void
    {
        // Same opt-out the standing member POST suites use: the gate is the
        // subject here, the session token is not.
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->signIn('staff');
        $addresses = $this->addresses();

        foreach ($this->memberRoutes() as [$method, $name]) {
            $this->json($method, route($name, $addresses))->assertForbidden();
        }
    }

    /**
     * P18-D2's web half, whole: the flag answers 403 (not the brief's 404,
     * D-1) on every route for the one role that normally bypasses every gate.
     */
    public function test_the_flag_refuses_even_a_superadmin_on_every_member_route(): void
    {
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->signIn('superadmin');
        Feature::deactivate('member_loyalty');
        Feature::flushCache();
        $addresses = $this->addresses();

        foreach ($this->memberRoutes() as [$method, $name]) {
            $this->json($method, route($name, $addresses))->assertForbidden();
        }
    }

    public function test_the_sidebar_offers_members_only_to_a_viewer_who_may(): void
    {
        // Every label, not just the first: an entry pointing at a route name
        // nobody registered is dropped silently, and `GateDUiGatingTest` only
        // proved it after three entries shipped rather than two.
        $this->signIn('staff');
        $this->get('/dashboard')->assertOk()
            ->assertDontSee('Members')
            ->assertDontSee('Tiers')
            ->assertDontSee('Membership Dashboard');

        $this->signIn('superadmin');
        $this->get('/dashboard')->assertOk()
            ->assertSee('Members')
            ->assertSee('Tiers')
            ->assertSee('Membership Dashboard');
    }

    /**
     * One highlighted entry per page. `members.*` is true for `members.tiers`
     * too, so the Tiers screen used to light up Members beside it — the sidebar
     * claiming the operator was on two screens at once.
     */
    public function test_exactly_one_membership_entry_highlights_per_page(): void
    {
        $this->signIn('superadmin');

        [$member, $tier] = $this->memberWithHistory();

        foreach ([
            '/members',
            '/members/tiers',
            '/members/tiers/create',
            '/members/tiers/'.$tier->id.'/edit',
            '/members/dashboard',
            '/members/'.$member->id,
        ] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();

            // Scoped to the sidebar on purpose: the list screens now carry their
            // own `nav-link active` tab, and counting those would answer "two
            // highlighted items" for a page whose sidebar is correct.
            $start = strpos($html, '<aside class="app-sidebar');
            $sidebar = substr($html, $start, strpos($html, '</aside>', $start) - $start);

            $this->assertSame(
                1,
                substr_count($sidebar, 'nav-link active'),
                "not exactly one active sidebar item on {$uri}"
            );
        }
    }

    /**
     * Every web route the module registers, method and name together. The
     * table and `routes/web.php` are supposed to be the same list — this is
     * the test that keeps them that way.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function memberRoutes(): array
    {
        return [
            ['GET', 'members.index'],
            ['GET', 'members.create'],
            ['POST', 'members.store'],
            ['GET', 'members.show'],
            ['POST', 'members.subscribe'],
            ['POST', 'members.subscriptions.cancel'],
            ['POST', 'members.activate'],
            ['POST', 'members.deactivate'],
            ['DELETE', 'members.destroy'],
            ['POST', 'members.restore'],
            ['DELETE', 'members.force-delete'],
            ['POST', 'members.bulk-action'],
            ['GET', 'members.tiers'],
            ['GET', 'members.tiers.create'],
            ['POST', 'members.tiers.store'],
            ['GET', 'members.tiers.edit'],
            ['PUT', 'members.tiers.update'],
            ['POST', 'members.tiers.activate'],
            ['POST', 'members.tiers.deactivate'],
            ['DELETE', 'members.tiers.destroy'],
            ['POST', 'members.tiers.restore'],
            ['DELETE', 'members.tiers.force-delete'],
            ['POST', 'members.tiers.bulk-action'],
            ['GET', 'membership.dashboard'],
            ['POST', 'membership.broadcast-expiry'],
        ];
    }

    /**
     * Real rows for the parameterised routes: a missing id answers 404 from
     * the binding before the gate is consulted, which would prove nothing.
     *
     * @return array<string, int>
     */
    private function addresses(): array
    {
        [$member, $tier] = $this->memberWithHistory();

        return [
            'member' => $member->id,
            'subscription' => $member->subscriptions()->first()->id,
            'tier' => $tier->id,
        ];
    }
}
