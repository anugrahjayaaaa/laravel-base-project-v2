# Realtime

> Which mechanism to reach for, and which one this project has already decided
> against. Read this before adding anything that pushes.

## The one question to ask first

**Does the user's own state change while they are looking at it?**

That is the whole split. Three different answers, three different tools, and the
expensive mistake is using the third for the first two.

| The thing | Mechanism | Why |
|---|---|---|
| "My state changed" — badge, a task assigned to me, an export finished | **Poll** | The state is already in the database and already in a page the user is on. Push adds infrastructure to answer a question the next request answers too |
| A response arriving progressively — AI tokens, a progress bar over a long computation | **SSE**, via `Response::stream()` | The data flows one way, belongs to one request, and ends when the response ends. A channel would broadcast state nobody else can read |
| A genuine cross-user event — "user B did a thing, tell user A" | **Broadcast** | See §3 — not adopted, and here is what adopting it costs |

## 1. Polling

Already proven in this codebase. `layouts/partials/scripts/notification-poll.blade.php`
polls a JSON endpoint every 30 s, updates a badge, and re-renders a Blade partial
only when the newest id changed. Three decisions worth copying verbatim:

- **Only while the tab is visible.** `document.hidden` is true for a background
  tab, a minimised window and a locked screen — the exact moments nobody is
  looking at a badge.
- **One failure stops the loop** for five minutes. A poll that retries into a
  throttled or dead endpoint turns a cosmetic feature into a load generator.
- **Re-render the server's partial, do not rebuild the DOM in JavaScript.**
  `list.outerHTML = html` against the same Blade the page used means there is one
  renderer of that markup, and it cannot drift from the page.

The same pattern with a shorter interval, stopping as soon as no row is
`pending`, is `pages/activity-logs/index`'s export table. That one differs from
the bell deliberately: **a poll must have an exit condition.** The bell has none
because a notification can arrive at any moment; a table of exports only has work
to do while a row is in flight, and a loop with no exit becomes load.

Throttle any poll endpoint (`throttle:120,1` on both). The ceiling exists to stop
a retry loop, not to police a well-behaved tab.

## 2. Server-Sent Events

Native. `Illuminate\Routing\ResponseFactory::stream()` returns an SSE response
when the callback is a **generator**, with `text/event-stream`, per-chunk flush,
and `X-Accel-Buffering: no` so nginx does not buffer it:

```php
return Response::stream(function () {
    foreach ($tokens as $token) {
        yield new StreamedEvent($token);   // or: yield $token;
    }
});
```

Use it for token streaming and long computations. No package, no daemon, no
channel authorization to get wrong.

**The ceiling:** one PHP process is held per open connection. A thousand
concurrent streams is a php-fpm pool question, not an application one. Know the
pool size before promising a connection count.

## 3. Broadcast — not adopted, and here is the price

`BROADCAST_CONNECTION=log`. `laravel/reverb` is not installed, `laravel-echo` is
not installed, and **no notification in this project declares `broadcastOn()`**.

What adopting it would cost, so the next person does not have to rediscover it:

- A Composer package (`AGENTS.md`: ADR + `changelog.md` first), `config/broadcasting.php`,
  five `BROADCAST_*` keys, two npm dependencies, Echo initialised in `resources/js/app.js`,
  and a rebuilt asset bundle.
- **A second long-running PHP daemon.** This project currently has one (the queue
  worker). A daemon needs supervisor/Herd config, a `wss://` reverse proxy in
  production, and a line in every deployment and troubleshooting document.
- **A new silent failure mode.** A dead socket reads exactly like a working page.
  Polling self-heals on the next page load; a socket needs reconnect logic and a
  fallback anyway.
- **`routes/channels.php`, which does not exist yet.** Broadcasting authorization
  is a *third* gate style alongside `can:` and `feature:`, with no convention, no
  precedent and no test. It is also where IDOR lives — `private-user.{id}` without
  a check lets any authenticated user subscribe to anyone. The same class of bug
  as the ownership check in `AuditExportDownloadAction`.

### The three conditions that would change this

1. The realtime feature ships **in the base project** and genuinely needs sub-second
   delivery. A downstream project can decide for itself; the base would own the
   daemon's lifecycle forever, and that is the only version of this worth paying for.
2. A base feature **requires** push — not "might benefit", requires.
3. Three or more downstream projects have already needed it. A plan is not three
   projects.

"Nanti mungkin butuh" is not one of the three.

## 4. Permission-filtered reads are not realtime

A feature that answers "what is available to *this* user" — an AI assistant
listing what it can do, for instance — is a permission-filtered `GET`, not a
realtime stream. `PermissionCatalog::all()` / `::forResource()` plus `User::can()`
already answer it.

The security rule that makes it a contract rather than a feature: **filter on the
server, per request.** Handing a model the full permission list and asking it to
be careful is not authorization, and a prompt is not a trust boundary.

## What to write down when the first channel exists

`routes/channels.php` does not exist. The first time a broadcast channel is
declared, it needs, in the same change:

- `Broadcast::channel('user.{id}', fn ($user, $id) => (int) $user->id === (int) $id)`
  — the ownership check, matching the precedent `AuditExportDownloadAction` set
  for a route taking an attacker-supplied id.
- A test. An unauthorized subscription test, the way
  `test_another_operators_export_is_never_listed` works.
- A note in this file, replacing §3 with what was actually adopted and why.

A channel without a check is an IDOR with a WebSocket on it.
