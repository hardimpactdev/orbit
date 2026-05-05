# Technical Contract: `orbit process:list`

[Back to public `process:list` documentation.](../process-list.md)

**Owner:** `process`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The caller role is `control`, `gateway`, or `app`; `unknown` callers are
  denied before prompts or side effects.
- The current node identity is authorized to read process intent for the target
  app or workspace context.

## Signature

```bash
orbit process:list [--app=<app>] [--workspace=<workspace>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `--app` or app context | Required unless `workspace` resolves the app. | Never. | Local app context when exactly one app is resolvable. | Must resolve to an app the caller may read. |
| `workspace` | `--workspace` or workspace context | Optional. | Never. | Local workspace context when exactly one workspace is resolvable. | Must resolve to a workspace of the selected app that the caller may read. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`process:list` follows the
[Process Caller Role Rule](../../README.md#process-caller-role-rule). It is a
read command, so app-node callers are valid when authorized for the resolved app
or workspace context.

| Role | Validity | Consequence |
| --- | --- | --- |
| `control` | `valid` | Read gateway-owned process intent through the gateway API when authorized. |
| `gateway` | `valid` | Read gateway-owned process intent locally when authorized. |
| `app` | `valid` | Resolve local app or workspace context when available, then call the gateway API. The app-node CLI does not probe the runtime backend or read node-local runtime state. |
| `unknown` | `invalid` | Deny before prompts or side effects with `error.code=caller_role_not_allowed`. |

## Input Mode Contracts

- [Interactive input mode](5.1_process-list_input-mode_interactive.md)
- [Non-interactive input mode](5.2_process-list_input-mode_non-interactive.md)

## Behavior Contract

### Process Listing Rules

1. Resolve caller role. Deny `unknown` callers before prompts or side effects.
2. Resolve caller authorization and app or workspace context.
3. Read app-owned process definitions from gateway intent in process order.
4. Derive expected runtime-unit identities for the selected main app or
   workspace context.
5. Read latest durable lifecycle events for the selected runtime context when
   events exist.
6. Render the selected output.

`process:list` must not SSH to app nodes, run live runtime backend probes, mutate
gateway intent, or change runtime state.

## Renderer Contracts

- [Human renderer](6.1_process-list_output-render_human.md)
- [JSON renderer](6.2_process-list_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Caller role not allowed | The caller role is `unknown`. | Failure (`error.code=caller_role_not_allowed`). |
| Validation failed | App or workspace context is missing, invalid, or ambiguous in non-interactive input mode. | Failure (`error.code=validation_failed`). |
| Authorization failed | The caller cannot read process intent for the target context. | Failure (`error.code=authorization_failed`). |
| Gateway unavailable | The CLI cannot reach the gateway API. | Failure (`error.code=gateway_unavailable`). |

Owning app-node reachability is not part of the default list path and does not
cause this command to fail.

## Doctor Relationship

`process:list` reports process intent and latest durable lifecycle events.
[`process-doctor.md`](../../process-doctor.md) owns live runtime-unit artifact
verification and repair.

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Commands/Processes/ProcessListCommandTest.php` | Command contract for app context resolution, workspace context resolution, app-node caller allowance through the gateway, unknown-role denial before prompts or side effects, registry-backed process listing in process order, latest durable event display, no live node probing, authorization failure, and gateway-unavailable failure. |
| `tests/Feature/Commands/Processes/ProcessListInputContractTest.php` | App and workspace input resolution, missing context failures, ambiguous context failures, and `--json` input-mode selection. |

Renderer and input-mode test mapping lives in the split companion files.
