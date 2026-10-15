<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four response headers `docs/base/security/web-security.md` promises.
 *
 * The CSP has its own test; what this one pins is the rest of the table, and the
 * two scope decisions that are easy to lose in a refactor:
 *
 * - the headers are on GLOBAL middleware, so a JSON response carries them too;
 * - the policy itself stays HTML-only, so adding them globally did not start
 *   putting a Content-Security-Policy on the API.
 *
 * Values are asserted exactly rather than "present", because the interesting
 * failure is a header that is sent with the wrong value: `preload` on HSTS is a
 * decision the owner has not made, and `max-age=1` would ship looking green.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** The exact values `web-security.md` records, or the doc and the code drift. */
    private const EXPECTED = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        // `preload` is deliberately absent: enrolling the domain is effectively
        // irreversible for ~18 months and is an owner decision, not a default.
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ];

    public function test_a_page_carries_the_origin_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        foreach (self::EXPECTED as $name => $value) {
            $response->assertHeader($name, $value);
        }

        $response->assertHeader(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        );
    }

    /**
     * Origin decisions, not page decisions: a JSON response gets them too.
     *
     * And the CSP still does not — that check is the reason this test exists
     * alongside ContentSecurityPolicyTest rather than inside it.
     */
    public function test_the_json_api_carries_them_but_not_the_policy(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        foreach (self::EXPECTED as $name => $value) {
            $response->assertHeader($name, $value);
        }

        $response->assertHeaderMissing('Content-Security-Policy');
    }

    /**
     * A redirect carries them too — the response a form submission actually
     * receives is usually this one, and it is the response that sets the cookie
     * HSTS and SameSite are protecting.
     *
     * The token is fetched rather than the middleware switched off: a POST
     * without one answers 419 here, which is the #7 control from the security
     * report doing its job, and bypassing it to make this test pass would be the
     * exact inversion that report warns about.
     */
    public function test_a_redirect_carries_them(): void
    {
        $this->get('/login')->assertOk();
        $token = session()->token();

        $response = $this->post('/login', [
            '_token' => $token,
            'email' => 'nobody@example.com',
            'password' => 'wrong-password-on-purpose',
        ]);

        $response->assertRedirect();

        foreach (self::EXPECTED as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }
}
