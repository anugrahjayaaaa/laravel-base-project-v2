<?php

namespace Tests\Feature\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The CORS grant as a browser sees it.
 *
 * The interesting half of CORS is the refusal: an origin nobody configured must
 * not receive an allow header, and no response may pair `*` with credentials.
 * Both are asserted here against the real middleware, not against the config
 * array alone — a config that is right and a middleware that never reads it look
 * identical from the array.
 *
 * Two configured origins, not one, in the refusal probes: `fruitcake/php-cors`
 * sets `Access-Control-Allow-Origin` to the single configured origin even when
 * the request's `Origin` is foreign (the browser rejects the mismatch, so it is
 * not a grant). The dynamic echo-or-omit branch needs at least two, and the
 * single-origin shape gets its own assertion below rather than being assumed.
 */
class CorsPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function allowOrigins(string ...$origins): void
    {
        config([
            'cors.allowed_origins' => array_values($origins),
            'cors.supports_credentials' => $origins !== [],
        ]);
    }

    private function preflight(string $origin): TestResponse
    {
        return $this->options('/api/v1/health', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, x-request-id',
        ]);
    }

    /**
     * `HandleCors` ships in Laravel's global stack. Registering it again in
     * `bootstrap/app.php` would run it twice; this is the guard against that
     * edit, not a substitute for the framework's default.
     */
    public function test_handle_cors_is_global_exactly_once(): void
    {
        $global = $this->app->make(Kernel::class)->getGlobalMiddleware();

        $this->assertSame(
            1,
            count(array_keys($global, HandleCors::class)),
            'HandleCors must be in the global stack exactly once',
        );
    }

    public function test_a_configured_origin_gets_exactly_the_preflight_grant(): void
    {
        $this->allowOrigins('https://app.example.com', 'https://admin.example.com');

        $response = $this->preflight('https://app.example.com')->assertStatus(204);

        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $response->assertHeader('Access-Control-Max-Age', '3600');

        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
        $this->assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));

        $allowed = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));

        foreach ([
            'authorization',
            'content-type',
            'accept',
            'x-csrf-token',
            'x-requested-with',
            'x-request-id',
        ] as $header) {
            $this->assertStringContainsString($header, $allowed, "{$header} is not accepted on a preflight");
        }
    }

    public function test_an_unlisted_origin_gets_no_grant(): void
    {
        $this->allowOrigins('https://app.example.com', 'https://admin.example.com');

        $response = $this->preflight('https://evil.example.com')->assertStatus(204);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    /**
     * One configured origin is the shape a deployment actually runs, and the
     * vendor echoes that origin regardless of who asked. The browser compares
     * it against the caller's origin, so it is not a grant — but this pins that
     * the attacker's own origin is never what comes back.
     */
    public function test_a_single_origin_deployment_never_grants_the_caller(): void
    {
        $this->allowOrigins('https://app.example.com');

        $response = $this->preflight('https://evil.example.com');

        $this->assertNotSame('https://evil.example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_actual_request_exposes_the_request_id_header(): void
    {
        $this->allowOrigins('https://app.example.com', 'https://admin.example.com');

        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://app.example.com'])->assertOk();

        $response->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        $response->assertHeader('X-Request-ID');

        $this->assertStringContainsString(
            'X-Request-ID',
            (string) $response->headers->get('Access-Control-Expose-Headers'),
        );
    }

    public function test_web_routes_emit_no_cors_headers(): void
    {
        $this->allowOrigins('https://app.example.com', 'https://admin.example.com');

        $login = $this->get('/login', ['Origin' => 'https://app.example.com'])->assertOk();
        $this->assertNull($login->headers->get('Access-Control-Allow-Origin'));

        $users = $this->get(route('users.index'), ['Origin' => 'https://app.example.com']);
        $this->assertNull($users->headers->get('Access-Control-Allow-Origin'));

        $preflight = $this->options('/login', [], [
            'Origin' => 'https://app.example.com',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $this->assertNull($preflight->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * The shipped file, whatever the environment: `paths` cannot widen to the
     * Blade pages, and the credential switch cannot disagree with the origin
     * list — `*` with credentials is an invalid pair this app must never emit.
     */
    public function test_the_shipped_config_is_default_deny(): void
    {
        $config = config('cors');
        $origins = $config['allowed_origins'];

        $this->assertSame(['api/*'], $config['paths']);
        $this->assertSame([], $config['allowed_origins_patterns']);
        $this->assertNotContains('*', $origins);
        $this->assertSame($origins !== [], $config['supports_credentials']);
        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $config['allowed_methods']);
        $this->assertContains('Authorization', $config['allowed_headers']);
        $this->assertContains('X-Request-ID', $config['allowed_headers']);
        $this->assertContains('X-Request-ID', $config['exposed_headers']);
        $this->assertSame(3600, $config['max_age']);
    }

    public function test_no_configured_origin_means_no_grant(): void
    {
        $this->allowOrigins();

        $response = $this->preflight('https://app.example.com')->assertStatus(204);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    /**
     * `CORS_ALLOWED_ORIGINS=*` is a valid uncredentialed grant and an invalid
     * credentialed one: fruitcake echoes the caller's own origin when `*` meets
     * `supports_credentials`, which turns one env typo into a credentialed read
     * of the API for every origin. The config file itself is re-evaluated, so
     * this pins the derivation, not a value the test wrote.
     */
    public function test_a_wildcard_origin_never_carries_credentials(): void
    {
        $saved = [$_ENV['CORS_ALLOWED_ORIGINS'] ?? null, $_SERVER['CORS_ALLOWED_ORIGINS'] ?? null];
        $_ENV['CORS_ALLOWED_ORIGINS'] = $_SERVER['CORS_ALLOWED_ORIGINS'] = '*';

        try {
            $config = require config_path('cors.php');
        } finally {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $saved[0];
            $_SERVER['CORS_ALLOWED_ORIGINS'] = $saved[1];
        }

        $this->assertSame(['*'], $config['allowed_origins']);
        $this->assertFalse($config['supports_credentials']);
    }
}
