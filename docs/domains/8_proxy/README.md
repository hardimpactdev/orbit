# Proxy Commands

Proxy commands expose Orbit's HTTP ingress registry. The command family is `proxy:*`; the durable state family and doctor key is `proxy`.

The `orbit-caddy` container is the current proxy backend. It is not the product model.

## Domain Rules

These rules govern ownership, route kinds, and the boundaries of the proxy command family.

- The proxy command family owns the `proxy:*` command prefix.
- The `proxy` state family is the canonical registry of every hostname Orbit
  exposes.
- Every proxy route has an owner: `app`, `app-websocket`, `workspace`,
  `gateway`, `websocket`, `s3`, `tool`, or `custom`.
- Every proxy route has a kind: `app`, `workspace`, `internal`, `proxy`, or
  `redirect`.
- `proxy:list` shows all proxy routes by default, including app routes,
  workspace routes, gateway/internal routes, tool-owned routes, custom upstream
  routes, and redirects.
- `proxy:list --filter=<filter>` narrows the unified view. Supported filters are
  `all`, `app`, `app-websocket`, `workspace`, `gateway`, `websocket`, `s3`,
  `tool`, `custom`, and `redirect`.
- App, workspace, gateway, and tool-owned routes are visible through proxy
  commands but edited through their owning domain commands.
- App WebSocket routes are visible through proxy commands but edited through
  app WebSocket binding commands. Public WebSocket hosts are `ingress` routes
  that forward to `router`; they must not route directly to websocket role
  nodes.
- Router-owned websocket service routes are visible through proxy commands but
  edited by websocket route convergence. Router owns `websocket.orbit`,
  websocket backend pools, and private router-to-websocket TLS verification.
- S3 public hosts and router-owned S3 service routes are visible through proxy
  commands but edited by S3 route convergence. Public S3 hosts are `ingress`
  routes that forward to `router`; they must not route directly to s3 role
  nodes. Router owns `s3.orbit`, S3 backend pools, S3 upload-compatible proxy
  settings, and private router-to-RustFS routing.
- Tool-owned `proxy` routes are HTTP or WebSocket ingress routes only. TCP tool
  service endpoints such as PostgreSQL, MySQL, and Redis are WireGuard service
  endpoints owned by the tool catalog and do not appear as HTTP proxy routes.
- Custom routes are created, updated, and removed through `proxy:add` and
  `proxy:remove`.
- Redirects are custom proxy routes with kind `redirect`; they are created by
  `proxy:add --redirect=<url>`, listed through `proxy:list --filter=redirect`,
  and removed by `proxy:remove`.
- Proxy writes mutate gateway-tracked configuration first, then apply proxy and
  TLS artifacts on the owning node.
- Orbit-managed TLS means gateway-issued route leaf certificate and key
  material on the serving node. Those certificates chain to the gateway root
  CA trusted by `gateway:add` and `gateway:trust`.
- Orbit intentionally issues route leaf certificates directly from the gateway
  root CA. It must not issue nodes intermediate CA certificates for
  routine route serving, because a node with an app role with intermediate signing
  authority could mint trusted certificates for arbitrary hosts if compromised.
- Nodes serve TLS material only. They do not become certificate authorities
  and do not sign certificates for apps, workspaces, or tools.
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
  `doctor --fix --family=proxy --adopt` semantics.

## Permissions

Proxy API requests are authorized against the route's serving node.

- `proxy:read` covers `proxy:list`; row-level filtering applies when the caller
  has access to only some serving nodes.
- `proxy:add` covers custom proxy and redirect route creation on the selected
  serving node.
- `proxy:remove` covers removal of custom proxy and redirect routes on the
  route's owning node.

Authorization failures use `authorization_failed` with standard
`missing_permission` metadata.

## App and workspace ingress baseline

App and workspace proxy routes are not generic reverse proxies. They provide the standard Orbit browser ingress contract for PHP-backed apps and workspaces:

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

Document-root policy is part of the route contract. Apps or workspaces that serve from a public document root keep project-root files outside the web root and still block adjacent sensitive files such as environment files, VCS metadata, and local entrypoints. Apps or workspaces that intentionally serve from the project root receive the stronger project-root blocking policy for framework config, storage, dependencies, source metadata, and local entrypoints.

Custom, redirect, and tool routes are separate route kinds. They may share TLS, DNS, and inventory behavior with app/workspace routes, but they do not inherit the PHP document-root contract unless their own command docs say so.

- **Public route artifact:** `orbit-caddy` site rendered on a `ingress` node.
  It terminates public HTTPS and reverse proxies to the active `router` over
  WireGuard.
- **Private router artifact:** `orbit-caddy` site rendered on the gateway-coupled
  `router` node. It owns private route artifacts, private `.orbit` service
  hostnames, backend pools, and private HTTP/WebSocket/S3 routing before
  reverse proxying to the backend pool.
- **Private backend artifact:** `orbit-caddy` site rendered on an `app-production`
  node. It listens on HTTP port `80` bound to the node's WireGuard address and
  serves the app/workspace ingress contract to a backend FrankenPHP container.
- **Router backend pool:** Ordered list of URLs for app-production backends.
  The router owns this pool. V1 creates one target but stores a list.
- **WebSocket backend pool:** Ordered list of TLS websocket backend URLs, such
  as `https://ws-1.websocket.orbit:8080`, owned by `router`. V1 creates one
  target but stores a list.
- **S3 backend pool:** Ordered list of RustFS backend URLs, such as
  `http://storage-1.s3.orbit:9000`, owned by `router`. V1 creates one target
  but stores a list.

## TLS Authority Model

The gateway is the only Orbit certificate authority. For each managed route, it issues a leaf certificate whose SAN matches that route host or IP, then applies the certificate and private key on the serving node as route-scoped TLS material. The serving node configures `orbit-caddy` with that explicit certificate and key; it does not use Caddy's local CA for Orbit-managed routes.

Orbit does not delegate intermediate CA authority to nodes. That delegation would make disconnected node-local certificate minting easier, but it would also expand the blast radius of a compromised node: the node could sign trusted certificates for hosts it should not control. Leaf certificates issued by the gateway for each route keep signing authority centralized on the gateway while still letting every node terminate HTTPS locally.

## Gateway Internal Ingress

Gateway-owned internal routes are proxy inventory, but their product purpose is the gateway API. Internal gateway ingress must bind to the gateway's Orbit network address, not become a public application route. It must preserve the WireGuard identity model by removing forwarded-client identity headers before the request reaches the gateway API.

Long-lived gateway streams, such as progress or log streams, must not consume
the same execution lane as short command/API requests. The gateway
`orbit-caddy` container forwards API traffic to the gateway `orbit-runtime`
container, and the product contract is that streaming traffic cannot starve
ordinary gateway API execution.

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
  "status": "expected"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `domain` | string | Hostname or host/path route identity. |
| `kind` | `app`, `workspace`, `internal`, `proxy`, or `redirect` | Route behavior at ingress. |
| `owner.type` | `app`, `app-websocket`, `workspace`, `gateway`, `websocket`, `s3`, `tool`, or `custom` | Domain that owns route lifecycle. |
| `owner.name` | string \| null | Owning app, app WebSocket binding, workspace, gateway route, websocket service, S3 service, or tool identity when applicable. |
| `node` | string | Serving node where proxy artifacts are expected. |
| `target.type` | string | Target behavior, such as `upstream`, `redirect`, `app`, `workspace`, `gateway`, `websocket`, `s3`, or `tool`. |
| `target.value` | string | Upstream URL, redirect URL, or owner-specific target value. |
| `redirect_code` | integer \| null | HTTP redirect status code for redirect routes. |
| `tls` | object | Orbit-managed TLS state expected for the route. |
| `status` | string | Gateway configuration status, not live backend verification. |

## Commands

Each command links to its public documentation and technical contract.

1. [`orbit proxy:list`](1_proxy-list/proxy-list.md)
2. [`orbit proxy:add <domain> --upstream=<url>`](2_proxy-add/proxy-add.md)
3. [`orbit proxy:remove <domain>`](3_proxy-remove/proxy-remove.md)

## Related

- [`doctor --family=proxy`](proxy-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
- [`orbit s3:*`](../19_s3/README.md)
