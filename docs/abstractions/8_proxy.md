# Proxy Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing proxy
command ports.

Product behavior remains owned by `docs/commands/8_proxy/**` and the top-level
product docs.

## Domain Constraints

- The gateway is the source of truth for proxy route intent.
- The state family key is `proxy`; the command prefix is `proxy:*`.
- Caddy is the current backend. It is not the product model.
- Every route has one serving node, one owner type, one kind, and one target
  shape represented in gateway intent.
- App, workspace, gateway, and tool-owned routes are visible through proxy
  commands but edited by their owning domain commands.
- `proxy:add` and `proxy:remove` own only custom upstream and redirect routes.
- Proxy writes persist gateway intent before node-side backend/TLS enactment.
  Retryable backend drift is reported as proxy-family warnings and repaired by
  doctor.
- Proxy doctor verifies ingress artifacts and TLS material. It does not verify
  application, workspace, tool, schedule, firewall, or node runtime health.
- Backend discovery/import is explicit doctor adoption work, not a permanent
  sync command.

## Schema and model pattern

- `proxy_routes`
  - `node_id`
  - `domain`
  - `app_id` nullable
  - `workspace_id` nullable
  - `owner_type`
  - `kind`
  - `source_hash`
  - `config` JSON

`ProxyRoute` belongs to `Node`, optional `App`, and optional `Workspace`.
Current clean schema stores backend-specific details in `config`; command and
doctor code should normalize that into route-entity DTOs instead of leaking raw
config arrays into renderers.

## Route Intent Pattern

- `owner_type` is one of the product owners documented by the proxy README:
  `app`, `workspace`, `gateway`, `tool`, or `custom`.
- `kind` is one of `app`, `workspace`, `internal`, `proxy`, or `redirect`.
- Custom upstream routes use `owner_type=custom` and `kind=proxy`.
- Custom redirects use `owner_type=custom` and `kind=redirect`.
- App routes keep `app_id`; workspace routes keep both `app_id` and
  `workspace_id`; custom routes have neither app nor workspace owner.
- `source_hash` should represent the rendered backend artifact expected from
  gateway intent. It is an implementation comparison helper, not product state
  shown directly to users.

## Command Pattern

- `proxy:list` is a gateway intent read. It applies visibility, filter, and node
  constraints without SSH or live backend probing.
- `proxy:add` creates or updates custom route intent, validates target shape,
  rejects ownership conflicts with non-custom routes, then enacts backend/TLS
  artifacts when the serving node is reachable.
- `proxy:remove` removes only custom route intent, requires destructive consent,
  then removes backend/TLS artifacts when no remaining route shares them.
- Operator and app callers use typed gateway API requests. Gateway callers use
  local database state and the gateway-owned `RemoteShell` edge for node-side
  enactment.
- Human/JSON output should use the proxy route JSON entity from
  `docs/commands/8_proxy/README.md`.

## Backend Rendering Pattern

- App and workspace routes must preserve the Orbit browser ingress baseline:
  PHP runtime routing, document-root policy, sensitive path blocking, profiling
  headers, baseline security headers, and immutable build-asset caching.
- Custom upstream and redirect routes do not inherit app/workspace PHP behavior.
- Backend artifact paths should be deterministic and domain-based so doctor can
  compare presence/content and identify stale Orbit-owned artifacts.
- TLS material is derived route state. Hostname routes include app-node
  compatibility material for common Laravel Vite TLS detection paths; internal
  IP-only routes skip hostname compatibility checks.
- Gateway-internal routes must preserve WireGuard identity semantics by removing
  forwarded-client identity headers before traffic reaches the gateway API.

## Doctor Pattern

- `ProxyRouteProbe` should check registry completeness, owner eligibility, node
  eligibility, and custom-domain conflicts before remote backend checks.
- Backend checks compare expected rendered route content and TLS material against
  node reality.
- Extra backend routes should be reported only when they carry Orbit ownership
  markers or when an operator explicitly selected an adoption scope.
- Adoption can create or update custom route intent only when the selected
  observed backend route can be represented as `proxy:add --upstream` or
  `proxy:add --redirect` without stealing app, workspace, gateway, or tool
  ownership.

## Evidence Pointers

- `docs/commands/8_proxy/README.md`
- `docs/commands/8_proxy/proxy-concepts.md`
- `docs/commands/8_proxy/proxy-doctor.md`
- `docs/commands/8_proxy/1_proxy-list`
- `docs/commands/8_proxy/2_proxy-add`
- `docs/commands/8_proxy/3_proxy-remove`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Services/ProxyRoutes/ProxyRouteProbe.php`
- Old evidence: `../orbit-old-may/app/Services/ProxyRoutes/ProxyRouteRenderer.php`
- Old evidence: `../orbit-old-may/app/Services/ProxyRoutes/ProxyRouteEnactor.php`
- Old evidence: `../orbit-old-may/app/Services/ProxyRoutes/ProxyRouteWriter.php`
- Old evidence: `../orbit-old-may/app/Services/ProxyRoutes/ProxyRouteAdopter.php`
- Old evidence: `../orbit-old-may/tests/Unit/Services/ProxyRoutes/ProxyRouteProbeTest.php`
- Old evidence: `../orbit-old-may/tests/Feature/Doctor/ProxyRoutesFamilyDoctorContractTest.php`
