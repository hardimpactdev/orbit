# Technical Contract: `orbit workspace:env list|set|render [name]`

[Back to public `workspace:env` documentation.](../workspace-env.md)

**Owner:** `workspace`.

**Effects:** `read` for `list` and `render`; `write` for `set`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The target workspace exists and belongs to one concrete instance.
- The authenticated peer has `workspace:read` or `workspace:write` on the
  workspace's owning node for the selected action.

## Signature

```bash
orbit workspace:env [action] [name] [--instance=<project.instance>] [--key=<KEY>] [--value=<value>] [--apply] [--secret] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Default | Validation |
| --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | None. | `list`, `set`, or `render`. |
| `name` | `[name]` | CWD does not resolve one registered workspace. | Registered workspace containing `ORBIT_HOST_CWD`. | Workspace identity slug. |
| `instance` | `--instance` | Matching workspace names are ambiguous. | None. | Project or concrete instance selector. |
| `key` | `--key` | `set`. | None. | Uppercase env key pattern. |
| `value` | `--value` | `set`. | None. | Any non-secret string, including production-like values. |
| `apply` | `--apply` | Optional for `set`. | `false`. | Rejected outside `set`. |
| `secret` | `--secret` | Never in this slice. | `false`. | Rejected. |
| `json` | `--json` | Optional. | `false`. | Selects JSON output. |

## State Model

Gateway-owned `workspace_env_variables` rows belong to one workspace and are
unique by `(workspace_id, key)`. Effective env merges Orbit defaults, explicit
workspace values, and database targets attached to that workspace. Secret
database values are redacted from responses.

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `GET` | `/api/workspaces/{workspace}/env` | `workspace:read` | List explicit values. |
| `POST` | `/api/workspaces/{workspace}/env` | `workspace:write` | Set one value; optional `apply=true`. |
| `GET` | `/api/workspaces/{workspace}/env/render` | `workspace:read` | Render the effective map. |
| `GET` | `/api/workspaces/env/resolve-by-path?path=<absolute>` | `workspace:read` | Resolve the workspace containing caller CWD. |

The workspace routes accept an optional `instance` query parameter for
disambiguation. It accepts `project.instance`, or a bare project only when that
project resolves to one concrete instance.

## Behavior Contract

### Ownership and persistence

1. Env intent belongs to one concrete workspace.
2. Explicit secret values are rejected.
3. Explicit values are not classified or rejected as production-like.
4. `set` stores intent without node mutation.

### Apply boundary

5. `set --apply` derives the env path only from the resolved registered workspace
   record (`<workspace path>/.env`). Clients cannot supply an alternate apply
   path. Orbit reads and writes only that path, clears Laravel config and deletes
   generated bootstrap cache files at the workspace path as the workspace runtime
   user, and restarts the exact rendered selected workspace runtime when it uses
   PHP—even when the container spec already matches a running container.
6. Parent instance paths, sibling workspace paths, and remote unrelated nodes are
   outside the side-effect boundary. Env publication is atomic (same-directory
   stage, lock, mode-preserving chmod, revalidate, rename) and preserves
   unrelated variables via the env editor. Missing remote env files are treated
   as empty; validation, read, and transport failures do not collapse into empty
   contents.

### Result metadata

7. Every success response includes `scope=workspace`, `project`, `instance`,
   `workspace`, `path`, `stored`, `applied`, and `runtime_restarted`. Successful
   apply payloads also expose explicit `env_written` and `runtime_restarted`
   facts (including `runtime_outcome=restarted` when a matching running container
   is restarted). Repeating `--apply` may restart the runtime again while leaving
   file contents, mode, and unrelated keys stable.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Workspace not found | No visible workspace matches. | `error.code=workspace.not_found`. |
| Workspace ambiguous | Name matches more than one visible target. | `error.code=validation_failed`, `error.meta.field=workspace`. |
| Instance ambiguous | A bare project selector resolves to more than one instance. | `error.code=validation_failed`, `error.meta.field=instance`, `error.meta.reason=instance_required`. |
| Production instance unsupported | The selected workspace belongs to an `app-prod` instance. | `error.code=workspace.unsupported_for_production` before storage, file, cache, or runtime effects. |
| Env file write failed after storage | Gateway registry saved the key but the workspace `.env` was not written. | `error.code=workspace.env_apply_failed`, `error.meta.phase=env_write`, `stored=true`, `env_written=false`, `runtime_restarted=false`. |
| Runtime apply failed after write | Workspace `.env` was written but cache clear or runtime restart failed. | `error.code=workspace.env_apply_failed`, `error.meta.phase=runtime`, `stored=true`, `env_written=true`, `runtime_restarted=false`. |

## Doctor Relationship

`workspace:env` writes workspace configuration and may apply its `.env`
representation immediately. Workspace runtime drift remains visible through
[`doctor --family=workspace`](../../workspace-doctor.md), whose family contract
is defined in [`workspace-doctor.md`](../../workspace-doctor.md). Database
connection env drift remains owned by `doctor --family=database_connection`.

## Renderer Contracts

- [Human renderer](6.1_workspace-env_output-render_human.md)
- [JSON renderer](6.2_workspace-env_output-render_json.md)

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Workspace/WorkspaceEnvCommandTest.php` | CLI validation, CWD resolution, forwarding, and output. |
| `apps/gateway/tests/Feature/Http/Api/WorkspaceEnvControllerTest.php` | Persistence, rendering, authorization, disambiguation, and apply isolation. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceEnvApplierTest.php` | Workspace-only file writes, cache clearing, and runtime reapply. |
| `apps/gateway/tests/Unit/Services/Workspaces/WorkspaceEnvInitializerTest.php` | Existing-file preservation and `.env.example` initialization. |
