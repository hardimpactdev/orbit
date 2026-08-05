# Technical Contract: `orbit workspace:show [name]`

[Back to public `workspace:show` documentation.](../workspace-show.md)

**Owner:** `workspace`.

**Effects:** `read`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target workspace is visible to the current node identity through
  gateway-owned access policy.

**Post-input path eligibility:**
- The resolved workspace must match an existing workspace record visible to
  the caller.
- The resolved workspace's parent project must be visible to the caller.

## Signature

```bash
orbit workspace:show [name] [--instance=<app.instance>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `name` | `[name]` | Never; resolvable through defaults or prompt. | Never. | Current workspace if the CWD is inside a known workspace path; otherwise prompt or fail (see below). | Must match an existing workspace record visible to the caller. |
| `instance` | `--instance` | When the resolved `name` matches more than one workspace record. | Never. | Parent project and required selected instance of the uniquely resolved workspace. | Must match a visible app or instance selector. Dot notation such as `happie.nmbp` selects one concrete instance. |
| `json` | `--json` | Optional. | Never. | `false`. | Selects the JSON renderer and non-interactive input mode according to the shared invocation model in [`docs/domains/README.md`](../../../README.md#invocation-model). |

Workspace slugs are unique within an app but not globally unique. Two apps may
each own a workspace with the same `name`, so `--instance` is the disambiguating
coordinate of the `(app, workspace)` identity rather than a redundant flag.
A bare project slug must resolve to exactly one concrete instance or fail with
`error.meta.reason=instance_required`.
When `--instance` includes an instance selector, the resolved workspace must
belong to that concrete instance.

## Input Resolution

1. **Resolve `name`** from `[name]` or current working directory.
   - Interactive mode prompts if CWD resolution fails. See
     [`5.1_workspace-show_input-mode_interactive.md`](5.1_workspace-show_input-mode_interactive.md).
   - Non-interactive mode fails if CWD resolution fails. See
     [`5.2_workspace-show_input-mode_non-interactive.md`](5.2_workspace-show_input-mode_non-interactive.md).
2. **Handle ambiguity.**
   - If multiple workspaces match `name` and `--instance` is missing, interactive
     mode prompts for the parent project. Non-interactive mode fails with
     `error.code=workspace.ambiguous_name`.
3. **Validate result.**
   - Must resolve to exactly one visible workspace record.
   - The caller must be authorized to inspect the workspace through
     gateway-owned access policy.
4. **Select renderer** and begin the read flow.

## Behavior Contract

1. **Registry Lookup.** Read the workspace record and related parent project and
   gateway history from the gateway database.
2. **Authorization Check.** Verify the caller is authorized to inspect the
   target workspace through gateway-owned access policy. If not authorized,
   fail before side effects.
3. **Result Assembly.** Collect the workspace record and the durable gateway
   configuration the workspace owns or inherits:
   - workspace registry: name, parent project, selected instance, branch,
     workspace path, canonical URL;
   - owning node: name and host (from the workspace's effective node);
   - runtime expectations: effective PHP version and inheritance source,
     runtime container, derived hostname;
   - inherited process: instance-owned process definitions inherited by this
     workspace (registry-shaped, not live status);
   - workspace-owned proxy route: host, kind, owner;
   - latest setup run summary: ID, status, completed_at.

   Default `workspace:show` is a registry read, not a live readiness command.

`workspace:show` must not:
- Mutate gateway configuration or node state.
- Fix drift or trigger setup.
- SSH into the owning node directly from the caller.
- Run live runtime container, document-root, route, or process probes.
- Block on slow or unreachable node runtime checks.

### Constraints & Invariants

- **Registry-only**: The command **must not** SSH to nodes, probe
  filesystems, check runtime containers, or verify live proxy routes.
- **No repair**: Does not fix drift or trigger setup.
- **Read-only**: Does not mutate gateway state.

## Renderer Contracts

Output renderer behavior is split out of the canonical command contract:

- [`6.1_workspace-show_output-render_human.md`](6.1_workspace-show_output-render_human.md):
  no-progress-tree decision, registry detail view, prose errors.
- [`6.2_workspace-show_output-render_json.md`](6.2_workspace-show_output-render_json.md):
  JSON envelope, registry-only data shape, error codes, error metadata.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Workspace not found | No visible workspace matches the resolved criteria. | Failure |
| Ambiguous workspace | Multiple workspaces match the name and `--instance` is missing. | Failure |
| Production app unsupported | The selected workspace belongs to an `app-prod` instance. | Failure (`error.code=workspace.unsupported_for_production`) before returning registry data. |
| Not authorized | The caller is not allowed to inspect the target workspace. | Failure |

`workspace:show` exits zero whenever the registry read succeeds. Runtime drift
and unverifiable live checks are not part of this command's default read path.
Operators who need readiness or drift information should run
[`doctor --family=workspace`](../../workspace-doctor.md) for runtime artifact
drift. HTTP probe results from setup time belong to `workspace:setup` command
metadata.

## Doctor Relationship

- `workspace:show` is a registry-backed view. It does not verify live
  readiness.
- [`doctor --family=workspace`](../../workspace-doctor.md) is the convergence
  interface for workspace drift and owns repair behavior.

## Activity Logging

The gateway API endpoint emits an activity entry for successful and failed
workspace detail reads.

| Field | Value |
| --- | --- |
| Type | `api:GET /workspaces/{name-or-path}` |
| Effect | `read` |
| Subject | `Workspace` when the workspace is resolved and visible; `none` for not-found, ambiguous, validation, or authorization failures before a workspace can be logged. |
| Properties | No command-specific properties. The API activity middleware adds transport context such as method, path, client, and serving gateway node. |
| Description | derived |

## Test Mapping

Primary test owners:

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceShowControllerTest.php` | Gateway workspace lookup, path-prefix resolution, canonical entity shape, ambiguity handling, registry_only metadata, and authorization failures. |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceShowCommandTest.php` | CLI show request forwarding, human detail layout, JSON payload passthrough, and not-found error handling. |

Input-mode-specific test mapping lives in:

- [`5.1_workspace-show_input-mode_interactive.md`](5.1_workspace-show_input-mode_interactive.md#test-mapping)
- [`5.2_workspace-show_input-mode_non-interactive.md`](5.2_workspace-show_input-mode_non-interactive.md#test-mapping)

Renderer-specific test mapping lives in:

- [`6.1_workspace-show_output-render_human.md`](6.1_workspace-show_output-render_human.md#test-mapping)
- [`6.2_workspace-show_output-render_json.md`](6.2_workspace-show_output-render_json.md#test-mapping)
