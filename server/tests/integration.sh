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

# --- Step 2: device-specific playlist ---
check_get "playlist?device=DEMO-01 has duration_ms" "$BASE/playlist.php?device=DEMO-01" 200 '"duration_ms"'
check_get "playlist?device=DEMO-01 has widgets"     "$BASE/playlist.php?device=DEMO-01" 200 '"widgets"'
check_get "playlist?device=DEMO-01 has tenant"      "$BASE/playlist.php?device=DEMO-01" 200 '"tenant"'
check_get "playlist?device=BOGUS is 404"            "$BASE/playlist.php?device=BOGUS_XYZ" 404 'unknown_device'

# --- Step 3: admin CRUD ---
check_get "tenants.php without token is 401" "$BASE/tenants.php" 401 'unauthorized'

# --- Step 4: weather (stub without API key) ---
check_get "weather?device=DEMO-01 responds (stub/live)" "$BASE/weather.php?device=DEMO-01" 200 '"stub"'

# --- Step 6: dashboard login + session guard ---
check_get "login.php renders form" "$BASE/login.php" 200 'Anmelden'
# admin.php without a session redirects to login (302)
adm_status="$(curl -s -m 15 -o /dev/null -w '%{http_code}' "$BASE/admin.php")"
[ "$adm_status" = "302" ] && pass "admin.php redirects when unauthenticated" || fail "admin.php redirect (got $adm_status)"
# wrong password is rejected (401)
wrong_status="$(curl -s -m 15 -o /dev/null -w '%{http_code}' -d 'password=definitely-wrong' "$BASE/login.php")"
[ "$wrong_status" = "401" ] && pass "login rejects wrong password (401)" || fail "login wrong-password (got $wrong_status)"

# Authed request: METHOD URL [json-body]. Echoes "<status>\n<body>".
areq() {
  local method="$1" url="$2" body="${3:-}"
  if [ -n "$body" ]; then
    curl -s -m 15 -X "$method" -H "X-Admin-Token: $TW_ADMIN_TOKEN" -H 'Content-Type: application/json' \
      -d "$body" -w $'\n%{http_code}' "$url" 2>/dev/null
  else
    curl -s -m 15 -X "$method" -H "X-Admin-Token: $TW_ADMIN_TOKEN" -w $'\n%{http_code}' "$url" 2>/dev/null
  fi
}

if [ -n "${TW_ADMIN_TOKEN:-}" ]; then
  # create tenant
  out="$(areq POST "$BASE/tenants.php" '{"name":"GATE Test Tenant"}')"
  tid="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$tid" ] && pass "create tenant (id=$tid)" || fail "create tenant"

  # create presentation
  out="$(areq POST "$BASE/presentations.php" "{\"tenant_id\":$tid,\"name\":\"GATE Show\"}")"
  pid="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$pid" ] && pass "create presentation (id=$pid)" || fail "create presentation"

  # set ordered slides with durations
  areq PUT "$BASE/presentations.php" "{\"id\":$pid,\"slides\":[{\"media_name\":\"poster_2.png\",\"duration_ms\":5000},{\"media_name\":\"Show_Splashscreen_v3.png\",\"duration_ms\":12000}]}" >/dev/null
  out="$(areq GET "$BASE/presentations.php?id=$pid")"
  printf '%s' "${out%$'\n'*}" | grep -q '"duration_ms":5000' && pass "slide order+duration persisted" || fail "slide order+duration persisted"

  # create device under tenant, assign presentation
  out="$(areq POST "$BASE/devices.php" "{\"tenant_id\":$tid,\"name\":\"GATE Dev\",\"presentation_id\":$pid}")"
  did="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$did" ] && pass "create device (id=$did)" || fail "create device"

  # widgets upsert
  out="$(areq PUT "$BASE/widgets.php" "{\"device_id\":$did,\"weather_enabled\":true,\"weather_location\":\"Hamburg,DE\"}")"
  printf '%s' "${out%$'\n'*}" | grep -q 'Hamburg,DE' && pass "widgets upsert" || fail "widgets upsert"

  # cleanup: delete tenant (cascades presentation/device/slides/widgets)
  out="$(areq DELETE "$BASE/tenants.php?id=$tid")"
  printf '%s' "${out##*$'\n'}" | grep -q '200' && pass "delete tenant (cascade cleanup)" || fail "delete tenant"

  # --- Step 5: manual notices mirrored into the device playlist ---
  # Seed device DEMO-01 is device_id=1 (first seeded device).
  SEED_DID=1
  MARK="GATE_NOTICE_$$"
  areq PUT "$BASE/widgets.php" "{\"device_id\":$SEED_DID,\"notices_enabled\":true,\"notices_text\":\"$MARK\"}" >/dev/null
  out="$(curl -s -m 15 "$BASE/playlist.php?device=DEMO-01")"
  printf '%s' "$out" | grep -q "$MARK" && pass "notice mirrored into playlist" || fail "notice mirrored into playlist"
  printf '%s' "$out" | grep -q '"notices_enabled":true' && pass "notices_enabled reflected" || fail "notices_enabled reflected"
  # reset seed device notice
  areq PUT "$BASE/widgets.php" "{\"device_id\":$SEED_DID,\"notices_enabled\":false,\"notices_text\":\"\"}" >/dev/null

  # --- Step 6: full login session -> admin.php reachable ---
  JAR="$(mktemp)"
  login_status="$(curl -s -m 15 -c "$JAR" -o /dev/null -w '%{http_code}' -d "password=$TW_ADMIN_TOKEN" "$BASE/login.php")"
  [ "$login_status" = "302" ] && pass "login accepts correct password (302)" || fail "login correct-password (got $login_status)"
  admin_ok="$(curl -s -m 15 -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/admin.php")"
  [ "$admin_ok" = "200" ] && pass "admin.php reachable with session (200)" || fail "admin.php with session (got $admin_ok)"
  rm -f "$JAR"
else
  echo "  SKIP admin CRUD roundtrip (set TW_ADMIN_TOKEN to enable)"
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "GATE: GREEN"; exit 0
else
  echo "GATE: RED"; exit 1
fi
