#!/usr/bin/env bash
# PULL DEPLOY — runs ON THE SERVER from cron (for hosts whose firewall blocks SSH from GitHub).
#
# Every few minutes: ask GitHub for the latest release of the app. If it's new, download it, check its
# checksum, back up the database, install it, migrate, and check the site answers. If the site doesn't
# answer after the install, the previous code is put back.
#
# Settings come from the app's .env:
#   GITHUB_DEPLOY_REPO=owner/repo          GITHUB_DEPLOY_TOKEN="github_pat_…"  (read-only: Contents)
#   APQ_PHP_BIN=/usr/local/bin/php         (optional)
#
# Usage: bash pull-deploy.sh [--force]      (--force reinstalls the latest release even if it's current)
# Log:   storage/logs/deploy.log     Status: storage/app/deploy-status.json (shown in Admin → Settings)
set -uo pipefail

APP_PATH="${APP_PATH:-$HOME/apqcleaner}"
FORCE="${1:-}"
cd "$APP_PATH" || { echo "No app folder at $APP_PATH" >&2; exit 1; }
mkdir -p .deploy storage/logs storage/app
LOG="storage/logs/deploy.log"
ASSET="apq-release.tar.gz"

log() { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }
env_value() { grep -E "^$1=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '\r' | sed -E 's/^[[:space:]]*"?//; s/"?[[:space:]]*$//'; }
status() {   # status <ok|failed|installing> <release> <message>
  printf '{"state":"%s","release":"%s","message":"%s","at":"%s"}\n' "$1" "$2" "$(printf '%s' "$3" | tr '"\n' "' ")" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > storage/app/deploy-status.json
}

# One run at a time.
if command -v flock >/dev/null 2>&1; then
  exec 9>.deploy/pull.lock
  flock -n 9 || exit 0
else
  mkdir .deploy/pull.lockdir 2>/dev/null || exit 0
  trap 'rmdir .deploy/pull.lockdir' EXIT
fi

[ -f .env ] || { log "ERROR: $APP_PATH/.env is missing."; exit 1; }
REPO=$(env_value GITHUB_DEPLOY_REPO)
TOKEN=$(env_value GITHUB_DEPLOY_TOKEN)
PHP=$(env_value APQ_PHP_BIN); PHP="${PHP:-/usr/local/bin/php}"
APP_URL=$(env_value APP_URL)
[ -n "$REPO" ] || { log "ERROR: GITHUB_DEPLOY_REPO is missing from $APP_PATH/.env (add: GITHUB_DEPLOY_REPO=spindesignau/apqcleaner)"; exit 1; }
[ -n "$TOKEN" ] || { log "ERROR: GITHUB_DEPLOY_TOKEN is missing from $APP_PATH/.env"; exit 1; }
GITHUB_API="${GITHUB_API:-https://api.github.com}"   # overridable for testing
api() { curl -fsS --max-time 120 -H "Authorization: Bearer $TOKEN" -H "X-GitHub-Api-Version: 2022-11-28" "$@"; }

# This script's own cron job (every 5 minutes), installed once. The scheduler job is added after each install.
if command -v crontab >/dev/null 2>&1 && ! crontab -l 2>/dev/null | grep -qF '# apq-pull-deploy'; then
  { crontab -l 2>/dev/null; echo "*/5 * * * * APP_PATH='$APP_PATH' bash '$APP_PATH/.deploy/pull-deploy.sh' >/dev/null 2>&1 # apq-pull-deploy"; } | crontab - \
    && log "Installed the pull-deploy cron job (every 5 minutes)."
fi

# 1. What's the latest release?
if ! json=$(api -H "Accept: application/vnd.github+json" "$GITHUB_API/repos/$REPO/releases/latest" 2>&1); then
  log "Could not ask GitHub for the latest release: $json"
  status failed "$(cat .deploy/current-release 2>/dev/null)" "Could not reach GitHub (check GITHUB_DEPLOY_TOKEN)"
  exit 1
fi
# Parse with a small PHP file (no process substitution: CloudLinux has no /dev/fd, and cPanel's php wrapper
# can't take multi-line -r code).
cat > .deploy/helper.php <<'PHPCODE'
<?php
if (($argv[1] ?? '') === 'sha256') { echo hash_file('sha256', $argv[2]); exit; }
$r = json_decode((string) file_get_contents($argv[2]), true) ?: [];
$ids = [];
foreach ($r['assets'] ?? [] as $a) { $ids[$a['name']] = $a['id']; }
echo ($r['tag_name'] ?? '-'), ' ', ($ids[$argv[3]] ?? '-'), ' ', ($ids[$argv[3].'.sha256'] ?? '-');
PHPCODE
printf '%s' "$json" > .deploy/latest.json
set -- $("$PHP" .deploy/helper.php release .deploy/latest.json "$ASSET" 2>/dev/null)
TAG="${1:--}"; ASSET_ID="${2:--}"; SUM_ID="${3:--}"
CURRENT=$(cat .deploy/current-release 2>/dev/null || true)
if [ "$TAG" = "-" ] || [ "$ASSET_ID" = "-" ]; then log "Latest release has no $ASSET yet."; exit 0; fi
if [ "$TAG" = "$CURRENT" ] && [ "$FORCE" != "--force" ]; then exit 0; fi   # nothing new: stay quiet
if [ "$TAG" = "$(cat .deploy/failed-release 2>/dev/null)" ] && [ "$FORCE" != "--force" ]; then exit 0; fi   # failed before: wait for a newer release

log "Installing release $TAG (current: ${CURRENT:-none})"
status installing "$TAG" "Installing"

# 2. Download and verify.
api -L -H "Accept: application/octet-stream" -o .deploy/release.tar.gz "$GITHUB_API/repos/$REPO/releases/assets/$ASSET_ID" \
  || { log "ERROR: download failed"; status failed "$CURRENT" "Download of $TAG failed"; exit 1; }
if [ "$SUM_ID" != "-" ]; then
  expected=$(api -L -H "Accept: application/octet-stream" "$GITHUB_API/repos/$REPO/releases/assets/$SUM_ID" | awk '{print $1}')
  actual=$(sha256sum .deploy/release.tar.gz 2>/dev/null | awk '{print $1}')
  [ -n "$actual" ] || actual=$("$PHP" .deploy/helper.php sha256 .deploy/release.tar.gz)
  [ -n "$expected" ] && [ "$expected" = "$actual" ] \
    || { log "ERROR: checksum mismatch — not installing"; rm -f .deploy/release.tar.gz; status failed "$CURRENT" "Checksum mismatch for $TAG"; exit 1; }
fi
gzip -t .deploy/release.tar.gz || { log "ERROR: archive is damaged"; status failed "$CURRENT" "Damaged download"; exit 1; }

# 3. .env must be readable before anything changes (an unquoted value with spaces breaks every page).
if [ -f artisan ] && ! out=$("$PHP" artisan --version 2>&1); then
  log "ERROR: .env can't be read — fix it (put values with spaces in \"double quotes\"). Site not changed. $out"
  status failed "$CURRENT" ".env can't be read — site not changed"
  exit 1
fi

# 4. Safety copies: database + current code (for rollback).
if [ -f artisan ]; then
  "$PHP" artisan apq:backup >> "$LOG" 2>&1 || log "WARNING: database backup failed (continuing)"
  if [ -f .deploy/manifest ]; then
    tar -czf .deploy/previous.tar.gz -T .deploy/manifest 2>>"$LOG" || rm -f .deploy/previous.tar.gz
  fi
  "$PHP" artisan down --retry=30 --refresh=15 >> "$LOG" 2>&1 || true
fi

rollback() {
  log "FAILED: $1"; [ -n "$CURRENT" ] && log "Rolling back to $CURRENT"
  if [ -f .deploy/previous.tar.gz ]; then
    while IFS= read -r item; do [ -n "$item" ] && rm -rf -- "./$item"; done < .deploy/manifest
    tar -xzf .deploy/previous.tar.gz && "$PHP" artisan optimize:clear >/dev/null 2>&1; "$PHP" artisan optimize >/dev/null 2>&1
    ( tar -tzf .deploy/previous.tar.gz | sed -E 's#^\./##; s#/.*##' | sort -u | sed '/^$/d' ) > .deploy/manifest
  fi
  "$PHP" artisan up >/dev/null 2>&1 || true
  echo "$TAG" > .deploy/failed-release
  status failed "$CURRENT" "Release $TAG failed and was rolled back: $1"
  exit 1
}

# 5. Install with the helper scripts shipped inside this release (same installer as SSH deploys), then finish setup.
for f in install-release.sh link-public-html.sh ensure-cron.sh pull-deploy.sh; do
  tar -xzf .deploy/release.tar.gz -O "./deploy/$f" > ".deploy/$f.new" 2>/dev/null && [ -s ".deploy/$f.new" ] \
    && mv ".deploy/$f.new" ".deploy/$f" || rm -f ".deploy/$f.new"
done
chmod +x .deploy/*.sh
[ -f .deploy/install-release.sh ] || rollback "release has no deploy/install-release.sh"
bash .deploy/install-release.sh "$APP_PATH" >> "$LOG" 2>&1 || rollback "install step failed"

# Each step must succeed; the first failure stops the install and is named in the log and on the status page.
step() { log "→ artisan $*"; "$PHP" artisan "$@" >> "$LOG" 2>&1 || rollback "'artisan $1' failed — see storage/logs/deploy.log"; }
mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/import bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
if ! grep -qE '^APP_KEY=base64:.+' .env; then
  grep -q '^APP_KEY=' .env || echo 'APP_KEY=' >> .env
  step config:clear          # not optimize:clear: on a new database the cache table doesn't exist yet
  step key:generate --force
fi
step config:clear
step migrate --force
"$PHP" artisan storage:link >> "$LOG" 2>&1 || true
step optimize:clear
step optimize
step queue:restart

bash .deploy/link-public-html.sh "$APP_PATH" >> "$LOG" 2>&1 || log "WARNING: could not link public_html"
bash .deploy/ensure-cron.sh "$APP_PATH" "$PHP" >> "$LOG" 2>&1 || log "WARNING: could not install the scheduler cron job"
"$PHP" artisan up >> "$LOG" 2>&1 || true

# 6. Does the site answer? (Only roll back on a real error page — not when DNS isn't pointed yet.)
if [ -n "$APP_URL" ]; then
  code=$(curl -s -o /dev/null -m 30 -w '%{http_code}' "${APP_URL%/}/up" || true)
  case "$code" in
    200) log "Health check OK" ;;
    000) log "Health check skipped: ${APP_URL} not reachable from the server yet (DNS?)" ;;
    5*)  rollback "health check returned HTTP $code" ;;
    *)   log "Health check returned HTTP $code (not rolling back)" ;;
  esac
fi

echo "$TAG" > .deploy/current-release
rm -f .deploy/previous.tar.gz .deploy/failed-release
status ok "$TAG" "Installed"
log "Release $TAG installed."
