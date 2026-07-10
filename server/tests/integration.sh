#!/usr/bin/env bash
#
# Integration gate for the multi-tenant backend.
# curl smoke-tests against the live VM. Exits non-zero on the first failure.
# Extended step-by-step by the backend-mandanten runbook.
#
# Usage: bash server/tests/integration.sh [BASE_URL]
set -uo pipefail

BASE="${1:-http://192.168.178.207/teamworkshow}"
FAILED=0

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }

# GET url, expect HTTP status, and optionally require a substring in the body.
check_get() {
  local desc="$1" url="$2" want_status="$3" want_sub="${4:-}"
  local out status body
  out="$(curl -s -m 15 -w $'\n%{http_code}' "$url" 2>/dev/null)"
  status="${out##*$'\n'}"
  body="${out%$'\n'*}"
  if [ "$status" != "$want_status" ]; then
    fail "$desc (status $status, want $want_status)"; return
  fi
  if [ -n "$want_sub" ] && ! printf '%s' "$body" | grep -q "$want_sub"; then
    fail "$desc (missing '$want_sub' in body)"; return
  fi
  pass "$desc"
}

echo "== TeamworkShow backend gate =="
echo "base: $BASE"

# --- Step 0/baseline ---
check_get "version.php returns version" "$BASE/version.php" 200 '"version"'
check_get "playlist.php (folder fallback) returns items" "$BASE/playlist.php" 200 '"items"'

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "GATE: GREEN"; exit 0
else
  echo "GATE: RED"; exit 1
fi
