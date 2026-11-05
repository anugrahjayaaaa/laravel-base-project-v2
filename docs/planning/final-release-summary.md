# Final Release Summary

> Written 2026-11-05 by **P17-D3** on `feature/phase-17-full-regression`. The one
> document a future owner reads cold. Every claim below cites a file — a test, a
> doc, a config key, or a workflow job — or is labelled **deferred**. Numbers are
> quoted from the Phase 17 run logs (`docs/planning/phase-17-full-regression.md` §
> *As implemented*), not carried from another document. Nothing in this phase was
> committed by the implementing turn: the owner commits (`AGENTS.md` hard rule).

## 1. What is delivered

Per capability: the route-level gate (`can:` + `feature:`, never in a controller —
`AGENTS.md`), the suites that prove it, and the design doc it was built against.
All the suites below ran and passed inside P17-A1's `php artisan test` on this tree.

| Capability | Route gate | Proving tests | Design doc |
|---|---|---|---|
| Auth (login, logout, session/token revocation) | auth stack + `feature:sessions` (`routes/web.php:77`, `routes/api.php:61`) | `tests/Feature/Auth/WebSessionRevocationTest.php`, `tests/Feature/Web/V1/Auth/AuthControllerTest.php`, `tests/Feature/Auth/UsernameEmailChangeTest.php` | [authentication.md](../base/security/authentication.md), [session-security.md](../base/security/session-security.md) |
| Password security (expiry, history, policy, inactivity lock, force change) | auth stack, `EnsurePasswordChangeRequired` middleware | `tests/Feature/Auth/{PasswordExpiry,PasswordHistory,PasswordPolicy,PasswordChangeThrottle,InactivityLock,PasswordLifecycleUi}Test.php` | [password-security.md](../base/security/password-security.md) |
| Registration + email verification | `feature:` on the registration group, mode read from `SystemSetting` | `tests/Feature/Auth/{Register,RegistrationDefaultRole,SelfRegistrationSecurity,EmailVerificationMode,VerificationLinkExpiryMail}Test.php` | [registration.md](../base/features/registration.md) |
| User management (CRUD, state, bulk, profile, last-superadmin guard) | `feature:users` (`routes/web.php:122`, `routes/api.php:207`) + per-ability `can:` on reads | `tests/Feature/User/` (16 files incl. `UserCrudWebTest`, `UserStateAuthorizationTest`, `LastSuperadminGuardTest`), `tests/Feature/Performance/UserViewPerformanceTest.php` | [user-management.md](../base/features/user-management.md), [soft-delete.md](../base/data/soft-delete.md) |
| RBAC (roles, permissions, superadmin protection) | `feature:roles` + `feature:permissions` as **separate** groups (`routes/web.php:189,211`) | `tests/Feature/Role/{RbacPerformance,RbacAuthorizationMatrix,RbacPentest,RbacUiRender}Test.php`, `tests/Unit/Policies/UserPolicyTest.php` | [roles-permissions.md](../base/features/roles-permissions.md), [authorization.md](../base/security/authorization.md) |
| Feature flags (Pennant, fail-closed) | `feature:{slug}` → 403 when OFF (`App\Http\Middleware\EnsureFeatureIsEnabled`) | `tests/Feature/FeatureFlag/FeatureFlagMiddlewareTest.php`, `tests/Feature/FeatureFlag/FeatureFlagUiRenderTest.php` | [feature-flags.md](../base/features/feature-flags.md) |
| Settings (`SystemSetting`, 75 validated keys) | `feature:settings` (`routes/web.php:85`, `routes/api.php:88`) | `tests/Feature/Settings/` (8 files incl. `SystemSettingKeyConsistencyTest`, `RuntimeConfigBindingTest`), `tests/Feature/Security/SettingsPentestTest.php` | [settings.md](../base/features/settings.md) |
| Notifications (inbox, bell, mail channels) | `feature:notifications` (`routes/web.php:253,287`, `routes/api.php:102`) | `tests/Feature/Notification/` (incl. `NotificationInboxTest`, `NotificationBenchmarkTest`, `NotificationUiRenderTest`), `tests/Feature/Security/NotificationPentestTest.php` | [notifications.md](../base/features/notifications.md), [queue.md](../base/infrastructure/queue.md) |
| Storage, backup, retention | `feature:storage_management` + `can:storage.view` / `can:backup.execute` (`routes/web.php:93,103`) | `tests/Feature/Storage/{StorageAuthorization,BackupStrategy,StorageDisk,DataRetention,StorageBenchmark}Test.php` | [storage.md](../base/infrastructure/storage.md), [backup-disaster-recovery.md](../base/infrastructure/backup-disaster-recovery.md), [retention.md](../base/operations/retention.md) |
| Audit trail (48 events, export, scrubbing) | `feature:activity_logs` + `can:audit.view` / `can:audit.export` (`routes/web.php:340,357`) | `tests/Feature/Audit/{ActionFirstAudit,AuditExport,AuditBenchmark,AuditPropertyScrub,AuditRequestId,AuditLogUiRender}Test.php` | [audit-trail.md](../base/features/audit-trail.md) |
| API v1 (versioned, envelope, pagination) | sanctum + per-route `can:` (`routes/api.php`), envelope enforced by the exception handler | `tests/Feature/Api/V1/**`, `tests/Feature/CrossCutting/ApiErrorEnvelopeTest.php`, `tests/Unit/Requests/RequestGuardsTest.php` | [api-architecture.md](../base/api/api-architecture.md), [error-contract.md](../base/api/error-contract.md), [response-contract.md](../base/api/response-contract.md) |
| API documentation (Scramble) | `viewApiDocs` gate: `api_docs_public` setting **or** `api_docs.view` (`Gate::define` in `app/Providers/AuthServiceProvider.php:146`, `App\Http\Middleware\RestrictApiDocsAccess`) | `tests/Feature/Api/{ApiDocumentation,OpenApiExport}Test.php`, `tests/Feature/Api/ApiDocsUiRenderTest.php` | [documentation.md](../base/api/documentation.md) |
| Monitoring (health, Pulse, Vantage) | `feature:health` (`routes/web.php:413`), `feature:pulse` via `config/pulse.php:153`, `feature:vantage` (`routes/web.php:436`) | `tests/Feature/Health/{HealthUiRender,HealthPermissionGate,HealthCheckCacheFallback}Test.php`, `tests/Feature/PulsePermissionGateTest.php`, `tests/Feature/Vantage/VantagePermissionGateTest.php` | [monitoring.md](../base/features/monitoring.md), [observability.md](../base/infrastructure/observability.md) |
| Correlation ID + logging | middleware on every request | `tests/Feature/CrossCutting/CorrelationIdMiddlewareTest.php`, `tests/Feature/Queue/QueueFailureProbeTest.php` | [logging.md](../base/infrastructure/logging.md) |

Cross-cutting gates that hold the shape rather than a feature:
`tests/Arch/NoInlineFqnTest.php` (no inline FQCN in any `app/` or `tests/` file),
`tests/Arch/SerializableConfigTest.php` (every object-valued config leaf must be
`__set_state`-able — added by P17-C2 after `php artisan optimize` failed on a live
`SecurityScheme`), `tests/Feature/CrossCutting/ActionNamingConventionTest.php`,
`tests/Feature/Queue/JobConventionTest.php` (the per-job standard in
[queue.md](../base/infrastructure/queue.md)), and
`tests/Arch/DocumentationLinkIntegrityTest.php` (relative links resolve, heading
hierarchy never skips, no table row starts with a double pipe).

## 2. The measured quality bar

All figures from the Phase 17 runs on this tree (2026-11-04/05), not from a prior doc.

| Gate | Measured | Enforced by |
|---|---|---|
| Full suite | **2668 passed / 9837 assertions, 9 risky**, exit 0 (498.58 s) | `ci-cd.yml` `test` job — `php artisan test --no-coverage` on every PR and every push to `main`; `deploy` is `needs: test` |
| Coverage | global **94.87 %** (5031/5303) at the `--min=85` floor; Actions **97.11 %**, Policies **100 %**, Requests **98.09 %** — `bin/coverage_gate.php` exit 0 | `testing.yml` `coverage` job — `if: schedule \|\| release`, pcov. Bars are the code's, not the brief's: `bin/coverage_gate.php` + [testing-strategy.md](../base/testing/testing-strategy.md) |
| Style | `vendor/bin/pint --test` exit 0, **PASS 489 files** (PSR-12 preset, whole tree) | `ci-cd.yml` `test` job, `Check PHP formatting` |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=1G` exit 0, `[OK] No errors`, 209 files at **level 8**, ADR-030 ledger **310 entries**, `reportUnmatchedIgnoredErrors: true` | `testing.yml` `phpstan` job — `if: pull_request` (fast, red in ~2 min) |
| Dependency audits | `composer audit --locked` exit 0 (no advisories); `npm audit --audit-level=high` exit 0, `found 0 vulnerabilities` — after the owner-decided `vite 8.3.0 → 8.3.5` lockfile bump (6 high advisories, all dev-only, blocked the `test` job) | both steps inside `ci-cd.yml` `test` per PR, and `testing.yml` `dependency-audit` weekly (`cron: 0 6 * * 1`, default branch only) |
| Frontend build | `npm ci` + `npm run build` exit 0; `app-*.js` 15.00 kB (gzip 4.62), `app-*.css` 49.62 kB (see § 3, item 5) | `ci-cd.yml` `test` + `deploy` |
| Production boot | `php artisan optimize` exit 0 (config/events/routes/views); **16 pages returned 200** with caches warm; Pennant and `SystemSetting` writes still changed behaviour under a cached config | not a CI job — P17-C2's manual walk, recorded in the phase doc § 11 and [deployment.md](../base/operations/deployment.md) |
| Scheduler | `php artisan schedule:list` exit 0, exactly the six entries in `routes/console.php:124-259`; the midnight collision is safe because the two every-minute closures feed different queues (`sweeps` vs `backups`) and `bin/run-workers.sh` listens on both | audited by P17-C3, [queue.md](../base/infrastructure/queue.md) |

## 3. Deferred and unproven — stated as deferred

A future owner must not read § 1 or § 2 as "everything verified". These are the
boundaries, and none of them was closed by this phase:

1. **Three runtime evidences deliberately deferred since Phase 14**: a real S3
   bucket (only the local/fake disks are exercised), the backup → restore **round
   trip** (the restore runbook was walked cold and verified against shipped vendor
   source, never executed against a real dump), and a live `timezone:rebase`
   against production data.
2. **9 risky tests** — pre-existing `tests/Feature` skips, incomplete-marked
   benchmarks and one warning; attribution P15-E1, accounted for in every run
   since. P17-A1 matched them **by count only**: that printer emitted the summary
   line, no per-test detail, so their identity was never re-proven by name.
3. **`qa-tracker.md` carries 21 rows that are not fully proven**: 13 UNPROVEN and
   8 PARTIAL of 99 (77 DONE, 1 N/A), each row naming the assertion that is missing
   or the site that is unobserved. Three are marked **PARTIAL — needs a human**
   (QA-AUDIT-004, QA-AUDIT-008, QA-MON-010): they cannot be closed by an automated
   test at all. The reconciliation was written by P17-D2; the gaps are the owner's
   to close or accept.
4. **Recorded delete-candidates, not deleted** (Phase 15 E2): the dead
   `password()` / `remember()` accessors on three auth Form Requests — 12
   statements. A testing phase does not delete production code.
5. **The Tailwind/CSS decision (D-8) is open and belongs to the owner.** The Vite
   build emits `app-*.css` (49.62 kB) and the self-hosted font CSS (6 files), and
   **no layout loads either**: all three layouts `@vite` only `resources/js/app.js`
   (`app.blade.php:61`, `guest.blade.php:31`, `auth.blade.php:83`), no manifest
   entry carries a `css` array, `@viteFonts()` is never used, and
   `Paginator::useBootstrap()` (`AppServiceProvider:70-71`) means the
   Tailwind-classed pagination views never render. `AGENTS.md` § *Stack* says "No
   Tailwind"; [ui-adminlte-setup.md](../base/ui/ui-adminlte-setup.md) said the
   build compiles Tailwind. The doc now carries the conflict note and the two
   options (drop the CSS input + `tailwindcss`/`@tailwindcss/vite`, or load the CSS
   and amend `AGENTS.md` by ADR). Consequence today: dead bytes and
   `--lbp-font: 'Instrument Sans'` resolving to the `system-ui` fallback — **not**
   a broken page, which is why nothing was deleted.
6. **Web email-verification has no signature/expiry check** (recorded by Phase 15
   C as a design question, not fixed). The API-side signed URL is pinned by
   `tests/Feature/Storage/StorageAuthorizationTest.php` and the cross-path replay
   test; the web half remains an open design decision.
7. **Sensitive data in log files is a policy, not a mechanism** (QA-LOG-003):
   `config/logging.php` installs only `PsrLogMessageProcessor`, no redaction
   handler; the DB audit trail *is* scrubbed and proven so by
   `AuditPropertyScrubTest`.
8. **Two view-level gaps**: the query-count gates cover eleven page groups;
   `users/create`, `users/show`, `profile/edit`, `sessions`,
   `notifications/show`, the six auth pages, `welcome` and most layout partials are
   rendered and asserted but never measured for queries
   ([testing-matrix.md](../base/testing/testing-matrix.md) § *UI render &
   view-query coverage*). And i18n is **not implemented** — no `lang/`, no `__()`,
   every string literal English by design (`AGENTS.md` § *Traps already paid for*).
9. **Findings filed, not fixed, during Group C**: `bin/fetch-page.sh`'s default
   credentials are stale (`LocalTest#2026` vs the seeded `#Password123`,
   `SuperAdminSeeder.php:24`), so its default invocation cannot log in; and the
   local dev DB reports a **Pending** migration
   (`2026_11_03_000000_remove_duplicate_expiry_settings`), deliberately not run by
   this phase.

## 4. Deploy and operate

Three lines, not a second runbook:

1. [deployment.md](../base/operations/deployment.md) is the sequence — including
   the process-start step Phase 16 D2 had to add, and the `optimize` / `optimize:clear`
   facts P17-C2 proved (config caching is a hard requirement now that
   `SerializableConfigTest` exists; `SystemSetting` and Pennant values stay
   DB-backed and runtime-mutable, so a cached config file does not freeze an
   operator's settings).
2. Workers and the scheduler start from `bin/run-workers.sh`
   (`local` / `backups` / `cron` / `workers` / `all`); the six scheduled entries
   live in `routes/console.php` and the queue names in `App\Support\QueueName`
   ([queue.md](../base/infrastructure/queue.md)).
3. When something is wrong, [troubleshooting.md](../base/operations/troubleshooting.md)
   and the operational runbooks in `docs/base/operations/` were walked cold by
   Phase 16 D2 — every command there is verified to resolve as written.

## 5. Verdict

**Shippable for its stated purpose**: a Laravel 13, API-first, UI-replaceable
foundation with auth + password security, user management, RBAC, feature flags,
settings, notifications, storage/backup/retention, an audit trail, a versioned API
with generated docs, and monitoring — measured at 2668 passing tests, 94.87 %
global coverage with per-layer bars enforced by a gate, PSR-12 and PHPStan level 8
clean, dependency audits clean, and a production-shaped cache boot that serves 16
walked pages.

The boundary of that sentence is § 3. Anything in it that a reader would act on
comes from a run recorded in `docs/planning/phase-17-full-regression.md` § *As
implemented* or a file cited above — not from the phase brief. Items 1, 3, 5 and 6
of § 3 need an owner decision or an owner action before this project can be called
"everything verified", and this document deliberately does not call it that.
