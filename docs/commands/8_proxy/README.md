# Proxy Commands

Proxy commands expose Orbit's HTTP ingress registry. The command family is
`proxy:*`; the durable state family and doctor key is `proxy_route`.

Caddy is the current proxy backend. It is not the product model.

## Domain Rules

- The proxy command family owns the `proxy:*` command prefix.
- `proxy_route` is the canonical registry of every hostname Orbit exposes.
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
- Custom routes are created, updated, and removed through `proxy:add` and
  `proxy:remove`.
- Redirects are custom proxy routes with kind `redirect`; they are created by
  `proxy:add --redirect=<url>`, listed through `proxy:list --filter=redirect`,
  and removed by `proxy:remove`.
- Proxy writes mutate gateway-tracked configuration first, then enact proxy and
  TLS artifacts on the owning node.
- Orbit-managed TLS means gateway-issued route leaf certificate and key
  material on the serving node. Those certificates chain to the gateway root
  CA trusted by `gateway:add` and `gateway:trust`.
- Proxy reads use gateway intent by default. Live proxy backend reality belongs
  to `doctor --family=proxy_route`.
- Backend discovery/import is not part of the proxy command surface. Adoption
  of observed backend routes must use explicit
  `doctor --family=proxy_route --adopt` semantics.

## Proxy Route JSON Entity

Proxy JSON renderers that return one route entity embed this shape under
`success.data.route`, or directly under `success.data.routes[]` for list items.

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
| `status` | string | Gateway-intent status, not live backend verification. |

## Commands

1. [`orbit proxy:list`](1_proxy-list/proxy-list.md)
2. [`orbit proxy:add <domain> --upstream=<url>`](2_proxy-add/proxy-add.md)
3. [`orbit proxy:remove <domain>`](3_proxy-remove/proxy-remove.md)

## Related

- [`doctor --family=proxy_route`](proxy-doctor.md)
- [`orbit app:*`](../5_app/README.md)
- [`orbit workspace:*`](../6_workspace/README.md)
- [`orbit tool:*`](../3_tool/README.md)
