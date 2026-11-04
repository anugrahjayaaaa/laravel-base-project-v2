<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `App\Policies\UserPolicy` — the only policy in the codebase.
 *
 * ## Four actors, because they reach the policy by four different routes
 *
 * - **superadmin** — never reaches the policy at all. `AuthServiceProvider`
 *   installs `Gate::before` returning true for the role, so every ability is
 *   allowed before the class is consulted. Asserted on targets the policy
 *   itself would refuse, because that is where a bypass that silently stopped
 *   working would show up first.
 * - **admin** — the delegated superadmin, granted the whole catalogue by
 *   `PermissionSeeder`.
 * - **user** — a real role row with zero permissions.
 * - **no role at all** — a different code path: Spatie's `hasRole()` has no
 *   pivot row to read, so `Gate::before` answers null rather than false and the
 *   policy is reached with nothing behind it. The Phase 10 pentest finding was
 *   about exactly this account: the fix must not depend on a role existing.
 *
 * Every assertion goes through the Gate (`Gate::forUser(...)`), not
 * `$policy->method(...)`. Calling the class directly would prove the class can
 * answer and say nothing about what the application actually asks.
 *
 * The four state methods are a different shape from the seven CRUD ones: they
 * answer about the TARGET first ("you may not unlock an account that is not
 * locked") and only then about the actor. The ordering is the invariant, and
 * it is asserted from the side that exposes it — an actor who holds no
 * permission must get the target's refusal, not the permission's.
 *
 * Reported, not fixed: `activate()` has no actor check at all, and the
 * `Response::deny($message, 409)` calls put 409 in `code()`, not `status()`
 * (409 on a Response is `denyWithStatus(409, ...)`). See the P15-B3 report.
 */
class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $plainUser;

    /** A real account with no role row at all — the Phase 10 pentest shape. */
    private User $roleless;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = $this->actor(SystemRole::SUPERADMIN);
        $this->admin = $this->actor(SystemRole::ADMIN);
        $this->plainUser = $this->actor(SystemRole::USER);
        $this->roleless = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        // Spatie caches the role→permission map; the actors above were assigned
        // after the seeder primed it.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * The actors, named, so a failure says which one answered wrongly.
     *
     * @return array<string, User>
     */
    private function actors(): array
    {
        return [
            'superadmin' => $this->superadmin,
            'admin' => $this->admin,
            'user' => $this->plainUser,
            'no role' => $this->roleless,
        ];
    }

    /**
     * @param  array<string, bool>  $expected
     */
    private function assertAllows(string $ability, mixed $target, array $expected): void
    {
        foreach ($this->actors() as $name => $actor) {
            $this->assertSame(
                $expected[$name],
                Gate::forUser($actor)->allows($ability, $target),
                "[{$name}] answered the wrong way for `{$ability}`"
            );
        }
    }

    private function inspect(User $actor, string $ability, mixed $target): Response
    {
        return Gate::forUser($actor)->inspect($ability, $target);
    }

    // === fixtures ===

    /**
     * Without this, a policy test that passes because every actor lacks the
     * permission would be measuring Spatie, not the policy.
     */
    public function test_the_actors_hold_what_they_are_supposed_to_hold(): void
    {
        $this->assertTrue($this->superadmin->hasRole(SystemRole::SUPERADMIN));
        $this->assertTrue($this->admin->can('users.unlock'), 'fixture: admin lacks users.unlock');
        $this->assertTrue($this->admin->can('users.force_delete'), 'fixture: admin lacks users.force_delete');
        $this->assertFalse($this->plainUser->can('users.view'), 'fixture: the plain user holds a permission');
        $this->assertFalse($this->roleless->can('users.view'), 'fixture: the roleless user holds a permission');
        $this->assertTrue($this->roleless->getRoleNames()->isEmpty(), 'fixture: the roleless user has a role');
    }

    // === CRUD: one permission each ===

    public function test_view_any_reads_users_view(): void
    {
        $this->assertAllows('viewAny', User::class, $this->holderOnly());
    }

    public function test_view_reads_users_view(): void
    {
        $this->assertAllows('view', $this->plainUser, $this->holderOnly());
    }

    public function test_create_reads_users_create(): void
    {
        $this->assertAllows('create', User::class, $this->holderOnly());
    }

    public function test_update_reads_users_update(): void
    {
        $this->assertAllows('update', $this->plainUser, $this->holderOnly());
    }

    public function test_delete_reads_users_delete(): void
    {
        $this->assertAllows('delete', $this->plainUser, $this->holderOnly());
    }

    public function test_force_delete_reads_users_force_delete(): void
    {
        $this->assertAllows('forceDelete', $this->plainUser, $this->holderOnly());
    }

    public function test_restore_reads_users_restore(): void
    {
        $this->assertAllows('restore', $this->plainUser, $this->holderOnly());
    }

    /**
     * The CRUD shape: superadmin by bypass, admin by the seeded catalogue,
     * everybody else denied — including the account with no role, which is the
     * one a lookup keyed on a role row would let through.
     *
     * @return array<string, bool>
     */
    private function holderOnly(): array
    {
        return [
            'superadmin' => true,
            'admin' => true,
            'user' => false,
            'no role' => false,
        ];
    }

    // === unlock ===

    /**
     * The target guard runs BEFORE the permission check: an actor holding
     * nothing is told the account is not locked, not that they lack a
     * permission. If the order flips, this message is what changes.
     */
    public function test_unlock_refuses_a_target_that_is_not_locked_before_asking_about_permission(): void
    {
        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'unlock', $this->plainUser);

            $this->assertFalse($response->allowed(), "[{$name}] was allowed to unlock an unlocked account");
            $this->assertStringContainsString('not locked', (string) $response->message());
        }

        // The superadmin never reaches the policy: Gate::before answers first.
        $this->assertTrue(
            $this->inspect($this->superadmin, 'unlock', $this->plainUser)->allowed(),
            'the superadmin bypass stopped working — its access is Gate::before, not a policy branch'
        );
    }

    public function test_unlock_needs_users_unlock_on_a_locked_target(): void
    {
        $target = User::factory()->create(['is_locked' => true]);

        $this->assertTrue($this->inspect($this->admin, 'unlock', $target)->allowed());

        foreach (['user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'unlock', $target);

            $this->assertFalse($response->allowed(), "[{$name}] unlocked an account without users.unlock");
            $this->assertStringContainsString('permission to unlock', (string) $response->message());
        }
    }

    // === deactivate ===

    public function test_deactivate_refuses_a_locked_target_before_asking_about_permission(): void
    {
        $locked = User::factory()->create(['is_locked' => true, 'is_active' => true]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'deactivate', $locked);

            $this->assertFalse($response->allowed(), "[{$name}] deactivated a locked account");
            $this->assertStringContainsString('Cannot deactivate a locked user', (string) $response->message());
        }
    }

    public function test_deactivate_refuses_an_already_inactive_target(): void
    {
        $inactive = User::factory()->create(['is_active' => false, 'is_locked' => false]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'deactivate', $inactive);

            $this->assertFalse($response->allowed(), "[{$name}] deactivated an already inactive account");
            $this->assertStringContainsString('already inactive', (string) $response->message());
        }
    }

    public function test_deactivate_needs_users_deactivate_on_an_active_target(): void
    {
        $target = User::factory()->create(['is_active' => true, 'is_locked' => false]);

        $this->assertTrue($this->inspect($this->admin, 'deactivate', $target)->allowed());
        $this->assertTrue($this->inspect($this->superadmin, 'deactivate', $target)->allowed());

        foreach (['user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'deactivate', $target);

            $this->assertFalse($response->allowed(), "[{$name}] deactivated an account without users.deactivate");
            $this->assertStringContainsString('permission to deactivate', (string) $response->message());
        }
    }

    // === lock ===

    public function test_lock_refuses_an_inactive_target_before_asking_about_permission(): void
    {
        $inactive = User::factory()->create(['is_active' => false, 'is_locked' => false]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'lock', $inactive);

            $this->assertFalse($response->allowed(), "[{$name}] locked an inactive account");
            $this->assertStringContainsString('lock an inactive user', (string) $response->message());
        }
    }

    public function test_lock_refuses_an_already_locked_target(): void
    {
        $locked = User::factory()->create(['is_active' => true, 'is_locked' => true]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'lock', $locked);

            $this->assertFalse($response->allowed(), "[{$name}] locked an already locked account");
            $this->assertStringContainsString('already locked', (string) $response->message());
        }
    }

    public function test_lock_needs_users_lock_on_an_active_unlocked_target(): void
    {
        $target = User::factory()->create(['is_active' => true, 'is_locked' => false]);

        $this->assertTrue($this->inspect($this->admin, 'lock', $target)->allowed());
        $this->assertTrue($this->inspect($this->superadmin, 'lock', $target)->allowed());

        foreach (['user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'lock', $target);

            $this->assertFalse($response->allowed(), "[{$name}] locked an account without users.lock");
            $this->assertStringContainsString('permission to lock', (string) $response->message());
        }
    }

    // === activate ===

    public function test_activate_refuses_a_locked_target(): void
    {
        $target = User::factory()->create(['is_active' => false, 'is_locked' => true]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'activate', $target);

            $this->assertFalse($response->allowed(), "[{$name}] activated a locked account");
            $this->assertStringContainsString('locked user', (string) $response->message());
        }
    }

    public function test_activate_refuses_an_already_active_target(): void
    {
        $target = User::factory()->create(['is_active' => true, 'is_locked' => false]);

        foreach (['admin', 'user', 'no role'] as $name) {
            $response = $this->inspect($this->actors()[$name], 'activate', $target);

            $this->assertFalse($response->allowed(), "[{$name}] re-activated an active account");
            $this->assertStringContainsString('already active', (string) $response->message());
        }
    }

    /**
     * `activate()` is the one state method with no actor check — unlike its
     * three siblings. The actor gate is the route's `can:users.activate`
     * (`routes/web.php:167`, `routes/api.php:209`), so this test pins where the
     * question is answered rather than pretending the policy answers it.
     * Reported as a divergence, not endorsed: see the P15-B3 report.
     */
    public function test_activate_answers_only_about_the_target_leaving_the_permission_to_the_route(): void
    {
        $target = User::factory()->create(['is_active' => false, 'is_locked' => false]);

        $this->assertTrue($this->inspect($this->admin, 'activate', $target)->allowed());
        $this->assertTrue(
            $this->inspect($this->roleless, 'activate', $target)->allowed(),
            'the authorisation answer moved: check whether routes still carry can:users.activate'
        );
    }
}
