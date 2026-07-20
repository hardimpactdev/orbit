# `orbit tool:credentials [tool]`

[Back to Tool commands.](../README.md)

Show connection credentials for a managed tool.

`tool:credentials` displays generated or registered connection details for
tools that declare a credential contract, such as Mailpit, SeaweedFS, OpenClaw,
or OpenCode Server. Credentials for runnable services such as MySQL,
PostgreSQL, and Valkey are not tool credentials.

## Usage

```bash
orbit tool:credentials [tool] [--instance=<project.instance>] [--node=<node>] [--json]
```

## Examples

```bash
orbit tool:credentials mailpit --node=app-1
orbit tool:credentials openclaw --node=agent-1
orbit tool:credentials opencode-cli --instance=docs
orbit tool:credentials --node=app-1
orbit tool:credentials opencode-cli --node=agent-1 --json
```

## Arguments and options

- `tool`: Optional tool name. When omitted in interactive mode, Orbit prompts
  from credential-bearing tools visible on the resolved node.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--instance`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--instance`, nor local
`node:default` resolves a node.

## What Happens

Run this command to read connection credentials for a managed tool on the target node.

`tool:credentials`:

1. Resolves the target node and credential-bearing tool.
2. Reads credential metadata from gateway configuration or the tool's managed secret
   store.
3. Renders the tool's declared credential fields.

Credential-bearing tools use generated Orbit-owned secrets. `tool:credentials`
reads the current values; it does not rotate credentials, reconfigure the tool,
or expose credentials for tools that do not declare a credential contract.
Credential reads stay gateway-local: the command exposes no node transport
selector and uses neither Agent push nor SSH.

## Output

Use `--json` to get machine-readable credential fields; omit it for a grouped detail view.

Human output shows connection fields in a grouped detail view.

Use `--json` for machine-readable credential fields with tool and node
metadata.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tool credentials for the
  selected node or instance.
- The tool is registered for the resolved node and declares credentials.

## Related Commands

Use these commands to inspect, repair, or verify managed tool state.

- [`tool:show`](../2_tool-show/tool-show.md) - inspect tool metadata
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup when credentials need repair
- [`doctor --family=tool`](../tool-doctor.md) - verify managed tool state

## Technical Contract

See [`tool-credentials` technical contract](technical/1_tool-credentials.md).
