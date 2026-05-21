# Public Ingress Role Design

## Goal

Add a `public-ingress` node role that owns Orbit's public production traffic
surface.

The role separates public exposure from production app runtime. Public ingress
may be co-located with an app-production node for small installs, but the public
attack surface, route hardening, and load-balancing behavior belong to
`public-ingress`, not to `app-production`.

## Current Context

Current Orbit docs say `app-production` nodes expose public HTTP/HTTPS directly
and may combine with `database`. That model is too permissive for the production
security posture Orbit should enforce.

The new product direction is:

- public production traffic requires a node with `public-ingress`;
- `app-production` without `public-ingress` is a private backend role;
- production database workloads must not share a node with app-production or
  public-ingress workloads;
- load balancing is a public-ingress capability from day one, even when the
  first backend pool contains one target.

Historical Orbit evidence supports Caddy and UFW/firewall baselines as existing
backend choices, but the old repo does not define this role split. This design
therefore changes the product contract rather than porting an old behavior.

## Product Decisions

- Add a new node role named `public-ingress`.
- Display the role as `Public ingress`.
- `public-ingress` is the only role that may expose public production route
  traffic.
- `public-ingress` may coexist with `app-production`.
- `public-ingress` may not coexist with `gateway`, `vpn`, `app-development`,
  `database`, or `agent`.
- `app-production` may not coexist with `database`.
- `database` may still coexist with `app-development`.
- `public-ingress` owns public listener policy, public Caddy route artifacts,
  public route hardening, and load-balancing behavior.
- `app-production` owns private app serving: Caddy on private HTTP, PHP-FPM,
  Supervisor, app files, and runtime artifacts.
- Caddy remains part of the `app-production` baseline for v1.
- FrankenPHP is out of scope and should be evaluated as a separate runtime
  option later.
- Private backend traffic uses HTTP over WireGuard in v1. Private HTTPS is out
  of scope.
- CrowdSec is out of scope for v1. When added later, it should be added as a
  proper Caddy bouncer/AppSec integration or not added at all.

## Role Compatibility

| Role pair | Policy |
| --- | --- |
| `app-production` + `public-ingress` | Allowed |
| `app-development` + `database` | Allowed |
| `app-production` + `database` | Denied |
| `public-ingress` + `database` | Denied |
| `public-ingress` + `gateway` | Denied |
| `public-ingress` + `vpn` | Denied |
| `public-ingress` + `app-development` | Denied |
| `public-ingress` + `agent` | Denied |
| `public-ingress` alone | Allowed |
| `database` alone | Allowed |

The flat role conflict model can express the v1 matrix. The implementation does
not need conditional role-set predicates for this slice.

## Role Baselines

### Public Ingress

The `public-ingress` baseline owns:

- Caddy as the public HTTP backend;
- public listener policy for route traffic;
- route artifact support for public app, workspace, custom, redirect, gateway,
  and tool HTTP routes where those routes are eligible for public exposure;
- load-balancing configuration for backend pools;
- firewall posture that keeps SSH and Orbit API access on WireGuard.

The baseline does not install CrowdSec or AppArmor in v1.

### App Production

The `app-production` baseline owns:

- Caddy as a private app-node HTTP server;
- a shared private listener on port `80`;
- Host-based routing for app and workspace backend routes;
- PHP-FPM pools and Unix sockets;
- Supervisor for app-managed long-running processes;
- app files and runtime artifacts.

Port `80` on an app-production node is not public merely because Caddy listens
there. Public exposure exists only when the node also has `public-ingress`.

### Database

The `database` baseline remains the database-tool substrate. It is not valid on
production app or public-ingress nodes.

## Command Flow

### `node:new --role=public-ingress`

Creates or adopts a node that can serve public production route traffic.

The first version may create a single public-ingress node. Multi-node public
ingress and failover are out of scope for this design.

### `node:new --role=app-production`

Interactive input mode asks:

```text
Serve public traffic from this node? [yes]
```

If the answer is `yes`, Orbit creates or adopts a node with both roles:

```text
app-production + public-ingress
```

If the answer is `no`, Orbit requires an active public-ingress node in the
fleet, prompts for the selected public-ingress node, and creates or adopts the
new node as:

```text
app-production
```

If the answer is `no` and no public-ingress node exists, the command fails
before provisioning side effects. The failure should explain that private
production app nodes need an existing public-ingress node and should guide the
operator to create one first:

```bash
orbit node:new edge-1 --role=public-ingress
```

Non-interactive mode must receive explicit placement input. It must not infer a
public-ingress node silently.

Suggested prompt ids:

- `node_new.serve_public_traffic`
- `node_new.public_ingress_node`

Suggested failure shape for missing public ingress:

- `error.code`: `validation_failed`
- `error.meta.field`: `public_ingress_node`
- `error.meta.required_role`: `public-ingress`

## Route And Backend Model

A public production app route has two sides.

Public route artifact:

- node: active `public-ingress` node;
- behavior: public HTTPS, public filtering boundary, load balancing, and
  forwarding to a backend pool.

Private backend route artifact:

- node: owning `app-production` node;
- behavior: HTTP on port `80` over WireGuard, Host-based routing, app/workspace
  ingress contract, static files, sensitive-path blocking, and PHP forwarding.

The backend pool is a list from day one:

```text
app.example.com -> [
  http://10.6.0.21:80
]
```

Later scaling appends app-production targets without changing the route concept:

```text
app.example.com -> [
  http://10.6.0.21:80,
  http://10.6.0.22:80
]
```

`public-ingress` Caddy preserves the original `Host` header and manages
forwarding headers deliberately. App-production Caddy receives the original
host, applies Orbit's app/workspace ingress contract, and forwards PHP requests
to the app's PHP-FPM Unix socket.

## Traffic Shape

Public app traffic follows this path:

```text
Browser
  -> public-ingress Caddy: HTTPS, public filtering boundary, load balancing
  -> app-production Caddy: HTTP over WireGuard on port 80
  -> PHP-FPM: Unix socket
```

If `public-ingress` and `app-production` are co-located on one node, Orbit still
keeps the ownership split. Public route artifacts belong to `public-ingress`;
private backend route artifacts and PHP runtime artifacts belong to
`app-production`.

## Security Posture

The v1 security improvement is structural:

- public production ingress is represented by one role;
- public hardening is not duplicated into every production app role;
- production databases cannot be co-located with production app or public
  ingress roles;
- app-production nodes can be made private backend nodes;
- all management traffic remains WireGuard-only.

The v1 design does not claim WAF, CrowdSec, Fail2Ban, or AppArmor coverage.

Future CrowdSec work should be a separate feature. That feature should either
manage a proper Caddy bouncer/AppSec module, including the custom Caddy build or
module packaging path, or deliberately choose not to ship CrowdSec.

## Documentation Impact

The following current docs will need updates before implementation:

- `docs/architecture.md`: production ingress flow and role descriptions.
- `docs/tech-stack.md`: Caddy placement, public route artifacts, private
  backend artifacts, and backend HTTP over WireGuard.
- `docs/concepts.md`: add `public-ingress`, backend pool, and public/private
  production ingress terms.
- `docs/domains/1_node/**`: role compatibility, role baselines, `node:new`
  input behavior, node doctor issue ownership, and role-removal boundaries.
- `docs/domains/4_firewall/**`: bootstrap network policy boundary for
  public-ingress and private app-production ports.
- `docs/domains/5_app/**`: app-production route placement and database
  co-location rejection.
- `docs/domains/8_proxy/**`: route placement, backend pools, public artifacts,
  private backend artifacts, and proxy doctor expectations.

## Implementation Impact

Expected implementation areas:

- Add `public-ingress` to `NodeRoleName`.
- Add role definition and compatibility rules in `NodeRoleRegistry`.
- Add role settings class or use empty settings for v1.
- Add a `PublicIngressRoleBaseline`.
- Adjust `AppProductionRoleBaseline` so production app nodes retain private
  Caddy and PHP-FPM runtime, but public listener policy moves to
  `public-ingress`.
- Update `NodeRoleAssignments` helper methods for public-ingress eligibility
  and route-serving queries.
- Update `node:new` interactive and non-interactive input handling.
- Update proxy route persistence/rendering so public route placement and backend
  pools are explicit.
- Update firewall/bootstrap policy rendering for public-ingress and private
  app-production.
- Update node, app, proxy, and firewall doctor probes/fixers where the role
  boundary changes drift ownership.

## Test Plan

Documentation tests:

- `composer docs-lint`

Feature and unit test coverage should include:

- `node:new --role=app-production` defaults to public serving in interactive
  mode.
- `node:new --role=app-production` with private serving fails when no active
  public-ingress node exists.
- `node:new --role=app-production` with private serving accepts an existing
  public-ingress node.
- `node:new --role=public-ingress` creates a public-ingress role assignment.
- Role compatibility accepts `app-production + public-ingress`.
- Role compatibility rejects `app-production + database`.
- Role compatibility rejects `public-ingress + database`.
- Role compatibility rejects `public-ingress + gateway`, `vpn`,
  `app-development`, and `agent`.
- Proxy route rendering produces public route artifacts on public-ingress nodes
  and private backend artifacts on app-production nodes.
- Backend pools are represented as lists even for one target.
- Firewall/bootstrap policy exposes public `80/443` only on public-ingress nodes
  and keeps app-production backend port `80` private.

E2E coverage should include one small co-located production topology:

```text
app-production + public-ingress
```

and one split topology:

```text
public-ingress -> app-production
```

## Non-Goals

- No CrowdSec, Caddy AppSec bouncer, Fail2Ban, or WAF.
- No AppArmor.
- No FrankenPHP.
- No private HTTPS between public-ingress and app-production backends.
- No multi-public-ingress high availability or failover.
- No app deployment synchronization or horizontal scaling automation beyond the
  backend-pool data shape.
- No custom Caddy module build pipeline in this slice.
- No conditional role-set compatibility engine beyond the existing flat conflict
  model.
