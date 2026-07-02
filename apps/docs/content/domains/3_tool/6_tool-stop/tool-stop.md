# `orbit tool:stop`

Stop a lifecycle-capable tool on a target node.

## Usage

```bash
orbit tool:stop <tool> --node=<node>
orbit tool:stop orbstack --node=<mac-node>
orbit tool:stop orbstack --node=<mac-node> --json
```

## Behavior

`tool:stop` is available only for tools whose catalog definition explicitly
declares a tool-owned stop capability. The first supported tool is macOS-only
`orbstack`. Unsupported tools fail without running host commands.

For `orbstack`, this dispatches OrbStack's stop command for the provider, not an
Orbit process-row action.

## Options

- `--node=<node>` selects the target node.
- `--app=<app>` resolves the target node from an app selector.
- `--json` returns a single JSON envelope.
- `--stream-json` streams progress frames.

## Related

- [Technical contract](technical/1_tool-stop.md)
- [`tool:start`](../5_tool-start/tool-start.md)
- [`tool:restart`](../7_tool-restart/tool-restart.md)
