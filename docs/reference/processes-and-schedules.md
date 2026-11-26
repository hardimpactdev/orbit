---
title: "Processes and schedules"
description: "How Orbit runs Processes for an Instance or a Node, and how a Project declares Process and Schedule definitions that production Instances copy."
covers:
  - apps/gateway/app/{Actions,Data}/{Processes,ProjectDefinitions}/**
  - apps/gateway/app/Domain/{Processes/**,Instances/InstanceNodeService*.php}
  - apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2027_02_06_000000_create_instance_node_service_targets_table,2027_03_19_000000_create_process_artifacts}.php
  - apps/gateway/{app/Infrastructure/Processes/{RemoteProcessRuntimeManager,SystemdProcessRenderer,DockerProcessRenderer,NativeProcessAdmissionLock,NativeProcessRuntimeLease,SshProcessUserResolver,ContainerProcessInspection}.php,resources/processes/container-process-read.py}
  - apps/gateway/app/Http/{Controllers/Api/ProcessesController,Requests/Processes/*}.php
  - apps/gateway/app/Actions/Instances/InstantiateProjectRuntimeDefinitionsAction.php
  - apps/gateway/app/Http/{Controllers/Api/ProjectRuntimeDefinitionsController,Requests/ProjectDefinitions/*}.php
  - apps/gateway/app/Models/{Process,ProcessArtifact,ProcessDefinition,ScheduleDefinition}.php
---

# Processes and schedules

A Process is a long-running systemd service or Docker container that Orbit manages for one owner: an Instance or a managed Node. A Project can also declare Process and Schedule definitions that Orbit copies into new Instances. [`process`](/cli/process) lists the CLI commands, and [Schedules](/reference/schedules) covers scheduled jobs.

## Owners

Each Process belongs to either an Instance or a Node:

- **Instance Process:** serves one Instance using its derived Node, systemd user, and working directory (Docker keeps `WORKDIR`). [Instance removal](/reference/instance-removal) cleans it up.
- **Container Instance Process:** runs inside the [Instance container](/reference/development-containers#processes) as `www-data:www-data` with home `/var/www` (or `Group=<user>` and `HOME=/home/orbit` in production containers). [`process:destroy --instance`](/cli/process/destroy#a-process-in-an-instance-container) removes unowned container Processes.
- **Node Process:** serves the Node itself as the Node's managed user or `user`. It never reads an Instance `.env` and blocks Node removal (`node.has_processes`).

`process:create` and `process:list` take one owner (`--instance`, `--node`, or `--project` for a definition). Update, start, stop, restart, logs, and destroy take the Process ID.

### Node account

A Node systemd Process accepts `user` (`--user` in the CLI): a letter or underscore followed by up to 31 letters, digits, underscores, or hyphens. Orbit resolves the account from the Node's live [`accounts` fact](/reference/node-agent#facts) and defaults the working directory to that account's home. Orbit does not create accounts, refuses `root` or UID 0, and returns `process.user_unavailable` (422) if the account or fact is missing.

Instance Processes, Docker Processes, presets, and Project definitions reject `user` (HTTP 422). macOS targets return `process.platform_unsupported` (422).

### Node service target records

An Instance can attach a standalone Docker Node Process via `POST /api/v1/instances/{instance}/node-service-targets` with `process_id`, `host_env_key`, and `port_env_key` (plus `port` when the Process publishes multiple TCP ports). The Process must be active, desired running, and publish a TCP port on `0.0.0.0`, the Instance bridge address (same-Node), or the service Node's WireGuard IPv4 address (remote). Reserved infrastructure ports, registered Database server ports, and role-owned Processes are refused.

- **Same-Node container targets:** Orbit installs a loopback `systemd-socket-proxyd` unit (`orbit-node-service-<port>.socket` and `.service`, 256-connection limit) on `127.0.0.1:<port>` and probes it before starting application Processes (`instance.node_service_unreachable` on failure).
- **Remote container targets:** Orbit opens a container NIC ACL rule and firewall forward rule directly to the WireGuard IP and port and adds the host to managed `NO_PROXY`.
- **Inspect and detach:** [`instance:node-service:list`](/cli/instance/node-service-list) lists attachments without exposing values. `DELETE /api/v1/instances/{instance}/node-service-targets/{target}` removes the record (and withdraws container rules first for running containers; stopped containers refuse detach).

While an Instance attaches a Node Process, `process.node_service_endpoint_in_use` blocks stopping it or changing the attached port, `process.required_by_instance_node_service` blocks destroying it, and `process.node_service_ownership_conflict` blocks assigning it to a role or service. Overlapping environment key names return `instance.node_service_environment_conflict`.

## Runtimes

A Process uses either `systemd` or `docker`:

| Runtime | Required | Optional | Default working directory |
| --- | --- | --- | --- |
| `systemd` | Name, absolute executable and arguments (container definitions also accept runtime command names) | Working directory, restart policy, `keep_alive`, `restart_on_deploy`, `start`, `required_mount_paths`, `user` (Node only) | Instance [application directory](/reference/projects#application-directory), or selected Node account home |
| `docker` | Name, image, command arguments | Working directory, environment, published ports, volumes, restart policy, `keep_alive`, `restart_on_deploy`, `start` | The image's `WORKDIR` (`null` omits `--workdir`) |

Commands allow up to 64 arguments of 4,096 bytes each. Restart policy is `never` (default), `on-failure`, `always`, or `unless-stopped`. Systemd units are named `orbit-process-{id}-{name}.service` and Docker containers `orbit-process-{id}-{name}`. Orbit verifies its Process ID ownership marker before replacing or removing any unit or container.

### Move data before removing an override

Updating a Docker specification can replace the container and discard its writable layer, even when the Process ID and named volumes stay the same. For example, an explicit `/app` working directory override on Valkey (whose image `WORKDIR` is `/data`) may have stored AOF/RDB files inside the container layer rather than the `/data` volume.

Before updating a stateful Docker Process:

1. Inspect the stored specification, container mounts, image `WORKDIR`, and actual data paths.
2. Stop writers, flush data, run `process:stop`, and back up files outside the container (using `docker cp` for remaining container files).
3. Test restoring the backup in a disposable environment.
4. Restore data into a named volume or bind mount at the image's expected data path.
5. Run `process:update` with the mount (and `working_directory: null` to use the image default), start the Process, and verify health before admitting clients.

## Environment contract

`environment` is always a string-to-string map (for example `{"CACHE_HOST":"10.44.0.20"}`), never a list of `NAME=VALUE` strings. The CLI accepts repeated `--environment=NAME=VALUE` flags and splits at the first `=`. Keys match `[A-Za-z_][A-Za-z0-9_]*`, values are strings, and an empty map serializes as `{}`. Public create and update accept `environment` for Docker Processes; API responses and Activity replace every value with `[REDACTED]`.

## Environment of a systemd Process

Systemd Processes receive managed private values through an immutable root-owned `0600` file at `/etc/orbit/processes/{id}/{sha256}.env` referenced by `EnvironmentFile=` in the unit. No private value is written inline in `/etc/systemd/system` or passed in public Operation inputs.

Every systemd unit sets `PATH` and `NODE_USE_SYSTEM_CA=1`. An Instance Process also reads the Instance's `.env` file from its application directory (except dev-server presets, where Vite loads `.env` itself).

Derived keys always take precedence: `PATH`, `NODE_USE_SYSTEM_CA`, `VITE_DEV_SERVER_CERT`, `VITE_DEV_SERVER_KEY`, `ORBIT_DEV_SERVER_*`, `__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS`, `ORBIT_SSR_PORT`, and `INERTIA_SSR_URL`.

- Development Processes receive `VITE_DEV_SERVER_CERT` and `VITE_DEV_SERVER_KEY` (unset on `vp-dev` and `vite` presets).
- Route-backed development Processes receive `ORBIT_DEV_SERVER_ORIGIN`, `ORBIT_DEV_SERVER_HOST`, `ORBIT_DEV_SERVER_PATH`, and `ORBIT_DEV_SERVER_PORT` (omitted on `vite`). `vp-dev` also adds `DEV_SERVER_ORIGIN` by default.
- Instances with an [assigned SSR port](/reference/assigned-ssr-ports) receive `ORBIT_SSR_PORT` and `INERTIA_SSR_URL`.
- A Node systemd Process running `artisan horizon` automatically receives the [Horizon graceful-stop policy](/reference/gateway-queue#releases) (`KillMode=mixed`, `KillSignal=SIGTERM`, `SendSIGKILL=no`, `TimeoutStopSec`).

## Presets

Presets configure a development Instance dev server without manual runtime, command, or environment flags. An Instance may have at most one Process per preset (`process.preset_exists` otherwise), and presets refuse Node or non-development targets (`validation.failed` / `process.preset_target_invalid`). A container Instance runs at most one dev-server preset (`process.container_port_conflict` if a second is added).

| Preset | Where allowed | Result |
| --- | --- | --- |
| `vp-dev` | `POST /api/v1/processes` (and captured definitions) | Runs Vite+ on an [assigned Vite port](/reference/assigned-vite-ports) with default restart policy `on-failure`. |
| `vite` | Project `development` definitions only | Runs the Project's `vite` on port `5173` inside a container clone, reachable at `https://<Route domain>:5173`. |

Both presets supply `__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS` and omit the application `.env` from systemd `EnvironmentFile` so Vite loads `.env` directly.

### The vite preset

The `vite` preset runs a Project's standard Vite server in a development container clone without Vite+ or repository changes. Declare it as a Project `development` Process definition:

```json
{
  "name": "vite",
  "environments": ["development"],
  "spec": {
    "runtime": "systemd",
    "preset": "vite",
    "preset_version": 1,
    "command": ["vite", "--host=0.0.0.0", "--port=5173", "--strictPort"],
    "restart_policy": "on-failure"
  }
}
```

The command must match that exact form. The unit runs `<application directory>/node_modules/.bin/vite` as `www-data`, sets `VITE_APP_URL=https://<Route domain>` (unless the definition sets its own `VITE_APP_URL`), and sets `INERTIA_SSR_HOT_URL=http://127.0.0.1:5173` on the container's PHP unit. It has no assigned Vite port, no `/__orbit/vite` proxy path, and no `DEV_SERVER_ORIGIN`.

Container defaults skip the `vite` preset (and deployments remove any earlier one) so `public/hot` never points a default Instance at an unreachable port. Host checkouts refuse the definition with `process.container_command_unsupported`.

## Operate a Process

Process create, update, start, stop, restart, and destroy return an [Operation](/reference/operations) with HTTP 202. On a non-container executor Node, `start` and `restart` execute synchronously and return HTTP 200 with Process data so an operator can recover executor services directly; `update`, `create`, `stop`, and `destroy` always return HTTP 202. Pass `--idempotency-key` to replay an admitted Operation safely.

## Upgrade a Process

A systemd Process running a downloaded GitHub release can carry a release artifact ([ADR 0273](/decisions/0273-upgrade-a-process-to-a-new-upstream-version)). Configure it with [`process:artifact:set`](/cli/process/artifact-set) (or remove it with [`process:artifact:unset`](/cli/process/artifact-unset)) and upgrade on demand with [`process:upgrade`](/cli/process/upgrade). Orbit checks GitHub daily via `process:artifacts:check-latest` (`latest_version`, `latest_checked_at`) and never upgrades a Process automatically.

| Field | Meaning |
| --- | --- |
| `source`, `repository` | `github_release` and `OWNER/REPO`. |
| `asset`, `binary` | Asset filename pattern containing `{version}`, and executable path inside the archive. |
| `checksums`, `version_args` | Optional checksum asset (defaults to GitHub's SHA-256 digest) and version-check arguments. |
| `version`, `previous_version`, `sha256` | Installed version (without leading `v`), retained previous version, and installed asset SHA-256. |

The Process executable must run `<folder>/current/<binary>` with releases stored under `<folder>/releases/<version>/` and no other Process on the Node sharing `<folder>`.

| Request | Result |
| --- | --- |
| `GET /api/v1/processes` | Lists Processes on reachable Nodes (`target_type` and `target_id` filter to one owner), including desired state, runtime status, `keep_alive`, `restart_on_deploy`, `cpu`, and `memory_bytes`. |
| `POST /api/v1/processes` | Creates a Process (202). An identical request returns the existing Process; a different spec with the same name returns `process.name_taken` (409). |
| `PATCH /api/v1/processes/{process}` | Partially updates the specification and reconverges the runtime (202). |
| `POST /api/v1/processes/{process}/start`, `/stop`, `/restart` | Changes runtime state and updates `desired_state` (`running` or `stopped`). |
| `GET /api/v1/processes/{process}/logs?lines=N` | Returns 1 through 1,000 recent lines. See [Live logs](/reference/live-logs) to follow. |
| `DELETE /api/v1/processes/{process}` | Stops the Process, removes its unit or container, resets any systemd failed state, and deletes the record (202). Refuses Database server, Analytics, AI router, Route-targeted, and node-service-attached Processes. |

Creating or starting a Process requires an active Instance and Node (`process.target_inactive` otherwise). On `app-dev` Nodes, systemd Instance Processes install without boot enablement so they stay down after a reboot until `process:start` or an HTTP [wake](/reference/app-dev-runtime-hibernation), and Docker Instance Processes map `always` to `unless-stopped`. On `app-prod`, a Process can be created stopped before the first release, but starting requires a selected release (`process.release_unavailable`). Production deploys and rollbacks restart active Processes with `restart_on_deploy: true` (the default).

### Direct Process controls

`process.start`, `process.stop`, and `process.restart` verify the release directory (on production), prepare Vite Caddy routing when needed, apply systemd boot policy (skipping enablement on on-demand dev units), run the start/stop/restart action, and verify the resulting state. For `vp-dev`, if Vite has not yet bound its port during the immediate check, start fails with `vite.listener_unavailable` (or `vite.not_ready` on checkout readiness failure); stop the Process or retry `process:start` once Vite is ready.

`process.start` and `process.stop` of a host systemd unit or a host Docker container can run on the Gateway engine as one signed `process.ensure_state` order. The projection is the same as on Swamp. A run the engine ends without a terminal receipt records `operation.cancelled`. Dev-server, production release, Instance-container, Gateway release and host Horizon Processes keep the Swamp workflow ([process.start and process.stop](/reference/gateway-engine#processstart-and-processstop)).

### Update in place

[`process:update`](/cli/process/update) (`PATCH /api/v1/processes/{process}`) updates only the fields you send, keeping the Process ID, name, owner, and desired state intact. Omitted fields keep their stored values; send `working_directory: null` to restore the default, or an empty map/list (`{}` / `[]`) to clear a collection. Switching runtimes returns `process.option_invalid` (422) if an omitted field from the old runtime would be discarded.

Ordinary systemd Processes also accept `required_mount_paths` (up to 64 unique normalized absolute paths), rendered as a `RequiresMountsFor=` directive and checked for existence before activation.

Updates that preserve a targeting Route's exact resolved TCP listener succeed without re-provisioning TLS or firewall rules. Changing or removing a targeting Route's upstream returns `process.route_upstream_changed` (409). Publishing TCP ports `5173` through `5199` on `0.0.0.0` or the Instance bridge address returns `instance.dev_band_reserved` (422) because that band is reserved for [container development ports](/reference/development-containers#the-own-site-from-inside).

### Persisted intent and retry recovery

Orbit validates the request, acquires locks, and persists the new specification before mutating the Node. If convergence fails or is interrupted, applied files remain in place and no automatic rollback runs. Inspect the Process and repeat the same create or update request to converge; retries preserve ownership checks, named volumes, and desired state.

Competing Process mutations return `operation.target_busy` (409). Competing Instance Process operations wait up to 30 seconds before returning `process.operation_busy`, and competing Instance lifecycle operations return `instance.lifecycle_busy` (409).

### Process runtime state

`desired_state` is stored intent (`running` or `stopped`), whereas `runtime_status` is an observation from the [runtime status index](/reference/metrics#process-runtime-status) (or a direct container read for container Instances).

For a systemd Process desired `running`, Doctor reports `process.crash_loop` drift when the unit is in `activating` / `auto-restart` or its `NRestarts` count increases during inspection. A stopped non-`keep_alive` Process on a sleeping development Instance is not reported as drift while its hibernation awake marker is absent.

## Project definitions

A Project can store Process and Schedule definitions under `/api/v1/projects/{project}/process-definitions` and `/schedule-definitions`. Each definition has a UUID, a unique slug `name` (1–63 lowercase letters, digits, or hyphens), `environments` (`production`, `development`, or both; Schedule definitions allow `production` only), and a `spec`.

- Development `instance:create` (and `instance:register --setup`) creates and starts `development` Process definitions after setup finishes. Container defaults reconcile edited or removed definitions on their next deployment.
- Production cloning copies `production` Process and Schedule definitions into the new Instance.
- Process definition `PUT` merges supplied `spec` fields with stored values (preserving `[REDACTED]` environment keys). Schedule definition `PUT` replaces the whole definition.
- Editing a definition never mutates existing production copies or existing clones; deleting a Project deletes its definitions.

## Production copies

[Cloning](/reference/instance-cloning) a production Instance copies every `production` Process and Schedule definition from the Project (ignoring the candidate's own Processes and Schedules). Each Process installs stopped and each Schedule installs disabled so workers and cron jobs never run before the new Instance's data is ready. Start them with [`process:start`](/cli/process/start) and [`schedule:enable`](/cli/schedule/enable) when ready.

A copy is independent of its definition after creation. A name conflict stops preparation with `process.name_taken` or `schedule.retry_conflict`, and retries install only unfinished copies.

## Why it works this way

### Node Processes

Shared infrastructure services such as Docker databases belong to a Node rather than an Instance, so they stay out of Instance removal and deployment lifecycles.

### Definitions on the Project, copies on the Instance

Development candidates often run ad hoc workers or dev servers that do not belong in production. Copying from Project definitions keeps production predictable, and keeping copies independent ensures editing a definition never alters running production Instances unexpectedly.

### Copies start stopped

Installing copied workers and Schedules stopped prevents them from processing copied queue jobs or firing against unfinished data before an operator is ready.

### Managed environment in private files

Keeping systemd environment values in root-owned `0600` `EnvironmentFile` files keeps secrets out of world-readable `/etc/systemd/system` unit files and public Operation history.
