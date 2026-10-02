#!/usr/bin/env bash
# A/B latency probe: boots a real HTTP server against the dev stack and times
# three authenticated pages. Requires a seeded DB + a known password.
set -uo pipefail

ROUTES=("/dashboard" "/users" "/settings")
N=${N:-30}
EMAIL=${EMAIL:-bench@example.com}
PASS=${PASS:-password}
PORT=${PORT:-8899}
BASE="http://127.0.0.1:${PORT}"
JAR=$(mktemp)
OUT=$(mktemp)

cleanup() { kill "$SRV" 2>/dev/null; wait "$SRV" 2>/dev/null; rm -f "$JAR" "$OUT"; }
trap cleanup EXIT

cd "$(dirname "$0")/.."

php -r '
require "vendor/autoload.php"; $a = require "bootstrap/app.php";
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u = App\Models\User::where("email", getenv("EMAIL"))->first();
if (! $u) {
    // forceFill, not create(): the bench user needs `username`, which is not
    // mass-assignable, and a silent fillable drop leaves is_active null and the
    // login silently refused.
    $u = new App\Models\User();
    $u->forceFill([
        "name" => "Bench", "email" => getenv("EMAIL"), "username" => "benchuser",
        "password" => getenv("PASS"), "email_verified_at" => now(), "is_active" => true,
    ]);
    $u->save();
}
if (! $u->hasRole(App\Support\SystemRole::SUPERADMIN)) {
    $u->assignRole(App\Support\SystemRole::SUPERADMIN);
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
echo "user ok id={$u->id}\n";
' EMAIL="$EMAIL" PASS="$PASS" || exit 1

php artisan config:clear >/dev/null 2>&1
php artisan serve --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1 &
SRV=$!

for _ in $(seq 1 40); do
  curl -s -o /dev/null "${BASE}/login" && break
  sleep 0.25
done

# Log in once, keep the cookie.
TOKEN=$(curl -s -c "$JAR" "${BASE}/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "${BASE}/login" \
  -d "_token=${TOKEN}" -d "identifier=${EMAIL}" -d "password=${PASS}"

for r in "${ROUTES[@]}"; do
  code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "${BASE}${r}")
  [ "$code" = "200" ] || { echo "${r}: LOGIN FAILED (http ${code})" >&2; exit 1; }
done

echo "route,iterations,p50_ms,p95_ms"
for r in "${ROUTES[@]}"; do
  for _ in $(seq 1 5); do curl -s -b "$JAR" -o /dev/null "${BASE}${r}"; done
  times=()
  for _ in $(seq 1 "$N"); do
    times+=("$(curl -s -b "$JAR" -o /dev/null -w '%{time_total}' "${BASE}${r}")")
  done
  sorted=$(printf '%s\n' "${times[@]}" | sort -g)
  p50=$(printf '%s\n' "$sorted" | awk -v n="$N" 'NR==int((n+1)/2)')
  p95=$(printf '%s\n' "$sorted" | awk -v n="$N" 'NR==int(n*0.95+0.5)')
  echo "${r},${N},$(echo "$p50 * 1000" | bc | cut -c1-6),$(echo "$p95 * 1000" | bc | cut -c1-6)"
done
