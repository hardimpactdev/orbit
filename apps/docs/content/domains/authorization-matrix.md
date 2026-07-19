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
| `agent-ide:message` | `agent-ide:message` | selected app instance's serving node, or resolved workspace's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus resolved app/workspace and `app_instance` when available |
| `app:agent-ide` | `app:agent` | selected concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:list` | `app:read` | at least one concrete Orbit app instance serving node per returned logical app | Logical-app and workspace placement filtering applies | `authorization_failed` | Standard missing-permission meta when the caller has no visible app-instance serving node |
| `app:instance list` | `app:read` | each concrete Orbit instance serving node | Row-level filtering applies; external-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta when no instance is visible |
| `app:instance show` | `app:read` | selected concrete Orbit instance serving node | External-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `app:instance add` | `app:write` | explicitly selected Orbit target node | External-driver creation is gateway-only; no logical-app default node | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:instance remove` | `app:write` | selected concrete Orbit instance serving node | External-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `app:new` | `app:new` | target app node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:prune` | `app:prune` | selected concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:register` | `app:register` | target app node | `app-dev` self-grants include same-node registration; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:remove` | `app:remove` | every affected Orbit app instance's serving node | Logical-wide destructive cascade preauthorizes all affected Orbit instances before effects; external-driver instances remain gateway-owned | `authorization_failed` | Standard missing-permission meta plus `app`, denied `app_instance`, and its serving node |
| `app:root` | `app:root` | selected concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:setup` | `app:write` | selected concrete app instance's serving node | Bare logical shorthand requires exactly one instance; `app:write` and `app:*` imply `app:setup` | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app-setup-step:add` | `app:write` | selected concrete app instance's serving node | Bare logical shorthand requires exactly one instance; `app:write` and `app:*` imply `app-setup-step:add` | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app-setup-step:list` | `app:read` | selected concrete app instance's serving node | Bare logical shorthand requires exactly one instance; `app:read` and `app:*` imply `app-setup-step:list` | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app-setup-step:remove` | `app:write` | selected concrete app instance's serving node | Bare logical shorthand requires exactly one instance; `app:write` and `app:*` imply `app-setup-step:remove` | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:show` | `app:read` | at least one caller-visible concrete Orbit app instance serving node | Return only authorized Orbit instances; external-driver instances are gateway-only | `authorization_failed` | Standard missing-permission meta when the caller has no visible app-instance serving node |
| `app:analytics disable` | `app:write` | selected concrete app instance's serving node | Public tracking-host placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:analytics enable` | `app:write` | selected concrete app instance's serving node | Public domain and tracking-host placement follow the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:analytics show` | `app:read` | selected concrete app instance's serving node | Shared non-placement policy may remain logical-app-owned; placement rows remain instance-filtered | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:analytics verify` | `app:read` | selected concrete app instance's serving node | Verification context follows the selected instance's public hosts | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:websocket credentials` | `app:credentials` | selected concrete app instance's serving node | Explicit credential-read permission; not implied by `app:read` or `app:write` | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:websocket disable` | `app:write` | selected concrete app instance's serving node | WebSocket binding placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `app:websocket enable` | `app:write` | selected concrete app instance's serving node | WebSocket binding placement follows the selected instance | `authorization_failed` | Standard missing-permission meta plus `app` and `app_instance` |
| `cf-cache:flush` | `cf:cache:flush` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-cache-rule:add` | `cf:cache:rule:add` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when app-scoped |
| `cf-cache-rule:remove` | `cf:cache:rule:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when app-scoped |
| `cf-dns:add` | `cf:dns:add` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:list` | `cf:dns:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:remove` | `cf:dns:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:disable` | `cf:ssl:disable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:enable` | `cf:ssl:enable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-zone:list` | `cf:zone:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `codex:app add` | `codex:app` | selected concrete Orbit app instance's serving node and selected Codex App target node | Requires a dotted Orbit app-instance selector; no app permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus `app`, `app_instance`, serving node, and selected Codex node |
| `codex:app list` | `codex:app` | selected Codex App target node | No app permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus selected node |
| `codex:app remove` | `codex:app` | selected concrete Orbit app instance's serving node and selected Codex App target node | Requires a dotted Orbit app-instance selector; no app permission implication; target node must be active visible non-gateway and supported by `codex-app` OS metadata | `authorization_failed` | Standard missing-permission meta plus `app`, `app_instance`, serving node, and selected Codex node |
| `database:add` | `database:write` | target database host or selected app-instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `database:add-user` | `database:write` | managed MySQL process node | None | `authorization_failed` | Standard missing-permission meta plus service |
| `database:attach` | `database:write` | target database connection and selected app-instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:describe` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:detach` | `database:write` | target database connection and selected app-instance/workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:list` | `database:read` | per visible target | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `database:query` | `database:query`; `database:query:write` for mutating SQL | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection and query mode |
| `database:remove` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:schema` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:show` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:tables` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:update` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `deploy:history` | `deploy:read` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `deploy:log` | `deploy:read` | selected deployment run's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus deployment run and `app_instance` |
| `deploy:run` | `deploy:run` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `deploy:step-add` | `deploy:step` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `deploy:step-list` | `deploy:read` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `deploy:step-remove` | `deploy:step` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
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
| `gateway:trust` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `manifest:update` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `manifest:remove` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `metrics:credentials` read mode | `tool:credentials` | selected active metrics node | V1 process-backed permission for Grafana credentials | `authorization_failed` | Standard missing-permission meta plus selected node and process |
| `metrics:credentials --reset` | `tool:credentials` | selected active metrics node | V1 uses the explicit credentials permission for Grafana password rotation | `authorization_failed` | Standard missing-permission meta plus selected node and process |
| `metrics:disable` | `role:remove` | target node | Delegates to metrics role removal; `--force` required before side effects | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `metrics:enable` | `role:add` | target node | Delegates to metrics role assignment and role baseline convergence | `authorization_failed` | Standard missing-permission meta plus target node and role |
| `metrics:status` | `process:read` | selected metrics node, or each visible metrics node | Row-level filtering applies when `--node` is absent | `authorization_failed` | Standard missing-permission meta plus selected node when requested |
| `node:agent-ide` | `node:agent` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
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
| `php:list` | `php:read` | explicit target node or selected app-instance/workspace serving node | A bare logical app is rejected with `app_instance_required`; external-driver instances expose no invented Orbit node | `authorization_failed` | Standard missing-permission meta plus the resolved serving node |
| `php:use` | `php:write` | explicit CLI target node, selected workspace serving node, or every affected Orbit app instance serving node for a logical-app policy write | Logical-app policy writes preauthorize every affected Orbit instance and verify each node's image availability before mutation | `authorization_failed` | Standard missing-permission meta plus denied `app_instance` and serving node when app-scoped |
| `process:add` | `process:add` | node owner, or concrete app-instance serving node | `app-dev` self-grants include same-node app-instance process definition creation; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus resolved process scope and app instance |
| `process:list` | `process:read` | node owner, or concrete app-instance serving node | Row-level filtering applies; bare logical-app shorthand requires exactly one instance | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible serving node |
| `process:logs` | `process:read` | node owner, or concrete app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus process and app instance when applicable |
| `process:remove` | `process:remove` | node owner, or concrete app-instance serving node | `app-dev` self-grants include same-node app-instance process definition removal; `app-prod` self-grants do not | `authorization_failed` | Standard missing-permission meta plus process and app instance when applicable |
| `process:restart` | `process:restart` | node owner, or concrete app-instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and app instance when applicable |
| `process:start` | `process:start` | node owner, or concrete app-instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and app instance when applicable |
| `process:stop` | `process:stop` | node owner, or concrete app-instance serving node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process and app instance when applicable |
| `process:update` | `process:update` | node owner, or concrete app-instance serving node | Public mutation surface for command, policy, runtime, and supported identity renames; `app-dev` self-grants include same-node app-instance process definition updates | `authorization_failed` | Standard missing-permission meta plus resolved process scope and app instance |
| `profile` | n/a - local-only | n/a | No gateway call, identity lookup, grant check, or activity entry | n/a | n/a |
| `proxy:add` | `proxy:add` | explicit target node, selected app-instance serving node, or workspace serving node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `proxy:list` | `proxy:read` | explicit target node or each route's serving node | Row-level filtering applies independently per route serving node | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible route serving node |
| `proxy:remove` | `proxy:remove` | selected route's serving node | None | `authorization_failed` | Standard missing-permission meta plus route and serving node |
| `s3:credentials` | `tool:credentials` | selected active S3 node | V1 tool-backed permission for the `seaweedfs` tool row | `authorization_failed` | Standard missing-permission meta plus selected node and tool |
| `s3:publish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; mutates S3-owned proxy publication intent through router and ingress route convergence | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `s3:unpublish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; `--force` required in non-interactive mode, including `--json` | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `schedule:add` | `schedule:add` | selected target node; for app scope, selected concrete app instance's serving node | Bare app shorthand requires exactly one eligible visible instance | `authorization_failed` | Standard missing-permission meta plus schedule target and `app_instance` when applicable |
| `schedule:list` | `schedule:read` | persisted target node; for app scope, persisted concrete app instance's serving node | Row-level filtering applies; bare app shorthand requires exactly one eligible visible instance | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `schedule:logs` | `schedule:read` | persisted target node; for app scope, persisted concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `app_instance` when applicable |
| `schedule:remove` | `schedule:remove` | persisted target node; for app scope, persisted concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `app_instance` when applicable |
| `schedule:run` | `schedule:run` | persisted target node; for app scope, persisted concrete app instance's serving node | Caller permission is checked once for a manual request; gateway execution does not re-authorize | `authorization_failed` | Standard missing-permission meta plus schedule and `app_instance` when applicable |
| `schedule:show` | `schedule:read` | persisted target node; for app scope, persisted concrete app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus schedule and `app_instance` when applicable |
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
| `vpn-client:disable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:enable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:list` | `vpn:read` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:new` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:remove` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-web-ui:change-password` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `workspace:history` | `workspace:read` | resolved concrete workspace's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `app_instance` |
| `workspace:list` | `workspace:read` | selected app-instance serving node when filtered; otherwise each visible concrete workspace's serving node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `workspace:log` | `workspace:read` | resolved concrete workspace's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace run and `app_instance` |
| `workspace:new` | `workspace:new` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace:remove` | `workspace:remove` | workspace's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `app_instance` |
| `workspace:setup` | `workspace:setup` | workspace's app-instance serving `app-dev` node | Self-grant case for app-dev nodes only; app-prod callers and targets are rejected before effects | `authorization_failed` | Standard missing-permission meta plus workspace, app, and `app_instance` |
| `workspace:show` | `workspace:read` | workspace's app-instance serving node | None | `authorization_failed` | Standard missing-permission meta plus workspace and `app_instance` |
| `workspace-setup-step:add` | `workspace:setup` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace-setup-step:list` | `workspace:read` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace-setup-step:remove` | `workspace:setup` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace-teardown-step:add` | `workspace:setup` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace-teardown-step:list` | `workspace:read` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |
| `workspace-teardown-step:remove` | `workspace:setup` | selected app instance's serving node | None | `authorization_failed` | Standard missing-permission meta plus `app_instance` |

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
a gateway permission check:

- `node:default`
- `dns:resolve-tld`
- `dns:list`
- `gateway:trust`
- `update`
- `profile`

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
