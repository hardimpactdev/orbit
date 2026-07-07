# Technical Contract: `orbit node role:add [node] [role]`

[Back to public `node role:add` documentation.](../node-role-add.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Gateway callers execute locally.
- Non-gateway callers have `role:add` on the target node, or an equivalent
  gateway-admin grant.

## Signature

```bash
orbit node role:add [node] [role] [--tld=] [--redis-node=] [--postgres-node=<node>] [--clickhouse-node=<node>] [--s3-data-path=<path>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always. | Never. | None. | Must match an active node record. |
| `role` | `[role]` | Always. | Never. | None. | `gateway`, `vpn`, `router`, and `agent` are rejected. |
| `tld` | `--tld` | Required for `app-dev`. | Forbidden for roles that do not support it. | None. | Must be a single lowercase DNS label without a leading dot. |
| `redis_node` | `--redis-node` | Required for `websocket`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and Redis expected or installed. |
| `postgres_node` | `--postgres-node` | Required for `analytics`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and PostgreSQL expected or installed. |
| `clickhouse_node` | `--clickhouse-node` | Required for `analytics`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and ClickHouse expected or installed. |
| `s3_data_path` | `--s3-data-path` | Never. | Forbidden for roles that do not support it. | `/srv/orbit/s3/data` for `s3`. | Absolute host path mounted into SeaweedFS as `/data`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and forces non-interactive input mode. |

## Behavior Contract

### Role Eligibility Rules

- `gateway` role is rejected before side effects.
- `vpn` and `router` roles are rejected before side effects with
  `validation_failed`. The failure message explains that they are
  gateway-coupled infrastructure roles in v1 and cannot be added
  independently through `node role:add`.
- `agent` role is rejected before side effects with `validation_failed`. The
  failure message points the caller to `node:new --template=agent`, the preferred
  path that may create an agent role assignment.
- `app-dev` requires `--tld`.
- `websocket` requires `--redis-node`. The resolved node must have an active
  `database` role and Redis expected or installed.
- `analytics` requires `--postgres-node` and `--clickhouse-node`. The resolved
  nodes must have active `database` roles and matching PostgreSQL and ClickHouse
  service processes expected or installed. Both options may point at the same
  database node. Either option may point at the target analytics node only when
  the target node also has an active `database` role.
- `s3` accepts optional `--s3-data-path`. The resolved path defaults to
  `/srv/orbit/s3/data`, must be absolute, is stored as `settings.data_path`,
  and is mounted into SeaweedFS as `/data`.
- `metrics` accepts no role-local options.
- `app-prod`, `database`, and other roles reject role-local options they
  do not support.
- Role conflicts are validated by `NodeRoleAssignmentService`.

### Convergence Rules

- Adding a role triggers convergence through `NodeRoleAssignmentService`.
- Success returns the stored assignment payload after convergence completes with
  `status=active`.
- `node role:add` does not create an Orbit Agent work queue. App-dev convergence
  over Orbit Agent remains deferred until it can run as a direct gateway-pushed
  command envelope.
- `orbit_agent_capable=true` marks a node eligible for explicit agent-push
  operations. The `agent` workload role and platform alone do not imply Orbit
  Agent capability.
- If synchronous convergence leaves the assignment in `error`, return a failure
  envelope and leave the errored assignment for `doctor --family=node --restore`.

### Caller Path Rules

- Gateway callers execute the service locally.
- Non-gateway callers forward to the gateway through typed HTTPS.
- The gateway authorizes the request with `role:add` on the target node.

## Renderer Contracts

- [Interactive input](5.1_node-role-add_input-mode_interactive.md)
- [Non-interactive input](5.2_node-role-add_input-mode_non-interactive.md)
- [Human renderer](6.1_node-role-add_output-render_human.md)
- [JSON renderer](6.2_node-role-add_output-render_json.md)

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Node/NodeWriteCommandTest.php` | CLI role:add post, render, and validation before gateway contact. |
| `apps/gateway/tests/Feature/Http/Api/NodeRoleAddControllerTest.php` | Gateway role add, reconverge behavior, gateway-role rejection, and omission of Orbit Agent pull-job metadata. |
| `apps/gateway/tests/Feature/Http/Api/OrbitAgentJobProtocolControllerTest.php` | Guard that Orbit Agent claim/event endpoints are not exposed. |

Input-mode-specific test mapping lives in:

- [`5.1_node-role-add_input-mode_interactive.md`](5.1_node-role-add_input-mode_interactive.md#test-mapping)
- [`5.2_node-role-add_input-mode_non-interactive.md`](5.2_node-role-add_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-role-add_output-render_human.md`](6.1_node-role-add_output-render_human.md#test-mapping)
- [`6.2_node-role-add_output-render_json.md`](6.2_node-role-add_output-render_json.md#test-mapping)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node matches `node`. | Failure |
| Convergence failed | Role assignment was stored but baseline convergence ended in `error`. | `error.code=node_role.convergence_failed`, `error.meta.last_error=<recorded convergence error>` |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:add` only changes desired state and triggers convergence.
