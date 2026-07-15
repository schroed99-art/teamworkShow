#!/usr/bin/env bash
#
# Integration gate for the multi-tenant backend.
# curl smoke-tests against the live VM. Exits non-zero on the first failure.
# Extended step-by-step by the backend-mandanten runbook.
#
# Usage: bash server/tests/integration.sh [BASE_URL]
#
# Credentials come from the environment, never from the repo:
#   TW_ADMIN_TOKEN     -> X-Admin-Token header (= admin_password in the VM config.php).
#                         Without it the whole CRUD + device half of the gate is skipped.
#   TW_ADMIN_EMAIL     -> a dashboard user (users table) ...
#   TW_ADMIN_PASSWORD  -> ... and their password. Both needed for the session-login checks.
#                         These are a *different* secret from TW_ADMIN_TOKEN: the token is
#                         checked against the config, the login against users.pass_hash.
#
# The gate provisions its own tenant/presentation/device and deletes them again, so it
# never depends on seed data that someone may have cleaned out of the live DB.
set -uo pipefail

BASE="${1:-http://192.168.178.207/teamworkshow}"
FAILED=0

pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILED=1; }
skip() { printf '  \033[33mSKIP\033[0m %s\n' "$1"; }

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

# --- Step 0/baseline (no credentials needed) ---
check_get "version.php returns version" "$BASE/version.php" 200 '"version"'
check_get "playlist.php (folder fallback) returns items" "$BASE/playlist.php" 200 '"items"'
# media.php: serves an existing seed file, rejects path traversal
media_ok="$(curl -s -m 15 -o /dev/null -w '%{http_code}' "$BASE/media.php?name=poster_2.png")"
[ "$media_ok" = "200" ] && pass "media.php serves seed file" || fail "media.php serve (got $media_ok)"
media_bad="$(curl -s -m 15 -o /dev/null -w '%{http_code}' "$BASE/media.php?name=../config.php")"
[ "$media_bad" = "400" ] && pass "media.php rejects path traversal (400)" || fail "media.php traversal (got $media_bad)"

# An unknown pairing code must 404 rather than fall back to some other playlist.
check_get "playlist?device=BOGUS is 404" "$BASE/playlist.php?device=BOGUS_XYZ" 404 'unknown_device'

# --- Step 3: admin endpoints are guarded ---
check_get "tenants.php without token is 401" "$BASE/tenants.php" 401 'unauthorized'

# --- Step 6: dashboard login form + session guard ---
check_get "login.php renders form" "$BASE/login.php" 200 'Anmelden'
# admin.php without a session redirects to login (302)
adm_status="$(curl -s -m 15 -o /dev/null -w '%{http_code}' "$BASE/admin.php")"
[ "$adm_status" = "302" ] && pass "admin.php redirects when unauthenticated" || fail "admin.php redirect (got $adm_status)"
# A real e-mail with the wrong password must be rejected. Falling back to a bogus address
# would only prove that an unknown user is refused, not that the password is verified.
wrong_email="${TW_ADMIN_EMAIL:-nobody@example.invalid}"
wrong_status="$(curl -s -m 15 -o /dev/null -w '%{http_code}' \
  -d "email=$wrong_email" -d 'password=definitely-wrong' "$BASE/login.php")"
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

# The gate's own throwaway tenant. Deleting it cascades to presentation/device/slides/widgets.
TID=""
cleanup() {
  if [ -n "$TID" ]; then
    areq DELETE "$BASE/tenants.php?id=$TID" >/dev/null
    TID=""
  fi
}
# Without this, an abort mid-run (failure, Ctrl-C) would leak a GATE tenant into the live DB.
trap cleanup EXIT INT TERM

if [ -n "${TW_ADMIN_TOKEN:-}" ]; then
  PAIR="GATE-$$"   # unique per run, so concurrent gates cannot collide

  # --- Step 3: admin CRUD ---
  out="$(areq POST "$BASE/tenants.php" '{"name":"GATE Test Tenant"}')"
  TID="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$TID" ] && pass "create tenant (id=$TID)" || fail "create tenant"

  out="$(areq POST "$BASE/presentations.php" "{\"tenant_id\":$TID,\"name\":\"GATE Show\"}")"
  pid="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$pid" ] && pass "create presentation (id=$pid)" || fail "create presentation"

  # set ordered slides with durations
  areq PUT "$BASE/presentations.php" "{\"id\":$pid,\"slides\":[{\"media_name\":\"poster_2.png\",\"duration_ms\":5000},{\"media_name\":\"Show_Splashscreen_v3.png\",\"duration_ms\":12000}]}" >/dev/null
  out="$(areq GET "$BASE/presentations.php?id=$pid")"
  printf '%s' "${out%$'\n'*}" | grep -q '"duration_ms":5000' && pass "slide order+duration persisted" || fail "slide order+duration persisted"

  # create the gate's device under that tenant, with a known pairing code
  out="$(areq POST "$BASE/devices.php" \
    "{\"tenant_id\":$TID,\"name\":\"GATE Dev\",\"presentation_id\":$pid,\"pairing_code\":\"$PAIR\",\"display_format\":\"landscape\"}")"
  did="$(printf '%s' "${out%$'\n'*}" | grep -o '"id":[0-9]*' | head -1 | grep -o '[0-9]*')"
  [ -n "$did" ] && pass "create device (id=$did, pairing=$PAIR)" || fail "create device"

  # widgets upsert
  out="$(areq PUT "$BASE/widgets.php" "{\"device_id\":$did,\"weather_enabled\":true,\"weather_location\":\"Hamburg,DE\"}")"
  printf '%s' "${out%$'\n'*}" | grep -q 'Hamburg,DE' && pass "widgets upsert" || fail "widgets upsert"

  # --- Step 2: device-specific playlist (against our own device) ---
  check_get "playlist?device=$PAIR has duration_ms" "$BASE/playlist.php?device=$PAIR" 200 '"duration_ms"'
  check_get "playlist?device=$PAIR has widgets"     "$BASE/playlist.php?device=$PAIR" 200 '"widgets"'
  check_get "playlist?device=$PAIR has tenant"      "$BASE/playlist.php?device=$PAIR" 200 '"tenant"'

  # --- Phase 5.1: display_format is exposed and validated ---
  # The device was created as 'landscape', so that is what the app must be told.
  check_get "playlist exposes device.display_format" "$BASE/playlist.php?device=$PAIR" 200 '"display_format":"landscape"'
  # An invalid format must not reach the app: devices.php clamps it to portrait.
  areq PUT "$BASE/devices.php" "{\"id\":$did,\"display_format\":\"bogus_xyz\"}" >/dev/null
  check_get "invalid display_format falls back to portrait" "$BASE/playlist.php?device=$PAIR" 200 '"display_format":"portrait"'

  # --- Phase 5.3 Vollausbau: free-form custom zone tree round-trips ---
  # The device is 'portrait' now. Store a two-zone rows split (customer + our own
  # presentation) exactly as the dashboard editor emits it, then prove devices.php
  # accepts it and playlist.php resolves it for this format. A leaf bound to the
  # gate presentation must carry that presentation's slides.
  ZL="{\"v\":1,\"layouts\":{\"portrait\":{\"axis\":\"rows\",\"children\":[{\"size\":50,\"node\":{\"zone\":{\"source\":\"customer\"}}},{\"size\":50,\"node\":{\"zone\":{\"source\":$pid}}}]}}}"
  areq PUT "$BASE/devices.php" "{\"id\":$did,\"zone_mode\":\"custom\",\"zone_layout\":$ZL}" >/dev/null
  cout="$(curl -s -m 15 "$BASE/playlist.php?device=$PAIR")"
  printf '%s' "$cout" | grep -q '"mode":"custom"' && pass "custom zone_mode resolves in playlist" || fail "custom zone_mode resolves"
  printf '%s' "$cout" | grep -q '"tree"'          && pass "custom playlist carries resolved tree" || fail "custom playlist tree"
  printf '%s' "$cout" | grep -q 'poster_2.png'    && pass "custom leaf resolves presentation slides" || fail "custom leaf slides"
  # An invalid tree (leaf pointing at a non-existent presentation) must be rejected 422.
  bad_status="$(areq PUT "$BASE/devices.php" "{\"id\":$did,\"zone_mode\":\"custom\",\"zone_layout\":{\"v\":1,\"layouts\":{\"portrait\":{\"zone\":{\"source\":999999}}}}}")"
  printf '%s' "${bad_status##*$'\n'}" | grep -q '422' && pass "custom layout with unknown presentation is 422" || fail "custom layout bad-source rejected"
  # Restore single so the later notice/cleanup checks run against a clean contract.
  areq PUT "$BASE/devices.php" "{\"id\":$did,\"zone_mode\":\"single\"}" >/dev/null

  # --- Step 4: weather (stub without API key) ---
  check_get "weather?device=$PAIR responds (stub/live)" "$BASE/weather.php?device=$PAIR" 200 '"stub"'

  # --- Step 5: manual notices mirrored into the device playlist ---
  MARK="GATE_NOTICE_$$"
  areq PUT "$BASE/widgets.php" "{\"device_id\":$did,\"notices_enabled\":true,\"notices_text\":\"$MARK\"}" >/dev/null
  out="$(curl -s -m 15 "$BASE/playlist.php?device=$PAIR")"
  printf '%s' "$out" | grep -q "$MARK" && pass "notice mirrored into playlist" || fail "notice mirrored into playlist"
  printf '%s' "$out" | grep -q '"notices_enabled":true' && pass "notices_enabled reflected" || fail "notices_enabled reflected"

  # --- cleanup: delete tenant (cascades presentation/device/slides/widgets) ---
  out="$(areq DELETE "$BASE/tenants.php?id=$TID")"
  printf '%s' "${out##*$'\n'}" | grep -q '200' && pass "delete tenant (cascade cleanup)" || fail "delete tenant"
  TID=""   # deleted; nothing left for the trap to do
  # Prove the cascade really removed the device instead of orphaning it.
  check_get "gate device is gone after cleanup" "$BASE/playlist.php?device=$PAIR" 404 'unknown_device'
else
  skip "admin CRUD + device checks (set TW_ADMIN_TOKEN to enable)"
fi

# --- Step 6: full login session -> admin.php reachable ---
# Session login is e-mail + password against the users table, which is a different
# secret from TW_ADMIN_TOKEN — hence its own env vars.
if [ -n "${TW_ADMIN_EMAIL:-}" ] && [ -n "${TW_ADMIN_PASSWORD:-}" ]; then
  JAR="$(mktemp)"
  login_status="$(curl -s -m 15 -c "$JAR" -o /dev/null -w '%{http_code}' \
    -d "email=$TW_ADMIN_EMAIL" -d "password=$TW_ADMIN_PASSWORD" "$BASE/login.php")"
  [ "$login_status" = "302" ] && pass "login accepts correct password (302)" || fail "login correct-password (got $login_status)"
  admin_ok="$(curl -s -m 15 -b "$JAR" -o /dev/null -w '%{http_code}' "$BASE/admin.php")"
  [ "$admin_ok" = "200" ] && pass "admin.php reachable with session (200)" || fail "admin.php with session (got $admin_ok)"
  rm -f "$JAR"
else
  skip "session login (set TW_ADMIN_EMAIL + TW_ADMIN_PASSWORD to enable)"
fi

echo ""
if [ "$FAILED" -eq 0 ]; then
  echo "GATE: GREEN"; exit 0
else
  echo "GATE: RED"; exit 1
fi
