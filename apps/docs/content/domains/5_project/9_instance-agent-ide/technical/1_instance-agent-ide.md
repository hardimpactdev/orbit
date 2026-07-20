# Technical Contract: `orbit instance:agent-ide [project.instance] [agent_ide]`

[Back to public `instance:agent-ide` documentation.](../instance-agent-ide.md)

**Owner:** `instance`.

**Effects:** `write`, `destructive` (during adapter switch).

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The authenticated peer has `instance:agent` on the selected instance's serving node.
- The authenticated peer is not an `app-prod` node. This command can plan
  workspace cleanup and is therefore unavailable to production app services.
- The target instance exists in gateway configuration.
- The adapter appears in the gateway-owned adapter registry. Core adapter names
  are `opencode` and `polyscope`; additional adapters are registered by
  installed Orbit extensions through the extension registration surface on the gateway. `inherit` and `none` are reserved input values handled by
  `instance:agent-ide` itself, not adapters in the registry.
- Destructive switches require explicit consent (`--force` or interactive
  confirmation).

## Signature

```bash
orbit instance:agent-ide [instance] [agent_ide] [--force] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `instance` | `[instance]` | Always. | Never. | None. | Dotted instance selector. Bare logical shorthand succeeds only for exactly one eligible visible instance; hostnames are invalid. |
| `agent_ide` | `[agent_ide]` | Always. | Never. | None. | Must be `inherit`, `none`, or appear in the gateway-owned adapter registry. Core adapter names: `opencode`, `polyscope`. Adapters supplied by installed Orbit extensions are accepted only after the extension has registered them with the gateway. |
| `force` | `--force` | Optional. | Never. | `false`. | Skips destructive consent prompt. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode. |

## Input Resolution

1. Resolve `app` from `[instance]`.
2. Validate `app` immediately. A dotted selector must exist; an ambiguous bare
   logical slug fails with `validation_failed`,
   `meta.reason=instance_required`. Authorize `instance:agent` on the selected
   instance's serving node.
3. Resolve `agent_ide` from `[agent_ide]`. The prompt presents `inherit`,
   `none`, and every adapter registered with the gateway-owned adapter
   registry. The CLI queries the gateway for choices before rendering the
   prompt; it never consults a local adapter manifest.
4. Validate `agent_ide` immediately.
   - Must be `inherit`, `none`, or appear in the gateway-owned adapter registry.
   - Validation is synchronous. The CLI does not consult a local adapter
     manifest, scan installed extensions on the CLI host, or fall back to a
     hard-coded core list. Trust-on-first-set with later doctor adoption is
     rejected; unsupported adapters fail at command time with
     `instance.unsupported_adapter`.
5. Identify potential destructive side effects.
   - If `agent_ide` differs from the current effective adapter, check the
     previous adapter for selected-instance workspaces absent under the new adapter.
   - If workspaces would be removed, require destructive consent.
6. Select the output renderer and begin the side-effect flow.

## Input Mode Contracts

- [Interactive input mode](5.1_instance-agent-ide_input-mode_interactive.md)
- [Non-interactive input mode](5.2_instance-agent-ide_input-mode_non-interactive.md)

## Behavior Contract

### App Instance Agent IDE Rules

An `app-prod` caller is rejected with
`workspace.unsupported_for_production` before adapter validation,
configuration writes, or workspace cleanup planning. On an `app-prod` target,
the adapter setting applies only to the app main context; workspace discovery
and cleanup are disabled.

1. **Lookup.** Resolve one concrete instance. If not found or a bare slug is
   ambiguous, fail before side effects.
2. **Adapter validation.** Validate the requested adapter against the
   gateway-owned adapter registry. The gateway is the sole authority. If the
   adapter is not registered (and is not the reserved `inherit` or `none`
   value), fail before side effects with `instance.unsupported_adapter`.
   The CLI uses the typed gateway adapter choices request for prompt choices
   and still sends the write request to the gateway for final authorization
   and validation. The CLI must not validate against a local hard-coded adapter
   list.
3. **Idempotence check.** Compare the requested adapter against the current
   instance override.
   - If they match, return success with `action: "converged"`.
   - If they differ, continue.
4. **Workspace Cleanup Planning (Destructive).**
   - Identify the previous effective adapter.
   - Identify workspaces that exist for the app under the previous adapter
     but absent under the new adapter, limited to the selected instance.
   - If workspaces are identified, require destructive consent (`--force` or
     interactive confirmation) before writing instance configuration.
5. **Write configuration.** Store the adapter as the instance-level override in gateway
   instance configuration.
   - If `agent_ide` is `inherit`, clear the instance override. `agent_ide.adapter`
     becomes `null` and `agent_ide.source` becomes `"node"` when the serving
     node has a default, or `"default"` when the chain resolves to no
     adapter.
   - If `agent_ide` is `none`, store the explicit "no adapter" override.
     `agent_ide.adapter` becomes `"none"` and `agent_ide.source` becomes
     `"instance"`. `agent_ide.effective_adapter` is `null`.
   - If `agent_ide` is a non-`none` adapter, store the adapter name.
     `agent_ide.adapter` becomes the adapter name, `agent_ide.source`
     becomes `"instance"`, and `agent_ide.effective_adapter` is the same value.
6. **Cleanup execution.** Remove identified stale workspaces through normal
   `instance:prune` / `workspace:remove` semantics after the instance configuration write.
   Cleanup failures after the instance configuration write are non-fatal: the command
   returns success and reports structured warnings under
   `success.meta.warnings[]` using the same warning vocabulary as
   `instance:prune` and `workspace:remove`.
7. **Report.** Return the dotted instance selector, the resulting adapter configuration
   (`adapter`, `source`, `effective_adapter`), the command `action`
   (`set` when the value changed, `converged` when it already matched), and
   any cleanup results.

`instance:agent-ide` is a configuration write with the single explicit destructive side
effect of removing workspaces that belong to the selected instance when the adapter changes. The instance
configuration write is not rolled back if the cleanup step cannot finish after the write; cleanup
drift is reported as success with warnings and repaired by the same `instance:prune`,
`workspace:remove`, and doctor paths used elsewhere.

This cleanup is deliberately instance-scoped. `node:agent-ide` may change the
inherited effective adapter for instances on a node, but it does not prune
workspaces; callers that want cleanup run `instance:prune` for each affected
instance. Downstream consumers resolve through `instance → serving node → none`.

### Scope Boundaries

`instance:agent-ide` must not:
- Create workspaces.
- Create an agent IDE session.
- Open a direct node shell. Workspace removal uses the normal Agent-push lane.
- Trigger downstream session restart or process invalidation.
- Notify running agent-IDE sessions, restart processes on the node,
  invalidate cached workspace-level overrides, or emit
  `success.meta.warnings[]` for "downstream consumers still using the prior
  adapter".
- Partially mutate workspace state outside the documented cleanup step.

## Renderer Contracts

- [Human renderer](6.1_instance-agent-ide_output-render_human.md)
- [JSON renderer](6.2_instance-agent-ide_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Instance required | Bare logical input resolves zero or multiple eligible visible instances. | Failure (`validation_failed`, `meta.reason=instance_required`). |
| Instance not found | No instance matches a dotted selector. | Failure (`instance.not_found`). |
| Production caller unsupported | The authenticated caller has active `app-prod`. | Failure (`error.code=workspace.unsupported_for_production`) before configuration or cleanup. |
| Unsupported adapter | The requested adapter is not present in the gateway-owned adapter registry. | Failure |
| Missing destructive consent | Workspaces would be removed but `--force` is missing in non-interactive mode or confirmation is denied in interactive mode. | Failure (`error.code=validation_failed`, `error.meta.field=force`). |
| Cleanup failed after configuration write | Instance configuration was updated but workspace removal could not finish. | Success with structured `success.meta.warnings[]`. |

No-op sets (already matching) are successful with `action: "converged"`, not
failure.

## Doctor Relationship

- `doctor --family=instance --instance=<project.instance>` verifies the selected instance's agent IDE configuration.
  See [`instance-doctor.md`](../../instance-doctor.md).
- Cross-reference `instance-doctor.md` for drift checks on agent IDE configuration instead of redefining doctor semantics here.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed app
agent-IDE override attempts.

| Field | Value |
| --- | --- |
| Type | `api:POST /apps/{instance}/agent-ide` |
| Effect | `write` |
| Subject | Selected `AppInstance`; `none` before target resolution. |
| Properties | `target_instance` (string), `instance` (string), `serving_node` (string or null), `agent_ide` (string or null effective adapter), and `action` (`set`, `cleared`, `converged`, or null). No adapter credentials, workspace paths, raw cleanup output, or secrets. |
| Description | derived, for example `"Instance docs.development agent IDE set to opencode"`. |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/App/AppWriteCommandTest.php` | CLI validation, force/prompt destructive consent, human/json render variants, and warning payload pass-through. |
| `apps/gateway/tests/Feature/Http/Api/AppAgentIdeControllerTest.php` | Gateway API authorization with `instance:agent`, adapter validation, destructive cleanup consent, cleanup execution, and activity logging. |

Input-mode-specific test mapping lives in:

- [`5.1_instance-agent-ide_input-mode_interactive.md`](5.1_instance-agent-ide_input-mode_interactive.md#test-mapping)
- [`5.2_instance-agent-ide_input-mode_non-interactive.md`](5.2_instance-agent-ide_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_instance-agent-ide_output-render_human.md`](6.1_instance-agent-ide_output-render_human.md#test-mapping)
- [`6.2_instance-agent-ide_output-render_json.md`](6.2_instance-agent-ide_output-render_json.md#test-mapping)
