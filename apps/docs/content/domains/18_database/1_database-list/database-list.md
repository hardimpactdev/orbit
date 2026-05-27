# `orbit database:list`

[Back to Database commands.](../README.md)

List stored database connections and their app or workspace mappings.

## Usage

```bash
orbit database:list [--app=<app>] [--workspace=<workspace>] [--node=<node>] [--json]
```

## What Happens

Use this command to read database connection records stored by the gateway and
their target mappings. Optional app, workspace, or node filters narrow the
returned set; the command does not inspect live databases or parse target
`.env` files.

## Output

By default, Orbit prints the matching connections and their attached targets in
a terminal-friendly form. Add `--json` for the machine-readable contract.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the gateway.
- The current node identity is authorized to inspect the selected app, workspace, or node scope.

## Related Commands

Use these commands when you want one connection in detail or need drift repair.

- [`orbit database:show`](../2_database-show/database-show.md)
- [`doctor --family=database_connection`](../database-doctor.md)

## Technical Contract

See [`database-list` technical contract](technical/1_database-list.md).
