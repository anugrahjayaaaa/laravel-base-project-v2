# DEP-006: Database Queue with Redis Compatibility

- **Status**: Accepted
- **Category**: Dependency Selection
- **Architecture Area**: Queue & Cache (Phase 1 `QUEUE-001`, `CACHE-001`)

## Context

The Base Project requires a queue backend for asynchronous processing
(emails, audit export, notifications). It also requires a cache backend.
Redis is the preferred high-throughput option in production, but the Base
Project should work with minimal infrastructure (Composer + PHP + database
only).

## Decision

- **Default queue backend**: Laravel's native `database` queue driver.
- **Default cache backend**: Laravel's native `file` cache driver.
- **Redis**: supported as a drop-in replacement via config, but NOT
  mandatory. Application code must never reference Redis directly.

## Alternatives Considered

- **Redis as default (mandatory)**: Would force every deployment to run a
  Redis server. Increases setup complexity and infrastructure requirements.
  Rejected for the Base Project.
- **External queue server (RabbitMQ, Beanstalkd)**: Adds infrastructure
  complexity. Laravel's database driver is sufficient for the foundation.
- **Synchronous execution only (no queue)**: Would block the request cycle
  on slow operations (email sending, audit export). Unacceptable.

## Why This Decision

- The `database` queue driver uses a `jobs` table — zero extra infrastructure.
- Redis can be enabled by setting `QUEUE_CONNECTION=redis` and
  `CACHE_STORE=redis` in `.env` — no application code changes.
- Application code uses Laravel's `Queue` and `Cache` facades exclusively,
  which abstract the underlying driver.

## Consequences

- `jobs` and `job_batches` tables required in migrations (Laravel 13 native).
- Application business logic references `Queue::push()`,
  `Cache::remember()`, `RateLimiter::attempt()` — never Redis APIs.
- Rate limiting uses `Illuminate\Cache\RateLimiter` (driven by cache backend)
  — works on both `file` and `redis` backends.
- Locks use `Illuminate\Support\Facades\Lock` — works on both `file` and
  `redis` backends.

## Security Implications

- Redis has no authentication by default — deployment config must enforce
  `requirepass`, bind address, and TLS if used.
- Database queue stores job payloads — ensure jobs do not contain sensitive
  data in their serialized state.

## Maintenance Implications

- No Redis-specific code to maintain.
- Switch between `database` and `redis` backends via environment config only.
- Queue worker monitoring (`php artisan queue:work`) is the same regardless
  of backend.

## Reversal / Replacement

- Swap `QUEUE_CONNECTION` and `CACHE_STORE` in `.env` — immediate switch.
- No application code changes required because all access is via facades.
