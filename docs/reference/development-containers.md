---
title: "Instance containers"
description: "How development Instances and task copies run in unprivileged Incus containers cloned from a Project's default Instance, with networking, ACLs, egress, sleep/wake, and nested fleets."
covers:
  - apps/gateway/app/{Console/Commands/SyncKnownHostsCommand,Infrastructure/Ssh/KnownHostsStore,Infrastructure/Ssh/KnownHostsRepository,Models/ProofSeed,Domain/Proof/ProofSeeds,Models/InstanceRuntime,Actions/Nodes/AllocateInstanceBridgeAction,Domain/Nodes/NodeBridges,Console/Commands/RefreshNestedFleetsCommand,Console/Commands/InstanceIdMapSizeCommand,Console/Commands/InstanceInnerVolumeCommand,Domain/Tasks/TaskTurn,Domain/Tasks/TaskTurnProjection}.php
  - apps/gateway/app/Domain/Instances/{InstanceRuntimePowerState,ContainerNodes,InstanceHostIntent,InstanceImage,InstanceFolderPlan,InstanceNotes,InstanceAgentInstructions,InstanceContainer,InstanceEgress,DevelopmentMail,DevelopmentInstanceCreateProjection,ClonePublication,NestedFleetIntent,NestedFleetRefresh,IsolatedIdRanges,InstanceOperationAlerts,InstanceModelRoute}.php
  - apps/gateway/app/Domain/{InstanceMigration/**,Instances/Environment/DevelopmentCreateEnvironment.php,Instances/Deployment/{ContainerDeployOutcome,DevelopmentDeploySteps}.php}
  - apps/gateway/database/migrations/2027_02_{04_000000_add_seed_commit_to_instance_runtimes,04_000002_add_operation_owner_to_instance_runtimes,05_000001_clear_completed_container_default_projections,05_000002_clear_completed_container_default_projections_by_route_target,25_000000_create_proof_seeds,26_000000_add_ci_bridge_to_nodes,26_000001_release_an_unused_instance_bridge,27_000000_add_seed_sender_id_to_nodes,28_000000_allow_a_proof_copy_clone_on_its_seed_node}.php
  - swamp/{ansible/{instance-container.yml,instance-container-destroy.yml,instance_node_runtime.py,instance_fleet_shrink.py,instance_fleet_runtime.py,tasks/instance-fleet-runtime.yml,instance_migrate_git.py,tasks/instance-node-runtime.yml,tasks/instance-container-phase.yml,tasks/instance-container-run-phase.yml},scripts/instance_clone{,_guest}.py,ansible/instance_{bridge,network,node_services}.py,extensions/behaviors/operations/instance-dev-command/result.ts,ansible/task-copy-turn.yml,workflows/workflow-operation-task-copy-turn.yaml,extensions/behaviors/operations/task-copy-turn/*}
  - apps/gateway/{config/instances.php,database/migrations/{2027_02_03_000000_create_instance_runtimes,2027_02_11_000001_add_workspace_baseline_to_instance_runtimes,2027_02_12_000000_add_idle_watch_started_at_to_instance_runtimes,2027_02_15_000000_add_t3_port_last_to_nodes,2027_02_16_000000_add_idmap_size_to_instance_runtimes,2027_02_16_000001_add_id_ranges_to_nodes}.php,resources/instances/{notes/**,frankenphp.Caddyfile,workspace-git-read.py,id-map-read.py},app/Infrastructure/Instances/{ContainerWorkspaceRead,NodeIdMapRead}.php}
  - swamp/ansible/tasks/{instance-host,instance-folder,instance-image,instance-egress,instance-network-check,instance-network-nic-check,instance-network-move,instance-network-move-nic,instance-bridge-rules,instance-bridge-refresh,instance-mail}.yml
  - swamp/ansible/t3_copy_rpc.mjs
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

The shared folders supply pinned static PHP, Composer, Vite+, Node.js, pnpm, Bun, `t3`, `conn-server`, `claude-code`, `codex`, `antigravity`, `chrome-headless-shell`, and `agent-browser` without installing copies inside each container.

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

Projects select `container_server` (`conn` by default, or `t3`; task copies always use `conn`). `/etc/orbit/instance` gates `t3.service` so default Instances never boot T3.

### T3 registration and pairing

Clone creation starts T3 on its allocated host proxy port, creates the workspace project, registers the server with [Conn](/reference/conn#instance-servers), and returns sealed Gateway and Swamp sessions.

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

When a clone has no custom Processes, units, or web config and its seed's `services_digest` matches `InstanceContainer::servicesDigest`, [`instance.complete`](/reference/gateway-engine#instancecomplete) ends immediately on the Gateway engine with zero steps and transitions the clone to `active` without starting a Swamp run.

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

`instance:destroy <id> --yes [--force]` deletes the container, its `instance-<id>` ACL, UFW `orbit:instance-<id>-` rules, repository mirror (`/var/lib/orbit/instance-repositories/<id>.git`), power helper files, and Route. Destroying a default Instance requires the Project to be listed in `ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS`.

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

The nested Gateway's Swamp Club key is delivered over pipelined stdin directly into `/run` tmpfs inside the container and never touches the host Node's disk.

### A stopped nested-fleet clone starts

Starting a stopped nested-fleet clone (`instance.start`) boots the outer container, verifies fleet rules, and brings up all three inner Nodes and the operator tunnel.

### Reset

Proof batches reset a nested-fleet copy back to its clean `base` snapshot via `proof-fleet-restore`.

### Refresh after a release

After a verified Gateway release settles `fleet: current`, `php artisan gateway:refresh-nested-fleets` refreshes nested-fleet defaults and retakes their seeds.

### What a refresh skips

Refresh compares `orbit:node-converge-digest --base` digests and skips converging inner Nodes whose base inputs did not change.

### The seed on each CI Node

`instance.seed_copy` pushes updated nested-fleet default seeds over WireGuard port `8444` (`incus copy --refresh --mode=push`) to every CI Node offering proof places ([The seed listener](/reference/node-provisioning#the-seed-listener)).

### The doctor warning

Doctor checks container bridge health (`node.instance_bridge_unhealthy`), host ID maps, and nested-fleet readiness on every container host.
