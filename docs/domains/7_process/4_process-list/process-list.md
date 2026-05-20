# `orbit process:list`

[Back to Process commands.](../README.md)

List process configuration and last known runtime state for an app or workspace.

`process:list` reads app-owned process definitions and latest durable lifecycle
events from the gateway without performing live node inspection.

## Usage

```bash
orbit process:list --app=docs
orbit process:list --app=docs --workspace=feature-docs
orbit process:list --app=docs --json
```

## Behavior Summary

Use this command to inspect process configuration and last known state without live node probing.

- **Process Definitions**: Reads process definitions from gateway configuration.
- **Context Resolution**: Resolves an app or workspace context.
- **Runtime Identity**: Derives expected runtime unit identities for that context.
- **Lifecycle Events**: Shows the latest durable process lifecycle event when one exists.
- **No Live Probing**: Does not SSH to the owning node and does not run live runtime probes.

## Related

- [`process:logs`](../8_process-logs/process-logs.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)

***

**Technical Contract:** [`technical/1_process-list.md`](technical/1_process-list.md)
