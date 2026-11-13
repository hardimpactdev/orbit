#!/bin/bash
# The fast wake of a frozen development container (docs/reference/development-containers.md#the-node-resumes-a-frozen-container).
# The Gateway writes a wake request, /dev/shm/orbit/hibernation/app-instance-<id>.wake, with the agent's `hibernation.marker`;
# only root writes in that folder. systemd starts this script for each request (orbit-instance-wake.path). For each request it
# first renames the request to app-instance-<id>.taken, then acts only when all hold: the id from the file NAME is digits, the
# request is at most one second old, the Instance's asleep marker exists, and Incus reports instance-<id> of orbit-instances as
# Frozen. Then it resumes the container, closes swap again, waits up to 1.5 s for FrankenPHP to answer below 500, removes the
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
# The Gateway waits 3 s from its request: a request older than 1 s is not acted on, so a resume never overlaps the wake's workflow.
max_age_ns=1000000000
probe_limit_ns=1500000000

now_ns() { date +%s%N; }
log() { echo "orbit-instance-wake: $*"; }

resume() {
    local id=$1 container="instance-$1" marker="$markers/app-instance-$1.asleep" status started resumed probed code
    if [ ! -f "$marker" ] || [ -L "$marker" ]; then
        log "instance $id: no asleep marker; nothing to do"
        return 0
    fi
    status=$(incus query -- "/1.0/instances/$container?project=$project" 2>/dev/null \
        | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("status", ""))' 2>/dev/null)
    if [ "$status" != Frozen ]; then
        log "instance $id: ${status:-missing}, not Frozen; nothing to do"
        return 0
    fi
    started=$(now_ns)
    if ! incus --project "$project" resume "$container"; then
        log "instance $id: resume failed; the marker stays"
        return 0
    fi
    resumed=$(now_ns)
    # Pages in swap come back on use; a running container swaps no more, as before the sleep.
    if ! printf 0 > "$cgroups/lxc.payload.${project}_${container}/memory.swap.max"; then
        log "instance $id: swap limit not written; the marker stays"
        return 0
    fi
    while :; do
        code=$(incus exec --project "$project" "$container" -- curl --silent --output /dev/null --write-out '%{http_code}' \
            --max-time 1 "http://127.0.0.1:$web_port/" 2>/dev/null)
        if [[ $code =~ ^[1-4][0-9][0-9]$ ]]; then
            break
        fi
        if (( $(now_ns) - resumed >= probe_limit_ns )); then
            log "instance $id: resumed, FrankenPHP gave no answer below 500; the marker stays"
            return 0
        fi
        sleep 0.1
    done
    probed=$(now_ns)
    rm -f -- "$marker" || { log "instance $id: marker not removed"; return 0; }
    : > "$requests/app-instance-$id.resumed"
    log "instance $id: resumed resume_ms=$(( (resumed - started) / 1000000 )) web_probe_ms=$(( (probed - resumed) / 1000000 ))"
}

shopt -s nullglob
for request in "$requests"/app-instance-*.wake; do
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
    mv -f -- "$request" "$taken" || { rm -f -- "$request"; continue; }
    rm -f -- "$requests/app-instance-$id.resumed"
    if [[ ! $written =~ ^[0-9]+$ ]] || (( $(now_ns) - written > max_age_ns )); then
        log "instance $id: request older than 1 s; nothing to do"
    else
        resume "$id"
    fi
    rm -f -- "$taken"
done
exit 0
