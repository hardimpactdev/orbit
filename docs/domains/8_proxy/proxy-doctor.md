# Proxy Doctor

[Back to Proxy commands.](README.md)

The proxy family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `proxy`.

`doctor --family=proxy` verifies whether gateway proxy route configuration still matches node proxy and TLS reality. It covers Orbit-owned ingress routes only.

The proxy family owns these facts:

- gateway-owned proxy route rows: domain, kind, owner, serving node, target,
  redirect code, TLS policy, and backend metadata needed to identify the applied
  route;
- managed proxy backend artifacts rendered from those rows;
- managed TLS material needed by those routes;
- hostname compatibility material derived from Orbit-managed TLS that app-role
  tooling can use for Laravel Vite TLS detection;
- drift between gateway configuration and node proxy backend reality;
- adoption facts for explicitly selected observed routes that can safely become
  custom proxy configuration.

App health belongs to `app`, workspace health belongs to `workspace`, gateway runtime readiness belongs to `node`, and tool runtime readiness belongs to `tool`. The proxy family verifies ingress artifacts, not the health of the service behind the route.

`orbit-caddy` is the Docker container that mounts and serves proxy route artifacts. Proxy doctor probes its container readiness on each serving node because routes cannot be served when the container is missing or stopped. Container spec drift, image version drift, and tool lifecycle policy remain owned by the [`tool` family](../3_tool/catalog/caddy.md).

Proxy doctor never probes a host `caddy.service`. Host Caddy is not part of the steady-state runtime.

## Probe Layers

The proxy probe reads gateway proxy route configuration and checks these layers:

1. **Registry configuration:** every selected route has a valid domain, kind, owner,
   serving node, target, and TLS policy.
2. **Owner eligibility:** the owner reference still resolves when the route is
   owned by an app, app WebSocket binding, workspace, gateway route, websocket
   service, S3 service, or tool.
3. **Node eligibility:** the serving node resolves to a visible active Ubuntu
   gateway or node with proxy capability.
4. **Conflict boundary:** custom routes do not claim domains owned by app, app
   WebSocket binding, workspace, gateway, websocket service, S3 service, or
   tool routes.
5. **Caddy container readiness:** the `orbit-caddy` container exists and is
   running on each serving node. Route artifacts mounted into `orbit-caddy` are
   only effective when the container is up.
6. **Backend presence:** the expected proxy backend route exists when gateway
   configuration says it should exist.
7. **Backend shape:** the observed backend route matches the expected owner,
   route kind, upstream or redirect target, redirect code, TLS behavior, and
   routing priority. For app/workspace routes, this includes the browser
   ingress baseline: document-root policy, PHP runtime target, security
   headers, sensitive path blocking, profiling timing markers, and immutable
   cache headers for versioned build assets.
8. **TLS material:** the TLS material that Orbit manages exists and matches the
   route's policy. For DNS hostname routes, this includes the app-role
   compatibility material used by Laravel Vite TLS detection. Internal IP-only
   routes skip hostname compatibility checks. Expected TLS material is a
   gateway-issued route leaf certificate and key — not CA material issued locally by Caddy,
   and not an app-role intermediate CA.
9. **Extra route ownership:** Orbit-owned backend routes without matching
   gateway configuration are reported as extra route drift.
10. **Adoption scope:** during `doctor --adopt`, explicitly selected observed backend
    routes may be inspected for compatible custom-route facts.

Observed backend routes without Orbit ownership markers are unmanaged node reality by default. They are reported as drift only when the operator requested an explicit adoption scope.

## Proxy Issue Codes

Each code below identifies a specific proxy-family drift condition that the probe can detect.

| Code | Detected when |
| --- | --- |
| `proxy.record_incomplete` | A selected gateway route lacks domain, kind, owner, serving node, target, redirect code, TLS policy, or backend identity metadata required for comparison. |
| `proxy.owner_invalid` | An app, app-websocket binding, workspace, gateway, websocket service, S3 service, or tool owner reference cannot be resolved or is not visible to the caller. |
| `proxy.node_invalid` | The route points at a missing, unauthorized, inactive, unsupported, or role-incompatible serving node. |
| `proxy.domain_conflict` | A custom route claims a domain owned by an app, app WebSocket binding, workspace, gateway, websocket service, S3 service, or tool route. |
| `proxy.docker_runtime_unavailable` | The serving node's Docker CLI is missing or the Docker daemon is unreachable, so `orbit-caddy` container readiness cannot be probed. Repair the node runtime through `doctor --family=node --restore` first. |
| `proxy.caddy_container_missing` | The `orbit-caddy` container is absent on a serving node that still owns proxy routes. |
| `proxy.caddy_container_down` | The `orbit-caddy` container exists on the serving node but is not running. Mounted route artifacts are not served. |
| `proxy.route_missing` | Gateway configuration expects a managed backend route, but the route is absent from node reality. |
| `proxy.route_mismatch` | A managed backend route exists but differs from gateway configuration. |
| `proxy.tls_missing` | Gateway configuration expects Orbit-managed TLS material, but it is absent from node reality. |
| `proxy.tls_mismatch` | Managed TLS material exists but does not match the expected route policy. |
| `proxy.route_extra` | An Orbit-owned backend route has no matching gateway proxy route row, or an explicitly selected observed backend route has no matching gateway proxy route row during adoption scope. |

## Proxy Fix Map

Use `doctor --restore` to trigger the repair action listed for each code.

| Code | `doctor --restore` behavior |
| --- | --- |
| `proxy.caddy_container_missing` | Reconcile the `orbit-caddy` container on the serving node from its managed spec, then re-render the mounted Caddy config. |
| `proxy.caddy_container_down` | Start the existing `orbit-caddy` container so mounted route artifacts are served again. |
| `proxy.route_missing` | Recreate the backend route from gateway configuration when the node is reachable and eligible. |
| `proxy.route_mismatch` | Replace the backend route with the gateway-configured route when the route can be identified safely. |
| `proxy.tls_missing` | Recreate Orbit-managed TLS material for the selected route when prerequisites are available. |
| `proxy.tls_mismatch` | Replace or relink Orbit-managed TLS material to match gateway configuration. Repair must converge to gateway-issued route leaf certificates when the node serves Caddy-local or intermediate-CA-issued material outside Orbit policy. |
| `proxy.route_extra` | Remove the extra backend route only when it carries Orbit ownership metadata or can otherwise be tied safely to an absent gateway route. |

`doctor --restore` does not handle `proxy.record_incomplete`, `proxy.owner_invalid`, `proxy.node_invalid`, `proxy.domain_conflict`, or `proxy.docker_runtime_unavailable`. The Docker runtime gap is a node-runtime concern; resolve it through `doctor --family=node --restore` before re-running proxy doctor.

## Proxy Adopt Map

Use `doctor --adopt` to apply the adoption action listed for each code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `proxy.route_extra` | Create a custom gateway proxy route row when: the operator selected a specific node and backend route; the domain is unowned; and the observed route maps to `--upstream` or `--redirect`. |
| `proxy.route_mismatch` | Update gateway configuration only when the operator selected a custom route and the observed backend route can be represented without changing app, app-websocket, workspace, gateway, websocket, S3, or tool ownership. |

`doctor --adopt` does not scan arbitrary hosts, adopt app/app-websocket/workspace/gateway/websocket/S3/tool routes as custom routes, infer app ownership from upstream paths, or adopt service health into the proxy family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Doctor/ProxyFamilyDoctorContractTest.php` | Proxy-family dispatch, probe-layer selection, proxy issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering as it affects proxy probes. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteProbeTest.php` | In-memory proxy probe diff behavior for registry configuration, owner eligibility, node eligibility, conflict boundaries, missing routes, mismatched routes, TLS drift, and selected extra routes in adoption scope. |
| `apps/gateway/tests/E2E/Read/ProxyDoctorTest.php` | Real read-only `doctor --family=proxy --json` against nodes with managed proxy routes. |
| `apps/gateway/tests/E2E/Ephemeral/ProxyDoctorFixTest.php` | Real `doctor --family=proxy --restore` repair of safe managed proxy and TLS drift. |
| `apps/gateway/tests/E2E/Ephemeral/ProxyDoctorAdoptTest.php` | Real `doctor --family=proxy --adopt` for compatible selected custom route adoption. |
