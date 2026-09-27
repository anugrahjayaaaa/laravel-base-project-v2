#!/bin/bash
# Fetch a page exactly as a browser would: real session cookie, real CSRF token,
# following the login redirect so the session cookie is actually kept.
set -u
B=http://127.0.0.1:8321
J=/tmp/cj.txt
OUT=${1:-/tmp/page.html}
PATHREQ=${2:-/}
USER=${3:-superadmin}
PASS=${4:-LocalTest#2026}

rm -f "$J"
CSRF=$(curl -s -c "$J" "$B/login" | grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')

# The login POST returns 302 and sets the session cookie in the jar. Following
# it with -L in the same call reuses the pre-login CSRF jar and loses the auth
# cookie, so follow it as a separate request.
curl -s -b "$J" -c "$J" -o /dev/null \
  --data-urlencode "_token=$CSRF" \
  --data-urlencode "identifier=$USER" \
  --data-urlencode "password=$PASS" \
  "$B/login"

curl -s -b "$J" -c "$J" -o "$OUT" -w "page_http=%{http_code}\n" "$B$PATHREQ"

if grep -q 'Redirecting to' "$OUT" 2>/dev/null; then
  echo "!! still redirected:"
  grep -o "url='[^']*'" "$OUT" | head -1
fi
echo "saved $OUT ($(wc -c < "$OUT") bytes)"
