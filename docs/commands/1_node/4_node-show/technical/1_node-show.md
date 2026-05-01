# Technical Contract: `orbit node:show [name]`

[Back to public `node:show` documentation.](../node-show.md)

**Owner:** `node`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The local caller role can be resolved according to the foundation
  [local node role setting](../../../../BLUEPRINT.md#local-node-role-setting)
  contract and the node-family
  [Local Caller Role](../../README.md#local-caller-role) contract.
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
| `name` | `[name]` | When no default or local context can resolve a target in non-interactive input mode; interactive input mode may prompt instead. | Never. | See [Default resolution](5.1_node-show_input-mode_interactive.md#default-resolution). | Must match an existing active node record visible to the caller. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/commands/README.md`](../../../README.md#invocation-model). |

## Caller Role Behavior

`node:show` resolves the caller role from the local node role setting before it
reads command inputs or renders prompts. See the node-family
[Local Caller Role](../../README.md#local-caller-role) contract.

If `general.local_node_role` is unset or `null`, the caller role is `control`.
Gateway and app callers must be explicit through `general.local_node_role`.

Unlike `node:new` and `node:remove`, `node:show` does not reject app-node
callers before side effects. App-node callers may inspect nodes they are
authorized to see through gateway-owned access policy. The command behavior is
access-policy-driven, not role-driven.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the request to the gateway over HTTPS through WireGuard when configured. Unconfigured control nodes before first-gateway bootstrap cannot resolve a calling node and fail before side effects. |
| `gateway` | Executes locally on the gateway. |
| `app` | Forwards the request to the gateway over HTTPS through WireGuard. May inspect nodes visible to the app node through access policy. |
| `unknown` | Invalid local context. Used only when `general.local_node_role` contains an unsupported value or cannot be read. Fail before prompts or side effects with a local context error. Missing `general.local_node_role` does not produce `unknown`; it defaults to `control`. |

Because caller role does not change command validity for `node:show`, no
role-specific companion contracts are needed. Role differences are limited to
request forwarding versus local execution and caller-identity resolution for the
local default development app-node fallback.

## Input Resolution

1. Resolve caller role.
   - Read `general.local_node_role` before reading command arguments or
     rendering prompts.
   - If `general.local_node_role` is unset or `null`, resolve caller role as
     `control`.
   - If `general.local_node_role` is `control`, `gateway`, or `app`, use that
     value for local path selection.
   - If the local role setting contains an unsupported value or cannot be read,
     resolve caller role as `unknown` and fail before prompts or side effects.
2. Resolve `node_show.name` from `[name]` or the selected input mode.
   - Interactive mode applies the default resolution chain and prompts when
     needed. See
     [`5.1_node-show_input-mode_interactive.md`](5.1_node-show_input-mode_interactive.md).
   - Non-interactive mode applies the default resolution chain and fails when
     no target can be resolved. See
     [`5.2_node-show_input-mode_non-interactive.md`](5.2_node-show_input-mode_non-interactive.md).
3. Validate `node_show.name` immediately.
   - Must match an existing active node record.
   - The caller must be authorized to inspect the target node through
     gateway-owned access policy.
4. Select the output renderer and begin the read flow.

## Input Mode Contracts

Input mode behavior is split out of the canonical command contract:

- [`5.1_node-show_input-mode_interactive.md`](5.1_node-show_input-mode_interactive.md):
  prompt mapping, default resolution, and interactive missing-input behavior.
- [`5.2_node-show_input-mode_non-interactive.md`](5.2_node-show_input-mode_non-interactive.md):
  default resolution, missing-input failures, and `--json` input behavior.

## Behavior Contract

### Registry Read Rules

- Read the node record from gateway-owned node intent by the resolved name.
- If no visible active node record matches, fail before side effects.
- Return the node record and durable gateway-owned grant metadata.
- Default `node:show` is a registry read, not a live readiness command.

### Authorization Rules

- Verify the caller is authorized to inspect the target node through
  gateway-owned access policy.
- If the caller is not authorized, fail before side effects.

### Scope Boundaries

`node:show` must not:
- Mutate gateway intent or node state.
- Fix drift or adopt node reality.
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

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No visible active node record matches the resolved name. | Failure |
| Not authorized | The caller is not allowed to inspect the target node. | Failure |
| Local context invalid | `general.local_node_role` is unreadable or unsupported. | Failure |
| Gateway unavailable | A control or app caller has no configured gateway or cannot reach the gateway API. | Failure |

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
probe, drift, fix, and adopt contract.

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
