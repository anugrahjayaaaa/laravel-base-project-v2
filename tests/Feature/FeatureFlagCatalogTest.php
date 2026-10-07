<?php

namespace Tests\Feature;

use App\Support\FeatureCatalog;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The flag catalogue and the store behind it.
 *
 * The rule this file exists to pin is one the package does not give you for
 * free: **declaring a flag is not activating it.** With the `database` store,
 * a slug with no row in `features` resolves FALSE, so a flag added to config
 * and wired to a route 404s for everyone — superadmin included — until
 * something writes the row. Every test here is about closing that window.
 */
class FeatureFlagCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * This class opts OUT of the baseline every other test starts from.
     *
     * Tests\TestCase seeds every flag ACTIVE, because the route gates added in
     * P7-D7/D8 otherwise 403 any test that visits a module page. That baseline
     * is wrong here: this class exists to assert what an UNSEEDED database does,
     * so `every_catalogued_flag_resolves_off_until_it_is_seeded` fails against a
     * seeded one by design.
     *
     * A test that needs the seeded baseline sets `$seedFlags = true` first —
     * four of them call FeatureFlagSeeder themselves. Opting in per test beats
     * opting out per class: the class default stays the honest empty database.
     */
    /**
     * @var array<int, string> tests that assert an UNSEEDED database
     */
    private const EMPTY_STATE_TESTS = [
        'every_catalogued_flag_resolves_off_until_it_is_seeded',
    ];

    protected function shouldSeedFeatureFlags(): bool
    {
        return ! in_array($this->name(), self::EMPTY_STATE_TESTS, true);
    }

    protected function setUp(): void
    {
        // The parent seeds every flag ACTIVE. Two tests here must see the honest
        // empty database instead, and the choice has to be made BEFORE the parent
        // setUp runs — so it is keyed off the test name rather than a property set
        // inside the test body, which would run too late.
        parent::setUp();
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

        public function every_catalogued_flag_resolves_off_until_it_is_seeded(): void
    {
        // The trap, stated as a test: a fresh database with a full catalogue
        // has every module switched off.
        foreach (FeatureCatalog::slugs() as $slug) {
            $this->assertFalse(
                Feature::active($slug),
                "[{$slug}] resolved active with no row in the store — fail-closed is broken"
            );
        }
    }

        public function seeding_activates_every_flag_and_is_idempotent(): void
    {
        $this->seed(FeatureFlagSeeder::class);

        foreach (FeatureCatalog::slugs() as $slug) {
            $this->assertTrue(Feature::active($slug), "[{$slug}] is still off after seeding");
        }

        $rows = DB::table('features')->count();
        $this->assertSame(count(FeatureCatalog::slugs()), $rows);

        $this->seed(FeatureFlagSeeder::class);
        $this->assertSame($rows, DB::table('features')->count(), 'reseeding duplicated rows');
    }

    /**
     * The one that matters most: `db:seed` is what someone runs when something
     * already looks wrong. A seeder that re-activates everything would silently
     * undo the operator's decision at the worst possible moment.
     */
        public function reseeding_does_not_undo_an_operators_decision(): void
    {
        $this->seed(FeatureFlagSeeder::class);

        Feature::deactivate('users');
        $this->assertFalse(Feature::active('users'), 'precondition: the operator turned it off');

        $this->seed(FeatureFlagSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        $this->assertFalse(
            Feature::active('users'),
            'reseeding re-enabled a flag an operator deliberately switched off'
        );
        $this->assertTrue(Feature::active('roles'), 'reseeding disturbed an untouched flag');
    }

    /**
     * The config kill switch beats a stored `true`.
     *
     * Asserted against `FeatureCatalog::isActive()` — the reader every caller
     * goes through — and NOT against `Feature::active()`. Pennant consults the
     * store when a row exists and never asks the resolver, so on the database
     * driver a stored `true` survives its own kill switch: `Feature::active()`
     * is genuinely unable to express this, and a test written against it would
     * either fail forever or, worse, be weakened until it asserted nothing.
     *
     * The store keeping `true` while the flag reads off is the intended shape:
     * the row records the operator's decision, config overrides it, and
     * removing the `disabled` key restores the operator's answer untouched.
     */
        public function test_a_config_kill_switch_wins_over_a_stored_row (): void
    {
        $this->seed(FeatureFlagSeeder::class);
        Feature::activateForEveryone('users');

        $this->assertTrue(FeatureCatalog::isActive('users'), 'precondition: the flag is on');

        config(['pennant.features.users.disabled' => true]);

        $this->assertFalse(
            FeatureCatalog::isActive('users'),
            '`disabled => true` did not switch the flag off — the key is decorative'
        );

        config(['pennant.features.users.disabled' => false]);
        $this->assertTrue(FeatureCatalog::isActive('users'), 'the flag did not come back');
    }

    /**
     * Global scope, forced in `AppServiceProvider::boot()`.
     *
     * Pennant defaults the scope to the authenticated user. Left that way, each
     * account would get its own answer, the management page would show one
     * user's state and write it for everyone, and the sidebar would disagree
     * with the routes depending on who asked. A kill switch is one switch.
     */
        public function test_the_scope_is_global_not_per_user (): void
    {
        $this->seed(FeatureFlagSeeder::class);

        $this->assertSame(
            ['global'],
            DB::table('features')->distinct()->pluck('scope')->all(),
            'flags are stored per-scope; without resolveScopeUsing they are per-account'
        );
    }

        public function test_an_undeclared_slug_is_never_active (): void
    {
        $this->assertFalse(
            Feature::active('no_such_flag'),
            'an undeclared flag resolved true — the fail-closed default is inverted'
        );
    }

        public function test_the_catalogue_reports_labels_groups_and_descriptions (): void
    {
        $grouped = FeatureCatalog::grouped();

        $this->assertNotEmpty($grouped);
        $this->assertArrayHasKey('Users', $grouped, 'the Users module group is missing');

        foreach (FeatureCatalog::all() as $slug => $row) {
            $this->assertSame($slug, $row['slug']);
            $this->assertNotSame('', $row['label'], "[{$slug}] has no label");
            $this->assertNotSame('', $row['group'], "[{$slug}] has no group");
        }

        $this->assertNull(FeatureCatalog::find('no_such_flag'));
        $this->assertFalse(FeatureCatalog::has('no_such_flag'));
        $this->assertTrue(FeatureCatalog::has('users'));
    }

    /**
     * `registration` is deliberately absent: it is already gated by the
     * `registration_enabled` system setting in four places, and a flag as well
     * would be two writers for one question.
     */
        public function registration_is_not_a_flag_because_it_is_already_a_setting(): void
    {
        $this->assertFalse(
            FeatureCatalog::has('registration'),
            'registration is already enforced by SystemSetting::registration_enabled'
        );

        // Seeded here rather than assumed: the assertion is that the two
        // mechanisms do not overlap, so the SystemSetting side has to actually
        // exist in this database.
        $this->seed(SystemSettingSeeder::class);

        // All three registration settings, not just the enabled one: the point
        // is that `registration%` is already SystemSetting territory, so no
        // flag may claim that namespace.
        $this->assertSame(
            ['registration_default_role', 'registration_enabled', 'registration_rate_limit_per_minute'],
            DB::table('system_settings')->where('key', 'like', 'registration%')->orderBy('key')->pluck('key')->all(),
            'precondition: the settings this would duplicate are still there'
        );
    }
}
