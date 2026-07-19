# App Concepts

This document defines app-family vocabulary and invariants. It supports the
app command contracts and the [app doctor](app-doctor.md); it does not override
the [Architecture](../../architecture.md).

## Identity

The terms below define the core identity vocabulary for the app family.

- **App:** Logical application record owned by the gateway, with a stable
  identity slug, shared runtime policy, and optional repository. An app may
  have multiple app instances and owns no placement defaults.
- **App instance:** Concrete runtime or deployment target for one app. An
  instance belongs to exactly one app, has a name unique within that app, selects
  one driver, and owns driver configuration, app env values, worker policy,
  setup-step policy, and setup runs. It is the target for database attachments
  plus deployment policy, runs, logs, and status.
- **App instance driver:** Backend that knows how an instance is placed and
  operated. Current drivers are `orbit` for Orbit-managed app-host nodes and
  `laravel-cloud` for Laravel Cloud application/environment targets.
- **Driver config:** Driver-specific configuration stored as a Spatie Laravel
  Data object under `driver_config`. `orbit` config records node/path/root/domain
  placement. `laravel-cloud` config records organization, application,
  environment, and domain selectors.
- **Initial app instance:** Concrete instance created together with a logical
  app by `app:new`. Instance-owned commands must resolve this or another
  concrete instance. A logical app selector fails when more than one instance
  could own the operation; it never falls back to app-owned placement state.
- **App identity slug:** Lowercase identity slug used as the app's globally
  unique gateway registry key. Maximum 40 characters.
- **App name argument:** Positional `[name]` argument used by commands that
  create, adopt, or re-converge app configuration. It is not a hostname selector.
- **App selector argument:** Positional `[app]` argument used by commands that
  read, update, prune, or remove an existing app. May be a name or hostname when
  the command contract opts into hostname resolution; name matches win over
  hostname matches.
- **Orbit instance serving node:** Node selected explicitly for one Orbit app
  instance. Orbit instances may only run on nodes with an active `app-dev` or
  `app-prod` role; a node without either role is not a valid target.

## Environment and hosting

These terms describe runtime and deployment environments. The app record
remains the logical identity. A concrete environment is represented only by an
app instance and its driver placement.

- **Development app instance:** Orbit instance whose serving node carries the
  `app-dev` role. Its hostname uses the development TLD. Workspaces may attach
  to that instance for branch-style isolation.
- **Production app instance:** Orbit instance whose serving node carries the
  `app-prod` role.
  Hostname is a public DNS name. Production domains are globally unique across
  the Orbit network and are activated only after DNS verification against the
  selected ingress placement. Public traffic terminates at
  `ingress`, forwards over WireGuard to `router`, and reaches the app
  through a private `app-prod` backend artifact.
- **App PHP version:** Gateway-tracked configuration for the PHP version used by the
  app's FrankenPHP runtime container and app command execution. Workspaces
  inherit this value unless they store an override.
- **App runtime kind:** The runtime shape selected for an app. `php` apps run
  in a dedicated FrankenPHP container; `static` apps serve files directly
  through `orbit-caddy` without a PHP runtime container and have no PHP image,
  worker mode, or worker config. Exposed in JSON as `runtime`.
- **App runtime container:** Dedicated Docker container for one PHP app runtime.
  It mounts the app source, uses the selected PHP image, receives app
  environment, and is targeted by `orbit-caddy` over the node Docker network.
  Static apps do not have a runtime container. The concrete app runtime, managed
  through the process lifecycle, is represented as a process with Docker runtime.
- **Development packages mount:** PHP app runtime containers on `app-dev` nodes
  mount the owning node user's conventional packages root
  (`/home/<node-user>/packages`) at `/packages`. This keeps Composer path
  repository symlinks usable inside `/app/vendor` without mounting the host
  home directory wholesale. `app-prod` runtime containers and static apps do
  not receive this mount.
- **App runtime mount:** Extra Docker bind mount stored on an app instance and
  managed through `app:mount` with dotted selectors such as `hauser.nmbp`. In
  the current slice, configurable runtime mounts are accepted only for PHP apps
  on `app-dev` nodes. Sources must live under the resolved instance node's home
  without exposing credential paths, and mounts default to read-only. Different
  instances of the same app may use different host source paths for the same
  container target. Configured instance runtime mounts are rendered into the app
  runtime container for the selected instance and inherited by workspace runtime
  containers that use that instance. App-level runtime-mount rows are not a
  supported ownership form.
- **Production app runtime container:** App-prod PHP runtime rendered as a
  per-app Docker container running FrankenPHP on the owning node. It
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
  represented as a process with Docker runtime. The app family owns desired app
  configuration, URL,
  source path, app-instance deployment policy, and runtime selection; the process family owns
  the concrete long-running lifecycle unit.
- **Worker mode:** Opt-in FrankenPHP mode that keeps a validated Laravel app in
  memory on one concrete app instance. It is disabled by default per instance
  and can be enabled only after readiness validation succeeds against that
  instance's serving node and source path.
- **Worker config:** Gateway-tracked object for worker settings such as worker
  count and max requests. It belongs to one app instance and is stored
  separately from that instance's on/off decision.
- **Required PHP extensions:** Instance-owned list of PHP extensions required by
  the app on that target. The list is normalized for stable output. Orbit driver
  instances are checked against the running FrankenPHP container by app doctor;
  Laravel Cloud instances use driver metadata as a preflight signal.
- **App instance env:** Values owned by the instance for non-secret env keys,
  stored in the gateway, and rendered on demand. Secret env storage is
  intentionally deferred in this slice.
- **App instance database target:** Mapping from a reusable database connection
  to one app instance and env prefix. Rendering the instance env injects
  supported database keys and redacts secret values in API responses.
- **App WebSocket binding:** Gateway-owned app configuration that enables one
  app to use the fleet websocket service. It owns per-app Reverb credentials,
  allowed origins, public WebSocket hosts, and the app's private
  `websocket.orbit` publishing configuration. Disabling an app WebSocket
  binding clears active public route intent without deleting the app's Reverb
  credential record.
- **App analytics binding:** Gateway-owned app configuration that enables one
  app to proxy browser analytics traffic to the fleet Plausible CE service. It
  owns the enabled flag and public tracking hostnames such as
  `analytics.example.com`. In v1 it does not provision Plausible sites,
  generate credentials, or inject scripts into the app. App owners add the
  Plausible script manually. Public analytics hosts proxy tracking paths only;
  the dashboard and admin UI stay private at `analytics.orbit`.
- **Reverb app credentials:** Reverb application id, key, and secret material
  for one app, owned by an app WebSocket binding. These credentials are not
  shared across apps; rotating or disabling one binding must not invalidate
  unrelated app bindings. Reading them requires the explicit
  `app:credentials` permission on the app's owning node; `app:read` and
  `app:write` do not imply credential access.
- **App agent IDE adapter:** Optional gateway-owned override of the owning
  node's default agent IDE adapter for app and workspace workflows. Set,
  cleared, and shown through `app:agent-ide`.
- **App dependency audit posture:** Gateway-owned compact summary of a read-only
  package-manager audit for an app's source path. The v1 storage and presentation
  slice stores per-manager status, severity counts, bounded advisory detail, and
  audit timestamps — not full Composer, npm, or Bun package inventories. The
  remote runner that refreshes these summaries from app nodes is a follow-up
  slice.
- **Dependency audit manager:** Supported package-manager audit lane for one app
  path. V1 managers are `composer`, `npm`, and `bun`. Each manager is detected
  independently from lockfiles and available binaries on the owning app node.
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

The terms below describe how an app moves through its active states.

- **App registration:** Idempotent convergence of app configuration and node artifacts
  performed by `app:register`. Used to install Orbit management on a new path,
  re-apply management to an existing app, or retry production domain activation.
- **App adoption:** Result of `app:register` against an existing path with no
  Orbit management. The resulting app entity reports `adopted=true`.
- **App adoption flag:** Boolean entity field that records whether an app was
  adopted from an existing path (`true`) or created fresh (`false`). Exposed in
  JSON as `adopted`.
- **App pruning:** Source-of-truth cleanup performed by `app:prune`. Removes
  stale apps, workspaces, and configured agent IDE associations. It is not
  doctor drift repair.
- **App setup pipeline:** Ordered app-instance-owned commands recorded with
  `app-setup-step:*` and run by `app:setup` on the selected instance's serving
  node and source path. Setup commands are for finite project bootstrap work
  such as dependency install, application key generation, storage linking,
  migrations, seeders, and app-owned user creation.
- **App setup run:** Gateway record of one `app:setup` execution owned by one
  app instance. It stores the step-set hash, per-step status, result code, and
  captured output so reruns can skip unchanged completed setup steps without
  affecting another instance of the same logical app.

## Boundaries

These boundaries define what the app family owns and what belongs to other families.

- **App-owned route:** Proxy route whose lifecycle is owned by the app, edited
  through app commands, and surfaced as inventory by the `proxy` family.
- **App-family boundaries:** App commands own app registry, app-instance
  registry, instance env rendering, runtime policy, app-instance deployment policy, app health
  configuration, app WebSocket binding state, and app analytics binding state.
  They do not own proxy route registry, workspace policy, process configuration,
  schedule definitions, tool registration, or firewall policy beyond what derives
  from app configuration.

  Production route exposure belongs to `ingress`; private route selection and
  backend-pool targeting belong to `router`; `app-prod` owns the private
  backend runtime; `websocket` owns the Reverb runtime; `analytics` owns the
  Plausible runtime.

  App commands do not install or own host Caddy, Reverb, or the host PHP
  toolchain. The `app-dev`/`app-prod` node role provisions the host PHP toolchain
  (PHP and Composer on both; the Laravel installer on `app-dev` only) for deploy
  and ad-hoc app CLI use. `app-prod` does not own lifecycle for database, cache,
  agent, storage, or web runtime units; long-running units are represented by
  processes, while tools remain node-level capability records.
- **Setup boundary:** App setup steps may run finite host-toolchain commands
  against the app source path. They must not represent long-running services,
  service images, scheduled jobs, database service lifecycle, or proxy routes;
  those belong to the process, schedule, database, and proxy families.
