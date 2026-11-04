<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\V1\Role\StoreRoleRequest;
use App\Http\Requests\V1\Role\UpdateRoleRequest;
use App\Http\Requests\V1\User\BulkUserRequest;
use App\Http\Requests\V1\User\EmailChangeRequest;
use App\Http\Requests\V1\User\ProfileUpdateRequest;
use App\Http\Requests\V1\User\UpdateUserRequest;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The guard branches of the write-path Form Requests (P15-E2 gap close).
 *
 * `UserRequestRulesTest` pins the rules; these are the `authorize()` refusals
 * and the `withValidator()` cooldown/disabled throws that only fire on the
 * refusal path — the branch nobody walks is the branch that ships broken.
 */
class RequestGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
    }

    public function test_store_role_refuses_a_caller_without_roles_create(): void
    {
        $request = StoreRoleRequest::create('/roles', 'POST', ['name' => 'editor']);
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }

    public function test_update_role_refuses_a_caller_without_roles_update(): void
    {
        $request = UpdateRoleRequest::create('/roles/1', 'PUT', ['name' => 'editor']);
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }

    public function test_email_change_refuses_when_the_route_carries_no_user_model(): void
    {
        // The `{user}` placeholder resolving to a raw string (a mistyped id on
        // a route without model binding) must refuse, not fatal.
        $request = EmailChangeRequest::create('/users/1/email-change', 'POST', ['email' => 'x@example.test']);
        $request->setContainer($this->app);

        $route = new Route('POST', 'users/{user}/email-change', fn () => 'ok');
        $route->bind($request);
        $route->setParameter('user', '1');
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }

    public function test_bulk_action_refuses_an_id_that_does_not_exist(): void
    {
        $validator = Validator::make(
            ['action' => 'delete', 'user_ids' => [999_999]],
            (new BulkUserRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'is invalid',
            $validator->errors()->first('user_ids.0'),
        );
    }

    /**
     * A caller with no permission at all may still save their own row — the
     * self-update branch, where authority comes from owning the target, not
     * from `users.update`.
     */
    public function test_update_user_allows_self_update_when_the_payload_touches_no_roles(): void
    {
        // No role at all: `users.update` refuses, so a pass can only come from
        // the self-update branch.
        $actor = User::factory()->create();

        $request = $this->updateUserRequest($actor, $actor, []);
        $request->setUserResolver(fn () => $actor);

        $this->assertTrue($request->authorize());
    }

    public function test_profile_update_refuses_a_username_change_while_they_are_disabled(): void
    {
        SystemSetting::set('allow_username_change', 'false');

        $user = User::factory()->create(['username' => 'old_name']);

        $request = ProfileUpdateRequest::create('/profile', 'PUT', ['name' => $user->name, 'username' => 'new_name']);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(fn () => $user);

        $this->expectException(ValidationException::class);
        $request->validateResolved();
    }

    public function test_profile_update_refuses_an_email_change_while_they_are_disabled(): void
    {
        SystemSetting::set('allow_email_change', 'false');

        $user = User::factory()->create();

        $request = ProfileUpdateRequest::create('/profile', 'PUT', ['name' => $user->name, 'email' => 'someone.else@example.test']);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));
        $request->setUserResolver(fn () => $user);

        $this->expectException(ValidationException::class);
        $request->validateResolved();
    }

    /**
     * The cooldown half of the email gate: changes are allowed in general, but
     * this row changed email today, so the refusal carries the date the next
     * change unlocks — the operator-facing part of the message.
     */
    public function test_update_user_refuses_an_email_change_inside_the_cooldown_window(): void
    {
        SystemSetting::set('email_change_cooldown_days', '30');

        $actor = $this->admin();
        $target = User::factory()->create(['email_changed_at' => now()]);

        $request = $this->updateUserRequest($actor, $target, ['email' => 'fresh.address@example.test']);
        $request->setUserResolver(fn () => $actor);

        try {
            $request->validateResolved();
            $this->fail('expected the cooldown refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'Next change allowed on '.now()->addDays(30)->format('Y-m-d'),
                $e->errors()['email'][0],
            );
        }
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
    private function updateUserRequest(User $actor, User $target, array $overrides): UpdateUserRequest
    {
        $payload = array_merge([
            'name' => $target->name,
            'status' => $target->status?->value ?? 'active',
        ], $overrides);

        $request = UpdateUserRequest::create('/users/'.$target->getKey(), 'PUT', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        $route = new Route('PUT', 'users/{user}', fn () => 'ok');
        $route->bind($request);
        $route->setParameter('user', $target);
        $request->setRouteResolver(static fn () => $route);

        return $request;
    }
}
