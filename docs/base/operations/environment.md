# Environment

## Environments

| Environment | Purpose |
|------------|---------|
| Local | Development |
| CI | Automated testing |
| Staging | Pre-production testing |
| Production | Live application |

## Environment Variables

### Base (.env.example)

```env
APP_NAME="Laravel Base Project"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_base
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_STORE=file
QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_LIFETIME=120

# Mail (defaults to log for local)
MAIL_MAILER=log
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"

# Redis (optional, not required for base)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Production

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:generated-key-here
APP_URL=https://your-app.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

CACHE_STORE=redis     # when available
QUEUE_CONNECTION=redis # when available
SESSION_DRIVER=redis   # when available
```

## Configuration Files

All configuration in `config/`:
- `app.php` — application settings
- `auth.php` — authentication config
- `cache.php` — cache config (default: file)
- `database.php` — database config
- `filesystems.php` — storage config
- `logging.php` — log channels
- `queue.php` — queue config (default: database)
- `session.php` — session config
- `mail.php` — mail config

## Settings vs Config

| Setting | Location | Manageable via UI? |
|---------|----------|-------------------|
| `security.inactivity.days` | Settings DB | Yes |
| `registration.enabled` | Settings DB | Yes |
| `DB_HOST` | `.env` / config | No |
| `QUEUE_CONNECTION` | `.env` / config | No |

- Technical infrastructure settings remain in configuration files.
- Operational application settings may be managed from the dashboard.

## Secrets

NEVER commit:
- `.env`
- passwords
- API secrets
- private keys
- mail credentials
- database credentials
- production credentials

Use environment variables for all secrets.