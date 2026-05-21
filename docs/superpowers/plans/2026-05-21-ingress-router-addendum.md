# Ingress Router Addendum

> Addendum to `docs/superpowers/plans/2026-05-21-ingress-role.md`.

## Status

This addendum changes the ingress implementation direction before the
initial ingress role lands.

The current plan routes public production traffic directly from
`ingress` to app-production backend pools. That should change.
`ingress` should be the public edge only. A new `router` role should own
private DNS and private service routing. In v1, `router` is gateway-coupled and
assigned automatically with the first gateway, like `vpn`.

## Revised Architecture

Role responsibilities:

- `gateway`: fleet authority, API, durable state, CA material, and convergence
  decisions.
- `vpn`: WireGuard access and peer connectivity.
- `router`: private `.orbit` DNS, private HTTP/WebSocket routing, and private
  service routing contracts.
- `ingress`: public 80/443 edge, public TLS, public hardening, and
  forwarding public traffic to `router`.
- `app-production`: private app runtime backend.
- `database`: database and cache service runtimes.
- `websocket`: future Reverb runtime backend.

Initial gateway bootstrap assigns:

```text
gateway + vpn + router
```

`router` is visible in `node role:list`, but normal role mutation commands
cannot add, update, or remove it independently in v1. Moving `router` away from
the gateway is a future feature.

## Traffic Shape

Public app traffic:

```text
Browser
  -> ingress Caddy: public HTTPS and edge policy
  -> router Caddy: private route and backend-pool selection over WireGuard
  -> app-production Caddy: private HTTP backend over WireGuard
  -> PHP-FPM
```

Public WebSocket traffic, when the future `websocket` role exists:

```text
Browser
  -> ingress Caddy: public WSS and edge policy
  -> router Caddy: private WebSocket backend-pool selection over WireGuard
  -> websocket node: Laravel Reverb
  -> Redis on database role node
```

Private service traffic:

```text
Node or VPN client
  -> <service>.orbit
  -> router
  -> owning service node
```

Direct node-local hostnames such as `<app>.<node-tld>` may still point directly
to the node that owns that TLD. They are useful for direct private access and
inspection, but they are not the scaling contract. Stable app and service
dependencies should use router-owned service names.

## Superseded Ingress Plan Items

The original plan says public production app routes have:

- public route artifacts on `ingress`;
- private backend artifacts on `app-production`;
- backend pools rendered by `ingress`.

Replace that with:

- public route artifacts on `ingress` terminate public TLS and forward
  to the active `router` role over WireGuard;
- private route artifacts on `router` own backend pools and route selection;
- private backend artifacts on `app-production` serve the app runtime contract;
- ingress Caddy does not need to know the app-production backend pool.

The backend pool concept remains, but its owner moves from `ingress` to
`router`.

## Role Compatibility

V1 role compatibility should add `router` as a gateway-coupled infrastructure
role:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `gateway` | `vpn`, `router` | `app-development`, `app-production`, `database`, `agent`, `ingress` |
| `vpn` | `gateway`, `router` | `app-development`, `app-production`, `database`, `agent`, `ingress` |
| `router` | `gateway`, `vpn` | `app-development`, `app-production`, `database`, `agent`, `ingress` |
| `app-development` | `database` | `gateway`, `vpn`, `router`, `app-production`, `agent`, `ingress` |
| `app-production` | `ingress` | `gateway`, `vpn`, `router`, `app-development`, `database`, `agent` |
| `database` | `app-development` | `gateway`, `vpn`, `router`, `app-production`, `agent`, `ingress` |
| `agent` | none | `gateway`, `vpn`, `router`, `app-development`, `app-production`, `database`, `ingress` |
| `ingress` | `app-production` | `gateway`, `vpn`, `router`, `app-development`, `database`, `agent` |

This v1 matrix keeps `router` coupled to the gateway host. A future router
move-out feature may relax these rules.

## Router Baseline

The `router` baseline owns:

- Caddy as the private HTTP/WebSocket router;
- private `.orbit` DNS service ownership previously described as gateway or
  vpn-coupled DNS behavior;
- private service hostnames such as `websocket.orbit`, `redis.orbit`,
  `postgres.orbit`, and `mysql.orbit`;
- private route artifacts and backend pools for HTTP and WebSocket services;
- private service-routing contracts for TCP services.

Implementation note: Caddy can handle HTTP and WebSocket proxying. Raw TCP
services such as Redis, Postgres, and MySQL need DNS-only routing, a TCP proxy,
or protocol-specific routing. Do not force those through ordinary Caddy
`reverse_proxy`. The current ingress slice only needs HTTP/WebSocket
routing through `router`; full TCP service routing can be a follow-up router
feature.

## Ingress Baseline

The `ingress` baseline owns:

- public Caddy listener policy for 80/443;
- public TLS and public edge hardening;
- public host routing to the active `router` role;
- public firewall posture.

The `ingress` baseline does not own backend-pool selection for
app-production or future websocket nodes. It forwards eligible public traffic to
`router`.

## Command And State Impact

Add to the ingress implementation plan:

- Add `router` to `NodeRoleName`.
- Add a `RouterRoleBaseline`.
- Register `router` in `NodeRoleRegistry` as gateway-coupled and not
  independently mutable.
- Assign `router` during first gateway bootstrap together with `gateway` and
  `vpn`.
- Show `router` in `node role:list`.
- Reject `node role:add router` and `node role:remove router` in v1 with the
  same gateway-coupled-role semantics used by `vpn`.
- Move gateway-owned private DNS wording in docs to router-owned private DNS.
- Change ingress route rendering so public route artifacts upstream to
  the active router, not directly to app-production nodes.
- Add router route rendering for private app backend pools.

Do not add the `websocket` role in the current ingress implementation
unless the ingress worker has already finished the router contract and
the scope is explicitly expanded.

## WebSocket Consequence

The future `websocket` role should use the router model from day one:

- apps and VPN clients target `websocket.orbit`;
- public users target branded hostnames such as `ws.example.com`;
- ingress forwards branded public WebSocket hosts to `router`;
- router forwards to the websocket backend pool;
- each websocket node runs Laravel Reverb and connects to Redis on a
  `database` role node;
- Redis coordinates Reverb nodes but does not route client connections.

This avoids direct app configuration against `websocket-1` and makes a future
second websocket node a backend-pool change instead of an app config migration.

## Performance Note

The router path adds one private hop:

```text
visitor -> ingress -> router -> app node
```

Orbit should document that placement affects latency, but v1 should not enforce
router/workload locality. Operators may tune WireGuard peer endpoints or
deployment placement separately. Locality metadata and warnings are future
topology work, not ingress validation.

## Acceptance Criteria Additions

- First gateway bootstrap creates active `gateway`, `vpn`, and `router` role
  assignments.
- `node role:list <gateway>` shows `router`.
- `node role:add <node> router` fails because `router` is gateway-coupled in
  v1.
- Ingress route artifacts forward to the active router over WireGuard.
- Router route artifacts own backend pools for app-production routes.
- App-production backend artifacts remain private HTTP over WireGuard.
- Docs describe private `.orbit` DNS as router-owned, not gateway-owned.
- Docs make clear that TCP service names such as `redis.orbit` are
  router-owned service contracts but not ordinary HTTP reverse-proxy routes.
