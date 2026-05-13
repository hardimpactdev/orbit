# Technical Contract: `orbit node:list`

[Back to public `node:list` documentation.](../node-list.md)

**Owner:** `node`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to read visible node registry configuration.

## Signature

```bash
orbit node:list [--role=<gateway|app|control>] [--environment=<development|production>] [--doctor] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

No required inputs exist; the command takes no positional arguments and all
options are optional.

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `role` | `--role` | Optional. | Never. | None. | One of `gateway`, `app`, `control`. Single value only; comma-separated input fails as `validation_failed` because it is not one of the allowed values. Invalid values fail before side effects. |
| `environment` | `--environment` | Optional. | Never. | None. | One of `development`, `production`. Single value only; comma-separated input fails as `validation_failed` because it is not one of the allowed values. Invalid values fail before side effects. |
| `doctor` | `--doctor` | Optional. | Never. | `false`. | Boolean flag. Explicit secondary operation. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

`--role` and `--environment` are scalar enum filters. Multi-value semantics are
not part of the initial contract. Operators who need to query multiple roles or
environments at once should run `node:list --json` without that filter and
post-filter the result, or run separate scoped invocations.

## Authorization By Caller Role

The gateway authenticates the CLI's WireGuard identity and serves the list of
nodes visible to that identity. All roles (`control`, `gateway`, `app`) may
read `node:list`; the response is scoped by gateway-owned access policy, not
gated by role. App-role callers are not rejected.

## Input Resolution

1. Resolve `node_list.role` from `--role` when present. Validate immediately.
2. Resolve `node_list.environment` from `--environment` when present. Validate immediately.
3. Resolve `node_list.doctor` from `--doctor`. Default `false`.
4. Select the output renderer and query the gateway for visible node registry
   configuration.
5. If `--doctor` is present, run node doctor checks as an explicit secondary
   operation after the list query succeeds. Attach doctor summaries to the
   output.

## Input Mode Contracts

No input-mode-specific contracts are required. The command takes no required
arguments and does not prompt. `--json` and filters behave the same in both
interactive and non-interactive modes.

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

### Filter And Sort Rules

- If `--role` is present, include only nodes with the matching role.
- If `--environment` is present, include only app nodes with the matching
  environment.
- Filters combine with AND semantics.
- Human output groups nodes by role. JSON output preserves the gateway's sort
  order.

### Doctor Summary Rules

- If `--doctor` is present, run node doctor checks and include a summary.
- Treat doctor checks as an explicit secondary operation because they may
  perform live checks and take longer than a registry list.
- Return the filtered node list through the selected output renderer after the
  optional doctor summary is attached.

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

| Failure | Condition | Outcome |
| --- | --- | --- |
| Invalid filter value | `--role` or `--environment` contains an unsupported value, including comma-separated input. | Failure |
| Gateway unavailable | The CLI cannot reach the gateway API. | Failure |
| Authorization failed | The caller identity is not authorized to read the node registry. | Failure |

Doctor findings are not failures of `node:list`. When `--doctor` is present
and the secondary doctor probe reports drift on one or more nodes, the
command still exits zero because the primary registry read succeeded. The
findings are reported as structured metadata under `success.meta.doctor` in
JSON output and beneath the table in human output. Operators who want
exit-on-drift semantics should run `orbit doctor --family=node [--json]`
instead of relying on `--doctor` here.

## Doctor Relationship

- `node:list` reports configuration. `doctor --family=node` verifies reality.
- `--doctor` is an explicit secondary operation that runs node doctor checks and
  includes their summaries. It must remain explicit because it can be slow.
- `--doctor` is a node-family-only convenience flag, not a shared list-command
  convention. App and workspace list commands remain registry-only and use
  `doctor --family=app` or `doctor --family=workspace` for live verification.
- Node doctor checks may report drift, missing peers, or readiness issues. These
  are summarized in the output but do not cause the list command to fail.
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
| `tests/Feature/Commands/Nodes/NodeListCommandTest.php` | Command contract: listing all visible nodes, role filtering, environment filtering, combined filters, `--doctor` secondary operation, gateway-unavailable failure, invalid filter validation, authorization failure, and read-only guarantee (no SSH, no configuration mutation). |
| `tests/Feature/Commands/Nodes/NodeListJsonRendererTest.php` | JSON envelope shape, success payload with node array, `--doctor` meta attachment, filter error JSON shape, and enum values. |
| `tests/Feature/Commands/Nodes/NodeListHumanRendererTest.php` | Human renderer selection, table grouping by role, success prose, filter error prose, and `--doctor` summary prose. |

Renderer-specific test mapping lives in:

- [`6.1_node-list_output-render_human.md`](6.1_node-list_output-render_human.md#test-mapping)
- [`6.2_node-list_output-render_json.md`](6.2_node-list_output-render_json.md#test-mapping)
