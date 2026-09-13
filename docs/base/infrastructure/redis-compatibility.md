# Redis Compatibility

## Compatibility Strategy

- Default infrastructure uses database driver (not Redis).
- Architecture must REMAIN Redis-compatible.
- Redis is NOT mandatory for the base project.

## Redis Usage (when enabled)

| Use Case | Redis Feature |
|----------|---------------|
| Queue | `redis` driver for `queue.php` |
| Cache | `redis` driver for `cache.php` |
| Rate limiting | `redis` driver for `rate_limits` |
| Locks | `redis` driver for `locks.php` |
| Distributed coordination | Redis sets, sorted sets, pub/sub |

## Configuration

```env
# Default (no Redis required)
CACHE_DRIVER=file
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# With Redis (optional)
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

## Abstraction Rule

- Business logic must not depend directly on Redis.
- All Redis usage via Laravel abstractions:
  - `Cache::store('redis')`
  - `Queue::connection('redis')`
  - `RateLimiter::attempt()`
  - `Cache::lock()` (`Lock` facade for distributed locks)

## Why Abstraction?

Using Laravel abstractions (Cache, Queue, Lock, RateLimiter facades) ensures the application can switch between database/file/Redis without code changes. Business logic never imports `Predis` or `PhpRedis` directly.

## Horizon

For production Redis queue monitoring:
- Optional package: `laravel/horizon`
- Provides UI for queue metrics, job throughput, failure rate
- Redis-only; not compatible with database queue