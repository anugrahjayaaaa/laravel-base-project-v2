<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/v1/sessions` answers with the caller's own tokens.
 *
 * The transformer moved out of the controller (P12-B2), so these pin the shape
 * that move has to preserve — and the two keys that must never appear: the
 * plaintext token is issued once at login and the hash IS the credential.
 */
class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_callers_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('laptop');
        $user->createToken('phone');

        $stranger = User::factory()->create();
        $stranger->createToken('not-yours');

        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson(route('api.v1.sessions'));

        $response->assertOk()->assertJsonCount(2, 'data.sessions');

        $names = collect($response->json('data.sessions'))->pluck('name')->all();
        $this->assertSame(['laptop', 'phone'], $names);
    }

    public function test_the_payload_describes_the_token_and_never_reveals_it(): void
    {
        $user = User::factory()->create();
        $user->createToken('laptop', ['read']);

        Sanctum::actingAs($user, ['*']);

        $session = $this->getJson(route('api.v1.sessions'))->assertOk()->json('data.sessions.0');

        $this->assertSame(
            ['id', 'name', 'abilities', 'last_used_at', 'created_at'],
            array_keys($session),
            'the session shape is the contract; a new key here is a new field for every client.',
        );
        $this->assertSame('laptop', $session['name']);
        $this->assertSame(['read'], $session['abilities']);
        $this->assertNull($session['last_used_at']);
        $this->assertNotNull($session['created_at']);
    }

    public function test_it_revokes_the_callers_own_session(): void
    {
        $user = User::factory()->create();
        $laptop = $user->createToken('laptop')->accessToken;
        $user->createToken('phone');

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson(route('api.v1.sessions.destroy', $laptop->getKey()))->assertOk();

        $this->assertSame(['phone'], $user->tokens()->pluck('name')->all());
    }

    /**
     * An id on another account answers 404, not 403: "you may not" would
     * confirm the token exists, which the caller has no business learning.
     */
    public function test_it_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->create();
        $strangerToken = User::factory()->create()->createToken('not-yours')->accessToken;

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson(route('api.v1.sessions.destroy', $strangerToken->getKey()))->assertNotFound();

        $this->assertNotNull($strangerToken->fresh(), 'the stranger token was revoked anyway');
    }

    /**
     * The point of revocation: the token stops working. `Sanctum::actingAs`
     * would hide that, because it authenticates without consulting the token.
     */
    public function test_a_revoked_token_stops_authenticating(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'is_active' => true]);
        $keep = $user->createToken('laptop');
        $kill = $user->createToken('phone');

        $this->withHeader('Authorization', 'Bearer '.$keep->plainTextToken)
            ->deleteJson(route('api.v1.sessions.destroy', $kill->accessToken->getKey()))
            ->assertOk();

        // A resolved guard lives for the whole test process and hands back the
        // user it already found; forget it or the second request never reads
        // the token that was just deleted.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$kill->plainTextToken)
            ->getJson(route('api.v1.profile.show'))
            ->assertUnauthorized();
    }
}
