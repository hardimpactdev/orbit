# `orbit tool:logs`

[Back to Tool commands.](../README.md)

Read retained log lines from a tool that declares a logs capability.

## Usage

```bash
orbit tool:logs <tool> --node=<node>
orbit tool:logs opencode-cli --app=<app> --lines=200
orbit tool:logs dns --lines=50
orbit tool:logs dns --json
```

## Behavior

`tool:logs` is available only when the selected tool declares `logs`. Orbit
must resolve exactly one runtime: either one direct tool-owned runtime, or one
process row whose canonical `tool` value matches the selected tool. Missing or
ambiguous runtimes fail before reading logs.

Direct remote runtimes use Agent push. Process-backed tools read through their
exact process row. `dns` is the gateway-local exception and reads the one
`orbit-dns` container directly. This command is a bounded retained-log read; use
`process:logs --follow` when a process-owned live stream is required.

## Options

- `--node=<node>` selects the target node.
- `--app=<app>` resolves the target node from an app selector.
- `--lines=<number>` selects the positive number of retained lines. The
  default is `100`.
- `--json` returns a single JSON envelope.

## Related

- [Technical contract](technical/1_tool-logs.md)
- [`tool:restart`](../7_tool-restart/tool-restart.md)
- [`tool:reload`](../11_tool-reload/tool-reload.md)
- [`process:logs`](../../7_process/8_process-logs/process-logs.md)
