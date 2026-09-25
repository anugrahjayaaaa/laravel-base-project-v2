# GitHub Actions Deployment Secrets Template

Do not put real values in this file. Add the values to GitHub under:
`Settings → Environments → Laravel Base Project V2 - Google Cloud → Environment secrets`.

| Secret | Example/source | Required |
|---|---|---|
| `DEPLOY_HOST` | VM public IP or SSH DNS name | Yes |
| `DEPLOY_USER` | Dedicated non-root Linux deployment user | Yes |
| `DEPLOY_PORT` | `22` or the VM SSH port | Yes |
| `DEPLOY_PATH` | `/var/www/laravel-base-project-v2` | Yes |
| `DEPLOY_URL` | `https://app.example.com` | Yes |
| `DEPLOY_SSH_KEY` | Private deploy key, stored only in GitHub Secrets | Yes |
| `DEPLOY_KNOWN_HOSTS` | Verified SSH host-key line for the VM | Yes |

## Generate the deploy key

Run on a trusted local machine. Do not send the private key through chat or commit it:

```bash
ssh-keygen -t ed25519 -C github-actions-laravel-base-project-v2
```

Keep the private key locally and add its contents to GitHub as `DEPLOY_SSH_KEY`.
Add the matching `.pub` public key to the deployment user's `~/.ssh/authorized_keys` on the VM.

## Verify the VM host key

Run against the VM, then compare the fingerprint through a trusted channel before saving the line:

```bash
ssh-keyscan -p 22 DEPLOY_HOST
ssh-keygen -lf <(ssh-keyscan -p 22 DEPLOY_HOST 2>/dev/null)
```

Save the verified host-key line as `DEPLOY_KNOWN_HOSTS`.

## Production environment file

Copy `.env.production.example` to the VM as:

```text
/var/www/laravel-base-project-v2/.env
```

Fill it directly on the VM. Never commit the filled `.env` file.

For Cloud SQL MySQL:

- use the private IP or the Cloud SQL Unix socket path;
- install the Cloud SQL CA certificate on the VM;
- set `MYSQL_ATTR_SSL_CA` to that certificate path;
- verify connectivity from the VM before running the first deployment.

## Suggested GitHub setup

1. Create a `Laravel Base Project V2 - Google Cloud` GitHub Environment.
2. Require manual approval for deployments from that Environment.
3. Add the seven secrets above to that Environment, not to repository-level secrets.
4. Protect `main` and require the `Test and build` check before merge.
5. Trigger the first deployment with `workflow_dispatch`; do not rely on `main` until SSH works.

## VM prerequisites

The deployment user must already have:

- SSH key access;
- `rsync`, PHP 8.3, Composer, and required PHP extensions;
- write access to `DEPLOY_PATH` and `storage/`;
- existing `.env` with Cloud SQL and mail configuration;
- existing queue/scheduler process management.

The repository already contains `bin/run-workers.sh` for:

```bash
bin/run-workers.sh cron
bin/run-workers.sh queue
```

The VM's existing Supervisor/systemd configuration should call those scripts. `php artisan queue:restart` in the workflow only signals workers; it does not create the Supervisor/systemd service.
