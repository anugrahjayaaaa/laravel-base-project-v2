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
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# With Redis (optional)
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

### `REDIS_HOST` is the address as seen FROM THE APP

`redis` resolves only inside the Docker network where the container runs. When
the app runs on the host and Redis in Docker, Docker publishes the port and the
correct value is `127.0.0.1`. Setting `REDIS_HOST=redis` in that setup makes
every cache and session read fail to resolve. Check with
`redis-cli -h <host> ping` before enabling the driver.

### Measured: Redis is not faster on a single node (2026-10-02)

Both drivers were forced on the same booted app and the same three routes timed
under each, alternating order, two independent runs, 40 iterations per pass.
Taking `min` (the least noise-contaminated estimator):

| Route | `database` | `redis` | delta |
|---|---|---|---|
| `GET /dashboard` | 7.08 ms | 7.80 ms | **+0.73 ms** |
| `GET /users` | 46.31 ms | 50.86 ms | **+4.55 ms** |
| `GET /settings` | 14.09 ms | 14.51 ms | **+0.42 ms** |

Redis was slower on every route in both runs. The reason is structural: a cache
or session operation becomes a TCP round trip over loopback, where the
alternative was a local file or SQLite read. The network is only cheaper than
the alternative when the alternative is a REMOTE database — several concurrent
workers, or a database on another host, where the round trip is going out
anyway.

So the default stays `database` (which also keeps the zero-infrastructure
promise in DEP-006). Turn Redis on when the deployment is multi-node or the
database is remote, not as a local speedup.

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