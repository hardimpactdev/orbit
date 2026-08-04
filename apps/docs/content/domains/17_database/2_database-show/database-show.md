# `orbit database:show <connection>`

[Back to Database commands.](../README.md)

Show one stored database connection and its target mappings.

## Usage

```bash
orbit database:show {connection} [--json]
```

## Output

By default, Orbit prints the resolved connection details and attached targets in
a terminal-friendly form. Add `--json` for the machine-readable contract.

## Related Commands

Use these commands to browse all connections or change target mappings.

- [`orbit database:list`](../1_database-list/database-list.md)
- [`orbit database:attach`](../6_database-attach/database-attach.md)

## Technical Contract

See [`database-show` technical contract](technical/1_database-show.md).
