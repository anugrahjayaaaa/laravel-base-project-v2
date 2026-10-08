<?php

namespace Tests\Feature\Role;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SystemRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Http\Middleware\VerifyCsrfToken;
use Spatie\Permission\Models\Permission;

/**
 * Trashing a role must not leave its holders stranded on no role at all.
 *
 * `RoleDeleteAction` deassigns the role from everyone holding it, which is
 * deliberate: Spatie skips `detach()` on a soft delete, so without the explicit
 * call the pivot rows would survive and a later `restore()` would silently hand
 * the role — and every permission it carries — straight back.
 *
 * The revocation was right; the consequence was not. An account with zero roles
 * holds zero permissions, so every gated screen 403s and the sidebar empties. It
 * can still log in, so nothing announces the problem: the account just goes
 * inert. One admin action stripped access from N people who never saw the role
 * picker and could not have known it was coming.
 *
 * The rule, both halves:
 *
 *   one role, and it is the one being trashed  → falls back to the default role
 *   two roles, one is trashed                  → untouched; the survivor stands
 */
class RoleRevocationFallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->admin = User::factory()->create(['email_verified_at' => now()]);
        $this->admin->assignRole(SystemRole::SUPERADMIN);

        $this->actingAs($this->admin, 'web');
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        // SystemSetting::$requestCache is a STATIC, so a test that calls set()
        // leaves its value readable by every test that runs afterwards. Without
        // this, the superadmin-default test below silently changes what the
        // tests after it see — which is how this file's own audit test failed
        // while passing in isolation.
        SystemSetting::bustCache();

        parent::tearDown();
    }

    private function makeRole(string $name): AppRole
    {
        return AppRole::create(['name' => $name, 'guard_name' => RoleLookup::guard()]);
    }

    private function makeUser(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function grant(AppRole $role, string $permission): void
    {
        $role->givePermissionTo(
            Permission::findByName($permission, RoleLookup::guard())
        );
    }

    /**
     * The stranded case: the account would otherwise hold nothing at all.
     */
    public function test_trashing_a_role_puts_its_holders_on_the_default_role(): void
    {
        $victim = $this->makeUser();
        $doomed = $this->makeRole('Doomed');
        $victim->assignRole($doomed);

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $this->assertSame(
            [SystemRole::USER],
            $victim->fresh()->roles->pluck('name')->all(),
            'a holder left with no role must land on the default, not on nothing'
        );
    }

    /**
     * The covered case, and the permission half of the rule.
     *
     * Trashing one of two roles must not rewrite an account that is still
     * perfectly well covered. The only thing that changes is what they can do:
     * the trashed role's permissions go with it, the survivor's stay. That falls
     * out of role-derived access rather than anything this action does — the
     * pivot row is gone so the role grants nothing, and there is no per-user
     * permission copy to revoke (ADR-004).
     */
    public function test_a_two_role_holder_keeps_their_other_role_and_its_permissions(): void
    {
        $keep = $this->makeRole('Keep');
        $this->grant($keep, 'users.view');

        $doomed = $this->makeRole('Doomed');
        $this->grant($doomed, 'users.delete');

        $target = $this->makeUser();
        $target->assignRole([$doomed, $keep]);

        $this->assertTrue($target->fresh()->can('users.delete'));

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $target->refresh();

        $this->assertSame(
            ['Keep'],
            $target->roles->pluck('name')->all(),
            'still holds a role, so no fallback is applied'
        );

        $this->assertFalse($target->can('users.delete'), 'the trashed role must grant nothing');
        $this->assertTrue($target->can('users.view'), 'the surviving role must still work');
    }

    /** The same rule stated from the single-role side, with the permission. */
    public function test_a_single_role_holder_lands_on_the_default(): void
    {
        $doomed = $this->makeRole('Doomed');
        $this->grant($doomed, 'users.delete');

        $target = $this->makeUser();
        $target->assignRole($doomed);

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $target->refresh();

        $this->assertSame([SystemRole::USER], $target->roles->pluck('name')->all());
        $this->assertFalse($target->can('users.delete'), 'the trashed role must grant nothing');
    }

    /**
     * The fallback follows the setting rather than a hardcoded 'user', or
     * trashing a role and self-registering would give two different answers to
     * "what does a new account get".
     */
    public function test_the_fallback_follows_the_configured_default_role(): void
    {
        SystemSetting::set('registration_default_role', 'admin');

        $victim = $this->makeUser();
        $doomed = $this->makeRole('Doomed');
        $victim->assignRole($doomed);

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $this->assertSame(['admin'], $victim->fresh()->roles->pluck('name')->all());
    }

    /**
     * superadmin is never handed out as a side effect.
     *
     * Its grant is restricted to superadmin actors by RoleAssignAction, and a
     * role deletion is not an actor anyone authorised. If the configured default
     * is somehow superadmin, the account stays empty rather than being promoted.
     */
    public function test_a_superadmin_default_is_never_granted_as_a_fallback(): void
    {
        SystemSetting::set('registration_default_role', SystemRole::SUPERADMIN);

        $victim = $this->makeUser();
        $doomed = $this->makeRole('Doomed');
        $victim->assignRole($doomed);

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $this->assertNotContains(
            SystemRole::SUPERADMIN,
            $victim->fresh()->roles->pluck('name')->all()
        );
    }

    /**
     * "A role was trashed" is not actionable during an incident; "these 12
     * accounts were moved to user" is. Both counts belong in the same row.
     */
    public function test_the_audit_row_records_how_many_were_reassigned(): void
    {
        $first = $this->makeUser();
        $second = $this->makeUser();
        $doomed = $this->makeRole('Doomed');
        $first->assignRole($doomed);
        $second->assignRole($doomed);

        $this->delete(route('roles.destroy', $doomed))->assertRedirect();

        $row = DB::table('activity_log')
            ->where('event', 'role.deleted')
            ->latest('id')
            ->first();

        $properties = json_decode($row->properties ?? '{}', true);

        // Both counts live in properties; activity_log has no such column.
        $this->assertSame(2, $properties['revoked_users'] ?? null);
        $this->assertSame(2, $properties['reassigned_to_default'] ?? null);
    }

}
