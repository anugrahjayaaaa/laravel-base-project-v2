# Web Security

## Secure Defaults

| Header | Recommended Value |
|--------|------------------|
| HTTPS | Enforce (redirect HTTP to HTTPS) |
| HSTS | `max-age=31536000; includeSubDomains; preload` |
| Content-Security-Policy | Strict, allowlist only |
| X-Content-Type-Options | `nosniff` |
| Referrer-Policy | `strict-origin-when-cross-origin` |
| Permissions-Policy | Restrictive defaults |
| X-Frame-Options | `DENY` or `SAMEORIGIN` (frame protection) |
| Cookie Secure | `true` |
| Cookie SameSite | `Lax` or `Strict` |
| CSRF | Laravel Sanctum/VerifyCsrfToken middleware |
| CORS | Restrict to known origins |

Only `Content-Security-Policy` in that table is sent by the application today;
the rest are recommendations this project has not implemented, and a header
missing from a response is a separate gap from a header configured wrongly.

## Content-Security-Policy

`App\Http\Middleware\AddContentSecurityPolicy`, appended to the `web` group and
set **only** on `text/html` responses — the JSON API carries no document for a
policy to govern, and a header written onto the streamed CSV export is the
classic way to corrupt the stream.

| Directive | Value | Reason |
|-----------|-------|--------|
| `script-src` | `'self' 'nonce-…'` | The XSS primitive. A nonce and NOTHING else: no `'unsafe-inline'`, no `'unsafe-eval'`, so an injected `<script>`, a surviving inline handler or a `javascript:` URL does not run. |
| `style-src` | `'self' 'unsafe-inline'` | Accepted compromise — see below. |
| `img-src` / `font-src` | `'self' data:` + allowed origins | Inline SVG and data URIs on the notification and channel screens. |
| `connect-src` | `'self'` (+ the dev server while it runs) | The two pollers fetch from this origin and nowhere else. |
| `object-src` / `base-uri` / `form-action` | `'none'` / `'self'` / `'self'` | The three exfiltration routes that are not script. |
| `frame-ancestors` | `'none'` | Answers the `X-Frame-Options` row above; the header itself is not sent. |

Allowed external origins are the asset allowlist, named once in the middleware:
`https://fonts.bunny.net` (fonts) and `https://unpkg.com` (Stoplight Elements on
`/docs/api`). Naming an origin is the allowlist doing its job — a path-shaped
exemption breaks the moment the path moves.

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