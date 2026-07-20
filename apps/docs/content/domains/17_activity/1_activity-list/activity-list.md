# `orbit activity:list`

[Back to Activity commands.](../README.md)

List recent gateway activity history visible to the caller.

`activity:list` reads durable gateway history for operational review,
troubleshooting, and audit trails. It shows recent command, API, and gateway
apply activity recorded by the gateway, including the correlation id that
ties entries from the same operation together.

## Usage

```bash
orbit activity:list [--node=<node>] [--project=<project>] [--effect=<effect>] [--correlation=<uuid>] [--include-internal] [--limit=<count>] [--json]
```

## Examples

```bash
orbit activity:list
orbit activity:list --project=docs
orbit activity:list --node=app-1 --limit=50
orbit activity:list --effect=destructive
orbit activity:list --effect=destructive --node=app-1
orbit activity:list --correlation=9f7307e8-38b2-45b8-9b94-cfc341456b85
orbit activity:list --include-internal
orbit activity:list --json
```

## Arguments and options

- `--project`: Limit results to activity associated with the recorded project key.
- `--node`: Limit results to activity associated with the recorded node name.
- `--effect`: Limit results to one effect: `read`, `write`, or `destructive`.
- `--correlation`: Limit results to one correlated operation.
- `--include-internal`: Include current Agent-push and bootstrap transport
  audit rows. They use channel `api`, are hidden by default through
  `properties.lane = internal`, and carry their current transport marker.
- `--limit`: Maximum number of entries to return. Defaults to `25`.
- `--json`: Output JSON.

## What Happens

Run `activity:list` to retrieve recent gateway history filtered by project, node, effect, or correlation id. `activity:list`:

1. Validates the requested filters.
2. Asks the gateway for recent activity history visible to the caller.
3. Renders the matching entries newest first.

The command is read-only. It does not inspect live node state, repair drift, or
query app-role runtimes.

## Output

Use `--json` for machine-readable output. Human output is a compact activity table with time, id, effect, type, subject,
actor, and command columns.

Use `--json` for machine-readable activity objects, filters, and count
metadata. Every row uses the canonical Activity DTO defined by
[`activity-concepts.md`](../activity-concepts.md).

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read gateway activity history for
  the requested filters.

## Related Commands

Use these commands to drill into individual entries or to check live state.

- [`activity:show`](../2_activity-show/activity-show.md) - show one activity
  entry and its correlated context
- [`doctor`](../../11_operation/3_doctor/doctor.md) - verify current drift
  rather than reading past activity records

## Technical Contract

See [`activity:list` technical contract](technical/1_activity-list.md).
