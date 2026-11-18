#!/usr/bin/env bash
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
retained() { [[ -f $inventory ]] && jq -e --arg sha "$sha" '.live == $sha or .old == $sha' "$inventory" >/dev/null; }
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
  printf '%s\n' "$after" | user "$machine" tee "$root/.gateway-env-digest" >/dev/null
}
check_release() { src bin/gateway-release-check | bash -se -- "$@"; }
private_ready() { check_release "$sha" "$(ctl address gateway "$id")"; }
host_ready() { src swamp/scripts/gateway-release-host-check.py | runuser -u caddy -- python3 - "$checkout" "$sha"; }

journal=/var/lib/orbit/gateway-release-switch
host_fallback=$journal/host-fallback
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
# A delete may fail after promotion. Keep its exact SHA across journal rotation.
prune_previous() {
  local discarded other matches live old
  [[ -f $journal/pending-prune ]] || return 0
  [[ ! -f $journal/operation ]] || fail 'recover or finish the interrupted switch before pruning'
  discarded=$(cat "$journal/pending-prune")
  [[ $discarded =~ ^[a-f0-9]{40}$ ]] || fail 'invalid pending rollback identity'
  live=$(field '.live')
  old=$(jq -er '.old // ""' "$inventory")
  [[ $live =~ ^[a-f0-9]{40}$ && ( -z $old || $old =~ ^[a-f0-9]{40}$ ) ]] || fail 'invalid live container inventory'
  if [[ $discarded != "$live" ]] && [[ $discarded != "$old" ]]; then
    other=gateway-${discarded:0:12}
    matches=$("${incus_cli[@]}" list "$other" --format json | jq -er --arg name "$other" '[.[] | select(.name==$name)] | length')
    if [[ $matches != 0 ]]; then
      [[ $matches == 1 && $(owned_sha "$other") == "$discarded" ]] || fail 'previous rollback identity changed'
      [[ $("${incus_cli[@]}" config get "$other" user.orbit.release.target) == gateway ]] || fail 'not a Gateway release'
      [[ $("${incus_cli[@]}" config get "$other" user.orbit.release.role) == candidate ]] || fail 'not a release clone'
      "${incus_cli[@]}" delete "$other" --force
    fi
    ctl forget gateway "${discarded:0:12}"
    rm -f -- "$journal/rollback-$discarded"
  fi
  rm -- "$journal/pending-prune"
}
set_inventory() {
  local live=$1 old=$2 tmp retired=false
  if [[ -f $journal/host-retired ]]; then retired=true; fi
  tmp=$(mktemp "$home/.gateway-containers.XXXXXXXX")
  jq -n --arg live "$live" --arg old "$old" --argjson retired "$retired" \
    '{version:1,live:$live,old:(if $old=="" then null else $old end),host_retired:$retired}' > "$tmp"
  chown orbit:orbit "$tmp"
  chmod 0600 "$tmp"
  mv -fT "$tmp" "$inventory"
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
  selected_units=$(wanted_horizons "$selected" "${@:3}")
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    unit_wanted "$unit" || continue
    if [[ $unit == orbit-gateway-horizon.service ]] && [[ $machine != host ]]; then
      # Laravel resolves the authoritative Gateway queue in this release.
      # shellcheck disable=SC2016
      configured=$(php "$machine" -r 'require $argv[1]."/vendor/autoload.php"; $app=require $argv[1]."/bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo App\Infrastructure\Gateway\GatewayQueueRedis::configured() ? "yes" : "no";' "$app")
      [[ $configured == yes ]] || continue
    fi
    run_on "$machine" systemctl enable --now -- "$unit" </dev/null >&2
    run_on "$machine" systemctl is-active --quiet -- "$unit" </dev/null
  done <<< "$selected_units"
}
start_background() {
  local machine=$1 unit
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    unit_wanted "$unit" || continue
    run_on "$machine" systemctl enable --now "$unit" </dev/null >&2
    run_on "$machine" systemctl is-active --quiet "$unit" </dev/null
  done < "${2:-$journal/background}"
}
stop_background() {
  local machine=$1 unit
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    run_on "$machine" systemctl disable --now "$unit" </dev/null >&2
  done < "${2:-$journal/background}"
}
restore_container() {
  local saved_inventory=$1 previous=$2 previous_sha address
  previous_sha=$(jq -er '.live | select(test("^[0-9a-f]{40}$"))' "$saved_inventory")
  [[ $previous == "gateway-${previous_sha:0:12}" && $(owned_sha "$previous") == "$previous_sha" ]] || fail 'saved previous release identity changed'
  ctl resume gateway "${previous#gateway-}" "$operation" >&2
  /usr/local/libexec/orbit-gateway-release-phase ready "$previous_sha" "$operation" >&2
  address=$(ctl address gateway "${previous#gateway-}")
  check_release "$previous_sha" "$address"
  rm -f -- "$journal/recovery-source-upstream"
  if [[ -f $upstream ]]; then cp -- "$upstream" "$journal/recovery-source-upstream"; fi
  set_upstream "$address"
  if ! /usr/local/libexec/orbit-gateway-site container >&2; then
    if [[ -f $journal/recovery-source-upstream ]]; then
      install -m 0644 "$journal/recovery-source-upstream" "$upstream.restore"
      mv -fT "$upstream.restore" "$upstream"
    else
      rm -f -- "$upstream"
    fi
    fail 'Caddy could not restore the verified previous release'
  fi
  install -o orbit -g orbit -m 0600 "$saved_inventory" "$inventory.restore"
  mv -fT "$inventory.restore" "$inventory"
}
restore_switch() {
  [[ -f $journal/operation && $(cat "$journal/operation") == "$operation" ]] || fail 'there is no matching switch to reverse'
  local previous horizon
  previous=$(cat "$journal/machine")
  if [[ -f $journal/inventory ]]; then
    restore_container "$journal/inventory" "$previous"
  else
    host_ready
    /usr/local/libexec/orbit-gateway-site host >&2
    check_release "$sha"
    rm -f -- "$inventory" "$upstream"
  fi
  stop_background "$candidate"
  drain_active_horizons "$candidate"
  horizon=$(cat "$journal/horizon" 2>/dev/null || :)
  start_horizons "$previous" "$horizon"
  start_background "$previous"
  if [[ $previous != host ]]; then
    "${incus_cli[@]}" config set "$previous" boot.autostart=true
  else
    sync -f "$home" /etc/caddy
  fi
  "${incus_cli[@]}" config set "$candidate" boot.autostart=false
}
finish_host_fallback() {
  rm -f -- "$inventory" "$upstream"
  sync -f "$home" /etc/caddy
  ORBIT_GATEWAY_INITIAL_MOVE=1 /usr/local/libexec/orbit-gateway-release-phase release-unused "$sha" "$operation"
  clear_switch
  rm -f -- "$journal/rollback-$sha" "$host_fallback"/{inventory,horizons,background,verified,verified.next}
}
reverse_host_fallback() {
  local horizon units
  restore_container "$host_fallback/inventory" "$candidate"
  stop_background host
  units=$(active_horizons host)
  drain_active_horizons host
  while IFS= read -r unit; do
    [[ -n $unit ]] || continue
    systemctl disable -- "$unit" </dev/null
  done <<< "$units"
  horizon=$(cat "$journal/horizon")
  start_horizons "$candidate" "$horizon" "$host_fallback/horizons"
  start_background "$candidate" "$host_fallback/background"
  "${incus_cli[@]}" config set "$candidate" boot.autostart=true
  sync -f "$home" /etc/caddy
  clear_switch
  rm -f -- "$host_fallback"/{inventory,horizons,background,verified,verified.next}
}

case "$action" in
  build)
    force=$1 manifest=$2 rollback=${3:-false}
    if [[ $rollback == true ]]; then
      [[ $(field '.old') == "$sha" ]] || fail 'retained rollback changed after admission'
    fi
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
    if [[ $force != true ]]; then
      git -c safe.directory="$mirror" -C "$mirror" merge-base --is-ancestor "$current" "$sha" || fail "$sha does not descend from live"
    fi
    ctl wake gateway "$operation" >&2
    # The candidate owns its sealed checkout as root. Trust only that path for fleet history reads as orbit.
    enter "$prep" git config --system --fixed-value --replace-all safe.directory "$checkout" "$checkout"
    # Build in a fresh checkout. Composer's download cache stays outside it.
    user "$prep" sh -eu -c 'cd /home/orbit; rm -rf .gateway-next; mkdir -m 0700 .gateway-next'
    bash <(src swamp/scripts/gateway-release-checkout.sh) "$mirror" "$sha" "$root/.gateway-next" \
      "${incus_cli[@]}" exec "$prep" -- runuser -u orbit --
    user "$prep" sh -eu -c '
      cd /home/orbit
      test ! -L orbit
      rm -rf .gateway-previous
      if test -d orbit; then mv -T orbit .gateway-previous; fi
      mv -T .gateway-next orbit
      rm -rf .gateway-previous
      cd orbit/apps/gateway
      rm -rf storage
      ln -s /home/orbit/shared/gateway-storage storage
      ln -s /home/orbit/shared/gateway.env .env'
    for package in apps/cli apps/gateway; do
      log=$(mktemp)
      if ! user "$prep" composer --working-dir="$checkout/$package" install --no-dev --prefer-dist --no-interaction --no-progress >"$log" 2>&1; then
        tail -c 4000 "$log" >&2
        rm -f -- "$log"
        fail "Composer install failed for $package"
      fi
      rm -f -- "$log"
      user "$prep" composer --working-dir="$checkout/$package" check-platform-reqs --no-dev >&2
    done
    user "$prep" mkdir -p "$checkout/apps/agent/delivery"
    printf '%s' "$manifest" | user "$prep" tee "$checkout/apps/agent/delivery/manifest.json" >/dev/null
    enter "$prep" install -d -m 0755 /etc/orbit/gateway-web
    enter "$prep" install -o root -g root -m 0644 "$checkout/swamp/ansible/files/gateway-web/Caddyfile" /etc/orbit/gateway-web/Caddyfile
    enter "$prep" install -o root -g root -m 0644 "$checkout/swamp/ansible/files/gateway-web/orbit-gateway-web.service" /etc/systemd/system/orbit-gateway-web.service
    enter "$prep" systemctl daemon-reload
    cache_config "$prep" >&2
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
    # Publish REVISION only after code, configuration and the checked web build all exist.
    user "$prep" test -s /home/orbit/web/current/index.html
    printf '%s\n' "$sha" | user "$prep" tee "$checkout/REVISION.next" >/dev/null
    user "$prep" mv -T "$checkout/REVISION.next" "$checkout/REVISION"
    ctl candidate gateway "$operation" "$sha" "$digest"
    src swamp/scripts/gateway-release-seal.sh | enter "$candidate" /bin/sh -se
    ;;
  snapshot)
    if retained; then exit 0; fi
    user "$candidate" env ORBIT_GATEWAY_PHP="$runtime/php/8.5/bin/php" ORBIT_GATEWAY_DISK_CHECK="$home" ORBIT_GATEWAY_RELEASE_APP="$app" bash "$checkout/swamp/scripts/gateway-release-snapshot.sh" "$id"
    ;;
  migrate)
    if retained; then echo migrate-exit=0; exit; fi
    set +e
    output=$(artisan "$candidate" migrate --force --isolated --no-interaction 2>&1)
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
    # A changed environment needs a fresh cache and worker. Otherwise retain the
    # running worker after a warm rollback. The digest is private to the release.
    /usr/local/libexec/orbit-gateway-environment refresh
    config_digest=$(user "$candidate" sha256sum "$root/shared/gateway.env")
    cached=$(user "$candidate" cat "$root/.gateway-env-digest" 2>/dev/null || true)
    if [[ $config_digest != "$cached" || $previous_bind != "$(enter "$candidate" cat /etc/orbit/gateway-web/runtime.env)" ]] || ! enter "$candidate" systemctl is-active --quiet orbit-gateway-web.service; then
      cache_config "$candidate" >&2
      enter "$candidate" systemctl restart orbit-gateway-web.service
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
    ;;
  drain)
    previous=$(field '.live')
    prev=gateway-${previous:0:12}
    [[ $prev != "$candidate" ]] || exit 0
    horizon=$1
    if [[ -n $horizon ]] && unit_wanted "$horizon"; then enter "$prev" /bin/sh "$checkout/swamp/scripts/gateway-release-drain.sh" "$horizon"; fi
    ;;
  switch)
    expected=$1
    if [[ ${ORBIT_GATEWAY_INITIAL_MOVE:-0} == 1 && ! -f $inventory ]]; then
      previous=''
    else
      previous=$(field '.live | select(test("^[0-9a-f]{40}$"))')
    fi
    [[ ${previous:0:12} == "$expected" ]] || fail 'live changed after admission'
    generations=$(artisan "$candidate" gateway:release:cleanup --no-interaction | jq -ce .generations)
    if [[ $previous != "$sha" ]]; then
      prev=gateway-${previous:0:12}
      /usr/local/libexec/orbit-gateway-unit apply-candidate "$operation" "$sha" >&2
      private_ready
      install -d -m 0700 "$journal"
      [[ ! -e $journal/operation ]] || fail 'an interrupted switch needs recovery'
      prune_previous
      # Discard an uncommitted host fallback stage before reusing the journal.
      rm -f -- "$host_fallback"/{inventory,horizons,background,verified,verified.next}
      # Stage recovery before publishing the switch. The host adapter can recover even if power fails mid-switch.
      rollback_file=$(mktemp "$journal/.rollback.XXXXXXXX")
      if ! src bin/gateway-rollback > "$rollback_file"; then rm -f "$rollback_file"; fail 'missing recovery program'; fi
      mv -fT "$rollback_file" "$journal/rollback-$sha"
      if [[ $previous ]]; then
        cp -- "$inventory" "$journal/inventory"
        cp -- "$upstream" "$journal/upstream"
      else
        prev=host
        rm -f -- "$journal/inventory" "$journal/upstream"
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
      # Process controls use this same host lock. Record the source workers
      # before draining them, including the first move from PHP-FPM.
      printf '%s\n' "${2:-}" > "$journal/horizon"
      active_horizons "$prev" > "$journal/horizons"
      record_switch
      drain_active_horizons "$prev"
      if grep -qxF orbit-agent-view.service "$journal/background"; then
        enter "$candidate" systemctl start orbit-agent-view.service
      fi
      addr=$(ctl address gateway "$id")
      enter "$candidate" systemctl enable orbit-gateway-web.service
      "${incus_cli[@]}" config set "$candidate" boot.autostart=true
      set_upstream "$addr"
      set_inventory "$sha" "$previous"
      if ! /usr/local/libexec/orbit-gateway-site container >&2; then restore_switch; clear_switch; fail 'Caddy reload failed; restored previous live'; fi
    fi
    printf '%s\n' "$generations"
    ;;
  public-ready)
    check_release "$sha"
    ;;
  restore|switch-back)
    if [[ -f $host_fallback/inventory ]]; then
      [[ $action == restore && $(cat "$journal/operation") == "$operation" && $(cat "$journal/candidate") == "$sha" ]] ||
        fail 'there is no matching host rollback to recover'
      if [[ -f $host_fallback/verified ]]; then
        finish_host_fallback
      else
        reverse_host_fallback
      fi
      exit
    fi
    restore_switch
    enter "$candidate" systemctl stop orbit-gateway-web.service
    clear_switch
    if [[ $action == restore ]]; then exit 0; fi
    echo "gateway.release_switched_back: verification failed, previous Gateway is serving again" >&2
    exit 1
    ;;
  restore-host)
    [[ ! -f $journal/host-retired && $(field '.live') == "$sha" && $(jq -r '.old // ""' "$inventory") == "" ]] || fail 'there is a newer container release'
    [[ ! -e $journal/operation ]] || fail 'an interrupted switch needs recovery'
    host_ready
    install -d -m 0700 "$host_fallback"
    rm -f -- "$host_fallback"/{verified,verified.next}
    cp -- "$inventory" "$host_fallback/inventory"
    active_horizons "$candidate" > "$host_fallback/horizons"
    remember_background "$candidate" > "$host_fallback/background"
    record_switch
    drain_active_horizons "$candidate"
    /usr/local/libexec/orbit-gateway-site host >&2
    check_release "$sha"
    stop_background "$candidate" "$host_fallback/background"
    start_horizons host "$1"
    start_background host
    "${incus_cli[@]}" config set "$candidate" boot.autostart=false
    # Once the host has passed verification and taken over, recovery completes
    # cleanup instead of returning to the container.
    touch "$host_fallback/verified.next"
    sync -f "$journal" /etc/caddy
    mv -fT "$host_fallback/verified.next" "$host_fallback/verified"
    sync -f "$journal"
    finish_host_fallback
    ;;
  start-horizon)
    live=$(field '.live')
    start_horizons "gateway-${live:0:12}" "$1"
    install -d -m 0700 "$journal"
    printf '%s\n' "$1" > "$journal/horizon"
    ;;
  refresh)
    artisan "$candidate" instances:refresh-nested-fleets --commit="$sha" --no-interaction
    ;;
  cleanup)
    live=$(field '.live')
    artisan "gateway-${live:0:12}" gateway:release:cleanup --no-interaction "$1"
    ;;
  retire)
    [[ $(field '.live') == "$sha" ]] || fail 'candidate is no longer live'
    if [[ ! -f $journal/operation ]]; then
      # A host restart can fall between committing the handover and publishing its marker to the Gateway.
      if [[ -f $journal/host-retired ]] && ! jq -e '.host_retired == true' "$inventory" >/dev/null; then
        set_inventory "$sha" "$(field '.old')"
      fi
      exit 0
    fi
    [[ $(cat "$journal/operation") == "$operation" ]] || fail 'switch identity changed'
    prev=$(cat "$journal/machine")
    stop_background "$prev"
    start_background "$candidate"
    enter "$candidate" systemctl enable orbit-gateway-web.service
    "${incus_cli[@]}" config set "$candidate" boot.autostart=true
    if [[ $prev != host ]]; then
      drain_active_horizons "$prev"
      while IFS= read -r unit; do
        [[ -n $unit ]] || continue
        if run_on "$prev" systemctl cat -- "$unit" </dev/null >/dev/null 2>&1; then
          run_on "$prev" systemctl disable -- "$unit" </dev/null
        fi
      done < <(wanted_horizons "$(cat "$journal/horizon" 2>/dev/null || :)")
      "${incus_cli[@]}" config set "$prev" boot.autostart=false
      # A graceful unit stop waits for the FrankenPHP workers and their in-flight requests.
      enter "$prev" systemctl restart orbit-gateway-web.service
      previous_sha=$(owned_sha "$prev")
      check_release "$previous_sha" "$(ctl address gateway "${prev#gateway-}")"
      ctl retire gateway "${prev#gateway-}" "$operation"
    fi
    if [[ -f $journal/inventory ]]; then
      discarded=$(jq -er '.old // ""' "$journal/inventory")
      if [[ -n $discarded ]] && [[ $discarded != "$sha" ]] && [[ gateway-${discarded:0:12} != "$prev" ]]; then
        [[ $discarded =~ ^[a-f0-9]{40}$ ]] || fail 'invalid former rollback identity'
        printf '%s\n' "$discarded" > "$journal/pending-prune.next"
        mv -T "$journal/pending-prune.next" "$journal/pending-prune"
      fi
    fi
    cp "$journal/operation" "$journal/last-operation"
    if [[ $prev == host ]]; then
      sync -f "$home" /etc/caddy
      clear_switch
    else
      # This rename closes recovery of the switch and records that a verified container rollback exists.
      mv -fT "$journal/operation" "$journal/host-retired"
      if ! jq -e '.host_retired == true' "$inventory" >/dev/null; then set_inventory "$sha" "$(field '.old')"; fi
    fi
    ;;
  prune-pending)
    prune_previous
    ;;
  prune)
    [[ $(field '.live') == "$sha" ]] || fail 'candidate is no longer live'
    [[ -f $journal/last-operation && $(cat "$journal/last-operation") == "$operation" ]] || exit 0
    prune_previous
    ;;
  *)
    fail "unknown phase $action"
    ;;
esac
