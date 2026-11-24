---
title: "Instance containers"
description: "How development Instances and task copies run in unprivileged Incus containers cloned from a Project's default Instance, with networking, ACLs, egress, sleep/wake, and nested fleets."
covers:
  - apps/gateway/app/{Console/Commands/SyncKnownHostsCommand,Infrastructure/Ssh/KnownHostsStore,Infrastructure/Ssh/KnownHostsRepository,Models/ProofSeed,Domain/Proof/ProofSeeds,Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges,Console/Commands/RefreshNestedFleetsCommand,Console/Commands/InstanceIdMapSizeCommand,Console/Commands/InstanceInnerVolumeCommand,Console/Commands/InstancePhpIndexCommand,Domain/Tasks/TaskTurn,Domain/Tasks/TaskTurnProjection}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceAgentInstructions,InstanceContainer,InstanceEgress,DevelopmentMail,DevelopmentInstanceCreateProjection,ClonePublication,NestedFleetIntent,NestedFleetRefresh,PhpSymbolIndex,PhpSymbolIndexRefresh,IsolatedIdRanges,InstanceOperationAlerts,InstanceModelRoute}.php
  - apps/gateway/app/Domain/{InstanceMigration/**,Instances/Environment/DevelopmentCreateEnvironment.php,Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php}
  - apps/gateway/database/migrations/2027_02_{04_000000_add_seed_commit_to_instance_runtimes,04_000002_add_operation_owner_to_instance_runtimes,05_000001_clear_completed_container_default_projections,05_000002_clear_completed_container_default_projections_by_route_target,25_000000_create_proof_seeds,26_000000_add_ci_bridge_to_nodes,26_000001_release_an_unused_instance_bridge,27_000000_add_seed_sender_id_to_nodes,28_000000_allow_a_proof_copy_clone_on_its_seed_node}.php
  - swamp/{ansible/{instance-container.yml,instance_php_index.py,instance-container-destroy.yml,instance_node_runtime.py,instance_fleet_shrink.py,instance_fleet_runtime.py,tasks/instance-fleet-runtime.yml,instance_migrate_git.py,tasks/instance-node-runtime.yml,tasks/instance-container-phase.yml,tasks/instance-container-run-phase.yml},scripts/instance_clone{,_guest}.py,ansible/instance_{bridge,network,node_services}.py,extensions/behaviors/operations/instance-dev-command/result.ts,ansible/task-copy-turn.yml,workflows/workflow-operation-{task-copy-turn,instance-php-index}.yaml,extensions/behaviors/operations/task-copy-turn/*}
  - apps/gateway/{config/instances.php,database/migrations/{2027_02_03_000000_create_instance_runtimes,2027_02_11_000001_add_workspace_baseline_to_instance_runtimes,2027_02_12_000000_add_idle_watch_started_at_to_instance_runtimes,2027_02_15_000000_add_t3_port_last_to_nodes,2027_02_16_000000_add_idmap_size_to_instance_runtimes,2027_02_16_000001_add_id_ranges_to_nodes}.php,resources/instances/{notes/**,frankenphp.Caddyfile,workspace-git-read.py,id-map-read.py},app/Infrastructure/Instances/{ContainerWorkspaceRead,NodeIdMapRead}.php}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress,instance-network-check,instance-network-nic-check,instance-network-move,instance-network-move-nic,instance-bridge-rules,instance-bridge-refresh,instance-mail}.yml
  - {swamp/ansible/t3_copy_rpc.mjs,apps/gateway/app/{Domain/ConnServer/**,Models/ConnServerNode.php,Http/Requests/Fleet/AdmitConnServerRolloutRequest.php},apps/gateway/database/migrations/2027_03_25_000000_create_conn_server_nodes_table.php,swamp/{workflows/workflow-operation-fleet-conn-server-rollout.yaml,ansible/conn-server-rollout.yml,ansible/tasks/conn-server-*.yml,tests/workflow_conn_server_rollout_test.ts}}
---

# Instance containers

Every new development Instance and task copy runs inside an unprivileged Incus system container on its `app-dev` Node ([ADR 0250](/decisions/0250-run-instances-and-task-groups-in-incus-containers)). Non-default Instances are ZFS clones of their Project's default Instance seed snapshot, so clones start with dependencies and databases pre-built. Production releases use [Production containers](/reference/production-containers).

## What is built

All development container features are active on `app-dev` and CI proof Nodes. This covers host preparation, egress and ACLs, default creation, seed retakes, two-phase clone creation, forced non-default destroy, checkout migration, sleep and wake (Python helper and Rust executor), task copies, and nested-fleet proof hosts.

## Nodes

`ContainerNodes` qualifies an `app-dev` Node as a container host when its `instance_host` setting is enabled, it is a nested fleet Node, it already hosts a container runtime, or its agent reports `instance_bridge` health.

### Prepare a Node

`app-dev` role convergence (and `ci-runner` on `ciOnly` Nodes) prepares the Incus host:

- Verifies the `orbit-instances` ZFS pool (or creates a btrfs pool on `nested-app-dev`).
- Allocates bridge `orbitinst0` (a `/24` from `10.253.0.0/16` avoiding `orbitci0` and VPN; `.1` gateway, `.2`–`.253` guests, `.254` image builder; `raw.dnsmasq=port=0`).
- Installs bridge ACL `orbitinst0-egress`, NIC ACL `orbitinst0-proxy`, Squid egress proxy on `<gateway>:3128`, the bridge refresh timer (every 15 s under `/var/lib/orbit/instance-bridge/writer.lock`, writing `/var/lib/orbit/instance-bridge/health.json`), and shared folders.
- Installs `/usr/local/libexec/orbit-instance-power` and fast-wake units, and builds the stock (and optional nested-fleet) container images.

### Admission

### Container-only admission

New development Instances always admit as containers; `instance:register` refuses new host-checkout registrations. Existing host checkouts remain supported until migrated via [`instance:migrate`](/cli/instance/migrate).

### Existing instances

Converging an `app-dev` Node leaves existing host-checkout Instances untouched and skips containers deleted concurrently during a converge walk.

## Records

`InstanceRuntime` stores each container's Incus project (`orbit-instances` or `orbit-instances-nested`), container name (`instance-<id>`), IPv4 address, T3 port (allocated from `3800`–`3999` via `nodes.t3_port_last`), `seed_commit`, `idmap_size`, `power_state`, `idle_watch_started_at`, and `workspace_baseline`.

## Container environment

Each unprivileged container receives 4 CPUs, 4,096 processes, 40 GiB root disk (80 GiB default for nested-fleet defaults via `ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE`), 8 GiB RAM (12 GiB nested), `hidepid=invisible`, and `user.max_user_namespaces=0` (non-nested).

### Image packages

The container image adds `acl`, `git`, `nftables`, `sudo`, and `bind9-dnsutils` (`dig`, which the Swamp fixtures run, as CI runners have it) to the stock Ubuntu image. A nested-fleet image adds `wireguard-tools`, `sqlite3`, `zstd`, and `btrfs-progs`. A change to the list is a new image alias that a Node converge builds.

### Isolated ID ranges

`IsolatedIdRanges` assigns each container an isolated block of subordinate UIDs and GIDs on the host (`65,536` IDs for normal containers) so two containers never share host UIDs.

#### The ID map size

Containers that run a nested fleet require larger ID maps (`2,000,000` by default, or `1,165,536` for converted defaults). Adjust a default's map size on the Gateway with `php artisan gateway:instance-idmap-size <id> <2000000|1165536>` (other values refuse with `instance.idmap_size_invalid`).

### Users

Each container has two application accounts:

- `www-data` (`33:33`): Runs FrankenPHP and Instance Processes.
- `agent` (`2750:2750`): Member of `www-data` with passwordless sudo (`/etc/sudoers.d/orbit-agent`), owns `/srv/workspace` (mode `2770`, default ACL `g:www-data:rwX`).

### Shared folders

Incus mounts two recipe folders built on the host read-only into every container (`/opt/orbit-runtime` and `/opt/orbit-agent`, idmapped on real hosts and `shift=false` on third-level fleet Nodes).

### The pinned shape tools

The shared folders supply pinned static PHP, Composer, Vite+, Node.js, pnpm, Bun, ripgrep, `laravel-lsp`, `phpactor`, `t3`, `conn-server`, `claude-code`, `codex`, `antigravity`, `chrome-headless-shell`, and `agent-browser` without installing copies inside each container.

#### Laravel language server

The recipe pins the official [laravel/lsp](https://github.com/laravel/lsp) PHAR (`laravel-lsp` `0.0.32`) as a part of the runtime folder, so every container, a clone of a Laravel default included, has `laravel-lsp` on its `PATH` with nothing installed per clone. It runs on the container's `php`, the Project's PHP version, and boots the application from its own `vendor/`. No unit starts it: a client starts one server over stdio, as `agent`, when it needs one, and the server loads framework data on demand.

The server takes the folder with `artisan` as its root URI. `laravel-lsp-root [path]`, a program of the same folder, prints that folder. It takes the first of these:

1. The application folder that a Laravel clone's `/etc/orbit/instance` names in `ORBIT_APPLICATION`: the Project root without `public`.
2. The nearest folder at or above `path` with `artisan`. The working folder is the default `path`.
3. The one folder at any depth below `path` with `artisan`, skipping `vendor`, `node_modules`, and `.git`.

It exits `1` when it finds none, or two with nothing to pick one. The Project holds no Orbit setting for it.

#### PHP symbol index

The recipe pins the [Phpactor](https://github.com/phpactor/phpactor) PHAR (`phpactor` `2026.11.22.0`, by SHA-256) as a part of the runtime folder, as it pins `laravel-lsp`. A Laravel default prepares Phpactor's index of its PHP classes before each seed, so a clone's first class lookup reads an index that is already built. The PHP symbol index and the [Laravel language server](#laravel-language-server) keep separate caches: `laravel-lsp` builds no index on disk and loads its framework data on demand.

Only the Project's `default`, of a Laravel source, on a Node whose one app role is an active `app-dev`, gets the work: its container intent has `php_index` (`PhpSymbolIndex`). A clone, a non-Laravel source, a production Instance, an `app-prod` Node, a Node with no app role, such as a CI Node, and an `app-dev` role that is not active get none. The recipe marks `phpactor` and `laravel-lsp` `dev_only`, so an `app-prod` Node's [production runtime folder](/reference/production-containers#the-pinned-runtime) has neither PHAR, their programs or `laravel-lsp-root`.

The `php_index` phase runs right before the `seed` phase: in the create's seed step, and in "Finish the container" of each deployment, after the checkout, the deploy steps (Composer's install and any generated PHP among them) and the `processes` phase. `agent` runs `phpactor index:build` at the application's folder, the one with `artisan` that `laravel-lsp-root` prints, with the deploy steps' environment. Orbit passes Phpactor no setting and writes no file in the Project.

Phpactor finds its index by itself. The folder it is started in, or the root URI its language server gets, is its project root, and its index is `~/.cache/phpactor/index/<folder name>-<first 6 hex of the MD5 of the folder's path>`. A clone has the same home and application path as its default, so a fresh Phpactor in the clone finds the seed's index: `phpactor index:build`, or a language-server session started at `laravel-lsp-root`, reindexes only the files that changed since the seed.

The run is incremental and bounded:

- Phpactor reindexes a PHP file whose ctime is not older than the index's last update. A build on an index that exists reads only what changed, and nothing resets it.
- The run records a fingerprint in `~/.cache/orbit-php-index.json`. It covers the `phpactor` and `php` paths, the folder, and the index's timestamp files.
- A new fingerprint waits in `~/.cache/orbit-php-index.pending.json` until the seed phase has taken the seed that holds the index. After a failed snapshot it stays pending, so the next run builds again and retakes the seed.
- The fingerprint also covers each PHP file and folder below the application's folder, with its ctime and size. `.git` is left out.
- An unchanged fingerprint starts no Phpactor, as at a repeat refresh of the same commit.
- The build runs at `nice -n 19` and `ionice -c 3`, as one Phpactor process, within 600 s (`PhpSymbolIndex::Seconds`).
- The step's bound holds 630 s for the phase: the 600 s and 30 s for the exec. Only an intent with `php_index` adds the 630 s to the run's budget.
- No unit, timer or daemon runs Phpactor. A clone's boot starts nothing.

A build that fails or runs past its bound fails the phase with `instance.php_index_failed`. Its detail names Phpactor's exit code or the bound, with the end of Phpactor's output. The seed is not taken, so the old seed stays, and the run records no fingerprint, so the next deployment builds again. Orbit fails the deployment rather than warn: a seed whose index is missing or half built would hand every clone a lookup that is silently wrong.

A Node whose runtime folder predates Phpactor skips the work rather than fail the run. The intent's `php_index` names that folder (`/opt/orbit-runtime/current`), and the phase skips only when it has neither the `phpactor` part folder nor its `bin/phpactor` link. The phase then starts nothing and records nothing, and the run's result names the reason in `php_index_skipped`. The seed is taken as without an index. A missing `php` always fails, and so does a runtime folder that ships Phpactor whose `bin/phpactor` is missing or not executable, with `instance.php_index_failed`. This is the [rollout window](#prepare-the-index-of-a-running-default) between a release and the Node's converge.

The index needs no Composer install. A default without `vendor/` gets an index of its own source, and a later `composer install` adds the packages at the next build, incrementally.

A legacy host checkout, such as a default that `instance:migrate` has not moved into a container, has no seed and no `php_index` phase. Its clones get no inherited index until it is a container default.

#### Prepare the index of a running default

A deployment is not the way to add the index to a default that already runs. Its `checkout` phase removes every untracked file that Git does not ignore from the default's tree (`instance_default_clean.py`), also at the same commit. `instance.php_index` instead runs only the container's `php_index` and `seed` phases in one play (`PhpSymbolIndexRefresh`, workflow `operation-instance-php-index`). It runs no fetch, checkout, clean, reset or deploy step, so the working tree, its untracked files included, stays as it is. The seed it may retake holds the clones' [agent folder](#agent-instructions), so admission reads the Project's agent instructions before its transaction, which cannot read document bodies.

The intent carries the seed that the Gateway records for clones, `seed_commit`. The `php_index` phase first requires that the container's HEAD is that commit. A default whose HEAD moved past it, as after a deployment that failed after its checkout, is refused with `instance.php_index_seed_moved` before any build or snapshot. Its seed phase would otherwise take `seed-<HEAD>` and delete the seed that clones copy. The next passed deployment moves the HEAD and the seed together.

A commit can also land in the running default during the build. So the seed phase checks again, right before it creates or deletes any snapshot, that the commit it names the seed after is `seed_commit`. Otherwise it fails with `instance.php_index_seed_moved` and changes no snapshot.

The seed phase retakes `seed-<HEAD>` only when the index changed. A second run on an unchanged tree starts no Phpactor and takes no snapshot. The retake stops and starts the default's Processes around the snapshot, as a deployment's seed does. The Operation's budget is 900 s and its step's bound 750 s.

Admission refuses each of these with its code:

- An Instance that gets no index (`instance.php_index_ineligible`): not a Laravel default, or not on an active `app-dev` Node.
- A host checkout with no container (`operation.kind_unavailable`).
- A default with no seed yet or with an unfinished Operation (`instance.lifecycle_busy`).
- A stopped container (`process.target_inactive`): the run never starts one.
- A running default whose HEAD is not its recorded seed (`instance.php_index_seed_moved`), refused by the run before it touches anything.
- A nested-fleet or proof default in the nested Incus project (`instance.php_index_unsupported`). Only its deployment builds its index, with the rules its seed needs.

To prepare the index in existing Laravel defaults:

1. Right after the Gateway release, before any deployment, converge each `app-dev` Node: `orbit node:converge <node>`. Its runtime folder then holds `phpactor`.
2. On the Gateway host, admit the runs: `php artisan instances:php-index <instance-id> [<instance-id>...]`.

Between the release and a Node's converge, the release's intents already carry `php_index` while the Node's runtime folder has no `phpactor`. A Laravel default deployment on that Node passes and takes its seed without an index, and its result has `php_index_skipped`. So does an `instances:php-index` run, which then retakes no seed. Run it again after the converge.

The command prints one JSON line per Instance: `{"instance":<id>,"operation":<id>}` when admitted, or `{"instance":<id>,"skipped":"<code>","reason":"..."}`, and exits `1` when any was skipped. Follow each Operation with `orbit operation:show <id>`. A failed build ends it with `instance.php_index_failed`, and the old seed stays.

### PHP

Containers serve web traffic with FrankenPHP (`orbit-frankenphp.service`, port `8080`) using the shared static PHP build and trusting only the Node's proxy headers.

### Processes

Instance Processes run as `orbit-*` systemd units inside the container ordered after `network.target`.

### Trust

Clone preparation installs the Orbit root CA from `/etc/orbit/agent/ca.pem` into the container's system trust bundle and Chromium's p11-kit NSS store.

### Agent instructions

`InstanceAgentInstructions` renders up to 32 files (32 KiB total) into the container's instruction folder owned by root, alongside an empty `.skills` directory.

### The machine name

Each container writes its presented machine name into `/etc/hostname`, `/etc/machine-info`, and `/etc/hosts` (`127.0.1.1`).

## Network, egress, and services

### Network

Every container's `eth0` enforces `security.ipv4_filtering=true`, `security.mac_filtering=true`, the shared `orbitinst0-proxy` NIC ACL, and a per-container `instance-<id>` default-reject ACL. Host-level UFW rules under `orbit:instance-<id>-` expose the container's T3 proxy port over WireGuard and allow attached database servers.

### Private names of the access plan

Container `/etc/hosts` includes private hostnames from the Instance's access plan so local services resolve without exposing VPN DNS inside the container.

### Egress and DNS

Containers have no direct DNS (`raw.dnsmasq=port=0`). All outbound HTTP/HTTPS goes through the Node's Squid egress proxy (`<gateway>:3128`). Host nftables table `orbit_instance_egress` rejects all private/special IPv4, WireGuard, local addresses, and IPv6, admitting only `127.0.0.53:53` (and parent proxy on nested fleet Nodes).

### Model access

Containers reach AI models through the Node's [container model forwarder](/reference/ai-router#container-model-forwarder) at `<gateway>:8319`. Inside the container, nftables restricts `:8319` to uid `2750` (`agent`) and `:3773` to uid `0`/`2750`.

#### Antigravity

Antigravity (`agy`) uses the Gemini routes on the model forwarder with `X-Goog-Api-Key` and the placeholder key rendered into the container's settings.

### Agent development servers

Host nftables table `orbit_instance_dev_ports` redirects WireGuard TCP ports `1024`–`65535` (excluding host-bound ports) to Caddy `:444` so developers can reach dev servers running inside containers.

### The own site from inside

Containers reach their own Route from inside via `<gateway>:443` and dev-server band `5173`–`5199` -> `:444` (`instance.dev_band_reserved` protects that band).

### Development mail

Loopback socket proxies on `127.0.0.1:1025` and `127.0.0.1:2525` forward container mail to the Node's Mailpit (`instances.mailpit`, default `mailpit:1025`).

## Container server and T3

### Container server

Projects select `container_server` (`conn` by default, or `t3`; task copies always use `conn`). The recipe pins `conn-server` `0.5.1` (keeping the T3 server `0.1.10` contract and `chrome-headless-shell` `157.0.8103.78`), with its Linux tarball and its [macOS arm64 zip](#conn-server-pin). `/etc/orbit/instance` gates `t3.service` so default Instances never boot T3.

#### Conn Server pin

The agent folder part `conn-server` in `apps/gateway/resources/machine-software/recipe.json` pins one Conn release, `conn-server-v<X>`, as two assets:

- `artifacts.amd64`: `conn-server-<X>-linux-x64.tar.gz` and its SHA-256. Container hosts unpack it into the agent folder and link `bin/conn-server` to `conn-server-<X>-linux-x64/conn-server`.
- `macos_arm64`, optional: `conn-server-<X>-macos-arm64.zip` and its SHA-256. It also names the signature that its `Conn Server.app` must carry: `bundle_id` (`com.nckrtl.conn-server`) and `team_id`, the Developer ID team (`9SVJ4SYB9B`).

The bundle is signed with the hardened runtime and is not notarized. Releases before 0.5.0 have no zip, so their pin leaves `macos_arm64` out.

```json
"conn-server": {
    "version": "<X>",
    "artifacts": {"amd64": {"url": "https://github.com/nckrtl/conn/releases/download/conn-server-v<X>/conn-server-<X>-linux-x64.tar.gz", "sha256": "…"}},
    "bin": {"conn-server": "conn-server-<X>-linux-x64/conn-server"},
    "macos_arm64": {
        "url": "https://github.com/nckrtl/conn/releases/download/conn-server-v<X>/conn-server-<X>-macos-arm64.zip",
        "sha256": "…",
        "bundle_id": "com.nckrtl.conn-server",
        "team_id": "9SVJ4SYB9B"
    }
}
```

The recipe loader accepts `macos_arm64` on agent folder parts only and requires the release's exact zip name. Container hosts never read it: the agent folder plan, and so the folder's version, has the Linux tarball alone. The Conn Server rollout to Mac Nodes reads the zip, its SHA-256 and the signing facts from this pin. A change to the pin changes the recipe digest, so a pin bump also updates the hashes in `apps/gateway/tests/Fixtures/production-gateway-converge-main.json`.

#### Conn Server rollout

`POST /api/v1/fleet/conn-server/rollout` (body `{"dry_run": true|false, "scope": "canary"|"fleet"|"nodes", "node_ids": [...]}`; `dry_run` and `scope` are required, and `node_ids` comes with scope `nodes` alone) starts `fleet.conn_server_rollout`: Swamp workflow `operation-fleet-conn-server-rollout` rolls the current pin out to the Nodes that run a Conn Server outside containers. Container Conn Servers are out of scope; their image pins the server. Nothing starts the rollout from a pin change.

Each such Node has a `conn_server_nodes` record: `platform` (`mac` or `linux`), `path` (the `.app` bundle, which may start with `~/`, or the Linux `current` link), `service` (the LaunchAgent label or the systemd unit), `port` (of `/health`), `user` (the user that runs it; on a Mac, the SSH user), `canary` and `notify_before_restart`.

Scope `canary` reaches the canary alone; `fleet` reaches the canary, then the other Nodes. Scope `nodes` reaches the Nodes that `node_ids` names, one to four, in the request's order; it adds no canary and puts none first. An id without a record, a repeated id, or `node_ids` with another scope is refused with a validation error. A request without a scope is refused with a validation error, so no default reaches the whole fleet. The Gateway refuses a pin without `macos_arm64` (`conn_server.pin_unavailable`) and a fleet without exactly one canary (`conn_server.canary_unset`).

The run is one Ansible phase, `conn-server-rollout.yml`, that visits the admitted Nodes one at a time, in the admitted order. A failed Node, or a canary that holds, stops the run; later Nodes report `not_reached`. The phase's deadline, 8,100 s, covers at most four Nodes of 2,000 s each: every Node's worst case, the sum of its task timeouts through a failed start and its rollback, with the health wait bounded by time. So the deadline never cuts a rollback. A run with more Nodes fails before it reaches one (`conn_server.too_many_nodes`).

On a Mac, the checks come first. The phase:

1. downloads the zip into a staging folder beside the bundle and checks its SHA-256 (`conn_server.hash_mismatch`);
2. checks `codesign --verify --strict --deep` (`conn_server.signature_invalid`), the pin's `TeamIdentifier` (`conn_server.team_mismatch`) and bundle id (`conn_server.bundle_mismatch`);
3. compares `codesign -dr -` of the staged and the installed bundle. A difference holds the Node (`held`, `designated_requirement_changed`) and installs nothing, because the TCC grants would reset;
4. stops here in a dry run (`checked`).

Then the swap and the restart. The phase:

5. sends the notice, when the record asks for one;
6. makes a new backup folder for the run. `mkdir` refuses one that exists (`conn_server.backup_exists`).
7. moves the installed bundle into the backup folder, and the staged bundle onto the same path. The TCC grants stay.
8. runs `launchctl kickstart -k gui/<uid>/<label>`, never a start from the SSH session itself;
9. reads a pending `UserNotificationCenter` prompt through System Events in each round of the health wait.
10. clicks **Allow** only on a prompt that names Conn Server and an expected permission. Any other prompt fails the Node (`conn_server.unexpected_prompt`).
11. waits up to about 3 minutes for `/health` to answer `server: conn-server` and the pin's version. No round starts after 180 s.

From the move into the backup on, any failure before `/health` answers puts the backup back on the path and kickstarts again. That covers a failed move, a failed or timed-out kickstart or **Allow** click, and a `/health` that never names the version. A move that renamed the bundle and then failed or timed out counts too. The Node fails with its cause, `conn_server.health_check_failed` when `/health` never names the version, and `rolled_back` is true when the old version answers.

Every command has a task timeout, because a pending prompt blocks `open()`. The run never reads or prints a LaunchAgent plist. Before any change, and in a dry run too, a Linux Node checks the layout the flow assumes: the unit's `ExecStart` program is `<current>/conn-server`, and the tarball holds `conn-server-<X>-linux-x64/conn-server`. Any other layout fails the Node with `conn_server.layout_mismatch`, and nothing is installed.

A Linux Node then reads `/health` on the address its unit listens on: `--host=<addr>` or `--host <addr>` in the `ExecStart` argv, or 127.0.0.1 when there is none or it is `0.0.0.0` or `::`. A Mac reads it on 127.0.0.1. Every Linux `/health` read uses that address, never through a proxy. A read that gets no answer fails the Node with `conn_server.health_unreachable`, before any change.

A Linux Node makes the `releases` folder beside `current` when it does not exist yet, as on a Node set up before this rollout; a failure to make it is `conn_server.step_failed`. It unpacks the tarball into a new `releases/<X>-<run>` folder, which `mkdir` without `-p` refuses when it exists (`conn_server.backup_exists`), moves `current` to it with one rename, restarts the unit and checks `/health` in the same way. Any failure from the relink until `/health` answers points `current` back and restarts again.

The notice before a restart is `orbit.conn.server_rollout_notice`: `ORBIT_CONN_SERVER_ROLLOUT_NOTICE_URL` gets a JSON POST of `node_id`, `version` and `event`, and `ORBIT_CONN_SERVER_ROLLOUT_NOTICE_COMMAND` runs on the Swamp worker with the Node id and the version as its last arguments. With neither set, no notice is sent. A failed notice fails the Node with `conn_server.notice_failed` before anything changes. The URL or the command may hold a token, so the phase logs neither, nor their output, on success or failure.

The workflow runs the phase with `-i localhost,`, so `localhost` is an inventory host that would take the ssh connection. The tasks that the phase delegates to the Swamp worker, each Node's result and the notice, therefore set a local connection; a test runs the phase this way, over the ssh connection plugin.

The result names the `version`, `dry_run`, and each Node's `outcome` (`updated`, `checked`, `held`, `failed` or `not_reached`) with its `reason`. A failed run keeps its `conn_server.*` code; a run that names none fails with `conn_server.rollout_failed`.

#### Switching an existing T3 container to Conn

An existing container runtime that records `t3` can switch one way to `conn` through [`instance:server:switch`](/cli/instance/server-switch) (`POST /api/v1/instances/{instance}/server-switch`, MCP `instance-server-switch`). Both servers use `/home/agent/.t3/userdata`. On its first start, when `engine.sqlite` is absent beside T3's `statev2.sqlite`, the Conn Server imports every project, thread, message, session, and pairing in one transaction while opening `statev2.sqlite` read-only and leaving it unchanged. No Instance, container, database, or Route is removed or recreated.

**Running container.** Before admitting `instance.server_switch`, the Gateway checks that no agent turn, pending runtime request, held reservation, or active task is running (`instance.container_server_busy`, 409). Right before writing `/etc/systemd/system/t3.service.d/20-orbit-server.conf` and restarting `t3.service`, Swamp re-checks `t3.service` processes with `t3-service-processes.j2` and refuses `instance.container_server_busy` if a terminal or command is active. After the restart, it waits up to 600 seconds for `/.well-known/t3/environment`, because the Conn Server listens only once its import has finished. That takes minutes on a large home.

It then checks that the answer reports the `serverVersion` that `bin/conn-server --version` states, and the Gateway records `container_server = conn` and updates `conn_environments.server_version`. A run can fail after it has written the drop-in (`instance.t3_unready`, `instance.container_server_version_mismatch`). Its result then names `container_server: conn`, and the Gateway records `conn` too, so the record and the unit agree. Nothing restarts the import, and a retry is refused.

**Stopped container.** A stopped container records `container_server = conn` immediately (`switch: deferred`) and writes `20-orbit-server.conf` through the Incus file API before boot on its next [`instance:wake`](/cli/instance/wake). Every wake path that boots writes the recorded drop-in first. The wake reads the container's real state just before the boot, so this covers a stopped container and a frozen one whose silent resume was force-stopped (`restarted_after_resume`). A frozen container that resumes takes the workflow wake path, updates `20-orbit-server.conf`, and restarts `t3.service`.

A wake that applies the switch waits up to 600 seconds for the Conn Server's import before T3's usual 30-second wait (`server_import_seconds`). Every wake of a clone then checks that the reported `serverVersion` matches the recorded server's `--version`. If it does not, the wake fails with `instance.container_server_version_mismatch` and the switch stays pending for the next wake. When the versions match, the Gateway updates `conn_environments.server_version`. Clients keep their URL and pairings.

**Refusals and one-way rule.** A serverless runtime refuses with `instance.container_server_none` (409) and a `conn` runtime refuses with `instance.container_server_already_conn` (409). Trigger `instance_runtimes_container_server_update` allows only `t3` to `conn` and aborts `conn` to `t3` or any change on a serverless runtime. The `projects.container_server` column default is `conn`; existing Project rows keep their stored value.

### T3 registration and pairing

Clone creation starts T3 on its allocated host proxy port, creates the workspace project, registers the server with [Conn](/reference/conn#instance-servers), and returns sealed Gateway and Swamp sessions. On a Conn Server the Gateway session is issued with `--ttl 3650d`, so it is not renewed ([Session lifetimes](/reference/conn#session-lifetimes)). For a [reserved clone](/reference/conn#reserve-a-clone), when the agent supports `instance.t3_pairing`, the engine create's `t3` step also issues and seals the reserving device's pairing link (a refused link never fails the create).

### Threads

Developers interact with a clone's T3 server directly through Conn; task copies run turns via Swamp.

### Conn launch transport

[Durable Conn launches](/reference/conn#durable-launches-v1) wake or create the container, verify T3 readiness, and deliver the initial prompt over the sealed session.

### Swamp drives T3

`operation-task-copy-turn` drives container T3 turns using `swamp/ansible/t3_copy_rpc.mjs`.

### Turn model and effort

Swamp passes the resolved `model` and `effort` ([Copy turn agents](/reference/tasks#copy-turn-agents)) on each turn request.

## Lifecycle

### The default Instance

Each Project has one default container Instance per `app-dev` Node, built from scratch and automatically deployed on each default-branch merge to keep its `seed-<commit>` snapshot current.

#### A retake keeps the seed

Every passing default deploy snapshots `next-<commit>` and atomically promotes it to `seed-<commit>` (retaining `base-<commit>` for nested fleets) so in-flight clones never see a half-written seed.

#### The default keeps a clean tree

Before deploy steps and the seed snapshot, a default deployment removes untracked non-ignored files (keeping `.env` and tracked/ignored files) and records `workspace_cleaned` (`removed_count`, first 20 `removed` names, `left_count`).

#### The seed manifest

Right before snapshotting `seed-<commit>`, the default writes shared clone files (`seed_files`) and records `/etc/orbit/seed-manifest.json` (`files`, `links`, `folders`, `bundles`, `nss`, `units`, `workspace_clean`, `services_digest`). Clone preparation reads the manifest and writes only what differs; task copies skip workspace cleaning when `workspace_clean` is true and the commit matches the seed.

#### A completion with nothing left

When `instance.create` recorded `services: seed` (matching `services_digest`), [`instance.complete`](/reference/gateway-engine#instancecomplete) can end immediately on the Gateway engine with zero steps and move the clone to `active`. This requires no remaining work on the Node: no SQLite or server database fill, no Valkey ACL user, no deferred Route to publish, no custom Processes, and no nested fleet start. Otherwise completion runs on Swamp.

#### Spare volumes

Nested-fleet defaults keep a spare inner volume ready so `instance.create` can claim it via `copy_volume` while `spare` prepares the next one in parallel.

#### The seed hold

`instance_seed:<default-id>` serializes default seed retakes against CI seed copies (`instance.seed_copy`) without blocking ordinary clone reads.

#### Convert a default

Existing host-checkout defaults move to containers via [`instance:migrate`](/cli/instance/migrate), which preserves local Git state and re-seeds the container default.

### Create

Non-default container creation (`instance.create`) clones the default's `seed-<commit>` snapshot, sets up ACLs, NICs, T3 proxy, and branch checkout, and hands off to `instance.complete`.

#### The address during the create

`ClonePublication` publishes the clone's Route during `instance.create` when target Nodes are free; otherwise it defers Route publication to `instance.complete` with `route_publication_reason` (`nodes_held`, `share_cap`, `waiter_first`, `node_refused`, or `engine_completion`).

#### The environment of a clone

Clone checkout merges the default seed's `.env` with the Gateway's rendered clone variables, replacing default hostnames and dropping default connection credentials while adding `.env` to `.git/info/exclude`.

#### A clone's Valkey user

Each clone attached to a Valkey Database server receives its own scoped ACL user prefixed `orbit_instance_<id>_*`.

### Complete a clone

`instance.complete` finishes database fills and Route publication (if deferred), then transitions the clone from `source_resolved` to `active`.

### A clone starts its fleet

Nested-fleet clones start their inner fleet and operator tunnel at boot in parallel with `instance.complete` via the lockless [`instance.fleet`](/reference/gateway-engine#instancefleet) Operation, recording `fleet_ready_at`, `fleet_failed_at`, and `fleet_error_code` on the Instance. Destroying or sleeping the clone cancels an open `instance.fleet` wait.

### Workspace work state

The Gateway inspects container Git state (`ContainerWorkspaceRead`, `workspace-git-read.py`) over pinned SSH without running repository code to detect unpushed commits or dirty files before destroy or cancel.

### Destroy

`instance:destroy <id> --yes --force` deletes the container, its `instance-<id>` ACL, UFW `orbit:instance-<id>-` rules, repository mirror (`/var/lib/orbit/instance-repositories/<id>.git`), power helper files, and Route. Container removal currently requires `--force`; omitting it returns HTTP `409 instance.container_destroy_force_required`.

Destroying an active default container Instance requires the Project ID to be listed in `ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS` (`503 operation.kind_unavailable` otherwise) and zero clones copying that default (`409 instance.default_has_copies` otherwise). A retained failed or cancelled default (`reserved` or `source_resolved`) can be destroyed with `--force` without listing the Project once no `instance.create` still holds its lock, including a timed-out create whose run has not settled.

### Migrate a checkout Instance

`instance:migrate` moves an existing host-checkout development Instance into a container while preserving branches, stashes, staging, untracked files, and verified file hashes.

#### Migration symlinks

Migration validates all symlinks before downtime using the same resolver as the final copied tree, refusing unsafe external links.

### Tasks in an Instance container

Task copies run inside standard development containers cloned from the Project's default Instance, using `/srv/workspace` as `agent`.

#### A task copy starts clean

Task copies start from the clean default seed commit with no leftover untracked edits, ensuring reproducible baseline checks.

#### The copy key

Each task copy receives `ORBIT_COPY_KEY` and `ORBIT_COPY_API` so it can call the three exact [Orbit copy routes](/reference/ai-router#orbit-routes-for-task-copies) on the model forwarder (`/orbit/v1/copy/{whoami,pull-request,handoff}`).

## Sleep and wake

Idle development containers sleep by freezing and reclaiming memory to swap (via either the [Rust executor](/reference/executor#rust-sleep-and-discard) when signed power is enabled or the [Python power helper](/reference/prepared-restore)).

### The Node resumes a frozen container

When a visitor hits a sleeping Instance's Route, Caddy calls `/api/v1/runtime-activations/app-instance/<id>`, which wakes the container via signed executor order or the Node's fast-wake worker before answering.

## Nested fleet

Projects with `nested_fleet` enabled run an isolated three-Node Orbit fleet (`gateway` 4 GiB, `app-dev` 5 GiB, `app-prod` 2 GiB, total 11 GiB inside a 12 GiB outer container) inside Incus project `orbit-instances-nested`.

### The nested-fleet image

`app-dev` and `ciOnly` `ci-runner` converges build a dedicated nested-fleet image with inner Incus support, `wireguard` and `overlay` kernel modules, and `volmode=dev` on ZFS `custom` datasets.

### The inner volume

Each nested-fleet container attaches a dedicated btrfs-on-zvol volume (`inner-<id>`, managed via `php artisan gateway:instance-inner-volume`) for inner Incus storage.

### The fleet step

Deploying a nested-fleet default runs `instance-fleet-*.yml` to verify inner fleet rules, apply the checkout, reconcile Swamp grants, and snapshot `base`.

### Fleet rules

Inner fleet rules write `/etc/orbit/instance-fleet`, egress proxy config (`95orbit-nested-egress`), and Docker/executor proxy drop-ins; drift outside Orbit-owned rules refuses with `instance.container_fleet_drift`.

### Instance host in the fleet

Inside a nested fleet, `nested-app-dev` and `nested-app-prod` use btrfs pools (`orbit-instances`, `orbit-releases`) and route outbound traffic through the parent egress proxy.

### The Club key of the nested Gateway

The nested Gateway's Swamp Club key is delivered during the `start` phase of `Start the container` (or `clone-club-key` on clones) over pipelined stdin directly into `/run` tmpfs inside the container and never touches the host Node's disk.

### A stopped nested-fleet clone starts

Starting a stopped nested-fleet clone (`instance.start`) boots the outer container, verifies fleet rules, and brings up all three inner Nodes and the operator tunnel.

### Reset

Proof batches reset a nested-fleet copy back to its clean `base` snapshot via `proof-fleet-restore`.

### Refresh after a release

After a verified Gateway release settles `fleet: current`, `php artisan gateway:refresh-nested-fleets` refreshes nested-fleet defaults and retakes their seeds across three merged Ansible container steps (`Start the container` running `start` and `probe` in 180 s, `Prepare the container` running `php`, `fleet_start`, `seed`, and `fetch` in 920 s, and `Finish the container` in 4,500 s).

If the `probe` finds the default's checkout already past the release commit, the deploy ends after `Start the container` as a succeeded no-op (`already_newer`, `kept_commit`; [Development defaults](/reference/deployments#development-defaults)). The release publishes the seed only if it matches `kept_commit`, failing seed publication with `instance.release_seed_moved` otherwise.

If an ended deploy still holds the Instance lock because its executor result is unknown (`executor.result_unknown`), the refresh ends failed with `instance.refresh_fenced` (naming `fenced_by`) instead of retrying through the lock.

### What a refresh skips

Refresh compares `orbit:node-converge-digest --base` digests and skips converging inner Nodes whose base inputs did not change.

### The seed on each CI Node

`instance.seed_copy` pushes updated nested-fleet default seeds over WireGuard port `8444` (`incus copy --refresh --mode=push`) to every CI Node offering proof places ([The seed listener](/reference/node-provisioning#the-seed-listener)).

### The doctor warning

Doctor checks container bridge health (`node.instance_bridge_unhealthy`), host ID maps, and nested-fleet readiness on every container host.
