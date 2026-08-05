# `orbit database:attach <connection>`

[Back to Database commands.](../README.md)

Attach a stored connection to one instance or one workspace using
an env prefix.

## Usage

```bash
orbit database:attach {connection} (--instance=<app.instance>|--workspace=<workspace>) [--env-prefix=DB] [--json]
```

## Technical Contract

See [`database-attach` technical contract](technical/1_database-attach.md).
