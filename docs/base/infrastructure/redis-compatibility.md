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

### The queue is a different question from the cache

The measurement above is **cache-only**, and it does not transfer. The queue
was moved to Redis on the same host and is a real change of shape, not a
latency trim: `jobs` goes from a MySQL table scanned by every worker on a poll
interval to a Redis list that a worker pops, so adding workers no longer adds
table pressure. Cache and queue were therefore given separate databases —
queue on `REDIS_DB` (0), cache on `REDIS_CACHE_DB` (1) — so flushing one
cannot take the other.

`SESSION_DRIVER` was left on `database`: sessions are read and written on
every single request, which is the worst case for the finding above.

One setting is **not** optional on the redis driver:

```
REDIS_QUEUE_RETRY_AFTER=180   # must exceed the worker's --timeout (default 90)
```

A redis-driver job sits in a `reserved` sorted set for `retry_after` seconds.
The shipped default is 90 and `bin/run-workers.sh`'s `QUEUE_TIMEOUT` is also
90, so a job that hits its timeout becomes visible again *while a worker is
still running it* — and a second worker executes it too. The database driver
does not have this window: it reserves the row atomically at pop time. Raise
the retry window whenever the worker timeout is raised.

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