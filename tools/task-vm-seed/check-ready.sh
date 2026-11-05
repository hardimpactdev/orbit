#!/usr/bin/env bash
# Checks the ready copies of a seed refresh before the seed is promoted (docs/cli/tasks/vm-seed-refresh.mmd). Run as root, in
# the builder, after ready-state.sh and before the builder stops.
#
# It never starts a ready copy. For each role it starts a throwaway copy of `fleet-ready-<role>` and requires two things:
# 1. The copy boots and its Incus agent answers, so the ready copy is a VM that starts.
# 2. The Swamp Club key of the build is nowhere in it: not at the path the fleet build pushes it to, and not by value anywhere
#    in /home, /root, /etc or /opt. The seed is cloned into every task VM of the host, so a key in it would reach all of them.
# The step that makes the copies deletes the key from the Gateway and scans for it; this is the seed's own check on the copies it
# will hand to every task VM. The key is also searched for in the volume `ready-source`. A ready copy stays unbooted.
# Everything but the last line goes to standard error. The last line is the JSON list of the roles that passed.
set -euo pipefail
exec 3>&1 1>&2

readonly key_file=/home/orbit/.config/swamp/club-api-key
readonly key_path=/home/orbit/.config/swamp/club-api-key
readonly source_copy=/var/lib/incus/storage-pools/topology-seed/custom/default_ready-source
roles=(gateway app-dev app-prod)

[[ -s $key_file ]] || {
    echo "check-ready: the build has no club key file to search for" >&2
    exit 1
}
# The key as one line without its newline, as `grep -F -f -` wants a pattern.
key=$(tr -d '\n' <"$key_file")

passed=()
for role in "${roles[@]}"; do
    check="check-$role"
    if incus info "$check" >/dev/null 2>&1; then incus delete --force "$check"; fi
    incus copy "fleet-ready-$role" "$check"
    incus start "$check"
    # shellcheck disable=SC2016
    timeout 180 bash -c 'until incus exec "$1" -- true 2>/dev/null; do sleep 2; done' bash "$check" || {
        echo "check-ready: the copy of fleet-ready-$role did not boot in 180 s" >&2
        exit 1
    }
    if incus exec "$check" -- test -e "$key_path"; then
        echo "check-ready: fleet-ready-$role holds $key_path" >&2
        exit 1
    fi
    if printf '%s' "$key" | incus exec "$check" -- grep -rIlF -f - /home /root /etc /opt 2>/dev/null; then
        echo "check-ready: fleet-ready-$role holds the club key" >&2
        exit 1
    fi
    incus delete --force "$check"
    passed+=("$role")
done

if [[ -d $source_copy ]] && printf '%s' "$key" | grep -rIlF -f - "$source_copy"; then
    echo "check-ready: the volume ready-source holds the club key" >&2
    exit 1
fi

printf '%s\n' "$(printf '"%s",' "${passed[@]}" | sed 's/,$//; s/^/[/; s/$/]/')" >&3
