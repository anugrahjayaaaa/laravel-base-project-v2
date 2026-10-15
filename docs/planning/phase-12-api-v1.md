# Phase 12 — API V1 (web/API gap audit)

> Raised 2026-10-13 by comparing the registered route tables rather than by
> reading the feature docs, after Phase 10's export work surfaced that the audit
> trail existed on one channel only.

## Headline

**Every WRITE to the audit trail went through the shared Actions from day one.
The READ side existed on one channel.** So the gap was transport and
serialization, not logic — and that is the reassuring part of this document. The
unreassuring part is that it went unnoticed through a whole phase, which says the
check that would have caught it is manual and has to be deliberate.

This is ADR-001 and ADR-002 ("all core capabilities have a clean API boundary",
"the boundary must remain usable if the UI is replaced") failing silently. Neither
is a rule you can hold an implementation to on review; they are only visible
when you diff the two route lists.

## What the audit found

Method: `php artisan route:list`, grouped by **capability** rather than by URI.
Path shape is not a gap — `POST /login` and `POST /api/v1/auth/login` are one
capability with two addresses, and reading the URI as the answer produces a
false-positive on almost every auth route.

Result at the time: 31 capability pairs matched, and these did not.

| # | Gap | Severity | Status |
|---|-----|----------|--------|
| 1 | Audit trail — no API at all | 🔴 | **Closed** |
| 2 | Notification inbox — no API at all | 🔴 | **Closed** |
| 3 | `roles.show` — API only | 🟢 | Documented, not a gap |
| 4 | `api.v1.auth.unlock` — closure shim | 🟡 | Documented |
| 5 | Verb mismatches on four capabilities | — | **Was not a gap** |

### 1. Audit trail — CLOSED

Browser-only: `GET /activity-logs`, `GET /activity-logs/{activity}`,
`POST /activity-logs/exports`, `GET /activity-logs/exports/{export}/download`.

Now `App\Http\Controllers\Api\V1\Audit\AuditController` with
`ActivityResource` and `AuditExportResource`, under `feature:activity_logs` and
the same `audit.view` / `audit.export` split the web routes carry. The export
routes reuse the **same `audit-export` limiter**, so arriving over HTTP is not a
way to get a larger allowance than a browser gets.

Three decisions worth keeping:

- **`POST` returns 202 and the row**, not a bare acknowledgement. The file does
  not exist yet, and the client needs the id to poll and the filters back to tell
  five exports apart without keeping its own state.
- **The resource never emits `path`.** A disk path in an API response is a
  private-storage location handed to a second consumer, and one that keeps
  working in that consumer's head after the sweep unlinks the file. A client
  downloads through the route, as the web viewer's button does.
- **`{activity}` is constrained to a number.** `/activity-logs/exports` is one
  segment shorter than the download route, so an unconstrained parameter
  swallows it depending on registration order — a literal path losing to a
  parameter, which reads as an endpoint that is absent rather than one that is
  shadowed. Applied to the web twin for the same reason.

### 2. Notification inbox — CLOSED

The inversion this found is the memorable part: the API could read a user's
notification **settings** — which channels are switched on — and could not read
a single notification. The preference without the thing the preference is about.

`App\Http\Controllers\Api\V1\Notification\NotificationInboxController`, ungated
on both channels and for the same reason: a notification belongs to the person
it was sent to, "whose inbox is this" is answered by the token, and there is no
permission name to check. `NotificationInboxAction` is shared, so marking read
invalidates the cached count and cached list exactly as the web path does.

**`SafeUrl` was extracted for this.** The same-origin check on
`notification.data['url']` was a private method on `NotificationInboxAction`,
guarding the web view. The API channel put the same question in a second place,
and a second copy of a security check is the copy that gets forgotten when one of
them is edited. Both call `App\Support\SafeUrl::actionFor()` now. The API
pentest probes it with `javascript:`, `//evil.example`, an absolute URL and a
slashless relative path.

### 3. `roles.show` — API only, and that is correct

The web has no role detail page because `roles.edit` **is** the detail view: it
loads the same `permissions` relation and the same `loadCount('users')` as the
API's `show`. Adding `routes.show` would be a second page rendering a second
copy of the same facts. Recorded here so the next audit does not file it again
as an open gap.

### 4. `api.v1.auth.unlock` — a redirect, on purpose

`routes/api.php` keeps a closure that redirects to
`api.v1.users.unlock`, for clients addressing the endpoint from before it moved
under `/users/{user}`. Scramble cannot introspect a closure, so it is absent from
the generated docs — which is correct: a client reading the docs should use the
current address. The cost is now written down rather than looking like an
oversight.

### 5. Verb mismatches — not a gap, and worth not "fixing"

Four capabilities use `POST` on the web and `PUT` on the API: settings, profile
password change, notification settings, notification channels.

This looks like drift and is not. **A browser form can only send GET or POST.**
`password-change-form.blade.php` posts with `@method('PUT')`, which is Laravel's
method spoofing and is working. So the web verb is dictated by the platform and
the API verb by REST; aligning them would mean either breaking the JSON API or
misrepresenting what a form can do. The Actions are already shared, so the
behaviour cannot diverge — only the spelling does, and the spelling is correct on
both sides.

Flagged here because it is the kind of thing an audit finds and an eager
implementer "fixes" into a bug.

## Follow-ups

| # | Item | Why |
|---|------|-----|
| P12-1 | A test that diffs the two route tables by capability | The check is manual and this phase proves it can be missed silently |
| P12-2 | The other three rate limiters (`user-state-actions`, `bulk-action`, `send-test-mail`) are still literals | Phase 10 §11 P10-E9 moved only `audit-export` |
| P12-3 | Audit the API for **response** parity, not route parity | Route presence was checked here; whether two channels return the same facts was not |
| P12-4 | `app/Http/Controllers/Api/V1/NotificationController.php` and the new `Notification/` sub-namespace coexist | Worth folding into the sub-namespace pattern the other modules use |

## The lesson, stated once

Phase 9 shipped notifications on both channels. Phase 10 shipped the audit trail
on one. Both passes read as complete, and the tracker recorded them as such.

A capability is not delivered by the UI that shows it. The audit that would have
caught this is comparing `route:list` against itself — so it has to be a test,
or it has to be a person remembering to look. **P12-1 is the one that matters.**
