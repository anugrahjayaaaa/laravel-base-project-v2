<?php

namespace Tests\Unit\Requests;

use App\Http\Requests\V1\User\CreateUserRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The 422 envelope every Form Request in the project shares.
 *
 * Before Phase 12 the four Auth requests that used `FormatsApiErrors`
 * individually answered `{message, errors, code, meta}` while the other
 * seventeen answered Laravel's `{message, errors}`. Both paths are pinned here,
 * on the base class rather than per request, so a request added tomorrow
 * inherits the contract or fails this test.
 *
 * The 422 path is the trait's: `BaseFormRequest` using `FormatsApiErrors` is the
 * only thing that produces `code`/`meta` on a validation failure. Drop that
 * `use` and the first test goes red.
 */
class BaseFormRequestContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_validation_failure_returns_the_shared_envelope(): void
    {
        // A real route with a real request class — LoginRequest is the simplest
        // BaseFormRequest, and its endpoint is unauthenticated.
        $response = $this->postJson(route('api.v1.auth.login'), ['identifier' => '']);

        $response->assertStatus(422);

        $body = $response->json();

        // Key ORDER is not the contract; the set is.
        $this->assertEqualsCanonicalizing(
            ['message', 'errors', 'code', 'meta'],
            array_keys($body),
        );

        $this->assertSame('The given data was invalid.', $body['message']);
        $this->assertSame('VALIDATION_ERROR', $body['code']);
        // LoginRequest::messages() — proves the request's own messages survive.
        $this->assertSame(['Identifier is required.'], $body['errors']['identifier']);

        $this->assertIsString($body['meta']['request_id']);
        $this->assertNotSame('', $body['meta']['request_id']);
        $this->assertNotFalse(strtotime($body['meta']['timestamp']));
        $this->assertSame(
            $response->headers->get('X-Request-ID'),
            $body['meta']['request_id'],
            'meta.request_id must be the id the correlation middleware assigned.',
        );
    }

    /**
     * A refused `authorize()` is not a validation failure: it never reaches
     * `failedValidation`, so the envelope comes from the exception handler
     * instead. Same four keys, no `errors` — a caller must be able to tell the
     * two apart by `code`, not by whether the body happens to be JSON.
     */
    public function test_an_authorization_failure_returns_the_shared_envelope(): void
    {
        Route::post('api/test/base-request-authorization', fn (CreateUserRequest $request) => response()->json([]));

        // CreateUserRequest::authorize() is `$this->user()?->can(...) ?? false`,
        // so a guest is refused without any permission plumbing.
        $response = $this->postJson('/api/test/base-request-authorization', []);

        $response->assertStatus(403);

        $body = $response->json();

        $this->assertSame('FORBIDDEN', $body['code']);
        $this->assertSame('This action is unauthorized.', $body['message']);
        $this->assertArrayNotHasKey('errors', $body);

        $this->assertIsString($body['meta']['request_id']);
        $this->assertNotSame('', $body['meta']['request_id']);
        $this->assertNotFalse(strtotime($body['meta']['timestamp']));
    }
}
