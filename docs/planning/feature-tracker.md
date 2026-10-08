# Feature Tracker — Laravel Base Project v2

Auto-generated from codebase scan. Not committed.

## Feature Matrix

| # | Feature | Web | API | Auth | Docs | Status |
|---|---------|-----|-----|------|------|--------|
| 1 | Login (username/email) | ✅ | ✅ | — | security/authentication.md | done |
| 2 | Email verification | ✅ | ✅ | — | security/authentication.md | done |
| 3 | Forgot password | ✅ | ✅ | — | security/password-security.md | done |
| 4 | Password reset | ✅ | ✅ | — | security/password-security.md | done |
| 5 | Password change (self) | ✅ | ✅ | own | security/password-security.md | done |
| 6 | Password change (expired bypass) | — | ✅ | sanctum | — | done |
| 7 | Logout current device | ✅ | — | own | security/session-security.md | done |
| 8 | Logout all devices | ✅ | ✅ | own | security/session-security.md | done |
| 9 | Session listing | ✅ | ✅ | own | — | done |
| 10 | Resend verification | ✅ | ✅ | own | — | done |
| 11 | Email change flow | ✅ | — | own | — | done |
| 12 | Failed login tracking + lock | — | — | — | security/authentication.md | done |
| 13 | Rate limiting (per-endpoint) | ✅ | ✅ | — | security/rate-limiting.md | done |
| 14 | Account activate/deactivate/lock/unlock | ✅ | ✅ | admin | features/user-management.md | done |
| 15 | User CRUD | ✅ | ✅ | admin | features/user-management.md | done |
| 16 | Soft delete / force delete / restore | ✅ | ✅ | admin | features/user-management.md | done |
| 17 | Bulk actions | ✅ | ✅ | admin | — | done |
| 18 | Admin resend verification | — | ✅ | admin | — | done |
| 19 | Profile view/edit | ✅ | ✅ | own | — | done |
| 20 | System settings | ✅ | ✅ | admin | features/settings.md | done |
| 21 | Registration (configurable) | ✅ | — | public | features/registration.md | done |
  | 22 | RBAC roles & permissions (Spatie) | ✅ | ✅ | — | features/roles-permissions.md | phase 6 Groups A, B, C1–C4 done; **Gate C met** (2026-09-30). Phase report + docs pending at E11 |
  | 23 | Superadmin protection | ✅ | — | — | features/roles-permissions.md | done — `Gate::before`, seeder role, system-role delete/rename refusals, last-superadmin guard (P6-C11), grant restricted to superadmin actors, role + account visible only to superadmin. Count guard is a minimum of one, not exactly one (spec says zero) |
| 24 | Feature flags (DB-backed) | ✅ | ✅ | — | features/feature-flags.md | done — Phase 7 complete (Groups A–F). Catalogue + activation, 403 middleware with no superadmin bypass, route/menu gate on 62 routes across web AND API, vendor `/pulse` gated through `pulse.middleware`, bulk enable/disable as one audited change, management UI. `translations` and `activity_logs` still gate nothing — they have no routes in any phase, so their `/features` rows are marked pending rather than offering a switch that changes nothing. Phase 8 (2026-10-02) confirmed this: it did not add them either |
| 25 | Audit trail (Spatie activitylog) | — | — | — | features/audit-trail.md | done |
| 26 | Notifications (email + DB) | — | — | — | features/notifications.md | done — Phase 9 complete. Global admin switches (mail + database), personal inbox with no permission behind it and user isolation as its only boundary, cached bell badge, `NotificationAudience` resolving per-action trigger permissions. Re-audited 2026-10-08: seven boundary findings closed (credential reachable via the settings module, in-transaction dispatch, badge/list cache divergence, no retention, no send-test rate limit, a dead model, the mail switch able to break account-critical flows). Copy rewritten against researched guidance and pinned by `NotificationCopyTest`; `NotificationBenchmarkTest` found no N+1; `NotificationPentestTest` found nothing across 11 probes. Two items left open at the end of the audit were closed on request: audience membership now matches the Gate's own definition (a permission granted directly to a person counts), and the bell and the inbox now read one cached unread number |
| 27 | Health check | ✅ | ✅ | — | features/monitoring.md | done |
| 28 | Laravel Pulse | — | — | — | features/monitoring.md | done — replaced Telescope + Periscope (`85384b4`); flag gated (`b08b8b4`); `pulse.view` permission + `viewPulse` gate override planned in Phase 11 |
| 29 | Queue (DB + Redis compat) | — | — | — | infrastructure/queue.md | done |
| 30 | Cache abstraction | — | — | — | infrastructure/cache.md | done |
| 31 | API v1 (versioned) | — | ✅ | — | api/api-architecture.md | done |
| 32 | Scramble API docs | — | — | — | api/documentation.md | done |
| 33 | Storage abstraction | — | — | — | infrastructure/storage.md | done |
| 34 | Data retention | — | — | — | operations/retention.md | planned |
| 35 | Backup/DR | — | — | — | infrastructure/backup-disaster-recovery.md | planned |
| 36 | Correlation ID tracing | ✅ | ✅ | — | infrastructure/logging.md | done |
| 37 | Password history | — | — | — | security/password-security.md | planned |
| 38 | Password expiration | — | — | — | security/password-security.md | planned |
| 39 | Password policy (IM8) | — | — | — | security/password-security.md | planned |
| 40 | Initial password gen (temp) | — | ✅ | admin | security/password-security.md | planned |
| 41 | Admin reset password | — | ✅ | admin | security/password-security.md | planned |
| 42 | Inactivity lock | — | — | — | features/user-management.md | planned |
| 43 | Theme toggle (UI) | ✅ | — | own | ui/design-system.md | done |
| 44 | Password toggle (UI) | ✅ | — | — | — | done |
| 45 | Blade components (UI primitives) | ✅ | — | — | ui/design-system.md | done |
| 46 | AdminLTE 4 layout | ✅ | — | — | ui/ui-adminlte-setup.md | done |

## Legend
- Web = web routes (routes/web.php)
- API = API routes (routes/api.php)
- Auth = middleware guard: `own` (self), `admin`, `sanctum`, `—` (public)
- Status: done / planned / in-progress
