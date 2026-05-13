# Proxy Doctor

[Back to Proxy commands.](README.md)

`doctor --family=proxy` verifies whether gateway proxy route configuration still matches node proxy and TLS reality. It covers Orbit-owned ingress routes only.

The proxy family owns these facts:

- gateway-owned proxy route rows: domain, kind, owner, serving node, target,
  redirect code, TLS policy, and backend metadata needed to identify the applied
  route;
- managed proxy backend artifacts rendered from those rows;
- managed TLS material needed by those routes;
- hostname compatibility material derived from Orbit-managed TLS that app-node
  tooling can use for Laravel Vite TLS detection;
- drift between gateway configuration and node proxy backend reality;
- adoption facts for explicitly selected observed routes that can safely become
  custom proxy configuration.

App health belongs to `app`, workspace health belongs to `workspace`, gateway runtime readiness belongs to `node`, and tool runtime readiness belongs to `tool`. The proxy family verifies ingress artifacts, not the health of the service behind the route.

## Probe Layers

The proxy probe reads gateway proxy route configuration and checks these layers:

1. **Registry configuration:** every selected route has a valid domain, kind, owner,
   serving node, target, and TLS policy.
2. **Owner eligibility:** the owner reference still resolves when the route is
   owned by an app, workspace, gateway route, or tool.
3. **Node eligibility:** the serving node resolves to a visible active Ubuntu
   gateway or app node with proxy capability.
4. **Conflict boundary:** custom routes do not claim domains owned by app,
   workspace, gateway, or tool routes.
5. **Backend presence:** the expected proxy backend route exists when gateway
   configuration says it should exist.
6. **Backend shape:** the observed backend route matches the expected owner,
   route kind, upstream or redirect target, redirect code, TLS behavior, and
   routing priority. For app/workspace routes, this includes the browser
   ingress baseline: document-root policy, PHP runtime target, security
   headers, sensitive path blocking, profiling timing markers, and immutable
   cache headers for versioned build assets.
7. **TLS material:** expected Orbit-managed TLS material exists and matches the
   route's policy. For DNS hostname routes, this includes the app-node
   compatibility material used by Laravel Vite TLS detection. Internal IP-only
   routes skip hostname compatibility checks. Expected TLS material is a
   gateway-issued route leaf certificate and key, not node-local Caddy CA
   material and not an app-node intermediate CA.
8. **Extra route ownership:** Orbit-owned backend routes without matching
   gateway configuration are reported as extra route drift.
9. **Adoption scope:** during `doctor --fix --adopt`, explicitly selected observed backend
   routes may be inspected for compatible custom-route facts.

Observed backend routes without Orbit ownership markers are unmanaged node reality by default. They are reported as drift only when the operator requested an explicit adoption scope.

## Proxy Issue Codes

| Code | Detected when |
| --- | --- |
| `proxy.record_incomplete` | A selected gateway route lacks domain, kind, owner, serving node, target, redirect code, TLS policy, or backend identity metadata required for comparison. |
| `proxy.owner_invalid` | An app, workspace, gateway, or tool owner reference cannot be resolved or is not visible to the caller. |
| `proxy.node_invalid` | The route points at a missing, unauthorized, inactive, unsupported, or role-incompatible serving node. |
| `proxy.domain_conflict` | A custom route claims a domain owned by an app, workspace, gateway, or tool route. |
| `proxy.route_missing` | Gateway configuration expects a managed backend route, but the route is absent from node reality. |
| `proxy.route_mismatch` | A managed backend route exists but differs from gateway configuration. |
| `proxy.tls_missing` | Gateway configuration expects Orbit-managed TLS material, but it is absent from node reality. |
| `proxy.tls_mismatch` | Managed TLS material exists but does not match the expected route policy. |
| `proxy.route_extra` | An Orbit-owned backend route has no matching gateway proxy route row, or an explicitly selected observed backend route has no matching gateway proxy route row during adoption scope. |

## Proxy Fix Map

| Code | `doctor --fix --restore` behavior |
| --- | --- |
| `proxy.route_missing` | Recreate the backend route from gateway configuration when the node is reachable and eligible. |
| `proxy.route_mismatch` | Replace the backend route with the gateway-configured route when the route can be identified safely. |
| `proxy.tls_missing` | Recreate Orbit-managed TLS material for the selected route when prerequisites are available. |
| `proxy.tls_mismatch` | Replace or relink Orbit-managed TLS material to match gateway configuration. If the node is serving Caddy-local certificates or any intermediate-CA-issued material outside Orbit policy, repair must converge back to gateway-issued route leaf certificates. |
| `proxy.route_extra` | Remove the extra backend route only when it carries Orbit ownership metadata or can otherwise be tied safely to an absent gateway route. |

`doctor --fix --restore` does not handle `proxy.record_incomplete`, `proxy.owner_invalid`, `proxy.node_invalid`, or `proxy.domain_conflict`.

## Proxy Adopt Map

| Code | `doctor --fix --adopt` behavior |
| --- | --- |
| `proxy.route_extra` | Create a custom gateway proxy route row only when the operator selected a specific node and backend route, the domain is not owned by another Orbit route, and the observed route can be represented as either `--upstream` or `--redirect`. |
| `proxy.route_mismatch` | Update gateway configuration only when the operator selected a custom route and the observed backend route can be represented without changing app, workspace, gateway, or tool ownership. |

`doctor --fix --adopt` does not scan arbitrary hosts, adopt app/workspace/gateway/tool routes as custom routes, infer app ownership from upstream paths, or adopt service health into the proxy family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ProxyFamilyDoctorContractTest.php` | Proxy-family dispatch, probe-layer selection, proxy issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering as it affects proxy probes. |
| `tests/Unit/Services/Proxy/ProxyRouteProbeTest.php` | In-memory proxy probe diff behavior for registry configuration, owner eligibility, node eligibility, conflict boundaries, missing routes, mismatched routes, TLS drift, and selected extra routes in adoption scope. |
| `tests/E2E/Read/ProxyDoctorTest.php` | Real read-only `doctor --family=proxy --json` against nodes with managed proxy routes. |
| `tests/E2E/Ephemeral/ProxyDoctorFixTest.php` | Real `doctor --fix --family=proxy --restore` repair of safe managed proxy and TLS drift. |
| `tests/E2E/Ephemeral/ProxyDoctorAdoptTest.php` | Real `doctor --fix --family=proxy --adopt` for compatible selected custom route adoption. |
