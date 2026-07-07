# `orbit process:logs [name]`

[Back to Process commands.](../README.md)

Show or follow logs for a process runtime context.

`process:logs` reads process logs from the owning node process manager through
the gateway for a resolved node, app, or workspace context. On agent-capable
nodes, bounded reads and follow streams use the typed `internal:process-logs`
local-executor command over agent-push; explicit SSH fallback is reserved for
migration or recovery.

## Usage

```bash
orbit process:logs vite --app=docs
orbit process:logs vite --app=docs --workspace=feature-docs --follow
orbit process:logs opencode-server --node=app-dev-1 --lines=200
orbit process:logs queue --app=docs --lines=200 --json
```

## Behavior Summary

Use this command to read or stream logs for a resolved process runtime context.

- **Context Resolution**: Resolves the process and node/app/workspace runtime context.
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
