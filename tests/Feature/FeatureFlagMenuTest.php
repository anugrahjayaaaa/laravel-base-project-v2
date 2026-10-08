<?php

namespace Tests\Feature;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\SystemRole;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The sidebar agrees with the routes behind it — P7-D9/D10.
 *
 * Asserted through the rendered dashboard rather than by calling the composer,
 * because the composer's methods are private and a test that reaches past the
 * view to inspect them proves the class works, not that the sidebar does.
 *
 * Superadmin is the case that matters. They pass every `can()`, so a menu
 * filtered on permission alone leaves the item visible to the one person
 * guaranteed to be able to click it and be refused. That is the assertion that
 * fails the moment someone helpfully adds a permission exemption.
 *
 * The pairs cover both directions: an item surviving its flag being switched
 * off, and an item disappearing while the flag is still on. The second is
 * quieter — nobody reports a missing link they never had.
 */
class FeatureFlagMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(FeatureFlagSeeder::class);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function login(string $role = SystemRole::ADMIN): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->assignRole(RoleLookup::find($role));

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * The rendered sidebar.
     *
     * Reuses the already-authenticated user rather than logging in again: a
     * second `actingAs` mid-test swaps the identity, and the composer reads
     * `Auth::user()` — so a flag-off assertion would render a different
     * viewer's menu than the one the precondition established.
     */
    private function sidebar(): string
    {
        return $this->get(route('dashboard'))->assertOk()->getContent();
    }

    /**
     * Just the header dropdown, so an assertion cannot pass on a sidebar link.
     */
    private function headerDropdown(): string
    {
        $html = $this->sidebar();

        // The dropdown is the <ul> holding the Profile / Sessions / Logout items.
        if (! preg_match('#<ul class="dropdown-menu dropdown-menu-end">(.*?)</ul>#s', $html, $m)) {
            $this->fail('the header dropdown was not found in the rendered layout');
        }

        return $m[1];
    }

        #[DataProvider('flaggedItems')]
    public function test_a_disabled_flag_removes_its_menu_item_for_superadmin (string $flag, string $label): void
    {
        $this->login(SystemRole::SUPERADMIN);

        Feature::activate($flag);
        $this->assertStringContainsString(
            $label,
            $this->sidebar(),
            "precondition: $flag is on"
        );

        Feature::deactivate($flag);

        $this->assertStringNotContainsString(
            $label,
            $this->sidebar(),
            "$flag off must hide \"$label\" from the sidebar even for superadmin"
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function flaggedItems(): array
    {
        return [
            'users' => ['users', 'Users'],
            'roles' => ['roles', 'Roles'],
            'permissions' => ['permissions', 'Permissions'],
            'settings' => ['settings', 'Settings'],
            'sessions' => ['sessions', 'Sessions'],
        ];
    }

        public function test_a_permissionless_item_is_hidden_by_its_flag (): void
    {
        // Sessions carries no permission — every authenticated user reaches it —
        // so the flag is the only thing that can remove it. A filter that ran the
        // permission check first and returned early would keep it on screen.
        $this->login();
        Feature::activate('sessions');
        $this->assertStringContainsString('Sessions', $this->sidebar());

        Feature::deactivate('sessions');

        $this->assertStringNotContainsString('Sessions', $this->sidebar());
    }

        public function test_the_header_dropdown_hides_sessions_with_its_flag (): void
    {
        // The header dropdown carries its OWN Sessions link, outside $menuGroups,
        // so filtering the sidebar alone left a live link pointing at a route
        // that now refuses with 403. Found by the permissionless case above, and
        // kept as its own test because the two menus are separate partials: a fix
        // to one does not reach the other.
        $this->login();
        Feature::activate('sessions');
        $this->assertStringContainsString('Sessions', $this->headerDropdown());

        Feature::deactivate('sessions');

        $this->assertStringNotContainsString('Sessions', $this->headerDropdown());
    }

        public function test_an_unflagged_item_survives_every_flag_being_off (): void
    {
        // The converse: gating must not become a blunt instrument. Dashboard
        // names no flag, and Feature Flags must stay reachable or a bad flag
        // leaves nobody able to switch it back on.
        $this->login();

        foreach (FeatureCatalog::slugs() as $slug) {
            Feature::deactivate($slug);
        }

        $html = $this->sidebar();

        $this->assertStringContainsString('Dashboard', $html);
        $this->assertStringContainsString('Feature Flags', $html);
        $this->assertStringNotContainsString('Users', $html);
    }

        public function test_the_features_page_is_never_gated_on_a_flag (): void
    {
        // The trap `routes/web.php` records in a comment: gating the page that
        // re-enables a flag means the switch disappears with the module.
        $this->login(SystemRole::SUPERADMIN);

        foreach (FeatureCatalog::slugs() as $slug) {
            Feature::deactivate($slug);
        }

        $this->get(route('features.index'))->assertOk();
    }
}
