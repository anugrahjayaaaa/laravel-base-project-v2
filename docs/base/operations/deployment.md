# Deployment

## Overview

Deployment strategy and procedures for the Laravel Base Project.

## Environment Configuration

### Required Environments

| Environment | Purpose |
|------------|---------|
| Local | Development |
| Staging | Pre-production testing |
| Production | Live application |

### Environment Variables

```env
APP_NAME="Laravel Base Project"
APP_ENV=local|staging|production
APP_KEY=base64:...
APP_DEBUG=false  # NEVER true in production
APP_URL=https://example.com

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_base
DB_USERNAME=root
DB_PASSWORD=secret
```

## Deployment Steps

```
1. Pull latest code (tagged release)
2. Checkout release tag
3. Install/update dependencies (composer install --no-dev)
4. Run migrations (php artisan migrate --force)
5. Run seeders if needed (php artisan db:seed --force) — a newly declared
   Pennant flag 403s its whole module until `FeatureFlagSeeder` writes the
   row; the store is fail-closed, seeding is not optional.
   `SEED_PASSWORD` must be set in the environment first: outside local/testing
   `SuperAdminSeeder` refuses to write an account whose password is the one
   published in this repository (`#Password123`). The demo seeders
   (`UserSeeder`, `MemberDemoSeeder`) also refuse to run there, and
   `DatabaseSeeder` skips `UserSeeder` for them, so a production seed writes
   starter data only.
6. Config cache (php artisan config:cache)
7. Route cache (php artisan route:cache)
8. View cache (php artisan view:cache)
9. Restart queue workers (php artisan queue:restart)
10. Clear/restart opcache
11. Health check verification
12. Notify stakeholders
```

## Processes an operator must start

The deploy checklist cannot start these; the host runs them.

```
bin/run-workers.sh local     # queue:work default,exports,sweeps
bin/run-workers.sh backups   # the ExecuteSystemBackupJob pair: queue AND
                             # connection `backups` — a listener on `default`
                             # never consumes it (docs/base/infrastructure/queue.md)
bin/run-workers.sh cron      # schedule:work, or `* * * * * php artisan schedule:run`
```

After `config:cache`, restart workers (`php artisan queue:restart`); a worker
keeps the config and timezone it booted with.

Steps 6–8 are what `php artisan optimize` does (config + events + routes + views);
that sequence was run end to end on the release tree in P17-C2 and exited 0.
`config:cache` serialises the merged config with `var_export`/`eval`, so **any config
value holding a live object without `__set_state` fails the deploy** — the one that did
was a scramble `SecurityScheme`, now built inside
`App\Support\OpenApi\BearerAuthSecurityStrategy` instead (`docs/base/api/documentation.md`).
`tests/Arch/SerializableConfigTest.php` is the gate, so the failure surfaces in CI
rather than at step 6. `optimize:clear` is the **development** command (it is how the
verification run restored its state), not part of this checklist.

## CI/CD

Integrate with GitHub Actions (or similar CI):
- Run tests on every PR
- Run security scan
- Run architecture compliance tests
- Deploy to staging on merge to develop
- Deploy to production on main branch tag

## Rollback Procedure

Code rollback is automated: `bin/deploy-rollback.sh` snapshots the release on
the VM before the rsync, and the deploy job restores it when the health check
fails. The database is deliberately left alone — see ADR-021.

```
1. Deploy failed → rollback already ran automatically
2. Recover the database by hand, only if the migration itself was the cause:
     ls -1t storage/db-backups/*.sql | head -1     # newest pre-migrate dump
     mysql <app> < that dump
3. Or fix forward: write a new migration, push. Preferred over step 2.
4. Verify health
5. Notify stakeholders
```

Never revert the schema automatically. The health check only probes `/up` and
`/vendor/theme.css`, so a red check is usually an asset or worker problem, and
`migrate --force` applies a whole batch at once — rolling back would drop
columns that were never at fault and discard rows written since the deploy.

A restored dump discards every write made after it was taken. Plan a
maintenance window for it.

## Secrets Management

- Secrets loaded from environment variables only.
- `.env` never committed to version control.
- Production secrets managed via:
  - Environment variables (server-level)
  - Secret manager (AWS Secrets Manager, HashiCorp Vault, etc.)
  - Never stored in code or config files committed to git

## Zero-Downtime Deployment

- Use load balancer with multiple app server instances
- Deploy new version to one instance at a time
- Run migrations in backward-compatible mode
- Queue restart only after all instances updated

## Monitoring During Deploy

- Monitor: error rate, response time, queue depth
- Alert on: deployment failure, high error rate
- Rollback automatically on health check failure

## Backup Before Deploy

- Database backup before migration
- File backup before destructive operations
- Ability to restore within RPO/RTO targets