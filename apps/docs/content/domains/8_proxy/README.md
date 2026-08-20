# Proxy Commands

Proxy commands expose Orbit's HTTP ingress registry. The command family is `proxy:*`; the durable state family and doctor key is `proxy`.

The `orbit-caddy` container is the current proxy backend. It is not the product model.

## Domain Rules

These rules govern ownership, route kinds, and the boundaries of the proxy command family.

- The proxy command family owns the `proxy:*` command prefix.
- The `proxy` state family is the canonical registry of every hostname Orbit
  exposes.
- Every proxy route has a public owner: `instance`, `analytics`, `websocket`,
  `workspace`, `gateway`, `router`, `s3`, `tool`, or `custom`. Persisted primary
  routes use `owner_type=app` and `kind=app`; registry and list rendering
  project both as `instance` only when the complete App, Instance, kind, and
  null Workspace ownership tuple is valid. Conflict and removal metadata carry
  the raw stored owner type, so a valid primary route reports `app` there.
  Invalid tuples also retain their stored owner type in registry metadata.
  Persisted `owner_type=instance` is
  invalid. Convergence and destructive cleanup accept a route only when its
  complete ownership tuple resolves to the intended owner; a domain collision
  with invalid or different ownership is a conflict. The owner value classifies
  which domain's convergence edits the route record; the private
  `websocket.orbit`, `s3.orbit`, and `analytics.orbit` service routes are owned
  by `router`. Router, gateway, tool, S3, and custom tuples require
  `app_id=null`, `workspace_id=null`, and `instance_id=null`. They also require
  the intended serving node and kind. Tool and service families require their
  stable `config.owner_name`, `config.protocol`, and placement identity where
  applicable. Convergence, cleanup, migration normalization, and Doctor restore
  preserve a malformed or differently owned row at the requested domain.
- Every proxy route has a public kind: `instance`, `workspace`, `internal`,
  `proxy`, or `redirect`.
- `proxy:list` shows all proxy routes by default, including instance routes,
  workspace routes, gateway/internal routes, tool-owned routes, custom upstream
  routes, and redirects.
- `proxy:list --filter=<filter>` narrows the unified view. Supported filters are
  `all`, `instance`, `workspace`, `gateway`,
  `websocket`, `s3`, `analytics`, `tool`, `custom`, and `redirect`.
  `websocket`, `s3`, and `analytics` are service filters:
  `websocket` selects the router-owned `websocket.orbit` service route, and
  `s3` selects the router-owned `s3.orbit` service route plus public S3 host
  routes, and `analytics` selects the router-owned `analytics.orbit` service
  route plus public instance analytics host routes. They are not owner-enum mirrors.
  The router-owned `metrics.orbit` route is visible in the unified/default
  inventory; no dedicated metrics filter is exposed in this slice.
- Workspace-owned routes are visible only when their serving node is active
  `app-dev` and the caller is not `app-prod`. Broad proxy inventory omits a
  forbidden workspace route while retaining supported app, custom, and service
  routes. An explicit `proxy:list --filter=workspace` request fails with
  `workspace.unsupported_for_production` before route facts are returned when
  either side crosses that boundary.
- Instance, workspace, gateway, and tool-owned routes are visible through proxy
  commands but edited through their owning domain commands.
- Every instance-primary route targets one concrete instance. The route uses
  `owner.type=instance` and `owner.name=<app.instance>`, with
  `target.type=instance`, `target.value=<app.instance>`, and `node` naming
  the serving node. A bare app is never a valid primary-route target.
- Instance WebSocket routes are visible through proxy commands but edited through
  instance WebSocket binding commands. Public WebSocket hosts are `ingress` routes
  that forward to `router`; they must not route directly to websocket role
  nodes.
- Instance analytics routes are visible through proxy commands but edited through
  instance analytics binding commands. Public analytics hosts are `ingress` routes
  that forward to `router`, preserve forwarding identity for event attribution,
  and expose only Plausible tracking paths; they must not route directly to
  analytics role nodes or expose the Plausible dashboard publicly.
- Router-owned websocket service routes are visible through proxy commands but
  edited by websocket route convergence. Router owns `websocket.orbit`,
  websocket backend pools, and private router-to-websocket TLS verification.
- S3 public hosts and router-owned S3 service routes are visible through proxy
  commands but edited by S3 route convergence. Public S3 hosts are `ingress`
  routes that forward to `router`; they must not route directly to s3 role
  nodes. Router owns `s3.orbit`, S3 backend pools, S3 upload-compatible proxy
  settings, and private router-to-SeaweedFS routing.
- The router-owned metrics service route is visible through proxy commands but
  edited by metrics role convergence. Router owns `metrics.orbit` and private
  router-to-Grafana routing. Metrics has no public ingress route in this slice.
- Router-owned analytics service routes are visible through proxy commands but
  edited by analytics route convergence. Router owns `analytics.orbit`,
  analytics backend pools, private router-to-Plausible routing, and public
  tracking-only path selection.
- Tool-owned `proxy` routes are HTTP or WebSocket ingress routes only. TCP
  service endpoints such as PostgreSQL, MySQL, and Valkey are WireGuard service
  endpoints owned by process definitions and do not appear as HTTP proxy
  routes.
- Custom routes are created, updated, and removed through `proxy:add` and
  `proxy:remove`.
- Redirects are custom proxy routes with kind `redirect`; they are created by
  `proxy:add --redirect=<url>`, listed through `proxy:list --filter=redirect`,
  and removed by `proxy:remove`.
- Proxy writes mutate gateway-tracked configuration first, then apply proxy and
  TLS artifacts. Production app routes converge in dependency order: backend,
  then router, then ingress. Each completed operation and any failure is
  persisted with its layer, node, and operation identifier.
- Orbit-managed TLS means gateway-issued route leaf certificate and key
  material on the serving node. Those certificates chain to the gateway root
  CA trusted by `gateway:add` and `gateway:trust`.
- Every Orbit-managed route leaf is issued for 397 days. This applies to app,
  workspace, gateway, router, service, tool, analytics, metrics, S3, WebSocket,
  and custom proxy routes that use the Orbit root CA. Convergence replaces
  leaves whose validity period is shorter or longer than the current issuance
  window.
- Orbit intentionally issues route leaf certificates directly from the gateway
  root CA. It must not issue nodes intermediate CA certificates for
  routine route serving, because a node with an app role with intermediate signing
  authority could mint trusted certificates for arbitrary hosts if compromised.
- Nodes serve TLS material only. They do not become certificate authorities
  and do not sign certificates for apps, instances, workspaces, or tools.
- For DNS hostname routes, the TLS managed by Orbit also applies
  compatibility material on the node. That material lets common Laravel
  Vite TLS detection paths find the route certificate.
- DNS hostname compatibility material belongs to proxy convergence because it
  is derived from route TLS configuration. It is not a separate app command.
- Internal IP-only routes receive gateway-issued leaf certificates for the IP
  target and do not require hostname compatibility material.
- Proxy reads use gateway configuration by default. Live proxy backend reality belongs
  to `doctor --family=proxy`.
- Backend discovery/import is not part of the proxy command surface. Adoption
  of observed backend routes must use explicit
  `doctor --family=proxy --adopt` semantics.

## Permissions

Proxy API requests are authorized against the route's serving node.

- `proxy:read` covers `proxy:list`; row-level filtering applies when the caller
  has access to only some serving nodes.
- `proxy:add` covers custom proxy and redirect route creation on the selected
  serving node.
- `proxy:remove` covers removal of custom proxy and redirect routes on the
  route's owning node, and registry rows whose owner record is proven missing.

Authorization failures use `authorization_failed` with standard
`missing_permission` metadata.

## App, instance, and workspace ingress baseline

Instance and workspace proxy routes are not generic reverse proxies. They provide the standard Orbit browser ingress contract for PHP-backed instances and workspaces:

- terminate Orbit-managed TLS for the app or workspace host;
- route dynamic requests to the resolved app/workspace FrankenPHP runtime
  container;
- serve static files from the configured document root;
- for workspace routes, apply the parent app document root relative to the
  workspace path;
- apply baseline browser security headers;
- block direct requests for sensitive project files and framework internals;
- emit profiling timing markers used by Orbit profile workflows;
- cache versioned build assets under `/build/*` with long-lived immutable cache
  headers.

Document-root policy is part of the route contract. Apps or workspaces that serve from a public document root keep app-root files outside the web root and still block adjacent sensitive files such as environment files, VCS metadata, and local entrypoints. Apps or workspaces that intentionally serve from the app root receive the stronger app-root blocking policy for framework config, storage, dependencies, source metadata, and local entrypoints.

Custom, redirect, and tool routes are separate route kinds. They may share TLS, DNS, and inventory behavior with app/workspace routes, but they do not inherit the PHP document-root contract unless their own command docs say so.

- **Public route artifact:** `orbit-caddy` site rendered on a `ingress` node.
  It terminates public HTTPS on TCP/443 and UDP/443, then reverse proxies to
  the active `router` over WireGuard.
- **Private router artifact:** `orbit-caddy` site rendered on the gateway-coupled
  `router` node. It owns private route artifacts, private `.orbit` service
  hostnames, backend pools, and private HTTP/WebSocket/S3 routing before
  reverse proxying to the backend pool.
- **Private backend artifact:** `orbit-caddy` site rendered on an `app-prod`
  node. It listens on HTTP port `80` bound to the node's WireGuard address and
  serves the app ingress contract to a backend FrankenPHP container. Workspace
  routes are an `app-dev`-only surface and never receive an `app-prod` backend
  artifact.
- **Router backend pool:** Ordered list of URLs for app-prod backends.
  The router owns this pool. V1 creates one target but stores a list.
- **WebSocket backend pool:** Ordered list of TLS websocket backend URLs using
  WireGuard IP targets such as `https://10.6.0.4:8080`, owned by `router`. V1
  supports one active backend and fails clearly if more than one websocket
  backend is active.
- **S3 backend pool:** Ordered list of SeaweedFS backend URLs, such as
  `http://storage-1.s3.orbit:8333`, owned by `router`. V1 creates one target
  but stores a list.
- **Metrics service target:** Grafana backend URL owned by `router`, such as
  `http://metrics-1.metrics.orbit:3000`, used by the private
  `metrics.orbit` route.
- **Analytics backend pool:** Ordered list of Plausible CE backend URLs using
  WireGuard IP targets such as `http://10.6.0.9:8000`, owned by `router`. V1
  supports one active backend and stores a pool shape for later scaling.

## TLS Authority Model

The gateway is the only Orbit certificate authority. For each managed route, it issues a 397-day leaf certificate whose SAN matches that route host or IP, then applies the certificate and private key on the serving node as route-scoped TLS material. The serving node configures `orbit-caddy` with that explicit certificate and key; it does not use Caddy's local CA for Orbit-managed routes.

Orbit does not delegate intermediate CA authority to nodes. That delegation would make disconnected node-local certificate minting easier, but it would also expand the blast radius of a compromised node: the node could sign trusted certificates for hosts it should not control. Leaf certificates issued by the gateway for each route keep signing authority centralized on the gateway while still letting every node terminate HTTPS locally.

## Gateway Internal Ingress

Gateway-owned internal routes are proxy inventory, but their product purpose is the gateway API. Internal gateway ingress must bind to the gateway's Orbit network address, not become a public application route. It must preserve the WireGuard identity model by removing forwarded-client identity headers before the request reaches the gateway API.

Long-lived gateway streams, such as progress or log streams, must not consume
the same execution lane as short command/API requests. In `router-colocated`
mode, `orbit-caddy` (owned by the router) forwards gateway API traffic to
`orbit-gateway` over `orbit-network`; in `gateway-direct` mode,
`orbit-gateway` publishes gateway HTTPS directly. In both modes, streaming
traffic cannot starve ordinary gateway API execution.

## Proxy Route JSON Entity

Proxy JSON renderers that return one route entity embed this shape under `success.data.route`, or directly under `success.data.routes[]` for list items.

```json
{
  "domain": "vite.docs.test",
  "kind": "proxy",
  "owner": {
    "type": "custom",
    "name": null
  },
  "node": "app-1",
  "target": {
    "type": "upstream",
    "value": "http://127.0.0.1:5173"
  },
  "redirect_code": null,
  "tls": {
    "managed_by": "orbit",
    "trusted_by_gateway_ca": true
  },
  "status": "converged"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `domain` | string | Hostname or host/path route identity. |
| `kind` | `instance`, `workspace`, `internal`, `proxy`, or `redirect` | Public route behavior. Persisted primary-instance rows use `kind=app` but always project `instance`. |
| `owner.type` | `app`, `instance`, `analytics`, `websocket`, `workspace`, `gateway`, `router`, `s3`, `tool`, or `custom` | Public domain whose convergence edits the route record. Router-owned service routes (`websocket.orbit`, `s3.orbit`, `analytics.orbit`) use `router`; `s3` is used by public S3 host routes and `analytics` by public analytics host routes. |
| `owner.name` | string \| null | Owning app, instance, WebSocket binding, workspace, gateway route, router service, S3 publication, or tool identity when applicable. |

The remaining fields describe placement, backend target, TLS, and status.

| Field | Type | Meaning |
| --- | --- | --- |
| `node` | string | Serving node where proxy artifacts are expected. |
| `target.type` | string | Target behavior, such as `upstream`, `redirect`, `instance`, `workspace`, `gateway`, `websocket`, `s3`, `analytics`, or `tool`. App primary routes always use `instance`. |
| `target.value` | string | Upstream URL, redirect URL, or owner-specific target value. |
| `redirect_code` | integer \| null | HTTP redirect status code for redirect routes. |
| `tls` | object | Orbit-managed TLS state expected for the route. |
| `status` | string | Persisted enactment status, not a live probe. |

`status` values are `unknown`, `intent_only`, `pending`, `partial`, `failed`,
`converged`, and `removed`. Rows without enactment evidence are `unknown`.
Healthy custom `proxy:add` completes as `pending` then `converged`. Apply
failure leaves `failed` or `partial` for doctor repair. `intent_only` remains
only for custom rows that lack one-step enactment evidence. `removed` is the
removal-terminal value returned by successful `proxy:remove` payloads. Orbit
never upgrades registry intent to `converged` (or any enacted status) merely
because the database row exists.

## Commands

Each command links to its public documentation and technical contract:
[`orbit proxy:list`](1_proxy-list/proxy-list.md),
[`orbit proxy:add <domain> --upstream=<url>`](2_proxy-add/proxy-add.md), and
[`orbit proxy:remove <domain>`](3_proxy-remove/proxy-remove.md).

## Related

- [`doctor --family=proxy`](proxy-doctor.md)
- [`orbit app:*` and `orbit instance:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
- [`orbit s3:*`](../18_s3/README.md)
- [`orbit metrics:*`](../19_metrics/README.md)
- [`orbit analytics:*`](../20_analytics/README.md)
