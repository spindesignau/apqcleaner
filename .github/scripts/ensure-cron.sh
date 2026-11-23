#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH): makes sure the Laravel scheduler cron job exists, exactly once.
# Usage: bash -s -- <app_path> <php_binary>
# Without it, queued work never runs: invoices sit on "Sending…", no SMS reminders, no backups.
set -euo pipefail
APP_PATH="$1"
PHP_BIN="$2"
MARKER="# apq-scheduler"
LINE="* * * * * cd '$APP_PATH' && $PHP_BIN artisan schedule:run >> /dev/null 2>&1 $MARKER"

if ! command -v crontab >/dev/null 2>&1; then
  echo "::warning::crontab is not available over SSH. Add this cron job in cPanel → Cron Jobs (every minute): cd $APP_PATH && $PHP_BIN artisan schedule:run >> /dev/null 2>&1"
  exit 0
fi

current=$(crontab -l 2>/dev/null || true)
# Drop our marked line and any hand-added scheduler line for this same app, then add ours once.
cleaned=$(printf '%s\n' "$current" | awk -v app="$APP_PATH" -v marker="$MARKER" \
  'index($0, marker) { next } index($0, "schedule:run") && index($0, app) { next } { print }' | sed '/^$/d')

if printf '%s\n' "$current" | grep -qF "$LINE"; then
  echo "Scheduler cron job already in place."
else
  { [ -n "$cleaned" ] && printf '%s\n' "$cleaned"; printf '%s\n' "$LINE"; } | crontab -
  echo "Scheduler cron job installed: $LINE"
fi
