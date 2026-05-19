# `orbit database:add <slug>`

[Back to Database commands.](../README.md)

Create a reusable database connection record.

## Usage

Use this command when you want to register a new reusable connection profile.

```bash
orbit database:add {slug} --driver=<mysql|pgsql|sqlite> [--host=<host>] [--port=<port>] [--database=<name>] [--path=<path>] [--username=<username>] [--password=<password>] [--node=<node>] [--json]
```

`sqlite` uses `--path` and may optionally declare `--node` for the node that
owns the file. `mysql` and `pgsql` use network fields and may omit `--node`
when the connection is not node-bound.

## Technical Contract

See [`database-add` technical contract](technical/1_database-add.md).
