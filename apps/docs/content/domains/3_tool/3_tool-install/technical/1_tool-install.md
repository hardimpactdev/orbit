# Technical Contract: `orbit tool:install <tool> [--app=<app>] [--node=<node>] [--tool-version=<version>] [--user=<name>] [--status=<installed|running>] [--with-process|--no-process] [--json|--stream-json]`

[Back to public `tool-install` documentation.](../tool-install.md)

**Owner:** `tool`.

**Effects:** `write, stream`.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to manage tools for the resolved node or app.

## Signature

```bash
orbit tool:install <tool> [--app=<app>] [--node=<node>] [--tool-version=<version>] [--user=<name>] [--status=<installed|running>] [--with-process|--no-process] [--json|--stream-json]
```

## Input Contract

This command follows the shared [Invocation Model](../../../README.md#invocation-model).

| Field | Source | Required when | Forbidden when | Default | Validation |
| --- | --- | --- | --- | --- | --- |
| `tool` | `argument` | `Always.` | `Never.` | `None.` | `Supported tool name.` |
| `node` | `--node` | When no `--app`, local `node:default`, or interactive target selection resolves a target. | `Never.` | `node:default` if set; otherwise interactive selection in TTY mode. | Visible active non-gateway node slug; selected tool must support the node operating system. |
| `app` | `--app` | `Optional.` | `Never.` | `None.` | `Visible app selector used to resolve the owning node.` |
| `version` | `--tool-version` | Optional. | When the selected tool definition does not explicitly support install versions. | Tool-defined latest supported version when applicable. | Specific version or installer channel supported by the selected tool definition. |
| `config.install_users` | `--user` (repeatable) | Optional for user-scoped CLI tools. | For tools that are not user-scoped CLI tools. | `None.` | Additional existing Linux usernames for user-scoped CLI installs. Each value must match a conservative Linux username allow-list; Orbit does not create the account. |
| `status` | `--status` | `Optional.` | `Never.` | `installed` | Expected capability state: installed or running. This does not start a process. |
| `with_process` | `--with-process` / `--no-process` | `Optional.` | `Never.` | `true` for tools that declare a related process | When set true (the default), a tool that declares a related singleton process configures that process. `--no-process` installs the capability only. Supplying both `--with-process` and `--no-process` fails. |
| `json` | `--json` | `Optional.` | `Never.` | `false` | `Selects the JSON renderer.` |
| `stream-json` | `--stream-json` | `Optional.` | `Never.` | `false` | Selects the stream JSON renderer and non-interactive input mode. Mutually exclusive with `--json`. |

`--tool-version` records the intended tool version at install time. `--expected-version`
and `expected_version` remain `tool:update` inputs and are rejected here.
For `claude-code`, `latest` and `stable` are floating installer channels. The
agent CLI tools added in this slice reject version intent until Orbit encodes a
source-backed version syntax for each tool.

For CLI tools installed per OS user, the node's stored default user is always
included. Empty install users fail before row writes. Tools outside that model
reject `config.install_users`. For `claude-code`, `latest` and `stable` version
targets are stored as the requested installer channel, while live probes report
the concrete Claude Code binary version returned by `claude --version`.

## Behavior Contract

### Tool configuration and apply rules

- Verifies the tool supports managed installation on the target node.
- Resolves the requested expected version before any gateway row or node artifact is written.
- Verifies the target node is active, visible, not the gateway, and supported
  by the selected tool definition's operating system metadata before writing
  gateway rows or node artifacts.
- Requires an explicit target source: `--node`, `--app`, local `node:default`,
  or interactive target selection. Non-interactive mode without a target source
  fails with `validation_failed`.
- Writes or updates gateway tool configuration.
- Generates managed credential material when the tool definition owns
  credentials.
- Creates or updates endpoint configuration owned by the tool when the tool
  definition declares an endpoint.
- Applies install and configuration through the gateway.
- For user-scoped CLI tools, treats the tool as a normal installable `runtime`
  tool with `requiredNodeRole() === null`. Eligibility is explicit target
  authorization, active non-gateway node selection, and supported operating
  system metadata.
  The command derives the default install user from `nodes.user` with an
  `orbit` fallback, sanitizes repeatable `--user` values into
  `config.install_users`, rejects unsafe usernames before row writes or remote
  shell actions, and runs the selected tool's source-backed installer as each
  existing target user with `sudo -u <user> -H bash -lc`. Tool doctor later
  probes the persisted `default_user` from the tool row, not a shared launcher.
  When `expected_version` is `latest` or `stable` for `claude-code`, doctor
  treats it as a floating installer channel instead of a literal version prefix.
- If remote installation or configuration fails after gateway configuration is
  written, Orbit keeps the expected tool row and reports the node as not yet
  converged; `doctor --family=tool --restore` owns later convergence when the tool
  definition declares a safe repair path.

### Related process configuration

- When the tool definition declares a related singleton service process and
  `--no-process` is not supplied, `tool:install` converges that process through
  the process family after the capability install succeeds. The process is
  node-owned and uses the runtime, command, and `--tool` dependency declared by
  the tool definition (for `opencode-cli`: related process `opencode-server`,
  `runtime=systemd`, command `opencode serve -a`, `tool=opencode-cli`).
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

`--stream-json` uses the shared
[Stream JSON Frames](../../../README.md#stream-json-frames) contract.

## Failure Semantics
Standard failures defined in [Common Failures](../../../README.md#common-failures) apply; command-specific failures below.

| Failure | Condition | Outcome |
| --- | --- | --- |
| Tool not found | The selected tool row or tool definition cannot be resolved. | `error.code=tool.not_found` |
| Unsupported tool action | The selected tool definition does not support this command's action. | `error.code=tool.unsupported_action` |
| Unsupported node OS | The selected tool definition does not support the target node operating system. | `error.code=tool.unsupported_on_node`; `error.meta.supported_operating_systems=<values>` |
| Unsupported status value | `--status` is not `installed` or `running`. | `error.code=validation_failed`; `error.meta.field=status`; `error.meta.reason=unsupported_value` |
| Missing target source | Non-interactive input provides no `--node`, `--app`, or local `node:default`. | `error.code=validation_failed`; `error.meta.fields=["target"]` |
| Unsupported runtime field | API input includes `runtime`. Tools do not own runtime lifecycle. | `error.code=validation_failed`; `error.meta.field=runtime`; `error.meta.reason=unsupported_field` |
| Unsupported instance field | API input includes `instance`. Tools do not support runnable service instances. | `error.code=validation_failed`; `error.meta.field=instance`; `error.meta.reason=unsupported_field` |
| Unsupported install version | API input includes `version` for a tool definition that does not explicitly support install versions. | `error.code=validation_failed`; `error.meta.field=version`; `error.meta.reason=unsupported_field` |
| Unsupported install users field | API input includes `config.install_users` for a tool that is not a user-scoped CLI tool. | `error.code=validation_failed`; `error.meta.field=config.install_users`; `error.meta.reason=unsupported_field` |
| Invalid install user | `--user` / `config.install_users` includes an empty or unsafe username. | `error.code=validation_failed`; `error.meta.field=config.install_users`; `error.meta.reason=unsupported_value` |
| Conflicting process options | Both `--with-process` and `--no-process` are supplied. | `error.code=validation_failed`; `error.meta.reason=conflicting_options` |
| Remote action failed | Gateway configuration was readable, but node inspection or apply failed. | `error.code=tool.remote_action_failed` |

## Doctor Relationship

`tool-install` changes gateway tool configuration and performs command-owned apply only. [`tool-doctor.md`](../../tool-doctor.md) owns the authoritative tool-family probe, issue codes, fix map, and adopt map.

## Test Mapping

| Path | Coverage |
| --- | --- |
| `apps/cli/tests/Feature/Commands/Tool/ToolWriteCommandTest.php` | CLI install write flow: prompts, payloads, default node resolution, `--tool-version`, user-scoped CLI `--user` forwarding, empty `--user` gateway rejection, unsupported `--status`, and gateway install error envelope pass-through. |
| `apps/cli/tests/Feature/Commands/Tool/ToolStreamCommandTest.php` | CLI stream adapter behavior for install: final complete frame in `--json` mode, canonical stream request shape, `--no-process`, and pre-stream gateway error pass-through. |
| `apps/gateway/tests/Feature/Http/Api/ToolInstallControllerTest.php` | Gateway/API install: row writes, CLI install-user config, rejected runtime/instance fields, related process convergence, authorization failure, unsupported status/action/version, and update-only version intent rejection. |
| `apps/gateway/tests/Unit/Services/Tools/ToolsProbeTest.php` | Tool-family probe behavior, including `claude-code` row-specific probing through the persisted default install user. |
| `apps/gateway/tests/Unit/Services/Tools/ToolCommandContractTest.php` | Shared in-memory tool command DTO shape and tool-family entity mapping used by install request handling. |
