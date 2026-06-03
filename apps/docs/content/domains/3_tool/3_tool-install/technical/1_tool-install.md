# Technical Contract: `orbit tool:install <tool> [--app=<app>] [--node=<node>] [--instance=<instance>] [--version=<major-or-specific-version>] [--runtime=<docker|docker-swarm>] [--status=<installed|running>] [--json]`

[Back to public `tool-install` documentation.](../tool-install.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:install <tool> [--app=<app>] [--node=<node>] [--instance=<instance>] [--version=<major-or-specific-version>] [--runtime=<docker|docker-swarm>] [--status=<installed|running>] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Supported tool name.` |
| `node` | `--node` | When no `--app`, local `node:default`, or interactive target selection resolves a target. | `Never.` | `node:default` if set; otherwise interactive selection in TTY mode. | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `instance` | `--instance` | When the selected tool definition permits multiple instances and no default instance can be inferred. | `Never.` | `default` for single-instance tools. | Tool instance slug supported by the selected tool definition. |
| `version` | `--version` | In non-interactive mode when the tool definition has multiple supported version families and no unambiguous default. | `Never.` | Tool-defined default version family or interactive prompt selection. | Supported major version family or specific version. A specific version must belong to one supported version family. |
| `runtime` | `--runtime` | In non-interactive mode when the tool definition has multiple platform-supported runtime families and no unambiguous default. | `Never.` | Tool-defined default runtime family or interactive prompt selection. | Runtime family declared by the tool definition and supported by the target node platform. |
| `status` | `--status` | `Optional.` | `Never.` | `installed` | `Expected lifecycle state: installed or running.` |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

`--version` records install-time version intent. Major-only input records the
version family and lets the tool definition choose the latest supported version
inside that family. Specific input records the exact expected version and the
derived version family. `--expected-version` and `expected_version` remain
`tool:update` inputs and are rejected here.

## Behavior Contract

### Tool configuration and apply rules

- Verifies the tool supports managed installation on the target node.
- Resolves the requested or prompted version family, expected version, runtime
  family, and instance id before any gateway row or node artifact is written.
- For role baseline service tools, verifies the target node already has the
  required active role. `tool:install rustfs` requires an active `s3` role and
  reconverges the RustFS role baseline instead of creating a standalone
  object-storage service.
- Requires an explicit target source: `--node`, `--app`, local `node:default`,
  or interactive target selection. Non-interactive mode without a target source
  fails with `validation_failed`.
- Writes or updates gateway tool configuration.
- Generates managed credential material when the tool definition owns
  credentials.
- Creates or updates service endpoint configuration owned by the tool when the tool
  definition declares an endpoint.
- Applies install and configuration through the gateway.
- Starts the tool when expected status is running.
- If remote installation, configuration, or start fails after gateway configuration is
  written, Orbit keeps the expected tool row and reports the node as not yet
  converged; `doctor --family=tool --restore` owns later convergence when the tool
  definition declares a safe repair path.

### Scope Boundaries

`tool-install` must not create apps, workspaces, processes, schedules, custom
proxy routes, non-tool firewall rules, node identities, or node grants.
Tool-owned endpoint configuration is allowed only when declared by the selected tool
definition. Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-install_output-render_human.md)
- [JSON renderer](6.2_tool-install_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Required role missing | The selected role baseline service tool requires an active role that the target node does not have, such as `rustfs` requiring `s3`. | `error.code=validation_failed`; `error.meta.field=node`; `error.meta.required_role=<role>` |
| Unsupported status value | `--status` is not `installed` or `running`. | `error.code=validation_failed`; `error.meta.field=status`; `error.meta.reason=unsupported_value` |
| Missing target source | Non-interactive input provides no `--node`, `--app`, or local `node:default`. | `error.code=validation_failed`; `error.meta.fields=["target"]` |
| Missing version selection | Non-interactive input omits `--version` and the tool definition has multiple supported version families with no unambiguous default. | `error.code=validation_failed`; `error.meta.field=version`; `error.meta.reason=required` |
| Unsupported runtime | `--runtime` names a runtime family the selected tool definition does not declare. | `error.code=tool.runtime_unsupported`; `error.meta.runtime=<runtime>`; `error.meta.tool=<tool>` |
| Unsupported runtime/platform | The selected runtime family is declared by the tool definition but has no implementation for the target node platform, such as `docker-swarm` on macOS. | `error.code=tool.runtime_platform_unsupported`; `error.meta.runtime=<runtime>`; `error.meta.platform=<platform>`; `error.meta.tool=<tool>` |
| Instance required | The selected tool supports multiple instances and no instance id can be inferred. | `error.code=tool.instance_required`; `error.meta.tool=<tool>` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-install` changes gateway tool configuration and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/gateway/tests/Feature/Commands/Tools/ToolInstallCommandTest.php` | Command contract for input validation, gateway authorization, target resolution, side-effect boundaries, failure codes, and doctor handoff behavior. |
| `apps/gateway/tests/Feature/Commands/Tools/ToolInstallJsonRendererTest.php` | JSON envelope shape, unsupported status metadata, missing target metadata, gateway error pass-through, and `--expected-version` rejection. |
| `apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php` | Gateway-side install validation for unsupported status, rejected install-time version intent, and required explicit target selectors. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape, target resolution rules, and tool-family entity mapping. |
