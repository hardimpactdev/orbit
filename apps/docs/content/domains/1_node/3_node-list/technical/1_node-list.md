# Technical Contract: `orbit node:list`

[Back to public `node:list` documentation.](../node-list.md)

**Owner:** `node`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible node registry configuration.

## Signature

```bash
orbit node:list [--role=<gateway|vpn|router|app-dev|app-prod|database|agent|ingress|websocket|s3>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `role` | `--role` | Optional. | Never. | None. | One of `gateway`, `vpn`, `router`, `app-dev`, `app-prod`, `database`, `agent`, `ingress`, `websocket`, `s3`. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

`--role` is a scalar enum filter with single-value semantics; comma-separated
input fails as `validation_failed` because it is not one of the allowed
values, and invalid values fail before side effects. Multi-value semantics
are not part of the initial contract. Operators who need to query multiple
roles at once should run `node:list --json` without that filter and
post-filter the result, or run separate scoped invocations.

## Input Resolution

1. Resolve `node_list.role` from `--role` when present. Validate immediately.
2. Select the output renderer and query the gateway for visible node registry
   configuration.

## Behavior Contract

### Visibility Rules

- Read visible node registry configuration scoped to the current consuming node's
  access policy.
- Filter visibility at the gateway as set membership against gateway-owned node
  access policy.
- Do not probe hosts as part of the base list operation.
- Hidden nodes are omitted entirely; the command does not return placeholder
  rows for unauthorized nodes.
- Do not surface a per-node `visible` field or otherwise leak the existence of
  nodes the caller cannot access.
- An authorized caller with no visible nodes receives an empty list and exit
  zero.
- A caller whose identity is not authorized to read the node registry at all
  receives `error.code=authorization_failed`. This is the only path that
  distinguishes "authorized but empty" from "not allowed to read."

### Filter and sort rules

- If `--role` is present, include only nodes with the matching effective role assignment. The filter uses active `node_role` assignments.
- Preserve the gateway's sort order for every output renderer. The gateway sorts
  by effective role assignment and then by node name. Renderer contracts own
  presentation shape.

### Scope Boundaries

`node:list` must not:
- SSH into nodes.
- Probe host reachability or health in the base operation.
- Modify gateway configuration, node records, access grants, or WireGuard state.
- Mint identity, write peer material, or grant access.
- Touch downstream family state.

## Renderer Contracts

- [Human renderer](6.1_node-list_output-render_human.md)
- [JSON renderer](6.2_node-list_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--role` contains an unsupported value, including comma-separated input. | Failure |

## Doctor Relationship

- `node:list` reports configuration. `doctor --family=node` verifies reality.
- See [`node-doctor.md`](../../node-doctor.md) for the authoritative node-family
  probe, drift, restore, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /nodes` |
| Effect | `read` |
| Subject | `none` |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeListCommandTest.php` | Command contract: listing all visible nodes, role filtering, gateway-unavailable failure, invalid filter validation, authorization failure, and read-only guarantee (no SSH, no configuration mutation). |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php` | JSON envelope shape, success payload with node array, filter error JSON shape, and enum values. |
| `apps/gateway/tests/Feature/Commands/Nodes/NodeListHumanRendererTest.php` | Human renderer selection, table grouping by role, success prose, filter error prose, and exact error messages. |

Renderer-specific test mapping lives in:

- [`6.1_node-list_output-render_human.md`](6.1_node-list_output-render_human.md#test-mapping)
- [`6.2_node-list_output-render_json.md`](6.2_node-list_output-render_json.md#test-mapping)
