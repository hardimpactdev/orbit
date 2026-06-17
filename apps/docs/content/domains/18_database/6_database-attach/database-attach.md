# `orbit database:attach <connection>`

[Back to Database commands.](../README.md)

Attach a stored connection to one app, one app instance, or one workspace using
an env prefix.

## Usage

```bash
orbit database:attach {connection} (--app=<app> [--instance=<name>]|--workspace=<workspace>) [--env-prefix=DB] [--json]
```

## Technical Contract

See [`database-attach` technical contract](technical/1_database-attach.md).
