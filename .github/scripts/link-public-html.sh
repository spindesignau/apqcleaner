#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by deploy-production.yml).
# cPanel won't let a reseller change the MAIN domain's document root (always ~/public_html), so we make
# ~/public_html a symlink to the app's public/ folder. Safe: the old folder is MOVED aside, never deleted,
# and only if it holds nothing but cPanel's defaults.
# Usage: bash -s -- <app_path>
set -euo pipefail
APP_PATH="${1:?app path required}"
WEB="$HOME/public_html"
TARGET="$APP_PATH/public"
BEGIN='# php -- BEGIN cPanel-generated handler, do not edit'
END='# php -- END cPanel-generated handler, do not edit'

[ -d "$TARGET" ] || { echo "::error::$TARGET does not exist (deploy the app first)"; exit 1; }

if [ -L "$WEB" ]; then
  if [ "$(readlink -f "$WEB")" = "$(readlink -f "$TARGET")" ]; then
    echo "public_html already points to the app."; exit 0
  fi
  echo "::warning::public_html is a link to $(readlink "$WEB"), not to the app. Left unchanged."; exit 0
fi

if [ -d "$WEB" ]; then
  extra=$(cd "$WEB" && ls -A | grep -vxE 'cgi-bin|\.well-known|\.htaccess|index\.html|default\.html|favicon\.ico|php\.ini|\.user\.ini|error_log' || true)
  if [ -n "$extra" ]; then
    echo "::warning::public_html contains files that aren't cPanel defaults, so it was NOT switched to the app: $(echo "$extra" | tr '\n' ' ')— move them out of ~/public_html and re-run the deploy."
    exit 0
  fi
  # Keep cPanel's PHP version setting (MultiPHP) and any pending AutoSSL check.
  if [ -f "$WEB/.htaccess" ] && grep -qF "$BEGIN" "$WEB/.htaccess" && ! grep -qF "$BEGIN" "$TARGET/.htaccess"; then
    { echo; sed -n "\|$BEGIN|,\|$END|p" "$WEB/.htaccess"; } >> "$TARGET/.htaccess"
    echo "Carried over cPanel's PHP version setting."
  fi
  if [ -d "$WEB/.well-known" ] && [ ! -e "$TARGET/.well-known" ]; then cp -a "$WEB/.well-known" "$TARGET/.well-known"; fi
  backup="$WEB.before-apq-$(date +%Y%m%d%H%M%S)"
  mv "$WEB" "$backup"
  echo "Moved the old public_html to $backup"
fi

ln -s "$TARGET" "$WEB"
echo "public_html now points to $TARGET"
