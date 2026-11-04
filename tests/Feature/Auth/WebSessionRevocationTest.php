<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The revocation rows of Phase 15 C1.
 *
 * A revoked session must fail on the NEXT request, not on the next login.
 * Every other suite in this area flips a state column and then asserts the
 * column or the next login attempt — none walks an already-authenticated
 * web user through `account.state` to prove the live session is refused.
 * That is the branch a race or a middleware-order change would silently
 * reopen, so it is asserted through the real stack here.
 */
class WebSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
    }

    public function test_a_deactivated_users_next_web_request_is_refused(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->assertLiveSessionIsRejected();
    }

    public function test_a_locked_users_next_web_request_is_refused(): void
    {
        $this->user->forceFill(['is_locked' => true])->save();

        $this->assertLiveSessionIsRejected();
    }

    public function test_a_soft_deleted_users_next_web_request_is_refused(): void
    {
        $this->user->delete();

        // Trashed rows are not re-fetched by the middleware (the default scope
        // excludes them), so token teardown is the delete action's job — the
        // revocation proof here is the rejection itself.
        $this->actingAs($this->user, 'web');

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    private function assertLiveSessionIsRejected(): void
    {
        // A live Sanctum token must die with the session — the middleware
        // deletes both on the rejection branch.
        $this->user->createToken('probe');

        $this->actingAs($this->user, 'web');

        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest('web');
        $this->assertSame(0, $this->user->tokens()->count(), 'the revoked user kept a live API token');
    }
}
