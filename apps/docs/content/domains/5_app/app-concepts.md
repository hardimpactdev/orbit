# App Concepts

This document defines app-family vocabulary and invariants. It supports the
app command contracts and the [app doctor](app-doctor.md); it does not override
the [Architecture](../../architecture.md).

## Identity

The terms below define the core identity vocabulary for the app family.

- **App:** An application record owned by the gateway, bound to one node, with a
  stable identity slug, primary URL, app path, document root, and optional
  repository. The app's environment (development versus production) is derived
  from the owning node's active app role and is not a separate field on the app
  record.
- **App identity slug:** Lowercase identity slug used as the app's globally
  unique gateway registry key. Maximum 40 characters.
- **App name argument:** Positional `[name]` argument used by commands that
  create, adopt, or re-converge app configuration. It is not a hostname selector.
- **App selector argument:** Positional `[app]` argument used by commands that
  read, update, prune, or remove an existing app. May be a name or hostname when
  the command contract opts into hostname resolution; name matches win over
  hostname matches.
- **Owning node:** The node slug that hosts the app's path, runtime, and
  app-derived artifacts. Apps may only run on nodes with an active
  `app-dev` or `app-prod` role; a node without an active app
  role is not a valid app target.

## Environment and hosting

These terms describe the two environments an app may occupy. An app's
environment is determined by the owning node's active app role —
`app-dev` or `app-prod` — not a separate field stored on the app
record.

- **Development app:** App whose owning node carries the `app-dev`
  role. Hostname uses the development TLD. Workspaces may attach to the app for
  branch-style isolation.
- **Production app:** App whose owning node carries the `app-prod` role.
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
  worker mode, or worker config. Exposed in JSON as `runtime_kind`.
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
- **Production app runtime container:** App-prod PHP runtime rendered as a
  per-app Docker container running FrankenPHP on the owning node. It
  listens on internal port `8080`, publishes no public host ports, and is
  reached only by the app-role-owned private backend `orbit-caddy` route. The
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
  and optional preload configuration. The lifecycle-managed FrankenPHP runtime
  for a concrete app or workspace is represented as a process with Docker
  runtime. The app family owns desired app configuration, URL,
  source path, deployment policy, and runtime selection; the process family owns
  the concrete long-running lifecycle unit.
- **Worker mode:** Opt-in FrankenPHP mode that keeps a validated Laravel app in
  memory. It is disabled by default and can be enabled only after readiness
  validation succeeds.
- **Worker config:** Gateway-tracked object for worker settings such as worker
  count, max requests, and failure thresholds. It is stored separately from the
  on/off decision.
- **App WebSocket binding:** Gateway-owned app configuration that enables one
  app to use the fleet websocket service. It owns per-app Reverb credentials,
  allowed origins, public WebSocket hosts, and the app's private
  `websocket.orbit` publishing configuration. Disabling an app WebSocket
  binding clears active public route intent without deleting the app's Reverb
  credential record.
- **Reverb app credentials:** Reverb application id, key, and secret material
  for one app, owned by an app WebSocket binding. These credentials are not
  shared across apps; rotating or disabling one binding must not invalidate
  unrelated app bindings. Reading them requires the explicit
  `app:credentials` permission on the app's owning node; `app:read` and
  `app:write` do not imply credential access.
- **App agent IDE adapter:** Optional gateway-owned override of the owning
  node's default agent IDE adapter for app and workspace workflows. Set,
  cleared, and shown through `app:agent-ide`.

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

## Boundaries

These boundaries define what the app family owns and what belongs to other families.

- **App-owned route:** Proxy route whose lifecycle is owned by the app, edited
  through app commands, and surfaced as inventory by the `proxy` family.
- **App-family boundaries:** App commands own app registry, runtime policy,
  deployment policy, app health configuration, and app WebSocket binding state.
  They do not own proxy route registry, workspace policy, process
  configuration, schedule definitions, tool registration, or firewall policy
  beyond what derives from app configuration.

  Production route exposure belongs to `ingress`; private route selection and
  backend-pool targeting belong to `router`; `app-prod` owns the private
  backend runtime; `websocket` owns the Reverb runtime.

  App commands do not install or own host Caddy, Reverb, or the host PHP
  toolchain. The `app-dev`/`app-prod` node role provisions the host PHP toolchain
  (PHP and Composer on both; the Laravel installer on `app-dev` only) for deploy
  and ad-hoc app CLI use. `app-prod` does not own lifecycle for database, cache,
  agent, storage, or web runtime units; long-running units are represented by
  processes, while tools remain node-level capability records.
