# `orbit database:update <connection>`

[Back to Database commands.](../README.md)

Update a stored database connection record without changing its target mappings.

## Usage

```bash
orbit database:update {connection} [--slug=<slug>] [--driver=<mysql|pgsql|sqlite>] [--host=<host>] [--port=<port>] [--database=<name>] [--path=<path>] [--username=<username>] [--password=<password>] [--clear-password] [--node=<node>] [--json]
```

## Technical Contract

See [`database-update` technical contract](technical/1_database-update.md).
