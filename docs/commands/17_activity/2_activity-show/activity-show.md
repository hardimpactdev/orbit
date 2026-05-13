# `orbit activity:show [id]`

[Back to Activity commands.](../README.md)

Show one gateway activity history entry.

`activity:show` retrieves the full details for one activity entry and shows
other visible entries that share its correlation id. Use it when an operator
needs to understand what a previous command, API call, or gateway apply
changed.

## Usage

```bash
orbit activity:show [id] [--json]
```

## Examples

```bash
orbit activity:show 42
orbit activity:show 42 --json
```

## Arguments And Options

- `id`: Activity id to inspect.
- `--json`: Output JSON.

## What Happens

`activity:show`:

1. Resolves the requested activity id.
2. Reads the visible gateway activity record.
3. Reads other visible entries from the same correlated operation.
4. Renders the activity detail view.

The command is read-only. It does not replay, revert, fix, adopt, or inspect
live node state.

## Output

Human output is a detail view with the activity's time, type, effect, subject,
actor, command, correlation id, summary, details, and related activity.

JSON output returns the selected activity under `success.data.activity` and
correlated entries under `success.data.related`.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to read gateway activity history.
- The activity entry exists and is visible to the current node identity.

## Related Commands

- [`activity:list`](../1_activity-list/activity-list.md) - list recent
  activity entries
- [`doctor`](../../11_operation/3_doctor/doctor.md) - verify current drift
  instead of reading historical events

## Technical Contract

See [`activity:show` technical contract](technical/1_activity-show.md).
