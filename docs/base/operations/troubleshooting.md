# Troubleshooting

## Common Issues

### Issue: Authentication fails silently

**Symptoms**: User cannot log in, no error message.

**Diagnosis**:
1. Check `APP_KEY` is set (`php artisan key:generate`).
2. Check `SESSION_DRIVER` and `SESSION_LIFETIME` in config.
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
1. Verify audit is triggered in the Action/Service layer (not just observers).
2. Check transaction is committing successfully (audit rolls back on failure).
3. Check audit config — is auditing enabled?
4. Verify `audit.enabled` setting.

**Resolution**:
- Add explicit audit calls in Action/Service.
- Verify transaction commits successfully (audit rolls back on failure).
- Enable audit setting.

### Issue: Emails not sending

**Symptoms**: Password reset emails, verification emails not received.

**Diagnosis**:
1. Check `MAIL_MAILER` — set to `log` or `smtp`.
2. Check queue worker is running (if queued).
3. Check spam/junk folder.
4. Verify `MAIL_FROM_ADDRESS` is set.
5. Check mail logs.

**Resolution**:
- Set correct MAIL_ env variables.
- Run `php artisan queue:work`.
- Whitelist sending domain in email provider.

## Debugging Tools

|| Tool | Purpose |
||------|---------|
|| `php artisan telescope` | (if installed) Laravel runtime inspection (technical debug) |
|| `php artisan tinker` | Interactive debugging |
|| `php artisan log:clear` | Clear log files |
|| `php artisan config:clear` | Clear config cache |
|| `php artisan route:list` | List all routes + middleware |

## Log Locations

| Log | Location |
|-----|----------|
| Application logs | `storage/logs/laravel.log` |
| Queue logs | `storage/logs/laravel-{queue}.log` |
| Cron logs | System cron log |
| Web server logs | `/var/log/nginx/`, `/var/log/apache2/` |

## Health Checks

- `/api/v1/health` — application health endpoint
- `php artisan up` — maintenance mode check
- Manual: database connectivity, queue workers, disk space

## Support

For issues not resolved here:
1. Document the issue in the QA tracker.
2. Create a regression test.
3. Check the changelog for related changes.
4. Review ADRs for affected decisions.