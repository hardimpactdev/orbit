# Technical Contract: `orbit app:agent-ide [app] [agent_ide]`

[Back to public `app:agent-ide` documentation.](../app-agent-ide.md)

**Owner:** `app`.

**Effects:** `write`, `destructive` (during adapter switch).

**Prerequisites:**
- The CLI caller role is `control` or `gateway`. App-node callers are denied
  before prompts or side effects.
- The current node identity is authorized to manage the app.
- The target app exists in gateway intent.
- The adapter appears in the gateway-owned adapter registry. Core adapter names
  are `opencode` and `polyscope`; additional adapters are registered by
  installed Orbit extensions through the gateway-side extension registration
  surface. `inherit` and `none` are reserved input values handled by
  `app:agent-ide` itself, not adapters in the registry.
- Destructive switches require explicit consent (`--force` or interactive
  confirmation).

## Signature

```bash
orbit app:agent-ide [app] [agent_ide] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `app` | `[app]` | Always. | Never. | None. | Must resolve to an existing app record by name or hostname. Name match wins; the hostname match is consulted only when no name match exists. |
| `agent_ide` | `[agent_ide]` | Always. | Never. | None. | Must be `inherit`, `none`, or appear in the gateway-owned adapter registry. Core adapter names: `opencode`, `polyscope`. Adapters supplied by installed Orbit extensions are accepted only after the extension has registered them with the gateway. |
| `force` | `--force` | Optional. | Never. | `false`. | Skips destructive consent prompt. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Caller Role Behavior

`app:agent-ide` is an app-level preference write that may remove stale
workspaces during adapter switches. It is not executable from app nodes. App
nodes remain restricted to read paths and explicitly documented local workflow
exceptions such as `workspace:setup`.

| Caller role | Behavior |
| --- | --- |
| `control` | Forwards the request to the gateway over HTTPS through WireGuard when configured and authorized. |
| `gateway` | Executes the preference write and any workspace cleanup locally on the gateway when authorized. |
| `app` | Denied before prompts or side effects. |
| `unknown` | Invalid local context. Fail before prompts or side effects. |

## Input Resolution

1. Resolve caller role. Deny `app` and `unknown` before prompts or side effects.
2. Resolve `app` from `[app]`.
3. Validate `app` immediately. Must exist in gateway intent. The lookup checks
   app name first; the hostname match is consulted only when no name match
   exists.
4. Resolve `agent_ide` from `[agent_ide]`. The prompt
     presents `inherit`, `none`, and every adapter registered with the
     gateway-owned adapter registry. Gateway callers read the registry
     locally; configured control callers query the gateway over HTTPS through
     WireGuard before rendering the prompt.
5. Validate `agent_ide` immediately.
   - Must be `inherit`, `none`, or appear in the gateway-owned adapter registry.
   - Validation is synchronous. The CLI caller does not consult a local
     adapter manifest, scan installed extensions on the control machine, or
     fall back to a hard-coded core list. Trust-on-first-set with later
     doctor adoption is rejected; unsupported adapters fail at command time
     with `app.unsupported_adapter`.
6. Identify potential destructive side effects.
   - If `agent_ide` differs from the current effective adapter, check the
     previous adapter for app workspaces that no longer exist for the app.
   - If workspaces would be removed, require destructive consent.
7. Select the output renderer and begin the side-effect flow.

## Input Mode Contracts

- [Interactive input mode](5.1_app-agent-ide_input-mode_interactive.md)
- [Non-interactive input mode](5.2_app-agent-ide_input-mode_non-interactive.md)

## Behavior Contract

### App Agent IDE Default Rules

1. **Lookup.** Find the app record by name or hostname. The lookup checks app
   name first; the hostname match is consulted only when no name match
   exists. If not found, fail before side effects.
2. **Adapter validation.** Validate the requested adapter against the
   gateway-owned adapter registry. The gateway is the sole authority. If the
   adapter is not registered (and is not the reserved `inherit` or `none`
   value), fail before side effects with `app.unsupported_adapter`.
   Configured control callers use the typed gateway adapter choices request for
   prompt choices and still send the write request to the gateway for final
   authorization and validation. The control caller must not validate against a
   local hard-coded adapter list.
3. **Idempotence check.** Compare the requested adapter against the current
   app default.
   - If they match, return success with `action: "converged"`.
   - If they differ, continue.
4. **Workspace Cleanup Planning (Destructive).**
   - Identify the previous effective adapter.
   - Identify workspaces that exist for the app under the previous adapter
     but not under the new adapter.
   - If workspaces are identified, require destructive consent (`--force` or
     interactive confirmation) before writing app intent.
5. **Write intent.** Store the adapter as the app-level default in gateway
   app intent.
   - If `agent_ide` is `inherit`, clear the app override. `agent_ide.adapter`
     becomes `null` and `agent_ide.source` becomes `"node"` when the owning
     node has a default, or `"default"` when the chain resolves to no
     adapter.
   - If `agent_ide` is `none`, store the explicit "no adapter" override.
     `agent_ide.adapter` becomes `"none"` and `agent_ide.source` becomes
     `"app"`. `agent_ide.effective_adapter` is `null`.
   - If `agent_ide` is a non-`none` adapter, store the adapter name.
     `agent_ide.adapter` becomes the adapter name, `agent_ide.source`
     becomes `"app"`, and `agent_ide.effective_adapter` is the same value.
6. **Cleanup execution.** Remove identified stale workspaces through normal
   `app:prune` / `workspace:remove` semantics after the app intent write.
   Cleanup failures after the app intent write are non-fatal: the command
   returns success and reports structured warnings under
   `success.meta.warnings[]` using the same warning vocabulary as
   `app:prune` and `workspace:remove`.
7. **Report.** Return the app name, the resulting adapter configuration
   (`adapter`, `source`, `effective_adapter`), the command `action`
   (`set` when the value changed, `converged` when it already matched), and
   any cleanup results.

`app:agent-ide` is an intent write with the single explicit destructive side
effect of removing app-owned workspaces under the previous adapter when the
adapter changes. The app intent write is not rolled back if post-write cleanup
cannot finish; cleanup drift is reported as success with warnings and repaired
by the same `app:prune`, `workspace:remove`, and doctor paths used elsewhere.
This cleanup is deliberately app-scoped. `node:agent-ide` may change the
inherited effective adapter for apps on a node, but it does not prune
workspaces; callers that want cleanup after changing a node default run
`app:prune` for each affected app. Beyond app-scoped cleanup, downstream
consumers resolve their effective agent IDE per-event using the current
inheritance chain (`app → node → none`); the blueprint reserves a future
workspace-level override slot above app scope. The writer does not push a
notification to consumers. A change to the app default is naturally picked up
at the next consumer-side resolution event.

### Scope Boundaries

`app:agent-ide` must not:
- Create workspaces.
- Create an agent IDE session.
- SSH into the target node (except for workspace removal).
- Trigger downstream session restart or process invalidation.
- Notify running agent-IDE sessions, restart processes on the node,
  invalidate cached workspace-level overrides, or emit
  `success.meta.warnings[]` for "downstream consumers still using the old
  adapter".
- Partially mutate workspace state outside the documented cleanup step.

## Renderer Contracts

- [Human renderer](6.1_app-agent-ide_output-render_human.md)
- [JSON renderer](6.2_app-agent-ide_output-render_json.md)

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| App not found | No app record matches `app`. | Failure |
| Caller role not allowed | The caller role is `app` or `unknown`. | Failure |
| Unsupported adapter | The requested adapter is not present in the gateway-owned adapter registry. | Failure |
| Missing destructive consent | Workspaces would be removed but `--force` is missing in non-interactive mode or confirmation is denied in interactive mode. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Gateway unavailable | A configured control caller cannot reach the gateway during validation or choices gathering. | Failure |
| Cleanup failed after intent write | App intent was updated but workspace removal could not finish. | Success with structured `success.meta.warnings[]`. |

No-op sets (already matching) are successful with `action: "converged"`, not
failure.

## Doctor Relationship

- `doctor --family=app --app=<app>` verifies app-owned agent IDE configuration.
  See [`app-doctor.md`](../../app-doctor.md).
- Cross-reference `app-doctor.md` for `doctor --family=app --app=<app>`
  agent-IDE drift checks instead of redefining doctor semantics here.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
agent-IDE override attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{app}/agent-ide` |
| Effect | `write` |
| Subject | `App` when the app is resolved and visible; `none` for not-found, caller-role, authorization, or adapter validation failures before the target app can be logged. |
| Properties | `target_app` (string), `agent_ide` (string or null effective adapter after the write), and `action` (`set`, `cleared`, `converged`, or null before a write completes). No adapter credentials, workspace paths, raw cleanup output, or secrets. |
| Description | derived, for example `"App docs agent IDE set to opencode"` or `"App docs agent IDE already set to opencode"`. |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `tests/Feature/Actions/Apps/ConfigureAppAgentIdeTest.php` | Action contract: setting/clearing adapter, `inherit` semantics, adapter validation, and workspace cleanup side effects. |
| `tests/Feature/Commands/Apps/AppAgentIdeCommandTest.php` | Command contract: signature, input resolution, destructive consent logic, success/failure reporting, JSON alignment, and warning payload shape for `success.meta.warnings[]`. |
| `tests/Feature/Commands/Apps/AppAgentIdeCallerRoleTest.php` | Control and gateway caller allowance when authorized, app-node caller denial before prompts or side effects, unknown-role failure, and forwarded control caller authorization failure. |

Input-mode-specific test mapping lives in:

- [`5.1_app-agent-ide_input-mode_interactive.md`](5.1_app-agent-ide_input-mode_interactive.md#test-mapping)
- [`5.2_app-agent-ide_input-mode_non-interactive.md`](5.2_app-agent-ide_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_app-agent-ide_output-render_human.md`](6.1_app-agent-ide_output-render_human.md#test-mapping)
- [`6.2_app-agent-ide_output-render_json.md`](6.2_app-agent-ide_output-render_json.md#test-mapping)
