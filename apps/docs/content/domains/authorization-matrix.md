# Authorization Matrix

This matrix is the implementation checklist for gateway API authorization. The
architecture remains the authority for the authorization model; this file maps
command surfaces to concrete permission checks.

Unless a row states otherwise, denial uses `error.code=authorization_failed` and
`error.meta={reason: missing_permission, missing_permission: <permission>,
serving_node: <resolved node name>}`. Gateway-role callers keep implicit
authority as described in [Architecture: Authorization
classes](../architecture.md#authorization-classes).

| Command | Required permission | Serving node resolution | Deployment-context exceptions | Error code on deny | Error meta shape |
| --- | --- | --- | --- | --- | --- |
| `activity:list` | `activity:read` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `activity:show` | `activity:read` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `analytics:update` | `process:update` | selected active analytics node | Exactly one active analytics role assignment | `authorization_failed` | Standard missing-permission meta plus selected analytics node |
| `app:list` | `app:read` | at least one concrete Orbit instance serving node per returned app | App and workspace placement filtering applies | `authorization_failed` | Standard missing-permission meta when the caller has no visible instance serving node |
| `instance:list` | `instance:read` | at least one active app-role serving node; then each concrete Orbit instance serving node for row filtering | Authorized empty inventories succeed; external-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta only when the caller has no qualifying serving-node grant |
| `instance:show` | `instance:read` | selected concrete Orbit instance serving node | External-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `instance:add` | `instance:write` | explicitly selected Orbit target node | External-driver creation is gateway-only; no app default node | `authorization_failed` | Standard missing-permission meta plus target node |
| `instance:remove` | `instance:write` | selected concrete Orbit instance serving node | External-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `instance:env` list/render | `instance:read` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:env` set | `instance:write` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:mount` list | `instance:read` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `instance:mount` add/remove | `instance:mount` | selected concrete instance's serving node | app-dev placement constraints apply | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `instance:worker` show | `instance:read` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `instance:worker` enable/disable | `instance:worker` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `app:new` | `app:new` | target app-role node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `instance:register` | `instance:register` | target app-role node | `app-dev` self-grants include same-node registration; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:remove` | `app:remove` | every affected Orbit instance's serving node | Logical-wide destructive cascade preauthorizes all affected Orbit instances before effects; external-driver instances remain gateway-owned | `authorization_failed` | Standard missing-permission meta plus `app`, denied `instance`, and its serving node |
| `instance:root` | `instance:root` | selected concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:setup` | `instance:write` | selected concrete instance's serving node | Requires a dotted selector or instance hostname; `instance:write` and `instance:*` imply `instance:setup` | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance-setup-step:add` | `instance:write` | selected concrete instance's serving node | Requires a dotted selector or instance hostname; `instance:write` and `instance:*` imply `instance-setup-step:add` | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance-setup-step:list` | `instance:read` | selected concrete instance's serving node | Requires a dotted selector or instance hostname; `instance:read` and `instance:*` imply `instance-setup-step:list` | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance-setup-step:remove` | `instance:write` | selected concrete instance's serving node | Requires a dotted selector or instance hostname; `instance:write` and `instance:*` imply `instance-setup-step:remove` | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `app:show` | `app:read` | at least one caller-visible concrete Orbit instance serving node | Return only authorized Orbit instances; external-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta when the caller has no visible instance serving node |
| `instance:analytics disable` | `instance:write` | selected concrete instance's serving node | Public tracking-host placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:analytics enable` | `instance:write` | selected concrete instance's serving node | Public domain and tracking-host placement follow the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:analytics show` | `instance:read` | selected concrete instance's serving node | Shared non-placement policy may remain app-owned; placement rows remain instance-filtered | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:analytics verify` | `instance:read` | selected concrete instance's serving node | Verification context follows the selected instance's public hosts | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:websocket credentials` | `instance:credentials` | selected concrete instance's serving node | Explicit credential-read permission; not implied by `instance:read` or `instance:write` | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:websocket disable` | `instance:write` | selected concrete instance's serving node | WebSocket binding placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `instance:websocket enable` | `instance:write` | selected concrete instance's serving node | WebSocket binding placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `instance` |
| `cf-cache:flush` | `cf:cache:flush` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-cache-rule:add` | `cf:cache:rule:add` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when instance-scoped |
| `cf-cache-rule:remove` | `cf:cache:rule:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when instance-scoped |
| `cf-dns:add` | `cf:dns:add` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:list` | `cf:dns:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:remove` | `cf:dns:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:disable` | `cf:ssl:disable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:enable` | `cf:ssl:enable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-zone:list` | `cf:zone:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `codex:app add` | `codex:app` | selected concrete Orbit instance's serving node and selected Codex App target node | Requires a dotted Orbit instance selector; no project or instance permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus `project`, `instance`, serving node, and selected Codex node |
| `codex:app list` | `codex:app` | selected Codex App target node | No project or instance permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus selected node |
| `codex:app remove` | `codex:app` | selected concrete Orbit instance's serving node and selected Codex App target node | Requires a dotted Orbit instance selector; no project or instance permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus `project`, `instance`, serving node, and selected Codex node |
| `database:add` | `database:write` | target database host or selected instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `database:add-user` | `database:write` | managed MySQL process node | None | `authorization_failed` | Standard missing-permission meta plus service |
| `database:attach` | `database:write` | target database connection and selected instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:describe` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:detach` | `database:write` | target database connection and selected instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:list` | `database:read` | per visible target | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `database:query` | `database:query`; `database:query:write` for mutating SQL | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection and query mode |
| `database:remove` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:schema` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:show` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:tables` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:update` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `deploy:history` | `deploy:read` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `deploy:log` | `deploy:read` | selected deployment run's instance serving node | None | `authorization_failed` | Standard missing-permission meta plus deployment run and `instance` |
| `deploy:run` | `deploy:run` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `deploy:step-add` | `deploy:step` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `deploy:step-list` | `deploy:read` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `deploy:step-remove` | `deploy:step` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `dns:list` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `dns:resolve-tld` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `doctor` verify mode | `doctor:verify` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `doctor --restore` | `doctor:restore` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `doctor --adopt` | `doctor:adopt` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `extension:list` | local registry read; `extension:read` for gateway state | gateway when `GET /api/extensions` is used | Local registry inspection remains local-only | `authorization_failed` | Standard missing-permission meta for gateway state |
| `extension:enable` | local-only for local enablement; `extension:enable` for gateway enablement | gateway for gateway enablement | Local enablement has no remote target | `authorization_failed` | Standard missing-permission meta for gateway state |
| `extension:disable` | local-only for local disablement; `extension:disable` for gateway disablement | gateway for gateway disablement | Local disablement has no remote target | `authorization_failed` | Standard missing-permission meta for gateway state |
| `firewall:allow` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `firewall:deny` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `firewall:list` | `firewall_rule:read` | target node or each visible node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta plus target node when requested |
| `firewall:remove` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `gateway:add` | n/a - pre-grants bootstrap | n/a | Deployment-context command | n/a | n/a |
| `gateway:list` | n/a - local-only | n/a | Lists local gateway entries only; no gateway API call | n/a | n/a |
| `gateway:status` | none (outside WireGuard identity middleware) | configured gateway endpoint | Public reachability probe; no peer identity and no grant check | n/a | Transport/gateway-unavailable envelopes; not missing-permission |
| `gateway:trust` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `gateway:use` | n/a - local-only | n/a | Selects the active local gateway entry; no gateway API call | n/a | n/a |
| `manifest:update` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `manifest:remove` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `metrics:credentials` read mode | `tool:credentials` | selected active metrics node | V1 process-backed permission for Grafana credentials | `authorization_failed` | Standard missing-permission meta plus selected node and process |
| `metrics:credentials --reset` | `tool:credentials` | selected active metrics node | V1 uses the explicit credentials permission for Grafana password rotation | `authorization_failed` | Standard missing-permission meta plus selected node and process |
| `metrics:disable` | `role:remove` | target node | Delegates to metrics role removal; `--force` required before side effects | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `metrics:enable` | `role:add` | target node | Delegates to metrics role assignment and role baseline convergence | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `metrics:status` | `process:read` | selected metrics node, or each visible metrics node | Row-level filtering applies when `--node` is absent | `authorization_failed` | Standard missing-permission meta plus selected node when requested |
| `node:default` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `node:grant` | `node:grant` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `node:list` | `node:read` | gateway, filtered by visible node grants | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta only for requested unavailable targets |
| `node:manage` | authenticated active roleless self | authenticated caller's own node | No grant check; gateway nodes and role-bearing nodes are rejected by eligibility | `node.not_operator` | `reason=not_roleless_operator`, `node=<caller>` |
| `node:new` | `node:new`; gateway-admin escalation accepted | gateway | First-gateway bootstrap is pre-grants | `authorization_failed` | Standard missing-permission meta |
| `node:permissions` read mode | `node:read` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `node:permissions` write modes | `node:permissions` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `node:remove` | `node:remove` | target node; gateway-admin escalation accepted | Self-removal is operator-only by default | `authorization_failed` | Standard missing-permission meta plus target node |
| `node:revoke` | `node:revoke` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `node:show` | `node:read` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `node:update` | `node:update` | target node; gateway-admin escalation accepted | Canonical path for TLD changes | `authorization_failed` | Standard missing-permission meta plus target node |
| `node role:add` | `role:add` | target node | Triggers self-grant materialization | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `node role:list` | `role:read` | target node | `role:read` implies `role:list` | `authorization_failed` | Standard missing-permission meta plus target node |
| `node role:remove` | `role:remove` | target node | Triggers self-grant reconciliation | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `node role:update` | n/a | n/a | No command surface; use `node:update` for node metadata | n/a | n/a |
| `php:list` | `php:read` | explicit target node or selected instance/workspace serving node | A bare app is rejected with `instance_required`; external-driver instances expose no invented Orbit node | `authorization_failed` | Standard missing-permission meta plus the resolved serving node |
| `php:use` | `php:write` | explicit CLI target node, selected workspace serving node, or every affected Orbit instance serving node for an app policy write | App policy writes preauthorize every affected Orbit instance and verify each node's image availability before mutation | `authorization_failed` | Standard missing-permission meta plus denied `instance` and serving node when instance-scoped |
| `process:add` | `process:add` | node owner, or concrete instance serving node | `app-dev` self-grants include same-node instance process definition creation; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus resolved process scope and instance |
| `process:list` | `process:read` | node owner, or concrete instance serving node | Row-level filtering applies; bare app shorthand requires exactly one instance | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible serving node |
| `process:logs` | `process:read` | node owner, or concrete instance serving node | None | `authorization_failed` | Standard missing-permission meta plus process and instance when applicable |
| `process:remove` | `process:remove` | node owner, or concrete instance serving node | `app-dev` self-grants include same-node instance process definition removal; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus process and instance when applicable |
| `process:restart` | `process:restart` | node owner, or concrete instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and instance when applicable |
| `process:start` | `process:start` | node owner, or concrete instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and instance when applicable |
| `process:stop` | `process:stop` | node owner, or concrete instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and instance when applicable |
| `process:update` | `process:update` | node owner, or concrete instance serving node | Public mutation surface for command, policy, runtime, and supported identity renames; `app-dev` self-grants include same-node instance process definition updates | `authorization_failed` | Standard missing-permission meta plus resolved process scope and instance |
| `profile` | n/a - local-only | n/a | No gateway call, identity lookup, grant check, or activity entry | n/a | n/a |
| `skill:install` | n/a - local-only | n/a | Writes skill files under the caller's home/install root only | n/a | n/a |
| `proxy:add` | `proxy:add` | explicit target node, selected instance serving node, or workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `proxy:list` | `proxy:read` | explicit target node or each route's serving node | Row-level filtering applies independently per route serving node | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible route serving node |
| `proxy:remove` | `proxy:remove` | selected route's serving node | None | `authorization_failed` | Standard missing-permission meta plus route and serving node |
| `s3:credentials` | `tool:credentials` | selected active S3 node | V1 tool-backed permission for the `seaweedfs` tool row | `authorization_failed` | Standard missing-permission meta plus selected node and tool |
| `s3:publish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; mutates S3-owned proxy publication intent through router and ingress route convergence | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `s3:unpublish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; `--force` required in non-interactive mode, including `--json` | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `schedule:add` | `schedule:add` | selected target node; for instance scope, selected concrete instance's serving node | Bare app shorthand requires exactly one eligible visible instance | `authorization_failed` | Standard missing-permission meta plus schedule target and `instance` when applicable |
| `schedule:list` | `schedule:read` | persisted target node; for instance scope, persisted concrete instance's serving node | Row-level filtering applies; bare app shorthand requires exactly one eligible visible instance | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `schedule:logs` | `schedule:read` | persisted target node; for instance scope, persisted concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `instance` when applicable |
| `schedule:remove` | `schedule:remove` | persisted target node; for instance scope, persisted concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `instance` when applicable |
| `schedule:run` | `schedule:run` | persisted target node; for instance scope, persisted concrete instance's serving node | Caller permission is checked once for a manual request; gateway execution does not re-authorize | `authorization_failed` | Standard missing-permission meta plus schedule and `instance` when applicable |
| `schedule:show` | `schedule:read` | persisted target node; for instance scope, persisted concrete instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `instance` when applicable |
| Solo read commands (`solo:*:list`, `solo:*:show`, and other catalogued reads) | `solo:*` | resolved Solo target node | Gateway target uses gateway loopback; non-gateway target uses Agent push to target-local loopback | `authorization_failed` | Standard missing-permission meta plus selected node and Solo resource |
| Solo mutation commands (`solo:*:create`, `add`, `update`, `complete`, `reopen`, `delete`, `lock`, `unlock`, and other catalogued writes) | operation-specific mutation permission from the enabled Solo extension catalog | resolved Solo target node | Transport follows the same target-local Solo proxy contract; destructive consent remains command-specific | `authorization_failed` | Standard missing-permission meta plus selected node and Solo resource |
| `tool:credentials` | `tool:credentials` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:install` | `tool:install` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:list` | `tool:read` | target node or each visible tool owner | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `tool:logs` | `tool:logs` (`tool:read` implies it) | target node; active serving gateway for gateway-local tools | Tool must declare an explicit logs capability and resolve exactly one runtime | `authorization_failed` | Standard missing-permission meta plus tool and serving node |
| `tool:reconfigure` | `tool:reconfigure` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:reload` | `tool:reload` | target node; active serving gateway for gateway-local tools | Tool must declare an explicit reload capability and resolve exactly one direct runtime | `authorization_failed` | Standard missing-permission meta plus tool and serving node |
| `tool:remove` | `tool:remove` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:restart` | `tool:restart` | target node; active serving gateway for gateway-local tools | Tool must declare an explicit lifecycle capability | `authorization_failed` | Standard missing-permission meta plus tool and serving node |
| `tool:show` | `tool:read` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:start` | `tool:start` | target node; active serving gateway for gateway-local tools | Tool must declare an explicit lifecycle capability | `authorization_failed` | Standard missing-permission meta plus tool and serving node |
| `tool:stop` | `tool:stop` | target node; active serving gateway for gateway-local tools | Tool must declare an explicit lifecycle capability | `authorization_failed` | Standard missing-permission meta plus tool and serving node |
| `tool:update` | `tool:update` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `update` | n/a - local-only | n/a | Updates the caller's local Orbit installation; only the source-mounted branch updates a checkout | n/a | n/a |
| `update:all` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `version` | n/a - local-only | n/a | Reads local Orbit release metadata; optional public release lookup only | n/a | n/a |
| `vpn-client:disable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:enable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:list` | `vpn:read` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:new` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:remove` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-web-ui:change-password` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `workspace:history` | `workspace:read` | resolved concrete workspace's instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `instance` |
| `workspace:env` list/render | `workspace:read` | workspace's owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `instance` |
| `workspace:env` set | `workspace:write` | workspace's owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `instance` |
| `workspace:list` | `workspace:read` | selected instance serving node when filtered; otherwise each visible concrete workspace's serving node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `workspace:log` | `workspace:read` | resolved concrete workspace's instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace run and `instance` |
| `workspace:new` | `workspace:new` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace:remove` | `workspace:remove` | workspace's instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `instance` |
| `workspace:setup` | `workspace:setup` | workspace's instance serving `app-dev` node | Self-grant case for app-dev nodes only; app-prod callers and targets are rejected before effects | `authorization_failed` | Standard missing-permission meta plus workspace, app, and `instance` |
| `workspace:show` | `workspace:read` | workspace's instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `instance` |
| `workspace-setup-step:add` | `workspace:setup` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace-setup-step:list` | `workspace:read` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace-setup-step:remove` | `workspace:setup` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace-teardown-step:add` | `workspace:setup` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace-teardown-step:list` | `workspace:read` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |
| `workspace-teardown-step:remove` | `workspace:setup` | selected instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `instance` |

Internal `orbit:internal:*` commands are not public grant surfaces. They are
invoked by controlled bootstrap/install flows and must not be exposed as remote
API commands.

## Authorization classes outside the default grants gate

Remote actions normally require a WireGuard identity, a serving node, and a
stored grant with the required permission. The architecture names the narrow
classes below so an exception is never described as an unspecified ungated
route. Gateway implicit authority is the fourth class and is enforced by the
shared authorizer for callers carrying the gateway role.

### Pre-Grants Bootstrap

These paths exist before useful grants can exist:

- `node:new --template=gateway` for first-gateway bootstrap.
- `gateway:add` for registering a local node connection to an existing gateway.

### Local-Only

These commands mutate or inspect only the caller's local machine and do not need
a gateway permission check.

Local node and DNS helpers:

- `node:default`
- `dns:resolve-tld`
- `dns:list`

Local gateway selection and trust:

- `gateway:list`
- `gateway:trust`
- `gateway:use`

Local maintenance surfaces:

- `update`
- `version`
- `profile`
- `skill:install`

### Identity-Gated Self-Management

These commands require a gateway call and a known WireGuard peer identity and
may change only the caller identity's approved self-management fields:

- `node:manage` when the authenticated identity is an active roleless operator
  node managing itself.
- `node:update --managed|--no-managed` when that same roleless operator identity
  manages itself; gateway targets cannot opt in.

### Gateway-Host Rejection

Commands in the pre-grants bootstrap and local-only deployment-context buckets
may reject when running on a gateway host with `error.code=validation_failed`
and `error.meta.reason=not_supported_on_gateway`.
