#!/usr/bin/env bash
#
# Restore the previous release on the VM after a failed deploy.
#
# CODE ONLY. The database is not reverted — see ADR-021. A bad migration is
# fixed forward with a new migration on the next push; the pre-migrate dump in
# storage/db-backups is the manual recovery route, restored by hand because
# it also discards every write made since it was taken.
#
# The health check only probes /up and /vendor/theme.css, so a red check is
# usually an asset or worker problem rather than a schema one, and
# `migrate --force` applies a whole batch at once — reverting the schema here
# would drop columns that were never at fault.
#
# .git is excluded from the deploy rsync, so the server has no history to check
# out from. The workaround is a tarball of the previous release, taken on the
# VM just before the new code is synced.
#
# Called by .github/workflows/ci-cd.yml from the health check step when the
# deploy turns out to be broken. Also safe to run by hand over SSH.
#
# Usage: bin/deploy-rollback.sh snapshot            take a release snapshot
#        bin/deploy-rollback.sh restore [archive]   restore (default: newest)
#
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SNAPSHOT_DIR="storage/release-snapshots"
PHP_BIN="${PHP_BIN:-php}"

# Prune with find, not `tar --exclude`.
#
# A leading `./` and a trailing slash are not enough to anchor an exclude: GNU
# tar treats `vendor` as a path prefix and BSD tar matches it as a substring,
# so both drop `public/vendor/` as well — the vendored AdminLTE and Bootstrap
# assets. Those are the files a rollback most needs to restore, and they are
# what the earlier rsync regression deleted from the VM.
#
# find(1) -prune has one meaning in both implementations, and handing tar an
# explicit file list makes the snapshot reproducible instead of
# implementation-dependent.
PRUNE_PATHS=(./.git ./node_modules ./vendor ./storage)
PRUNE_FILES=(./.env)

release_file_list() {
    local prune_args=() expr=() path
    for path in "${PRUNE_PATHS[@]}"; do
        prune_args+=(-path "$path" -prune -o)
    done
    for path in "${PRUNE_FILES[@]}"; do
        prune_args+=(-name "$path" -prune -o)
    done
    find . -mindepth 1 "${prune_args[@]}" -print
}

snapshot() {
    local target="$1"
    release_file_list | tar -czf "$target" -T -
}

restore() {
    local archive="$1"
    # storage/ and .env are never in a snapshot; excluding them again keeps a
    # hand-made tarball from overwriting live state.
    tar -xzf "$archive" \
        --exclude='./storage/' \
        --exclude='./.env' \
        --exclude='./.git/'

    composer install --prefer-dist --no-dev --no-interaction --no-progress --optimize-autoloader
    "$PHP_BIN" artisan optimize:clear
    "$PHP_BIN" artisan config:cache
    "$PHP_BIN" artisan route:cache
    "$PHP_BIN" artisan view:cache
    "$PHP_BIN" artisan queue:restart

    # storage/ is never in a snapshot, so recreate what the workers need.
    mkdir -p storage/logs storage/db-backups \
             storage/framework/cache/data storage/framework/sessions storage/framework/views \
             bootstrap/cache
    # The script ships in every release, but a rollback can land on a tree that
    # predates it. Not fatal: supervisor reports a missing program itself.
    if [ -f bin/run-workers.sh ]; then
        chmod +x bin/run-workers.sh
    else
        echo "::warning::bin/run-workers.sh is absent from this release; skipping chmod"
    fi
    chmod -R u+rwX,g+rwX storage bootstrap/cache

    for svc in "php$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" php-fpm; do
        if command -v sudo >/dev/null && sudo -n systemctl cat "$svc" >/dev/null 2>&1; then
            sudo -n systemctl reload "$svc" && echo "reloaded $svc"
            break
        fi
    done
}

latest_snapshot() {
    find "$SNAPSHOT_DIR" -maxdepth 1 -name '*.tar.gz' -type f 2>/dev/null | sort | tail -n 1
}

do_snapshot() {
    mkdir -p "$SNAPSHOT_DIR"
    # %N is nanoseconds: without it, two snapshots inside the same second share
    # a name, the second overwrites the first, and the prune then deletes the
    # file it just wrote.
    local target="$SNAPSHOT_DIR/$(date -u +%Y%m%d-%H%M%S-%N).tar.gz"
    snapshot "$target"
    echo "snapshot written to $target"

    # Keep the last 3 releases; older ones are not reachable by a rollback.
    # `head -n -3` is GNU-only, and BSD head rejects it outright — count
    # instead so the same line works on a laptop and on the VM.
    find "$SNAPSHOT_DIR" -maxdepth 1 -name '*.tar.gz' -type f | sort > "$SNAPSHOT_DIR/.list"
    local keep total
    total=$(wc -l < "$SNAPSHOT_DIR/.list" | tr -d ' ')
    if [ "$total" -gt 3 ]; then
        keep=$((total - 3))
        head -n "$keep" "$SNAPSHOT_DIR/.list" | xargs -r rm -f
    fi
    rm -f "$SNAPSHOT_DIR/.list"
}

do_restore() {
    local archive="${1:-}"
    if [ -z "$archive" ]; then
        archive="$(latest_snapshot)"
    fi
    if [ -z "$archive" ] || [ ! -f "$archive" ]; then
        echo "::error::no release snapshot found in $SNAPSHOT_DIR — cannot roll back automatically" >&2
        return 1
    fi

    echo "restoring $archive"
    restore "$archive"
    echo "rollback complete"
}

case "${1:-}" in
    snapshot)
        do_snapshot
        ;;

    restore)
        do_restore "${2:-}"
        ;;

    *)
        echo "usage: $0 snapshot | restore [archive]" >&2
        exit 2
        ;;
esac
