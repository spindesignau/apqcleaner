#!/usr/bin/env bash
# Runs on the GitHub runner. Sets up an SSH alias with a pinned host key.
# Usage: ssh-alias.sh <alias>   with env HOST PORT USER KEY PASSPHRASE KNOWN_HOSTS
set -euo pipefail
ALIAS="$1"
trim() { printf '%s' "$1" | tr -d '\r\n' | sed -E 's/^[[:space:]]+|[[:space:]]+$//g'; }
HOST=$(trim "${HOST:-}"); PORT=$(trim "${PORT:-22}"); USER_NAME=$(trim "${USER:-}")
[ -n "$HOST" ] && [ -n "$USER_NAME" ] || { echo "::error::SSH host/user secrets for '$ALIAS' are empty"; exit 1; }

install -m 700 -d ~/.ssh
printf '%s\n' "$KEY" | tr -d '\r' > ~/.ssh/key_"$ALIAS"
chmod 600 ~/.ssh/key_"$ALIAS"
if [ -n "${PASSPHRASE:-}" ]; then
  ssh-keygen -p -q -P "$PASSPHRASE" -N "" -f ~/.ssh/key_"$ALIAS" >/dev/null \
    || { echo "::error::Could not unlock the '$ALIAS' SSH key — check its passphrase secret"; exit 1; }
fi
printf '%s\n' "$KNOWN_HOSTS" | tr -d '\r' \
  | awk -v a="$ALIAS-server" '$0 !~ /^[[:space:]]*#/ && NF >= 3 && $2 ~ /^(ssh-|ecdsa-|sk-)/ { print a, $2, $3 }' >> ~/.ssh/known_hosts
grep -q "^$ALIAS-server " ~/.ssh/known_hosts || { echo "::error::No host keys in the '$ALIAS' KNOWN_HOSTS secret"; exit 1; }
cat >> ~/.ssh/config <<CFG
Host $ALIAS
  HostName $HOST
  Port $PORT
  User $USER_NAME
  IdentityFile ~/.ssh/key_$ALIAS
  IdentitiesOnly yes
  HostKeyAlias $ALIAS-server
  StrictHostKeyChecking yes
  ConnectTimeout 20
CFG
chmod 600 ~/.ssh/config
ssh "$ALIAS" true || { echo "::error::SSH to '$ALIAS' failed"; exit 1; }
echo "Connected to $ALIAS."
