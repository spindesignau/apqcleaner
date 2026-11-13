#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by deploy-staging.yml). No rsync needed: only bash, tar, gzip.
# Usage: bash -s -- /home/CPANELUSER/apqcleaner   (release archive already uploaded to .deploy/release.tar.gz)
#
# Replaces the app's code with the new release while keeping server-owned files:
#   .env, storage/, .deploy/, public/.well-known (AutoSSL)
set -euo pipefail

APP_PATH="${1:?app path required}"
cd "$APP_PATH"
[ -f .deploy/release.tar.gz ] || { echo "release archive missing" >&2; exit 1; }

rm -rf .deploy/new
mkdir -p .deploy/new
tar -xzf .deploy/release.tar.gz -C .deploy/new

# Never ship or delete these.
for protected in .env storage .deploy; do
  if [ -e ".deploy/new/$protected" ]; then echo "release must not contain $protected" >&2; exit 1; fi
done

# Keep the AutoSSL challenge folder if cPanel created one in the document root.
if [ -d public/.well-known ]; then rm -rf .deploy/well-known.keep; mv public/.well-known .deploy/well-known.keep; fi

# Remove top-level items shipped by the previous release that the new one no longer has.
if [ -f .deploy/manifest ]; then
  while IFS= read -r item; do
    [ -n "$item" ] || continue
    case "$item" in .env|storage|.deploy) continue ;; esac
    [ -e ".deploy/new/$item" ] || rm -rf -- "./$item"
  done < .deploy/manifest
fi

# Swap in each top-level item of the new release.
( cd .deploy/new && ls -A ) > .deploy/manifest.new
while IFS= read -r item; do
  [ -n "$item" ] || continue
  rm -rf -- "./$item"
  mv -- ".deploy/new/$item" "./$item"
done < .deploy/manifest.new
mv .deploy/manifest.new .deploy/manifest

if [ -d .deploy/well-known.keep ]; then mv .deploy/well-known.keep public/.well-known; fi
rm -rf .deploy/new .deploy/release.tar.gz
echo "Release installed: $(wc -l < .deploy/manifest) top-level items"
