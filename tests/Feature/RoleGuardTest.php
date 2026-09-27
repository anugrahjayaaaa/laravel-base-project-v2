<?php

namespace Tests\Feature;

use App\Actions\V1\User\CreateUserAction;
use App\Http\Controllers\Web\V1\SystemSettingController;
use App\Http\Controllers\Web\V1\UserController;
use App\Http\Requests\System\SystemSettingRequest;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

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
        $this->seed(SystemSettingSeeder::class);
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_the_resolver_survives_a_mutated_auth_default(): void
    {
        // What Sanctum::actingAs does to a request.
        auth()->shouldUse('sanctum');
        $this->assertSame('sanctum', config('auth.defaults.guard'), 'precondition');

        // Spatie intersects the default with the guards a User can use, so the
        // raw config lies here and the resolver does not.
        $this->assertSame(
            Guard::getDefaultName(User::class),
            RoleLookup::guard()
        );
        $this->assertContains(RoleLookup::guard(), Guard::getNames(User::class)->all());
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
        $this->assertSame(['admin', 'superadmin', 'user'], $offered->all());
    }

    public function test_creating_a_user_assigns_the_role_on_the_resolved_guard(): void
    {
        // The other guard's row is inserted FIRST, so an unscoped
        // where('name', ...)->first() would return it.
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $user = app(CreateUserAction::class)->run([
            'name' => 'Guard Test',
            'username' => 'guardtest',
            'email' => 'guardtest@example.com',
            'roles' => ['admin'],
        ], 'QaTest#2026x');

        $assigned = $user->roles;

        $this->assertCount(1, $assigned);
        $this->assertSame(RoleLookup::guard(), $assigned->first()->guard_name);
        $this->assertTrue($user->hasRole('admin'), 'the assigned role must be one the permission check reads');
    }

    public function test_self_registration_uses_the_default_role_on_the_resolved_guard(): void
    {
        SystemSetting::set('registration_default_role', 'user');
        Role::create(['name' => 'user', 'guard_name' => 'api']);

        $user = app(CreateUserAction::class)->run([
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
            app(SystemSettingRequest::class)->rules()
        )->passes());

        // Now make `user` exist only on another guard. The rule is scoped, so
        // the name can no longer be saved — an unscoped exists:roles,name would
        // accept it and the create action would then assign nothing at all.
        Role::where('name', 'user')->delete();
        Role::create(['name' => 'user', 'guard_name' => $guard === 'web' ? 'api' : 'web']);

        $this->assertFalse(
            Validator::make(['registration_default_role' => 'user'], app(SystemSettingRequest::class)->rules())->passes(),
            'a role that exists only on another guard must not validate'
        );
    }

    public function test_the_settings_page_offers_each_role_name_once(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);

        $view = app(SystemSettingController::class)->index();
        $roles = $view->getData()['roles'];

        $this->assertSame(['admin', 'superadmin', 'user'], array_values($roles->all()));
    }

    public function test_the_user_forms_offer_each_role_name_once(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
        $user = User::factory()->create();

        foreach (['create', 'show'] as $method) {
            $roles = app(UserController::class)->{$method}(...($method === 'show' ? [$user] : []))->getData()['roles'];

            $this->assertSame(
                ['admin', 'superadmin', 'user'],
                $roles->pluck('name')->all(),
                "{$method}() must offer each role once"
            );
        }
    }
}
