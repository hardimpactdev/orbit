# Technical Contract: `orbit node role:add [node] [role]`

[Back to public `node role:add` documentation.](../node-role-add.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- Caller is authenticated through the gateway WireGuard identity path.
- Every public CLI caller uses the typed gateway HTTPS API.
- Normal callers have `role:add` on the target node, or an equivalent
  gateway-admin grant; gateway-role callers use implicit authority.

## Signature

```bash
orbit node role:add [node] [role] [--valkey-node=] [--postgres-node=<node>] [--postgres-process=<process>] [--clickhouse-node=<node>] [--s3-data-path=<path>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `node` | `[node]` | Always. | Never. | None. | Must match an active node record. |
| `role` | `[role]` | Always. | Never. | None. | `gateway`, `vpn`, `router`, and `agent` are rejected. |
| `valkey_node` | `--valkey-node` | Required for `websocket`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and Valkey expected or installed. |
| `postgres_node` | `--postgres-node` | Required for `analytics`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and PostgreSQL expected or installed. |
| `postgres_process` | `--postgres-process` | Required for new `analytics` assignments. | Forbidden for roles that do not support it. | Existing compatible assignment's stored process identity. | Must match a node-owned `postgres` service process on `postgres_node` with `version_family=16`. The gateway persists its process ID. Other families fail validation because Plausible requires PostgreSQL 16. |
| `clickhouse_node` | `--clickhouse-node` | Required for `analytics`. | Forbidden for roles that do not support it. | None. | Must match an active node with the `database` role and ClickHouse expected or installed. |
| `s3_data_path` | `--s3-data-path` | Never. | Forbidden for roles that do not support it. | `/srv/orbit/s3/data` for `s3`. | Canonical host path under `/media`, `/mnt`, `/opt/orbit`, `/srv`, or `/var/lib/orbit`, mounted into SeaweedFS as `/data`. |
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
- `app-dev` accepts no role-local settings and consumes the target node's
  mandatory node-owned TLD.
- `websocket` requires `--valkey-node`. The resolved node must have an active
  `database` role and Valkey expected or installed.
- `analytics` requires `--postgres-node`, `--postgres-process`, and
  `--clickhouse-node`. The resolved PostgreSQL process must be owned by the
  selected PostgreSQL node, retain `service=postgres`, and have
  `version_family=16`. The resolved
  nodes must have active `database` roles and matching PostgreSQL and ClickHouse
  service processes expected or installed. Both options may point at the same
  database node. Either option may point at the target analytics node only when
  the target node also has an active `database` role.
- `analytics` is fleet-singleton. A pending, active, errored, or removing
  analytics role assignment on another node rejects the request before an
  assignment or runtime side effect is created. Removal releases the slot only
  after its cleanup completes and the assignment row is deleted.
- `s3` accepts optional `--s3-data-path`. The resolved path defaults to
  `/srv/orbit/s3/data`, must be canonical and rooted under `/media`, `/mnt`,
  `/opt/orbit`, `/srv`, or `/var/lib/orbit`, is stored as
  `settings.data_path`, and is mounted into SeaweedFS as `/data`.
- `metrics` accepts no role-local options.
- `app-prod`, `database`, and other roles reject role-local options they
  do not support.
- Role conflicts are validated by `NodeRoleAssignmentService`.

### Convergence Rules

- Adding a role triggers convergence through `NodeRoleAssignmentService`.
- Success returns the stored assignment payload after convergence completes with
  `status=active`.
- Analytics success requires the Plausible runtime, router-owned
  `analytics.orbit` route, rendered Caddy site, and Orbit-managed TLS material
  to converge. Any route enactment failure leaves the assignment in `error`.
- Websocket convergence resolves the selected release manifest's
  `orbit-websocket` image. When its verified image archive is available,
  convergence downloads it over HTTPS, verifies its SHA-256, and loads it
  without registry credentials; otherwise it pulls the digest-pinned image.
  The target verifies that the image is self-contained before updating the
  runtime alias and applying the Reverb container. If the
  manifest endpoint is unreachable, convergence preserves the existing local
  alias inspection and source-checkout fallback. Existing mutable websocket
  references are never installed and use that same safe fallback; newly
  generated manifests reject them.
- App-development convergence runs as direct gateway-pushed command envelopes.
- Active workload roles supply Agent intent. A roleless non-gateway operator
  may instead opt in through `managed`; platform and WireGuard eligibility
  still apply, and gateway nodes are never Agent targets.
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
| Analytics role already assigned | Another analytics assignment exists in the fleet, including an incomplete deployment or removal. | `error.code=validation_failed` |
| Analytics PostgreSQL version unsupported | The selected PostgreSQL process does not have `version_family=16`. | `error.code=validation_failed` before role assignment |
| Convergence failed | Role assignment was stored but baseline convergence ended in `error`. | `error.code=node_role.convergence_failed`, `error.meta.last_error=<recorded convergence error>` |

## Doctor Relationship

- [`doctor --family=node`](../../node-doctor.md) owns retry and drift repair for errored role assignments.
- `node role:add` only changes desired state and triggers convergence.
