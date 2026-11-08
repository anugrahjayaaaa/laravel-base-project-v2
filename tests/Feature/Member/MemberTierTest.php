<?php

namespace Tests\Feature\Member;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\MemberTierSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The tier write screens (Phase 18, P18-B6).
 *
 * Three things this proves beyond "the form saves": the role a tier may carry
 * never includes `superadmin` (a tier role is synced onto the account on
 * activation, so offering it would sell the app), a tier in use cannot be
 * deleted, and every write leaves exactly one audit row.
 */
class MemberTierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        Feature::flushCache();
    }

    private function actingAsHolderOf(array $permissions): User
    {
        $role = Role::create(['name' => 'tier_clerk', 'guard_name' => RoleLookup::guard()]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->syncRoles([$role->name]);

        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function tierPayload(array $overrides = []): array
    {
        // `array_merge`, not `+`: the union operator keeps the left-hand
        // (default) key, so an override would silently do nothing.
        return array_merge([
            'name' => 'Platinum',
            'slug' => 'platinum',
            'role_name' => null,
            'price' => 250000,
            'duration_days' => 180,
            'point_multiplier' => 3,
            'is_active' => 1,
            'sort_order' => 4,
        ], $overrides);
    }

    public function test_a_manager_creates_a_tier_and_the_row_is_audited_once(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);

        $this->post(route('members.tiers.store'), $this->tierPayload())
            ->assertRedirect(route('members.tiers'));

        $tier = MemberTier::where('slug', 'platinum')->firstOrFail();
        $this->assertSame('250000.00', (string) $tier->price);

        $this->assertSame(1, Activity::where('event', 'member_tier.created')->count());
    }

    /**
     * The slug comes from the name, never from the payload: the form field is
     * disabled, and an integration key that a hand-crafted POST could set to
     * anything is not a key.
     */
    public function test_the_slug_is_generated_from_the_name_and_the_submitted_one_is_ignored(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);

        $this->post(route('members.tiers.store'), $this->tierPayload([
            'name' => 'Gold Tier',
            'slug' => 'whatever-i-typed',
        ]))->assertRedirect(route('members.tiers'));

        $this->assertDatabaseHas('member_tiers', ['name' => 'Gold Tier', 'slug' => 'gold-tier']);
    }

    public function test_renaming_a_tier_moves_its_slug_with_it(): void
    {
        $this->actingAsHolderOf(['member_tiers.update']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);

        $this->put(route('members.tiers.update', $tier), $this->tierPayload([
            'name' => 'Gold Classic',
        ]))->assertRedirect(route('members.tiers'));

        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'slug' => 'gold-classic']);
    }

    public function test_a_name_that_generates_nothing_is_refused_on_the_name(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);

        $this->from(route('members.tiers.create'))
            ->post(route('members.tiers.store'), $this->tierPayload(['name' => '   ']))
            ->assertSessionHasErrors(['name', 'slug']);

        $this->assertDatabaseCount('member_tiers', 0);
    }

    public function test_the_role_picker_offers_no_superadmin_on_create_or_edit(): void
    {
        $this->actingAsHolderOf(['member_tiers.create', 'member_tiers.update']);

        // RoleSeeder creates it, so its absence in the markup is the filter's
        // doing and not a missing row.
        $this->assertNotNull(RoleLookup::find('superadmin'));

        $this->get(route('members.tiers.create'))->assertOk()->assertDontSee('value="superadmin"', false);

        $tier = MemberTier::factory()->create();
        $this->get(route('members.tiers.edit', $tier))->assertOk()->assertDontSee('value="superadmin"', false);
    }

    public function test_a_payload_naming_superadmin_is_refused(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);

        $this->from(route('members.tiers.create'))
            ->post(route('members.tiers.store'), $this->tierPayload(['role_name' => 'superadmin']))
            ->assertSessionHasErrors('role_name');

        $this->assertDatabaseMissing('member_tiers', ['slug' => 'platinum']);
    }

    public function test_a_manager_edits_a_tier_and_only_the_changed_fields_are_audited(): void
    {
        $this->actingAsHolderOf(['member_tiers.update']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold', 'duration_days' => 100]);

        $this->put(route('members.tiers.update', $tier), $this->tierPayload([
            'name' => 'Gold',
            'slug' => 'gold',
            'duration_days' => 120,
        ]))->assertRedirect(route('members.tiers'));

        $this->assertSame(120, $tier->fresh()->duration_days);

        // `properties` and not `getChangedProperties()`: the audit payload is the
        // house `field => [from, to]` shape `user.updated` writes, which that
        // Spatie accessor only understands for its own `attributes`/`old` form.
        $properties = Activity::where('event', 'member_tier.updated')->firstOrFail()->properties->toArray();

        $this->assertArrayHasKey('duration_days', $properties);
        $this->assertArrayNotHasKey('slug', $properties, 'an unchanged field was reported as edited');
    }

    public function test_the_list_prints_the_price_in_the_configured_currency(): void
    {
        $this->actingAsHolderOf(['member_tiers.view', 'member_tiers.create']);

        $this->post(route('members.tiers.store'), $this->tierPayload(['name' => 'Bronze', 'slug' => 'bronze', 'price' => 35000]));

        // `currency_code` is IDR from the settings seeder and IDR's row is
        // `Rp` with `.` thousands — the convention a reader of this app writes.
        $this->get(route('members.tiers'))->assertOk()->assertSee('Rp 35.000,00', false);
    }

    public function test_the_tier_list_searches_and_paginates_like_the_other_lists(): void
    {
        $this->actingAsHolderOf(['member_tiers.view']);

        MemberTier::factory()->create(['name' => 'Diamond', 'slug' => 'diamond']);
        MemberTier::factory()->count(11)->create();

        $this->get(route('members.tiers', ['search' => 'Diamond']))
            ->assertOk()
            ->assertSee('Diamond')
            ->assertSee('value="Diamond"', false);

        // 12 rows, 10 per page: a second page link and the full total.
        $this->get(route('members.tiers'))
            ->assertOk()
            ->assertSee('12 entries', false)
            ->assertSee('page=2', false);
    }

    public function test_the_default_seeder_lands_three_tiers_at_the_documented_prices(): void
    {
        $this->seed(MemberTierSeeder::class);

        $this->assertSame(
            [
                ['bronze', 30, '35000.00'],
                ['silver', 60, '70000.00'],
                ['gold', 100, '100000.00'],
            ],
            MemberTier::orderBy('sort_order')
                ->get()
                ->map(fn (MemberTier $t): array => [$t->slug, $t->duration_days, (string) $t->price])
                ->all(),
        );

        // Re-seeding must not duplicate, and must not die on the unique slug of a
        // row the operator deleted.
        MemberTier::where('slug', 'gold')->firstOrFail()->delete();
        $this->seed(MemberTierSeeder::class);

        $this->assertSame(3, MemberTier::withTrashed()->count());
        $this->assertFalse(MemberTier::withTrashed()->where('slug', 'gold')->firstOrFail()->trashed());
    }

    public function test_the_delete_permission_trashes_an_unused_tier(): void
    {
        $this->actingAsHolderOf(['member_tiers.delete']);

        $tier = MemberTier::factory()->create(['name' => 'Bronze', 'slug' => 'bronze']);

        $this->delete(route('members.tiers.destroy', $tier))->assertRedirect(route('members.tiers'));

        $this->assertNull(MemberTier::find($tier->id));
        $this->assertTrue(Activity::where('event', 'member_tier.deleted')->exists());
    }

    public function test_a_tier_with_subscriptions_on_it_refuses_to_be_deleted(): void
    {
        $this->actingAsHolderOf(['member_tiers.delete']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $member = Member::factory()->create();

        MemberSubscription::factory()->create([
            'member_id' => $member->id,
            'member_tier_id' => $tier->id,
        ]);

        $this->from(route('members.tiers'))
            ->delete(route('members.tiers.destroy', $tier))
            ->assertSessionHasErrors('name');

        $this->assertNotNull(MemberTier::find($tier->id), 'a tier in use was trashed');
        $this->assertFalse(Activity::where('event', 'member_tier.deleted')->exists());
    }

    public function test_a_trashed_slugs_name_is_still_taken_and_the_form_says_so(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);
        $tier->delete();

        // The unique index does not forget a trashed row, so a check scoped to
        // live rows would answer "valid" and let the INSERT throw.
        $this->post(route('members.tiers.store'), $this->tierPayload(['name' => 'Gold', 'slug' => 'gold']))
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, MemberTier::withTrashed()->count());
    }

    public function test_a_viewer_cannot_reach_any_tier_write_route(): void
    {
        $this->actingAsHolderOf(['member_tiers.view']);

        $tier = MemberTier::factory()->create();

        $this->get(route('members.tiers.create'))->assertForbidden();
        $this->get(route('members.tiers.edit', $tier))->assertForbidden();
        $this->post(route('members.tiers.store'), $this->tierPayload())->assertForbidden();
        $this->put(route('members.tiers.update', $tier), $this->tierPayload())->assertForbidden();
        $this->delete(route('members.tiers.destroy', $tier))->assertForbidden();

        $this->assertDatabaseHas('member_tiers', ['id' => $tier->id, 'deleted_at' => null]);
    }

    /**
     * The edit page owns a Danger Zone, the create page does not: there is no row
     * to trash before the first save, and a card with a dead button in it reads as
     * a feature that broke rather than one that does not apply.
     */
    public function test_the_edit_page_offers_the_trash_button_and_the_create_page_does_not(): void
    {
        $this->actingAsHolderOf(['member_tiers.create', 'member_tiers.update', 'member_tiers.delete']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold']);

        $this->get(route('members.tiers.edit', $tier))
            ->assertOk()
            ->assertSee('Danger Zone')
            ->assertSee('data-action="'.route('members.tiers.destroy', $tier).'"', escape: false);

        $this->get(route('members.tiers.create'))
            ->assertOk()
            ->assertDontSee('Danger Zone', false);
    }

    public function test_the_flag_refuses_the_tier_writes_too(): void
    {
        $this->actingAsHolderOf(['member_tiers.create']);
        Feature::deactivate('member_loyalty');

        $this->get(route('members.tiers.create'))->assertForbidden();
        $this->post(route('members.tiers.store'), $this->tierPayload())->assertForbidden();
    }

    /**
     * The Active box also sits on the edit form, so without this guard saving a
     * price retired the tier — `member_tiers.deactivate` existed as a permission
     * nothing ever checked.
     */
    public function test_saving_a_tier_cannot_change_its_active_state_without_the_deactivate_permission(): void
    {
        $this->actingAsHolderOf(['member_tiers.update']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold', 'is_active' => true]);

        $this->put(route('members.tiers.update', $tier), $this->tierPayload([
            'name' => 'Gold',
            'slug' => 'gold',
            'is_active' => 0,
        ]))->assertSessionHasErrors('is_active');

        $this->assertTrue(MemberTier::find($tier->id)->is_active);
    }

    public function test_saving_a_tier_may_change_its_active_state_with_the_deactivate_permission(): void
    {
        $this->actingAsHolderOf(['member_tiers.update', 'member_tiers.deactivate']);

        $tier = MemberTier::factory()->create(['name' => 'Gold', 'slug' => 'gold', 'is_active' => true]);

        $this->put(route('members.tiers.update', $tier), $this->tierPayload([
            'name' => 'Gold',
            'slug' => 'gold',
            'is_active' => 0,
        ]))->assertRedirect(route('members.tiers'));

        $this->assertFalse(MemberTier::find($tier->id)->is_active);
    }
}
