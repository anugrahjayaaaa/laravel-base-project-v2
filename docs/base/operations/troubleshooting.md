# Troubleshooting

## Common Issues

### Issue: Authentication fails silently

**Symptoms**: User cannot log in, no error message.

**Diagnosis**:
1. Check `APP_KEY` is set (`php artisan key:generate`).
2. Check `SESSION_DRIVER` in config and the `web_session_expire_*` system setting.
3. Verify user `is_active` and not `is_locked`.
4. Check failed login attempts table (`failed_login_attempts`).
5. Verify password hash — check bcrypt argon2 config.

**Resolution**:
- Regenerate `APP_KEY` if missing.
- Check account state in database.
- Unlock account if locked.

### Issue: API returns 401 instead of 403

**Symptoms**: Authenticated user gets 401 on protected routes.

**Diagnosis**:
1. Verify token driver is configured (Sanctum SPA or database tokens).
2. Check token was sent in `Authorization: Bearer` header.
3. Verify token not expired.
4. Check Sanctum middleware is on the route.

**Resolution**:
- Ensure auth middleware uses `auth:sanctum` for API routes.
- Verify token validity and expiration.

### Issue: Rate limiting too aggressive

**Symptoms**: Legitimate users getting 429 errors.

**Diagnosis**:
1. Check rate limit headers (`X-RateLimit-Remaining`, `Retry-After`).
2. Review rate limit configuration.
3. Check if IP is shared (NAT/proxy).

**Resolution**:
- Adjust rate limits in config.
- Consider user-based vs IP-based limits for authenticated endpoints.

### Issue: Sessions not clearing across devices

**Symptoms**: Logout all devices still shows active session.

**Diagnosis**:
1. Check `SESSION_DRIVER` — must be database or Redis (not file) for cross-server session invalidation.
2. Verify session invalidation logic runs.
3. Look for session caches not cleared.

**Resolution**:
- Use database/Redis session driver.
- Ensure session invalidation hooks fire on password change/lock/deactivate.

### Issue: Audit records not appearing

**Symptoms**: Mutations happen but no audit records created.

**Diagnosis**:
1. Audit is written only by the Action/Service layer through
   `Auditable::audit()` — there are no observers. If a new write path mutated
   a model without calling the trait, no row appears (this is the defect,
   not a configuration).
2. Check the transaction committed — the audit row is written **inside** it
   and rolls back with the mutation.
3. Check the reader, not the writer: the viewer filters by allowlisted event
   and source; a row with `source = null` (console/scheduler origin) is
   real and stays.

**Resolution**:
- Add the `Auditable::audit()` call to the Action that owns the mutation
  (`docs/base/features/audit-trail.md` § Adding audit to a new feature).
- There is no `audit.enabled` switch to check — writing is not configurable.

### Issue: Emails not sending

**Symptoms**: Password reset emails, verification emails not received.

**Diagnosis**:
1. Check the effective transport first: mail settings live in
   `system_settings` (Phase 9) and **override** `.env`; `MAIL_MAILER` is only
   the fallback. `/settings` shows the configured transport; the send-test
   probe there is the fastest proof of the real path.
2. Check a queue worker is running for the `default` queue
   (`bin/run-workers.sh local`) — notifications are queued, and a dead worker
   looks exactly like a dead mailer.
3. Check spam/junk folder.
4. Verify `MAIL_FROM_ADDRESS` is set.
5. Check mail logs.

**Resolution**:
- Correct the transport settings on `/settings` (audited) or the MAIL_ env
  variables, then `php artisan queue:restart` so the worker reloads config.
- Whitelist the sending domain with the provider.

## Debugging Tools

| Tool | Purpose |
|------|---------|
| `php artisan route:list` | List all routes + middleware |
| `php artisan route:list --path=pulse` | Verify Pulse routes are registered |
| `php artisan route:list --path=pulse -v` | Confirm the `pulse` middleware group carries `feature:pulse` |
| `php artisan pulse:check` | Verify Pulse is installed and its tables exist |
| `php artisan migrate` | Run the `pulse_*` table migrations |
| `php artisan backup:list` | List backup artifacts on the `backups` disk |
| `php artisan tinker` | Interactive debugging |
| `php artisan log:clear` | Clear log files |
| `php artisan config:clear` | Clear config cache |

## Log Locations

| Log | Location |
|-----|----------|
| Application logs | `storage/logs/laravel.log` |
| Queue logs | `storage/logs/laravel-{queue}.log` |
| Cron logs | System cron log |
| Web server logs | `/var/log/nginx/`, `/var/log/apache2/` |

## Health Checks

- `GET /api/v1/health` — ungated application health endpoint (throttled, cached check set)
- Maintenance mode: the presence of `storage/framework/down` — `php artisan up` exits it
- Failed work: the `failed_jobs` table and `/vantage` (retry needs `queues.retry`)
- Manual: database connectivity, queue workers, disk space

## Support

For issues not resolved here:
1. Document the issue in the QA tracker.
2. Create a regression test.
3. Check the changelog for related changes.
4. Review ADRs for affected decisions.