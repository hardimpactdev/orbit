# Technical Contract: `orbit node:show [name]`

[Back to public `node:show` documentation.](../node-show.md)

**Owner:** `node`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target node is visible to the current node identity through gateway-owned
  access policy.

**Post-input path eligibility:**
- The resolved node name must match an existing active node record visible to
  the caller.
- The caller must be authorized to inspect the target node through gateway-owned
  access policy.

## Signature

```bash
orbit node:show [name] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | When no default or local context can resolve a target in non-interactive input mode; interactive input mode prompts when missing. | Never. | Interactive: none. Non-interactive: see [Default resolution](5.2_node-show_input-mode_non-interactive.md#default-resolution). | Must match an existing active node record visible to the caller. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

## Input Resolution

1. Resolve `node_show.name` from `[name]` or the selected input mode.
   - Interactive mode prompts with a finite node data table when `[name]` is
     missing. See
     [`5.1_node-show_input-mode_interactive.md`](5.1_node-show_input-mode_interactive.md).
   - Non-interactive mode applies the default resolution chain and fails when
     no target can be resolved. See
     [`5.2_node-show_input-mode_non-interactive.md`](5.2_node-show_input-mode_non-interactive.md).
2. Validate `node_show.name` immediately.
   - Must match an existing active node record.
   - The caller must be authorized to inspect the target node through
     gateway-owned access policy.
3. Select the output renderer and begin the read flow.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-show_input-mode_interactive.md`](5.1_node-show_input-mode_interactive.md):
  prompt mapping, default resolution, and interactive missing-input behavior.
- [`5.2_node-show_input-mode_non-interactive.md`](5.2_node-show_input-mode_non-interactive.md):
  default resolution, missing-input failures, and `--json` input behavior.

## Behavior Contract

### Registry Read Rules

- Read the node record from gateway-owned node configuration by the resolved name.
- If no visible active node record matches, fail before side effects.
- Return the node record and durable gateway-owned grant metadata.
- Default `node:show` is a registry read, not a live readiness command.

### Authorization Rules

- Verify the caller is authorized to inspect the target node through
  gateway-owned access policy.
- If the caller is not authorized, fail before side effects.

### Scope Boundaries

`node:show` must not:
- Mutate gateway configuration or node state.
- Restore drift or adopt node reality.
- SSH into the target node directly from the caller.
- Run live readiness, platform, WireGuard, gateway API, or SSH probes.
- Block on slow or unreachable node runtime checks.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_node-show_output-render_human.md`](6.1_node-show_output-render_human.md): progress
  tree decision, exact human-rendered strings, prose errors, summaries, and next
  steps.
- [`6.2_node-show_output-render_json.md`](6.2_node-show_output-render_json.md): JSON
  envelope, data shape, error codes, error messages, and error metadata.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No visible active node record matches the resolved name. | Failure |
| Not authorized | The caller is not allowed to inspect the target node. | Failure |

`node:show` exits zero whenever the registry read succeeds. Runtime drift and
unverifiable live checks are not part of this command's default read path.
Operators who need readiness or drift information should run
`doctor --family=node`.

## Doctor Relationship

- `node:show` is a registry-backed node view. It does not inspect live node
  readiness.
- `doctor --family=node` is the convergence interface for node drift and owns
  repair behavior.

See [Node Doctor](../../node-doctor.md) for the authoritative node-family
probe, drift, restore, and adopt contract.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed node
registry reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /nodes/{name}` |
| Effect | `read` |
| Subject | `Node` when the node is visible and resolved; `none` for not-found or hidden node responses. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeShowCommandTest.php` | Command contract: input resolution, default fallback chain, caller-role resolution, node lookup, authorization check, registry-only read behavior, no live probe invocation, read-only guarantee, and failure semantics. |

Input-mode-specific test mapping lives in:

- [`5.1_node-show_input-mode_interactive.md`](5.1_node-show_input-mode_interactive.md#test-mapping)
- [`5.2_node-show_input-mode_non-interactive.md`](5.2_node-show_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-show_output-render_human.md`](6.1_node-show_output-render_human.md#test-mapping)
- [`6.2_node-show_output-render_json.md`](6.2_node-show_output-render_json.md#test-mapping)
