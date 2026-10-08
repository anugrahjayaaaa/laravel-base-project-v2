<?php

namespace Tests\Feature\Role;

use App\Actions\V1\User\UserCreateAction;
use App\Http\Controllers\Web\V1\SystemSettingController;
use App\Http\Requests\V1\System\SystemSettingRequest;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Database\Seeders\PermissionSeeder;

/**
 * Spatie keys a role by (name, guard_name), so the same name can exist twice.
 * The seeder hardcoded `api` while the app assigns on `web`, nothing filtered
 * on the guard, and `where('name', ...)->first()` returned whichever row came
 * first — so the role pickers rendered `superadmin` and `admin` twice, and a
 * new user could be handed a role no permission check ever reads.
 *
 * These pin the resolver, because the obvious fix is wrong:
 * `config('auth.defaults.guard')` is mutated per request (Sanctum::actingAs
 * calls Auth::shouldUse('sanctum')) and Spatie does not take it at face value.
 * It intersects the default with the guards a User can authenticate under, so
 * it resolves `web` even while the default reads `sanctum`. Reading the config
 * directly therefore disagrees with the permission checks the roles feed.
 */
class RoleGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // PermissionSeeder too: the role/permission matrix (RBAC-004) lives there,
        // so without it the admin role carries no rows and every can() is false.
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        // Admin role: the users create/edit forms are gated on users.create /
        // users.update (P6-D1), and this suite renders them.
        $actor = User::factory()->create();
        $actor->assignRole(RoleLookup::find('admin'));
        $this->actingAs($actor, 'web');
    }

    /**
     * A causer holding users.assign_roles.
     *
     * P6-C10 made the admin path of UserCreateAction permission-gated, so a
     * direct call with a `roles` key and no causer is now refused — which is the
     * behaviour, not a test to work around.
     */
    private function admin(): User
    {
        return User::factory()->create()->assignRole(
            RoleLookup::find(SystemRole::SUPERADMIN)
        );
    }

    public function test_the_resolver_survives_a_mutated_auth_default(): void
    {
        // What Sanctum::actingAs does to a request.
        auth()->shouldUse('sanctum');
        $this->assertSame('sanctum', config('auth.defaults.guard'), 'precondition');

        // The invariant is that the resolver does not MOVE, not that it agrees
        // with Spatie's own resolver.
        //
        // It used to be phrased as `RoleLookup::guard() === Guard::getDefaultName()`,
        // which was true only because `sanctum` was not a declared guard: the
        // intersection could then only ever return `web`. Declaring `sanctum` in
        // config/auth.php — which it must be, or Spatie's Role::users() cannot
        // resolve a model class inside a withCount() subquery on a token request
        // — made Spatie return `sanctum`, and every role query filtered on a
        // guard no role is stored under.
        //
        // So RoleLookup::guard() now reads the session guard from config instead
        // of asking Spatie, and the assertion is that it is unchanged by a
        // request. Agreement with Spatie is checked in the User model, which
        // overrides getDefaultGuardName() to delegate here.
        $this->assertSame('web', RoleLookup::guard());
        $this->assertContains(RoleLookup::guard(), Guard::getNames(User::class)->all());

        // And the whole point: the model's own permission checks follow, which
        // is what a token request actually depends on.
        $user = User::factory()->create();
        $this->assertSame('web', (fn () => $this->getDefaultGuardName())->call($user));
    }

    public function test_the_seeder_writes_roles_on_the_resolved_guard(): void
    {
        $guard = RoleLookup::guard();

        $this->assertSame(
            ['admin', 'superadmin', 'user'],
            Role::where('guard_name', $guard)->orderBy('name')->pluck('name')->all()
        );

        $this->assertSame(0, Role::where('guard_name', '!=', $guard)->count(), "a second guard's rows defeat the point");
    }

    public function test_the_seeder_is_safe_to_run_twice(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count(), 're-seeding must not duplicate roles');
    }

    public function test_the_role_picker_offers_each_name_once(): void
    {
        // The state that produced two `superadmin` checkboxes: both guards hold
        // the same name.
        Role::create(['name' => 'superadmin', 'guard_name' => 'api']);
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $offered = RoleLookup::assignable()->pluck('name');

        $this->assertSame($offered->unique()->values()->all(), $offered->values()->all());
        // assignable() is guard-scoped only — it does not filter by viewer, so
        // superadmin is still here. Visibility is visibleTo()'s job.
        $this->assertSame(['admin', 'superadmin', 'user'], $offered->all());
    }

    public function test_creating_a_user_assigns_the_role_on_the_resolved_guard(): void
    {
        // The other guard's row is inserted FIRST, so an unscoped
        // where('name', ...)->first() would return it.
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $user = app(UserCreateAction::class)->run([
            'name' => 'Guard Test',
            'username' => 'guardtest',
            'email' => 'guardtest@example.com',
            'roles' => ['admin'],
        ], 'QaTest#2026x', causer: $this->admin());

        $assigned = $user->roles;

        $this->assertCount(1, $assigned);
        $this->assertSame(RoleLookup::guard(), $assigned->first()->guard_name);
        $this->assertTrue($user->hasRole('admin'), 'the assigned role must be one the permission check reads');
    }

    public function test_self_registration_uses_the_default_role_on_the_resolved_guard(): void
    {
        SystemSetting::set('registration_default_role', 'user');
        Role::create(['name' => 'user', 'guard_name' => 'api']);

        $user = app(UserCreateAction::class)->run([
            'name' => 'Self Reg',
            'username' => 'selfreg',
            'email' => 'selfreg@example.com',
        ], 'QaTest#2026x');

        $this->assertSame(['user'], $user->getRoleNames()->all());
        $this->assertSame(RoleLookup::guard(), $user->roles->first()->guard_name);
    }

    public function test_the_default_role_rule_only_accepts_a_role_on_the_resolved_guard(): void
    {
        $guard = RoleLookup::guard();

        // The seeder, the picker and the create action all offer `user` on the
        // resolved guard, so this is the name a real submission carries.
        $this->assertTrue(Validator::make(
            ['registration_default_role' => 'user'],
            (new SystemSettingRequest())->rules()
        )->passes());

        // Now make `user` exist only on another guard. The rule is scoped, so
        // the name can no longer be saved — an unscoped exists:roles,name would
        // accept it and the create action would then assign nothing at all.
        Role::where('name', 'user')->delete();
        Role::create(['name' => 'user', 'guard_name' => $guard === 'web' ? 'api' : 'web']);

        $this->assertFalse(
            Validator::make(['registration_default_role' => 'user'], (new SystemSettingRequest())->rules())->passes(),
            'a role that exists only on another guard must not validate'
        );
    }

    public function test_the_settings_page_offers_each_role_name_once(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $view = app(SystemSettingController::class)->index();
        $roles = $view->getData()['roles'];

        // The viewer here is not a superadmin, so `superadmin` is not offered.
        // The point of the test is that each name appears ONCE, not which names.
        $this->assertSame(['admin', 'user'], array_values($roles->all()));
    }

    public function test_the_user_forms_offer_each_role_name_once(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $user = User::factory()->create();

        // Through the HTTP layer, not by calling the controller: the role list
        // now arrives via AccountOptionsComposer, which only runs when the view
        // is rendered. Reading getData() off the controller's return value
        // asserts nothing about what the page is given.
        $admin = User::factory()->create(['is_active' => true]);
        // The seeded admin, not a hand-made 'webadmin' role: the users forms are
        // gated on users.create / users.update, and a role with no permission
        // rows satisfies neither.
        $admin->assignRole(RoleLookup::find('admin'));

        foreach (['create', 'show'] as $method) {
            $page = $this->actingAs($admin)->get(
                $method === 'show' ? route('users.show', $user) : route('users.create')
            );
            $page->assertOk();

            // visibleTo(), because that is what the picker is fed: this viewer is
            // not a superadmin, so superadmin is not one of the offered names.
            foreach (RoleLookup::visibleTo($admin)->pluck('name') as $name) {
                $page->assertSee($name);
            }
        }
    }
}
