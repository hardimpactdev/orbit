# `orbit tool:install <tool>`

[Back to Tool commands.](../README.md)

Install or configure a managed host tool on a node.

`tool:install` bootstraps a supported tool capability on a target node and
records gateway configuration for that node. When the tool backs a singleton
service (such as `hermes` backing `orbit-hermes-dashboard`), install also configures that tool's
related process by default so the capability comes up running; pass
`--no-process` to install the capability only. Multi-instance services such as
MySQL or Valkey are not tool installs; use `process:add --service=<identifier>` for those.

## Usage

```bash
orbit tool:install <tool> [--instance=<project.instance>] [--node=<node>] [--tool-version=<version>] [--user=<name>] [--status=<installed|running>] [--with-process|--no-process] [--json|--stream-json]
```

## Examples

```bash
orbit tool:install composer --node=app-1
orbit tool:install composer --node=app-1 --tool-version=2.9.2
orbit tool:install hermes --node=agent-1
orbit tool:install composer --node=app-1 --json
orbit tool:install composer --node=app-1 --stream-json
orbit tool:install claude-code --node=app-1
orbit tool:install claude-code --node=app-1 --user=agent
orbit tool:install codex-cli --node=app-1 --user=agent
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node.
- `--instance`: Resolve the target node from an app.
- `--tool-version`: Specific tool version supported by the tool definition.
  Tools that do not explicitly support install versions fail with
  `validation_failed`.
- `--user`: Repeatable additional Linux OS user for user-scoped CLI installs.
  Supplying `--user` for a tool that is not user-scoped fails with
  `validation_failed`.
  The node's stored default user (`nodes.user`, falling back to `orbit`) is
  always installed; each `--user` value adds another install scoped to that
  existing OS user. The option does not create Linux accounts. Empty values are
  rejected by gateway validation before row writes. This is additive install
  targeting, not a node-role eligibility gate.
- `--status`: Expected capability state after install. Defaults to
  `installed`; it does not create or start a process.
- `--with-process`: Also configure the tool's related service process. This is
  the default for service-backing tools.
- `--no-process`: Install the capability only; do not configure the related
  service process.
- `--json`: Output JSON.
- `--stream-json`: Stream newline-delimited progress JSON. Mutually exclusive
  with `--json`.

Target context is required. Provide `--node`, `--instance`, configure local
`node:default`, or select a target interactively. `tool:install` does not use a
silent single-node fallback.

## What Happens

Run this command to bootstrap a supported tool on the target node and record gateway configuration.

`tool:install`:

1. Resolves the target node and tool definition.
2. Resolves the requested tool version when one is supplied.
3. Runs a read-only constraint preflight for the explicit node platform,
   required container provider, runtime user and isolation, route/TLD, and
   target locality before any write or installer action. Missing platform
   metadata is not treated as Linux.
4. Creates or updates the gateway tool row for the node only after preflight
   succeeds.
5. Generates managed credentials when the selected tool declares a credential
   contract.
6. Creates or updates tool-owned endpoint configuration, when the tool declares one.
7. Applies the managed install/configuration through the gateway.
8. Reports the resulting expected state and command-owned apply outcome.

Gateway configuration work stays gateway-local. Target-node preflight and
apply actions use Agent push; `tool:install` exposes no node transport selector
and never falls back to SSH.

When the tool declares a related singleton service process and `--no-process`
is not supplied, `tool:install` also converges that process (runtime, command,
and tool dependency) so installing the capability yields a running service.
Re-installing converges the existing process instead of creating a duplicate.
Process lifecycle (start, stop, restart, logs) remains owned by the `process:*`
family.

If the tool is already managed and the operator wants to change its version
intent later, use
[`tool:update --expected-version`](../9_tool-update/tool-update.md). Updating a
managed tool does not migrate or restart related processes.

## Output

Use `--json` to get one machine-readable result, or `--stream-json` to stream
newline-delimited progress JSON.

Human output shows progress for configuration write and install/configuration
steps.

Use `--json` for the machine-readable tool and command outcome metadata. When a
related service process is configured, the JSON result includes the process
outcome alongside the tool entity. See the [JSON renderer contract](technical/6.2_tool-install_output-render_json.md) for the exact shape.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or app.
- The tool definition supports managed installation on the resolved node.
- Every declared install constraint is satisfied. Docker-backed tools require a
  reachable Docker-compatible provider; tools with a runtime user require that
  user and its declared isolation posture to exist on the target.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands for related tool lifecycle and configuration actions.

- [`tool:update`](../9_tool-update/tool-update.md) - change intended version for a managed tool
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup without changing version
- [`doctor --family=tool`](../tool-doctor.md) - verify installed tool state

## Technical Contract

See [`tool-install` technical contract](technical/1_tool-install.md).
