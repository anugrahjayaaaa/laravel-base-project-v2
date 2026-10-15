<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy, with a per-request nonce.
 *
 * `docs/base/security/web-security.md` asks for a strict allowlist policy. What
 * that means concretely is decided here rather than left to whoever adds it:
 * `script-src` carries a nonce and NOTHING else — no `'unsafe-inline'`, no
 * `'unsafe-eval'` — so an injected `<script>` tag, an injected inline handler or
 * a `javascript:` URL does not run. That is the XSS primitive, and closing it is
 * the reason this middleware exists.
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
 * attribute. Converting 114 attributes to utility classes is its own project.
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
 */
class AddContentSecurityPolicy
{
    /**
     * Origins that are not this application. See the class docblock for why each
     * one is here; `https://unpkg.com` is the API-docs renderer.
     */
    private const ASSET_ORIGINS = [
        'https://fonts.bunny.net',
        'https://unpkg.com',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(Str::random(40));

        // Shared before the view renders, which is inside `$next()`. A redirect
        // never renders a view and still gets a nonce — harmless, and the
        // alternative is threading the value through the response instead.
        View::share('cspNonce', $nonce);

        $response = $next($request);

        // HTML only. The JSON API and the audit CSV download must not carry this:
        // a Content-Security-Policy on a file response is meaningless, and setting
        // a header on a streamed response is the classic way to corrupt it.
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
