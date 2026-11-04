<?php

namespace Tests\Unit\Support;

use App\Models\Role;
use App\Models\RoleLookup;
use App\Support\SystemRole;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `App\Support\SystemRole` is the single answer to "is this a role the
 * application depends on". `RoleSeeder`, `Role::isSystemRole()` and every
 * protection rule (`RoleDeleteAction`, `RoleForceDeleteAction`, `RoleUpdateAction`)
 * read it, so a role name added to one of those places and not here becomes an
 * ordinary, deletable role that the app still relies on.
 *
 * The comparison is case-sensitive and whole-string on purpose: role names are
 * stored as written, so `Superadmin` is a different, unprotected role — and a
 * prefix match would make `superadmin-2` protected and `Admin` not.
 */
class SystemRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_the_list_is_unique_lowercase_names(): void
    {
        $names = SystemRole::names();

        $this->assertSame(array_values(array_unique($names)), $names, 'a system role is listed twice');

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', $name);
        }
    }

    /**
     * The names the seeder writes must be exactly the names this class protects.
     * A fourth role created by the seeder and not listed here is seeded as a
     * system role and then treated as ordinary by every guard.
     */
    public function test_the_seeded_roles_are_exactly_the_system_roles(): void
    {
        $seeded = Role::query()->orderBy('name')->pluck('name')->all();
        $names = SystemRole::names();
        sort($names);

        $this->assertSame($names, $seeded, 'RoleSeeder and SystemRole::names() disagree');

        foreach ($seeded as $name) {
            $this->assertTrue(SystemRole::isSystem($name), "the seeder created `{$name}` as a role this class does not protect");
        }
    }

    public function test_is_system_is_case_sensitive_and_exact(): void
    {
        foreach (SystemRole::names() as $name) {
            $this->assertTrue(SystemRole::isSystem($name));
        }

        foreach (['Superadmin', 'ADMIN', 'User', 'superadmin-2', 'administrator', 'admin ', '', 'guest'] as $other) {
            $this->assertFalse(
                SystemRole::isSystem($other),
                "`{$other}` was treated as a protected system role — the check is not exact"
            );
        }
    }

    /**
     * `Role::$is_system` is the model-side reader every view uses; a role
     * object and the class must not disagree about the same name.
     */
    public function test_the_model_accessor_delegates_to_the_catalogue(): void
    {
        foreach (SystemRole::names() as $name) {
            $this->assertTrue(
                RoleLookup::find($name)->is_system,
                'Role->is_system says `'.$name.'` is ordinary'
            );
        }

        $custom = Role::create(['name' => 'reporter', 'guard_name' => RoleLookup::guard()]);

        $this->assertFalse($custom->is_system, 'a custom role was reported as a system role');
    }
}
