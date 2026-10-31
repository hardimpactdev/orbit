# Database private material

Database direct workflows use the shared direct-Operation private-material API. This family adds no endpoint, publisher or hydrator. The Gateway records intent and projects results; Swamp executes the system commands.

Public intent contains only typed resource identity, admitted paths, driver,
container identity. Passwords, dynamic SQL,
environment, ACL text and Valkey payloads stay in the encrypted
`DatabaseExecutionIntent`. References contain the existing `database.intent`
source and the intent ID. The existing source checks the Operation's target and
Node before resolving an explicitly protected parameter.

| Kind                           | Native steps                                                         | Private material                                        |
| ------------------------------ | -------------------------------------------------------------------- | ------------------------------------------------------- |
| `database_server.create`       | Container converge, readiness, optional ACL and restart              | Environment, root password, ACL, Valkey payload         |
| `database_server.destroy`      | Remove the admitted Process                                          | None                                                    |
| `database_server.close`        | Close the Valkey default user                                        | Root password, Valkey payload                           |
| `database.create`              | Ownership preflight, create database, owner and grants               | Root password, SQL, Valkey payload                      |
| `database.destroy`             | Ownership preflight, drop database and unshared user                 | Root password, SQL, Valkey payload                      |
| `database_user.create`         | Apply the admitted SQL login and grants                              | Root password, SQL                                      |
| `database.write`               | One SQLite or PDO write                                              | SQL, connection password for PDO                        |
| `database.clone`               | Export on source Node, transfer artifact, install on target, cleanup | Root password and SQL for server databases              |
| `database.destroy_owned`       | Remove admitted SQLite files or owned server databases               | Root password, SQL, Valkey payload for server databases |
| `database.sqlite_export`       | Snapshot on admitted source Node                                     | None                                                    |
| `database.sqlite_cleanup`      | Remove admitted snapshot on its owning Node                          | None                                                    |
| `database.sqlite_seed`         | Prepare source and target, transfer, install, cleanup                | None                                                    |
| `database.sqlite_seed_abandon` | Settle target attempt, cleanup source                                | None                                                    |

Cross-Node steps form native workflow jobs. They do not create Gateway child
Operations or transfer database bytes through the Gateway. Each local step is
placed on its admitted Node. Node 209 can prove local disposable actions only.

`user_create_action.ts` supplies the SQL-user action. Its strict public schema
refuses raw private fields, arbitrary clients and command names. The action
receives private values inside the shared callback, invokes only the pinned
local `database.server-admin` through the existing shared sudo launcher, and
returns only `backend: active` or a closed error code. It discards SQL output
and replaces parser, transport and execution diagnostics with a fixed message.
The callback returns void; only a separately validated safe outcome leaves it.
PostgreSQL WAL readiness runs locally before mutation. Its admitted SQL checks
and creates a new role in one atomic block; an existing role is accepted only
when its SCRAM verifier exactly matches that immutable intent, or the Gateway
already recorded ownership. This permits replay after SQL succeeded but its
native outcome was lost, without taking over a foreign role. No private value is
returned as a native argument, resource or result.

The Gateway projects the recorded `upsert_user` after native success. Its stored
password stays in the encrypted intent and existing DatabaseUser store; the
worker does not return a copy. This kind needs no secret-output write.

The native `create` step writes only a workflow-scoped safe outcome. The native
`project` step consumes `steps.create.outputs.outcome`, so resuming a failed
projection does not repeat SQL or reclassify the newly created role as foreign.
There is no latest-resource lookup across runs. If the SQL step itself has an
uncertain outcome before its native output is saved, the PostgreSQL role guard
and grant replacement permit safe replay; native resume cannot make SQL exactly
once. The native output's `vary: [run_id]` dimension keeps each run's outcome
separate while the behavior keeps its per-Node execution lock. Native workflow
lifetime and version collection remain enabled. A later run cannot evict an
earlier run's outcome before its projection resumes.

Before takeover, prove exact-run and caller-Node binding, refusal after terminal
completion, no-store responses, denied foreign Node/package/task access, and
absence of raw values in Operation projections, Activity, logs and native
history. Use disposable credentials and a disposable database. Credential
transport adoption remains an orchestrator-reviewed change.

The direct-only `database_user.create` declaration defaults to direct and
refuses an explicit polled selection. Its old execution registration, receipt
completion and Gateway SQL preflights are removed together. Rollback reverts the
kind PR. The workflow targets `orbit-node-<admitted Node>` on every Node,
including 209, and requires the admitted Node's string `labels.node`. A foreign
Node never executes the local action.

`user_create_native_test.py` uses the shared `tests/native_harness.py` and
exercises privacy success and failures in the native DAG with fake HTTP and native effect injection. `local_sql_effect_test.ts` exercises the real native MySQL create, clone and destroy actions on an owned disposable container. `write_native_test.py`, `clone_native_test.py` and the server fixtures use the real `gatewayHttp` client and scan their scratch repositories for private sentinels. Opt-in effect tests prove system behavior on disposable resources; they do not prove live fleet placement or grants.
