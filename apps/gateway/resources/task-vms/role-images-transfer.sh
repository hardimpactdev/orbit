#!/usr/bin/env bash
# Puts a task VM's claimed recipe base image into the VM, as root on its Incus host. Host-local export and transfer: no
# remote Incus trust or host credential enters the group VM.
#
#   role-images-transfer.sh check PROJECT VM MANIFEST     exit 0 when the VM holds MANIFEST, 3 when it does not
#   role-images-transfer.sh transfer PROJECT VM MANIFEST  transfer the base image unless the VM holds MANIFEST, then
#                                                         prove that it does
#
# The VM holds MANIFEST when its manifest exists, is not empty, parses in the VM, and names the same recipe and base
# fingerprint. The harness removes the archive once it imports it, so only the manifest proves a transfer.
#
# The Gateway passes this script as the argument of `bash -c`, never on stdin: `incus exec` forwards its stdin into the
# guest, so the first one would take the rest of a script on stdin, and Bash would exit 0 after it. Stdin is /dev/null
# and every `incus exec` runs with `-n`, so no child can wait on input either.
set -euo pipefail
exec </dev/null
umask 077
[[ $# -eq 4 && $1 =~ ^(check|transfer)$ && $2 =~ ^[a-z0-9][a-z0-9-]*$ && $3 =~ ^tvm-[1-9][0-9]*$ ]] || exit 64
mode=$1 project=$2 vm=$3 manifest=$4
incus=(incus --project "$project")
guest=/var/lib/orbit/task-vm-role-images
fields=$(python3 -c 'import json,sys; m=json.loads(sys.argv[1]); print(m["recipe_identity"], m["images"]["base"]["fingerprint"])' "$manifest") || exit 65
read -r identity fingerprint <<<"$fields"
[[ $identity =~ ^sha256:[a-f0-9]{64}$ && $fingerprint =~ ^[a-f0-9]{64}$ ]] || exit 65

# Exits 0 when the VM holds the manifest, 3 when it does not, and with the failure of `incus exec` when it cannot tell.
holds() {
    "${incus[@]}" exec -n "$vm" -- python3 -c '
import json, sys
try:
    with open(sys.argv[1]) as file:
        manifest = json.load(file)
    held = manifest["recipe_identity"] == sys.argv[2] and manifest["images"]["base"]["fingerprint"] == sys.argv[3]
except (OSError, ValueError, KeyError, TypeError):
    held = False
sys.exit(0 if held else 3)
' "$guest/manifest.json" "$identity" "$fingerprint"
}

status=0
holds || status=$?
if [[ $mode == check || $status -eq 0 ]]; then
    exit "$status"
fi

work=$(mktemp -d /var/tmp/orbit-role-images.XXXXXXXX)
trap 'rm -rf -- "$work"' EXIT
printf '%s\n' "$manifest" >"$work/manifest.json"
"${incus[@]}" exec -n "$vm" -- install -d -o 1000 -g 1000 -m 0700 "$guest"
"${incus[@]}" image export "$fingerprint" "$work/base"
[[ -f $work/base.tar ]] || exit 66
# Publish the manifest only after the base archive is present. Retries replace only these exact private files.
"${incus[@]}" file push "$work/base.tar" "$vm$guest/base.tar" --mode=0644
"${incus[@]}" file push "$work/manifest.json" "$vm$guest/manifest.json.new" --mode=0644
"${incus[@]}" exec -n "$vm" -- mv -f "$guest/manifest.json.new" "$guest/manifest.json"
holds || exit 67
