#!/usr/bin/env bash
# Prepares a task group's declared E2E topology nested in its task VM, as the managed user.
#
#   topology.sh start RUN CHECKOUT ISSUE NODES VPN_SUBNET   install this script and start the unit for RUN
#   topology.sh status RUN                                  print the state of RUN as one JSON line; a ready run from
#                                                           an earlier boot of the VM is `stale`
#   topology.sh run RUN CHECKOUT ISSUE NODES VPN_SUBNET     the unit's work: host setup, then acquire
#
# The Gateway runs it with `bash -c`, and sends it on stdin too to `start`, which installs it for the unit. `run`
# writes exactly one result file per run, so a unit that stops without one was killed. Every harness command runs from
# the workspace checkout.
set -euo pipefail

self="$HOME/.local/lib/orbit/task-topology"
state="$HOME/.local/state/orbit/task-topology"
unit_prefix=orbit-task-topology-
# A unit that runs longer than this is stopped by systemd and reports no result.
max_seconds=4500

valid_run() { [[ $1 =~ ^[a-z0-9]{8,40}$ ]]; }

# Keep unreadable evidence pending. Only a missing unit or positive terminal proof permits another start.
unit_running() {
    python3 - "$1" <<'PYTHON'
# ORBIT_TASK_UNIT_STATE
import sys

state = unit_state(sys.argv[1])
sys.exit(0 if state is None or (state.get('LoadState') != 'not-found'
         and empty_terminal(sys.argv[1], state=state) is None) else 1)
PYTHON
}

case ${1-} in
    start)
        [[ $# -eq 6 ]] && valid_run "$2" || exit 64
        [[ $3 == /* && -d $3 ]] || { echo "topology: the checkout $3 does not exist." >&2; exit 66; }
        [[ $4 =~ ^TASK-[1-9][0-9]{0,8}$ && $5 =~ ^[a-z0-9+,-]*$ && $6 =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$ ]] || exit 64
        install -d -m 0700 "$(dirname "$self")" "$state"
        cat >"$self.new"
        chmod 0700 "$self.new"
        mv -f -- "$self.new" "$self"
        # A repeated start of a run that already runs leaves it alone.
        if unit_running "$unit_prefix$2.service"; then
            exit 0
        fi
        # One preparation at a time: a unit of an earlier run, whose start the Gateway lost, stops first.
        units=$(systemctl list-units --all --plain --no-legend "$unit_prefix*" | awk '{print $1}')
        for unit in $units; do
            if unit_running "$unit"; then
                sudo -n systemctl stop -- "$unit"
            fi
        done
        rm -f -- "$state/$2.json" "$state/$2.log"
        exec sudo -n systemd-run --quiet --collect --unit="$unit_prefix$2" \
            --uid="$(id -un)" --gid="$(id -gn)" --working-directory="$3" \
            --setenv=HOME="$HOME" --property=RuntimeMaxSec="$max_seconds" \
            -- "$self" run "$2" "$3" "$4" "$5" "$6"
        ;;
    status)
        [[ $# -eq 2 ]] && valid_run "$2" || exit 64
        if [[ -s $state/$2.json ]]; then
            # A ready topology from an earlier boot is gone: its guests stopped with the VM. A run written before the
            # boot ID was recorded has none, and counts as ready.
            result=$(<"$state/$2.json")
            boot=$(</proc/sys/kernel/random/boot_id)
            if [[ $result == '{"state":"ready",'* && $result == *'"boot_id":'* && $result != *"\"boot_id\":\"$boot\""* ]]; then
                result='{"state":"stale"}'
            fi
            printf '%s\n' "$result"
        elif unit_running "$unit_prefix$2.service"; then
            echo '{"state":"running"}'
        else
            echo '{"state":"lost"}'
        fi
        ;;
    run)
        [[ $# -eq 6 ]] && valid_run "$2" || exit 64
        run=$2 checkout=$3 issue=$4 nodes=$5 vpn_subnet=$6
        log="$state/$run.log"
        exec >>"$log" 2>&1
        step=start
        started=$SECONDS
        retries=0
        # One line as a JSON string: invalid UTF-8 and control characters dropped, the rest escaped.
        json_string() {
            iconv -c -f UTF-8 -t UTF-8 | tr -d '\000-\010\013\014\016-\037\177' \
                | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e 's/\r/\\r/g' \
                | awk 'BEGIN { ORS = ""; print "\"" } NR > 1 { print "\\n" } { print } END { print "\"" }'
        }
        # The result is written last and atomically, so `status` never reads half of it.
        result() {
            printf '{"state":"%s","step":"%s","seconds":%d,"boot_id":"%s","log":%s}\n' \
                "$1" "$step" "$((SECONDS - started))" "$(</proc/sys/kernel/random/boot_id)" \
                "$(tail -n 40 -- "$log" | json_string)" >"$state/$run.json.new"
            mv -f -- "$state/$run.json.new" "$state/$run.json"
        }
        on_exit() {
            local rc=$?
            [[ $rc -eq 0 ]] || result failed
        }
        trap on_exit EXIT
        cd -- "$checkout"
        printf 'topology: preparing %s with nodes [%s]\n' "$issue" "$nodes"

        step=host-setup
        # Adopts the VM's clone of the topology seed. The input rule keeps topology guests off this VM's own address
        # in the fleet's VPN subnet.
        sudo -n bin/e2e-topology-host-setup --wireguard-subnet="$vpn_subnet"

        step=acquire
        # A nested guest can miss its readiness window. Acquire removes what it created, and --replace releases a
        # topology an earlier run left, so it can run again. The log records the retry.
        until bin/e2e-topology acquire "$issue" "$checkout" --nodes="$nodes" --replace; do
            ((retries < 1)) || exit 1
            retries=$((retries + 1))
            echo "topology: warning: acquire failed; retry $retries of 1."
        done
        result ready
        ;;
    *)
        exit 64
        ;;
esac
