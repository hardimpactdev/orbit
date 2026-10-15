#!/usr/bin/env bash
# Host-local export and transfer: no remote Incus trust or host credential enters the group VM.
set -euo pipefail
umask 077
[[ $# -eq 3 && $1 =~ ^[a-z0-9][a-z0-9-]*$ && $2 =~ ^tvm-[1-9][0-9]*$ ]] || exit 64
project=$1 vm=$2 manifest=$3
incus=(incus --project "$project")
guest=/var/lib/orbit/task-vm-role-images
work=$(mktemp -d /var/tmp/orbit-role-images.XXXXXXXX)
trap 'rm -rf -- "$work"' EXIT
printf '%s\n' "$manifest" >"$work/manifest.json"
"${incus[@]}" exec "$vm" -- install -d -o 1000 -g 1000 -m 0700 "$guest"
# Publish the manifest only after every archive is present. Retries replace only these exact private files.
for role in gateway app-dev app-prod operator; do
    fingerprint=$(python3 -c 'import json,sys; print(json.load(sys.stdin)["images"].get(sys.argv[1], {}).get("fingerprint", ""))' "$role" <"$work/manifest.json")
    [[ -n $fingerprint ]] || continue
    [[ $fingerprint =~ ^[a-f0-9]{64}$ ]] || exit 65
    "${incus[@]}" image export "$fingerprint" "$work/$role"
    [[ -f $work/$role.tar ]] || exit 66
    "${incus[@]}" file push "$work/$role.tar" "$vm$guest/$role.tar" --mode=0644
    rm -- "$work/$role.tar"
done
"${incus[@]}" file push "$work/manifest.json" "$vm$guest/manifest.json.new" --mode=0644
"${incus[@]}" exec "$vm" -- mv -f "$guest/manifest.json.new" "$guest/manifest.json"
