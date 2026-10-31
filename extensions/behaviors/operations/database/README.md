# Database workflow tests

Database workflows own preparation, effects, retries and cleanup. Gateway tests
cover admission, authorization, encrypted intent and bounded record projection.
SQL, SQLite and Valkey actions run directly in Swamp; no workflow invokes an
agent action or a Gateway execution primitive.

Run focused native action tests with Deno:

```sh
deno test -A extensions/behaviors/operations/database/connection_update_test.ts
```

`database.update` uses `operation-database-connection-update` on the exact
admitted Node. A managed Valkey index change reads `CONFIG GET databases` inside
the private-material callback. Other connection updates require no server read.
The step returns only a bounded outcome; the Gateway applies the encrypted
proposed attributes after native success. Refusal preserves the old record.
Tests cover bounds, repeated reads, closed sockets and discarded diagnostics.

`operation-database-server-close` uses bounded native RESP on the exact admitted
Node and reads only the shared declared admin password. The apply step saves a
safe outcome before record projection. Native tests use the shared harness and
scan scratch native history for private sentinels. The opt-in
`DATABASE_VALKEY_EFFECT_CANARY=1` test uses a disposable loopback-only Valkey
container, proves anonymous refusal and repeat closure, and removes its fixture.
It does not prove live worker readiness.

`database.write` has one direct `operation-database-write` workflow
(`e947ab58-40c6-405d-9c74-2c14210e4511`). Every job inherits exact
`orbit-node-ID` placement and the matching Node label. SQLite uses its fixed
native program with the admitted owner/path; network PDO runs only on the
admitted Gateway worker. Password, SQL, DSN and username come from declared
shared private material and stay inside the effect callback and subprocess
stdin. Only the bounded row count leaves it. PDO needs no agent pin.

The apply step emits a native outcome; projection resume reads that saved output
without repeating SQL. An interrupted apply with a running Gateway status
refuses another private read or effect: arbitrary SQL cannot be assumed
idempotent. Validation or private-read failure before invocation projects failed
with `database.write_outcome_unknown`; after invocation an exception remains
uncertain. SQL is limited to 16,384 characters and MySQL multi-statements are
disabled. Inspect an uncertain mutation before starting another Operation. No
family receipt, lock, claim or retry loop is added.

`write_native_test.py` uses the shared native harness and the REAL `gatewayHttp`
client against an isolated TLS fixture. It scans the entire scratch repository,
stdout and stderr for SQL, password, DSN and username; fixture SQL is injected.
`DATABASE_WRITE_EFFECT_CANARY=1` separately proves MySQL/PostgreSQL PDO and
SQLite sudo effects only on disposable fixtures. Each can run under `timeout 60`
with a driver filter. Neither proof claims fleet admission or authorization.
These tests use disposable resources and do not claim a live fleet canary.

`database_server.destroy` runs the fixed `operation-database-server-destroy`
workflow (`2fb743f6-7bd7-4d0a-8a61-168d30da8a44`) on exact orbit-node-ID
placement. It removes only the admitted owned Process and preserves its named
data volume. The Gateway refuses servers that hold databases, records removal
intent, and deletes records only after the native absent result. No private
material or family relay is needed. Verify the released workflow with a
disposable-server canary. Never use a production database server for the proof.

`database_server.create` is direct-only. The fixed
`operation-database-server-create` workflow
(`f497279b-80cd-4dc8-82bb-1a6622d977e4`) provisions on exact `orbit-node-ID`
placement. Its private callback supplies only the admitted admin password,
initializer environment, ACL file and user policy. The Gateway records
server/Process intent and projects a closed result; it runs no readiness or
adoption probes. The Node authenticates adopted Valkey state before any write,
initializes SQL once, proves TCP readiness, removes initializer variables and
proves readiness again. Retries preserve the named volume. An old open Valkey
repair refuses a closed or unknown live default-user policy before writing its
ACL; it never restores nopass after a close.

`server_create_native_test.py` uses `tests/native_harness.py` and REAL
`gatewayHttp` for whole-scratch secret scans and lost-projection resume. The
opt-in `DATABASE_SERVER_EFFECT_CANARY=1` Deno test creates only uniquely named
loopback disposable MySQL/PostgreSQL/Valkey servers, proves provisioning,
initializer scrub and replay, and retires its containers/volumes. Run each
driver with `--filter mysql`, `pgsql` or `redis` under `timeout 60`.

After merge and normal hot reload, verify with an owned disposable server on an
exact worker. Keep credentials inside the private callback. Never use production
services-valkey for this proof.

All database effects run native system commands. SQL uses `local_sql.ts`; SQLite
uses the bundled `sqlite_program.ts` through `sqlite_local.ts`; Valkey uses the
fixed RESP and ACL actions. Server Docker work uses the canonical
`processes-schedules/process-system.ts`. No database workflow calls an agent
action or requires an agent binary pin. The agent retains only database
observations.

The remaining parents (`database.create`, `database.clone`, `database.destroy`,
`database.destroy_owned`, SQLite export/seed/abandon) each run one native DAG.
Remote SQLite data travels as a versioned native file artifact, never a JSON
resource. The Gateway records admitted placement and performs bounded result
projection. It has no child operations, polled executor, completion receipts or
synchronous SQL teardown.

If restoring anonymous access after ACL SAVE fails, closure reports only
`database.server_restore_failed` and `default_open: unknown`. The Gateway stores
unknown access, marks the server failed, and emits one critical alert. Private
diagnostics remain inside the effect callback.
