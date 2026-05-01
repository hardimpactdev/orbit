# `orbit process:restart [name]`

[Back to Process commands.](../README.md)

**Purpose:** Restart a process in an app or workspace runtime context.

**Description:** Cycles one derived runtime unit through the gateway on the
owning app node and records lifecycle events for the transition.

**Technical contract:** [`technical/1_process-restart.md`](technical/1_process-restart.md)

## Usage

```bash
orbit process:restart vite --app=docs
orbit process:restart vite --app=docs --workspace=feature-docs
orbit process:restart vite --app=docs --json
```

## Behavior

- Resolves the process and app/workspace runtime context.
- Restarts the derived runtime unit through the gateway.
- Records and publishes lifecycle events for the stopped and started runtime
  transitions.
- Does not change process intent and does not repair divergent runtime-unit
  files.

## Related

- [`process:start`](../5_process-start/process-start.md)
- [`process:stop`](../6_process-stop/process-stop.md)
- [`process-doctor.md`](../process-doctor.md)
