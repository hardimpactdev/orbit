# `orbit process:list`

[Back to Process commands.](../README.md)

**Purpose:** List process intent and last known runtime state for an app or
workspace.

**Description:** Reads app-owned process definitions and latest durable
lifecycle events from the gateway without performing live node inspection.

**Technical contract:** [`technical/1_process-list.md`](technical/1_process-list.md)

## Usage

```bash
orbit process:list --app=docs
orbit process:list --app=docs --workspace=feature-docs
orbit process:list --app=docs --json
```

## Behavior

- Reads process definitions from gateway intent.
- Resolves an app or workspace context.
- Derives expected runtime unit identities for that context.
- Shows the latest durable process lifecycle event when one exists.
- Does not SSH to the owning app node and does not run live runtime probes.

## Related

- [`process:logs`](../8_process-logs/process-logs.md)
- [`process:start`](../5_process-start/process-start.md)
- [`process-doctor.md`](../process-doctor.md)
