# Proxy Commands

Proxy commands expose Orbit's HTTP ingress registry. The command family is `proxy:*`; the durable state family and doctor key is `proxy`.

Caddy is the current proxy backend. It is not the product model.

## Domain Rules

- The proxy command family owns the `proxy:*` command prefix.
- The `proxy` state family is the canonical registry of every hostname Orbit
  exposes.
- Every proxy route has an owner: `app`, `workspace`, `gateway`, `tool`, or
  `custom`.
- Every proxy route has a kind: `app`, `workspace`, `internal`, `proxy`, or
  `redirect`.
- `proxy:list` shows all proxy routes by default, including app routes,
  workspace routes, gateway/internal routes, tool-owned routes, custom upstream
  routes, and redirects.
- `proxy:list --filter=<filter>` narrows the unified view. Supported filters are
  `all`, `app`, `workspace`, `gateway`, `tool`, `custom`, and `redirect`.
- App, workspace, gateway, and tool-owned routes are visible through proxy
  commands but edited through their owning domain commands.
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
  root CA. It must not issue app nodes intermediate CA certificates for
  routine route serving, because an app node with intermediate signing
  authority could mint trusted certificates for arbitrary hosts if compromised.
- App nodes serve TLS material only. They do not become certificate authorities
  and do not sign certificates for apps, workspaces, or tools.
- For DNS hostname routes, Orbit-managed TLS also includes app-node
  compatibility material that lets common Laravel Vite TLS detection paths find
  the route certificate. This belongs to proxy convergence because it is
  derived from route TLS configuration. It is not a separate app command.
- Internal IP-only routes receive gateway-issued leaf certificates for the IP
  target and do not require hostname compatibility material.
- Proxy reads use gateway configuration by default. Live proxy backend reality belongs
  to `doctor --family=proxy`.
- Backend discovery/import is not part of the proxy command surface. Adoption
  of observed backend routes must use explicit
  `doctor --fix --family=proxy --adopt` semantics.

## App And Workspace Ingress Baseline

App and workspace proxy routes are not generic reverse proxies. They provide the standard Orbit browser ingress contract for PHP-backed apps and workspaces:

- terminate Orbit-managed TLS for the app or workspace host;
- route PHP requests to the resolved app/workspace PHP runtime;
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

## TLS Authority Model

The gateway is the only Orbit certificate authority. For each managed route, it issues a leaf certificate whose SAN matches that route host or IP, then applies the certificate and private key on the serving node as route-scoped TLS material. The serving node configures Caddy with that explicit certificate and key; it does not use Caddy's local CA for Orbit-managed routes.

Orbit does not delegate intermediate CA authority to app nodes. That delegation would make disconnected node-local certificate minting easier, but it would also expand the blast radius of a compromised app node: the node could sign trusted certificates for hosts it should not control. Per-route gateway-issued leaf certificates keep signing authority centralized on the gateway while still letting every node terminate HTTPS locally.

## Gateway Internal Ingress

Gateway-owned internal routes are proxy inventory, but their product purpose is the gateway API. Internal gateway ingress must bind to the gateway's Orbit network address, not become a public application route. It must preserve the WireGuard identity model by removing forwarded-client identity headers before the request reaches the gateway API.

Long-lived gateway streams, such as progress or log streams, must not consume the same execution lane as short command/API requests. The current backend may implement that with separate runtime sockets, but the product contract is that streaming traffic cannot starve ordinary gateway API execution.

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
| `owner.type` | `app`, `workspace`, `gateway`, `tool`, or `custom` | Domain that owns route lifecycle. |
| `owner.name` | string \| null | Owning app, workspace, gateway route, or tool identity when applicable. |
| `node` | string | Serving node where proxy artifacts are expected. |
| `target.type` | string | Target behavior, such as `upstream`, `redirect`, `app`, `workspace`, `gateway`, or `tool`. |
| `target.value` | string | Upstream URL, redirect URL, or owner-specific target value. |
| `redirect_code` | integer \| null | HTTP redirect status code for redirect routes. |
| `tls` | object | Orbit-managed TLS state expected for the route. |
| `status` | string | Gateway configuration status, not live backend verification. |

## Commands

1. [`orbit proxy:list`](1_proxy-list/proxy-list.md)
2. [`orbit proxy:add <domain> --upstream=<url>`](2_proxy-add/proxy-add.md)
3. [`orbit proxy:remove <domain>`](3_proxy-remove/proxy-remove.md)

## Related

- [`doctor --family=proxy`](proxy-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
