# `orbit database:remove <connection>`

[Back to Database commands.](../README.md)

Remove a stored database connection and its target mappings.

## Usage

Use this command when you want to delete stored connection intent and its
mapping rows.

```bash
orbit database:remove {connection} --force [--json]
```

`--force` is required because removal is destructive and may orphan target `.env`
state until doctor restore or manual cleanup.

## Technical Contract

See [`database-remove` technical contract](technical/1_database-remove.md).
