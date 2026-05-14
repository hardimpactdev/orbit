# `orbit app:remove [app]`

[Back to App commands.](../README.md)

Decommissions an application and cleans up its registry state and managed infrastructure artifacts.

Use this command when a service is no longer needed or is being moved to a different node.

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

The following steps describe the removal sequence in order.

1. **Configuration Removal:** Deletes the gateway app configuration record. This is the point of no return.
2. **Dependent Cleanup:** Removes app-owned records from `proxy`, schedules, workspace configuration, and process artifacts.
3. **Artifact Cleanup:** Cleans node-side runtime artifacts (FPM config, app-owned directories) over SSH where possible.
4. **Drift Monitoring:** Removed apps disappear from `app:list` and `app:show`. Once Step 1 succeeds, any failure during later cleanup is a non-fatal warning pointing at the affected `doctor --fix --family=<family> --restore`. App-owned node artifacts are reported as orphaned app drift by [`app-doctor.md`](../app-doctor.md).

## Output Summary

You will receive a summary of the removal result in the chosen output format.

- **Human:** Framed destructive confirmation followed by progress. Drift after gateway configuration removal renders as a footer with one line per affected family doctor.
- **JSON:** A machine-readable output. Partial cleanup is reported with
  structured warning metadata and repair guidance.

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
