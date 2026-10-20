<?php

namespace Tests\Feature\CrossCutting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The error envelope is the contract, and `APP_DEBUG` does not change it.
 *
 * If that sounds obvious, it was not: the whole handler used to sit inside
 * `! config('app.debug')`, so the documented body only existed when debug was
 * off. A deployment that turned debug on answered in Laravel's shape instead —
 * different keys, no `code`, no correlation — and no test noticed, because
 * `.env.testing` sets `APP_DEBUG=false` and every contract test ran in the one
 * branch the deployment does not always use.
 *
 * Debug now ADDS a `debug` key. These tests pin the invariant from both sides:
 * the keys that must always be there, and the key that may only appear in debug.
 */
class ApiErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function bodyOf(string $uri): array
    {
        $response = $this->getJson($uri);
        $response->assertStatus(404);

        return $response->json();
    }

    public function test_a_handler_error_carries_a_code_and_correlation_meta(): void
    {
        $body = $this->bodyOf('/api/v1/no-such-endpoint');

        $this->assertSame('NOT_FOUND', $body['code']);
        $this->assertIsString($body['message']);
        $this->assertIsString($body['meta']['request_id']);
        $this->assertNotSame('', $body['meta']['request_id']);
        $this->assertIsString($body['meta']['timestamp']);
        $this->assertArrayNotHasKey('debug', $body, 'debug detail must not leak when APP_DEBUG is off.');
    }

    public function test_debug_mode_adds_detail_without_changing_the_shape(): void
    {
        config(['app.debug' => false]);
        $quiet = $this->bodyOf('/api/v1/no-such-endpoint');

        config(['app.debug' => true]);
        $loud = $this->bodyOf('/api/v1/no-such-endpoint');

        // Same keys, so a client written against one deployment works against
        // the other. `debug` is the one allowed addition.
        $this->assertSame(
            array_keys($quiet),
            array_values(array_diff(array_keys($loud), ['debug'])),
            'the envelope changed shape with APP_DEBUG — a client cannot branch on that.',
        );

        $this->assertSame('NOT_FOUND', $loud['code']);
        $this->assertSame(NotFoundHttpException::class, $loud['debug']['exception'] ?? null);
    }

    public function test_an_authentication_refusal_carries_correlation_meta(): void
    {
        $response = $this->getJson('/api/v1/profile');

        $response->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['message', 'code', 'meta' => ['request_id', 'timestamp']]);
    }

    public function test_a_validation_failure_keeps_its_errors_and_gains_meta(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => '',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'message',
                'code',
                'errors',
                'meta' => ['request_id', 'timestamp'],
            ]);
    }
}
