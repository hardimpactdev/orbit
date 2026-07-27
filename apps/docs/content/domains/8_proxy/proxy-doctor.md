# Proxy Doctor

[Back to Proxy commands.](README.md)

The proxy family doctor implements the
[Family Doctor Implementation Contract](../11_operation/3_doctor/technical/1_doctor.md#family-doctor-implementation-contract).
`key()` returns `proxy`.

`doctor --family=proxy` verifies whether gateway proxy route configuration still matches node proxy and TLS reality. It covers all supported Orbit-owned routes — app, workspace, gateway API, websocket, S3, analytics, tool-owned, and custom proxy routes — and their TLS material. For installed agent tools, the proxy family derives the expected internal route from the tool row and node TLD, so it can detect a route row that was deleted rather than inspecting only rows that still exist.

An `app-prod` target never admits workspace route rows, unsupported workspace
owner markers, workspace domains, or workspace TLS expectations into route or shared
global-config comparison. Supported app and node proxy drift remains visible.
Explicit workspace scope is rejected before this probe runs.

When Doctor is scoped with `--instance` or `--workspace`, route-specific probing,
planning, and restore/adopt actions are limited to that owner. Shared
node-level Caddy container and global-config checks still run because those
artifacts are prerequisites for the selected route, but unrelated app,
workspace, WebSocket, or S3 routes are not enumerated or changed.
The fleet-wide private DNS projection is checked only for an unscoped proxy
run on the active router, not for app- or workspace-scoped runs.

The proxy family owns these facts:

- gateway-owned proxy route rows: domain, kind, owner, serving node, target,
  redirect code, TLS policy, and backend metadata needed to identify the applied
  route;
- expected agent-tool internal route intent derived from installed agent tool
  rows and each tool node's configured TLD;
- managed proxy backend artifacts rendered from those rows;
- `dnsmasq.d/20-proxy-records.conf`, containing router/private `.orbit`
  directives and exact backend records, currently including S3 backends;
- managed TLS material needed by those routes;
- hostname compatibility material derived from Orbit-managed TLS that app-role
  tooling can use for Laravel Vite TLS detection;
- drift between gateway configuration and node proxy backend reality;
- adoption facts for explicitly selected observed routes that can safely become
  custom proxy configuration.

Instance health belongs to `instance`, workspace health belongs to `workspace`, gateway service readiness belongs to `node`, tool capability readiness belongs to `tool`, and process runtime readiness belongs to `process`. The proxy family verifies ingress artifacts, not the health of the service behind the route.

`orbit-caddy` is the Docker container that mounts and serves proxy route artifacts. Proxy doctor probes its container readiness and managed-network attachment on each serving node because routes cannot be served when the container is missing, stopped, or detached from `orbit-network`. Container spec drift and image capability drift remain owned by the [`tool` family](../3_tool/catalog/caddy.md) until Caddy is fully represented as a process-backed runtime unit.

Proxy doctor never probes a host `caddy.service`. Host Caddy is not part of the steady-state runtime.

## Probe Layers

The proxy probe reads gateway proxy route configuration and checks these layers:

1. **Registry configuration:** every selected route has a valid domain, kind, owner,
   serving node, target, and TLS policy. Installed agent tools contribute an
   expected route even when no proxy route row remains.
2. **Owner eligibility:** the owner reference still resolves when the route is
   owned by a project, instance, WebSocket binding, workspace, gateway route, router
   service, S3 publication, or tool.
3. **Node eligibility:** the serving node resolves to a visible active Ubuntu
   gateway or node with proxy capability.
4. **Conflict boundary:** custom routes do not claim domains owned by app, app
   WebSocket binding, workspace, gateway, router service, S3 publication, or
   tool routes.
5. **Caddy container readiness:** the `orbit-caddy` container exists, is
   running, and is attached to the managed Docker network on each serving node.
   Route artifacts mounted into `orbit-caddy` are only effective when the
   container is reachable by managed runtimes.
6. **Backend presence:** the expected proxy backend route exists when gateway
   configuration says it should exist.
7. **Backend shape:** the observed backend route matches the expected owner,
   route kind, upstream or redirect target, redirect code, TLS behavior, and
   routing priority. For app/workspace routes, this includes the browser
   ingress baseline: document-root policy, PHP runtime target, security
   headers, sensitive path blocking, profiling timing markers, and immutable
   cache headers for versioned build assets. Every app primary route must keep
   its project owner while targeting one concrete instance, that
   instance's serving node, runtime upstream, and inner-TLS server name.
8. **TLS material:** the TLS material that Orbit manages exists and matches the
   route's policy. For DNS hostname routes, this includes the app-role
   compatibility material used by Laravel Vite TLS detection. Internal IP-only
   routes skip hostname compatibility checks. Expected TLS material is a
   gateway-issued 397-day route leaf certificate and key — not CA material
   issued locally by Caddy, and not an app-role intermediate CA. An otherwise
   valid route leaf with a longer or shorter issuance lifetime is TLS drift.
9. **Extra route ownership:** Orbit-owned backend routes without matching
   gateway configuration are reported as extra route drift.
10. **Enactment state:** app routes whose persisted enactment state is failed,
    partial, or pending are reported alongside any artifact drift so one
    restore run can repair artifacts and perform the complete ordered retry.
11. **Private DNS projection:** for an unscoped active-router run,
    `dnsmasq.d/20-proxy-records.conf` matches router-owned private `.orbit`
    routes and exact backend intent. DNS base configuration and runtime facts
    remain tool-owned.
12. **Adoption scope:** during `doctor --adopt`, explicitly selected observed backend
    routes may be inspected for compatible custom-route facts.

Observed backend routes without Orbit ownership markers are unmanaged node reality by default. They are reported as drift only when the operator requested an explicit adoption scope.

## Proxy Issue Codes

Each code below identifies a specific proxy-family drift condition that the probe can detect.

| Code | Detected when |
| --- | --- |
| `proxy.record_incomplete` | A selected gateway route lacks domain, kind, owner, serving node, target, redirect code, TLS policy, or backend identity metadata required for comparison. |
| `proxy.owner_invalid` | A project, instance, WebSocket binding, workspace, gateway, router service, S3 publication, or tool owner reference does not resolve to a valid gateway-owned record. |
| `proxy.node_invalid` | The route points at a missing, inactive, unsupported, or role-incompatible serving node. |
| `proxy.domain_conflict` | A custom route claims a domain owned by a project, instance, WebSocket binding, workspace, gateway, router service, S3 publication, or tool route. |
| `proxy.docker_runtime_unavailable` | The serving node's Docker CLI is missing or the Docker daemon is unreachable, so `orbit-caddy` container readiness cannot be probed. Repair the Docker tool baseline through `doctor --family=tool --restore` first. |
| `proxy.caddy_container_missing` | The `orbit-caddy` container is absent on a serving node that still owns proxy routes. |
| `proxy.caddy_container_down` | The `orbit-caddy` container exists on the serving node but is not running. Mounted route artifacts are not served. |
| `proxy.caddy_container_detached` | The running `orbit-caddy` container is not attached to the serving node's managed Docker network. |
| `proxy.agent_tool_route_missing` | An installed agent tool expects an internal route under its node TLD, but the gateway proxy route row is absent. |
| `proxy.agent_tool_route_mismatch` | The expected agent-tool route row exists for the same tool but its serving node, kind, upstream, owner shape, or source hash differs from canonical proxy intent. |
| `proxy.agent_tool_route_conflict` | The expected agent-tool domain is occupied by a custom route or a different tool. Proxy doctor reports the conflict but does not overwrite the other owner. |
| `proxy.route_missing` | Gateway configuration expects a managed backend route, but the route is absent from node reality. |
| `proxy.route_mismatch` | A managed backend route exists but differs from gateway configuration. |
| `proxy.enactment_incomplete` | Persisted enactment is failed, partial, or pending. Restore reports it with artifact drift and retries backend → router → ingress. |
| `proxy.dns_mapping_mismatch` | Proxy-owned `dnsmasq.d/20-proxy-records.conf` differs from active router/private `.orbit` and exact-backend intent. |
| `proxy.websocket.router_route_missing` | Gateway WebSocket route intent expects the private router-owned `websocket.orbit` route row, but it is missing or differs from the canonical WebSocket service route. |
| `proxy.websocket.public_route_missing` | An enabled instance WebSocket binding expects a public ingress route, but the route row is missing or differs from canonical intent. |
| `proxy.websocket.router_route_orphaned` | The private `websocket.orbit` service route row exists, but no active `websocket` role assignment remains in the topology. Service routes exist only while a matching role is active. |
| `proxy.s3.router_route_missing` | The private `s3.orbit` route is absent or any field (node, owner, config, source_hash) diverges from gateway S3 service-route intent. Does not overlap with `proxy.s3.router_backend_invalid`. |
| `proxy.s3.router_backend_invalid` | The `s3.orbit` route exists and matches intent structurally, but its backend pool is invalid: the upstreams list is empty or contains a non-SeaweedFS host. Route absence is covered by `proxy.s3.router_route_missing`. |
| `proxy.s3.public_route_missing` | An active seaweedfs tool row lists public hosts, but the ingress public S3 route for a host is absent or diverges from expected ingress route intent. |
| `proxy.s3.router_route_orphaned` | The private `s3.orbit` service route row exists, but no active `s3` role assignment remains in the topology. Service routes exist only while a matching role is active. |
| `proxy.analytics.router_route_missing` | The private `analytics.orbit` route is absent or differs from canonical route intent for the singleton active analytics assignment. |
| `proxy.analytics.public_route_missing` | An enabled instance analytics binding expects a public tracking route, but its route row is absent or differs from canonical instance analytics intent. |
| `proxy.analytics.router_route_orphaned` | The private `analytics.orbit` route row exists, but no active analytics role assignment remains. |
| `proxy.tls_missing` | Gateway configuration expects Orbit-managed TLS material, but it is absent from node reality. |
| `proxy.tls_mismatch` | Managed TLS material exists but its path, issuer policy, or 397-day issuance lifetime does not match the expected route policy. |
| `proxy.route_extra` | An Orbit-owned backend route has no matching gateway proxy route row, or an explicitly selected observed backend route has no matching gateway proxy route row during adoption scope. |

## Proxy Fix Map

Use `doctor --restore` to trigger the repair action listed for each code.
After applying restore actions, Doctor performs a fresh probe in the same
node/instance/workspace scope. A command-level success is reported as failed when
the matching route still has drift, with the node, verification operation, and
observed mismatch retained in the action details. Doctor reports convergence
only when that readback is clean.

For an instance primary route, restoring a mismatch also persists its project
owner, concrete instance target, serving node, runtime upstream, and inner-TLS
server name before rendering.

| Code | `doctor --restore` behavior |
| --- | --- |
| `proxy.caddy_container_missing` | Reconcile the `orbit-caddy` container on the serving node from its managed spec, then re-render the mounted Caddy config. |
| `proxy.caddy_container_down` | Start the existing `orbit-caddy` container so mounted route artifacts are served again. |
| `proxy.caddy_container_detached` | Reconcile the `orbit-caddy` container from its managed spec so the container is recreated on the managed Docker network. |
| `proxy.agent_tool_route_missing` | Recreate the expected tool-owned route row from the installed agent tool and node TLD, then render its Caddy artifact and TLS material. |
| `proxy.agent_tool_route_mismatch` | Rewrite a same-tool route row to canonical proxy intent, then re-render its Caddy artifact and TLS material. |
| `proxy.route_missing` | Recreate the backend route from gateway configuration when the node is reachable and eligible. |
| `proxy.route_mismatch` | Replace the backend route with the gateway-configured route when it can be identified safely. |
| `proxy.enactment_incomplete` | Retry the instance route's complete backend → router → ingress enactment. The persisted state becomes converged only after every operation succeeds; a retry failure retains partial state and reports the exact node and operation. |
| `proxy.dns_mapping_mismatch` | Re-render only `dnsmasq.d/20-proxy-records.conf`, atomically replace that artifact through the shared ownership-neutral materializer, and reload or restart DNS once. If the projection directory mount is not active, leave drift unresolved rather than reporting success. |
| `proxy.websocket.router_route_missing` | Re-sync the private `websocket.orbit` service route from gateway WebSocket route intent. |
| `proxy.websocket.public_route_missing` | Re-sync public WebSocket ingress routes from the owning instance binding. |
| `proxy.websocket.router_route_orphaned` | Remove the orphaned `websocket.orbit` service route row and its rendered artifacts. |
| `proxy.s3.router_route_missing` | Re-sync the private `s3.orbit` service route from gateway S3 intent. |
| `proxy.s3.router_backend_invalid` | Re-sync the `s3.orbit` service route to rebuild the backend pool from active SeaweedFS backends. |
| `proxy.s3.public_route_missing` | Re-sync public S3 ingress routes from the owning seaweedfs tool row. |
| `proxy.s3.router_route_orphaned` | Remove the orphaned `s3.orbit` service route row and its rendered artifacts. |
| `proxy.analytics.router_route_missing` | Re-sync and enact the private `analytics.orbit` route and Orbit-managed TLS from gateway analytics intent. |
| `proxy.analytics.public_route_missing` | Re-sync and enact the public ingress and router tracking routes from the owning project analytics binding. |
| `proxy.analytics.router_route_orphaned` | Remove the orphaned `analytics.orbit` route row, rendered site, certificate, and key. |
| `proxy.tls_missing` | Recreate Orbit-managed TLS material for the selected route when prerequisites are available. |
| `proxy.tls_mismatch` | Reissue or relink the TLS material so its path and 397-day validity match Orbit policy, then force-reload Caddy so an unchanged route configuration reprovisions the active certificate from disk. |
| `proxy.route_extra` | Remove the extra backend route only when it carries Orbit ownership metadata or can otherwise be tied safely to an absent gateway route. |

`doctor --restore` does not handle `proxy.record_incomplete`, `proxy.owner_invalid`, `proxy.node_invalid`, `proxy.domain_conflict`, `proxy.agent_tool_route_conflict`, or `proxy.docker_runtime_unavailable`. The Docker runtime gap is tool-family capability drift; resolve it through `doctor --family=tool --restore` before re-running proxy doctor.

## Proxy Adopt Map

Use `doctor --adopt` to apply the adoption action listed for each code.

| Code | `doctor --adopt` behavior |
| --- | --- |
| `proxy.route_extra` | Create a custom gateway proxy route row when: the operator selected a specific node and backend route; the domain is unowned; and the observed route maps to `--upstream` or `--redirect`. |
| `proxy.route_mismatch` | Update gateway configuration only when the operator selected a custom route and the observed backend route can be represented without changing project, instance, WebSocket, workspace, gateway, router, S3, or tool ownership. |

`doctor --adopt` does not scan arbitrary hosts, adopt project/instance/WebSocket/workspace/gateway/router/S3/tool routes as custom routes, infer project ownership from upstream paths, or adopt service health into the proxy family.
`proxy.dns_mapping_mismatch` is derived projection drift and is never adoptable.

## Test Mapping

Required test files:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/DoctorRunControllerTest.php` | Gateway doctor API coverage for proxy family scope, proxy drift reporting, and restore behavior. |
| `apps/gateway/tests/Feature/Services/Doctor/DoctorDnsProjectionRestoreTest.php` | Family-specific restore routing for node and proxy DNS projections. |
| `apps/gateway/tests/Unit/Services/Doctor/ProxyDnsProjectionProbeTest.php` | Router/private `.orbit`, exact backend projection, and `proxy.dns_mapping_mismatch`. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteProbeTest.php` | Probe drift for registry, derived agent-tool route intent, ownership, node eligibility, artifacts, TLS, and safe adoption. |
| `apps/gateway/tests/Unit/Services/Proxy/ProxyRouteFixerTest.php` | Restore behavior for deleted and mismatched agent-tool routes, complete app-route re-enactment, and layer-specific artifact repairs. |
| `apps/gateway/tests/Unit/Services/Analytics/AnalyticsProxyDoctorProbeTest.php` | Analytics service-route registry drift, orphan detection, and restore behavior. |

No current E2E test is mapped for proxy-family doctor coverage.
