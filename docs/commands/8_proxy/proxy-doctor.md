# Proxy Doctor

[Back to Proxy commands.](README.md)

`doctor --family=proxy` verifies whether gateway proxy route intent still
matches node proxy and TLS reality. It covers Orbit-owned ingress routes only.

The proxy family owns these facts:

- gateway-owned proxy route rows: domain, kind, owner, serving node, target,
  redirect code, TLS policy, and backend metadata needed to identify the enacted
  route;
- managed proxy backend artifacts rendered from those rows;
- managed TLS material needed by those routes;
- drift between gateway intent and node proxy backend reality;
- adoption facts for explicitly selected observed routes that can safely become
  custom proxy intent.

App health belongs to `app`, workspace health belongs to `workspace`, gateway
runtime readiness belongs to `node`, and tool runtime readiness belongs to
`tool`. The proxy family verifies ingress artifacts, not the health of the
service behind the route.

## Probe Layers

The proxy probe reads gateway proxy route intent and checks these layers:

1. **Registry intent:** every selected route has a valid domain, kind, owner,
   serving node, target, and TLS policy.
2. **Owner eligibility:** the owner reference still resolves when the route is
   owned by an app, workspace, gateway route, or tool.
3. **Node eligibility:** the serving node resolves to a visible active Ubuntu
   gateway or app node with proxy capability.
4. **Conflict boundary:** custom routes do not claim domains owned by app,
   workspace, gateway, or tool routes.
5. **Backend presence:** the expected proxy backend route exists when gateway
   intent says it should exist.
6. **Backend shape:** the observed backend route matches the expected owner,
   route kind, upstream or redirect target, redirect code, TLS behavior, and
   routing priority. For app/workspace routes, this includes the browser
   ingress baseline: document-root policy, PHP runtime target, security
   headers, sensitive path blocking, profiling timing markers, and immutable
   cache headers for versioned build assets.
7. **TLS material:** expected Orbit-managed TLS material exists and matches the
   route's policy.
8. **Extra route ownership:** Orbit-owned backend routes without matching
   gateway intent are reported as extra route drift.
9. **Adoption scope:** during `--adopt`, explicitly selected observed backend
   routes may be inspected for compatible custom-route facts.

Observed backend routes without Orbit ownership markers are unmanaged node
reality by default. They are reported as drift only when the operator requested
an explicit adoption scope.

## Proxy Issue Codes

| Code | Detected when |
| --- | --- |
| `proxy.record_incomplete` | A selected gateway route lacks domain, kind, owner, serving node, target, redirect code, TLS policy, or backend identity metadata required for comparison. |
| `proxy.owner_invalid` | An app, workspace, gateway, or tool owner reference cannot be resolved or is not visible to the caller. |
| `proxy.node_invalid` | The route points at a missing, unauthorized, inactive, unsupported, or role-incompatible serving node. |
| `proxy.domain_conflict` | A custom route claims a domain owned by an app, workspace, gateway, or tool route. |
| `proxy.route_missing` | Gateway intent expects a managed backend route, but the route is absent from node reality. |
| `proxy.route_mismatch` | A managed backend route exists but differs from gateway intent. |
| `proxy.tls_missing` | Gateway intent expects Orbit-managed TLS material, but it is absent from node reality. |
| `proxy.tls_mismatch` | Managed TLS material exists but does not match the expected route policy. |
| `proxy.route_extra` | An Orbit-owned backend route has no matching gateway proxy route row, or an explicitly selected observed backend route has no matching gateway proxy route row during adoption scope. |

## Proxy Fix Map

| Code | `--fix` behavior |
| --- | --- |
| `proxy.route_missing` | Recreate the backend route from gateway intent when the node is reachable and eligible. |
| `proxy.route_mismatch` | Replace the backend route with the gateway-intended route when the route can be identified safely. |
| `proxy.tls_missing` | Recreate Orbit-managed TLS material for the selected route when prerequisites are available. |
| `proxy.tls_mismatch` | Replace or relink Orbit-managed TLS material to match gateway intent. |
| `proxy.route_extra` | Remove the extra backend route only when it carries Orbit ownership metadata or can otherwise be tied safely to an absent gateway route. |

`--fix` does not handle `proxy.record_incomplete`,
`proxy.owner_invalid`, `proxy.node_invalid`,
or `proxy.domain_conflict`.

## Proxy Adopt Map

| Code | `--adopt` behavior |
| --- | --- |
| `proxy.route_extra` | Create a custom gateway proxy route row only when the operator selected a specific node and backend route, the domain is not owned by another Orbit route, and the observed route can be represented as either `--upstream` or `--redirect`. |
| `proxy.route_mismatch` | Update gateway intent only when the operator selected a custom route and the observed backend route can be represented without changing app, workspace, gateway, or tool ownership. |

`--adopt` does not scan arbitrary hosts, adopt app/workspace/gateway/tool routes
as custom routes, infer app ownership from upstream paths, or adopt service
health into the proxy family.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Doctor/ProxyFamilyDoctorContractTest.php` | Proxy-family dispatch, probe-layer selection, proxy issue codes, fix map, adopt map, denied fix/adopt cases, and scope filtering as it affects proxy probes. |
| `tests/Unit/Services/Proxy/ProxyRouteProbeTest.php` | In-memory proxy probe diff behavior for registry intent, owner eligibility, node eligibility, conflict boundaries, missing routes, mismatched routes, TLS drift, and selected extra routes in adoption scope. |
| `tests/E2E/Read/ProxyDoctorTest.php` | Real read-only `doctor --family=proxy --json` against nodes with managed proxy routes. |
| `tests/E2E/Ephemeral/ProxyDoctorFixTest.php` | Real `doctor --family=proxy --fix` repair of safe managed proxy and TLS drift. |
| `tests/E2E/Ephemeral/ProxyDoctorAdoptTest.php` | Real `doctor --family=proxy --adopt` for compatible selected custom route adoption. |
