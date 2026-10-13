#!/usr/bin/env bash
# Prepares a task group's declared E2E topology nested in its task VM, as the managed user.
#
#   topology.sh start RUN CHECKOUT ISSUE NODES POOL_SIZE VPN_SUBNET   install this script and start the unit for RUN
#   topology.sh status RUN                                 print the state of RUN as one JSON line; a ready run from
#                                                          an earlier boot of the VM is `stale`
#   topology.sh run RUN CHECKOUT ISSUE NODES POOL_SIZE VPN_SUBNET     the unit's work: host setup, role images, acquire
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

case ${1-} in
    start)
        [[ $# -eq 7 ]] && valid_run "$2" || exit 64
        [[ $3 == /* && -d $3 ]] || { echo "topology: the checkout $3 does not exist." >&2; exit 66; }
        [[ $4 =~ ^TASK-[1-9][0-9]{0,8}$ && $5 =~ ^[a-z0-9+,-]*$ && $6 =~ ^[1-9][0-9]*GiB$ && $7 =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/[0-9]{1,2}$ ]] || exit 64
        install -d -m 0700 "$(dirname "$self")" "$state"
        cat >"$self.new"
        chmod 0700 "$self.new"
        mv -f -- "$self.new" "$self"
        # A repeated start of a run that already runs leaves it alone.
        if systemctl is-active --quiet "$unit_prefix$2.service"; then
            exit 0
        fi
        # One preparation at a time: a unit of an earlier run, whose start the Gateway lost, stops first.
        for unit in $(systemctl list-units --plain --no-legend --state=active "$unit_prefix*" | awk '{print $1}'); do
            sudo -n systemctl stop -- "$unit"
        done
        rm -f -- "$state/$2.json" "$state/$2.log"
        exec sudo -n systemd-run --quiet --collect --unit="$unit_prefix$2" \
            --uid="$(id -un)" --gid="$(id -gn)" --working-directory="$3" \
            --setenv=HOME="$HOME" --property=RuntimeMaxSec="$max_seconds" \
            -- "$self" run "$2" "$3" "$4" "$5" "$6" "$7"
        ;;
    status)
        [[ $# -eq 2 ]] && valid_run "$2" || exit 64
        if [[ -s $state/$2.json ]]; then
            # A ready topology from an earlier boot is gone: its guests stopped with the VM. A run written before the
            # boot ID was recorded has none, and counts as ready.
            # shellcheck disable=SC2016 # PHP code, not shell.
            php -r '
                $result = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
                if (($result["state"] ?? null) === "ready" && isset($result["boot_id"]) && $result["boot_id"] !== trim((string) file_get_contents("/proc/sys/kernel/random/boot_id"))) {
                    $result = ["state" => "stale"];
                }
                echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
            ' -- "$state/$2.json"
        elif systemctl is-active --quiet "$unit_prefix$2.service"; then
            echo '{"state":"running"}'
        else
            echo '{"state":"lost"}'
        fi
        ;;
    run)
        [[ $# -eq 7 ]] && valid_run "$2" || exit 64
        run=$2 checkout=$3 issue=$4 nodes=$5 pool_size=$6 vpn_subnet=$7
        log="$state/$run.log"
        exec >>"$log" 2>&1
        step=start
        started=$SECONDS
        # The result is written last and atomically, so `status` never reads half of it.
        result() {
            # shellcheck disable=SC2016 # PHP code, not shell.
            php -r '
                $tail = array_slice(file($argv[4], FILE_IGNORE_NEW_LINES) ?: [], -40);
                $boot = trim((string) file_get_contents("/proc/sys/kernel/random/boot_id"));
                echo json_encode(["state" => $argv[1], "step" => $argv[2], "seconds" => (int) $argv[3], "boot_id" => $boot, "log" => implode("\n", $tail)], JSON_UNESCAPED_SLASHES), "\n";
            ' -- "$1" "$step" "$((SECONDS - started))" "$log" >"$state/$run.json.new"
            mv -f -- "$state/$run.json.new" "$state/$run.json"
        }
        on_exit() {
            local rc=$?
            [[ $rc -eq 0 ]] || result failed
        }
        trap on_exit EXIT
        cd -- "$checkout"
        printf 'topology: preparing %s with nodes [%s]\n' "$issue" "$nodes"

        step='env'
        # Like bin/bootstrap: the harness needs the Gateway .env, which a workspace's composer install does not write.
        [[ -e apps/gateway/.env ]] || cp apps/gateway/.env.example apps/gateway/.env

        step=host-setup
        # The input rule keeps topology guests off this VM's own address in the fleet's VPN subnet.
        sudo -n bin/e2e-topology-host-setup --pool-size="$pool_size" --wireguard-subnet="$vpn_subnet"

        step=images
        roles=(gateway operator)
        [[ ,$nodes, == *,app-dev[,+]* ]] && roles+=(app-dev)
        [[ ,$nodes, == *,app-prod[,+-]* ]] && roles+=(app-prod)
        # shellcheck disable=SC2016 # PHP code, not shell.
        missing=$(bin/e2e-topology-images status --json | php -r '
            $wanted = array_slice($argv, 1);
            foreach (json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR)["images"] as $image) {
                if (in_array($image["role"], $wanted, true) && $image["state"] === "missing") {
                    echo "--role=", $image["role"], "\n";
                }
            }
        ' -- "${roles[@]}")
        if [[ -n $missing ]]; then
            mapfile -t build <<<"$missing"
            bin/e2e-topology-images build "${build[@]}"
        fi

        step=release
        held=$(bin/e2e-topology status "$issue" --worktree="$checkout" --json | php -r 'echo json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR)["state"] ?? "";')
        if [[ $held != absent ]]; then
            bin/e2e-topology release "$issue" --worktree="$checkout"
        fi

        step=acquire
        # A nested guest can miss its readiness window once; acquire removes what it created, so it can run again.
        if ! bin/e2e-topology acquire "$issue" "$checkout" --nodes="$nodes"; then
            echo 'topology: acquire failed; trying once more.'
            bin/e2e-topology acquire "$issue" "$checkout" --nodes="$nodes"
        fi
        result ready
        ;;
    *)
        exit 64
        ;;
esac
