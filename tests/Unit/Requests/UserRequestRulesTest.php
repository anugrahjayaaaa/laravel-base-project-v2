<?php

namespace Tests\Unit\Requests;

use App\Actions\V1\User\UserCreateAction;
use App\Actions\V1\User\UserUpdateAction;
use App\Enums\UserStatusEnum;
use App\Http\Requests\V1\User\CreateUserRequest;
use App\Http\Requests\V1\User\UpdateUserRequest;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The user write path's rules and the two transformations it applies.
 *
 * `username` is `alpha_dash` + `unique`, and the uniqueness check differs by
 * endpoint on purpose: a create must not reuse an existing name, an update must
 * not treat the row's own name as a collision. The role payload arrives from a
 * checkbox group, so an empty placeholder value is stripped while the key
 * itself survives; `name` is passed through `strip_tags()` by the actions.
 */
class UserRequestRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    // === username rules ===

    public function test_create_accepts_letters_digits_dashes_and_underscores(): void
    {
        $this->assertTrue($this->createValidator(['username' => 'john_doe-01'])->passes());
    }

    public function test_create_rejects_a_username_with_a_space(): void
    {
        $validator = $this->createValidator(['username' => 'john doe']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('username', $validator->errors()->toArray());
    }

    public function test_create_rejects_a_username_already_taken(): void
    {
        User::factory()->create(['username' => 'taken_name']);

        $validator = $this->createValidator(['username' => 'taken_name']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('username', $validator->errors()->toArray());
    }

    /**
     * The update form submits the row's current username unchanged, so treating
     * it as a collision would make every save fail.
     */
    public function test_update_ignores_the_users_own_username(): void
    {
        $user = User::factory()->create(['username' => 'my_name']);

        $validator = $this->updateValidator($user, ['username' => 'my_name']);

        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    public function test_update_rejects_another_users_username(): void
    {
        User::factory()->create(['username' => 'other_name']);
        $user = User::factory()->create(['username' => 'my_name']);

        $validator = $this->updateValidator($user, ['username' => 'other_name']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('username', $validator->errors()->toArray());
    }

    // === payload transformation ===

    /**
     * A checkbox group posts a hidden empty value so an unticked list still
     * sends the key. Validation must see the key without the empty string.
     */
    public function test_the_roles_placeholder_is_stripped_but_the_key_survives(): void
    {
        $user = User::factory()->create();
        $admin = $this->admin();

        $request = $this->updateRequest($user, ['roles' => ['', 'admin']]);
        $request->setUserResolver(fn () => $admin);
        $request->validateResolved();

        $this->assertTrue($request->has('roles'));
        $this->assertSame(['admin'], $request->validated('roles'));
    }

    public function test_create_action_strips_tags_from_the_name(): void
    {
        Notification::fake();

        $admin = User::factory()->create();

        $user = app(UserCreateAction::class)->run([
            'name' => '<b>Bold</b> Name',
            'email' => 'bold.name@example.test',
            'username' => 'bold_name',
        ], 'Str0ng#Pass!2024', $admin);

        $this->assertSame('Bold Name', $user->fresh()->name);
    }

    public function test_update_action_strips_tags_from_the_name(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $user = User::factory()->create(['name' => 'Plain Name']);

        app(UserUpdateAction::class)->run($user, [
            'name' => '<script>alert(1)</script>Bob',
            'status' => UserStatusEnum::ACTIVE->value,
        ], $admin);

        $this->assertSame('alert(1)Bob', $user->fresh()->name);
    }

    // === helpers ===

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createValidator(array $overrides): ValidatorContract
    {
        $payload = array_merge([
            'name' => 'John Doe',
            'username' => 'john_doe',
            'email' => 'john@example.test',
        ], $overrides);

        return Validator::make($payload, (new CreateUserRequest())->rules());
    }

    /**
     * `UpdateUserRequest::rules()` reads the target from the route, so the
     * request needs a resolver before it can be asked for its rules.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function updateRequest(User $target, array $overrides = []): UpdateUserRequest
    {
        $payload = array_merge([
            'name' => $target->name,
            'status' => UserStatusEnum::ACTIVE->value,
        ], $overrides);

        $request = UpdateUserRequest::create('/api/v1/users/'.$target->getKey(), 'PUT', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        $route = new Route('PUT', 'api/v1/users/{user}', fn () => response()->json([]));
        $route->bind($request);
        $route->setParameter('user', $target);

        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function updateValidator(User $target, array $overrides = []): ValidatorContract
    {
        $request = $this->updateRequest($target, $overrides);

        return Validator::make($request->all(), $request->rules());
    }
}
