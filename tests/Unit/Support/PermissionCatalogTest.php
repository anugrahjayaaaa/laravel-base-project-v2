<?php

namespace Tests\Unit\Support;

use App\Support\FeatureCatalog;
use App\Support\PermissionCatalog;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * `App\Support\PermissionCatalog` is the contract; the `permissions` table is
 * only its storage. The seeder, the role matrix and the route gates all read
 * it, and the moment one of them keeps its own copy the copies drift — which is
 * how Phase 5 shipped `password_mixed_case` beside `password_require_upper`.
 *
 * Every test here is written against an invariant that a second, hand-typed
 * list would break: a name that does not parse, a resource that silently has no
 * owning flag, a permission that exists in code and not in the database.
 */
class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * `{resource}.{action}`, lowercase and dotted. `grouped()` derives the
     * heading from the prefix, so a name that breaks the shape lands under no
     * heading at all.
     */
    public function test_every_permission_is_a_lowercase_resource_dot_action(): void
    {
        $all = PermissionCatalog::all();

        $this->assertNotEmpty($all);

        foreach ($all as $name) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/',
                $name,
                "`{$name}` is not a {resource}.{action} name"
            );
        }
    }

    public function test_no_permission_is_declared_twice(): void
    {
        $all = PermissionCatalog::all();

        $this->assertSame(
            array_values($all),
            array_values(array_unique($all)),
            'a permission is listed twice, so its row and its section both lie about how many there are'
        );
    }

    /**
     * `forResource()` must partition `all()`: every permission appears under its
     * own prefix exactly once. A name filed under the wrong heading passes a
     * count assertion and fails this one.
     */
    public function test_for_resource_partitions_the_catalogue_by_prefix(): void
    {
        $flattened = [];

        foreach (PermissionCatalog::grouped() as $resource => $names) {
            $this->assertNotEmpty($names, "resource `{$resource}` groups no permission");

            foreach ($names as $name) {
                $this->assertStringStartsWith($resource.'.', $name, "`{$name}` was filed under `{$resource}`");
            }

            $this->assertSame(
                $names,
                PermissionCatalog::forResource($resource),
                "forResource('{$resource}') disagrees with the grouping"
            );

            $flattened = array_merge($flattened, $names);
        }

        $this->assertSame(PermissionCatalog::all(), $flattened, 'the grouping lost or duplicated a permission');
        $this->assertSame([], PermissionCatalog::forResource('no_such_resource'));
    }

    /**
     * The single-source claim, asserted on the database rather than on the
     * array: a seeder that keeps its own list creates rows the code cannot
     * check (and drops rows the code can). The seeder prunes orphans too, so
     * this is an exact equality, not a subset.
     */
    public function test_the_seeded_permissions_are_exactly_the_catalogue(): void
    {
        $seeded = Permission::query()->orderBy('name')->pluck('name')->all();
        $catalogue = PermissionCatalog::all();
        sort($catalogue);

        $this->assertSame($catalogue, $seeded, 'the permissions table and PermissionCatalog::all() disagree');
    }

    /**
     * The admin role's grants are the catalogue, read at seed time. If the
     * seeder hand-picked a subset instead, a permission added later would exist
     * in the code and never be granted, and the delegate admin would 403 on it.
     */
    public function test_the_admin_role_is_granted_the_whole_catalogue(): void
    {
        $granted = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', SystemRole::ADMIN)
            ->pluck('permissions.name')
            ->sort()
            ->values()
            ->all();

        $catalogue = PermissionCatalog::all();
        sort($catalogue);

        $this->assertSame($catalogue, $granted, 'admin holds a hand-picked subset instead of the catalogue');
    }

    /**
     * A new resource must either be mapped to a flag or listed as deliberately
     * unmapped. Silently unmapped is the one outcome that must be loud: the
     * role matrix would offer a permission for a module nobody can switch off.
     */
    public function test_every_resource_is_either_mapped_to_a_flag_or_listed_as_unmapped(): void
    {
        $mapped = [];
        $unmapped = [];

        foreach (PermissionCatalog::all() as $name) {
            $flag = PermissionCatalog::featureFor($name);

            if ($flag === null) {
                $unmapped[Str::before($name, '.')] = true;

                continue;
            }

            $this->assertTrue(
                FeatureCatalog::has($flag),
                "`{$name}` names the feature `{$flag}`, which the catalogue does not declare"
            );

            // Every permission of one resource shares one flag: `featureFor()`
            // resolves by resource, so a per-permission answer here means the
            // map was keyed by name after all.
            $mapped[Str::before($name, '.')] = $flag;
        }

        $listed = PermissionCatalog::unmappedResources();
        $found = array_keys($unmapped);
        sort($listed);
        sort($found);

        $this->assertSame($listed, $found, 'the unmapped-resource list is not the set that has no flag');
        $this->assertSame([], array_intersect(array_keys($mapped), $listed), 'a resource is both mapped and listed unmapped');
    }

    /**
     * `forFeature()` is the inverse of `featureFor()`, and the two must cover
     * the catalogue without overlap — switching a flag off revokes exactly the
     * names that said they belonged to it.
     */
    public function test_for_feature_returns_every_permission_whose_flag_it_is(): void
    {
        $byFlag = [];

        foreach (PermissionCatalog::all() as $name) {
            $flag = PermissionCatalog::featureFor($name);

            if ($flag !== null) {
                $byFlag[$flag][] = $name;
            }
        }

        foreach ($byFlag as $flag => $names) {
            $this->assertSame($names, PermissionCatalog::forFeature($flag), "forFeature('{$flag}') is not the inverse");
        }

        $this->assertSame([], PermissionCatalog::forFeature('no_such_flag'));

        // `features` is the one resource with no flag on purpose: the page that
        // re-enables a flag cannot be behind a flag.
        $this->assertSame([], PermissionCatalog::forFeature('features'));
        $this->assertContains(
            'features.manage',
            PermissionCatalog::visibleNames(),
            '`features` is ungated, so its permissions must stay visible whatever the store says'
        );
    }

    /**
     * Visible + hidden is a partition, and the split follows the flag store:
     * turning `pulse` off hides exactly `pulse.view` and nothing else.
     */
    public function test_the_visible_and_hidden_sets_partition_the_catalogue(): void
    {
        $this->assertSame(PermissionCatalog::all(), PermissionCatalog::visibleNames(), 'every flag is seeded on here');
        $this->assertSame([], PermissionCatalog::namesForInactiveFeatures());

        Feature::deactivate('pulse');
        Feature::flushCache();

        $hidden = PermissionCatalog::namesForInactiveFeatures();

        $this->assertSame(['pulse.view'], $hidden, 'switching pulse off hid the wrong permissions');
        $this->assertSame(
            count(PermissionCatalog::all()),
            count(PermissionCatalog::visibleNames()) + count($hidden),
            'the split lost or duplicated a permission'
        );
        $this->assertNotContains('pulse.view', PermissionCatalog::visibleNames());
        $this->assertContains('queues.view', PermissionCatalog::visibleNames(), 'an unrelated module was hidden too');
    }
}
