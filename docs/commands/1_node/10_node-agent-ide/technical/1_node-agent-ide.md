# Technical Contract: `orbit node:agent-ide [name] [agent_ide]`

[Back to public `node:agent-ide` documentation.](../node-agent-ide.md)

**Owner:** `node`.

**Effects:** `write`.

**Prerequisites:**
- The gateway authenticates the CLI and authorizes `node:agent-ide` only when
  the caller's gateway-known role is `control` or `gateway`. App-role callers
  are rejected.
- Gateway callers can read and write gateway-owned node configuration.
- Control callers have configured gateway access.
- The target node exists in gateway node configuration.
- The adapter appears in the gateway-owned adapter registry. Core adapter names
  are `opencode` and `polyscope`; additional adapters are registered by
  installed Orbit extensions through the gateway-side extension registration
  surface. `none` is a reserved node-scope input token that clears the node
  default; it is not an adapter in the registry.

## Signature

```bash
orbit node:agent-ide [name] [agent_ide] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Always. | Never. | None. | Must match an existing active node record. |
| `agent_ide` | `[agent_ide]` | Always. | Never. | None. | Must be `none` or appear in the gateway-owned adapter registry. Core adapter names: `opencode`, `polyscope`. Adapters supplied by installed Orbit extensions are accepted only after the extension has registered them with the gateway. `none` is a reserved node-scope input token that clears the node default; it is not an adapter. `inherit` is invalid at node scope because nodes are the root of the agent IDE inheritance chain. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Authorization By Caller Role

The CLI sends a typed agent-IDE write request to the gateway. The gateway
authenticates the WireGuard peer identity, derives the caller's gateway-known
role, and applies the rules below. Write semantics are identical across valid
roles; gating is access-policy-driven, not role-driven.

| Caller role | Gateway authorizes |
| --- | --- |
| `control` | The agent-IDE write when the caller is authorized to update node registry configuration. |
| `gateway` | The agent-IDE write directly. Gateway-local execution does not require WireGuard forwarding. |
| `app` | Rejected. The gateway returns `caller_role_not_allowed` with message `This command may only be run from a control or gateway node.` |

## Input Resolution

1. Resolve `node_agent_ide.name` from `[name]`.
2. Validate `node_agent_ide.name` immediately.
   - Must match an existing active node record.
3. Resolve `node_agent_ide.adapter` from `[agent_ide]`. The prompt presents
   the reserved `none` token followed by the list of supported adapters from
   the shared Agent IDE adapter registry. Gateway callers read the registry
   locally; control callers query the gateway adapter choices API before
   rendering the prompt.
4. Validate `node_agent_ide.adapter` immediately.
   - Must be `none` or appear in the gateway-owned adapter registry. The gateway is the sole
     authority on which adapter names are accepted; the CLI does not consult a
     local adapter manifest, does not scan installed extensions on the control
     machine, and does not fall back to a hard-coded core list.
   - Validation is synchronous. The CLI forwards the request to the gateway
     and receives the registry decision before any side effect.
     Trust-on-first-set with later doctor adoption is rejected; unsupported
     adapters fail at command time with `node.unsupported_adapter`.
5. Send the typed request to the gateway over HTTPS through WireGuard. The
   gateway authenticates the caller and authorizes the write before any side
   effects. Select the output renderer and render the result.

## Input Mode Contracts

- [Interactive input mode](5.1_node-agent-ide_input-mode_interactive.md)
- [Non-interactive input mode](5.2_node-agent-ide_input-mode_non-interactive.md)

## Behavior Contract

### Adapter Validation Rules

- Find the node record by name. If not found, fail before side effects.
- Validate the requested adapter against the gateway-owned adapter registry.
- The gateway is the sole authority for adapter support.
- The CLI uses the typed gateway adapter choices request for prompt choices
  and still sends the write request to the gateway for final authorization and
  validation. The CLI must not validate against a local hard-coded adapter
  list.
- If the adapter is not registered, fail before side effects with
  `node.unsupported_adapter`.

### Node Default Write Rules

- Compare the requested adapter against the current node default.
- If they match, return success with `action: "converged"`.
- If they differ, store the adapter as the node-level default in gateway node
  configuration.
- If `agent_ide` is `none`, clear the node default. `agent_ide.adapter` becomes
  `null` and `agent_ide.source` becomes `"default"`. This is the node-scope way
  to let app/workspace resolution fall through to no configured agent IDE.
- If `agent_ide` is a non-`none` adapter, set `agent_ide.adapter` to the adapter
  name and `agent_ide.source` to `"node"`.
- Return the node name, the resulting adapter, its source, and the command
  action.

### Scope Boundaries

`node:agent-ide` is a pure configuration write. Apps and current workspaces
resolve their effective agent IDE per-event at consumption time using the
current inheritance chain (`app → node → none`); the architecture reserves a
future workspace-level override slot above app scope. The writer of the node
default
does not push a notification to consumers. A change to the node default is
naturally picked up at the next consumer-side resolution event. Workspace
cleanup is not part of node-default writes: pruning stale workspaces is an
app-scoped operation owned by `app:prune` and the explicit app-level cleanup
path in `app:agent-ide`.

`node:agent-ide` must not:
- Create an agent IDE session.
- Grant node access or alter node transport.
- Override app-level agent IDE settings.
- SSH into the target node.
- Trigger downstream session restart or app-level invalidation.
- Notify running agent-IDE sessions, restart processes on the node, invalidate
  cached app-level or workspace-level overrides, or emit
  `success.meta.warnings[]` for "downstream apps still using the old adapter".
- Remove or prune workspaces for apps that inherit the node default.
- Partially mutate app or workspace state.

## Renderer Contracts

- [Human renderer](6.1_node-agent-ide_output-render_human.md)
- [JSON renderer](6.2_node-agent-ide_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Node not found | No active node record matches `name`. | Failure |
| Unsupported adapter | The requested adapter is not supported. | Failure |
| Caller role not allowed | The caller role is `app`. | Failure |
| Gateway unavailable | A control caller has no configured gateway or cannot reach the gateway API. | Failure |
| Authorization failed | A forwarded control caller is not authorized to update node registry configuration. | Failure |

No-op sets (already matching) are successful with `action: "converged"`, not
failure.

## Activity Logging

Emitted through the cross-cutting Loggable contract. See
[`activity-concepts.md`](../../../17_activity/activity-concepts.md).

| Field | Value |
| --- | --- |
| Type | `api:POST /nodes/{name}/agent-ide` |
| Effect | `write` |
| Subject | The target `Node` model when the node exists; `null` for pre-target failures. |
| Properties | `target_node`, `agent_ide`, and `action`. `agent_ide` is the resulting adapter, or `null` when cleared with `none`. |
| Description | `Node <name> agent IDE set to <adapter>`, `Node <name> agent IDE cleared`, or `Node <name> agent IDE already set to <adapter>`. |

## Doctor Relationship

- `doctor --family=node` verifies node-owned agent IDE default configuration
  when the adapter supports a non-destructive check.
- `node:agent-ide` is the explicit command for setting or clearing the node
  default. `doctor --family=node --restore` does not change the adapter; it only
  verifies that the configured adapter is healthy and reports drift when it is
  not through `node.agent_ide_default_invalid`. Recover by running
  `orbit node:agent-ide <node> <supported-adapter>` or
  `orbit node:agent-ide <node> none`.
- See [`node-doctor.md`](../../node-doctor.md) for the authoritative node-family
  probe, drift, restore, and adopt contract.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Nodes/NodeAgentIdeCommandTest.php` | Command contract: setting an adapter, clearing with `none`, idempotent convergence, unsupported adapter rejection, node-not-found validation, app-node caller denial before prompts or side effects, gateway forwarding for control callers, absence of downstream warning payloads, and read-only guarantees (no SSH, no session creation). |

Input-mode-specific test mapping lives in:

- [`5.1_node-agent-ide_input-mode_interactive.md`](5.1_node-agent-ide_input-mode_interactive.md#test-mapping)
- [`5.2_node-agent-ide_input-mode_non-interactive.md`](5.2_node-agent-ide_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_node-agent-ide_output-render_human.md`](6.1_node-agent-ide_output-render_human.md#test-mapping)
- [`6.2_node-agent-ide_output-render_json.md`](6.2_node-agent-ide_output-render_json.md#test-mapping)
