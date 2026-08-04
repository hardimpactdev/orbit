# Proxy Concepts

This document defines proxy-family vocabulary and invariants. It supports the proxy command contracts and the [proxy doctor](proxy-doctor.md); it does not override the [Architecture](../../architecture.md).

## Routes

These terms define the types of routes that the proxy family owns and manages.

- **Proxy route:** Gateway-owned record of one hostname or host/path Orbit
  exposes through its HTTP ingress, with an owner, a kind, a serving node, a
  target, and TLS configuration.
- **Route owner:** The domain that owns route lifecycle. One of `project`,
  `instance`, `analytics`, `websocket`, `workspace`, `gateway`, `router`, `s3`,
  `tool`, or `custom`. The `owner` value classifies which domain's convergence
  edits the route record; it is not necessarily the role that owns the hostname
  or artifact.
- **Route kind:** Route behavior at ingress. One of `project`, `instance`, `workspace`,
  `internal`, `proxy`, or `redirect`.
- **Project route:** Proxy route whose owner is the project and whose kind is
  `instance`, and whose target is always one concrete instance. The route stores
  the project slug as `owner.name`, the dotted instance selector as
  `target.value`, and that instance's serving node as `node`. Edited through
  project and instance commands.
- **Workspace route:** Proxy route whose owner is a workspace and whose kind is
  `workspace`. Edited through workspace commands.
- **Internal route:** Proxy route with kind `internal`. Currently always paired
  with owner `gateway` and used for gateway API ingress; bound to the gateway
  Orbit network address and never a public application route.
- **Custom route:** Proxy route whose owner is `custom`. Created, updated, and
  removed through `proxy:add` and `proxy:remove`.
- **Redirect route:** Custom proxy route with kind `redirect`, created through
  `proxy:add --redirect=<url>`.
- **Tool-owned route:** Proxy route whose owner is `tool` and kind is `proxy`.
  Represents an HTTP or WebSocket tool ingress; TCP tool service endpoints are
  not HTTP proxy routes.
- **Instance WebSocket route:** Public WebSocket route whose public owner is
  `websocket` and whose kind is `proxy`. It is created from an instance
  WebSocket binding, rendered on an `ingress` node, and forwards to `router`;
  it must not target a concrete websocket node.
- **Project analytics route:** Public analytics tracking route whose public owner is
  `analytics` and whose kind is `proxy`. It is created from a project
  analytics binding, rendered on an `ingress` node, forwards to `router`, and
  proxies only Plausible script and event-ingest paths. It must not expose the
  Plausible dashboard or target a concrete analytics node.
- **Analytics service route:** Private router route for `analytics.orbit`,
  owned by `router`. It exists while at least one active `analytics` role
  assignment exists in the topology and targets the analytics backend pool
  owned by router.
- **WebSocket service route:** Private router route for `websocket.orbit`,
  owned by `router`. It exists while at least one active `websocket` role
  assignment exists in the topology and is removed when none remains. It
  targets the websocket backend pool owned by the router and is the stable
  private publishing endpoint projects and instances use.
- **Public S3 route:** Public S3 route whose owner is `s3` and whose kind is
  `proxy`. It is rendered on an `ingress` node, forwards to `router`, preserves
  S3 request metadata needed for uploads, and must not target a concrete s3
  node.
- **S3 service route:** Private router route for `s3.orbit`, owned by
  `router`. It exists while at least one active `s3` role assignment exists in
  the topology and is removed when none remains. It targets the S3 backend
  pool owned by the router and is the stable private S3 endpoint projects and
  VPN clients use.
- **Metrics service route:** Private router route for `metrics.orbit`, owned by
  `router`. It exists while an active `metrics` role assignment has converged
  route intent and targets the selected metrics node's Grafana backend.
- **Public route artifact:** `orbit-caddy` site rendered on a `ingress` node.
  It terminates public HTTPS on TCP/443 and UDP/443, then reverse proxies to
  the active `router` over WireGuard.
- **Private router artifact:** `orbit-caddy` site rendered on the gateway-coupled
  `router` node. It owns private route artifacts, private `.orbit` service
  hostnames, backend pools, and private HTTP/WebSocket/S3 routing before
  reverse proxying to the backend pool.
- **Private service DNS projection:** Proxy-family artifact at
  `dnsmasq.d/20-proxy-records.conf`. It contains router/private `.orbit`
  directives and exact backend records derived from active router-owned service
  routes. The DNS tool serves this file but does not own its content.
- **Exact backend DNS record:** A hostname-specific address directive that
  bypasses the generic `.orbit` router mapping for a backend that must be
  reached directly. Current records map active S3 backend hostnames such as
  `storage-1.s3.orbit` to the backend node's WireGuard address.
- **Private backend artifact:** `orbit-caddy` site rendered on an `app-prod`
  node. It listens on HTTP port `80` bound to the node's WireGuard address and
  serves the app ingress contract to a backend FrankenPHP container. Workspace
  routes are an `app-dev`-only surface and never receive an `app-prod` backend
  artifact.
- **Route enactment state:** Persisted operation evidence attached to route
  intent. For one-step custom add, the normal path is `pending` then
  `converged` on success, or `failed`/`partial` when backend/TLS apply fails
  (Doctor repairs that partial state). `pending` means no operation has
  completed, `partial` means some operations completed before a named failure,
  `failed` means the first operation failed, and `converged` means every planned
  operation completed. `intent_only` remains only for older custom rows that
  never recorded one-step enactment evidence; new custom adds do not use it as
  the happy path. `unknown` is an existing row without enactment evidence.
- **Production enactment order:** Project production artifacts are applied backend
  first, router second, and ingress last. Orbit never reports convergence if
  any layer fails and records the exact layer, node, and operation for repair.
- **Router backend pool:** Ordered list of URLs for app-prod backends.
  The router owns this pool. V1 creates one target but stores a list.
- **WebSocket backend pool:** Ordered list of TLS websocket backend URLs using
  WireGuard IP targets such as `https://10.6.0.4:8080`, owned by `router`. V1
  supports one active backend and fails clearly if more than one websocket
  backend is active. The pool shape remains so later multi-node Reverb scaling
  does not change app or browser configuration.
- **S3 backend pool:** Ordered list of SeaweedFS backend URLs, such as
  `http://storage-1.s3.orbit:8333`, owned by `router`. V1 creates one active
  backend but stores a pool shape so later S3 scaling does not change app or
  client configuration.
- **Metrics service target:** Grafana backend URL owned by `router`, such as
  `http://metrics-1.metrics.orbit:3000`, used by the private `metrics.orbit`
  route. V1 selects one active metrics backend.
- **Analytics backend pool:** Ordered list of Plausible CE backend URLs using
  WireGuard IP targets such as `http://10.6.0.9:8000`, owned by `router`. V1
  supports one active backend and stores a pool shape for later scaling.

## TLS

These terms define certificate authority, leaf certificate scope, and hostname compatibility material.

- **Orbit-managed TLS:** Gateway-issued route leaf certificate and key material
  applied on the serving node. Certificates chain to the gateway root CA
  trusted through `gateway:add` and `gateway:trust`.
- **Route leaf certificate:** A server certificate issued for one Orbit route
  host or IP with a 397-day validity period. It can terminate HTTPS for that
  route, but it cannot sign other certificates. Proxy convergence replaces
  leaves outside Orbit's current issuance window.
- **Intermediate CA certificate:** A certificate with signing authority below
  the gateway root CA. Orbit does not issue intermediate CA certificates to app
  nodes for routine proxy serving because that would let a compromised node
  mint trusted certificates outside its route ownership.
- **TLS authority boundary:** The gateway owns certificate signing authority.
  Nodes receive route-scoped leaf certificates and private keys as serving
  artifacts only; they do not act as Orbit certificate authorities.
- **Hostname compatibility material:** Instance-role files derived from route TLS
  configuration that let common Laravel Vite TLS detection paths find the route
  certificate. Owned by proxy convergence, not by the app or workspace family.

## Ingress Contracts

These terms define the ingress behavior applied to app and workspace routes.

- **Project ingress baseline:** Standard browser ingress contract applied to project and instance
  and workspace routes: TLS termination, dynamic routing to the resolved
  FrankenPHP runtime container,
  static file serving from the configured document root, baseline security
  headers, sensitive-file blocking, profiling timing markers, and immutable
  caching for `/build/*`.
- **Document-root policy:** Route-level policy that determines how aggressively
  ingress blocks adjacent sensitive files. Public-document-root instances and
  workspaces use the lighter policy; project-root instances and workspaces use the
  stronger blocking policy.
- **Development runtime wake gate:** App-instance and workspace routes rendered
  on `app-dev` use only standard Caddy directives. A node-local awake marker
  in Caddy's ephemeral shared-memory filesystem lets an active scope bypass the
  gateway. An absent marker after a Caddy or host restart causes a bounded
  `forward_auth` request to the private gateway activation endpoint before the
  original browser request continues. The endpoint accepts only the exact
  serving node's WireGuard identity and serializes against idle shutdown. When
  the scope is already awake, it returns success immediately so the original
  request continues. When soft (recent-idle) or cold (dependency rebuild) wake
  work is required, the pre-check starts or follows one serialized wake
  operation for that scope and returns a minimal no-store HTML response
  immediately, without starting processes or restoring dependencies inline.
  That response stays mounted and, after five seconds, probes the original
  same-origin path and query with non-overlapping background fetches
  (credentials same-origin, cache no-store, redirect manual so application
  redirects including cross-origin login/OAuth do not trap fetch without an
  Orbit pending header). Pending and failed Orbit responses set
  `X-Orbit-Runtime-Activation-State` so application status codes alone
  (including application 503) are not treated as Orbit pending. Pending keeps
  the page; network errors retry after five seconds; failed is terminal with
  the existing retry action; a response without the header (or an opaque
  redirect) means handoff to the application and triggers one browser
  navigation to the original URI. It
  presents one indeterminate animated Orbit mark only: no soft/cold
  distinction, aggregate progress bar, step rows, diagnostics, commands,
  filesystem paths, environment values, or raw logs. Soft and cold share the
  same page and operation machinery; the plan records the mode. Soft runners
  only fence process activation. Cold runners restore and verify dependencies,
  fence process activation, and clear that scope's cold marker only after ready.
  After the pre-check succeeds, the reverse-proxy handoff retries failed
  upstream connections for up to 15 seconds so the original request can span
  container warm-up without requiring a custom Caddy module. Failed activation
  retains the cold or asleep gate and presents a retry action; a later request
  replaces a detached activation runner only after its progress heartbeat
  expires and both its source dependency fence and scope activation fence are
  available. One sibling scope never clears another sibling's cold gate.
- **Development runtime activity:** Every app-development instance or workspace
  route writes a dedicated access log whose modification time is the last HTTP
  activity for that scope. Activity logs remain in Caddy's persistent data
  mount, while awake and hibernated markers are ephemeral. Multiple domains
  share the same scope identity.
  Background HTTP polling counts as activity; a WebSocket reconnect by itself
  is not a wake signal. The process family consumes this state in a ten-minute
  sweep to enforce the one-hour idle policy and combines it with lifecycle and
  source-tree activity before applying the seven-day cold-dependency policy.

## Boundaries

These terms define what the proxy family owns and what remains outside its scope.

- **Proxy-family boundaries:** Proxy commands own the unified ingress
  registry, route TLS configuration, ingress contracts, and convergence of derived
  proxy and TLS artifacts. The family also owns
  `dnsmasq.d/20-proxy-records.conf` for router/private `.orbit` and exact
  backend DNS records. It uses the shared ownership-neutral DNS materializer
  and restart path when that projection changes. It does not own project, instance WebSocket binding, project
  analytics binding, workspace, gateway, websocket service, S3 service,
  analytics service, or tool identity, do not create or remove owner-side
  records, and do not manage TCP tool service endpoints or firewall policy.

  Public WebSocket hosts are ingress routes that forward to router. Router owns
  `websocket.orbit`, websocket backend pools, and private router-to-websocket
  TLS verification. Public S3 hosts are ingress routes that forward to router.
  Router owns `s3.orbit`, S3 backend pools, S3 upload-compatible proxy settings,
  and private router-to-SeaweedFS routing. Router also owns the private
  `metrics.orbit` route to Grafana; metrics has no public ingress route in this
  slice.

  Public analytics hosts are ingress routes that forward to router and preserve
  forwarding identity for Plausible event attribution. Router owns
  `analytics.orbit`, analytics backend pools, private router-to-Plausible
  routing, and public path selection that allows tracking only. Ingress must not
  route directly to websocket, s3, metrics, or analytics role nodes.
