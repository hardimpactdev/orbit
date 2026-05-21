# WebSocket Role Design

## Goal

Add a `websocket` node role that provides Orbit-managed Laravel Reverb
infrastructure for apps in the fleet.

The role gives apps a stable private realtime endpoint, optional branded public
WebSocket hostnames, per-app Reverb credentials, and a Redis-backed runtime
shape that can scale horizontally without changing app configuration.

## Current Context

Orbit already has a `reverb` tool catalog entry, but a reusable fleet
WebSocket service is bigger than a generic installable tool. The desired product
shape is a node role:

- a `websocket` node runs a default Laravel application configured with Laravel
  Reverb;
- apps and VPN clients use `websocket.orbit`;
- public clients use app-owned branded hosts such as `ws.example.com`;
- public traffic enters through `ingress`;
- private service routing and backend pools are owned by the `router` role;
- Redis lives on a `database` role node and is selected by the `websocket`
  role.

This design depends on the router addendum to the ingress plan:
`docs/superpowers/plans/2026-05-21-ingress-router-addendum.md`.

## Product Decisions

- Add a node role named `websocket`.
- Display the role as `WebSocket`.
- `websocket` is a private workload role. It does not expose public listeners.
- `websocket` is a dev-services-compatible private workload role in v1: it
  can combine with `app-development`, `database`, and `s3`, but it does not
  combine with `gateway`, `vpn`, `router`, `ingress`, `app-production`, or
  `agent`.
- `websocket` may be assigned through `node:new` and `node role:add` when the
  target node has no conflicting role assignments.
- The role requires a Redis service on a node with the `database` role.
- The role setting is `redis_node_id`, pointing at the selected database node
  that owns the Redis service.
- Reverb uses Redis-backed scaling configuration from day one, even with one
  websocket node.
- V1 supports one active websocket backend. Route and config state must use a
  backend-pool shape so adding a second websocket node later does not require
  app config changes.
- Apps never target a concrete websocket node. Apps target
  `https://websocket.orbit`.
- Public clients never target a concrete websocket node. Public clients target
  app-owned public hosts such as `wss://ws.example.com`.
- Ingress forwards public WebSocket hosts to router, not directly to
  websocket nodes.
- Router owns websocket backend-pool selection.
- Reverb binds only to the websocket node's WireGuard address.
- Reverb must not bind `0.0.0.0` or a public interface.
- WebSocket routing uses TLS everywhere, including private app-to-router and
  router-to-websocket traffic.
- Orbit issues backend leaf certificates for stable backend names such as
  `ws-1.websocket.orbit`.
- App Reverb credentials are per app. A leaked Reverb secret for one app must
  not compromise other apps.
- WebSocket app binding is explicit. Realtime is not automatically enabled for
  every app.

## Role Compatibility

V1 role compatibility adds `websocket` as a dev-services-compatible private
workload role:

| Role | Combines with | Conflicts with |
| --- | --- | --- |
| `websocket` | `app-development`, `database`, `s3` | `gateway`, `vpn`, `router`, `ingress`, `app-production`, `agent` |

When merged with the router/ingress/S3 matrix, every other role except
`app-development`, `database`, and `s3` must list `websocket` in its conflicts
list. The `s3` role may co-locate with `websocket` on dev-services topology
nodes.

## Role Settings

The `websocket` role stores one required setting:

```php
[
    'redis_node_id' => 12,
]
```

`redis_node_id` must reference an active node with the `database` role and an
installed or expected-installed `redis` tool row.

The role does not store public hostnames, app credentials, or allowed origins.
Those are app-owned WebSocket binding state.

## Runtime Baseline

The `websocket` baseline owns:

- a dedicated default Laravel application for Reverb runtime;
- Laravel Reverb installation and configuration;
- a private TLS backend listener bound to the node's WireGuard address;
- backend certificate/key material for the node's stable websocket backend
  name;
- Supervisor or the current Orbit process manager entry that keeps
  `php artisan reverb:start` running;
- app credential material rendered into Reverb config;
- Redis connection configuration that points at the selected Redis service;
- firewall posture that permits Reverb traffic only through the Orbit private
  network and router path.

Suggested steady-state paths:

```text
/opt/orbit/websocket/current
/etc/orbit/websocket/reverb.env
/etc/orbit/certs/ws-1.websocket.orbit.crt
/etc/orbit/certs/ws-1.websocket.orbit.key
```

Suggested runtime command:

```bash
php artisan reverb:start --host=<wireguard-ip> --port=8080 --hostname=ws-1.websocket.orbit
```

The exact process manager backend should follow the product contract current at
implementation time. In the current host-native stack, Supervisor is the
expected process manager for long-running PHP processes.

## Service Names

Router owns these private names:

```text
websocket.orbit        -> router-owned stable service endpoint
ws-1.websocket.orbit   -> websocket node 1 WireGuard IP
ws-2.websocket.orbit   -> websocket node 2 WireGuard IP, when scaling lands
```

`websocket.orbit` routes to the websocket backend pool. Concrete backend names
resolve to the owning websocket node's WireGuard address and are used for TLS
identity verification.

## Traffic Shape

Private app publishing:

```text
app node
  -> https://websocket.orbit
  -> router
  -> https://ws-1.websocket.orbit:8080
  -> Reverb
  -> Redis on database role node
```

Public client subscription:

```text
browser
  -> wss://ws.example.com
  -> ingress
  -> router
  -> wss://ws-1.websocket.orbit:8080
  -> Reverb
  -> Redis on database role node
```

Scaling later adds backends without changing app or browser configuration:

```text
websocket.orbit
  -> router
  -> ws-1.websocket.orbit:8080
  -> ws-2.websocket.orbit:8080
  -> ws-3.websocket.orbit:8080
```

Redis coordinates Reverb nodes. Redis does not route client connections.

## App Binding

An app WebSocket binding enables one app to use the fleet websocket service.
The binding owns:

- app id;
- enabled status;
- Reverb app id;
- Reverb app key;
- encrypted Reverb app secret;
- allowed origins;
- public hosts;
- internal service host, always `websocket.orbit` in v1.

Suggested table shape:

```text
app_websocket_bindings
  id
  app_id unique
  enabled boolean
  reverb_app_id string unique
  reverb_app_key string unique
  reverb_app_secret text encrypted by model cast
  allowed_origins json
  public_hosts json
  created_at
  updated_at
```

The default public host for a production app may be derived as
`ws.<app-domain>`, but explicit host input must be supported.

Example binding for `example.com`:

```json
{
  "app": "docs",
  "internal_host": "websocket.orbit",
  "public_hosts": ["ws.example.com"],
  "allowed_origins": ["https://example.com"],
  "reverb_app_id": "docs",
  "reverb_app_key": "<public-client-key>",
  "reverb_app_secret": "<encrypted-server-secret>"
}
```

## App Configuration

App server-side publishing uses the router-owned private service endpoint:

```env
REVERB_APP_ID=<app-reverb-id>
REVERB_APP_KEY=<app-reverb-key>
REVERB_APP_SECRET=<app-reverb-secret>
REVERB_HOST=websocket.orbit
REVERB_PORT=443
REVERB_SCHEME=https
```

Browser-side Echo configuration uses the app-owned public host:

```env
VITE_REVERB_APP_KEY=<app-reverb-key>
VITE_REVERB_HOST=ws.example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

Development or VPN-only usage may use `websocket.orbit` as the frontend host
when no ingress route is enabled.

## Commands

### `node:new --role=websocket`

Creates or adopts a private websocket node.

```bash
orbit node:new ws-1 --role=websocket --redis-node=db-1
```

The command fails before side effects when:

- no active `router` role exists;
- `--redis-node` is missing in non-interactive mode;
- the selected Redis node is not an active `database` role node;
- the selected Redis node has no installed or expected-installed `redis` tool
  row;
- the role conflicts with another target node role.

### `node role:add <node> websocket`

Adds `websocket` to an existing compatible node.

```bash
orbit node role:add ws-1 websocket --redis-node=db-1
```

### `app:websocket enable`

Enables websocket configuration for one app.

```bash
orbit app:websocket enable docs --host=ws.example.com
```

The command creates or updates the app WebSocket binding, generates per-app
credentials, registers public route intent when `ingress` exists, and
registers router-side binding intent.

### `app:websocket credentials`

Returns app WebSocket connection metadata and credentials.

```bash
orbit app:websocket credentials docs --json
```

Human output must not print the app secret unless the command is explicitly a
credentials command. JSON output includes secret material only for callers with
the required credential permission.

### `app:websocket disable`

Disables public and private websocket binding for one app without deleting app
history.

```bash
orbit app:websocket disable docs
```

## Router Contract

Router renders:

- `websocket.orbit` service route;
- backend-pool routes for active websocket nodes;
- public-host relay intent received from ingress/app binding state;
- TLS trust configuration for websocket backend certificates;
- WebSocket upgrade-compatible reverse proxy configuration.

Router must not route WebSocket traffic to a direct node chosen by app config.
Backend selection belongs to router.

## Ingress Contract

Ingress renders public WebSocket host routes such as `ws.example.com`.
Those routes terminate public TLS and forward to router over the private
WireGuard path.

Ingress does not own websocket backend pools and does not route directly
to websocket nodes.

## Doctor And Drift

`doctor --family=node` owns websocket role baseline drift:

- role assignment status;
- supported platform;
- WireGuard address availability;
- private bind address;
- TLS backend certificate/key presence;
- firewall posture;
- baseline runtime files.

`doctor --family=tool` or app/proxy doctor surfaces own runtime and route
health until a dedicated websocket family is justified:

- Reverb process running;
- Reverb accepts TLS on `ws-1.websocket.orbit:8080`;
- backend cert chains to Orbit CA and matches backend name;
- selected Redis service reachable;
- Reverb scaling enabled;
- router has `websocket.orbit` route;
- router backend pool includes active websocket node;
- ingress has public WebSocket host routes for enabled bindings.

## Failure Modes

| Failure | Behavior |
| --- | --- |
| Missing active router | `node:new --role=websocket` and `app:websocket enable` fail before side effects. |
| Missing Redis selection | Non-interactive websocket role assignment fails validation. |
| Selected node lacks `database` role | Websocket role assignment fails validation. |
| Redis down | Websocket role converges, but doctor reports runtime unhealthy. |
| Backend certificate mismatch | Router route is unhealthy and doctor reports TLS drift. |
| No ingress role | Private `websocket.orbit` may work; public host enable fails. |
| No active websocket node | `app:websocket enable` fails with `required_role=websocket`. |
| Reverb binds public interface | Doctor reports security drift and restore re-renders private bind config. |

## Test Plan

Unit and feature coverage should include:

- `websocket` role registry definition and conflicts;
- role settings validation for `redis_node_id`;
- `node:new --role=websocket` requiring Redis/database node input;
- `node role:add websocket` rejecting conflicting roles;
- role convergence rendering private WireGuard bind configuration;
- role convergence rendering TLS backend certificate intent;
- router rendering `websocket.orbit` to a websocket backend pool;
- ingress forwarding app public WebSocket hosts to router;
- app WebSocket binding generating per-app credentials;
- credentials not shared across apps;
- Redis-backed Reverb scaling config present for one websocket backend;
- second websocket backend added to pool without changing app binding
  credentials.

E2E coverage should include:

```text
browser/client connects to wss://ws.example.com through ingress
app publishes through https://websocket.orbit
client receives the event
```

## Non-Goals

- No arbitrary WebSocket application hosting.
- No non-Reverb WebSocket backend in v1.
- No public exposure directly from websocket nodes.
- No direct app configuration against concrete websocket nodes.
- No Redis ownership inside the `websocket` role.
- No multi-websocket-node scheduling or autoscaling in v1.
- No TCP database routing implementation in this role.
- No custom Caddy layer-4 module as part of this role.
