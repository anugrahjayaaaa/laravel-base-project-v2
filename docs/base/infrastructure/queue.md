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

## Named Queues

Application jobs never ride `default` silently — every job names its queue from
`App\Support\QueueName`, and `tests/Feature/Queue/JobConventionTest.php` asserts
it over every class in `app/Jobs` by reflection (a new job is covered the day it
is written).

| Constant | Queue | Jobs |
|---|---|---|
| `QueueName::EXPORTS` | `exports` | `GenerateAuditExport` — heavy, IO-bound, user-initiated |
| `QueueName::BACKUPS` | `backups` | `ExecuteSystemBackupJob` — heavy, IO-bound, scheduled or operator-triggered; one attempt, a partial artifact is retried by an operator. Rides the `backups` connection, consumed by `bin/run-workers.sh backups` |
| `QueueName::SWEEPS` | `sweeps` | `PasswordExpirySweep`, `InactivityLockSweep`, `CleanTemporaryStorageJob` — scheduled / threshold-triggered, idempotent, never urgent |

The queue is set **on the instance** (`$this->onQueue(...)` in the constructor),
never as a `public $queue` property: `Queueable` already declares `$queue`, and
the redeclaration is a class-load fatal that `php -l` does not catch.

Workers listen to all of them in priority order — `default` first (queued
notifications and mails are user-facing), then `exports`, then `sweeps`:

```bash
QUEUE_NAME="${QUEUE_NAME:-default,exports,sweeps}"   # bin/run-workers.sh queue
```

`backups` is **not** in that list: it rides its own connection and its own
worker (below). A worker started with `QUEUE_NAME=default` alone leaves every
named-queue job waiting forever.

### The backups connection

`retry_after` is a property of the connection the **worker** runs, and a job
whose reservation is older than `retry_after` is popped again — on both drivers.
The database driver does not hold a row for the whole run: `DatabaseQueue`'s
`isReservedButExpired()` re-selects it, and each pop increments `attempts`. With
`$tries = 1`, that second pop marks the job failed without running it.

A backup legitimately runs for up to 1800 seconds while every other job finishes
in seconds, so the two shapes cannot share one window. `config/queue.php` derives
a `backups` connection from the default one — same driver and credentials, only
`queue` and `retry_after` overridden — and `ExecuteSystemBackupJob` calls
`onConnection('backups')`:

```text
BACKUP_QUEUE_RETRY_AFTER (2000)  >  ExecuteSystemBackupJob::$timeout (1800)
```

```bash
bin/run-workers.sh backups   # queue:work --connection=backups --queue=backups
```

Both processes are in `deploy/supervisor/laravel-base-project.conf`
(`laravel-v2-worker`, `laravel-v2-backup-worker`) and both start under
`bin/run-workers.sh local`. **A deployment that starts only `queue` never runs a
backup** — the scheduler and the manual trigger would enqueue into a queue
nobody reads.

### Correlation id on the payload

`GenerateRequestCorrelationId` binds `request_id` on the **request**; the worker
that later runs the job is a different process where that binding never
happened. `AppServiceProvider` therefore merges `request_id` into every queue
payload via `Queue::createPayloadUsing()` at push time, and jobs read it with
`Jobs\Concerns\ReadsCorrelationId::requestId()` in `failed()`. The key is always
present — a scheduler-dispatched sweep reads `null` — so a log consumer can rely
on the field existing.

## Password Security Workers

The expiry and inactivity jobs are dispatched by the minute scheduler only when the configured sweep time matches. The comparison uses `now()` in the **global system timezone** (`app_timezone`, ADR-027) — there is no separate sweep timezone. Run the scheduler and queue as separate processes:

```bash
bin/run-workers.sh local
bin/run-workers.sh cron
bin/run-workers.sh queue
bin/run-workers.sh backups
```

Production `queue` should run under Supervisor/systemd. Queue lifecycle verbosity is configurable with `QUEUE_VERBOSITY`; business sweep counters are written to `storage/logs/laravel.log`.

A long-lived `queue:work` (and `schedule:work`) fixes the timezone it booted with. After changing `app_timezone`, run `php artisan queue:restart` and restart `schedule:work`; `schedule:run` ticks from cron each minute and picks the new value up on its own.

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

### The standard every job follows

This is the standard, not a description — `tests/Feature/Queue/JobConventionTest.php`
asserts items 1–3 by reflection over **every** class in `app/Jobs`, so a new job
is held to them the day it is written:

1. **Names its queue** from `App\Support\QueueName`, set with `onQueue()` in the
   constructor — see *Named Queues* above. A new queue name means one constant
   there AND one addition to `bin/run-workers.sh`'s `QUEUE_NAME` default, or the
   job waits forever on a queue nobody listens to. A job whose timeout cannot
   share the connection's `retry_after` also needs its own connection and worker
   — see *The backups connection*.
2. **Declares its retry chain** — `$tries`, `$timeout`, `$maxExceptions` and
   `backoff()` (see *Failure Handling*). The declarations are the point: a retry
   chain read off the framework's defaults is a retry chain nobody wrote down.
3. **Implements `failed()`** — logs event name + exception + `request_id`, read
   through `Jobs\Concerns\ReadsCorrelationId` (see *Correlation id on the
   payload*).
4. **Dispatches after commit** when it depends on committed database state.
5. **Is idempotent** — a retried job must not duplicate side effects.

Two ways to break the correlation contract deliberately, both wrong:

- Do **not** pass `request_id` as a job constructor argument. The value belongs
  to the push-time request; a per-job argument is a call site that can forget
  it, and forgetting is silent.
- Do **not** read it from the container inside a job
  (`app()->bound('request_id')`). The worker is a different process where that
  binding never happened — it answers `null` exactly when the id matters most.

### Dispatch After Commit

```php
DB::transaction(function () use ($data) {
    $model = Model::create($data);
    ProcessModelJob::dispatchAfterCommit($model->id);
});
```

### Failure Handling

- Failed jobs stored in `failed_jobs` table (database).
- Every job declares `$tries`, `$timeout`, `$maxExceptions` and `backoff()`, and
  implements `failed()` logging event name + exception + `request_id`
  (Phase 11 P11-E2/E3). `JobConventionTest` enforces all of it — the
  declarations are the point: a retry chain read off the framework's defaults is
  a retry chain nobody wrote down.
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