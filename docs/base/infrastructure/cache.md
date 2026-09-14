# Cache

## Strategy

- Laravel's Cache abstraction used throughout.
- Default cache driver: file (no Redis requirement).
- Redis cache driver when available for production performance.

## Configuration

```env
CACHE_STORE=file    # default
SESSION_DRIVER=database  # default
```

## Cache Tags

Use cache tags for grouped invalidation:

```php
Cache::tags(['users', 'permissions'])->put('user-permissions-'.$userId, $perms, 3600);
Cache::tags(['users'])->flush(); // flush only user-related cache
```

## Cache Keys

- Use descriptive, namespaced cache keys.
- Include model identifier and version: `user-permissions:1:2` (user 1, permissions version 2).
- Never cache sensitive data without encryption.

## Cache Invalidation

- Settings changes should invalidate relevant cache.
- Role/permission changes should invalidate affected user permission caches.
- Use events to trigger cache invalidation where applicable.
- Cache expiration as safety net, not primary invalidation strategy.

## What NOT to Cache

- Passwords / sensitive credentials
- Audit records (read directly for integrity)
- Session tokens (except via session driver)
- Real-time data where staleness is unacceptable

## TTL Strategy

| Data Type | TTL | Rationale |
|----------|-----|-----------|
| Permissions | 3600s (1 hour) | Rarely changes; re-derived on role change |
| Settings | 300s (5 min) | Operational settings may change |
| User profile | 600s (10 min) | Read-heavy, infrequent changes |
| Feature flags | 300s | May toggle based on conditions |

## Cache-Aside Pattern

```php
return Cache::remember('key', $ttl, function () {
    return $expensiveOperation();
});
```