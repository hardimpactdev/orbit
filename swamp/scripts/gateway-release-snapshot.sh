#!/bin/bash
# VACUUM INTO a private copy before any migration; keeps the newest two and the one a pause names. Argument: release id.
# Run by the step "Snapshot the database" of swamp/workflows/workflow-operation-gateway-release.yaml.
# With --check <database> <bytes> it only checks, before anything is written, that <bytes> fit above the floor as the disk is, and that <bytes>
# and a copy of <database> fit when the snapshots this script would remove first are gone. The step "Build the release" runs it from its commit.
# The floor is the one of Doctor's node.disk_low: 2 GiB and 10 % of the disk stay free. ORBIT_RELEASE_BASE moves the base for tests only.
set -euo pipefail
shopt -s nullglob
base=${ORBIT_RELEASE_BASE:-/home/orbit}
backups=$base/.orbit/backups
fail() { echo "$1: $2" >&2; exit 1; }
paused=$(jq -r '.snapshot // empty' "$base/.orbit/gateway-release.paused" 2>/dev/null || true)
# The snapshots past the newest $1, without the one a pause names and the one this run wrote.
# shellcheck disable=SC2012
older() { local files=("$backups"/pre-*.sqlite); [ ${#files[@]} -eq 0 ] || ls -1t -- "${files[@]}" | tail -n +$(($1 + 1)) | { grep -vxF -e "${paused:-/}" -e "${path:-/}" || true; }; }
# Prints the bytes free above the floor after <bytes> more are written: negative when the floor would break.
room() {
  local size avail floor
  read -r size avail < <(df --output=size,avail -B1 "$base" | tail -1)
  floor=$((size / 10 > 2147483648 ? size / 10 : 2147483648))
  echo $((avail - $1 - floor))
}
bytes() { local total=0 file; while IFS= read -r file; do total=$((total + $(stat -c %s "$file"))); done; echo "$total"; }

if [ "${1:-}" = --check ]; then
  need=$(($(stat -c %s "$2") + $3))
  [ "$(room "$3")" -ge 0 ] && [ $(($(room "$need") + $(older 1 | bytes))) -ge 0 ] \
    || fail gateway.release_disk_low "$base cannot hold $need bytes for the build and the database snapshot above the floor of 2 GiB and 10 % free"
  exit 0
fi

id=$1 app=$base/releases/$1/apps/gateway
umask 077 && install -d -m 0700 "$backups"
# Releases run one at a time, so a partial copy here is from a stopped run.
rm -f -- "$backups"/pre-*.sqlite.partial
# The PHP code is in single quotes on purpose.
# shellcheck disable=SC2016
database=$(php8.5 -r '$c = require $argv[1]; echo $c["database"]["connections"]["sqlite"]["database"];' "$app/bootstrap/cache/config.php")
need=$(stat -c %s "$database")
if [ "$(room "$need")" -lt 0 ]; then
  older 1 | xargs -r rm -f --
  [ "$(room "$need")" -ge 0 ] || fail gateway.release_disk_low "$base cannot hold a $need-byte database snapshot above the floor of 2 GiB and 10 % free"
fi
path="$backups/pre-$id-$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n').sqlite"
# shellcheck disable=SC2016
php8.5 -r '$db = new PDO("sqlite:".$argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $db->exec("VACUUM INTO ".$db->quote($argv[2]));' \
  "$database" "$path.partial" || { rm -f "$path.partial"; echo "gateway.release_snapshot_failed: VACUUM INTO failed" >&2; exit 1; }
sync "$path.partial" && mv "$path.partial" "$path"
older 2 | xargs -r rm -f --
printf '%s\n' "$path"
