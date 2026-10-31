# Database workflow tests

The database workflow owns readiness, ordered effects, retries and cleanup.
Gateway tests cover admission, authorization, stored intent and receipt
validation. Its named SQL and ACL primitives still keep credentials inside the
Gateway invocation.

Run the focused suite with a Gateway checkout containing the named primitives:

```sh
ORBIT_GATEWAY_CHECKOUT=/path/to/orbit deno test -A extensions/behaviors/operations/database/mod_test.ts extensions/behaviors/operations/database/valkey_workflow_test.ts
```

The retained Valkey removal and server-convergence cases exercise the remaining
Gateway primitives. They invoke the real `resources/remote/database/valkey.py`
from that checkout through an in-memory protocol peer, then verify the workflow
steps, receipt and completion. No live server is contacted and no executor
implementation is copied here. Without `ORBIT_GATEWAY_CHECKOUT`, these
integration cases are marked ignored; the standalone workflow suite still runs.

Coverage includes removal compensation, preserving `default`, removing stale
users and changing only differing users. Direct create and close tests cover
their native effects separately. `tests/valkey-primitive.py` is a test adapter,
not a deployed behavior.

`operation-database-server-close` uses bounded native RESP on the exact admitted
Node and reads only the shared declared admin password. The apply step saves a
safe outcome before record projection. Native tests use the shared harness and
scan scratch native history for private sentinels. The opt-in
`DATABASE_VALKEY_EFFECT_CANARY=1` test uses a disposable loopback-only Valkey
container, proves anonymous refusal and repeat closure, and removes its fixture.
It does not prove live worker readiness.

Managed `database.create` uses one direct-only workflow on the admitted Node.
Names, pool bounds and ownership are checked locally before SQL. The shared
private-material callback supplies only admin and reserved owner credentials.
PostgreSQL resumes verify the stored SCRAM password, authenticate the same role
and preserve every existing database; MySQL resumes require that role's exact
grants. Valkey SAVE failure retains the owned user for explicit convergence. No
SQL, password or verifier is emitted in native resources. Gateway reservations
stay available for identical retries.

Before Gateway cutover, load the exact `operation-database-create` UUID
`b77987fa-6c2a-4ba7-9b35-c1739ef369b1` normally and prove the shared Gateway
principal has only run/read on it. After the credential-approved merge releases,
use freshly pinned, exact Node9 or Node17 workers and owned disposable MySQL,
PostgreSQL and Valkey servers. Prove Gateway admission/run binding before the
first read, database/role/ACL creation, identical native replay, foreign-owner
refusal and secret-free public/native histories. Retire all fixture containers,
records and directories. Revert the kind pair immediately on a code failure.
Never use production services-valkey.

Managed MySQL clone reset and copy use the direct-only
`operation-database-mysql-clone` workflow, UUID
`d3021397-57b1-4756-b47e-e9521d27ccde`. The existing Instance parent's database
child selects `database.mysql_clone`; SQLite retains `database.clone`. The Node
checks the reserved owner's password and every existing target's grants before
DROP, recreates only the admitted primary/test databases, and copies the fixed
source through the pinned agent. It never resets the source. No reset plan or
live clone inventory probe remains in the Gateway. Before release, confirm no
old active MySQL `database.clone` intents remain; do not interrupt or gate their
parents. Both new workflows need exact existing-principal run/read proof before
the Gateway release. PostgreSQL SQL literals preserve backslashes with standard
conforming strings; MySQL literals retain their dialect's escaping.
