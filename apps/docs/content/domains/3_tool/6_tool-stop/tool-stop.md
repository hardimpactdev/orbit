# `orbit tool:stop`

Stop a lifecycle-capable tool on a target node.

## Usage

```bash
orbit tool:stop <tool> --node=<node>
orbit tool:stop orbstack --node=<mac-node>
orbit tool:stop opencode-cli --instance=<project.instance>
orbit tool:stop orbstack --node=<mac-node> --json
```

## Behavior

`tool:stop` is available only when the selected tool declares `stop`. Orbit
must resolve exactly one runtime: either one direct tool-owned runtime, or one
process row whose canonical `tool` value matches the selected tool. Missing or
ambiguous runtimes fail without running host commands.

Direct remote runtimes use Agent push. Process-backed tools use their exact
process row; this is an explicit catalog contract, not a generic compatibility
fallback.

## Options

- `--node=<node>` selects the target node.
- `--instance=<project.instance>` resolves the target node from an instance selector.
- `--json` returns a single JSON envelope.
- `--stream-json` streams progress frames.

## Related

- [Technical contract](technical/1_tool-stop.md)
- [`tool:start`](../5_tool-start/tool-start.md)
- [`tool:restart`](../7_tool-restart/tool-restart.md)
