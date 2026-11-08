# Implementation Roadmap

## Phases

| Phase | Title | Priority | Status |
|-------|-------|----------|--------|
| 0 | Architecture & project conventions | P0 | PLANNED |
| 1 | Laravel foundation & environment | P0 | DONE |
| 2 | Database foundation | P0 | DONE |
| 3 | Authentication foundation | P0 | IN PROGRESS |
| 4 | User lifecycle & user management | DONE |
| 5 | Password/security lifecycle | P0 | PLANNED |
| 6 | RBAC & authorization | P0 | PLANNED |
| 7 | Feature availability / feature flags | P1 | PLANNED |
| 8 | Settings | P1 | PLANNED |
| 9 | Notification/mail/queue | P1 | DONE |

| 10 | Audit Trail | P0 | DONE |
| 11 | Monitoring/observability | P1 | DONE |
| 12 | API V1 | P0 | DONE |
| 13 | Security hardening | P0 | DONE |
| 14 | Storage/backup/retention | P1 | DONE |
| 15 | Comprehensive testing | P0 | DONE |
| 16 | Documentation verification | P0 | DONE |
| 17 | Full regression / final review | P0 | DONE |
| 18 | General membership & loyalty system | P1 | Group A in REVIEW; B–E PLANNED (voucher moved to 19); **Group F added 2026-11-06** |
| 19 | Basic POS engine & omnichannel payment gateway | P1 | PLANNED (voucher A-slice in REVIEW; renumbered 20 → 19 on 2026-11-08, ADR-035) |
| 20 | General app dashboard & platform health metrics | P1 | PLANNED (breakdown written 2026-11-06) |

## Dependency Map

```
Laravel Foundation (Phase 1)
    ↓
Database (Phase 2)
    ↓
Authentication (Phase 3)
    ↓
User Lifecycle (Phase 4)
    ↓
Password/Security (Phase 5)
    ↓
Authorization (Phase 6)
    ↓
Feature Availability (Phase 7)
    ↓
Settings / Security (Phase 8)
    ↓
Notifications / Queue (Phase 9)
    ↓
Audit (Phase 10)
    ↓
API (Phase 12)
    ↓
Observability (Phase 11)
    ↓
Testing / Hardening (Phases 13-17)
    ↓
Membership & Loyalty (Phase 18)
    ↓
POS & Payment Gateway (Phase 19)

Side branch (added 2026-11-06, no membership dependency — it reads users/sessions only):
General App Dashboard (Phase 20) — free to run after Phase 17, in either order with 18/19
```

## Phase Details

### Phase 0: Architecture & Project Conventions
- Finalize architecture principles (done in docs)
- Set up coding conventions (naming, folder structure)
- Configure PSR standards, linting
- Status: DONE

### Phase 1: Laravel Foundation & Environment
- Laravel 13 install
- `.env` / `.env.example`
- Config files (`config/`)
- Queue (database), cache (file) — Redis optional
- Correlation/request ID middleware
- Packages installed: Sanctum, Spatie Permission, Activitylog, Laravel Pulse, Laravel Pennant (feature flags)
  (see [dependency overview](../base/dependencies/overview.md))
- AdminLTE initial UI setup (UI-001: vendor from release ZIP, wire Blade layout, UI-independent)
- Status: DONE

### Phase 2: Database Foundation
|- Base migrations (existing Laravel defaults + Spatie + Sanctum + Pennant + Pulse)
|- Seed data: RoleSeeder (superadmin, admin, user) wired into DatabaseSeeder
|- Database constraints: users table has is_active, is_locked, must_change_password,
  password_expires_at, last_activity_at, soft-deletes
|- Status: DONE

### Phase 3: Authentication Foundation
|- Sanctum API token driver (AUTH-002): already configured in config/auth.php
|- Login (AUTH-004/AUTH-005): FormRequest + AuthController (authenticate, check
  account state, issue Sanctum token, update last_activity_at)
|- Email verification (AUTH-011): enable MustVerifyEmail on User, routes + controller
|- Forgot/reset password (AUTH-012/AUTH-013): Laravel password broker routes + controllers
|- Session management (AUTH-006/AUTH-009/AUTH-010): token issuance + revocation
  (current device + all devices)
|- Failed login tracking (AUTH-007): needs failed_login_attempts table migration
  + increment/lockout logic
|- Temporary lock (AUTH-008): 5 failed attempts → 15 min lock (configurable)
|- Rate limiting (login endpoint): throttle middleware
|- Status: DONE
|
**Frontend (AdminLTE):** Login, register, forgot-password, reset-password,
email-verification-notice, password-change pages using AdminLTE auth layout.

### Phase 4: User Lifecycle & Management
- User CRUD
- Activate/deactivate
- Lock/unlock
- Admin user creation (temp password)
- Status: DONE

### Phase 5: Password & Security Lifecycle
- IM8 password policy
- Password history
- Password expiration
- Failed login + temp lock
- Inactivity lock
- Status: DONE

### Phase 6: RBAC & Authorization
- Spatie Permission setup
- Roles & permissions
- Superadmin protection
- Status: DONE

### Phase 7: Feature Availability
- Feature flags
- Backend enforcement
- Status: DONE

### Phase 8: Settings
- Settings management UI/API
- Settings validation
- Settings audit
- Status: DONE

### Phase 9: Notifications & Mail
- Mail configuration — **DONE** (`SystemSetting` + `encrypt()`, rebound into `config('mail')` after commit)
- Notification channels — **DONE** (global admin switches; `via()` reads them)
- In-app inbox — **DONE** (`/notifications/inbox`, Laravel's `database` channel, no permission)
- Audience rule — **DONE** (`NotificationAudience`, per-action trigger permissions)
- Re-audit of the shipped phase — **DONE** (7 findings, all closed): credential reachable through the settings module, in-transaction dispatch on 3 actions + `$afterCommit` on all 7 classes, badge/list cache divergence, no retention, no rate limit, a dead model, and the mail switch able to break the four flows with no alternative channel
- Notification copy — **DONE** (researched and rewritten: subjects stand alone, bodies add the actor and the next step; `NotificationCopyTest` pins the contract)
- Performance — **MEASURED** (`NotificationBenchmarkTest`: no N+1, warm bell 0 queries, inbox bounded by pagination, audience 2 queries at any admin count)
- Security — **DONE** (`NotificationPentestTest`: 11 probes / 108 assertions, no findings)
- Retention — **DONE** (daily scheduled sweep, read notifications older than 90 days)
- Status: DONE

### Phase 10: Audit Trail
- Audit package integration — **DONE** (`spatie/laravel-activitylog ^4.8`, 3 migrations)
- Audit abstraction layer — **DONE** (`App\Models\Concerns\Auditable` trait, one entry point)
- Async export — **DONE** (job on a private disk, retention sweep, rate limit in `system_settings`)
- Reader, on **both** channels — **DONE** (`phase-12-api-v1.md` closed the API gap)
- Pentest — **DONE** (`AuditPentestTest`: 63 probes, verified to fail with the gate removed; `CsvInjectionPentestTest`: 4 probes, 2026-10-14)
- Status: DONE
  `phase-10-audit-trail.md` §11 remain; see `progress.md`.

### Phase 11: Monitoring & Observability
- Laravel Pulse integration (`^1.8`, in `require`) — **DONE** (`85384b4`)
- `pulse` feature flag + route enforcement — **DONE** (`b08b8b4`)
- `pulse.view` permission + `viewPulse` gate override — **DONE** (2026-10-15, P11-B1/B2)
- Sidebar entry for Pulse — **DONE** (2026-10-15, P11-A1 — Monitoring group, both dashboards)
- Health check API endpoint — **DONE** (`/api/v1/health`, ungated by design)
- System health dashboard (permission `health.view` + flag `health`) — **DONE** (2026-10-15, P11-B3/C1/A2 + A3/A4 gate and render tests)
- Queue conventions (named queues, `$tries`/`$timeout`, `failed()`) — **DONE** (2026-10-16, P11-E1..E3)
- Slow-query detection on production — **DEFERRED by decision**: MySQL slow log covers it; the `DB::listen()` logger is added only when a case appears the slow log cannot see
- Status: DONE

> Full breakdown: [phase-11-monitoring-observability.md](phase-11-monitoring-observability.md)
> — Groups A–F, the access-gating decision table, the slow-query diagnosis
> ladder, the VM runbook, and the Pulse/Horizon/Vantage queue-dashboard
> decision all live there; this section is the status summary only.
> **Superseded scope:** this phase originally specified `laravel/telescope` with
> `periscope` as a companion UI. Both were removed in `85384b4` in favour of
> Laravel Pulse — see [DEP-004](../base/architecture/decision-records/DEP-004-laravel-pulse-observability.md).

### Phase 12: API V1
- Versioned API routes
- API resources
- Documentation (Scramble/API docs)
- Status: DONE
  post-close audit (contract envelope and authorization in the generated
  document, the `bearerAuth` scheme, module tags, and the three untested
  endpoints covered). `/docs/api` and `/docs/api.json` are gated by
  `api_docs_public` / `api_docs.view` and served from the deploy export
  (ADR-025). See [phase-12-api-v1.md](phase-12-api-v1.md).

### Phase 13: Security Hardening
- Security headers (CSP, HSTS, etc.)
- CSRF, CORS
- Input sanitization
- Status: DONE

### Phase 14: Storage / Backup / Retention
- Storage disks (local, public, tmp)
- Backup strategy
- Retention jobs
- Status: DONE

### Phase 15: Comprehensive Testing
- All test types from [testing-matrix.md](../base/testing/testing-matrix.md)
- Coverage thresholds
- Security scanning
- Status: DONE

### Phase 16: Documentation Verification
- Verify docs match code
- Update as needed
- Status: DONE (Group E closed 2026-11-05 by P17-D2 on the Phase 17 run logs)

### Phase 17: Full Regression / Final Review
- End-to-end testing
- Final review against Definition of Done
- Status: DONE — Groups A, B and C run and recorded 2026-11-04, Group D run
  2026-11-05 (reconciliation, tracker closure, `docs/planning/final-release-summary.md`).
  Every gate exited 0 on the final tree; what remains open is listed as deferred in
  the release summary § 3 and in `progress.md` § *Known Issues*. Work sits in the
  working tree uncommitted — the owner reviews and commits (`AGENTS.md`).

### Phase 18: General Membership & Loyalty System
- Dynamic membership tiers
- Duration / expiry engine
- Member dashboard & statistics
- Automated expiry sweep + notification jobs
- ~~Member voucher & discount engine~~ — **moved to Phase 19 (owner decision
  2026-11-05): voucher is its own domain — own flag, own menu, validated at payment time,
  neither a membership nor a gateway feature**
- Depends on: Phase 17 (foundation closed) — reuses the Phase 8 settings, Phase 7 feature
  flag, Phase 9 notification/queue and Phase 10 audit capabilities rather than adding new
  infrastructure
- Breakdown: [phase-18-membership-loyalty.md](phase-18-membership-loyalty.md) — 23 tasks in
  six groups (A UI + flag → B data/Actions/controllers → C sweep + bulk notification →
  D RBAC/flag/audit → E tests + closure → **F membership analytics dashboard, added
  2026-11-06**), written 2026-11-05 with seven brief-vs-code conflicts recorded
  (D-1..D-7) before any task is built, and six more (D-8..D-13) written against Group F's
  brief on 2026-11-06: flag-off is **403, not 404**;
  `members` is a loyalty profile keyed to `users.id`, not a second identity; there is no
  WhatsApp channel; `lockForUpdate()` cannot be proven on the SQLite test connection;
  **no `date_of_birth` / `address` column exists**, so the demographic card is cut (O-5);
  `GetMembershipDashboardStatsAction` is verb-first and fails `ActionNamingConventionTest`;
  and the expiring-soon table with its broadcast modal is **already shipped** — F extends it
- Status: **Groups A–E DONE (E closed 2026-11-06) · Group F PLANNED (added 2026-11-06, no
  code written)** — A–D shipped
  and closed on their own gates; E2's full-suite run came back **red on its first pass
  (3 failures, 2910 passed)**, and those three failures were the phase's findings rather than
  noise: the eight `members.tiers.*` slugs broke the `{resource}.{action}` naming gate, and the
  member deactivation "violation" switch had been added to the **global** confirmation modal, so
  two read-only UI render gates saw a control they assert is absent. Both were fixed (renamed
  `member_tiers.*` with a data migration; the switch moved to `@push('confirmModalFields')` on the
  member list with a regression test), and the closure run is green: **2923 tests / 11152
  assertions / 9 risky, exit 0**, coverage **global 94.31 %, Actions 96.62 %, Policies 100 %,
  Requests 98.10 %**, Pint PASS 571 files, PHPStan `[OK] No errors` at 310 ledger entries. Detail in
  `docs/planning/task-tracker.md` § Phase 18 and `progress.md` § *Test Status*. Delivered:
  six `pages/members/*` views, `member_loyalty` flag declared **and** seeded, four tables
  (`member_tiers`, `members`, `member_subscriptions`, `member_point_histories`), the tier CRUD
  with `App\Support\Money` pricing, the member create/state/trash/bulk flows, the subscription
  activate/cancel engine with the tier role synced onto the **`User`**, the points ledger with
  the System Setting earn rule and the annual reset, the daily expiry sweep on `sweeps` (the
  eighth `schedule:list` entry), the chunked bulk expiry warning (mail + in-app, D-3), 25 web
  routes and their API twins under one permission set, and the seventeen member-module
  slugs behind them (nine `members.*` + eight `member_tiers.*`, each write route naming its
  own, both resources mapped to the one `member_loyalty` flag). **The voucher engine left for Phase 19 (owner 2026-11-05)**;
  no spend/checkout path exists yet — Phase 19 B2 spends the points this phase deliberately
  left unspent. Deferred: the MySQL two-session proof of the points row lock (D-5) and the
  WhatsApp channel (O-2, unshipped by design). Work sits in the working tree **uncommitted** —
  the owner reviews and commits (`AGENTS.md`).
  **Group F (P18-F1..F4) is the analytics dashboard on the screen A3 already shipped** —
  growth vs expirations, renewal rate, DAU/MAU, tenure, at-risk list, timeframe filter — and
  it also absorbs the delivered member-facing `/dashboard`, which held the ID `P18-F1` until
  this group was written and is now `P18-E3` (D-8).

### Phase 19: Basic POS Engine & Omnichannel Payment Gateway
- Point of Sales engine (self-service + cashier interface)
- ESC/POS thermal printing on the owner's hardware: **Eppos EP58M / RPP02**, a 58 mm USB unit
  (384 dots, **32 characters a line**, no cutter) — the receipt header is configurable (logo,
  address, phone, NPWP, notes) and the QRIS code the customer scans rides on that paper
  (D-21/D-27/D-28)
- **Gateway: Midtrans, Core API v2 (VT-Direct)** — owner decision 2026-11-08 (ADR-036).
  QRIS + one VA bank + cash, with EDC recorded out-of-band; one encrypted credential
  (`payment_server_key`) that signs both the charge and the notification
- Dynamic financial dashboard, split into the four questions the owner's sketch holds:
  **till position** (expected vs counted, float in/out), **sales performance** (gross,
  discounts, service charge, by tender), **gateway settlement & reconciliation**, and
  **tax — "total PPN yang harus dibayar" per masa, split by price mode** (**no fee or profit
  figure until a cost and a fee source exist**, D-11/D-20/D-29)
- **Monthly / annual report screen with CSV export** (period + PPN rekap), generated by a
  queued job on the Phase 10 export shape with its own table (A8/B9, D-30)
- **PPN as data, not code**: an applied/not-applied switch, the effective rate (11 today), and
  **two price modes — harga sudah termasuk PPN vs belum termasuk PPN** — with rate, mode and
  DPP snapshotted per transaction so a later change never rewrites a printed receipt
  (B8, D-24/D-25, ADR-038)
- Payment race-condition shield + atomic webhook handler + the reconciliation sweep that
  settles what the webhook never reports (C5, D-22)
- **Voucher domain** (moved in from Phase 18): own `vouchers` flag, own menu entry,
  `vouchers.view`/`vouchers.manage`, `ValidateVoucherAction` deciding validity when a
  payment presents a code
- Depends on: Phase 18 (tier pricing and points are applied at the POS line; the voucher
  now lives here, not there) and
  Phase 14 (gateway credentials are encrypted `SystemSetting` values, same pattern as SMTP)
- Breakdown: [phase-19-pos-and-payment.md](phase-19-pos-and-payment.md) — 27 tasks in five
  groups (A UI + settings cards + dashboard + reports + flags → B data/shifts/sales/totals/
  ESC-POS/reports → C gateway order + webhook + credentials + reconciliation → D
  RBAC/flag/audit → E tests + closure), written 2026-11-05 and revised 2026-11-08 twice: after
  reading the official Midtrans PHP SDK and its API reference, then after the receipt-hardware,
  PPN and financial-report research; thirty-one brief-vs-code conflicts recorded
  (D-1..D-31) before any task is built: **no
  product or stock table exists in the schema** (B1 must add `products`; how a product is
  created is open decision O-1); Livewire/Alpine are not installed and the CSP forbids them;
  `Illuminate\Support\CircuitBreaker` is absent from this install while `Http::retry`/
  `Http::timeout` are native — and a gateway retry is only safe with our own deterministic
  `order_id`; **the vendor SDK is not used at all** (no cURL timeout, not fake-able in tests,
  process-global static credentials, and a `Notification` class that fires an outbound call
  per webhook and verifies no signature); the webhook's race shield must be an atomic
  conditional update plus a unique **notification hash** — Midtrans sends no event id and one
  transaction arrives several times, out of order (D-17); settlement is asynchronous and a
  payment that fails in the customer's app is **never notified**, so completion is one Action
  with three triggers (D-22); gateway keys stay **out** of the settings whitelist and
  **inside** `SystemSetting::SECRET_KEYS` so `settings.manage` cannot repoint production
  money; Net Profit has no cost data and fees have no API; `mike42/escpos-php` needs ADR-037
  before `composer require` and only its byte stream is testable here — v5 dropped the
  USB connector and the GD dependency D-9 blamed, the 32-column budget is a hardware fact and
  not a style choice, and `cut()` is never called because 58 mm units have no cutter
  (D-27/D-28); the payment is synchronous in the register's request, since a queue worker may
  not be the machine holding the printer; there is **no expense table**, so "uang keluar" is
  bounded to voids, refunds and the shift float (D-29); the export button already exists in
  Phase 10 and is copied, CSV only (D-30); PPN is a liability while PPh is the merchant's own
  tax and never a customer line (D-31), and whether this install is a PKP is **O-12**;
  flag-off is 403, not 404
- Status: PLANNED — except the voucher Group A slice (P19-A6), shipped 2026-11-05 and in
  REVIEW

### Phase 20: General App Dashboard & Platform Health Metrics
- Platform-wide staff dashboard: registered users, email verification health, profile
  completeness, active sessions
- Registration growth trend with a dynamic timeframe filter (7d / 30d / 90d / 1y / custom)
- Account status breakdown, profile completion checklist, security event feed (failed logins,
  locked accounts) read from the tables Phase 3/6/10 already write
- **Split from membership on 2026-11-06 by owner decision**: member analytics stay in
  Phase 18 Group F, one screen per audience; POS & payment moved out of this number to
  **Phase 19**
- Depends on: Phase 17 only. It reads `users`, `sessions`, `activities` and
  `failed_login_attempts` — no membership table. It does **not** depend on Phase 18, but it
  shares `/dashboard` with the member-facing screen shipped 2026-11-06 (P18-E3), and that
  branch is where `dashboard.view` has to land: the landing route stays ungated or a
  permissionless account 403s off the page login sends it to (phase doc D-1, ADR-034)
- Breakdown: [phase-20-general-dashboard.md](phase-20-general-dashboard.md) — 12 tasks in four
  groups (A UI + filters + render gate → B aggregator + health read + controller → C
  permission + flag + audit decision → D tests + closure), written 2026-11-06 with eight
  brief-vs-code conflicts recorded (D-1..D-8) before any task is built: **no profile fields
  exist to score** (O-1); the Action name is verb-first; health and sessions are already
  answered by `/health`, `/sessions` and Pulse; audit convention says reads are not audited
  and a per-pageview `dashboard.viewed` is a write on every landing (O-2); **no chart library
  is installed and CSP blocks inline JS** (O-3); `pages/dashboard/index.blade.php` must
  replace `pages/dashboard.blade.php` in the same commit
- Status: **PLANNED (breakdown written 2026-11-06, no task started, no code written)**
