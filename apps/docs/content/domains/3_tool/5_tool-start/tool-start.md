# `orbit tool:start`

Start a lifecycle-capable tool on a target node.

## Usage

```bash
orbit tool:start <tool> --node=<node>
orbit tool:start orbstack --node=<mac-node>
orbit tool:start orbstack --node=<mac-node> --json
```

## Behavior

`tool:start` is available only for tools whose catalog definition explicitly
declares a tool-owned start capability. The first supported tool is macOS-only
`orbstack`. Unsupported tools fail without running host commands.

## Options

- `--node=<node>` selects the target node.
- `--app=<app>` resolves the target node from an app selector.
- `--json` returns a single JSON envelope.
- `--stream-json` streams progress frames.

## Related

- [Technical contract](technical/1_tool-start.md)
- [`tool:stop`](../6_tool-stop/tool-stop.md)
- [`tool:restart`](../7_tool-restart/tool-restart.md)
