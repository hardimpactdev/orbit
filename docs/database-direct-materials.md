# Database private material

Database direct workflows use lane 23's shared direct-Operation private-material
API. The SQL-user workflow uses the reviewed Orbit #1743 / Swamp #206 contract;
those shared PRs require orchestrator credential approval before deployment.
This family adds no endpoint, publisher or hydrator. Each kind PR includes its
switch and replaced-path deletion after a safe canary.

Public intent contains only typed resource identity, admitted paths, driver,
container identity and the admitted agent digest. Passwords, dynamic SQL,
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
exercises privacy success and failures in the native DAG with fake HTTP and
agent effects. The opt-in `user_create_effect_native_test.py` runs the actual
Laravel Gateway, shared TLS native start, private read, sudo agent and encrypted
user projection against an owned, network-isolated disposable PostgreSQL
container on Beast Node 9. It checks unbound 409 before submission, binding
before successful reads, terminal private-read refusal and every scratch file
for the synthetic credential, then removes its container and services.

The manual test uses local execution and an auth-none loopback scratch serve; it
does not establish fleet worker placement, live credential grants or package
isolation. The installed digest must match the recorded canary pin. Set
`DATABASE_USER_EFFECT_CANARY=beast-9`, `DATABASE_USER_GATEWAY_ROOT` to the
matching Orbit Gateway checkout, `DATABASE_USER_EXPECTED_AGENT_SHA256` to the
independently matched Gateway/Node pin and `SWAMP_BIN` to the repository's
pinned native binary before running that test.
