# `orbit process:restart [name]`

[Back to Process commands.](../README.md)

**Purpose:** Restart one process, or all processes, in an app or workspace
runtime context.

**Description:** Cycles derived runtime units through the gateway on the owning
app node and records lifecycle events for the transition.

**Technical contract:** [`technical/1_process-restart.md`](technical/1_process-restart.md)

## Usage

```bash
orbit process:restart vite --app=docs
orbit process:restart vite --app=docs --workspace=feature-docs
orbit process:restart vite --app=docs --json
orbit process:restart --app=docs --workspace=feature-docs
```

## Behavior

- Resolves the app or workspace runtime context.
- When `[name]` is supplied, restarts that process only.
- When `[name]` is omitted, restarts all process definitions for the selected
  context in process order.
- Restarts the derived runtime units through the gateway.
- Records and publishes lifecycle events for the stopped and started runtime
  transitions.
- Does not change process intent and does not repair divergent runtime-unit
  files.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:stop`](../6_process-stop/process-stop.md)
- [`process-doctor.md`](../process-doctor.md)
