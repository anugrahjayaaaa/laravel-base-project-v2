<?php

namespace Tests\Feature\CrossCutting;

use App\Exceptions\LastSuperadminException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;
use Throwable;

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

    /**
     * One case per code the handler documents.
     *
     * The exceptions are raised from a throw-away route rather than through
     * each real endpoint: this pins the mapping in `bootstrap/app.php` (class →
     * status → code), which is the part every endpoint shares. Endpoint-level
     * tests already prove the same codes are reachable in context.
     *
     * @return array<string, array{int, string, bool}>
     */
    public static function contractStatuses(): array
    {
        return [
            '400' => [400, 'INVALID_TOKEN', false],
            '401' => [401, 'UNAUTHENTICATED', false],
            '403' => [403, 'FORBIDDEN', false],
            '404' => [404, 'NOT_FOUND', false],
            '409' => [409, 'LAST_SUPERADMIN', false],
            '422' => [422, 'VALIDATION_ERROR', true],
            '429' => [429, 'RATE_LIMITED', false],
            '500' => [500, 'SERVER_ERROR', false],
        ];
    }

    #[DataProvider('contractStatuses')]
    public function test_every_contract_status_is_emitted_in_the_shared_envelope(
        int $status,
        string $code,
        bool $hasErrors,
    ): void {
        config(['app.debug' => false]);

        Route::get("/api/v1/contract/{$status}", fn () => throw $this->exceptionFor($status));

        $response = $this->getJson("/api/v1/contract/{$status}");

        $response->assertStatus($status)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('code', $code);

        $body = $response->json();

        $this->assertIsString($body['message']);
        $this->assertSame($hasErrors, array_key_exists('errors', $body));
        $this->assertIsString($body['meta']['request_id']);
        $this->assertNotSame('', $body['meta']['request_id']);
        $this->assertIsString($body['meta']['timestamp']);
        $this->assertArrayNotHasKey('debug', $body, 'debug detail must not leak when APP_DEBUG is off.');
    }

    private function exceptionFor(int $status): Throwable
    {
        return match ($status) {
            400 => new InvalidSignatureException(),
            401 => new AuthenticationException(),
            403 => new AuthorizationException('Forbidden.'),
            404 => new NotFoundHttpException(),
            409 => new LastSuperadminException(),
            422 => ValidationException::withMessages(['field' => ['Invalid.']]),
            429 => new ThrottleRequestsException(),
            500 => new RuntimeException('boom'),
        };
    }
}
