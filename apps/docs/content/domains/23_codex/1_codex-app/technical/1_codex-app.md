# Technical Contract: `orbit codex:app add|remove|list [app]`

[Back to public `codex:app` documentation.](../codex-app.md)

**Owner:** `codex`.

**Effects:** `read` for `list`; `write, remote-apply` for `add` and `remove`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The selected target node is active, visible to the caller, not the gateway,
  and its platform resolves to macOS for the `codex-app` tool.
- `add` and `remove` require the authenticated peer to have `codex:app` on both
  the app's owning node and the selected Codex App target node.
- `list` requires `codex:app` on the selected Codex App target node.

## Signature

```bash
orbit codex:app <action> [app] --node=<node> [--node-transport=<transport>] [--json]
```

## Input Contract

This command follows the shared
[Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `action` | `{action}` | Always. | Never. | None. | Must be `add`, `remove`, or `list`. |
| `app` | `[app]` | `add`, `remove`. | `list`. | None. | Existing app selector. |
| `node` | `--node` | Always. | Never. | None. | Active visible non-gateway node whose platform resolves to macOS for `codex-app`. |
| `node_transport` | `--node-transport` | Optional. | Never. | `auto`. | One of `auto`, `agent-push`, or `transitional-ssh-fallback`. |
| `json` | `--json` | Optional. | Never. | `false` | Selects the JSON renderer and non-interactive input mode. |

## API Surface

| Method | Path | Permission | Action |
| --- | --- | --- | --- |
| `POST` | `/api/codex/apps/{app}` | `codex:app` on app node and target node | Add or update the app project entry. |
| `DELETE` | `/api/codex/apps/{app}` | `codex:app` on app node and target node | Remove the app project entry. |
| `GET` | `/api/codex/projects` | `codex:app` on target node | List target-node Codex App projects. |

## Behavior Contract

### Target Rules

- Resolve `--node` as an explicit tool target, not as an app owner.
- Reject gateway nodes for every action.
- Reject inactive or hidden nodes.
- Reject nodes whose `nodes.platform` does not resolve to macOS for the
  `codex-app` tool definition.
- Use the app-owning node name as the first Codex SSH alias. The gateway still
  reaches the selected Codex App target node over that target node's
  WireGuard address when reading and writing the config file.

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
      "sshAlias": "app-node",
      "projects": [
        {
          "remotePath": "/home/orbit/apps/docs",
          "label": "docs"
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
- Apply `codex://codex-app/apply-config` after a successful add or remove
  write.
- If the apply callback fails after the config file is written, return success
  with `codex_app.apply_failed` in `success.meta.warnings[]`.

### Scope Boundaries

`codex:app` must not:

- Write app runtime files.
- Change `app:agent-ide`.
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
| Missing node | `--node` is absent. | `error.code=validation_failed`; `error.meta.field=node` |
| Gateway target | The selected target node is the gateway. | `error.code=validation_failed`; `error.meta.field=node`; `error.meta.reason=gateway_not_tool_eligible` |
| Unsupported node OS | The selected node platform does not resolve to macOS for `codex-app`. | `error.code=tool.unsupported_on_node` |
| Config read failed | The target node config file could not be read. | `error.code=codex_app.config_read_failed` |
| Config write failed | The target node config file could not be written. | `error.code=codex_app.config_write_failed` |

## Doctor Relationship

`codex:app` is a direct app-to-Codex-App configuration bridge. It is not
currently restored by [`doctor --family=app`](../../../5_app/app-doctor.md); later drift
automation must use the same source-agnostic config services.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Codex/CodexAppCommandTest.php` | CLI validation, request routing, JSON pass-through, and human progress output. |
| `apps/gateway/tests/Feature/Http/Api/CodexAppControllerTest.php` | Gateway authorization, target eligibility, config read/write/apply behavior, warning payloads in `success.meta.warnings[]`, and response shapes. |
| `apps/gateway/tests/Unit/Services/CodexApp/CodexAppConfigMergerTest.php` | Preserves unrelated config keys, creates missing project arrays, updates duplicate project entries in place, and removes only matching entries. |
