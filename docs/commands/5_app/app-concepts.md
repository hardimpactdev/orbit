# App Concepts

This document defines app-family vocabulary and invariants. It supports the
app command contracts and the [app doctor](app-doctor.md); it does not override
the [Architecture](../../ARCHITECTURE.md).

## Identity

- **App:** A gateway-owned application record bound to one app node, with a
  stable identity slug, environment, primary URL, app path, document root, and
  optional repository.
- **App identity slug:** Lowercase identity slug used as the app's globally
  unique gateway registry key. Maximum 40 characters.
- **App name argument:** Positional `[name]` argument used by commands that
  create, adopt, or re-converge app intent. It is not a hostname selector.
- **App selector argument:** Positional `[app]` argument used by commands that
  read, update, prune, or remove an existing app. May be a name or hostname when
  the command contract opts into hostname resolution; name matches win over
  hostname matches.
- **Owning app node:** The app-node slug that hosts the app's path, runtime, and
  app-derived artifacts. Apps may only run on app nodes; gateway and control
  nodes are never valid app targets.

## Environment And Hosting

- **Development app:** App whose hostname uses the development TLD. Workspaces
  may attach to the app for branch-style isolation.
- **Production app:** App whose hostname is a public DNS name. Production
  domains are globally unique across the Orbit network and are activated only
  after DNS verification against the owning node's recorded production
  addresses.
- **App PHP version:** Gateway-tracked intent for the PHP version used by the
  app's PHP-FPM pool and CLI runtime. Workspaces inherit this value unless they
  store an override.
- **App agent IDE adapter:** Optional gateway-owned override of the owning
  node's default agent IDE adapter for app and workspace workflows. Set,
  cleared, and shown through `app:agent-ide`.

## Lifecycle

- **App registration:** Idempotent convergence of app intent and node artifacts
  performed by `app:register`. Used to install Orbit management on a new path,
  re-apply management to an existing app, or retry production domain activation.
- **App adoption:** Result of `app:register` against an existing path that was
  not previously Orbit-managed. The resulting app entity reports `adopted=true`.
- **App pruning:** Source-of-truth cleanup performed by `app:prune`. Removes
  stale apps, workspaces, and configured agent IDE associations. It is not
  doctor drift repair.

## Boundaries

- **App-owned route:** Proxy route whose lifecycle is owned by the app, edited
  through app commands, and surfaced as inventory by the `proxy` family.
- **App-family boundaries:** App commands own app registry, runtime policy,
  deployment policy, and app health intent. They do not own proxy route
  registry, workspace policy, process intent, schedule definitions, tool
  registration, or firewall policy beyond what derives from app intent.
