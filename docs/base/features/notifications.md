# Notifications & Mail

## Overview

Notifications are the user notification abstraction. Mail is the transport for email delivery.

## Notification Channels

| Channel | Use Case |
|---------|----------|
| `mail` | Email notifications |
| `database` | Database notification (inbox) |
| `slack` | Team notifications (ops) |
| `broadcast` | Real-time web notifications |
| `vonage` / `nexmo` | SMS notifications (custom) |
| `push` | Mobile push (custom) |

## Notification Categories

| Category | Channel | Audience |
|----------|---------|----------|
| Security alerts | mail, database | Users |
| Password reset | mail | Users |
| Email verification | mail | Users |
| Audit export ready | mail, database | Admins |
| Registration welcome | mail | Users |
| System alerts | slack, mail | Ops |
| Feature announcements | database, broadcast | Users |

## Mail Configuration

Settings group: `mail`

```
mail.default_transport   (smtp | log | file)
mail.host
mail.port
mail.encryption         (tls | ssl)
mail.username
mail.password           (via env)
mail.from_address
mail.from_name
```

Technical mail config remains in environment (`.env`): `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`.

## Queue Integration

All notifications and mail sending should be queued (not synchronous):
- Use `ShouldQueue` on notification classes.
- Mail Mailable should be queued via `Mail::queue()`.

## Template

Mail/notification templates:
- Use Laravel Mailable/Notifications classes.
- Templates in `resources/views/mail/`.
- Support localization (see i18n dual-source).

## Settings Integration

Some notification settings may be operational:
- `mail.from_address` (operational, manageable via settings)
- `mail.from_name` (operational, manageable via settings)

Transport settings (host, port, credentials) remain technical (config/env only).

## Dependency

Notifications/Queue is Phase 9 in the implementation roadmap.
Depends on: Settings (Phase 8), Queue (Phase 1 infrastructure).