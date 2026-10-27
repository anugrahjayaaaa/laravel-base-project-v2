# Web Security

## Secure Defaults

| Header | Recommended Value | Sent by the application (2026-10-15) |
|--------|-------------------|----------------------------------------|
| HTTPS | Enforce (redirect HTTP to HTTPS) | Deployment decision (reverse proxy) |
| HSTS | `max-age=31536000; includeSubDomains; preload` | ✅ `max-age=31536000; includeSubDomains` — `preload` deliberately withheld, see below |
| Content-Security-Policy | Strict, allowlist only | ✅ nonce policy, documented below |
| X-Content-Type-Options | `nosniff` | ✅ |
| Referrer-Policy | `strict-origin-when-cross-origin` | ✅ |
| Permissions-Policy | Restrictive defaults | ✅ every device feature this app uses (none) denied |
| X-Frame-Options | `DENY` or `SAMEORIGIN` (frame protection) | ✗ answered by `frame-ancestors 'none'` in the policy instead — same decision, one source of truth |
| Cookie Secure | `true` | `env('SESSION_SECURE_COOKIE')` — deployment decision, like `APP_DEBUG` |
| Cookie SameSite | `Lax` or `Strict` | ✅ `Lax` (`config/session.php` default) |
| CSRF | Laravel Sanctum/VerifyCsrfToken middleware | ✅ including under test — see `VerifyCsrfToken::runningUnitTests()` |
| CORS | Restrict to known origins | No `config/cors.php`; nothing opts in |

A header missing from a response is a different gap from a header configured
wrongly, so the third column is asserted rather than asserted-about:
`tests/Feature/Security/SecurityHeadersTest.php` checks the four response
headers and `ContentSecurityPolicyTest` checks the policy.

## Response Headers

All four are set by `App\Http\Middleware\AddSecurityHeaders`, on **every**
response the application makes — it is appended to global middleware, because
each of these is a decision about this origin rather than about one page:

| Header | Value | Reasoning |
|--------|-------|-----------|
| `X-Content-Type-Options` | `nosniff` | Most valuable on the streamed CSV download — the file a browser is most likely to sniff into something it will execute. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Full URL same-origin, origin only cross-origin, nothing over a downgrade. |
| `Permissions-Policy` | `accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()` | The app has a use for none of them. An absent header means "the browser's default", and the default for `camera` is not "no". |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Sent unconditionally rather than gated on `$request->isSecure()`: a browser honours it only over https, so a dev server's plain-http answer is ignored, while gating it would drop the header behind any TLS terminator absent from `TrustProxies`. |

**`preload` is withheld on purpose.** Submitting a domain to the HSTS preload
list is effectively irreversible for ~18 months and outlives this header being
removed, so it is an owner decision and not a default: add `; preload` to the
value above when that decision is made.

## Content-Security-Policy

`App\Http\Middleware\AddSecurityHeaders`, appended to global middleware, sets the
policy on **every** response, the JSON API and the streamed CSV included. It only
governs a document, so on those it is inert — it is sent anyway because a header
set that changes with `Content-Type` is one a proxy or a scanner has to
special-case, and "is this response hardened" should have one answer. Setting it
here is safe for a file response: this runs before the response is returned, which
is before a `StreamedResponse` starts sending. A header set after output has begun
is what corrupts a stream. The nonce is shared on every request and read only
where a view renders.

| Directive | Value | Reason |
|-----------|-------|--------|
| `script-src` | `'self' 'nonce-…'` (+ `'unsafe-eval'` on `/pulse` and `/vantage`) | The XSS primitive. A nonce and nothing else: no `'unsafe-inline'`, so an injected `<script>`, a surviving inline handler or a `javascript:` URL does not run. The two vendor dashboards add `'unsafe-eval'` alone — see below. |
| `style-src` | `'self' 'unsafe-inline'` | Accepted compromise — see below. |
| `img-src` / `font-src` | `'self' data:` + allowed origins | Inline SVG and data URIs on the notification and channel screens. |
| `connect-src` | `'self'` (+ `https://unpkg.com` on `/vantage`, + the dev server while it runs) | The two pollers fetch from this origin and nowhere else; Vantage's Lucide source map is the one cross-origin fetch. |
| `object-src` / `base-uri` / `form-action` | `'none'` / `'self'` / `'self'` | The three exfiltration routes that are not script. |
| `frame-ancestors` | `'none'` | Answers the `X-Frame-Options` row above; the header itself is not sent. |

Allowed external origins are the asset allowlist, named once in the middleware:
`https://fonts.bunny.net` (fonts) and `https://unpkg.com` (Stoplight Elements on
`/docs/api`). Naming an origin is the allowlist doing its job — a path-shaped
exemption breaks the moment the path moves. Two more origins —
`https://cdn.tailwindcss.com` and `https://cdn.jsdelivr.net` (Tailwind's Play CDN
and Chart.js, both loaded by Vantage's vendor layout) — join `script-src` **only**
on `vantage.*`, so the allowlist does not grow for the pages that do not use them.

**`'unsafe-eval'` on the two vendor dashboards, and there only.** Both are pages
this application does not write, and both ship a script that builds code at run
time:

- `/pulse` inlines Livewire's normal JavaScript build (`Pulse::js()` hardcodes
  `dist/livewire.js`, so the CSP-safe build is never in play), and that build's
  Alpine evaluates every `x-data` and `wire:*` expression by constructing a
  function — `new Function`, which CSP blocks unless `script-src` names
  `'unsafe-eval'`. Without it the page is 200 but every expression throws: the
  `#[Lazy]` cards never leave their `animate-pulse` skeletons and the dashboard
  sits loading forever.
- `/vantage` loads Tailwind's Play CDN, which compiles the utility classes it sees
  in the DOM into a stylesheet the same way. Without `'unsafe-eval'` the page is
  200 and completely unstyled.

The allowance is scoped by **route name** (`policy($nonce, $request->routeIs('pulse'),
$request->routeIs('vantage.*'))`, evaluated after `$next()` when the route is
already resolved), not by a path string, so it cannot outlive a route move — and
neither script runs anywhere else in this application, so no other page's policy
changes. What the exception does not concede: the nonce stays mandatory (it is not
`'unsafe-inline'`), so a raw injected `<script>` still does not run. What it
concedes is that an injected Alpine or Tailwind expression on those read-only,
permission-gated, feature-flagged observability pages could evaluate — the cost
accepted for two vendor pages this application does not write.

**Vendor views are overridden to carry the nonce.** A package view is not a view
this application can add `<script @nonce>` to, so the four Vantage views that ship
an inline script are overridden under `resources/views/vendor/vantage/`
(`loadViewsFrom` registers that path ahead of the package's). The vendor's inline
`onclick`/`onchange` handlers are the same problem as the sortable headers above —
they never run under this policy — so they are re-bound by id inside the nonced
script. `ContentSecurityPolicyTest` reads the rendered Vantage pages through the
DOM and fails if an upgrade drifts a script back to a nonce-less `<script>`.

**Nonce, not a hash list.** Thirteen inline `<script>` blocks across twelve views,
three more on the API docs page: a hash list would mean re-hashing all sixteen
every time one line of one changes, and a policy that fails closed on an unrelated
edit is a policy somebody deletes. `<script @nonce>` at the call site (the Blade
directive lives in `AppServiceProvider`), so the attribute name and the variable
cannot drift apart.

**Inline handlers are gone, not neutered.** `onclick`/`onsubmit` never run under
this `script-src`, so every one was converted: submit buttons carry
`data-disable-on-submit` and the filter form carries `data-prevent-submit`, both
handled by the delegated listeners in `resources/js/helpers/form-guards.js`; the
sortable headers on the users table are real `<a href>` links (they were
`onclick="window.location.href=…"` on a `<th>`, which a keyboard user could not
reach at all).

**`style-src` is deliberately not strict.** 113 inline `style=` attributes, and an
inline style *attribute* is governed by `style-src`, not by a script nonce —
keeping them strict means `'unsafe-inline'` or one hash per attribute. CSS
injection is a much weaker primitive than script injection: there is no
`javascript:` equivalent for a stylesheet, and the routes that matter
(`form-action`, `object-src`, `base-uri`) are strict above. Converting 113
attributes to utility classes is its own project, and the asymmetry is why the
compromise is acceptable rather than merely tolerated.

**The dev server is allowed only while it is running.** `npm run dev` writes
`public/hot` and serves the entrypoints from its own origin, which `script-src
'self'` would block — a 200 page whose JavaScript never loads. The origin (and
its `ws://` HMR socket) joins the policy only while that file exists, so a
production install, which has no hot file, cannot inherit it.

Guarded by `tests/Feature/Security/ContentSecurityPolicyTest.php`, which asserts
the header and the rendered HTML **together** — a nonce nobody prints blocks the
page, and a printed nonce the policy does not carry blocks nothing. What the
policy then binds on every view author — `<script @nonce>` and nothing else,
no inline handlers, the data-attribute pattern for behaviour — is written up as
a standard in `docs/base/ui/design-system.md` (Content Security Policy — What a
View May Contain), with the self-check greps for views the test does not cover.

## CSRF Protection

- Laravel's built-in CSRF tokens via `VerifyCsrfToken` middleware.
- API routes use `api` middleware group (stateless) or Sanctum SPA tokens.
- Web routes require CSRF token for all state-changing requests.
- A missing or stale token answers **419**: the framework's error page for a form
  submission, the `CSRF_TOKEN_MISMATCH` envelope for a JSON caller. The
  middleware's `except` list stays empty, and `runningUnitTests()` returns `false`
  so the check is enforced in the suite too.

## CORS Policy

- CORS is handled natively by Laravel's `HandleCors` middleware (Laravel 11+).
- Restrict to known frontend origins.
- Do not use wildcard `*` for credentials-enabled endpoints.

## Frontend Compatibility

Security policies must remain compatible with:
- Blade server-rendered pages
- Vue SPA
- React SPA
- Mobile applications

## Security Audit

All security-relevant changes must be reviewed:
- Input validation at trust boundaries
- Authorization checks (Policy-based)
- SQL injection prevention (Eloquent/query builder)
- XSS prevention (escaping in views)
- Session fixation prevention
- Password hashing (bcrypt/Argon2)
- Secret management (never in code)