# `orbit process:logs [name]`

[Back to Process commands.](../README.md)

Show or follow logs for a process runtime context.

`process:logs` reads process logs from the resolved node or instance serving node process manager through
the gateway for a resolved node, instance, or workspace context. On agent-capable
nodes, bounded reads and follow streams use the typed `internal:process-logs`
local-executor command over Agent push. Follow mode publishes through the
durable operations WebSocket plane. There is no Orbit-managed SSH path.

## Usage

```bash
orbit process:logs vite --instance=docs.production
orbit process:logs vite --instance=docs.development --workspace=feature-docs --follow
orbit process:logs orbit-hermes-dashboard --node=app-dev-1 --lines=200
orbit process:logs queue --instance=docs.production --lines=200 --json
```

## Behavior Summary

Use this command to read or stream logs for a resolved process runtime context.

- **Context Resolution**: Resolves the process and node/instance/workspace runtime context. Prefer `<app.instance>`; a bare project slug is accepted only when that project has exactly one instance.
- **Placement**: Instance and workspace logs are read from the instance's serving node.
- **Log Streaming**: Streams or returns logs from the selected process runtime
  backend through the gateway.
- **Service Metadata**: Bounded JSON reads include safe service connection
  metadata for service process definitions, including endpoint and credential
  field names but not credential values.
- **No Mutations**: Does not mutate process configuration.
- **JSON Restriction**: Uses JSON output only for non-follow mode. `--json --follow` is rejected before opening the log stream.

## Related

- [`process:list`](../4_process-list/process-list.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-logs.md`](technical/1_process-logs.md)
