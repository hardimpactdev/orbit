# Technical Contract: `orbit codex:app add|remove|list [project]`

[Back to public `codex:app` documentation.](../codex-app.md)

**Owner:** `codex`.

**Effects:** `read` for `list`; one locked target-side read, merge, atomic
replacement, and apply action for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The selected target node is active, visible to the caller, not the gateway,
  and its platform resolves to macOS for the `codex-app` tool.
- `add` and `remove` require the authenticated peer to have `codex:app` on both
  the selected Orbit instance's serving node and the selected Codex App
  target node.
- `list` requires `codex:app` on the selected Codex App target node.

## Signature

```bash
orbit codex:app <action> [project] --node=<node> [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be `add`, `remove`, or `list`. |
| `project` | `[project]` | `add`, `remove`. | `list`. | None. | Existing project name or hostname whose source can be registered in Codex App. |
| `node` | `--node` | Always. | Never. | None. | Active visible non-gateway node whose platform resolves to macOS for `codex-app`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/codex/apps/{project}` | `codex:app` on instance serving node and target node | Add or update the instance project entry. |
| `DELETE` | `/api/codex/apps/{project}` | `codex:app` on instance serving node and target node | Remove the instance project entry. |
| `GET` | `/api/codex/projects` | `codex:app` on target node | List target-node Codex App projects. |

## Behavior Contract

### Target Rules

- Resolve `--node` as an explicit tool target, not as an app owner.
- Resolve `add` and `remove` source context from the concrete Orbit app
  instance before authorization or config reads. Its driver config supplies the
  serving node and source path; project records supply neither.
- Reject a bare project with `validation_failed`,
  `error.meta.field=instance`, and `error.meta.reason=instance_required`.
- Reject a selected external-driver instance with `validation_failed`,
  `error.meta.field=instance`, `error.meta.reason=unsupported`, and its existing
  `driver` value. Do not infer an Orbit node or path for it.
- Reject gateway nodes for every action.
- Reject inactive or hidden nodes.
- Reject nodes whose `nodes.platform` does not resolve to macOS for the
  `codex-app` tool definition.
- Use the selected Orbit instance serving-node name as the Codex SSH alias and
  that instance's path as `remotePath`. The gateway still reaches the selected
  Codex App target node over that target node's WireGuard address when listing
  or mutating the config file.

## Context Contracts

- [Client context](2_codex-app_on-client.md)

## Input Mode Contracts

- [Interactive input mode](5.1_codex-app_input-mode_interactive.md)
- [Non-interactive input mode](5.2_codex-app_input-mode_non-interactive.md)

### Config Rules

- Read and write only `~/.codex/codex-app/config.json` on the target node.
- Preserve unrelated config keys and unrelated project entries.
- Store app projects under Codex App's remote SSH shape:

```json
{
  "version": 1,
  "remoteConnections": [
    {
      "sshAlias": "app-dev-1",
      "projects": [
        {
          "remotePath": "/home/orbit/apps/docs",
          "label": "docs.development"
        }
      ]
    }
  ]
}
```

- Malformed JSON fails before writing and preserves the original file.
- Add is idempotent: an existing app project entry is updated in place.
- Remove is idempotent: a missing app project entry returns success with
  `removed=false`.
- For add and remove, send one target-side mutation action. Acquire an exclusive
  sibling lock before reading, then keep the lock while merging, replacing, and
  applying the config.
- Keep the lock file at `~/.codex/codex-app/config.json.lock`. Release its lock
  and close its handle after every success or failure. Do not remove the lock
  file because a replacement lock inode would not serialize existing waiters.
- Replace a changed config through a mode `0600` temporary file in the same
  directory and an atomic rename. Remove the temporary file after any failed
  write or rename. A failure must preserve the current committed config and
  must not expose a partial file.
- Do not replace the config when the merged value is unchanged. Preserve its
  bytes and inode, then apply it as usual.
- Apply `codex://codex-app/apply-config` before releasing the mutation lock.
- If the apply callback fails after a coherent config is committed, release the
  lock and return success with `app_codex.apply_failed` in
  `success.meta.warnings[]`.

### Scope Boundaries

`codex:app` must not:

- Write app runtime files.
- Register workspaces or Codex-managed worktrees.
- Create node roles, node grants, SSH keys, host keys, or WireGuard identity
  material.

## Renderer Contracts

- [Human renderer](6.1_codex-app_output-render_human.md)
- [JSON renderer](6.2_codex-app_output-render_json.md)

## Failure Semantics

Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Unsupported action | `action` is not `add`, `remove`, or `list`. | `error.code=validation_failed`; `error.meta.field=action` |
| Instance required | `add` or `remove` receives a bare project selector. | `error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=instance_required` |
| External instance unsupported | The selected instance driver is not `orbit` and has no Orbit serving node or source path. | `error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=unsupported`; `error.meta.driver=<driver>` |
| Missing node | `--node` is absent. | `error.code=validation_failed`; `error.meta.field=node` |
| Gateway target | The selected target node is the gateway. | `error.code=validation_failed`; `error.meta.field=node`; `error.meta.reason=gateway_not_tool_eligible` |
| Unsupported node OS | The selected node platform does not resolve to macOS for `codex-app`. | `error.code=tool.unsupported_on_node` |
| Config lock failed | The target node lock file could not be opened, acquired, or released. | `error.code=app_codex.config_lock_failed` |
| Config read failed | The target node config file could not be read. | `error.code=app_codex.config_read_failed` |
| Config write failed | The target node config file could not be written or atomically replaced. | `error.code=app_codex.config_write_failed` |

## Doctor Relationship

`codex:app` is a direct project-to-Codex-App configuration bridge. It is not
currently restored by [`doctor --family=instance`](../../../5_app/instance-doctor.md); later drift
automation must use the same source-agnostic config services.

## Activity Logging

| Field | Value |
| --- | --- |
| Type | `api:GET /codex/projects` for `list`; `api:POST /codex/apps/{project}` for `add`; `api:DELETE /codex/apps/{project}` for `remove`. |
| Effect | `read` for `list`; `write` for `add` and `remove`. |
| Subject | The selected target `Node` for `list`; the resolved Orbit `App` for `add` and `remove`; `none` when resolution fails. |
| Properties | `action` and `target_node`; never project paths, raw config, or credentials. |
| Description | derived |

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Codex/CodexAppCommandTest.php` | CLI validation, request routing, JSON pass-through, and human progress output. |
| `apps/cli/tests/Feature/InternalCodexAppConfigCommandTest.php` | Target-side locking, concurrent add/remove, unchanged config, safe write and replacement failures, apply warnings, temporary-file cleanup, and lock release. |
| `apps/gateway/tests/Feature/Http/Api/CodexAppControllerTest.php` | Gateway authorization, target eligibility, one-action add/remove dispatch, target failure mapping, warning payloads in `success.meta.warnings[]`, and response shapes. |
| `apps/gateway/tests/Unit/Services/CodexApp/CodexAppConfigMergerTest.php` | Preserves unrelated config keys, creates missing project arrays, updates duplicate project entries in place, and removes only matching entries. |
