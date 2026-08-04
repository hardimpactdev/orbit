# `orbit database:add-user <connection>`

[Back to Database commands.](../README.md)

Create or update a MySQL database and user through an existing managed MySQL
process, then create or update the reusable database connection record.

## Usage

```bash
orbit database:add-user {connection} --service=<process> --database=<name> --username=<user> --password=<password> [--node=<node>] [--json]
```

Use this command when you already have a process-owned MySQL service and need a
database connection record for an instance or workspace.

## Arguments and options

| Input | Meaning |
| --- | --- |
| `connection` | Database connection slug to create or update. |
| `--service` | Existing managed MySQL process name. |
| `--database` | MySQL database name. |
| `--username` | MySQL username. |
| `--password` | MySQL password to store encrypted in gateway state. |
| `--node` | Node that owns the managed MySQL process. Required when process names are ambiguous. |
| `--json` | Render JSON. |

## Behavior Summary

`--service` is the name of an existing process-owned managed MySQL service,
created with a command such as this:

```bash
orbit process:add mysql8 --service=mysql --runtime=docker --version=8.3 --node=beast
```

When the process exists and is a Docker managed MySQL service, Orbit:

1. Runs the typed request gateway-locally when the gateway owns the process, or
   sends it through authenticated Agent push over WireGuard when a non-gateway
   node owns the process, then executes inside the MySQL container.
2. Creates the database if missing.
3. Creates or updates the MySQL user and password.
4. Grants that user privileges on the named database.
5. Creates or updates the database connection record named by `{connection}`.

The response never includes the password. The stored connection keeps the
password encrypted in gateway state for `.env` restore and query execution.

## Requirements

The caller needs `database:write` on the node that owns the MySQL process. A
non-gateway owning node must be Agent-eligible and reachable through Agent push.

## Output Summary

Human output confirms the user and connection. JSON output returns the
connection entity without secret credentials.

## Examples

```bash
orbit database:add-user dlf-leden --service=mysql8 --node=beast --database=dlf_leden --username=dlf_leden --password='...'
```

## Related

- [`orbit process:add`](../../7_process/1_process-add/process-add.md)
- [`orbit database:attach`](../6_database-attach/database-attach.md)
- [`doctor --family=database_connection`](../database-doctor.md)

## Technical Contract

See [`database:add-user` technical contract](technical/1_database-add-user.md).

The current implementation is intentionally limited to Docker runtime managed
MySQL processes. If the matching process uses Docker Swarm, the command fails
before mutation until Orbit has a safe service exec primitive.
