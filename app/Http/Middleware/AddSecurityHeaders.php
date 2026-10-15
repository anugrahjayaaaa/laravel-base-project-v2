<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers this application sets on a response.
 *
 * `docs/base/security/web-security.md` is the source of truth for WHAT is sent;
 * this class is the single place that sends it, so nobody has to hunt for the
 * CSP in one middleware and the rest in another.
 *
 * ## Content-Security-Policy, with a per-request nonce
 *
 * What a strict allowlist policy means concretely is decided here rather than
 * left to whoever adds it: `script-src` carries a nonce and NOTHING else — no
 * `'unsafe-inline'`, no `'unsafe-eval'` — so an injected `<script>` tag, an
 * injected inline handler or a `javascript:` URL does not run. That is the XSS
 * primitive, and closing it is the reason this middleware exists.
 *
 * ## Why the nonce and not a hash list
 *
 * Thirteen inline `<script>` blocks across twelve views, three more on the API
 * docs page. A hash list would mean listing all sixteen in this class and
 * re-hashing them every time one line of one of them changes — a policy that
 * fails closed on an unrelated edit, which is how policies get removed. The nonce
 * travels with the response, so the browser only trusts scripts the server chose
 * to send this time.
 *
 * `View::share()` rather than a container binding because a Blade view has no
 * other way to reach it, and a directive that echoes it is the only ergonomic
 * spelling at the call site (`<script @nonce>`).
 *
 * ## Why `style-src` is NOT strict, and why that is not the same problem
 *
 * There are 113 `style="..."` attributes across the views. `style-src` cannot be
 * strict and keep them: an inline style ATTRIBUTE is governed by `style-src`, not
 * by a nonce, so it needs `'unsafe-inline'` or `'unsafe-hashes'` plus a hash per
 * attribute. Converting 113 attributes to utility classes is its own project.
 *
 * CSS injection is a much weaker primitive than script injection — there is no
 * equivalent of `javascript:` for a stylesheet, and the exfiltration routes that
 * matter (`form-action`, `object-src`, `base-uri`) are all strict below. The
 * asymmetry is the reason this compromise is acceptable; it is recorded in
 * `docs/base/security/web-security.md` rather than left implicit here.
 *
 * ## `https://unpkg.com`
 *
 * `/docs/api` is Stoplight Elements, which loads itself from unpkg. An allowlist
 * is an explicit list of origins — naming one is the allowlist doing its job.
 * Excluding the route by path instead would be a path-shaped exemption that
 * breaks the moment the route moves.
 *
 * ## The other four headers
 *
 * The rest of the table in `docs/base/security/web-security.md` is answered here
 * as well, on every response this application makes — JSON included, because
 * these are browser-origin decisions rather than page decisions:
 *
 * - `X-Content-Type-Options: nosniff` — most valuable on the streamed CSV
 *   download, which is exactly the file a browser might otherwise sniff into
 *   something it is willing to execute.
 * - `Referrer-Policy: strict-origin-when-cross-origin` — the document's own
 *   recommended value: full URLs on same-origin navigation, origin only
 *   cross-origin, nothing on downgrade.
 * - `Permissions-Policy` denies every device feature this application has a use
 *   for (none), so the grant list starts closed and a use case has to open it
 *   deliberately.
 * - `Strict-Transport-Security` — sent unconditionally rather than gated on
 *   `$request->isSecure()`. A browser honours the header only when it arrives
 *   over https, so a plain-http dev server's answer is ignored instead of
 *   harmful, while gating it would silently drop the header behind any TLS
 *   terminator that is not listed in `TrustProxies`.
 *
 * `X-Frame-Options` is deliberately ABSENT: `frame-ancestors 'none'` above is the
 * same answer in the policy browsers still read, and sending both would make two
 * headers the two sources of truth for one decision.
 *
 * `preload` is deliberately absent too. Enrolling a domain in the HSTS preload
 * list is effectively irreversible for 18 months — it outlives this header being
 * removed — so it belongs to whoever owns the domain, not to a default. Add
 * `; preload` here when that decision is made.
 */
class AddSecurityHeaders
{
    /**
     * Origins that are not this application. See the class docblock for why each
     * one is here; `https://unpkg.com` is the API-docs renderer.
     */
    private const ASSET_ORIGINS = [
        'https://fonts.bunny.net',
        'https://unpkg.com',
    ];

    /**
     * Every device feature this application has a use for: none of them.
     *
     * Written as an allowlist of denials rather than left unset, because an
     * absent header is "whatever the browser's default is", and the default for
     * `camera` is not "no".
     */
    private const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(40));

        // Shared before the view renders, which is inside `$next()`. A redirect
        // never renders a view and still gets a nonce — harmless, and the
        // alternative is threading the value through the response instead.
        View::share('cspNonce', $nonce);

        $response = $next($request);

        // Set BEFORE the CSP block and unconditionally: these four are origin
        // decisions, they apply to JSON and to the streamed CSV as much as to a
        // page, and a StreamedResponse only refuses a header once it has started
        // sending — which is after this line.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        // CSP only for HTML: on JSON there is no document for a policy to
        // govern, and on a file response it would govern nothing. The four
        // headers above are written on every response including the streamed
        // CSV — safely, because this runs before the response is returned and
        // therefore before a StreamedResponse starts sending. A header set
        // after output has begun is what corrupts a stream.
        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    /**
     * The policy, as a single header value.
     *
     * `connect-src` is `'self'` because the two pollers fetch from this origin
     * and nowhere else; `img-src` allows `data:` because the notification and
     * channel screens render inline SVG and data URIs. `frame-ancestors 'none'`
     * replaces `X-Frame-Options`, which this policy subsumes.
     */
    private function policy(string $nonce): string
    {
        $assets = implode(' ', self::ASSET_ORIGINS);

        // `npm run dev` serves the entrypoints from Vite's own origin instead of
        // from `public/build`, and a `script-src 'self'` that does not know it
        // leaves a developer with an application whose JavaScript never loads. The
        // allowance exists only while `public/hot` does — a production install has
        // no hot file, so the origin cannot travel to production with a deploy.
        $dev = Vite::devServerUrl();
        $devAssets = $dev === null ? '' : ' '.$dev;
        // HMR is a websocket, and `http://host` in `connect-src` does not match
        // `ws://host`: the scheme has to be named or the socket never opens.
        $devConnect = $dev === null ? '' : ' '.$dev.' '.preg_replace('#^http#', 'ws', $dev);

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$assets}{$devAssets}",
            "style-src 'self' 'unsafe-inline' {$assets}{$devAssets}",
            "img-src 'self' data: {$assets}{$devAssets}",
            "font-src 'self' data: {$assets}{$devAssets}",
            "connect-src 'self'{$devConnect}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
