# `orbit tool:credentials [tool]`

[Back to Tool commands.](../README.md)

Show connection credentials for a managed tool.

`tool:credentials` displays generated or registered connection details for tools
that declare a credential contract, such as PostgreSQL, MySQL, Redis, Mailpit,
Reverb, or OpenCode Server.

## Usage

```bash
orbit tool:credentials [tool] [--node=<node>] [--app=<app>] [--json]
```

## Examples

```bash
orbit tool:credentials postgres --node=app-1
orbit tool:credentials mailpit --node=app-1
orbit tool:credentials opencode-server --app=docs
orbit tool:credentials --node=app-1
orbit tool:credentials postgres --node=app-1 --json
```

## Arguments And Options

- `tool`: Optional tool name. When omitted in interactive mode, Orbit prompts
  from credential-bearing tools visible on the resolved node.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--app`: Resolve the target node from an app.
- `--json`: Output JSON.

Target context is required when neither `--node`, `--app`, nor local
`node:default` resolves a node.

## What Happens

`tool:credentials`:

1. Resolves the target node and credential-bearing tool.
2. Reads credential metadata from gateway intent or the tool's managed secret
   store.
3. Renders the tool's declared credential fields.

Credential-bearing managed service tools use generated Orbit-owned secrets.
`tool:credentials` reads the current values; it does not rotate credentials,
reconfigure the tool, or expose credentials for tools that do not declare a
credential contract.

## Output

Human output shows connection fields in a grouped detail view.

JSON output returns credential fields under `success.data.credentials.fields`
with tool and node metadata under `success.data.credentials`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to inspect tool credentials for the
  selected node or app.
- The tool is registered for the resolved node and declares credentials.

## Related Commands

- [`tool:show`](../2_tool-show/tool-show.md) - inspect tool metadata
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md) - rerun setup when credentials need repair
- [`doctor --family=tool`](../tool-doctor.md) - verify managed tool state

## Technical Contract

See [`tool-credentials` technical contract](technical/1_tool-credentials.md).
