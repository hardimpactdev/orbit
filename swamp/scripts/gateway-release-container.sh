#!/usr/bin/env bash
# orbit-gateway-recovery-protocol: 2
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
set_inventory() {
  local live=$1 tmp retired=false
  if [[ -f $journal/host-retired ]] || { [[ -f $inventory ]] && jq -e '.host_retired == true' "$inventory" >/dev/null; }; then retired=true; fi
  tmp=$(mktemp "$home/.gateway-containers.XXXXXXXX")
  jq -n --arg live "$live" --argjson retired "$retired" '{version:2,live:$live,old:null,host_retired:$retired}' > "$tmp"
  chown orbit:orbit "$tmp"
  chmod 0600 "$tmp"
  mv -fT "$tmp" "$inventory"
  sync -f "$home"
}
gateway_slot() {
  local live='' old='' records stale name
  [[ ! -e $journal/operation ]] || fail 'finish the interrupted Gateway handover first'
  records=$("${incus_cli[@]}" list --format json)
  if [[ -f $inventory ]]; then
    live=$(field '.live | select(test("^[0-9a-f]{40}$"))')
    old=$(field '.old // ""')
    if [[ -n $old ]]; then
      [[ $old =~ ^[a-f0-9]{40}$ && $old != "$live" && $old != "$sha" ]] || fail 'Gateway rollback is unavailable; deploy a corrective commit'
      name=gateway-${old:0:12}
      stale=$(jq -c --arg name "$name" 'map(select(.name==$name))|first // {}' <<< "$records")
      if [[ $stale != '{}' ]]; then
        jq -e --arg old "$old" '(.status == "Frozen" or .status == "Stopped") and (.config |
          .["user.orbit.release.target"]=="gateway" and .["user.orbit.release.role"]=="candidate" and
          .["user.orbit.release.commit"]==$old and .["boot.autostart"]=="false")' <<< "$stale" >/dev/null ||
          fail 'legacy rollback is not safely parked'
        "${incus_cli[@]}" delete "$name" --force
      fi
      ctl forget gateway "${old:0:12}" >&2
      set_inventory "$live"
    fi
  fi
  # Never create a fourth container or evict a clone belonging to another operation.
  # A failed legacy prune may have left another unrecorded clone, even without .old.
  records=$("${incus_cli[@]}" list --format json)
  jq -e --arg live "gateway-${live:0:12}" --arg new "$candidate" --arg sha "$sha" --arg op "$operation" '
    [.[] | select(.name=="prepare-gateway" or (.name | test("^gateway-[a-f0-9]{12}$")) or .config["user.orbit.release.target"]=="gateway")] as $owned |
    ($owned|length) <= 3 and all($owned[]; .name=="prepare-gateway" or .name==$live or
      (.name==$new and .config["user.orbit.release.role"]=="candidate" and
       .config["user.orbit.release.commit"]==$sha and .config["user.orbit.release.operation"]==$op))' <<< "$records" >/dev/null ||
    fail 'another Gateway clone occupies the preparation slot; finish its operation or release its unused clone'
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
  local machine=$1 unit units
  units=$(active_horizons "$machine")
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    run_on "$machine" /bin/sh "$checkout/swamp/scripts/gateway-release-drain.sh" "$unit" </dev/null
  done <<< "$units"
  [[ -z $(active_horizons "$machine") ]] || fail 'previous Gateway still has active Horizon consumers'
}
wanted_horizons() {
  local admitted=$1 observed=${2:-/dev/null}
  if [[ $# == 1 && -f $journal/horizons && -f $journal/candidate && $(cat "$journal/candidate") == "$sha" ]]; then
    observed=$journal/horizons
  fi
  src swamp/scripts/gateway-release-horizons.py | python3 - "$admitted" "$observed"
}
start_horizons() {
  local machine=$1 selected=$2 unit selected_units
  selected_units=$(wanted_horizons "$selected" "${@:3}") || return 1
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    unit_wanted "$unit" || continue
    if [[ $unit == orbit-gateway-horizon.service ]] && [[ $machine != host ]]; then
      # Laravel resolves the authoritative Gateway queue in this release.
      # shellcheck disable=SC2016
      configured=$(php "$machine" -r 'require $argv[1]."/vendor/autoload.php"; $app=require $argv[1]."/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Infrastructure\Gateway\GatewayQueueRedis::configured() ? "yes" : "no";' "$app") || return 1
      [[ $configured == yes ]] || continue
    fi
    run_on "$machine" systemctl enable --now -- "$unit" </dev/null >&2 || return 1
    run_on "$machine" systemctl is-active --quiet -- "$unit" </dev/null || return 1
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
publish_forward() {
  local addr
  [[ $(cat "$journal/candidate") == "$sha" && $(cat "$journal/operation") == "$operation" ]] || fail 'handover identity changed'
  # This marker is durable before either Caddy input changes. A crash or uncertain reload
  # can only republish this same candidate; an earlier backend can never be selected again.
  addr=$(ctl address gateway "$id")
  set_upstream "$addr"
  sync -f /etc/caddy
  /usr/local/libexec/orbit-gateway-site container >&2
  set_inventory "$sha"
}
finish_forward() {
  local prev previous_sha exists unit service_failed=false
  [[ $(field '.live') == "$sha" ]] || fail 'candidate no longer serves'
  if [[ ! -f $journal/operation ]]; then return; fi
  [[ $(cat "$journal/operation") == "$operation" && $(cat "$journal/candidate") == "$sha" ]] || fail 'handover identity changed'
  prev=$(cat "$journal/machine")
  if [[ $prev != host ]]; then
    previous_sha=$(jq -er '.live | select(test("^[0-9a-f]{40}$"))' "$journal/inventory")
    [[ $prev == "gateway-${previous_sha:0:12}" && $previous_sha != "$sha" ]] || fail 'previous Gateway identity changed'
    exists=$("${incus_cli[@]}" list "$prev" --format json | jq -c --arg name "$prev" 'map(select(.name==$name))|first // {}')
    if [[ $exists != '{}' ]]; then
      jq -e --arg sha "$previous_sha" '.config | .["user.orbit.release.role"]=="candidate" and
        .["user.orbit.release.target"]=="gateway" and .["user.orbit.release.commit"]==$sha' <<< "$exists" >/dev/null ||
        fail 'previous Gateway container changed'
      "${incus_cli[@]}" config set "$prev" boot.autostart=false
      if [[ $(jq -r .status <<< "$exists") != Stopped ]]; then
        stop_background "$prev"
        drain_active_horizons "$prev"
        # A previous release may predate the no-SIGKILL drop-in. Always wait for the web
        # master and children to leave before stopping or deleting the former container.
        enter "$prev" install -d /run/systemd/system/orbit-gateway-web.service.d
        printf '[Service]\nKillMode=mixed\nSendSIGKILL=no\nTimeoutStopSec=infinity\n' |
          enter "$prev" tee /run/systemd/system/orbit-gateway-web.service.d/drain.conf >/dev/null
        enter "$prev" systemctl daemon-reload
        enter "$prev" systemctl stop orbit-gateway-web.service
        [[ $(enter "$prev" systemctl show -p ActiveState --value orbit-gateway-web.service) == inactive ]] || fail 'former Gateway HTTP still draining'
        "${incus_cli[@]}" stop "$prev" --timeout 120
      fi
      "${incus_cli[@]}" delete "$prev"
    fi
    ctl forget gateway "${previous_sha:0:12}" >&2
  else
    stop_background host
    drain_active_horizons host
    while IFS= read -r unit; do
      [[ -z $unit ]] || systemctl disable -- "$unit" </dev/null
    done < "$journal/horizons"
  fi
  # If new application services cannot start, leave the new target selected and allow
  # the operator to deploy a corrected commit. The next handover inherits desired units.
  start_background "$candidate" || service_failed=true
  cp "$journal/operation" "$journal/last-operation"
  touch "$journal/host-retired"
  set_inventory "$sha"
  sync -f "$journal"
  clear_switch
  [[ $service_failed == false ]] || fail 'Gateway selected; a background service needs a corrective deployment'
}

case "$action" in
  build)
    force=$1 manifest=$2 rollback=${3:-false}
    [[ $rollback != true ]] || fail 'Gateway rollback is unavailable; deploy a corrective commit'
    gateway_slot
    if retained; then
      ctl resume gateway "$id" "$operation" >&2
      grants "$candidate"
      exit
    fi
    if [[ ${ORBIT_GATEWAY_INITIAL_MOVE:-0} == 1 && ! -f $inventory ]]; then
      current=$(cat "$root/orbit/REVISION")
      [[ $sha == "$current" ]] || fail 'the move must use the currently serving commit'
    else
      current=$(field '.live')
    fi
    if [[ $force == true ]]; then fail "Gateway releases require a descendant of live"; fi
    if [[ -n $current ]]; then
      git -c safe.directory="$mirror" -C "$mirror" merge-base --is-ancestor "$current" "$sha" || fail "$sha does not descend from live"
    fi
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
    if retained; then exit 0; fi
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
    digest=$1
    if retained; then exit 0; fi
    gateway_slot
    # A retry can find this exact build already parked. Never execute guest code in a frozen preparer.
    if [[ $( "${incus_cli[@]}" config get "$prep" user.orbit.release.operation) != "$operation" ]]; then fail 'preparer operation changed'; fi
    if [[ $( "${incus_cli[@]}" config get "$prep" user.orbit.release.parked-seal) != "$operation/$sha/$digest" ]]; then
    # Publish REVISION only after code, configuration and the checked web build all exist.
    user "$prep" test -s /home/orbit/web/current/index.html
    printf '%s\n' "$sha" | user "$prep" tee "$checkout/REVISION.next" >/dev/null
    user "$prep" mv -T "$checkout/REVISION.next" "$checkout/REVISION"
    "${incus_cli[@]}" config set "$prep" user.orbit.release.commit="$sha" user.orbit.release.artifact="$digest" user.orbit.release.parked-seal="$operation/$sha/$digest"
    fi
    ctl sleep gateway "$operation" >&2
    ctl candidate gateway "$operation" "$sha" "$digest"
    src swamp/scripts/gateway-release-seal.sh | enter "$candidate" /bin/sh -se
    ;;
  snapshot)
    # Older workflow definitions still dispatch this phase during the transition.
    exit 0
    ;;
  migrate)
    if retained; then echo migrate-exit=0; exit; fi
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
      record_switch
      "${incus_cli[@]}" config set "$candidate" boot.autostart=true
      publish_forward
    fi
    printf '%s\n' "$generations"
    ;;
  public-ready)
    check_release "$sha"
    ;;
  recover)
    "${incus_cli[@]}" config set "$candidate" boot.autostart=true
    ctl resume gateway "$id" "$operation" >&2
    publish_forward
    failed=false
    start_horizons "$candidate" "$(cat "$journal/horizon")" || failed=true
    finish_forward
    [[ $failed == false ]] || fail 'Gateway selected; queue workers need a corrective deployment'
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
    [[ $rc == 0 || $rc == 75 ]] || exit "$rc"
    ;;
  retire) finish_forward ;;
  prune|prune-pending) : ;;
  *) fail "unknown phase $action" ;;
esac
