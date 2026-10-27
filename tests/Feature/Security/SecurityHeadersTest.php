<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The four response headers `docs/base/security/web-security.md` promises.
 *
 * The CSP has its own test; what this one pins is the rest of the table, and the
 * three scope decisions that are easy to lose in a refactor:
 *
 * - the headers are on GLOBAL middleware, so a JSON response carries them too —
 *   including the policy, which governs nothing there but keeps the set uniform;
 * - frame protection is `frame-ancestors 'none'` in that policy, not a second
 *   `X-Frame-Options` header (D-1 in `phase-13-security-hardening.md`).
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
        'Permissions-Policy' => 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()',
    ];

    /**
     * Every view that renders unescaped output, and how many times.
     *
     * `{{ }}` is the XSS primitive and it is already the default; `{!! !!}` is
     * the exception that has to be argued for. A new one fails this test until it
     * is either escaped or listed here with a reason — which is the difference
     * between an audit that happened once and a control that keeps holding.
     *
     * - `pages/users/index`: sort icons, constant markup produced by a closure.
     * - `vendor/pulse`: the package's own CSS/js emitters; vendor code, not ours.
     */
    private const UNESCAPED_OUTPUT = [
        'pages/users/index.blade.php' => 3,
        'vendor/pulse/components/pulse.blade.php' => 2,
    ];

    public function test_a_page_carries_the_origin_headers(): void
    {
        $response = $this->get('/login')->assertOk();

        foreach (self::EXPECTED as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    /**
     * Origin decisions, not page decisions: a JSON response gets them too,
     * the policy included.
     */
    public function test_the_json_api_carries_them_too(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        foreach (self::EXPECTED as $name => $value) {
            $response->assertHeader($name, $value);
        }

        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $response->headers->get('Content-Security-Policy'),
            'the API lost the policy, so the header set is no longer uniform',
        );
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

    /**
     * Frame protection is one decision, in one place.
     *
     * `frame-ancestors 'none'` is stricter than `X-Frame-Options: SAMEORIGIN`,
     * and sending both would make two headers the source of truth for one rule.
     * The absence is asserted so "add the missing header" cannot be applied as a
     * fix without meeting this test first.
     */
    public function test_frame_protection_is_the_policy_not_a_second_header(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeaderMissing('X-Frame-Options');

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy'),
            'frame protection disappeared: no header and no directive means the page can be framed',
        );
    }

    /**
     * The unescaped-output allowlist, scanned rather than remembered.
     *
     * Blade comments are stripped first: several of them discuss `{!! !!}` in
     * prose, and counting those would turn the allowlist into a list of
     * sentences that has to be edited whenever someone improves a comment.
     */
    public function test_unescaped_output_is_limited_to_the_declared_sites(): void
    {
        $found = [];

        foreach (File::allFiles(resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $view->getContents());
            $count = preg_match_all('/\{!!.*?!!\}/s', (string) $markup);

            if ($count > 0) {
                $found[$view->getRelativePathname()] = $count;
            }
        }

        ksort($found);

        $expected = self::UNESCAPED_OUTPUT;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            'a view renders unescaped output that is not on the allowlist — use {{ }} or justify it in UNESCAPED_OUTPUT',
        );
    }
}
