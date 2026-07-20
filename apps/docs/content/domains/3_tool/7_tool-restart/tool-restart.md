# `orbit tool:restart`

Restart a lifecycle-capable tool on a target node.

## Usage

```bash
orbit tool:restart <tool> --node=<node>
orbit tool:restart orbstack --node=<mac-node>
orbit tool:restart dns
orbit tool:restart opencode-cli --instance=<project.instance>
orbit tool:restart orbstack --node=<mac-node> --json
```

## Behavior

`tool:restart` is available only when the selected tool declares `restart`.
Orbit must resolve exactly one runtime: either one direct tool-owned runtime,
or one process row whose canonical `tool` value matches the selected tool.
Missing or ambiguous runtimes fail without running host commands.

Direct remote runtimes use Agent push. Process-backed tools use their exact
process row. `dns` is the gateway-local exception: it restarts the one
`orbit-dns` container directly and accepts no remote target.

## Options

- `--node=<node>` selects the target node.
- `--instance=<project.instance>` resolves the target node from an instance selector.
- `--json` returns a single JSON envelope.
- `--stream-json` streams progress frames.

## Related

- [Technical contract](technical/1_tool-restart.md)
- [`tool:start`](../5_tool-start/tool-start.md)
- [`tool:stop`](../6_tool-stop/tool-stop.md)
