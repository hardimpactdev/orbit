#!/usr/bin/env bash
# Orbit CI shard runners in unprivileged Incus containers. See "Self-hosted shard runners" in
# docs/reference/implementation-loop.md.
# First add the ci-runner role through Orbit; it owns the host project, profile, network and firewall.
# Run container operations on the host as a member of incus-admin with sudo. Every step is idempotent.
#
#   tools/ci-runners/setup.sh container NAME [AGENT SHA256] # optional source-built agent for rollout
#   tools/ci-runners/setup.sh user_manager NAME     # start the runner user manager without restarting a runner
#   tools/ci-runners/setup.sh register NAME [TOKEN]  # ORBIT_RUNNER_TOKEN in the environment, or TOKEN
#   tools/ci-runners/setup.sh actions NAME           # cache pinned actions; restart the service only when idle
#   tools/ci-runners/setup.sh hook NAME              # job-started hook that resets the checkout; restart only when idle
#   tools/ci-runners/setup.sh proof_hook             # the same hook for the orbit-proof runner on this host (sudo)
#   tools/ci-runners/setup.sh verify NAME            # egress and loopback probes from inside the container
#
# A registration token comes from `gh api -X POST orgs/hardimpactdev/actions/runners/registration-token --jq .token`
# and expires after an hour. The script never stores or prints it. Prefer ORBIT_RUNNER_TOKEN: an argument shows in
# the host's process list. The script hands the token to the container on stdin, but config.sh takes it as an
# argument, so it shows in the host's process list for the seconds that config.sh runs.
#
# `container` installs missing packages without upgrading installed ones, except the recipe's PHP build, which
# bin/php-build installs and moves to the recipe. It sets raw.apparmor on an existing container; a changed raw.apparmor
# applies at the next restart. Run it only while the runner is idle, from a checkout of main.
set -euo pipefail

REPOSITORY=$(cd "$(dirname "$0")/../.." && pwd)
PROJECT=orbit-ci
IMAGE=images:ubuntu/26.04
RUNNER_VERSION=2.338.0
RUNNER_SHA256=af4b794c1bc41d73d40535e3fe092a39f9679cd8d965954c2aca25a05ca41d32
DENO_VERSION=2.9.8
DENO_SHA256=e890975bc2549807bbcc0b584ffafda72cc7c93349dd99c80483dd784f6c4609
BUN_VERSION=1.4.2
BUN_SHA256=36368faef7527875d5ffa52e53cd48021741f2a83eb6208a8dd64068d422a913
COMPOSER_VERSION=2.10.3
COMPOSER_SHA256=7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6
REPO_URL=https://github.com/hardimpactdev
# The labels every hardimpactdev shard runner carries. A ci-sabre-* container also gets `sabre`.
LABELS=self-hosted,Linux,X64,hardimpact,orbit-shard
# systemd 259 (Ubuntu 26.04) hangs at boot in (sd-mkuserns) without nesting: AppArmor denies PID 1 and udevd
# signal/ptrace to container processes whose label is stacked (profile//&...). This lets the container signal its own
# stacked processes only; they stay confined by the container profile. Nesting stays off. Set per instance, because a
# profile key is also checked against the project's VM low-level restriction.
APPARMOR="signal peer=@{profile_name}//&*, ptrace peer=@{profile_name}//&*,"

in_ct() { incus exec "$1" --project "$PROJECT" -- "${@:2}"; }

container() {
  local name=$1
  # Compile locally, or verify the source-built artifact supplied by the rollout host.
  local source_agent=${2:-$REPOSITORY/apps/agent/target/release/orbit-agent}
  if [ "$#" -gt 1 ]; then
    [ "$#" -eq 3 ] && [[ "$3" =~ ^[0-9a-f]{64}$ ]] || { echo 'container NAME AGENT SHA256' >&2; return 2; }
    printf '%s  %s\n' "$3" "$source_agent" | sha256sum -c - >/dev/null
  else
    (cd "$REPOSITORY/apps/agent" && cargo build --release --locked)
  fi
  incus info "$name" --project "$PROJECT" >/dev/null 2>&1 || incus launch "$IMAGE" "$name" --project "$PROJECT" -c raw.apparmor="$APPARMOR"
  if [ "$(incus config get "$name" raw.apparmor --project "$PROJECT")" != "$APPARMOR" ]; then
    incus config set "$name" raw.apparmor="$APPARMOR" --project "$PROJECT"
    echo "$name: raw.apparmor repaired; restart the container while its runner is idle to apply it" >&2
  fi
  [ "$(incus list "$name" --project "$PROJECT" -c s -f csv)" = RUNNING ] || incus start "$name" --project "$PROJECT"
  for _ in $(seq 90); do in_ct "$name" getent hosts archive.ubuntu.com >/dev/null 2>&1 && break; sleep 1; done

  # The recipe's exact PHP build (ADR 0216), the same one that CI publishes caches for. A rerun moves it to the recipe.
  # Both local adapters call this checkout's native agent with typed pins.
  local tree=/root/orbit-php-build
  in_ct "$name" rm -rf "$tree"
  in_ct "$name" install -d -m 0700 "$tree/bin" "$tree/tools/ci-runners" "$tree/apps/gateway/resources/machine-software" "$tree/apps/agent/target/release"
  incus file push "$source_agent" "$name$tree/apps/agent/target/release/orbit-agent" --project "$PROJECT"
  in_ct "$name" chmod 0755 "$tree/apps/agent/target/release/orbit-agent"
  incus file push "$REPOSITORY/bin/php-build" "$name$tree/bin/php-build" --project "$PROJECT"
  incus file push "$REPOSITORY/apps/gateway/resources/machine-software/recipe.json" \
    "$name$tree/apps/gateway/resources/machine-software/recipe.json" --project "$PROJECT"
  incus file push "$REPOSITORY/tools/agent_command.py" "$name$tree/tools/agent_command.py" --project "$PROJECT"
  incus file push "$REPOSITORY/tools/ci-runners/javascript.py" "$name$tree/tools/ci-runners/javascript.py" --project "$PROJECT"
  incus file push "$REPOSITORY/apps/gateway/resources/machine-software/vite-plus-package-lock.json" \
    "$name$tree/apps/gateway/resources/machine-software/vite-plus-package-lock.json" --project "$PROJECT"
  incus file push "$REPOSITORY/tools/ci-runners/user-manager.sh" "$name$tree/tools/ci-runners/user-manager.sh" --project "$PROJECT"
  # Root runs the adapters, so root owns them.
  in_ct "$name" chown -R root:root "$tree"

  in_ct "$name" env RUNNER_VERSION="$RUNNER_VERSION" RUNNER_SHA256="$RUNNER_SHA256" \
    COMPOSER_VERSION="$COMPOSER_VERSION" COMPOSER_SHA256="$COMPOSER_SHA256" \
    DENO_VERSION="$DENO_VERSION" DENO_SHA256="$DENO_SHA256" BUN_VERSION="$BUN_VERSION" BUN_SHA256="$BUN_SHA256" bash -euo pipefail <<'SH'
export DEBIAN_FRONTEND=noninteractive
caddy_key=/usr/share/keyrings/caddy-stable-archive-keyring.gpg
if [ ! -s "$caddy_key" ]; then
  apt-get update -qq && apt-get install -y -qq --no-install-recommends curl ca-certificates gnupg >/dev/null
  curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --dearmor > /tmp/caddy.gpg
  gpg --show-keys --with-colons /tmp/caddy.gpg | grep -q '^fpr:::::::::65760C51EDEA2017CEA2CA15155B6D79CA56EA34:'
  install -m 0644 /tmp/caddy.gpg "$caddy_key" && rm /tmp/caddy.gpg
fi
echo "deb [signed-by=$caddy_key] https://dl.cloudsmith.io/public/caddy/stable/deb/debian any-version main" \
  > /etc/apt/sources.list.d/caddy-stable.list
apt-get update -qq
# actions/cache puts the compression method in the cache version: without zstd, every zstd entry from Sabre and
# hosted runners misses.
apt-get install -y -qq --no-install-recommends --no-upgrade \
  git curl ca-certificates jq unzip zip rsync sqlite3 python3 python3-venv openssh-client socat netcat-openbsd bind9-dnsutils \
  acl attr wireguard-tools openssl sudo dbus-user-session caddy tar gzip xz-utils zstd libkrb5-3 zlib1g gpgv >/dev/null
# Project checks run cargo fmt; APT verifies these exact packages against signed repository checksums.
apt-get install -y -qq --no-install-recommends --allow-downgrades cargo=1.93.1ubuntu1 rustfmt=1.93.1ubuntu1 >/dev/null
# Jobs start their own PostgreSQL cluster as runner; never create or start a system cluster.
apt-get install -y -qq --no-install-recommends --no-upgrade postgresql-common >/dev/null
cluster_config=/etc/postgresql-common/createcluster.conf
sed -i 's/^[#[:space:]]*create_main_cluster[[:space:]]*=.*/create_main_cluster = false/' "$cluster_config"
if ! grep -q '^create_main_cluster = false$' "$cluster_config"; then
  printf '\ncreate_main_cluster = false\n' >> "$cluster_config"
fi
apt-get install -y -qq --no-install-recommends --no-upgrade postgresql-18 postgresql-client-18 >/dev/null
systemctl disable --now postgresql >/dev/null
# A shard container runs only CI jobs, so it purges PHP packages and sources outside the recipe.
python3 /root/orbit-php-build/bin/php-build install --profile=ci-runner --purge-extras --no-composer >/dev/null
# The runner's own native dependencies (ICU, OpenSSL, Kerberos, zlib) come with the list above; its
# installdependencies.sh only knows libicu names up to 76.
systemctl disable --now caddy >/dev/null 2>&1 || true

if ! echo "${COMPOSER_SHA256}  /usr/local/bin/composer" | sha256sum -c - >/dev/null 2>&1; then
  curl -fsSLo /tmp/composer.phar "https://getcomposer.org/download/${COMPOSER_VERSION}/composer.phar"
  echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c - >/dev/null
  install -m 0755 /tmp/composer.phar /usr/local/bin/composer && rm /tmp/composer.phar
fi
# The Composer update program runs /usr/bin/composer, the path on Nodes, and its tests run that program.
ln -sfn /usr/local/bin/composer /usr/bin/composer

# The runner user owns nothing outside its home and has no sudo. Remove the image's default user if it has one.
id ubuntu >/dev/null 2>&1 && userdel -r ubuntu 2>/dev/null || true
id runner >/dev/null 2>&1 || useradd -m -U -s /bin/bash runner
# The image grants its default user passwordless sudo in 90-incus.
rm -f /etc/sudoers.d/runner /etc/sudoers.d/90-incus
install -d -o runner -g runner /home/runner/work
bash /root/orbit-php-build/tools/ci-runners/user-manager.sh

# These dedicated containers retire the distro Node stack before installing the recipe tools.
# Refuse a removal that reaches outside that stack; never autoremove unrelated packages.
if dpkg-query -W -f='${db:Status-Status}' nodejs 2>/dev/null | grep -qx installed; then
  mapfile -t retired_node < <(LC_ALL=C apt-get --simulate remove -- nodejs | awk '$1 == "Remv" {print $2}')
  [ "${#retired_node[@]}" -gt 0 ]
  for package in "${retired_node[@]}"; do
    [[ "$package" =~ ^(nodejs|node-[a-z0-9+-]+|libnode[0-9]+)$ ]] || { echo "Refuse Node removal: $package" >&2; exit 1; }
  done
  apt-get remove --yes -- "${retired_node[@]}" >/dev/null
fi
# Install and verify recipe Node, then retire its old source and provider files.
python3 /root/orbit-php-build/tools/ci-runners/javascript.py
# Verify the launcher from a directory that runner can traverse, against the recipe.
expected_node_version=$(python3 -c 'import json,sys; print("v" + json.load(open(sys.argv[1]))["tools"]["node"]["version"])' /root/orbit-php-build/apps/gateway/resources/machine-software/recipe.json)
actual_node_version=$(cd / && sudo -u runner -H /usr/local/bin/node --version)
if [ "$actual_node_version" != "$expected_node_version" ]; then
  echo "Runner Node version does not match the recipe: expected $expected_node_version, got $actual_node_version" >&2
  exit 1
fi
printf '%s\n' "$actual_node_version"

# Jobs use the managed tools without sudo or per-job toolchain installs.
if [ "$(deno --version 2>/dev/null | head -n 1)" != "deno $DENO_VERSION (stable, release, x86_64-unknown-linux-gnu)" ]; then
  tool_archive=$(mktemp)
  tool_directory=$(mktemp -d)
  curl -fsSLo "$tool_archive" "https://github.com/denoland/deno/releases/download/v${DENO_VERSION}/deno-x86_64-unknown-linux-gnu.zip"
  echo "${DENO_SHA256}  $tool_archive" | sha256sum -c - >/dev/null
  unzip -q "$tool_archive" -d "$tool_directory"
  install -m 0755 "$tool_directory/deno" /usr/local/bin/deno
  rm -rf "$tool_archive" "$tool_directory"
fi
if [ "$(bun --version 2>/dev/null)" != "$BUN_VERSION" ]; then
  tool_archive=$(mktemp)
  tool_directory=$(mktemp -d)
  curl -fsSLo "$tool_archive" "https://github.com/oven-sh/bun/releases/download/bun-v${BUN_VERSION}/bun-linux-x64.zip"
  echo "${BUN_SHA256}  $tool_archive" | sha256sum -c - >/dev/null
  unzip -q "$tool_archive" -d "$tool_directory"
  install -m 0755 "$tool_directory/bun-linux-x64/bun" /usr/local/bin/bun
  rm -rf "$tool_archive" "$tool_directory"
fi

if [ ! -x /home/runner/actions-runner/config.sh ]; then
  curl -fsSLo /tmp/runner.tgz "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-x64-${RUNNER_VERSION}.tar.gz"
  echo "${RUNNER_SHA256}  /tmp/runner.tgz" | sha256sum -c - >/dev/null
  install -d -o runner -g runner /home/runner/actions-runner
  tar -xzf /tmp/runner.tgz -C /home/runner/actions-runner && rm /tmp/runner.tgz
  chown -R runner:runner /home/runner/actions-runner
fi
SH
  actions "$name"
  hook "$name"
}

# GitHub images use this runner-native cache, which survives the per-job _actions cleanup:
# https://github.com/actions/runner-images/blob/main/images/ubuntu/scripts/build/install-actions-cache.sh
actions() (
  local name=$1 action sha archive scratch
  mkdir -p "$REPOSITORY/.orbit-artifacts/runner-actions"
  scratch=$(mktemp -d "$REPOSITORY/.orbit-artifacts/runner-actions/cache.XXXXXX")
  trap 'rm -rf "$scratch"' EXIT
  while read -r action sha <&3; do
    archive="/opt/actionarchivecache/${action//\//_}/$sha.tar.gz"
    if in_ct "$name" test -s "$archive"; then
      in_ct "$name" chown root:root "${archive%/*}" "$archive"
      continue
    fi
    git init --bare --quiet "$scratch/source"
    # Fetch the immutable commit over Git, never the rate-limited codeload archive endpoint.
    git -C "$scratch/source" fetch --quiet --depth=1 "https://github.com/$action.git" "$sha"
    [ "$(git -C "$scratch/source" rev-parse FETCH_HEAD)" = "$sha" ]
    git -C "$scratch/source" archive --format=tar --prefix=action/ "$sha" | gzip > "$scratch/action.tar.gz"
    in_ct "$name" install -d -m 0755 "${archive%/*}"
    incus file push "$scratch/action.tar.gz" "$name$archive.tmp" --project "$PROJECT" --quiet
    in_ct "$name" chown root:root "${archive%/*}" "$archive.tmp"
    in_ct "$name" chmod 0644 "$archive.tmp"
    in_ct "$name" mv "$archive.tmp" "$archive"
    rm -rf "$scratch/source"
  done 3< <(git -C "$REPOSITORY" grep -h 'uses: ' -- .github/workflows .github/actions |
    sed -nE 's/^[[:space:]-]*uses: ([^./ ][^/ ]*\/[^/@ ]+)(\/[^@ ]+)?@([0-9a-f]{40}).*/\1 \3/p' | sort -u)
  # shellcheck disable=SC2016
  in_ct "$name" bash -euo pipefail -c '
    env_file=/home/runner/actions-runner/.env
    touch "$env_file"
    sed -i "/^ACTIONS_RUNNER_ACTION_ARCHIVE_CACHE=/d" "$env_file"
    echo "ACTIONS_RUNNER_ACTION_ARCHIVE_CACHE=/opt/actionarchivecache" >> "$env_file"
    chown runner:runner "$env_file"
  '
  echo "$name: pinned actions cached; restart its runner service when idle to load the cache setting"
)

# The runner runs this before every job: it resets a reused checkout that a previous job left modified, which would
# make actions/checkout fail with "not uptodate". `container` installs it too; the service reads .env at start.
hook() {
  local name=$1 runner=/home/runner/actions-runner
  incus file push "$REPOSITORY/tools/ci-runners/job-started-hook.sh" "$name$runner/job-started-hook.sh" --project "$PROJECT" --quiet
  in_ct "$name" chown runner:runner "$runner/job-started-hook.sh"
  in_ct "$name" chmod 0755 "$runner/job-started-hook.sh"
  # shellcheck disable=SC2016
  in_ct "$name" bash -euo pipefail -c '
    env_file=/home/runner/actions-runner/.env
    touch "$env_file"
    sed -i "/^ACTIONS_RUNNER_HOOK_JOB_STARTED=/d" "$env_file"
    echo "ACTIONS_RUNNER_HOOK_JOB_STARTED=/home/runner/actions-runner/job-started-hook.sh" >> "$env_file"
    chown runner:runner "$env_file"
  '
  echo "$name: job-started hook installed; restart its runner service when idle to load it"
}

# The host-level proof runners (beast-orbit-proof, shark-orbit-proof) run as the system user orbit-proof outside Incus
# and reuse a checkout too. Run this on their host; it needs sudo, and it does not restart the service.
proof_hook() {
  local runner=${ORBIT_PROOF_RUNNER_DIR:-/var/lib/orbit-proof/actions-runner}
  # Root reads the operator checkout, which orbit-proof may not be able to traverse.
  sudo -n install -o orbit-proof -g orbit-proof -m 0755 "$REPOSITORY/tools/ci-runners/job-started-hook.sh" "$runner/job-started-hook.sh"
  sudo -n -u orbit-proof bash -euo pipefail -s "$runner" <<'SH'
env_file=$1/.env
touch "$env_file"
sed -i "/^ACTIONS_RUNNER_HOOK_JOB_STARTED=/d;/^ORBIT_RUNNER_WORK=/d" "$env_file"
echo "ACTIONS_RUNNER_HOOK_JOB_STARTED=$1/job-started-hook.sh" >> "$env_file"
echo "ORBIT_RUNNER_WORK=$1/_work" >> "$env_file"
SH
  echo "proof runner: job-started hook installed; restart its runner service when idle to load it"
}

register() {
  local name=$1 token=${2:-${ORBIT_RUNNER_TOKEN:-}} labels=$LABELS
  case $name in ci-sabre-*) labels=$labels,sabre ;; esac
  if ! in_ct "$name" test -f /home/runner/actions-runner/.runner; then
    [ -n "$token" ] || { echo 'register needs TOKEN or ORBIT_RUNNER_TOKEN' >&2; exit 2; }
    in_ct "$name" chown -R runner:runner /home/runner/actions-runner
    # The token reaches the container on stdin, not in the argv of incus. config.sh still takes it as an argument.
    local uid gid
    uid=$(in_ct "$name" id -u runner) gid=$(in_ct "$name" id -g runner)
    # The container shell expands its arguments after it reads the token.
    # shellcheck disable=SC2016
    printf '%s\n' "$token" | incus exec "$name" --project "$PROJECT" --user "$uid" --group "$gid" \
      --env HOME=/home/runner --cwd /home/runner/actions-runner -- \
      sh -c 'IFS= read -r token; exec ./config.sh --unattended --url "$1" --token "$token" --name "$2" --labels "$3" --runnergroup Default --work /home/runner/work --replace' \
      config "$REPO_URL" "$name" "$labels" >/dev/null
  fi
  in_ct "$name" bash -c "cd /home/runner/actions-runner && { [ -f .service ] || ./svc.sh install runner >/dev/null; } && ./svc.sh start >/dev/null && systemctl is-active \$(cat .service)"
}

user_manager() {
  local name=$1
  # Apply only this prerequisite to existing runners; no packages or listener restart.
  in_ct "$name" install -d -m 0700 /root/orbit-ci-prerequisites
  incus file push "$REPOSITORY/tools/ci-runners/user-manager.sh" "$name/root/orbit-ci-prerequisites/user-manager.sh" --project "$PROJECT"
  in_ct "$name" bash /root/orbit-ci-prerequisites/user-manager.sh
}

verify() {
  local name=$1 targets=() ip
  for ip in $(ip -4 -o addr show | awk '$2 != "lo" {split($4,a,"/"); print a[1]}'); do
    targets+=("$ip:22" "$ip:53" "$ip:2019" "$ip:5432" "$ip:6379" "$ip:443")
  done
  targets+=(10.44.0.2:443 192.168.6.1:80)
  in_ct "$name" su runner -c "
    probe() { timeout 3 bash -c \"exec 3<>/dev/tcp/\${1%:*}/\${1##*:}\" 2>/dev/null && echo \"OPEN    \$1\" || echo \"blocked \$1\"; }
    for t in ${targets[*]}; do probe \$t; done
    gw=\$(ip -4 route show default | awk '{print \$3}')
    probe \$gw:22; probe \$gw:2019
    echo 'note    port 53 on host addresses is a known gap: Incus accepts DNS from the bridge before the ACL' 
    getent hosts github.com >/dev/null && echo 'dns     ok' || echo 'dns     FAILED'
    curl -fsS -o /dev/null -w 'https   github.com %{http_code}\n' https://github.com
    timeout 3 curl -6 -fsS -o /dev/null https://github.com 2>/dev/null && echo 'OPEN    ipv6' || echo 'blocked ipv6'
    python3 -c 'import socket;s=socket.socket();s.bind((\"127.0.0.1\",2019));s.listen();c=socket.create_connection((\"127.0.0.1\",2019));print(\"own lo  127.0.0.1:2019 is the container (bound by the probe itself)\")'
    sudo -n true 2>/dev/null && echo 'SUDO    allowed' || echo 'sudo    denied'
  "
}

case "${1:-}" in
  container | actions | hook | proof_hook | register | user_manager | verify) "$@" ;;
  *) sed -n '2,19p' "$0" >&2; exit 2 ;;
esac
