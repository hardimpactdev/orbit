#!/bin/bash
# The fast wake of a frozen development container (docs/reference/prepared-restore.md#the-fast-wake, ADR 0261). The Gateway records
# the instance.wake Operation <opid>, then writes /dev/shm/orbit/hibernation/app-instance-<id>-op-<opid>.wake with the agent's
# `hibernation.marker`; only root writes in that folder. Two modes, both started by systemd:
#   dispatch      orbit-instance-wake.path starts it when a request exists. It renames each valid request to .taken and starts
#                 orbit-instance-wake@<id>-<opid>.service without waiting, so a slow worker never holds up the next request.
#   work <id>-<opid>
#                 One worker per Operation. It calls the power helper's restore through the Instance's lock (the same helper and the
#                 same rules as the wake's run), checks readiness once from the Node to the target descriptor's web address, removes
#                 the asleep marker and writes exactly one outcome under /var/lib/orbit/hibernation/outcomes:
#                 app-instance-<id>-op-<opid>.ready-<complete|partial|skipped|timed-out>, then .done; or .failed.
# The Gateway reads these files with `hibernation.times`. A worker never repeats a wake whose outcome exists, never touches an
# existing outcome, and never writes a ready or done file it did not earn. The script never reads a request's content and builds no
# command from it: the only dynamic values are the two numeric ids of the file name. Every step logs one line to the journal.
set -u

requests=${ORBIT_WAKE_REQUESTS:-/dev/shm/orbit/hibernation}
markers=${ORBIT_WAKE_MARKERS:-/data/caddy/orbit/hibernation}
state=${ORBIT_WAKE_STATE:-/var/lib/orbit/hibernation}
outcomes=$state/outcomes
helper=/usr/local/libexec/orbit-instance-power
nested_project=orbit-instances-nested
# A request older than 1 s is not acted on: the Gateway gives up after 6 s and the Operation continues in its run.
max_age_ns=1000000000
# The same check as the wake's run (tasks/instance-container-power.yml, "Refresh a stale library cache after the wake"): a converge
# cannot exec in a frozen container, so its library cache may lack the shared runtime libraries.
# shellcheck disable=SC2016 # it runs in the container's shell, which expands it
library_check='d=/opt/orbit-runtime/current/lib; c=$(ldconfig -p) || exit 0; for f in "$d"/lib*.so*; do n=${f##*/}; case "$n" in *.so|*.so.[0-9]|*.so.[0-9][0-9]|*.so.[0-9][0-9][0-9]) ;; *) continue;; esac; [ -e "$f" ] || continue; printf "%s\n" "$c" | sed -n "s/.* => //p" | grep -qxF "$d/$n" || { ldconfig -X && echo rebuilt; exit 0; }; done'
# The same check as the wake's run ("Refresh a stale CA bundle after the wake"): the bundle must hold the container's Orbit root.
# shellcheck disable=SC2016 # it runs in the container's shell, which expands it
ca_check='r=/usr/local/share/ca-certificates/orbit-managed-root-ca.crt; [ -r "$r" ] || exit 0; b=$(grep -v -- ----- "$r" | tr -d "\n"); grep -v -- ----- /etc/ssl/certs/ca-certificates.crt | tr -d "\n" | grep -qF "$b" || { update-ca-certificates >/dev/null && echo refreshed; }'
id_pattern='[1-9][0-9]{0,18}'

now_ns() { date +%s%N; }
log() { echo "orbit-instance-wake: $*"; }

dispatch() {
    local request name id opid
    shopt -s nullglob
    for request in "$requests"/app-instance-*-op-*.wake; do
        name=${request##*/}
        if [[ ! $name =~ ^app-instance-($id_pattern)-op-($id_pattern)\.wake$ ]] || [ -L "$request" ] || [ ! -f "$request" ]; then
            rm -f -- "$request"
            log "ignored request $name"
            continue
        fi
        id=${BASH_REMATCH[1]}
        opid=${BASH_REMATCH[2]}
        # The rename keeps the request's time, which the worker bounds.
        mv -f -- "$request" "$requests/app-instance-$id-op-$opid.taken" || { rm -f -- "$request"; continue; }
        if ! systemctl start --no-block "orbit-instance-wake@$id-$opid.service"; then
            log "instance $id op $opid: worker not started"
        fi
    done
}

# Writes one outcome file and makes it durable before anything reads it as a fact.
publish() {
    : > "$outcomes/$1" && chmod 0644 -- "$outcomes/$1" && sync -- "$outcomes/$1" "$outcomes"
}

decline() {
    log "instance $id op $opid: $1; the run continues the Operation"
    publish "$base.failed" || log "instance $id op $opid: failed file not written"
    exit 0
}

work() {
    [[ ${1:-} =~ ^($id_pattern)-($id_pattern)$ ]] || { log "ignored worker ${1:-}"; exit 0; }
    id=${BASH_REMATCH[1]}
    opid=${BASH_REMATCH[2]}
    base=app-instance-$id-op-$opid
    local taken="$requests/$base.taken" marker="$markers/app-instance-$id.asleep" ready=() file written target result status \
        address port code started restored probed
    shopt -s nullglob
    # 1. An Operation with an outcome is not woken again, and its files keep their times.
    for file in "$outcomes/$base".ready-{complete,partial,skipped,timed-out}; do
        [ -f "$file" ] && [ ! -L "$file" ] && ready+=("$file")
    done
    if [ -f "$outcomes/$base.failed" ] || [ -f "$outcomes/$base.done" ]; then
        log "instance $id op $opid: outcome exists; nothing to do"
        exit 0
    fi
    if (( ${#ready[@]} == 1 )); then
        # The worker ended after its ready file: the facts are committed, so only the done file is missing.
        publish "$base.done" && log "instance $id op $opid: done after an earlier ready"
        exit 0
    fi
    (( ${#ready[@]} == 0 )) || decline "more than one ready file"
    # 2. Only a fresh request for a container behind its marker, with a valid target descriptor outside the nested fleet.
    written=$(stat -c %.9Y -- "$taken" 2>/dev/null | tr -d .)
    [[ $written =~ ^[0-9]+$ ]] && [ ! -L "$taken" ] || decline "no request"
    (( $(now_ns) - written <= max_age_ns )) || decline "request older than 1 s"
    [ -f "$marker" ] && [ ! -L "$marker" ] || decline "no asleep marker"
    target=$(python3 -I - "$state/instances/$id/target.json" "$id" "$nested_project" 2>/dev/null <<'PY'
import ipaddress, json, sys
path, instance_id, nested = sys.argv[1], int(sys.argv[2]), sys.argv[3]
with open(path) as handle:
    target = json.load(handle)
assert target["schema_version"] == 1 and target["instance_id"] == instance_id
assert target["incus_project"] in ("orbit-instances", nested) and target["incus_name"] == f"instance-{instance_id}"
web = target["web"]
if web is not None:
    assert type(web["port"]) is int and 0 < web["port"] < 65536
    address = ipaddress.ip_address(web["address"])
    web = f"[{address}]" if address.version == 6 else str(address), web["port"]
print(target["incus_project"], target["incus_name"], *(web or ("-", "-")))
PY
    ) || decline "no valid target descriptor"
    read -r project container address port <<< "$target"
    [ "$project" != "$nested_project" ] || decline "a nested-fleet Instance takes its run"
    # 3. The one restore of every caller, through the Instance's lock. A busy lock declines: the run waits for it.
    started=$(now_ns)
    result=$(systemd-run "--unit=orbit-instance-power-$id" --wait --pipe --collect --quiet \
        "$helper" restore --instance "$id" --operation "$opid" < /dev/null) || decline "power helper busy or failed"
    restored=$(now_ns)
    status=$(python3 -I -c 'import json, sys
r = json.load(sys.stdin)
s = (r.get("prepared_restore") or {}).get("status")
print(s.replace("_", "-") if r.get("outcome") == "resumed" and s in ("complete", "partial", "skipped", "timed_out") else "")' <<< "$result" 2>/dev/null)
    [ -n "$status" ] || decline "the helper did not resume the container"
    # 4. One bounded readiness request from the Node to the recorded web port: an answer below 500. No web unit, no request.
    if [ "$address" != - ]; then
        code=$(curl --silent --output /dev/null --write-out '%{http_code}' --max-time 1.5 "http://$address:$port/" 2>/dev/null)
        [[ $code =~ ^[1-4][0-9][0-9]$ ]] || decline "resumed, the web unit gave no answer below 500; the marker stays"
    fi
    probed=$(now_ns)
    rm -f -- "$marker" || decline "marker not removed"
    publish "$base.ready-$status" || decline "ready file not written"
    # 5. The helper wrote its receipt; this line is the journal's record of the Operation.
    log "instance $id op $opid: resumed restore=$status restore_ms=$(( (restored - started) / 1000000 )) web_probe_ms=$(( (probed - restored) / 1000000 ))"
    publish "$base.done" || log "instance $id op $opid: done file not written"
    # 6. Maintenance after the outcome: the visitor does not wait for it.
    if [ "$(timeout 5 incus exec --project "$project" "$container" -- sh -c "$library_check" 2>/dev/null)" = rebuilt ]; then
        log "instance $id: library cache rebuilt"
    fi
    if [ "$(timeout 5 incus exec --project "$project" "$container" -- sh -c "$ca_check" 2>/dev/null)" = refreshed ]; then
        log "instance $id: CA bundle refreshed"
    fi
    exit 0
}

case "${1:-}" in
    dispatch) dispatch ;;
    work) work "${2:-}" ;;
    *) log "usage: orbit-instance-wake dispatch | work <id>-<opid>"; exit 2 ;;
esac
exit 0
