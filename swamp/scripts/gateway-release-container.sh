#!/usr/bin/env bash
# orbit-gateway-recovery-protocol: 3
# Run one release phase on the Gateway Node. The Swamp run orders the phases.
set -euo pipefail
umask 077
action=$1 sha=$2 operation=$3
shift 3
[[ $sha =~ ^[a-f0-9]{40}$ && $operation =~ ^[1-9][0-9]*$ ]] || exit 64
id=${sha:0:12}
root=/home/orbit
mirror=$root/shared/orbit.git
home=$root/.orbit
inventory=$home/gateway-containers.json
upstream=/etc/caddy/orbit-gateway-upstream.caddy
prep=prepare-gateway
candidate=gateway-$id
project=orbit-releases
checkout=/home/orbit/orbit
app=$checkout/apps/gateway
runtime=/opt/orbit-runtime/current
incus_cli=(incus --force-local --project "$project")
fail() { echo "gateway.release_container_failed: $*" >&2; exit 1; }
src() { git -c safe.directory="$mirror" -C "$mirror" show "$sha:$1"; }
ctl() { python3 <(src swamp/scripts/release-container.py) "$@"; }
enter() { local machine=$1; shift; "${incus_cli[@]}" exec "$machine" --cwd /home/orbit -- "$@"; }
user() { local machine=$1; shift; enter "$machine" runuser -u orbit -- env HOME=/home/orbit "PATH=$runtime/bin:$runtime/php/8.5/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" ORBIT_GATEWAY_SSH_MUX=/run/orbit/ssh-mux "$@"; }
php() { local machine=$1; shift; user "$machine" "$runtime/php/8.5/bin/php" "$@"; }
artisan() { local machine=$1; shift; php "$machine" "$app/artisan" "$@"; }
field() { jq -er "$1" "$inventory"; }
retained() { [[ -f $inventory ]] && jq -e --arg sha "$sha" '.live == $sha' "$inventory" >/dev/null; }
rollback_target() { [[ -f $inventory ]] && jq -e --arg sha "$sha" '.old == $sha' "$inventory" >/dev/null; }
owned_sha() { "${incus_cli[@]}" config get "$1" user.orbit.release.commit; }
grants() {
  # The child interpreter expands this program.
  # shellcheck disable=SC2016
  php "$1" -r 'require $argv[1]."/vendor/autoload.php"; $grants = (new App\Infrastructure\GatewayReleases\SwampGrantRenderer)->render($argv[2]); echo $grants["grants/orbit-gateway.yaml"],"---\n",$grants["grants/orbit-serve.yaml"];' "$app" "$checkout/swamp"
}
cache_config() {
  local machine=$1 before after
  /usr/local/libexec/orbit-gateway-environment refresh
  before=$(user "$machine" sha256sum "$root/shared/gateway.env")
  user "$machine" env ORBIT_AGENT_DELIVERY="$checkout/apps/agent/delivery/manifest.json" "$runtime/php/8.5/bin/php" "$app/artisan" config:cache --no-interaction --quiet
  after=$(user "$machine" sha256sum "$root/shared/gateway.env")
  [[ $before == "$after" ]] || fail 'Gateway environment changed while caching'
  printf '%s\n' "$after"
}
release_runtime() (
  # Read every installer input from the same admitted commit, before the candidate has a checkout.
  local stage
  stage=$(mktemp -d /run/orbit-gateway-runtime.XXXXXXXX)
  trap 'rm -rf -- "$stage"' EXIT
  git -c safe.directory="$mirror" -C "$mirror" archive "$sha" -- \
    bin/gateway-container-prepare apps/gateway/resources/machine-software/recipe.json \
    swamp/ansible/{ansible.cfg,gateway-container-runtime.yml,transport-exit-codes.json,tasks/artifact-fetch.yml,library/orbit_artifact.py} \
    | tar -x -C "$stage"
  python3 "$stage/bin/gateway-container-prepare" --release-runtime
)
check_release() { src bin/gateway-release-check | bash -se -- "$@"; }
private_ready() { check_release "$sha" "$(ctl address gateway "$id")"; }
verify_candidate() {
  local bind_changed=$1 desired cached fresh
  /usr/local/libexec/orbit-gateway-environment refresh
  desired=$(user "$candidate" sha256sum "$root/shared/gateway.env")
  cached=$(user "$candidate" cat "$root/.gateway-env-digest" 2>/dev/null || :)
  if [[ $desired != "$cached" || $bind_changed == true ]] || ! enter "$candidate" systemctl is-active --quiet orbit-gateway-web.service; then
    user "$candidate" rm -f -- "$root/.gateway-env-digest"
    fresh=$(set -e; cache_config "$candidate")
    enter "$candidate" systemctl restart orbit-gateway-web.service
    printf '%s\n' "$fresh" | user "$candidate" tee "$root/.gateway-env-digest" >/dev/null
  fi
  private_ready
  # The child interpreter expands this program.
  # shellcheck disable=SC2016
  php "$candidate" -r '
    require $argv[1]."/vendor/autoload.php"; $app=require $argv[1]."/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (App\Infrastructure\Gateway\GatewayQueueRedis::configured()) {
        App\Infrastructure\Gateway\GatewayQueueRedis::configure();
        Illuminate\Support\Facades\Redis::connection("gateway")->ping();
    }' "$app"
}
host_ready() { src swamp/scripts/gateway-release-host-check.py | runuser -u caddy -- python3 - "$checkout" "$sha"; }

journal=/var/lib/orbit/gateway-release-switch
record_switch() {
  printf '%s\n' "$sha" > "$journal/candidate"
  printf '%s\n' "$operation" > "$journal/operation.next"
  # Persist the recovery data before making the operation visible to --recover.
  sync -f "$journal"
  mv -fT "$journal/operation.next" "$journal/operation"
  sync -f "$journal"
}
clear_switch() {
  rm -f -- "$journal/operation"
  sync -f "$journal"
}
# Inventory: live, the stopped rollback release (old), an older stopped release kept while live is unverified
# (pending), and the releases that passed public verification. An inventory from before verification
# was recorded trusts the releases it holds.
set_inventory() {
  local live=$1 old=${2:-} pending=${3:-} verified=${4-__keep__} tmp retired=false
  if [[ -f $journal/host-retired ]] || { [[ -f $inventory ]] && jq -e '.host_retired == true' "$inventory" >/dev/null; }; then retired=true; fi
  if [[ $verified == __keep__ ]]; then verified=$(verified_list "$inventory"); fi
  tmp=$(mktemp "$home/.gateway-containers.XXXXXXXX")
  jq -n --arg live "$live" --arg old "$old" --arg pending "$pending" --arg verified "$verified" --argjson retired "$retired" '
    ($verified | split(" ") | map(select(. != ""))) as $known |
    {version:2,live:$live,old:(if $old == "" then null else $old end),pending:(if $pending == "" then null else $pending end),
     verified:([$live,$old,$pending] | map(select(. != "" and ([.] | inside($known)))) | unique),host_retired:$retired}' > "$tmp"
  chown orbit:orbit "$tmp"
  chmod 0600 "$tmp"
  mv -fT "$tmp" "$inventory"
  sync -f "$home"
}
verified_list() {
  [[ -f $1 ]] || return 0
  jq -r 'if has("verified") then .verified else [.live, .old] end | map(select(. != null)) | join(" ")' "$1"
}
is_verified() { [[ " $(verified_list "$2") " == *" $1 "* ]]; }
mark_verified() {
  [[ -f $inventory && ! -e $journal/operation ]] || return 0
  [[ $(field '.live') == "$1" ]] || return 0
  is_verified "$1" "$inventory" && return 0
  set_inventory "$1" "$(field '.old // ""')" "$(field '.pending // ""')" "$(verified_list "$inventory") $1"
}
record_of() {
  "${incus_cli[@]}" list "$1" --format json | jq -c --arg name "$1" 'map(select(.name==$name))|first // {}'
}
# A retained release is a stopped clone that cannot start with the Node.
parked() {
  jq -e --arg sha "$2" '.status == "Stopped" and (.config | .["user.orbit.release.target"]=="gateway" and
    .["user.orbit.release.role"]=="candidate" and .["user.orbit.release.commit"]==$sha and .["boot.autostart"]=="false")' \
    <<< "$1" >/dev/null
}
# The journaled previous live, or nothing when the host served before the switch.
former_sha() {
  [[ $(cat "$journal/machine") != host ]] || return 0
  jq -er '.live | select(test("^[0-9a-f]{40}$"))' "$journal/inventory"
}
discard_legacy() {
  local discarded=$1 live=$2 name stale
  [[ $discarded =~ ^[a-f0-9]{40}$ ]] && [[ $discarded != "$live" ]] && [[ $discarded != "$sha" ]] ||
    fail 'Gateway rollback is unavailable; deploy a corrective commit'
  name=gateway-${discarded:0:12}
  stale=$("${incus_cli[@]}" list "$name" --format json | jq -c --arg name "$name" 'map(select(.name==$name))|first // {}')
  if [[ $stale != '{}' ]]; then
    jq -e --arg sha "$discarded" '(.status == "Frozen" or .status == "Stopped") and (.config |
      .["user.orbit.release.target"]=="gateway" and .["user.orbit.release.role"]=="candidate" and
      .["user.orbit.release.commit"]==$sha and .["boot.autostart"]=="false")' <<< "$stale" >/dev/null ||
      fail 'legacy rollback is not safely parked'
    "${incus_cli[@]}" delete "$name" --force
  fi
  ctl forget gateway "${discarded:0:12}" >&2
}
# Drop a recorded parked release whose container is gone; refuse one that exists but is not parked.
present() {
  local name=gateway-${1:0:12} record
  record=$(record_of "$name")
  if [[ $record == '{}' ]]; then
    echo "gateway.release_container_missing: ${1:0:12} is gone; Orbit forgets it" >&2
    ctl forget gateway "${1:0:12}" >&2
    return 1
  fi
  parked "$record" "$1" || [[ $2 == rollback && $1 == "$sha" ]] ||
    fail "the retained Gateway release ${1:0:12} is running; finish its rollback or release it first"
}
# Count every Gateway container. At most three exist at any moment: preparation or its promoted candidate,
# live and old; while live is unverified, a pending older release takes the preparation slot instead.
three_containers() {
  local records
  records=$("${incus_cli[@]}" list --format json)
  jq -e --arg allowed "$*" '
    [.[] | select(.name=="prepare-gateway" or (.name | test("^gateway-[a-f0-9]{12}$")) or .config["user.orbit.release.target"]=="gateway")] as $owned |
    ($owned|length) <= 3 and ([$owned[].name] - ($allowed | split(" "))) == []' <<< "$records" >/dev/null
}
gateway_slot() {
  local live='' old='' pending='' changed=false legacy
  [[ ! -e $journal/operation ]] || fail 'finish the interrupted Gateway handover first'
  if [[ -f $inventory ]]; then
    live=$(field '.live | select(test("^[0-9a-f]{40}$"))')
    old=$(field '.old // ""')
    pending=$(field '.pending // ""')
    if [[ -f $journal/pending-prune ]]; then
      legacy=$(cat "$journal/pending-prune")
      if [[ $legacy != "$old" ]]; then discard_legacy "$legacy" "$live"; fi
      rm -- "$journal/pending-prune"
      sync -f "$journal"
    fi
    # Protocol 1 kept a frozen release that cannot serve a rollback of this protocol.
    if [[ -n $old && $(field '.version') == 1 ]]; then
      discard_legacy "$old" "$live"
      old=''
      changed=true
    fi
    if [[ -n $old ]]; then
      [[ $old =~ ^[a-f0-9]{40}$ ]] && [[ $old != "$live" ]] || fail 'invalid Gateway rollback release'
      present "$old" "${1:-}" || { old=''; changed=true; }
    fi
    if [[ -n $pending ]]; then present "$pending" '' || { pending=''; changed=true; }; fi
    if [[ $changed == true ]]; then set_inventory "$live" "$old" "$pending"; fi
    # A forward release needs the preparation slot. An unverified live keeps two stopped releases there.
    if [[ ${1:-} != rollback && -n $pending ]]; then settle_parked "$live" "$old" "$pending"; old=$(field '.old // ""'); pending=''; fi
  fi
  three_containers prepare-gateway "gateway-${live:0:12}" "${old:+gateway-${old:0:12}}" "${pending:+gateway-${pending:0:12}}" "$candidate" ||
    fail 'another Gateway clone occupies a slot; finish its operation or release its unused clone'
}
# Keep one stopped release once live is verified: the newest verified one, else the newest. Recreate preparation.
settle_parked() {
  local live=$1 keep='' each
  check_release "$live" >&2 || fail 'the serving Gateway release is unverified; roll back to old or deploy a fix after it verifies'
  mark_verified "$live"
  for each in "$2" "$3"; do
    [[ -n $each ]] && [[ -z $keep ]] && is_verified "$each" "$inventory" && keep=$each
  done
  [[ -n $keep ]] || keep=$2
  for each in "$2" "$3"; do
    [[ -n $each ]] && [[ $each != "$keep" ]] || continue
    parked "$(record_of "gateway-${each:0:12}")" "$each" || fail "release ${each:0:12} is not parked"
    "${incus_cli[@]}" delete "gateway-${each:0:12}"
    ctl forget gateway "${each:0:12}" >&2
  done
  set_inventory "$live" "$keep" ''
  ctl seed gateway "${live:0:12}" "$operation" >&2
}
set_upstream() {
  local addr=$1 tmp
  tmp=$(mktemp /etc/caddy/.orbit-gateway-upstream.XXXXXXXX)
  printf 'vars gateway_release_upstream %s:8080\n' "$addr" > "$tmp"
  chmod 0644 "$tmp"
  mv -fT "$tmp" "$upstream"
}
run_on() {
  local target=$1; shift
  if [[ $target == host ]]; then "$@"; else enter "$target" "$@"; fi
}
remember_background() {
  local machine=$1
  # Systemd Process names are generated by Orbit. Save only units that were running.
  # The child interpreter expands this program.
  # shellcheck disable=SC2016
  run_on "$machine" bash -euc '
    for unit in orbit-agent-view.service orbit-runtime-hibernator.timer; do
      if systemctl is-active --quiet "$unit"; then echo "$unit"; fi
    done
    for unit in $(systemctl list-units --state=active --type=service --plain --no-legend | awk "{print \$1}"); do
      cmd=$(systemctl show -p ExecStart --value "$unit")
      wd=$(systemctl show -p WorkingDirectory --value "$unit")
      [[ $wd == /home/orbit/orbit/apps/gateway* ]] || continue
      if [[ $cmd == *"artisan schedule:work"* ]]; then echo "$unit"; fi
    done'
}
unit_wanted() {
  local definition=/var/lib/orbit/gateway-processes/$1.json
  [[ ! -f $definition ]] || jq -e '.removed != true and .desired == "running"' "$definition" >/dev/null
}
active_horizons() {
  run_on "$1" systemctl list-units --state=active,activating,deactivating --type=service --plain --no-legend \
    'orbit-process-*-horizon.service' orbit-gateway-horizon.service | awk '{print $1}'
}
drain_active_horizons() {
  local machine=$1 unit units pid failed=0
  local pids=()
  units=$(active_horizons "$machine") || return 1
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    # Stop all old consumers promptly; one long job must not leave another worker claiming jobs.
    run_on "$machine" /bin/sh "$checkout/swamp/scripts/gateway-release-drain.sh" "$unit" </dev/null &
    pids+=("$!")
  done <<< "$units"
  for pid in "${pids[@]}"; do wait "$pid" || failed=1; done
  [[ $failed == 0 && -z $(active_horizons "$machine") ]] || fail 'previous Gateway still has active Horizon consumers'
}
drain_http() {
  local prev=$1
  # Older releases can have a finite service stop timeout. Do not force a request to end.
  enter "$prev" install -d /run/systemd/system/orbit-gateway-web.service.d
  printf '[Service]\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\n' |
    enter "$prev" tee /run/systemd/system/orbit-gateway-web.service.d/drain.conf >/dev/null
  enter "$prev" systemctl daemon-reload
  enter "$prev" systemctl stop orbit-gateway-web.service
  [[ $(enter "$prev" systemctl show -p ActiveState --value orbit-gateway-web.service) == inactive ]] || fail 'former Gateway HTTP still draining'
}
wanted_horizons() {
  local admitted=$1 observed=${2:-/dev/null}
  if [[ $# == 1 && -f $journal/horizons && -f $journal/candidate && $(cat "$journal/candidate") == "$sha" ]]; then
    observed=$journal/horizons
  fi
  src swamp/scripts/gateway-release-horizons.py | python3 - "$admitted" "$observed"
}
start_horizons() {
  local machine=$1 selected=$2 unit selected_units group
  selected_units=$(wanted_horizons "$selected" "${@:3}") || return 1
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    unit_wanted "$unit" || continue
    if [[ $unit == orbit-gateway-horizon.service ]] && [[ $machine != host ]]; then
      # Laravel resolves the authoritative Gateway queue in this release.
      # shellcheck disable=SC2016
      configured=$(php "$machine" -r 'require $argv[1]."/vendor/autoload.php"; $app=require $argv[1]."/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Infrastructure\Gateway\GatewayQueueRedis::configured() ? "yes" : "no";' "$app") || return 1
      if [[ $configured != yes ]]; then
        if grep -qxF "$unit" "$journal/horizons"; then
          fail 'the former fallback Horizon was active but the selected Gateway queue is not configured'
        fi
        continue
      fi
    fi
    run_on "$machine" systemctl enable --now -- "$unit" </dev/null >&2 || return 1
    run_on "$machine" systemctl is-active --quiet -- "$unit" </dev/null || return 1
    group=$(run_on "$machine" systemctl show --property=ControlGroup --value -- "$unit") || return 1
    php "$machine" "$checkout/bin/gateway-horizon-ready" "$app" "$group" || return 1
  done <<< "$selected_units"
}
start_background() {
  local machine=$1 unit
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    unit_wanted "$unit" || continue
    run_on "$machine" systemctl enable --now "$unit" </dev/null >&2 || return 1
    run_on "$machine" systemctl is-active --quiet "$unit" </dev/null || return 1
  done < "${2:-$journal/background}"
}
stop_background() {
  local machine=$1 unit
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    run_on "$machine" systemctl disable --now "$unit" </dev/null >&2
  done < "${2:-$journal/background}"
}
fence_admissions() {
  artisan "$candidate" operations:check-cleanup --fence-only --json >&2
}
publish_forward() {
  local addr earlier
  [[ $(cat "$journal/candidate") == "$sha" && $(cat "$journal/operation") == "$operation" ]] || fail 'handover identity changed'
  # This marker is durable before either Caddy input changes. A crash or uncertain reload
  # can only republish this same candidate; an earlier backend can never be selected again.
  [[ $(owned_sha "$candidate") == "$sha" &&
    $("${incus_cli[@]}" config get "$candidate" user.orbit.release.operation) == "$operation" ]] || fail 'candidate identity changed'
  "${incus_cli[@]}" config set "$candidate" boot.autostart=true
  addr=$(ctl address gateway "$id")
  set_upstream "$addr"
  sync -f /etc/caddy
  /usr/local/libexec/orbit-gateway-site container >&2
  # Retire decides which stopped release stays. Until then the inventory keeps the earlier ones.
  earlier=$(jq -r '.old // ""' "$journal/inventory" 2>/dev/null || :)
  if [[ $earlier == "$sha" ]]; then earlier=$(former_sha); fi
  set_inventory "$sha" "$earlier" "$(jq -r --arg sha "$sha" '.pending // "" | select(. != $sha)' "$journal/inventory" 2>/dev/null || :)" \
    "$(verified_list "$journal/inventory")"
}
# After the switch: verify the new live, delete what it replaces, drain the former live, park or delete it,
# then recreate preparation. Never more than three Gateway containers, also on retry and --recover.
finish_forward() {
  local prev previous_sha='' exists='{}' unit service_failed=false running=false http_pid drain_failed=0 enabled
  local verified=false known current each first='' second='' trusted journal_old='' journal_pending=''
  local -a keep=() earlier=() order=()
  [[ $(field '.live') == "$sha" ]] || fail 'candidate no longer serves'
  if [[ ! -f $journal/operation ]]; then return; fi
  [[ $(cat "$journal/operation") == "$operation" && $(cat "$journal/candidate") == "$sha" ]] || fail 'handover identity changed'
  # A failed Horizon start leaves the previous consumers and journal intact for retry.
  start_horizons "$candidate" "$(cat "$journal/horizon")"
  if check_release "$sha" >&2; then verified=true; fi
  known=$(verified_list "$journal/inventory")
  current=$(verified_list "$inventory")
  known="$known $current"
  prev=$(cat "$journal/machine")
  if [[ $prev != host ]]; then
    previous_sha=$(former_sha)
    [[ $prev == "gateway-${previous_sha:0:12}" ]] && [[ $previous_sha != "$sha" ]] || fail 'previous Gateway identity changed'
    exists=$(record_of "$prev")
    if [[ $exists != '{}' ]]; then
      jq -e --arg sha "$previous_sha" '.config | .["user.orbit.release.role"]=="candidate" and
        .["user.orbit.release.target"]=="gateway" and .["user.orbit.release.commit"]==$sha' <<< "$exists" >/dev/null ||
        fail 'previous Gateway container changed'
      "${incus_cli[@]}" config set "$prev" boot.autostart=false
      if [[ $(jq -r .status <<< "$exists") != Stopped ]]; then running=true; fi
    fi
  fi
  if [[ -f $journal/inventory ]]; then
    journal_old=$(jq -r '.old // empty' "$journal/inventory")
    journal_pending=$(jq -r '.pending // empty' "$journal/inventory")
    for each in "$journal_old" "$journal_pending"; do
      [[ -n $each ]] && [[ $each != "$sha" ]] && [[ $each != "$previous_sha" ]] || continue
      [[ $each =~ ^[a-f0-9]{40}$ ]] || fail 'invalid earlier rollback identity'
      if [[ $(record_of "gateway-${each:0:12}") != '{}' ]]; then earlier[${#earlier[@]}]=$each; else ctl forget gateway "${each:0:12}" >&2; fi
    done
  fi
  # Choose the stopped releases that stay, newest first. A verified live keeps one beside preparation:
  # the newest verified one. An unverified live keeps every earlier release, and the former live only when it
  # was verified, so a verified rollback target survives two failed releases in a row.
  if [[ $exists != '{}' ]]; then order[0]=$previous_sha; fi
  for each in "${earlier[@]}"; do order[${#order[@]}]=$each; done
  if [[ $verified == true ]]; then
    for each in "${order[@]}"; do
      if [[ " $known " == *" $each "* ]]; then keep[0]=$each; break; fi
    done
    if [[ ${#keep[@]} == 0 ]]; then
      if [[ $exists != '{}' ]]; then keep[0]=$previous_sha; elif [[ ${#earlier[@]} -gt 0 ]]; then keep[0]=${earlier[0]}; fi
    fi
  else
    if [[ $exists != '{}' ]] && { [[ " $known " == *" $previous_sha "* ]] || [[ ${#earlier[@]} == 0 ]]; }; then keep[0]=$previous_sha; fi
    for each in "${earlier[@]}"; do keep[${#keep[@]}]=$each; done
  fi
  (( ${#keep[@]} <= 2 )) || fail 'more than two stopped Gateway releases would remain'
  # The verified candidate has the route: the releases it replaces go before the drain.
  if [[ $verified == true ]]; then
    for each in "${earlier[@]}"; do
      [[ " ${keep[*]} " == *" $each "* ]] && continue
      parked "$(record_of "gateway-${each:0:12}")" "$each" || fail 'earlier rollback release is not parked'
      "${incus_cli[@]}" delete "gateway-${each:0:12}"
      ctl forget gateway "${each:0:12}" >&2
    done
  fi
  if [[ $prev == host ]] || [[ $running == true ]]; then
    /usr/local/libexec/orbit-gateway-release-phase scheduler-window "$sha" "$operation"
    stop_background "$prev"
  fi
  # Transfer the scheduler before waiting for long-running jobs or HTTP responses.
  # Preserve desired services across an unhealthy release so a corrective deploy can start them.
  if start_background "$candidate"; then
    rm -f "$journal/retry-background"
  else
    cp "$journal/background" "$journal/retry-background"
    service_failed=true
  fi
  if [[ $prev == host ]]; then
    drain_active_horizons host
    # The host's on-demand Gateway pool exits after its last request and idle timeout.
    # Other PHP-FPM pools keep their own workers. Leave the retirement marker absent on timeout.
    pool_rc=0
    timeout 630 pidwait -fx 'php-fpm: pool orbit-gateway' || pool_rc=$?
    case $pool_rc in 0|1) ;; *) fail 'old Gateway PHP requests still draining' ;; esac
    if pgrep -fx 'php-fpm: pool orbit-gateway' >/dev/null; then fail 'old Gateway PHP requests still draining'; fi
    while IFS= read -r unit; do
      [[ -z $unit ]] || systemctl disable -- "$unit" </dev/null
    done < "$journal/horizons"
  else
    if [[ $running == true ]]; then
      drain_http "$prev" </dev/null &
      http_pid=$!
      (drain_active_horizons "$prev") || drain_failed=1
      wait "$http_pid" || drain_failed=1
      [[ $drain_failed == 0 ]] || fail 'former Gateway is still draining'
      # A rollback starts old with its units as they are. Only the selected live consumes jobs or runs the scheduler.
      enabled=$(enter "$prev" systemctl list-unit-files --state=enabled --type=service --plain --no-legend \
        'orbit-process-*-horizon.service' 'orbit-process-*-schedule-work.service' orbit-gateway-horizon.service </dev/null | awk '{print $1}')
      printf '%s\n' "$enabled" | sort -u - "$journal/horizons" | while IFS= read -r unit; do
        [[ -z $unit ]] || enter "$prev" systemctl disable -- "$unit" </dev/null >&2
      done
    fi
    if [[ $exists != '{}' ]]; then
      # Stop, never freeze: a stopped release holds no memory or swap until a rollback starts it.
      ctl retire gateway "${previous_sha:0:12}" "$operation" >&2
      if [[ " ${keep[*]} " != *" $previous_sha "* ]]; then
        "${incus_cli[@]}" delete "$prev"
        ctl forget gateway "${previous_sha:0:12}" >&2
      fi
    fi
  fi
  first=${keep[0]:-}
  second=${keep[1]:-}
  trusted=$known
  if [[ $verified == true ]]; then trusted="$trusted $sha"; fi
  touch "$journal/host-retired"
  set_inventory "$sha" "$first" "$second" "$trusted"
  # Preparation returns once a slot is free: the third container beside live and one stopped release.
  if (( ${#keep[@]} < 2 )); then ctl seed gateway "$id" "$operation" >&2; fi
  cp "$journal/operation" "$journal/last-operation"
  sync -f "$journal"
  clear_switch
  [[ $service_failed == false ]] || fail 'Gateway selected; a background service needs a corrective deployment'
  [[ $verified == true ]] || fail 'Gateway selected but its public check failed; it keeps the earlier releases. Roll back to old or deploy a fix'
}

case "$action" in
  build)
    force=$1 manifest=$2 rollback=${3:-false}
    if [[ $rollback == true ]]; then
      # Rollback selects the stopped old with its sealed build and web assets. It builds nothing.
      rollback_target || fail 'the selected release is not the retained Gateway release'
      gateway_slot rollback
      ctl resume gateway "$id" "$operation" >&2
      # The switch admits only a candidate of this operation.
      "${incus_cli[@]}" config set "$candidate" user.orbit.release.operation="$operation"
      grants "$candidate"
      exit
    fi
    [[ $force != true ]] || fail "Gateway releases require a descendant of live"
    gateway_slot
    if retained; then
      fail 'selected Gateway already serves; reconcile its exact revision or deploy a corrective commit'
    fi
    if [[ ${ORBIT_GATEWAY_INITIAL_MOVE:-0} == 1 && ! -f $inventory ]]; then
      current=$(cat "$root/orbit/REVISION")
      [[ $sha == "$current" ]] || fail 'the move must use the currently serving commit'
    else
      current=$(field '.live')
    fi
    git -c safe.directory="$mirror" -C "$mirror" merge-base --is-ancestor "$current" "$sha" || fail "$sha does not descend from live"
    if [[ -f $inventory ]]; then
      ctl capacity gateway --live="${current:0:12}" >&2
    else
      ctl capacity gateway --host-gateway >&2
    fi
    pinned_runtime=$(release_runtime)
    ctl wake gateway "$operation" >&2
    python3 <(src swamp/ansible/files/gateway-release-guest.py) --runtime "$operation" "$pinned_runtime" >&2
    bash <(src swamp/scripts/gateway-release-checkout.sh) "$mirror" "$sha" "$checkout" \
      "${incus_cli[@]}" exec "$prep" -- runuser -u orbit --
    user "$prep" sh -eu -c '
      cd /home/orbit/orbit/apps/gateway
      rm -rf storage
      ln -s /home/orbit/shared/gateway-storage storage
      ln -s /home/orbit/shared/gateway.env .env'
    log=$(mktemp)
    if ! user "$prep" composer --working-dir="$app" install --no-dev --prefer-dist --no-interaction --no-progress >"$log" 2>&1; then
      tail -c 4000 "$log" >&2
      rm -f -- "$log"
      fail "Composer install failed for apps/gateway"
    fi
    rm -f -- "$log"
    user "$prep" composer --working-dir="$app" check-platform-reqs --no-dev >&2
    user "$prep" mkdir -p "$checkout/apps/agent/delivery"
    printf '%s' "$manifest" | user "$prep" tee "$checkout/apps/agent/delivery/manifest.json" >/dev/null
    enter "$prep" install -d -m 0755 /etc/orbit/gateway-web
    enter "$prep" install -o root -g root -m 0644 "$checkout/swamp/ansible/files/gateway-web/Caddyfile" /etc/orbit/gateway-web/Caddyfile
    enter "$prep" install -o root -g root -m 0644 "$checkout/swamp/ansible/files/gateway-web/orbit-gateway-web.service" /etc/systemd/system/orbit-gateway-web.service
    enter "$prep" systemctl daemon-reload
    user "$prep" rm -f -- "$root/.gateway-env-digest"
    cache_config "$prep" >/dev/null
    /usr/local/libexec/orbit-gateway-unit apply-preparation "$operation" >&2
    grants "$prep"
    ;;
  web)
    # The archive header/token stays on stdin. Retained releases already hold their verified web build.
    if retained || rollback_target; then exit 0; fi
    [[ $1 =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || fail 'invalid release repository'
    printf 'https://github.com/%s\n' "$1" | user "$prep" tee "$checkout/GATEWAY_ORIGIN" >/dev/null
    enter "$prep" install -d -m 0750 -o orbit -g orbit /home/orbit/web </dev/null
    # Run the same bounded extraction and digest validation used by host releases.
    enter "$prep" env ORBIT_GATEWAY_WEB_GROUP=orbit ORBIT_GATEWAY_WEB_ARCHIVE_STDIN="${ORBIT_GATEWAY_WEB_ARCHIVE_STDIN:-0}" bash "$checkout/swamp/scripts/gateway-release-web.sh" "$id" "$@"
    user "$prep" ln -sfnT "releases/$id" /home/orbit/web/current
    # Sealed clones have independent web trees. Only the newly checked web build
    # belongs in the preparation snapshot; never traverse a symlink or a shared mount.
    user "$prep" find /home/orbit/web/releases -xdev -regextype posix-extended -mindepth 1 -maxdepth 1 \
      '!' -name "$id" '!' -name ".$id.digest" \
      -regex '.*/([a-f0-9]{12}|\.[a-f0-9]{12}\.(partial|digest))' -exec rm -rf -- '{}' +
    ;;
  clone)
    # The preparer itself becomes the candidate: no clone, so the Gateway never holds a fourth container.
    digest=$1
    if retained || rollback_target; then fail "cannot promote over a retained Gateway release"; fi
    gateway_slot
    if [[ $(record_of "$candidate") == '{}' ]]; then
      # A retry can find this exact build already parked. Never execute guest code in a sealed preparer twice.
      if [[ $("${incus_cli[@]}" config get "$prep" user.orbit.release.operation) != "$operation" ]]; then fail 'preparer operation changed'; fi
      if [[ $("${incus_cli[@]}" config get "$prep" user.orbit.release.parked-seal) != "$operation/$sha/$digest" ]]; then
        # Publish REVISION only after code, configuration and the checked web build all exist.
        user "$prep" test -s /home/orbit/web/current/index.html
        printf '%s\n' "$sha" | user "$prep" tee "$checkout/REVISION.next" >/dev/null
        user "$prep" mv -T "$checkout/REVISION.next" "$checkout/REVISION"
        "${incus_cli[@]}" config set "$prep" user.orbit.release.commit="$sha" user.orbit.release.artifact="$digest" user.orbit.release.parked-seal="$operation/$sha/$digest"
      fi
    fi
    ctl promote gateway "$operation" "$sha" "$digest" >&2
    src swamp/scripts/gateway-release-seal.sh | enter "$candidate" /bin/sh -se
    ;;
  snapshot)
    # Older workflow definitions still dispatch this phase during the transition.
    exit 0
    ;;
  migrate)
    # A rollback runs old's code on the current schema. Migrations are expand-only, so it never migrates.
    if retained || rollback_target; then echo migrate-exit=0; exit; fi
    set +e
    output=$(artisan "$candidate" migrate --force --isolated=1 --no-interaction 2>&1)
    result=$?
    set -e
    printf '%s\n' "$output" | tail -c 1500
    printf 'migrate-exit=%s\n' "$result"
    ;;
  wait-idle)
    artisan "$candidate" gateway:release:wait-idle --no-interaction
    ;;
  ready)
    address=$(ctl address gateway "$id")
    proxy=$(ip -4 -j addr show orbitgw0 | jq -er '.[0].addr_info[] | select(.scope == "global").local')
    [[ $proxy != *$'\n'* ]] || fail 'Gateway bridge has several IPv4 addresses'
    enter "$candidate" install -d -m 0755 /etc/orbit/gateway-web
    previous_bind=$(enter "$candidate" cat /etc/orbit/gateway-web/runtime.env 2>/dev/null || :)
    printf 'GATEWAY_BIND_ADDRESS=%s\nGATEWAY_TRUSTED_PROXY=%s\n' "$address" "$proxy" |
      enter "$candidate" tee /etc/orbit/gateway-web/runtime.env >/dev/null
    bind_changed=false
    if [[ $previous_bind != "$(enter "$candidate" cat /etc/orbit/gateway-web/runtime.env)" ]]; then bind_changed=true; fi
    verify_candidate "$bind_changed"
    ;;
  switch)
    expected=$1
    if [[ -f $journal/operation ]]; then
      fence_admissions
      publish_forward
      cat "$journal/generations"
      exit
    fi
    previous=''
    if [[ -f $inventory ]]; then previous=$(field '.live | select(test("^[0-9a-f]{40}$"))'); fi
    [[ ${previous:0:12} == "$expected" ]] || fail 'live changed after admission'
    generations=$(artisan "$candidate" gateway:release:cleanup --no-interaction | jq -ce .generations)
    if [[ $previous != "$sha" ]]; then
      /usr/local/libexec/orbit-gateway-unit apply-candidate "$operation" "$sha" >&2
      verify_candidate false
      install -d -m 0700 "$journal"
      prev=host
      if [[ $previous ]]; then
        prev=gateway-${previous:0:12}
        cp -- "$inventory" "$journal/inventory"
      else
        [[ ${ORBIT_GATEWAY_INITIAL_MOVE:-0} == 1 ]] || fail 'missing live container inventory'
        rm -f -- "$journal/inventory"
      fi
      printf '%s\n' "$prev" > "$journal/machine"
      {
        remember_background "$prev"
        if [[ -f $journal/retry-background ]]; then cat "$journal/retry-background"; fi
        for record in /var/lib/orbit/gateway-processes/orbit-process-*-schedule-work.service.json; do
          [[ -f $record ]] || continue
          unit=${record##*/}
          if unit_wanted "${unit%.json}"; then echo "${unit%.json}"; fi
        done
      } | sort -u > "$journal/background"
      printf '%s\n' "${2:-}" > "$journal/horizon"
      active_horizons "$prev" > "$journal/horizons"
      printf '%s\n' "$generations" > "$journal/generations"
      if grep -qxF orbit-agent-view.service "$journal/background"; then enter "$candidate" systemctl start orbit-agent-view.service; fi
      enter "$candidate" systemctl enable orbit-gateway-web.service
      # Set autostart after committing to forward recovery; a failed private build must stay disposable.
      # No on-disk or live traffic selection changes until the database has durably
      # rejected new admissions for all builders declared obsolete by this candidate.
      record_switch
      fence_admissions
      publish_forward
    fi
    printf '%s\n' "$generations"
    ;;
  public-ready)
    check_release "$sha"
    mark_verified "$sha"
    ;;
  settle)
    # Without an open handover: verify live, keep one stopped release and recreate preparation.
    [[ ! -e $journal/operation && $(field '.live') == "$sha" ]] || fail 'finish the Gateway handover first'
    if [[ -n $(field '.pending // ""') ]]; then settle_parked "$sha" "$(field '.old // ""')" "$(field '.pending // ""')"; fi
    ;;
  recover)
    ctl resume gateway "$id" "$operation" >&2
    fence_admissions
    publish_forward
    finish_forward
    ;;
  start-horizon)
    [[ $(field '.live') == "$sha" ]] || fail 'candidate is no longer live'
    start_horizons "$candidate" "$1"
    ;;
  cleanup)
    [[ $(field '.live') == "$sha" ]] || fail 'candidate is no longer live'
    artisan "$candidate" gateway:release:cleanup --no-interaction "$1"
    ;;
  cleanup-versions)
    [[ ! -f $journal/operation && $(field '.live') == "$sha" ]] || fail 'finish the Gateway handover before checking cleanup'
    rc=0
    artisan "$candidate" operations:check-cleanup --json || rc=$?
    case $rc in 0|75) ;; *) exit "$rc" ;; esac
    ;;
  retire) finish_forward ;;
  prune|prune-pending) : ;;
  *) fail "unknown phase $action" ;;
esac
