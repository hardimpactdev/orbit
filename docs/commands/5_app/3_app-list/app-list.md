# `orbit app:list`

[Back to Apps commands.](../README.md)

List apps registered on the gateway.

`app:list` provides a high-level summary of application intent, showing which
apps are assigned to which nodes and their configured environments. For live
app health and runtime verification, use
[`doctor --family=app`](../app-doctor.md).
There is intentionally no `app:list --doctor` flag; app list output stays a
fast registry read.

## Usage

```bash
orbit app:list [--node=<name>] [--environment=<development|production>] [--json]
```

## Examples

```bash
orbit app:list
orbit app:list --node=app-1
orbit app:list --environment=production
orbit app:list --json
```

## Arguments And Options

- `--node`: filter by owning node name.
- `--environment`: filter by environment (`development` or `production`).
- `--json`: Output JSON.

## What Happens

`app:list` reads the app registry from the gateway and applies the requested
filters:

1. Connects to the gateway API.
2. Reads app registry intent scoped to what the caller is authorized to see.
3. Filters by node or environment when options are supplied.
4. Returns a list of apps with their names, nodes, environments, and primary
   URLs.

`app:list` does not:
- SSH into app nodes.
- Probe app health or path existence (use [`doctor --family=app`](../app-doctor.md)).
- Mutate gateway intent or node artifacts.

## Output

Both renderers use the same deterministic ordering: apps are sorted by owning
node name and then by app name (alphabetical, case-insensitive).

Human output presents that ordering as tables grouped by owning node.

JSON output returns a flat list of apps in the same order under the standard
success envelope. See the
[JSON renderer contract](technical/6.2_app-list_output-render_json.md) for the
exact payload shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The caller identity is authorized to read the app registry.

## Related Commands

- [`app:new`](../1_app-new/app-new.md) — create or clone an app
- [`app:show`](../5_app-show/app-show.md) — inspect a single app's details
- [`doctor --family=app`](../app-doctor.md) — verify and repair app drift
- [`node:list`](../../1_node/3_node-list/node-list.md) — list registered nodes

## Technical Contract

See [`app:list` technical contract](technical/1_app-list.md).
