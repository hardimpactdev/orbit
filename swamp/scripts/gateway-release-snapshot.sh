#!/bin/bash
# VACUUM INTO a private copy before any migration; keeps the newest three and the one a pause names. Argument: release id.
# Run by the step "Snapshot the database" of swamp/workflows/workflow-operation-gateway-release.yaml.
set -euo pipefail
id=$1 app=/home/orbit/releases/$1/apps/gateway backups=/home/orbit/.orbit/backups
umask 077 && install -d -m 0700 "$backups"
path="$backups/pre-$id-$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n').sqlite"
# The PHP code is in single quotes on purpose.
# shellcheck disable=SC2016
php8.5 -r '$c = require $argv[1]; $db = new PDO("sqlite:".$c["database"]["connections"]["sqlite"]["database"], null, null,
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $db->exec("VACUUM INTO ".$db->quote($argv[2]));' \
  "$app/bootstrap/cache/config.php" "$path.partial" || { rm -f "$path.partial"; echo "gateway.release_snapshot_failed: VACUUM INTO failed" >&2; exit 1; }
sync "$path.partial" && mv "$path.partial" "$path"
paused=$(jq -r '.snapshot // empty' /home/orbit/.orbit/gateway-release.paused 2>/dev/null || true)
# grep -v exits 1 when it passes no line, as with three snapshots or fewer; that is no failure.
# shellcheck disable=SC2012
ls -1t "$backups"/pre-*.sqlite | tail -n +4 | { grep -vxF -- "${paused:-/}" || true; } | xargs -r rm -f --
printf '%s\n' "$path"
