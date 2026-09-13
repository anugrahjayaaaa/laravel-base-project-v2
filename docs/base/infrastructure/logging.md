# Logging

## Strategy

Structured logging with Laravel's logging system.

## Channels

| Channel | Use |
|---------|-----|
| `stack` (default) | Development logging |
| `single` | Simple single-file logging |
| `daily` | Daily rotating log files |
| `slack` | Alert notifications |
| `syslog` | System-level logging |
| `errorlog` | PHP error log |

## Log Structure

Use structured context in all log entries:

```php
Log::info('User logged in', [
    'user_id' => $user->id,
    'ip' => request()->ip(),
    'user_agent' => request()->userAgent(),
    'request_id' => request()->header('X-Request-ID'),
]);
```

## Log Levels

| Level | When |
|-------|------|
| emergency | System unusable |
| alert | Immediate action required |
| critical | Critical conditions |
| error | Runtime errors (non-fatal) |
| warning | Exceptional occurrences |
| notice | Normal but significant events |
| info | Interesting events |
| debug | Detailed debug info |

## Security in Logs

- Log correlation/request IDs with every relevant log entry.
- Audit metadata should be logged.
- Never log: passwords, tokens, secrets, PII without protection.
- Log IP addresses and user agents for audit/security context.

## Retention

Configurable log retention:
```php
// config/logging.php
'tap' => [
    'production' => [
        'days' => 30, // configurable
    ],
],
```

## Correlation IDs

- Every request gets a unique correlation/request ID.
- Propagated to logs, audit records, exception handling, and queue context.
- Generated at middleware level; available globally via helper or context.

## Exception Handling

- Custom exception handler for consistent error responses.
- Log exceptions with full context (request ID, user, IP).
- Do not expose stack traces in production responses.
- Send critical errors to monitoring (Sentry, etc.) where configured.