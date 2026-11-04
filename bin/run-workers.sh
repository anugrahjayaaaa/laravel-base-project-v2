#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PHP_BIN="${PHP_BIN:-php}"
# `default` first because queued notifications and mails ride it and are
# user-facing; `exports` next (user-initiated, heavy); `sweeps` last
# (scheduled, idempotent, never urgent). Jobs name their queue via
# `App\Support\QueueName` — a worker that listens only `default` would
# leave every named-queue job waiting forever (Phase 11 P11-E1).
#
# `backups` is NOT in this list: it rides the `backups` connection (see
# config/queue.php), whose retry window is longer than the backup job's
# 30-minute timeout. A second worker (`bin/run-workers.sh backups`) consumes it.
QUEUE_NAME="${QUEUE_NAME:-default,exports,sweeps}"
BACKUP_QUEUE="${BACKUP_QUEUE:-backups}"
QUEUE_SLEEP="${QUEUE_SLEEP:-3}"
QUEUE_TRIES="${QUEUE_TRIES:-3}"
QUEUE_TIMEOUT="${QUEUE_TIMEOUT:-90}"
BACKUP_QUEUE_TIMEOUT="${BACKUP_QUEUE_TIMEOUT:-1800}"
QUEUE_VERBOSITY="${QUEUE_VERBOSITY:-vvv}"

usage() {
    cat <<'EOF'
Usage:
  bin/run-workers.sh cron       Run one scheduler tick (use with system cron)
  bin/run-workers.sh scheduler  Run the scheduler daemon (use with Supervisor)
  bin/run-workers.sh queue      Run one queue worker (use with Supervisor/systemd)
  bin/run-workers.sh backups    Run the backup queue worker (use with Supervisor)
  bin/run-workers.sh local      Run scheduler and both workers together (local development)

Environment:
  PHP_BIN              PHP binary (default: php)
  QUEUE_NAME           Comma-separated queue names (default: default,exports,sweeps)
  BACKUP_QUEUE         Backup queue name (default: backups)
  QUEUE_SLEEP          Queue sleep seconds (default: 3)
  QUEUE_TRIES          Queue retry attempts (default: 3)
  QUEUE_TIMEOUT        Queue timeout seconds (default: 90)
  BACKUP_QUEUE_TIMEOUT Backup worker timeout seconds (default: 1800)
  QUEUE_VERBOSITY      Queue worker verbosity: v, vv, or vvv (default: vvv)
EOF
}

case "${1:-}" in
    cron)
        exec "$PHP_BIN" artisan schedule:run
        ;;

    # Supervisor needs a long-running process. `cron` exits after one tick, so
    # supervisor would restart it in a tight loop.
    scheduler)
        exec "$PHP_BIN" artisan schedule:work
        ;;

    queue)
        exec "$PHP_BIN" artisan queue:work \
            --queue="$QUEUE_NAME" \
            --sleep="$QUEUE_SLEEP" \
            --tries="$QUEUE_TRIES" \
            --timeout="$QUEUE_TIMEOUT" \
            "-$QUEUE_VERBOSITY"
        ;;

    # The backup job runs for up to BACKUP_QUEUE_TIMEOUT (1800s), and the
    # `backups` connection holds its row for BACKUP_QUEUE_RETRY_AFTER (2000s).
    # One worker cannot serve both windows, so this is a process of its own.
    backups)
        exec "$PHP_BIN" artisan queue:work backups \
            --queue="$BACKUP_QUEUE" \
            --sleep="$QUEUE_SLEEP" \
            --tries="$QUEUE_TRIES" \
            --timeout="$BACKUP_QUEUE_TIMEOUT" \
            "-$QUEUE_VERBOSITY"
        ;;

    local)
        "$PHP_BIN" artisan schedule:work &
        scheduler_pid=$!
        "$PHP_BIN" artisan queue:work \
            --queue="$QUEUE_NAME" \
            --sleep="$QUEUE_SLEEP" \
            --tries="$QUEUE_TRIES" \
            --timeout="$QUEUE_TIMEOUT" \
            "-$QUEUE_VERBOSITY" &
        queue_pid=$!
        "$PHP_BIN" artisan queue:work backups \
            --queue="$BACKUP_QUEUE" \
            --sleep="$QUEUE_SLEEP" \
            --tries="$QUEUE_TRIES" \
            --timeout="$BACKUP_QUEUE_TIMEOUT" \
            "-$QUEUE_VERBOSITY" &
        backups_pid=$!

        cleanup() {
            kill "$scheduler_pid" "$queue_pid" "$backups_pid" 2>/dev/null || true
            wait "$scheduler_pid" "$queue_pid" "$backups_pid" 2>/dev/null || true
        }
        trap cleanup INT TERM EXIT

        while kill -0 "$scheduler_pid" 2>/dev/null \
            && kill -0 "$queue_pid" 2>/dev/null \
            && kill -0 "$backups_pid" 2>/dev/null; do
            sleep 1
        done
        ;;

    *)
        usage
        exit 1
        ;;
esac
