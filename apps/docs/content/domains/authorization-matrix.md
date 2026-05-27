# Authorization Matrix

This matrix is the implementation checklist for gateway API authorization. The
architecture remains the authority for the authorization model; this file maps
command surfaces to concrete permission checks.

Unless a row states otherwise, denial uses `error.code=authorization_failed` and
`error.meta={reason: missing_permission, missing_permission: <permission>,
serving_node: <resolved node name>}`. Gateway-role callers keep implicit
authority as described in [Architecture: Gateway implicit
authority](../architecture.md#gateway-implicit-authority).

| Command | Required permission | Serving node resolution | Deployment-context exceptions | Error code on deny | Error meta shape |
| --- | --- | --- | --- | --- | --- |
| `activity:list` | `activity:read` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `activity:show` | `activity:read` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `agent-ide:message` | `agent-ide:message` | resolved app or workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus resolved app/workspace when available |
| `app:agent-ide` | `app:agent` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `app:list` | `app:read` | app owning node per returned row | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested filter resolves to no visible node |
| `app:new` | `app:new` | target app node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:prune` | `app:prune` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `app:register` | `app:register` | target app node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `app:remove` | `app:remove` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `app:root` | `app:root` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `app:show` | `app:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `cf-cache:flush` | `cf:cache:flush` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-cache-rule:add` | `cf:cache:rule:add` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when app-scoped |
| `cf-cache-rule:remove` | `cf:cache:rule:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta plus `app` when app-scoped |
| `cf-dns:add` | `cf:dns:add` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:list` | `cf:dns:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-dns:remove` | `cf:dns:remove` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:disable` | `cf:ssl:disable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-ssl:enable` | `cf:ssl:enable` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `cf-zone:list` | `cf:zone:list` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `database:add` | `database:write` | target database host or app/workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `database:attach` | `database:write` | target database connection and app/workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:describe` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:detach` | `database:write` | target database connection and app/workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus connection and target |
| `database:list` | `database:read` | per visible target | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `database:query` | `database:query`; `database:query:write` for mutating SQL | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection and query mode |
| `database:remove` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:schema` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:show` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:tables` | `database:read` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `database:update` | `database:write` | target database connection node | None | `authorization_failed` | Standard missing-permission meta plus connection |
| `deploy:history` | `deploy:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `deploy:log` | `deploy:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus deployment run |
| `deploy:run` | `deploy:run` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `deploy:step-add` | `deploy:step` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `deploy:step-list` | `deploy:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `deploy:step-remove` | `deploy:step` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `dns:list` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `dns:resolve-tld` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `doctor` verify mode | `doctor:verify` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `doctor --restore` | `doctor:restore` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `doctor --adopt` | `doctor:adopt` | per selected scope | None | `authorization_failed` | Standard missing-permission meta plus scope |
| `firewall:allow` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `firewall:deny` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `firewall:list` | `firewall_rule:read` | target node or each visible node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta plus target node when requested |
| `firewall:remove` | `firewall_rule:write` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `gateway:add` | n/a - pre-grants bootstrap | n/a | Deployment-context command | n/a | n/a |
| `gateway:trust` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `node:agent-ide` | `node:agent` | target node | None | `authorization_failed` | Standard missing-permission meta plus target node |
| `node:default` | n/a - local-only | n/a | Deployment-context command | n/a | n/a |
| `node:grant` | `node:grant` | gateway | None | `authorization_failed` | Standard missing-permission meta |
| `node:list` | `node:read` | gateway, filtered by visible node grants | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta only for requested unavailable targets |
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
| `node role:update` | deleted in PR 2d | n/a | Deprecated surface; use `node:update` for node metadata | n/a | n/a |
| `php:list` | `php:read` | target node, app owning node, or workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `php:use` | `php:write` | target node, app owning node, or workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `process:add` | `process:add` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `process:edit` | `process:edit` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus `app` |
| `process:list` | `process:read` | app owning node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `process:logs` | `process:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus process |
| `process:remove` | `process:remove` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus process |
| `process:restart` | `process:restart` | app owning node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process |
| `process:start` | `process:start` | app owning node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process |
| `process:stop` | `process:stop` | app owning node | Transitive calls inside `workspace:setup` do not re-authorize | `authorization_failed` | Standard missing-permission meta plus process |
| `profile` | n/a - authenticated but ungated | resolved subject owning node | Requires authenticated WireGuard identity, no permission check | n/a | n/a |
| `proxy:add` | `proxy:add` | target node, app owning node, or workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus resolved target |
| `proxy:list` | `proxy:read` | target node or each visible route owner | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `proxy:remove` | `proxy:remove` | route owning node | None | `authorization_failed` | Standard missing-permission meta plus route |
| `s3:credentials` | `tool:credentials` | selected active S3 node | V1 tool-backed permission for the `rustfs` tool row | `authorization_failed` | Standard missing-permission meta plus selected node and tool |
| `s3:publish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; mutates S3-owned proxy publication intent through router and ingress route convergence | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `s3:unpublish` | `tool:reconfigure` | selected active S3 node | V1 tool-backed permission; `--force` required in non-interactive mode, including `--json` | `authorization_failed` | Standard missing-permission meta plus selected node, host, and tool |
| `schedule:add` | `schedule:add` | schedule target node or app owning node | None | `authorization_failed` | Standard missing-permission meta plus schedule target |
| `schedule:list` | `schedule:read` | schedule target node or app owning node | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `schedule:logs` | `schedule:read` | schedule target node or app owning node | None | `authorization_failed` | Standard missing-permission meta plus schedule |
| `schedule:remove` | `schedule:remove` | schedule target node or app owning node | None | `authorization_failed` | Standard missing-permission meta plus schedule |
| `schedule:run` | `schedule:run` | schedule target node or app owning node | None | `authorization_failed` | Standard missing-permission meta plus schedule |
| `schedule:show` | `schedule:read` | schedule target node or app owning node | None | `authorization_failed` | Standard missing-permission meta plus schedule |
| `tool:credentials` | `tool:credentials` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:install` | `tool:install` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:list` | `tool:read` | target node or each visible tool owner | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `tool:logs` | `tool:read` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:reconfigure` | `tool:reconfigure` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:reload` | `tool:reload` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:remove` | `tool:remove` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:restart` | `tool:restart` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:show` | `tool:read` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:start` | `tool:start` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:stop` | `tool:stop` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `tool:update` | `tool:update` | target node | None | `authorization_failed` | Standard missing-permission meta plus tool |
| `update` | n/a - local-only | n/a | Updates caller's own checkout | n/a | n/a |
| `update:all` | gateway-admin only | gateway | No narrow permission | `authorization_failed` | `reason=missing_gateway_admin`, `serving_node=<gateway>` |
| `vpn-client:disable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:enable` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-client:list` | `vpn:read` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:new` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `vpn-client:remove` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta plus client |
| `vpn-web-ui:change-password` | `vpn:write` | gateway | v1 gateway-coupled VPN role | `authorization_failed` | Standard missing-permission meta |
| `workspace:history` | `workspace:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace/app |
| `workspace:list` | `workspace:read` | app owning node or each visible workspace owner | Row-level filtering applies | `authorization_failed` | Standard missing-permission meta when a requested target resolves to no visible node |
| `workspace:log` | `workspace:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace run |
| `workspace:new` | `workspace:new` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace:remove` | `workspace:remove` | workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace |
| `workspace:setup` | `workspace:setup` | workspace owning node | Self-grant case for app-development and app-production nodes | `authorization_failed` | Standard missing-permission meta plus workspace/app |
| `workspace:show` | `workspace:read` | workspace owning node | None | `authorization_failed` | Standard missing-permission meta plus workspace |
| `workspace-setup-step:add` | `workspace:setup` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace-setup-step:list` | `workspace:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace-setup-step:remove` | `workspace:setup` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace-teardown-step:add` | `workspace:setup` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace-teardown-step:list` | `workspace:read` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |
| `workspace-teardown-step:remove` | `workspace:setup` | app owning node | None | `authorization_failed` | Standard missing-permission meta plus app |

Internal `orbit:internal:*` commands are not public grant surfaces. They are
invoked by controlled bootstrap/install flows and must not be exposed as remote
API commands.

## Commands outside the standard grants flow

Most commands require a WireGuard identity, a serving node, and a stored grant
with the required permission. The commands below are deliberate exceptions.

### Pre-Grants Bootstrap

These paths exist before useful grants can exist:

- `node:new --role=gateway` for first-gateway bootstrap.
- `gateway:add` for registering a local node connection to an existing gateway.

### Local-Only Deployment Context

These commands mutate or inspect only the caller's local machine and do not need
a gateway permission check:

- `node:default`
- `dns:resolve-tld`
- `dns:list`
- `gateway:trust`
- `update`

### Authenticated But Ungated

These commands require a gateway call and a known WireGuard peer identity, but
do not require a permission check:

- `profile`

### Gateway-Host Rejection

Commands in the pre-grants bootstrap and local-only deployment-context buckets
may reject when running on a gateway host with `error.code=validation_failed`
and `error.meta.reason=not_supported_on_gateway`.
