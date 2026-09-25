#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PHP_BIN="${PHP_BIN:-php}"
QUEUE_NAME="${QUEUE_NAME:-default}"
QUEUE_SLEEP="${QUEUE_SLEEP:-3}"
QUEUE_TRIES="${QUEUE_TRIES:-3}"
QUEUE_TIMEOUT="${QUEUE_TIMEOUT:-90}"
QUEUE_VERBOSITY="${QUEUE_VERBOSITY:-vvv}"

usage() {
    cat <<'EOF'
Usage:
  bin/run-workers.sh cron       Run one scheduler tick (use with system cron)
  bin/run-workers.sh queue      Run one queue worker (use with Supervisor/systemd)
  bin/run-workers.sh local      Run scheduler and queue together (local development)

Environment:
  PHP_BIN         PHP binary (default: php)
  QUEUE_NAME      Queue name (default: default)
  QUEUE_SLEEP     Queue sleep seconds (default: 3)
  QUEUE_TRIES     Queue retry attempts (default: 3)
  QUEUE_TIMEOUT   Queue timeout seconds (default: 90)
  QUEUE_VERBOSITY Queue worker verbosity: v, vv, or vvv (default: vvv)
EOF
}

case "${1:-}" in
    cron)
        exec "$PHP_BIN" artisan schedule:run
        ;;

    queue)
        exec "$PHP_BIN" artisan queue:work \
            --queue="$QUEUE_NAME" \
            --sleep="$QUEUE_SLEEP" \
            --tries="$QUEUE_TRIES" \
            --timeout="$QUEUE_TIMEOUT" \
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

        cleanup() {
            kill "$scheduler_pid" "$queue_pid" 2>/dev/null || true
            wait "$scheduler_pid" "$queue_pid" 2>/dev/null || true
        }
        trap cleanup INT TERM EXIT

        while kill -0 "$scheduler_pid" 2>/dev/null && kill -0 "$queue_pid" 2>/dev/null; do
            sleep 1
        done
        ;;

    *)
        usage
        exit 1
        ;;
esac
