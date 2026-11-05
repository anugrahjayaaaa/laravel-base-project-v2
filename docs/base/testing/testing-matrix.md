# Testing Matrix

## Coverage Matrix

| Component | Unit | Feature | API | Auth | Authorization | Security | Validation | DB | Queue | Events | Notification | Policy | Middleware | Concurrency | Regression |
|-----------|------|---------|-----|------|---------------|----------|-----------|-----|-------|--------|--------------|--------|------------|-------------|------------|
| Login | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | | | ✓ | | | ✓ |
| Logout | | ✓ | ✓ | ✓ | | ✓ | | | | | | ✓ | | | ✓ |
| Logout all devices | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Forgot password | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Password reset | | ✓ | ✓ | ✓ | | ✓ | ✓ | | | ✓ | ✓ | | ✓ | | | ✓ |
| Email verification | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | ✓ | | ✓ | | | ✓ |
| Session management | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Token management | | ✓ | ✓ | ✓ | | ✓ | | | | ✓ | | ✓ | | | ✓ |
| Failed login protection | | ✓ | ✓ | ✓ | | ✓ | | | | | | ✓ | | | ✓ |
| Registration | | ✓ | ✓ | ✓ | | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| User list | | | ✓ | | | | | ✓ | | | | | ✓ | | ✓ |
| User detail | | | ✓ | | | | | ✓ | | | | | ✓ | | ✓ |
| Create user | | | ✓ | | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | ✓ | | ✓ |
| Update user | | | ✓ | | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | ✓ | | ✓ |
| Activate/Deactivate | | | ✓ | | ✓ | ✓ | | ✓ | | ✓ | | | ✓ | | | ✓ |
| Lock/Unlock | | | ✓ | | ✓ | ✓ | | ✓ | | ✓ | | | ✓ | | | ✓ |
| Force password change | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| RBAC roles | ✓ | | | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| RBAC permissions | ✓ | | | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| Superadmin protection | | ✓ | ✓ | | ✓ | ✓ | | ✓ | | | | ✓ | | | ✓ |
| Feature flags | ✓ | ✓ | ✓ | | ✓ | | | ✓ | | | | ✓ | | | ✓ |
| Audit trail | | | ✓ | | ✓ | | | ✓ | | ✓ | ✓ | | ✓ | | | ✓ |
| Settings | ✓ | ✓ | | | ✓ | | ✓ | ✓ | | | | ✓ | | | ✓ |
| Notifications | | | | | | | | | ✓ | | ✓ | | | | ✓ |
| Mail | | ✓ | | | | | | | ✓ | | ✓ | | | | ✓ |
| Queue jobs | ✓ | ✓ | | | | | | ✓ | ✓ | | | | | ✓ | ✓ |
| Events/listeners | ✓ | ✓ | | | | | | | ✓ | ✓ | | | | | ✓ |
| Rate limiting | | ✓ | ✓ | | ✓ | ✓ | | | | | | ✓ | ✓ | | ✓ |
| Security headers | | ✓ | ✓ | | | ✓ | | | | | | ✓ | | | ✓ |
| Validation rules | ✓ | | | | | | ✓ | | | | | | | | ✓ |
| Error handling | | | ✓ | | | | | | | | | | | | ✓ |
| API versioning | | | ✓ | | | | | | | | | | | | ✓ |

## UI render & view-query coverage

The matrix above is capability-keyed. Render coverage has its own section because
the "no direct DB queries in templates" property is **proven per page**, not
tree-wide: a global gate would fail for reasons that are not view bugs (eager
loading legitimately queries before the view renders). Owners: `tests/Feature/UI/`
(23 tests) plus the seven page render suites — `SettingsUiRenderTest` 14 ·
`RbacUiRenderTest` 20 · `NotificationUiRenderTest` 19 · `AuditLogUiRenderTest` 27 ·
`FeatureFlagUiRenderTest` 14 · `HealthUiRenderTest` 11 · `ApiDocsUiRenderTest` 9.
Counts measured 2026-11-04 (P17-A3), all green.

| Page | Gate owner | Property asserted |
|---|---|---|
| settings index | `SettingsUiRenderTest::test_it_renders_and_queries_nothing` | zero queries on warm re-render |
| health index | `HealthUiRenderTest::test_it_renders_and_queries_nothing` | zero queries on warm re-render |
| features index | `FeatureFlagUiRenderTest::test_it_renders_and_queries_nothing` | zero queries on warm re-render |
| activity-logs index + show | `AuditLogUiRenderTest::test_the_index_renders_and_queries_nothing`, `…the_show_page_renders_and_queries_nothing` | zero queries on warm re-render |
| roles index/create/edit + permissions index | `RbacUiRenderTest::test_each_view_renders_and_queries_nothing` | zero queries per rendered view |
| mail configuration + channels pages | `NotificationUiRenderTest::test_the_mail_configuration_page_renders_and_queries_nothing`, `…the_channels_page…` | zero queries outside the layout |
| dashboard | `RbacPerformanceTest::test_the_sidebar_gates_cost_nothing` | fixed query count with the sidebar gates on |
| users index / edit | `UserViewPerformanceTest::test_the_user_index_query_count_does_not_grow_with_row_count`, `…the_user_edit_form_query_count_does_not_grow_with_role_count`; `RbacPerformanceTest::test_the_view_gates_cost_nothing_per_row` | count does not grow with rows/roles |
| inbox + header bell | `NotificationBenchmarkTest::test_the_inbox_cost_does_not_grow_with_its_size`, `test_a_warm_bell_cache_costs_no_query` | non-growing; warm bell costs zero |
| audit index/show at volume | `AuditBenchmarkTest` | same cost at 1 and 500 rows |
| API list responses | `PerformanceGuardTest::test_api_list_query_counts_do_not_grow_with_the_row_count` | non-growing (not Blade) |

**Not proven by a query-count gate** (rendered and asserted for markup, escaping
or authorization, but never measured for queries): `users/create`, `users/show`,
`profile/edit`, `sessions`, `notifications/show`, the auth pages (`login`, `register`,
`forgot-password`, `reset-password`, `verify-email`, `password-expired`),
`welcome`, and the layout partials other than the sidebar and header bell
(`footer`, `alerts`, `password-strength`, the confirmation modal). The `vendor/`
overlays (Pulse, Vantage, Scramble) are third-party markup and out of scope.
These pages are static or read only preloaded view data, so the risk is low — but
"low" is not "gated", and the gap is named rather than folded into the claim.

## Key

- ✓ = Test required
- Empty = Not applicable
- Some tests span multiple categories (marked with multiple checks)

## Notes

- Authentication tests cover login, logout, session, token flows
- Authorization tests cover RBAC, policies, superadmin protection
- Security tests cover input validation, escaping, auth boundary checks
- Concurrency tests for rate limiting, failed login tracking, password history