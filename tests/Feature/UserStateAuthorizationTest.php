<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four user state toggles had no authorization anywhere.
 *
 * UserStateController takes a plain User, runs the action, and audits. Its
 * actions check nothing. So the route was the only line of defence, and the
 * route had no `can:` — meaning ANY authenticated account could lock, unlock,
 * activate or deactivate any other account, including a superadmin.
 *
 * The buttons are hidden by @can in the view, which is not a control: a POST to
 * the URL is all it takes. Confirmed by running this before the fix existed.
 */
class UserStateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $attacker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->superadmin = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->superadmin->assignRole(SystemRole::SUPERADMIN);

        // Holds users.view and nothing else — a legitimate, narrow account.
        $role = AppRole::create([
            'name' => 'Read Only '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::findByName('users.view', RoleLookup::guard())
        );

        $this->attacker = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->attacker->assignRole($role);

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function toggleProvider(): array
    {
        return [
            'activate' => ['users.activate', 'users.activate', 'is_active', 'true'],
            'deactivate' => ['users.deactivate', 'users.deactivate', 'is_active', 'false'],
            'lock' => ['users.lock', 'users.lock', 'is_locked', 'true'],
            'unlock' => ['users.unlock', 'users.unlock', 'is_locked', 'false'],
        ];
    }

    /**
     * The regression: a user holding only users.view must not be able to change
     * anybody's account state.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toggleProvider')]
    public function test_a_state_toggle_refuses_a_caller_without_the_permission(
        string $route,
        string $permission,
        string $column,
        string $expected
    ): void {
        $victim = $this->superadmin;

        $before = (bool) $victim->fresh()->{$column};

        $this->actingAs($this->attacker, 'web');

        $this->from(route('users.index'))
            ->post(route($route, $victim))
            ->assertForbidden();

        $this->assertSame(
            $before,
            (bool) $victim->fresh()->{$column},
            "{$route} changed the target's {$column} despite the refusal"
        );
    }

    /**
     * The other half: holding the permission still works. A gate that refuses
     * everyone is indistinguishable from a broken route.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('toggleProvider')]
    public function test_a_state_toggle_still_works_for_a_caller_with_the_permission(
        string $route,
        string $permission,
        string $column,
        string $expected
    ): void {
        $granted = AppRole::create([
            'name' => 'Operator '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);
        $granted->givePermissionTo(
            \Spatie\Permission\Models\Permission::findByName($permission, RoleLookup::guard())
        );

        $operator = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
            'is_locked' => false,
        ]);
        $operator->assignRole($granted);

        $victim = User::factory()->create([
            'email_verified_at' => now(),
            'is_active' => true,
            'is_locked' => false,
        ]);

        // UserLockAction refuses an INACTIVE user ("activate the user first"), so
        // the deactivate case is what flips is_active, and the lock case must
        // start from an active account or it throws instead of locking.
        if ($permission === 'users.deactivate') {
            $victim->update(['is_active' => true]);
        }

        $this->actingAs($operator, 'web');

        $this->post(route($route, $victim))->assertRedirect();

        $this->assertSame(
            $expected === 'true',
            (bool) $victim->fresh()->{$column},
            "{$route} did not set {$column}"
        );
    }

    /**
     * A locked account cannot act, so the attacker must not be able to lock the
     * one account that could undo it — the superadmin. The first test covers
     * this; asserting the target explicitly documents why it matters.
     */
    public function test_a_read_only_user_cannot_lock_a_superadmin(): void
    {
        $this->actingAs($this->attacker, 'web');

        $this->post(route('users.lock', $this->superadmin))->assertForbidden();

        $this->assertFalse(
            $this->superadmin->fresh()->is_locked,
            'a superadmin was locked by an account with no lock permission'
        );
    }

    /**
     * An unauthenticated caller is refused too.
     */
    public function test_a_state_toggle_refuses_an_anonymous_caller(): void
    {
        $this->post(route('users.deactivate', $this->superadmin))->assertRedirect();

        $this->assertTrue($this->superadmin->fresh()->is_active);
    }
}
