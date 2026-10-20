<?php

namespace Tests\Feature\FeatureFlag;

use App\Models\RoleLookup;
use App\Models\User;
use App\Support\FeatureCatalog;
use App\Support\PermissionCatalog;
use App\View\Composers\AppMenuComposer;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Laravel\Pennant\Feature;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The resource → flag map, and the two things that must agree with it.
 *
 * `PermissionCatalog::FEATURE_BY_RESOURCE` is what answers "whose permission is
 * this" for the two halves of the flag work — hiding it from the role matrix and
 * revoking it on a switch-off. A wrong or missing entry fails in a way nothing
 * else notices: the matrix keeps offering a permission whose route 403s, or a
 * toggle leaves a grant behind for a module nobody can reach.
 *
 * So the map is pinned three ways: every resource has an answer (or is on the
 * documented exception list), every answer names a flag that actually exists,
 * and the sidebar's own `feature` keys — written before this map existed —
 * agree with it.
 */
class PermissionFeatureMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        // The seeder writes the pivot directly, so Spatie's cached set is stale.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * A resource with no mapping is either a mistake or a deliberate exception.
     *
     * The assertion is EQUALITY against the exception list, not "no unmapped
     * resources": adding a resource without deciding which flag owns it has to
     * fail here, and the only way to pass is to name it in
     * `PermissionCatalog::UNMAPPED_RESOURCES` with a reason.
     */
    public function test_every_resource_is_mapped_to_a_flag_or_is_a_documented_exception(): void
    {
        $unmapped = [];

        foreach (PermissionCatalog::all() as $name) {
            if (PermissionCatalog::featureFor($name) === null) {
                $unmapped[Str::before($name, '.')] = true;
            }
        }

        $this->assertSame(
            PermissionCatalog::unmappedResources(),
            array_keys($unmapped),
            'a resource owns no flag and is not on the exception list — hiding and revoking have no '
            .'answer for its permissions, so the flag would leave them behind'
        );
    }

    public function test_every_mapped_flag_is_a_declared_flag(): void
    {
        foreach (PermissionCatalog::all() as $name) {
            $flag = PermissionCatalog::featureFor($name);

            if ($flag === null) {
                continue;
            }

            $this->assertTrue(
                FeatureCatalog::has($flag),
                "{$name} is mapped to [{$flag}], which is not a declared flag — the map would "
                .'never read false and the permission would never be hidden or revoked'
            );
        }
    }

    public function test_a_permission_inherits_the_flag_of_its_resource(): void
    {
        $this->assertSame('activity_logs', PermissionCatalog::featureFor('audit.export'));
        $this->assertSame('vantage', PermissionCatalog::featureFor('queues.retry'));
        $this->assertNull(PermissionCatalog::featureFor('features.view'));
    }

    public function test_for_feature_returns_the_permissions_a_switch_takes_away(): void
    {
        $this->assertSame(['pulse.view'], PermissionCatalog::forFeature('pulse'));
        $this->assertSame(['queues.view', 'queues.retry'], PermissionCatalog::forFeature('vantage'));

        // `features` owns no permission, and neither does a flag nobody mapped.
        $this->assertSame([], PermissionCatalog::forFeature('features'));
    }

    /**
     * The baseline: with every flag on, nothing is hidden.
     *
     * A `visibleNames()` that returned too little would silently shrink the role
     * matrix and the permission index on a healthy install, which is the failure
     * the flag-off tests cannot see.
     */
    public function test_nothing_is_hidden_while_every_flag_is_on(): void
    {
        $this->assertSame([], PermissionCatalog::namesForInactiveFeatures());
        $this->assertSame(PermissionCatalog::all(), PermissionCatalog::visibleNames());
    }

    public function test_switching_a_flag_off_hides_exactly_its_own_permissions(): void
    {
        Feature::deactivate('pulse');
        Feature::flushCache();

        $this->assertSame(['pulse.view'], PermissionCatalog::namesForInactiveFeatures());
        $this->assertSame(
            count(PermissionCatalog::all()) - 1,
            count(PermissionCatalog::visibleNames()),
            'one flag off must hide one permission, not a different number'
        );
        $this->assertNotContains('pulse.view', PermissionCatalog::visibleNames());
        $this->assertContains('queues.view', PermissionCatalog::visibleNames());
    }

    /**
     * The sidebar and the map must give the same answer.
     *
     * `AppMenuComposer` names its own `feature` key per item and predates this
     * map. If the two disagree, a module can be switched off with its menu entry
     * still on screen — a link into a 403 — or the entry can vanish while the
     * permission stays assignable. Asserted through the composer because that is
     * where the second copy of the relationship lives.
     */
    public function test_the_sidebar_names_the_flag_the_catalogue_does(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->assignRole(RoleLookup::find('admin'));

        // Every permission-bearing item survives the permission filter, so this
        // compares the whole menu rather than one viewer's slice of it.
        $this->actingAs($admin, 'web');

        $captured = [];
        $view = Mockery::mock(View::class);

        $view->shouldReceive('with')->andReturnUsing(
            static function (string $name, mixed $value) use (&$captured, $view): View {
                $captured[$name] = $value;

                return $view;
            }
        );

        (new AppMenuComposer())->compose($view);

        $items = collect($captured['menuGroups'] ?? [])->pluck('items')->flatten(1);

        $this->assertNotEmpty($items, 'precondition: the menu rendered items to compare');

        foreach ($items as $item) {
            if (! isset($item['permission'])) {
                continue;
            }

            $flag = PermissionCatalog::featureFor($item['permission']);

            if ($flag === null) {
                $this->assertArrayNotHasKey(
                    'feature',
                    $item,
                    "the sidebar gates [{$item['permission']}] on a flag, but the catalogue says no flag owns it"
                );

                continue;
            }

            $this->assertSame(
                $flag,
                $item['feature'] ?? null,
                "the catalogue maps [{$item['permission']}] to [{$flag}] while the sidebar says "
                .var_export($item['feature'] ?? null, true)
            );
        }
    }
}
