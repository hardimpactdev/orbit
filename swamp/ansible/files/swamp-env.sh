#!/bin/bash
# Runs one command with the Swamp Club credential env of the account in $HOME. On the Swamp Node a `swamp` call from a
# non-interactive shell (systemd, SSH, a workflow step) fails with auth_gate_blocked unless ~/.config/swamp/env.sh is sourced first.
# The file holds a credential: it is sourced with all output discarded, and a failure names only its path and a fixed reason.
set -euo pipefail
credentials="${HOME:?}/.config/swamp/env.sh"
if [[ ! -f $credentials || ! -r $credentials ]]; then
  echo "swamp.credential_env_missing: $credentials is missing or unreadable; swamp would fail with auth_gate_blocked. Restore the Swamp Club credential env of $(id -un)." >&2
  exit 78
fi
set +x
# shellcheck disable=SC1090
if ! . "$credentials" >/dev/null 2>&1; then
  echo "swamp.credential_env_invalid: $credentials could not be sourced; swamp would fail with auth_gate_blocked." >&2
  exit 78
fi
set -euo pipefail +x
exec "$@"
