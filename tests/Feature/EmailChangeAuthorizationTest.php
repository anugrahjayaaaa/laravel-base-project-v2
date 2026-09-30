<?php

namespace Tests\Feature;

use App\Models\Role as AppRole;
use App\Models\RoleLookup;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The email-change endpoints took {user} from the URL and authorized nobody.
 *
 * EmailChangeRequest::authorize() returned `true`, under a docblock claiming
 * "route,token-based authorization" that did not exist, and cancel-email-change
 * had no FormRequest at all. Any authenticated account could therefore write
 * `pending_email` onto ANOTHER user's row — and because the change completes by
 * clicking the emailed link, that is an account takeover, not a nuisance.
 *
 * The endpoint is genuinely self-service, so it cannot simply require a
 * permission or nobody could ever change their own address. The rule is "your own
 * account, or users.update" — both halves are asserted below, because a gate that
 * only refuses would pass while breaking the profile page.
 */
class EmailChangeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $readOnly;

    private User $editor;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Notification::fake();

        $this->readOnly = $this->userWith(['users.view']);
        $this->editor = $this->userWith(['users.update']);
        $this->victim = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);

        $role = AppRole::create([
            'name' => 'Scoped '.implode('+', $permissions).' '.uniqid(),
            'guard_name' => RoleLookup::guard(),
        ]);

        $role->givePermissionTo(
            \Spatie\Permission\Models\Permission::whereIn('name', $permissions)
                ->where('guard_name', RoleLookup::guard())
                ->get()
        );

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * The takeover path: writing another account's pending address.
     */
    public function test_a_stranger_cannot_request_an_email_change_for_someone_else(): void
    {
        $this->actingAs($this->readOnly, 'web');

        $this->post(route('users.request-email-change', $this->victim), [
            'email' => 'attacker@evil.test',
        ])->assertForbidden();

        $this->assertNull(
            $this->victim->fresh()->pending_email,
            'a stranger wrote pending_email onto another account'
        );
    }

    /**
     * The lesser sibling: clearing somebody else's pending change.
     */
    public function test_a_stranger_cannot_cancel_someone_elses_email_change(): void
    {
        $this->victim->update(['pending_email' => 'legit@example.test']);

        $this->actingAs($this->readOnly, 'web');

        $this->post(route('users.cancel-email-change', $this->victim))->assertForbidden();

        $this->assertSame(
            'legit@example.test',
            $this->victim->fresh()->pending_email,
            'a stranger cleared another account pending email change'
        );
    }

    /**
     * Self-service must keep working — the profile page depends on it, and this
     * is the half a permissions-only fix would have broken.
     */
    public function test_a_user_can_still_change_their_own_email(): void
    {
        $this->actingAs($this->readOnly, 'web');

        $this->post(route('users.request-email-change', $this->readOnly), [
            'email' => 'mine@example.test',
        ])->assertRedirect();

        $this->assertSame('mine@example.test', $this->readOnly->fresh()->pending_email);
    }

    public function test_a_user_can_still_cancel_their_own_email_change(): void
    {
        $this->readOnly->update(['pending_email' => 'mine@example.test']);

        $this->actingAs($this->readOnly, 'web');

        $this->post(route('users.cancel-email-change', $this->readOnly))->assertRedirect();

        $this->assertNull($this->readOnly->fresh()->pending_email);
    }

    /**
     * The admin path: someone holding users.update may act on another account,
     * which is why this is not a blanket permission check.
     */
    public function test_a_user_editor_may_change_another_accounts_email(): void
    {
        $this->actingAs($this->editor, 'web');

        $this->post(route('users.request-email-change', $this->victim), [
            'email' => 'set-by-admin@example.test',
        ])->assertRedirect();

        $this->assertSame('set-by-admin@example.test', $this->victim->fresh()->pending_email);
    }

    /**
     * The same boundary on the API, which ran the same unguarded controller.
     */
    public function test_the_api_refuses_the_same_thing(): void
    {
        $this->actingAs($this->readOnly, 'web');

        $this->postJson(route('api.v1.users.request-email-change', $this->victim), [
            'email' => 'attacker@evil.test',
        ])->assertForbidden();

        $this->assertNull($this->victim->fresh()->pending_email);
    }

    public function test_the_api_allows_self_service(): void
    {
        $this->actingAs($this->readOnly, 'web');

        $this->postJson(route('api.v1.users.request-email-change', $this->readOnly), [
            'email' => 'mine@example.test',
        ])->assertOk();

        $this->assertSame('mine@example.test', $this->readOnly->fresh()->pending_email);
    }
}
