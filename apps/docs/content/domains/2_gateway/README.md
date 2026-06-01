# Gateway Commands

Gateway commands manage the caller's relationship with an Orbit gateway after
a node identity that the gateway owns has been established.

The gateway command family owns the `gateway:*` command prefix. It does not own
node provisioning, fleet membership, WireGuard peer issuance, or node drift
repair. Those behaviors remain part of the node lifecycle and node doctor
contracts.

## State Ownership

The gateway command domain does not own a state family. Gateway commands manage
the caller's client-side relationship with gateway-owned node identity, gateway
trust material, and gateway API access policy.

The gateway root CA is the trust anchor for Orbit-managed HTTPS inside the
Orbit network. The gateway owns root CA private material and route certificate
issuance. App, workspace, proxy, gateway, and tool route domains may receive
leaf certificate material that the gateway issues on their serving nodes, but route
applying and route doctor families own those artifacts. Gateway commands only
install or repair caller-local trust for the public root.

[`doctor --family=node`](../1_node/node-doctor.md) owns gateway API readiness,
gateway node identity, WireGuard identity, gateway CA mismatch checks, and node
drift repair. Gateway commands may repair caller-local gateway trust, but they
do not create a gateway doctor family.

The gateway API runtime is the gateway `orbit-runtime` container, exposed on
 the Orbit network through the gateway `orbit-caddy` container. Gateway bootstrap
 ensures `orbit-runtime` is created and running before `orbit-caddy` is
 configured to route HTTPS gateway traffic to it. Gateway commands verify and
 trust that API; they do not install host PHP, host Caddy, or PHP-FPM gateway
 fallbacks, and the gateway API path does not render PHP-FPM sockets or
 `php_fastcgi` upstream configuration.

 ## Streaming under Docker runtime

 The containerized gateway API preserves the existing progress/SSE streaming
 contract with three mechanisms:

 1. **Caddy `flush_interval -1`**: The gateway API Caddy block disables
    response buffering on the `reverse_proxy` to `orbit-runtime`, so SSE frames
    are forwarded immediately.
 2. **No compression on gateway API**: The gateway API Caddy block omits
    `encode zstd gzip` to prevent compression middleware from buffering small
    SSE frames or heartbeats.
 3. **SAPI-conditional flush in PHP**: Streaming controllers and the progress
    factory flush output buffers only under `fpm-fcgi` and `cli-server` SAPI.
    This covers the Docker runtime (`php artisan serve` runs under
    `cli-server`) while avoiding double-flush under FrankenPHP or other SAPIs.
 4. **Multi-process dev server**: The gateway HTTP server inside
    `orbit-runtime` sets `PHP_CLI_SERVER_WORKERS=4` (configurable via
     environment) so that long-lived SSE streams do not starve ordinary API
     requests. The PHP built-in server is single-process by default.

 The product invariant from the proxy domain applies: streaming traffic cannot
 starve ordinary gateway API execution.

 ## Domain Rules

These rules apply to all gateway commands and define the invariants the family enforces.

- Gateway commands must start with the `gateway:` prefix.
- Gateway commands may read gateway-owned node identity and access policy when
  they verify the caller's gateway relationship.
- Gateway commands may write caller-local gateway configuration, trust material,
  and gateway-client metadata.
- Gateway commands may install or refresh local trust for the gateway root CA,
  which is the root that Orbit-managed route certificates chain to.
- Gateway commands must not create gateway node rows, client rows, app
  node rows, WireGuard peer material, or node access grants.
- Gateway commands must not issue, upload, renew, or clean up TLS leaf certificates
  that are scoped to a route; that belongs to the route-owning domain and its doctor
  family.
- First-gateway bootstrap and node identity issuance belong to
  [`node:new`](../1_node/1_node-new/node-new.md).
- Node drift, gateway API reachability drift, and gateway CA mismatch checks
  belong to [`doctor --family=node`](../1_node/node-doctor.md).

## Commands

These are the gateway-family commands available to operator-node callers.

1. Existing gateway onboarding:
   [`orbit gateway:add [gateway_ip]`](1_gateway-add/gateway-add.md)
2. Local gateway CA trust repair:
   [`orbit gateway:trust`](2_gateway-trust/gateway-trust.md)
3. Local gateway selection list:
   [`orbit gateway:list`](3_gateway-list/gateway-list.md)
4. Active gateway selection:
   [`orbit gateway:use <name>`](4_gateway-use/gateway-use.md)
