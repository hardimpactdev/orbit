# `orbit database:query <target>`

[Back to Database commands.](../README.md)

Run audited SQL against a stored database connection.

## Usage

Use this command when you want to run audited SQL through a stored target
mapping instead of reading `.env` files directly.

```bash
orbit database:query {target} --sql=<sql> [--connection=<slug>] [--limit=50] [--full] [--write] [--json]
```

`target` resolves an app or workspace context. `--connection` is optional when
the target has exactly one attached mapping for the selected prefix.

## Technical Contract

See [`database-query` technical contract](technical/1_database-query.md).
