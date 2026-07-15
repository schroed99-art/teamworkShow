#!/usr/bin/env bash
#
# Build the SIGNED release APK and publish it to the server so devices can
# self-update in-app (see server/php/app_update.php + UpdateManager.kt).
#
# Run this AFTER scripts/deploy.sh has bumped VERSION + stamped version.php,
# so the published versionCode is newer than what's installed on the devices.
#
# Requires keystore.properties (git-ignored) to exist for release signing.
#
set -euo pipefail
cd "$(dirname "$0")/.."   # repo root

# ---- config (matches scripts/deploy.sh; override via scripts/deploy.env) ----
DEPLOY_HOST="192.168.178.207"
DEPLOY_USER="root"
DEPLOY_PATH="/var/www/html/teamworkshow"
DEPLOY_KEY="$HOME/.ssh/teamworkshow_deploy"
DEPLOY_PORT="22"
DEPLOY_PRIVATE_DIR="/var/www/teamworkshow-apk"   # APK dir, outside the web root
DEPLOY_CHOWN="www-data:www-data"                 # empty on hosts without root
if [ -f scripts/deploy.env ]; then
  # shellcheck disable=SC1091
  . scripts/deploy.env
fi
JAVA_HOME_DEFAULT="/Applications/Android Studio.app/Contents/jbr/Contents/Home"

if [ ! -f keystore.properties ]; then
  echo "!! keystore.properties fehlt — Release kann nicht signiert werden." >&2
  exit 1
fi

VERSION="$(tr -d '[:space:]' < VERSION)"
IFS='.' read -r MA MI PA <<< "$VERSION"
VCODE=$(( ${MA:-0} * 10000 + ${MI:-0} * 100 + ${PA:-0} ))
echo ">> Publishing v$VERSION (versionCode $VCODE)"

# ---- build signed release APK ----
export JAVA_HOME="${JAVA_HOME:-$JAVA_HOME_DEFAULT}"
echo ">> Building signed release APK…"
./gradlew :app:assembleRelease --console=plain -q

APK="app/build/outputs/apk/release/app-release.apk"
[ -f "$APK" ] || { echo "!! APK nicht gefunden: $APK" >&2; exit 1; }

SHA="$(shasum -a 256 "$APK" | awk '{print $1}')"
SIZE="$(stat -f%z "$APK")"

META="$(mktemp -t app_update).json"
cat > "$META" <<JSON
{"versionCode":$VCODE,"versionName":"$VERSION","apk":"app-release.apk","size":$SIZE,"sha256":"$SHA"}
JSON

# ---- publish to the VM ----
# The APK goes to a PRIVATE dir outside the web root; it is served only through
# apk.php (dashboard session or valid device pairing code). Only the metadata
# (app_update.json) stays public so the app can check for updates.
PRIVATE_DIR="$DEPLOY_PRIVATE_DIR"
SSH="ssh -i $DEPLOY_KEY -p $DEPLOY_PORT -o BatchMode=yes"
SCP="scp -i $DEPLOY_KEY -P $DEPLOY_PORT -o BatchMode=yes"
echo ">> Uploading APK ($SIZE bytes) to $DEPLOY_USER@$DEPLOY_HOST:$PRIVATE_DIR + metadata to web root…"
$SSH "$DEPLOY_USER@$DEPLOY_HOST" "mkdir -p $PRIVATE_DIR"
$SCP "$APK" "$DEPLOY_USER@$DEPLOY_HOST:$PRIVATE_DIR/app-release.apk"
$SCP "$META" "$DEPLOY_USER@$DEPLOY_HOST:$DEPLOY_PATH/app_update.json"
# chown only where we have root (DEPLOY_CHOWN empty on shared hosting).
CHOWN_CMD=true
[ -n "${DEPLOY_CHOWN:-}" ] && CHOWN_CMD="chown $DEPLOY_CHOWN $PRIVATE_DIR/app-release.apk $DEPLOY_PATH/app_update.json"
$SSH "$DEPLOY_USER@$DEPLOY_HOST" \
  "rm -f $DEPLOY_PATH/app-release.apk; $CHOWN_CMD; chmod 640 $PRIVATE_DIR/app-release.apk"
rm -f "$META"

echo ""
echo "==================================================="
echo "  Published Teamwork Show APK  v$VERSION  (code $VCODE)"
echo "  sha256: $SHA"
echo "  Devices pick it up on their next 60s sync."
echo "==================================================="
