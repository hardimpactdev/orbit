# `orbit process:edit [name]`

[Back to Process commands.](../README.md)

**Purpose:** Update an app-owned process definition.

**Description:** Changes a process command, restart policy, or crash notification policy and re-renders every runtime unit derived from that process definition.

**Technical contract:** [`technical/1_process-edit.md`](technical/1_process-edit.md)

## Usage

```bash
orbit process:edit vite --app=docs --command="npm run dev"
orbit process:edit queue --app=docs --restart-policy=on_failure --restart
orbit process:edit vite --app=docs --command="npm run dev" --json
```

## Behavior

- Updates the gateway-owned process definition.
- Re-renders the main app runtime unit and every workspace runtime unit derived from the process definition.
- Does not restart running runtime units unless `--restart` is supplied.
- Reports repairable runtime-unit apply drift after successful configuration changes.

## Related

- [`process:add`](../1_process-add/process-add.md)
- [`process:restart`](../7_process-restart/process-restart.md)
- [`process-doctor.md`](../process-doctor.md)
