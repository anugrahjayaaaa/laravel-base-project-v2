# Queue

## Default Queue

```
database
```

## Redis Compatibility

- The application must remain Redis-compatible.
- Redis may later be used for: Queue, Cache, Rate limiting, Locks, Distributed coordination.
- Do not make Redis mandatory for the base project.
- Business logic must not depend directly on Redis. Use Laravel abstractions.

## Configuration

```env
QUEUE_CONNECTION=database  # default
# QUEUE_CONNECTION=redis  # when Redis available
```

## Password Security Workers

The expiry and inactivity jobs are dispatched by the minute scheduler only when the configured sweep time and timezone match. Run the scheduler and queue as separate processes:

```bash
bin/run-workers.sh local
bin/run-workers.sh cron
bin/run-workers.sh queue
```

Production `queue` should run under Supervisor/systemd. Queue lifecycle verbosity is configurable with `QUEUE_VERBOSITY`; business sweep counters are written to `storage/logs/laravel.log`.

## Queue Candidates
| Job Type | Examples |
|----------|----------|
| Email sending | User notifications, password reset |
| Audit export | Report generation |
| Notification delivery | Push/email/SMS |
| Log archival | Log rotation |
| Backup tasks | Database/file backup |
| Expensive/background work | Any CPU/IO intensive operation |

## Job Patterns

### Dispatch After Commit

```php
DB::transaction(function () use ($data) {
    $model = Model::create($data);
    ProcessModelJob::dispatchAfterCommit($model->id);
});
```

### Failure Handling

- Failed jobs stored in `failed_jobs` table (database).
- Configure `retry_until`, `tries`, `backoff`.
- Monitor failed job queue via Horizon (if Redis) or database monitoring.

**The ceiling belongs to the setting, not the code.** `audit-export` reads
`audit_export_rate_limit_per_hour` from `system_settings` rather than hardcoding
it, because the audit page quotes the same number in its Export tooltip — one
stored value, one shown value. The same applies to the USER-facing retry budget
on a failed export: `audit_export_max_manual_retries` is read through
`AuditExport::maxRetries()`, which is the only place the key appears, so the
Action that enforces the cap, the API resource that reports it and the Blade that
renders the button cannot disagree about it. `user-state-actions`, `bulk-action`
and `send-test-mail` are still literals; see
`phase-11-monitoring-observability.md` for the dashboard decision and what still
needs a `SystemSetting`.

**Retries need a log line of their own.** `failed()` fires once, after the final
attempt, and `failed_jobs` keeps one row per job — so with `$tries` above 1 the
early attempts leave no trace anywhere. Log each attempt (with its number) before
rethrowing. `GenerateAuditExport` does this and it is the pattern.

### After-Commit Dispatch

Jobs that depend on committed database state MUST use
`dispatchAfterCommit()` (or `Bus::afterCommit`) instead of `dispatch()`.
This guarantees the job runs only after the transaction commits, and is
discarded if the transaction rolls back:

```php
DB::transaction(function () use ($user) {
    $user->update($data);
    SendWelcomeEmailJob::dispatchAfterCommit($user);
});
```

### Rollback Behavior

If the enclosing transaction rolls back, any `dispatchAfterCommit` jobs are
discarded. A failure must be observable through application logging (event
name + exception + request_id). Jobs MUST be idempotent — a retried job must
not duplicate side effects (e.g., sending an email twice).

## Idempotency

- Design jobs to be idempotent.
- Use unique job constraint where applicable:
  ```php
  public function unique()
  {
      return 'process-user-' . $this->user->id;
  }
  ```

## Scaling

- Horizontal scaling with multiple workers.
- Use `php artisan queue:work --daemon` for production.
- Monitor queue length and processing time.