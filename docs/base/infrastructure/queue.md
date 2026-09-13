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

## Queue Jobs

Queue candidates include:
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