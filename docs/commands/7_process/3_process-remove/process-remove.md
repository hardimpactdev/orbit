# `orbit process:remove [name]`

[Back to Process commands.](../README.md)

**Purpose:** Remove an app-owned process definition and its rendered runtime units.

**Description:** Deletes process configuration and stops/removes the derived runtime units for the main app instance and every workspace.

**Technical contract:** [`technical/1_process-remove.md`](technical/1_process-remove.md)

## Usage

```bash
orbit process:remove vite --app=docs
orbit process:remove queue --app=docs --force
orbit process:remove vite --app=docs --force --json
```

## Behavior

- Requires destructive consent before side effects: an interactive confirmation prompt or `--force`.
- Removes app-owned process configuration from the gateway.
- Stops and removes derived runtime units for the main app instance and all workspaces.
- Does not remove historical logs.
- Reports repairable cleanup drift when configuration removal succeeds but runtime-unit cleanup does not fully converge.

## Related

- [`process:add`](../1_process-add/process-add.md)
- [`process:list`](../4_process-list/process-list.md)
- [`process-doctor.md`](../process-doctor.md)
