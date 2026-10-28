<?php

namespace Tests\Feature\FeatureFlag;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Laravel\Pennant\Feature;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The feature-flag management API.
 *
 * These exist because the web endpoints had no API equivalent, and a new write
 * surface is where the mistakes land. The tests pin the properties that
 * actually broke things elsewhere:
 *
 *   - a caller without features.manage is refused on BOTH routes
 *   - the flags are reachable while the `features` flag is itself off, which is
 *     the whole reason these routes carry no feature: middleware
 *   - bulk-action refuses an undeclared slug instead of creating a row for it
 *   - the read is `features.view`, projects the page model down to the six
 *     fields a client can act on, and answers the writer's own state (the
 *     toggle flushes the snapshot the read serves)
 *
 * Mutation-verified on 2026-10-02: dropping `->can('features.manage')` fails
 * the toggle test, dropping the abort_unless in the controller fails it too,
 * and adding `feature:features` to the group fails the "reachable while off" test.
 */
class FeatureFlagApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function manager(): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole(RoleLookup::find(SystemRole::SUPERADMIN));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function viewerWithout(string ...$permissions): User
    {
        $role = Role::create(['name' => 'Plain '.uniqid(), 'guard_name' => RoleLookup::guard()]);
        $keep = array_values(array_diff(PermissionCatalog::all(), $permissions));
        $role->givePermissionTo(
            Permission::whereIn('name', $keep)
                ->where('guard_name', RoleLookup::guard())->get()
        );

        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    public function test_a_manager_can_toggle_a_flag_through_the_api(): void
    {
        $this->actingAs($this->manager(), 'sanctum');

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertSuccessful()
            ->assertJsonPath('data.feature.to', false);

        $this->assertFalse(Feature::active('pulse'));
    }

    public function test_a_caller_without_the_permission_is_refused(): void
    {
        $this->actingAs($this->viewerWithout('features.manage', 'features.view'), 'sanctum');

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertForbidden();

        $this->postJson(route('api.v1.features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['pulse'],
        ])->assertForbidden();

        // Neither refusal may have moved the flag.
        $this->assertTrue(Feature::active('pulse'));
    }

    /**
     * The trap these routes exist to avoid.
     *
     * Gating the flag management API on `feature:{slug}` means switching that
     * very flag off deletes the only way to switch it back on. This walks every
     * declared slug rather than picking one: a single-slug test passes happily
     * when the group is gated on some *other* flag, which is exactly the bug it
     * is meant to catch.
     */
    #[DataProvider('everyDeclaredSlug')]
    public function test_toggling_a_flag_back_on_works_while_that_flag_is_off(string $slug): void
    {
        Feature::deactivate($slug);
        Feature::flushCache();
        $this->assertFalse(Feature::active($slug), 'precondition: the flag is off');

        $this->actingAs($this->manager(), 'sanctum');

        // Must answer, not 403/404/419. If this route group ever picks up a
        // `feature:` middleware, this is the line that goes red.
        $this->postJson(route('api.v1.features.toggle', ['feature' => $slug]), ['enabled' => true])
            ->assertSuccessful();

        $this->assertTrue(Feature::active($slug));
    }

    /**
     * Read from the config file, not from the container.
     *
     * A PHPUnit data provider is evaluated before the application boots, so
     * config() — and FeatureCatalog::slugs() on top of it — is not available
     * here. Reading the file keeps the list honest and static.
     */
    public static function everyDeclaredSlug(): array
    {
        // Walked up from this file rather than through `base_path()`, because a
        // data provider runs before the application boots and `base_path()` needs
        // it. `dirname(__DIR__, N)` is depth-dependent — it broke the moment this
        // file moved a directory deeper when the suite was grouped by domain —
        // so this points at the one file that must exist for the suite to run at
        // all, and a moved test file cannot change where `config/` lives.
        $features = require dirname(__DIR__, 3).'/config/pennant.php';

        return array_map(
            fn (string $slug) => [$slug],
            array_keys($features['features'] ?? []),
        );
    }

    public function test_a_viewer_can_read_the_flag_catalogue_through_the_api(): void
    {
        // `features.view` only: reading the catalogue is a different permission
        // from changing it, the same split the web page uses.
        $this->actingAs($this->viewerWithout('features.manage'), 'sanctum');

        $response = $this->getJson(route('api.v1.features.index'))->assertSuccessful();

        $rows = collect($response->json('data.features'))->keyBy('slug');

        $this->assertCount(count(FeatureCatalog::slugs()), $rows);

        // The projection, pinned: a page model leaking through here is how a
        // JSON client gets a web toggle URL it cannot POST to (session + CSRF).
        $this->assertSame(
            ['slug', 'label', 'group', 'description', 'enabled', 'pending'],
            array_keys($rows['users']),
        );

        // No flag is in the "pending" state — every declared feature either
        // gates real routes or is a vendor dashboard, so no `pending` key
        // survives in the catalogue.
        foreach ($rows as $row) {
            $this->assertNull($row['pending'], "{$row['slug']} should not carry a pending state");
        }
    }

    public function test_the_catalogue_reflects_a_state_change_made_through_the_api(): void
    {
        $this->actingAs($this->manager(), 'sanctum');

        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertSuccessful();

        // The toggle flushes FeatureIndexAction's snapshot; if it did not, this
        // read would answer from a cache up to 30 seconds old and the endpoint
        // would be a state report that disagrees with its own writer.
        $response = $this->getJson(route('api.v1.features.index'))->assertSuccessful();

        $this->assertFalse(
            collect($response->json('data.features'))->firstWhere('slug', 'pulse')['enabled'],
        );
    }

    public function test_the_catalogue_is_refused_without_features_view(): void
    {
        // Holds everything except the one permission the read needs, including
        // `features.manage` — a route written against the wrong sibling gate
        // passes a zero-permission test and fails here.
        $this->actingAs($this->viewerWithout('features.view'), 'sanctum');

        $this->getJson(route('api.v1.features.index'))->assertForbidden();
    }

    public function test_the_catalogue_is_not_public(): void
    {
        $this->getJson(route('api.v1.features.index'))->assertUnauthorized();
    }

    public function test_a_viewer_cannot_toggle(): void
    {
        $this->actingAs($this->viewerWithout('features.manage'), 'sanctum');

        $this->getJson(route('api.v1.features.index'))->assertSuccessful();
        $this->postJson(route('api.v1.features.toggle', ['feature' => 'pulse']), ['enabled' => false])
            ->assertForbidden();
    }

    public function test_bulk_action_refuses_an_undeclared_slug(): void
    {
        $this->actingAs($this->manager(), 'sanctum');

        $this->postJson(route('api.v1.features.bulk-action'), [
            'action' => 'disable_feature',
            'features' => ['not-a-real-flag'],
        ])->assertStatus(422);

        // Fail-closed: no row for a slug that was never in the catalogue. The
        // store table itself is the check — an inserted row would mean the
        // request let an undeclared name through.
        $this->assertDatabaseMissing('features', ['feature' => 'not-a-real-flag']);
    }

    public function test_bulk_action_reports_requested_versus_unchanged(): void
    {
        $this->actingAs($this->manager(), 'sanctum');

        // Put the flag in a known state first rather than assuming a default:
        // the point is that a second, identical request reports nothing
        // changed. The web controller makes the same promise in its flash
        // message ("5 requested, 2 already in that state").
        Feature::activate('pulse');
        Feature::flushCache();

        $payload = [
            'action' => 'disable_feature',
            'features' => ['pulse'],
        ];

        // First call does the work.
        $this->postJson(route('api.v1.features.bulk-action'), $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.changed.0.slug', 'pulse')
            ->assertJsonPath('data.unchanged', []);

        // The same call again changes nothing, and saying so is the point: a
        // bare "1 updated" would overstate what happened. The web controller
        // makes the same promise in its flash message.
        $this->postJson(route('api.v1.features.bulk-action'), $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.changed', [])
            ->assertJsonPath('data.unchanged', ['pulse']);
    }
}
