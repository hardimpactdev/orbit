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
| `app` | `--instance` | Matching workspace names are ambiguous. | None. | App or concrete instance selector. |
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

The workspace routes accept optional `app` and `instance` query parameters for
disambiguation. An `instance` query parameter is valid only when `app` is also
present, so authorization and target selection resolve the same concrete app
instance.

## Behavior Contract

### Ownership and persistence

1. Env intent belongs to one concrete workspace.
2. Explicit secret values are rejected.
3. Explicit values are not classified or rejected as production-like.
4. `set` stores intent without node mutation.

### Apply boundary

5. `set --apply` reads and writes only `<workspace path>/.env`, clears Laravel
   caches at the workspace path, and reapplies only the workspace runtime.
6. Parent app paths, sibling workspace paths, and remote unrelated nodes are
   outside the side-effect boundary.

### Result metadata

7. Every success response includes `scope=workspace`, `app`, `instance`,
   `workspace`, `path`, `stored`, `applied`, and `runtime_restarted`.

## Failure Semantics

| Failure | Condition | Outcome |
| --- | --- | --- |
| Workspace not found | No visible workspace matches. | `error.code=workspace.not_found`. |
| Workspace ambiguous | Name matches more than one visible target. | `error.code=validation_failed`, `error.meta.field=workspace`. |
| Instance without app | The raw API supplies `instance` without `app`. | `error.code=validation_failed`, `error.meta.field=instance`. |
| Production app unsupported | The selected workspace belongs to an `app-prod` instance. | `error.code=workspace.unsupported_for_production` before storage, file, cache, or runtime effects. |
| Runtime apply failed | Gateway state saved but workspace file/cache/runtime application failed. | `error.code=workspace.env_apply_failed`. |

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
