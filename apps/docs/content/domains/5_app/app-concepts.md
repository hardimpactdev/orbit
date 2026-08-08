# App and Instance Concepts

This document defines app and instance vocabulary and invariants. It supports the
app and instance command contracts and the [instance doctor](instance-doctor.md); it does not override
the [Architecture](../../architecture.md).

## Identity

The terms below define the core identity vocabulary.

- **App:** Logical application record owned by the gateway, with a stable
  identity slug, shared runtime policy, and optional repository. An app may
  have multiple instances and owns no placement defaults.
- **Instance:** Concrete runtime or deployment target for one app. An
  instance belongs to exactly one app, has a name unique within that app, selects
  one driver, and owns driver configuration, instance env values, worker policy,
  setup-step policy, and setup runs. It is the target for database attachments
  plus deployment policy, runs, logs, and status.
- **Instance driver:** Backend that knows how an instance is placed and
  operated. Current drivers are `orbit` for Orbit-managed app-host nodes and
  `laravel-cloud` for Laravel Cloud application/environment targets.
- **Driver config:** Driver-specific configuration stored as a Spatie Laravel
  Data object under `driver_config`. `orbit` config records node/path/root/domain
  placement. `laravel-cloud` config records organization, application,
  environment, and domain selectors.
- **Initial instance:** Concrete instance created together with an
  app by `app:new`. Orbit names it `development` when `--domain` is absent and
  `production` when `--domain` is present. Instance-owned commands must resolve
  this or another concrete instance. An app selector fails when more
  than one instance could own the operation; it never falls back to app-owned
  placement state.
- **App identity slug:** Lowercase identity slug used as the app's globally
  unique gateway registry key. Maximum 40 characters.
- **App name argument:** Positional `[name]` argument used by commands that
  create, adopt, or re-converge app configuration. It is not a hostname selector.
- **Instance selector argument:** Positional `[instance]` argument used by commands that
  read or update existing app or instance state. Placement-sensitive commands use a dotted
  instance selector, with bare logical shorthand only when exactly one
  eligible visible instance exists. Exact instance names always resolve before
  hostname, domain, node, path, or TLD aliases so a sibling that shares those
  facts cannot make selectors such as `mealou.nmbp` or `mealou.development`
  ambiguous. The logical `app:remove` command is the
  exception: it accepts only the logical slug and cascades across all instances.
- **Orbit instance serving node:** Node selected explicitly for one Orbit
  instance. Orbit instances may only run on nodes with an active `app-dev` or
  `app-prod` role; a node without either role is not a valid target.

## Environment and hosting

These terms describe runtime and deployment environments. The app record
remains the logical identity. A concrete environment is represented only by an
instance and its driver placement.

- **Development instance:** Orbit instance whose serving node carries the
  `app-dev` role. Its hostname uses the development TLD. Workspaces may attach
  to that instance for branch-style isolation.
- **Production instance:** Orbit instance whose serving node carries the
  `app-prod` role.
  Hostname is a public DNS name. Production domains are globally unique across
  the Orbit network and are activated only after DNS verification against the
  selected ingress placement. Public traffic terminates at
  `ingress`, forwards over WireGuard to `router`, and reaches the instance
  through a private `app-prod` backend artifact.
- **App PHP creation template:** Gateway-tracked configuration holding the PHP
  version that new instances copy at creation. Each instance then owns the
  concrete version its FrankenPHP runtime container and command execution use,
  and each workspace copies its owning instance. Changing this template never
  reaches an instance or workspace that already exists.
- **App runtime kind:** The runtime shape selected for an app. Instances of `php`
  apps run in a dedicated FrankenPHP container; `static` apps serve files directly
  through `orbit-caddy` without a PHP runtime container and have no PHP image,
  worker mode, or worker config. Exposed in JSON as `runtime`.
- **App runtime container:** Dedicated Docker container for one PHP app runtime.
  It mounts the app source, uses the selected PHP image, receives app
  environment, and is targeted by `orbit-caddy` over the node Docker network.
  Static apps do not have a runtime container. The concrete instance runtime, managed
  through the process lifecycle, is represented as a process with Docker runtime.
- **Development packages mount:** PHP app runtime containers on `app-dev` nodes
  mount `/home/<node-user>/packages` from the selected instance's serving node
  at `/packages`. This is the conventional packages root for that node user and keeps Composer path
  repository symlinks usable inside `/app/vendor` without mounting the host
  home directory wholesale. `app-prod` runtime containers and static apps do
  not receive this mount.
- **Instance runtime mount:** Extra Docker bind mount stored on an instance and
  managed through `instance:mount` with dotted selectors such as `hauser.nmbp`. In
  the current slice, configurable runtime mounts are accepted only for PHP apps
  on `app-dev` nodes. Sources must live under the resolved instance node's home
  without exposing credential paths, and mounts default to read-only. Different
  instances of the same app may use different host source paths for the same
  container target. Configured instance runtime mounts are rendered into the app
  runtime container for the selected instance and inherited by workspace runtime
  containers that use that instance. App-level runtime-mount rows are not a
  supported ownership form.
- **Production app runtime container:** App-prod PHP runtime rendered as a
  per-instance Docker container running FrankenPHP on the instance's serving
  node. It
  listens on internal port `8080`, publishes no public host ports, and is
  reached only by the app-host-owned private backend `orbit-caddy` route. The
  process family owns the concrete long-running lifecycle unit for the container.
- **Production app runtime user:** Path-derived Linux user and group used for
  one production app's source, releases, and runtime container identity. It must
  not be a member of the Docker group and must not receive access to the Docker
  socket.
- **Production release mount boundary:** Runtime container bind mounts are
  limited to the app source or active release path plus explicitly managed
  shared paths. The Docker socket, gateway config, host home directories, and
  unrelated release paths are outside the app runtime mount boundary.
- **FrankenPHP app runtime:** PHP app/workspace web runtime. Classic mode is
  the default. It serves HTTP for PHP apps and workspaces and must carry
  OPcache, realpath cache, Composer autoload optimization, Laravel cache warmup,
  and optional preload configuration. On `app-dev` nodes, classic app and
  workspace runtimes also render native FrankenPHP thread-pool tuning through
  `FRANKENPHP_CONFIG` (`max_threads auto` and `max_idle_time 1h`) so development
  runtimes keep idle capacity warm without enabling worker mode. The
  lifecycle-managed FrankenPHP runtime for a concrete app or workspace is
  represented as a process with Docker runtime. The app family owns shared
  runtime policy; the instance family owns URL, source path, deployment policy,
  and runtime selection; the process family owns
  the concrete long-running lifecycle unit.
- **Worker mode:** Opt-in FrankenPHP mode that keeps a validated Laravel app in
  memory on one concrete instance. It is disabled by default per instance
  and can be enabled only after readiness validation succeeds against that
  instance's serving node and source path.
- **Worker config:** Gateway-tracked object for worker settings such as worker
  count and max requests. It belongs to one instance and is stored
  separately from that instance's on/off decision.
- **Required PHP extensions:** Instance-owned list of PHP extensions required by
  the app on that target. The list is normalized for stable output. Orbit driver
  instances are checked against the running FrankenPHP container by instance doctor;
  Laravel Cloud instances use driver metadata as a preflight signal.
- **Instance env:** Values owned by the instance for non-secret env keys,
  stored in the gateway, and rendered on demand. Secret env storage is
  intentionally deferred in this slice.
- **Instance database target:** Mapping from a reusable database connection
  to one instance and env prefix. Rendering the instance env injects
  supported database keys and redacts secret values in API responses.
- **Instance WebSocket binding:** Gateway-owned binding for one concrete instance
  and site. It enables that instance to use the fleet websocket service and
  owns Reverb credentials, allowed origins derived from instance placement,
  public WebSocket hosts, and the instance's private
  `websocket.orbit` publishing configuration. Disabling an instance WebSocket
  binding clears active public route intent without deleting the instance's Reverb
  credential record.
- **Instance analytics binding:** Gateway-owned binding for one concrete instance
  and site. It enables that instance to proxy browser analytics traffic to the
  fleet Plausible CE service and owns the enabled flag and public tracking hostnames such as
  `analytics.example.com`. In v1 it does not provision Plausible sites,
  generate credentials, or inject scripts into the app. App owners add the
  Plausible script manually. Public analytics hosts proxy tracking paths only;
  the dashboard and admin UI stay private at `analytics.orbit`.
- **Reverb app credentials:** Reverb application id, key, and secret material
  for one selected instance site, owned by its WebSocket binding. These credentials are not
  shared across apps; rotating or disabling one binding must not invalidate
  unrelated instance bindings. Reading them requires the explicit
  `instance:credentials` permission on the instance's serving node; `instance:read` and
  `instance:write` do not imply credential access.
- **App dependency audit posture:** Gateway-owned compact summary of a read-only
  package-manager audit for an app's source path. The v1 storage and presentation
  slice stores per-manager status, severity counts, bounded advisory detail, and
  audit timestamps — not full Composer, npm, or Bun package inventories. The
  remote runner that refreshes these summaries from app nodes is a follow-up
  slice.
- **Dependency audit manager:** Supported package-manager audit lane for one app
  path. V1 managers are `composer`, `npm`, and `bun`. Each manager is detected
  independently from lockfiles and available binaries at a concrete instance
  source path; the app exposes only the aggregate summary.
- **Dependency audit status:** Per-manager or aggregate posture state. `clean`
  means an audit succeeded with zero findings, and `findings` means the audit
  returned one or more advisories. `not_applicable` means no supported lockfile
  exists for that manager. `unsupported` means manager audit JSON or binary
  support is missing, including Bun when no binary is on PATH. `failed` means
  the audit could not complete because of missing binary, malformed JSON,
  timeout, or non-finding exit codes. `unknown` means no stored audit summary
  exists yet.
- **Dependency audit severity bands:** Headline counts exposed on app list/show
  JSON. `danger` aggregates manager-native `critical` and `high` severities.
  `warning` aggregates `moderate`, `medium`, `low`, and `unknown`/unclassified
  severities. Exit code `1` from `composer audit --format=json` or
  `npm audit --json` means findings were returned, not command failure.

## Lifecycle

The terms below describe app and instance lifecycle.

- **Instance registration:** Idempotent convergence of app configuration and node artifacts
  performed by `instance:register`. Used to install Orbit management on a new path,
  re-apply management to an existing app, or retry production domain activation.
- **Instance adoption:** Result of `instance:register` against an existing path
  with no Orbit management. The resulting instance reports `adopted=true`.
- **Instance adoption flag:** Boolean instance field that records whether
  that concrete path was adopted (`true`) or created fresh (`false`). Exposed
  in the canonical instance JSON as `adopted`. Instance removal deletes one
  concrete instance and the workspaces and associations owned by that
  instance; it is not app deletion or doctor drift repair.
- **Instance setup pipeline:** Ordered instance-owned commands recorded with
  `instance-setup-step:*` and run by `instance:setup` on the selected instance's serving
  node and source path. Setup commands are for finite app bootstrap work
  such as dependency install, application key generation, storage linking,
  migrations, seeders, and app-owned user creation.
- **Instance setup run:** Gateway record of one `instance:setup` execution owned by one
  instance. It stores the step-set hash, per-step status, result code, and
  captured output so reruns can skip unchanged completed setup steps without
  affecting another instance of the same app.

## Boundaries

These boundaries define what the app and instance families own and what belongs to other families.

- **Instance-owned route:** Proxy route whose lifecycle is owned by one
  concrete instance, edited through instance commands, and surfaced as
  inventory by the `proxy` family.
- **App and instance boundaries:** App commands own the app registry; instance commands own the
  instance registry, instance env rendering, runtime policy, instance deployment policy, instance health
  configuration, instance-bound WebSocket state, and instance-bound analytics
  state.
  They do not own proxy route registry, workspace policy, process configuration,
  schedule definitions, tool registration, or firewall policy beyond what derives
  from app configuration.

  Production route exposure belongs to `ingress`; private route selection and
  backend-pool targeting belong to `router`; `app-prod` owns the private
  backend runtime; `websocket` owns the Reverb runtime; `analytics` owns the
  Plausible runtime.

  App and instance commands do not install or own host Caddy, Reverb, or the host PHP
  toolchain. The `app-dev`/`app-prod` node role provisions the host PHP toolchain
  (PHP and Composer on both; the Laravel installer on `app-dev` only) for deploy
  and ad-hoc app CLI use. `app-prod` does not own lifecycle for database, cache,
  agent, storage, or web runtime units; long-running units are represented by
  processes, while tools remain node-level capability records.
- **Setup boundary:** Instance setup steps may run finite host-toolchain commands
  against the instance source path. They must not represent long-running services,
  service images, scheduled jobs, database service lifecycle, or proxy routes;
  those belong to the process, schedule, database, and proxy families.
