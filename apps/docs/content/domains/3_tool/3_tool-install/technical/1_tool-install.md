# Technical Contract: `orbit tool:install <tool> [--app=<app>] [--node=<node>] [--tool-version=<version>] [--status=<installed|running>] [--with-process|--no-process] [--json]`

[Back to public `tool-install` documentation.](../tool-install.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:install <tool> [--app=<app>] [--node=<node>] [--tool-version=<version>] [--status=<installed|running>] [--with-process|--no-process] [--json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Supported tool name.` |
| `node` | `--node` | When no `--app`, local `node:default`, or interactive target selection resolves a target. | `Never.` | `node:default` if set; otherwise interactive selection in TTY mode. | `Visible node slug.` |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `version` | `--tool-version` | Optional. | `Never.` | Tool-defined latest supported version when applicable. | Specific version supported by the selected tool definition. |
| `status` | `--status` | `Optional.` | `Never.` | `installed` | Expected capability state: installed or running. This does not start a process. |
| `with_process` | `--with-process` / `--no-process` | `Optional.` | `Never.` | `true` for tools that declare a related process | When set true (the default), a tool that declares a related singleton process configures that process. `--no-process` installs the capability only. Supplying both `--with-process` and `--no-process` fails. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |

`--tool-version` records the intended tool version at install time. `--expected-version`
and `expected_version` remain `tool:update` inputs and are rejected here.

## Behavior Contract

### Tool configuration and apply rules

- Verifies the tool supports managed installation on the target node.
- Resolves the requested expected version before any gateway row or node artifact is written.
- For role baseline tools, verifies the target node already has the
  required active role. `tool:install seaweedfs` requires an active `s3` role and
  reconverges the SeaweedFS role baseline instead of creating a standalone
  object-storage service.
- Requires an explicit target source: `--node`, `--app`, local `node:default`,
  or interactive target selection. Non-interactive mode without a target source
  fails with `validation_failed`.
- Writes or updates gateway tool configuration.
- Generates managed credential material when the tool definition owns
  credentials.
- Creates or updates endpoint configuration owned by the tool when the tool
  definition declares an endpoint.
- Applies install and configuration through the gateway.
- If remote installation or configuration fails after gateway configuration is
  written, Orbit keeps the expected tool row and reports the node as not yet
  converged; `doctor --family=tool --restore` owns later convergence when the tool
  definition declares a safe repair path.

### Related process configuration

- When the tool definition declares a related singleton service process and
  `--no-process` is not supplied, `tool:install` converges that process through
  the process family after the capability install succeeds. The process is
  node-owned and uses the runtime, command, and `--tool` dependency declared by
  the tool definition (for `opencode-server`: `runtime=systemd`, command
  `opencode serve -a`, `tool=opencode`).
- The convergence is idempotent: a newly created process is reported as
  `configured`; an existing related process is reported as `converged`. It never
  creates a duplicate.
- `--no-process` installs the capability only and configures no process.
  `--with-process` is the explicit form of the default; supplying both
  `--with-process` and `--no-process` fails with `validation_failed`.
- Process lifecycle (start, stop, restart, logs) stays owned by `process:*`.
  `tool:install` only configures the related process row and its initial
  convergence.

### Scope Boundaries

`tool-install` must not create apps, workspaces, schedules, custom proxy routes,
non-tool firewall rules, node identities, or node grants. It may configure only
the singleton service process a tool definition declares as its related process;
all other process creation belongs to the process family. Tool-owned endpoint
configuration is allowed only when declared by the selected tool definition.
Related drift belongs to each owning family doctor contract.

## Renderer Contracts

- [Human renderer](6.1_tool-install_output-render_human.md)
- [JSON renderer](6.2_tool-install_output-render_json.md)

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Required role missing | The selected role baseline tool requires an active role that the target node does not have, such as `seaweedfs` requiring `s3`. | `error.code=validation_failed`; `error.meta.field=node`; `error.meta.required_role=<role>` |
| Unsupported status value | `--status` is not `installed` or `running`. | `error.code=validation_failed`; `error.meta.field=status`; `error.meta.reason=unsupported_value` |
| Missing target source | Non-interactive input provides no `--node`, `--app`, or local `node:default`. | `error.code=validation_failed`; `error.meta.fields=["target"]` |
| Unsupported runtime field | API input includes `runtime`. Tools do not own runtime lifecycle. | `error.code=validation_failed`; `error.meta.field=runtime`; `error.meta.reason=unsupported_field` |
| Unsupported instance field | API input includes `instance`. Tools do not support runnable service instances. | `error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=unsupported_field` |
| Conflicting process options | Both `--with-process` and `--no-process` are supplied. | `error.code=validation_failed`; `error.meta.reason=conflicting_options` |
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
