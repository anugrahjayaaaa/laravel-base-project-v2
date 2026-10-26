<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * GET /api/v1/auth/email/verify/{id}/{hash}
 *
 * The route carried a signed URL and no test, so the API address was only
 * proven to exist by the drift gate — never to work.
 */
class VerifyEmailApiTest extends TestCase
{
    use RefreshDatabase;

    private function signedUrl(User $user): string
    {
        return URL::signedRoute('api.v1.auth.email.verify', [
            'id' => $user->getKey(),
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_a_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->getJson($this->signedUrl($user))
            ->assertOk()
            ->assertJsonPath('data.message', 'Email verified successfully.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_already_verified_email_is_refused(): void
    {
        $user = User::factory()->create();

        $this->getJson($this->signedUrl($user))
            ->assertStatus(422)
            ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED');
    }
}
