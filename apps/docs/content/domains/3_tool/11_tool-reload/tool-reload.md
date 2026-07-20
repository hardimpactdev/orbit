# `orbit tool:reload`

[Back to Tool commands.](../README.md)

Reload a tool that declares a reload capability without replacing its runtime.

## Usage

```bash
orbit tool:reload <tool> --node=<node>
orbit tool:reload caddy --node=<node>
orbit tool:reload caddy --node=<node> --json
```

## Behavior

`tool:reload` is available only when the selected tool declares `reload`.
Orbit must resolve exactly one direct tool-owned runtime. Missing or ambiguous
runtimes fail without running a host command.

Direct remote reload uses Agent push. Reload is not inferred from start,
restart, reconfigure, or a similarly named process. Process-backed reload is
unsupported until a tool declares a canonical reload operation for that
runtime.

## Options

- `--node=<node>` selects the target node.
- `--instance=<project.instance>` resolves the target node from an instance selector.
- `--json` returns a single JSON envelope.
- `--stream-json` streams progress frames.

## Related

- [Technical contract](technical/1_tool-reload.md)
- [`tool:restart`](../7_tool-restart/tool-restart.md)
- [`tool:logs`](../8_tool-logs/tool-logs.md)
- [`tool:reconfigure`](../12_tool-reconfigure/tool-reconfigure.md)
