# `orbit app:remove [app]`

[Back to App commands.](../README.md)

Decommissions an application and cleans up its registry state and managed infrastructure artifacts. Used when a service is no longer needed or being moved to a different node.

## Usage

```bash
orbit app:remove [app] [--force] [--json]
```

### Arguments

- `app`: The app name or hostname to remove.

### Options

- `--force`: Skip interactive confirmation.
- `--json`: Output JSON.

## Examples

```bash
# Remove an app with interactive confirmation
orbit app:remove my-app

# Force removal without confirmation
orbit app:remove my-app --force
```

## Behavior Summary

1. **Configuration Removal:** Deletes the gateway app configuration record. This is the point of no return.
2. **Dependent Cleanup:** Removes app-owned records from `proxy`, schedules, workspace configuration, and process artifacts.
3. **Artifact Cleanup:** Cleans node-side runtime artifacts (FPM config, app-owned directories) over SSH where possible.
4. **Drift Monitoring:** Removed apps disappear from `app:list` and `app:show`. Once Step 1 (gateway configuration removal) succeeds, any failure during dependent or node-side cleanup is reported as a non-fatal warning that points at the affected `doctor --fix --family=<family> --restore`. App-owned node artifacts are reported as orphaned app drift by [`app-doctor.md`](../app-doctor.md).

## Output Summary

- **Human:** Framed destructive confirmation followed by a step tree. Drift after gateway configuration removal renders as a footer with one line per affected family doctor.
- **JSON:** A single top-level `success` or `error` envelope. Partial cleanup is `success` with structured warnings under `success.meta.warnings[]` (each carrying `code`, `family`, `message`, and `next_command`).

## Requirements

- CLI caller must reach the Orbit gateway.
- Authorized node identity for the target app or node.
- Gateway SSH access to the app node is used for artifact cleanup when
  available. If cleanup cannot finish after app configuration removal, the command
  still succeeds and reports warnings with repair commands.

## Related

- [`app:new`](../1_app-new/app-new.md)
- [`app:list`](../3_app-list/app-list.md)
- [`node:remove`](../../1_node/8_node-remove/node-remove.md)

---

[View Technical Contract](technical/1_app-remove.md)
