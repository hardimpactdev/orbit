# `orbit activity:list`

[Back to Operation commands.](../README.md)

List recent gateway activity history visible to the caller.

`activity:list` reads durable gateway history for operational review,
troubleshooting, and audit trails. It shows recent command, API, and gateway
enactment activity recorded by the gateway, including the correlation id that
ties entries from the same operation together.

## Usage

```bash
orbit activity:list [--app=<app>] [--node=<node>] [--correlation=<uuid>] [--limit=<count>] [--json]
```

## Examples

```bash
orbit activity:list
orbit activity:list --app=docs
orbit activity:list --node=app-1 --limit=50
orbit activity:list --correlation=9f7307e8-38b2-45b8-9b94-cfc341456b85
orbit activity:list --json
```

## Arguments And Options

- `--app`: Limit results to activity associated with the recorded app key.
- `--node`: Limit results to activity associated with the recorded node name.
- `--correlation`: Limit results to one correlated operation.
- `--limit`: Maximum number of entries to return. Defaults to `25`.
- `--json`: Output JSON.

## What Happens

`activity:list`:

1. Resolves the local caller role and gateway connection.
2. Validates the requested filters.
3. Asks the gateway for recent activity history visible to the caller.
4. Renders the matching entries newest first.

The command is read-only. It does not inspect live node state, repair drift, or
query app-node runtimes.

## Output

Human output is a compact activity table with time, id, effect, type, subject,
actor, and command columns.

JSON output returns activity objects under `success.data.activities` with filter
and count metadata under `success.meta`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read gateway activity history for
  the requested filters.

## Related Commands

- [`activity:show`](../5_activity-show/activity-show.md) - show one activity
  entry and its correlated context
- [`doctor`](../3_doctor/doctor.md) - verify current drift instead of reading
  historical events

## Technical Contract

See [`activity:list` technical contract](technical/1_activity-list.md).
