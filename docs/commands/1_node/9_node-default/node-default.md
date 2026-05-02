# `orbit node:default [name]`

[Back to Nodes commands.](../README.md)

Choose, show, set, or clear the local default development app node.

Stores a control-node-local target preference so repeated development commands
run against a chosen remote app node without requiring `--node` every time.
This is the only command that sets or clears the local default; `node:new`
never sets it automatically.

## Usage

```bash
orbit node:default [name] [--clear] [--json]
orbit node:default
```

Run without arguments in interactive input mode to choose from the visible
development app nodes the current node identity may read. Provide `name` to set
it directly. Use `--clear` to remove the preference.
In non-interactive input mode, omitting `name` and `--clear` shows
the current local default.

## Examples

```bash
orbit node:default              # choose default interactively
orbit node:default app-1        # set app-1 as the default
orbit node:default --clear      # clear the default
orbit node:default --json       # show current default as JSON
orbit node:default app-1 --json # set app-1 as the default, output JSON
```

## Arguments And Options

- `name`: visible development app node name. Required for the `set` sub-action
  when no interactive prompt is available and a set is requested.
- `--clear`: clear the local default node. Mutually exclusive with providing
  `name`.
- `--json`: Output JSON.

## What Happens

`node:default` reads and writes local control-node configuration only. It does
not mutate gateway node intent, grant access to the default node, or change the
gateway endpoint configured by `gateway:add`.

### Choose or set

In interactive input mode, running `node:default` without a target queries the
gateway for visible development app nodes and presents them as choices. If the
current local default is still in that choice list, it is preselected. Selecting
a node stores it as the local default. Providing `name` skips
the choice prompt and validates that node directly.

### Show (non-interactive no `name`, no `--clear`)

Reads the locally stored default development app node and displays it. No
gateway call is required when a default is already stored locally. If no default
is set, reports that state without failure.

### Set (`name` provided)

Validates that the named node is a visible development app node by querying the
gateway, then stores the name as the local default development app node. Setting
requires the CLI caller to reach the Orbit gateway and the target to be visible
to the current identity.

### Clear (`--clear`)

Removes the locally stored default development app node. This is a local
configuration change only and does not require gateway reachability when the
local preference exists.

Commands that accept an app-node target resolve targets in this order: explicit
`--node`, app or workspace ownership, local `node:default`, then interactive
prompt or non-interactive failure.

### Recovery From Doctor Warnings

When `doctor --self` reports `node.local_default_invalid`, the stored local
default points at a missing, unauthorized, or non-development app node. Doctor
reports this only; it does not choose or clear a target automatically. Recover
by running `orbit node:default <valid-development-app-node>` or
`orbit node:default --clear`.

## Output

Human output shows the current default, confirms the set operation, or confirms
the clear operation.

JSON output returns the command result, the affected default node name, the
sub-action performed, and renderer-defined metadata such as `was_set` for clear
operations. See the
[JSON renderer contract](technical/6.2_node-default_output-render_json.md) for envelope
and payload shapes.

## Requirements

- The local CLI has an active gateway configuration (for `set` sub-action;
  interactive choice and direct set; `show` and `clear` may work with purely
  local state).
- Choosing or setting a default requires the CLI caller to reach the Orbit
  gateway.
- The target node must be a visible development app node.
- App-node and gateway callers are rejected before prompts or side effects.

## Related Commands

- [`node:new`](../1_node-new/node-new.md) — add a node to the fleet
- [`node:list`](../3_node-list/node-list.md) — list registered nodes
- [`node:show`](../4_node-show/node-show.md) — show node details
- [`gateway:add`](../../2_gateway/1_gateway-add/gateway-add.md) — configure local gateway
  access
- [`doctor --self`](../node-doctor.md) — verify local node context and default
  node warnings

## Technical Contract

See [`node:default` technical contract](technical/1_node-default.md).
