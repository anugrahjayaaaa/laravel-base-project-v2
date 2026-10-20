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
}
