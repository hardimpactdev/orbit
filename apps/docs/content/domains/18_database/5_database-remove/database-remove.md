# `orbit database:remove <connection>`

[Back to Database commands.](../README.md)

Remove a stored database connection and its target mappings.

## Usage

Use this command when you want to delete stored connection intent and its
mapping rows.

```bash
orbit database:remove {connection} [--force] [--json]
```

Removal is destructive and may orphan target `.env` state until doctor restore
or manual cleanup. Interactive use prompts for confirmation when `--force` is
absent; non-interactive use, including `--json`, requires `--force`.

## Technical Contract

See [`database-remove` technical contract](technical/1_database-remove.md).
