#!/bin/bash
# The fast wake of a frozen development container (docs/reference/development-containers.md#the-node-resumes-a-frozen-container).
# The Gateway writes a wake request, /dev/shm/orbit/hibernation/app-instance-<id>.wake, with the agent's `hibernation.marker`;
# only root writes in that folder. systemd starts this script for each request (orbit-instance-wake.path). For each request it
# first renames the request to app-instance-<id>.taken, then acts only when all hold: the id from the file NAME is digits, the
# request is at most one second old, the Instance's asleep marker exists, and Incus reports instance-<id> of orbit-instances as
# Frozen. Then it resumes the container, closes swap again, asks FrankenPHP once (1.5 s at most) for an answer below 500, removes the
# asleep marker and writes app-instance-<id>.resumed. Last it removes the .taken file. The Gateway reads these files with
# `hibernation.times`: a request still there means no script runs; .taken means it works; neither and no .resumed means it
# declined. Anything else changes nothing. The script never reads a request's content and builds no command from it. Every step
# logs one line to the journal.
set -u

requests=${ORBIT_WAKE_REQUESTS:-/dev/shm/orbit/hibernation}
markers=${ORBIT_WAKE_MARKERS:-/data/caddy/orbit/hibernation}
cgroups=${ORBIT_CGROUP_ROOT:-/sys/fs/cgroup}
project=orbit-instances
# FrankenPHP's port in every Instance container (InstanceContainer::WebPort).
web_port=8080
# The Gateway waits 3 s from its request, then starts the wake's workflow, whose first container step comes several seconds later
# (measured: 8-13 s for its playbook step). A request older than 1 s is not acted on, and each Incus call has its own bound, so
# a resume ends within about 6.5 s of the request. The library checks come after every pending request, each bounded to 5 s; they
# only rebuild the cache, which ldconfig replaces atomically.
max_age_ns=1000000000
# The same check as the wake's workflow (tasks/instance-container-power.yml, "Refresh a stale library cache after the wake"): a converge
# cannot exec in a frozen container, so its library cache may lack the shared runtime libraries.
# shellcheck disable=SC2016 # it runs in the container's shell, which expands it
library_check='d=/opt/orbit-runtime/current/lib; c=$(ldconfig -p) || exit 0; for f in "$d"/lib*.so*; do n=${f##*/}; case "$n" in *.so|*.so.[0-9]|*.so.[0-9][0-9]|*.so.[0-9][0-9][0-9]) ;; *) continue;; esac; [ -e "$f" ] || continue; printf "%s\n" "$c" | sed -n "s/.* => //p" | grep -qxF "$d/$n" || { ldconfig -X && echo rebuilt; exit 0; }; done'
# The same check as the wake's workflow ("Refresh a stale CA bundle after the wake"): the bundle must hold the container's Orbit root.
# shellcheck disable=SC2016 # it runs in the container's shell, which expands it
ca_check='r=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt; [ -r "$r" ] || exit 0; b=$(grep -v -- ----- "$r" | tr -d "\n"); grep -v -- ----- /etc/ssl/certs/ca-certificates.crt | tr -d "\n" | grep -qF "$b" || { update-ca-certificates >/dev/null && echo refreshed; }'

now_ns() { date +%s%N; }
log() { echo "orbit-instance-wake: $*"; }

resume() {
    local id=$1 container="instance-$1" marker="$markers/app-instance-$1.asleep" status started resumed probed code
    if [ ! -f "$marker" ] || [ -L "$marker" ]; then
        log "instance $id: no asleep marker; nothing to do"
        return 0
    fi
    status=$(timeout 1 incus query -- "/1.0/instances/$container?project=$project" 2>/dev/null \
        | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("status", ""))' 2>/dev/null)
    if [ "$status" != Frozen ]; then
        log "instance $id: ${status:-missing}, not Frozen; nothing to do"
        return 0
    fi
    started=$(now_ns)
    if ! timeout 2 incus --project "$project" resume "$container"; then
        log "instance $id: resume failed; the marker stays"
        return 0
    fi
    resumed=$(now_ns)
    # Pages in swap come back on use; a running container swaps no more, as before the sleep.
    if ! printf 0 > "$cgroups/lxc.payload.${project}_${container}/memory.swap.max"; then
        log "instance $id: swap limit not written; the marker stays"
        return 0
    fi
    # A resumed FrankenPHP still holds its socket, so one request tells; a silent one is the workflow's to wait for or restart.
    code=$(timeout 2 incus exec --project "$project" "$container" -- curl --silent --output /dev/null --write-out '%{http_code}' \
        --max-time 1.5 "http://127.0.0.1:$web_port/" 2>/dev/null)
    if [[ ! $code =~ ^[1-4][0-9][0-9]$ ]]; then
        log "instance $id: resumed, FrankenPHP gave no answer below 500; the marker stays"
        return 0
    fi
    probed=$(now_ns)
    rm -f -- "$marker" || { log "instance $id: marker not removed"; return 0; }
    : > "$requests/app-instance-$id.resumed"
    log "instance $id: resumed resume_ms=$(( (resumed - started) / 1000000 )) web_probe_ms=$(( (probed - resumed) / 1000000 ))"
    pending+=("$id")
}

shopt -s nullglob
pending=()

# Until no request is left: one that arrives during a resume is taken before any library check. Each pass removes or renames every
# request it saw, so the loop ends.
take_requests() {
    local request name id taken written seen=yes
    while [ "$seen" = yes ]; do
        seen=no
        for request in "$requests"/app-instance-*.wake; do
            seen=yes
            name=${request##*/}
            id=${name#app-instance-}
            id=${id%.wake}
            if [[ ! $id =~ ^[1-9][0-9]{0,18}$ ]] || [ -L "$request" ] || [ ! -f "$request" ]; then
                rm -f -- "$request"
                log "ignored request $name"
                continue
            fi
            taken="$requests/app-instance-$id.taken"
            written=$(stat -c %.9Y -- "$request" 2>/dev/null | tr -d .)
            # The old answer goes before the request does, so the Gateway never reads it as this one's.
            rm -f -- "$requests/app-instance-$id.resumed"
            mv -f -- "$request" "$taken" || { rm -f -- "$request"; continue; }
            if [[ ! $written =~ ^[0-9]+$ ]] || (( $(now_ns) - written > max_age_ns )); then
                log "instance $id: request older than 1 s; nothing to do"
            else
                resume "$id"
            fi
            rm -f -- "$taken"
        done
    done
}

# Every request first, so a wake that arrives during a resume does not wait for a library check; one that arrives during a check waits
# for it (at most 5 s), and if it is then older than 1 s the wake's workflow wakes that Instance. Then one check per resumed container, after its page
# answers: the visitor does not wait for it. Requests that arrived meanwhile go first again.
take_requests
while (( ${#pending[@]} > 0 )); do
    id=${pending[0]}
    pending=("${pending[@]:1}")
    if [ "$(timeout 5 incus exec --project "$project" "instance-$id" -- sh -c "$library_check" 2>/dev/null)" = rebuilt ]; then
        log "instance $id: library cache rebuilt"
    fi
    if [ "$(timeout 5 incus exec --project "$project" "instance-$id" -- sh -c "$ca_check" 2>/dev/null)" = refreshed ]; then
        log "instance $id: CA bundle refreshed"
    fi
    take_requests
done
exit 0
