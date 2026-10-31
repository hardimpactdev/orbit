# Database workflow tests

The database workflow owns readiness, ordered effects, retries and cleanup.
Gateway tests cover admission, authorization, stored intent and receipt
validation. Its named SQL and ACL primitives still keep credentials inside the
Gateway invocation.

Run the focused suite with a Gateway checkout containing the named primitives:

```sh
ORBIT_GATEWAY_CHECKOUT=/path/to/orbit deno test -A extensions/behaviors/operations/database/mod_test.ts extensions/behaviors/operations/database/valkey_workflow_test.ts
```

The seven Valkey cases replace the former Gateway primitive execution suite.
They invoke the real `resources/remote/database/valkey.py` from that checkout
through an in-memory protocol peer, then verify the workflow steps, receipt and
completion. No live server is contacted and no executor implementation is copied
here. Without `ORBIT_GATEWAY_CHECKOUT`, these integration cases are marked
ignored; the standalone workflow suite still runs.

Coverage includes final ACL denials, save-failure compensation, fixed refusal
codes, preserving `default`, client refusal before close, removing stale users,
changing only differing users, and recovering a lost reply without repeating the
effect. `tests/valkey-primitive.py` is a test adapter, not a deployed behavior.

`operation-database-server-close` uses bounded native RESP on the exact admitted
Node and reads only the shared declared admin password. The apply step saves a
safe outcome before record projection. Native tests use the shared harness and
scan scratch native history for private sentinels. The opt-in
`DATABASE_VALKEY_EFFECT_CANARY=1` test uses a disposable loopback-only Valkey
container, proves anonymous refusal and repeat closure, and removes its fixture.
It does not prove live worker readiness.

`database.write` has one direct `operation-database-write` workflow
(`e947ab58-40c6-405d-9c74-2c14210e4511`). Every job inherits exact
`orbit-node-ID` placement and the matching Node label. SQLite uses its pinned
sudo agent and admitted owner/path; network PDO runs only on the admitted
Gateway worker. Password, SQL, DSN and username come from declared shared
private material and stay inside the effect callback and subprocess stdin. Only
the bounded row count leaves it. PDO needs no agent pin.

The apply step emits a native outcome; projection resume reads that saved output
without repeating SQL. An interrupted apply with a running Gateway status
refuses another private read or effect: arbitrary SQL cannot be assumed
idempotent. Inspect an uncertain mutation before starting another Operation. No
family receipt, lock, claim or retry loop is added.

`write_native_test.py` uses the shared native harness and the REAL `gatewayHttp`
client against an isolated TLS fixture. It scans the entire scratch repository,
stdout and stderr for SQL, password, DSN and username; fixture SQL is injected.
`DATABASE_WRITE_EFFECT_CANARY=1` separately proves MySQL/PostgreSQL PDO and
SQLite sudo effects only on disposable fixtures. Each can run under `timeout 60`
with a driver filter. Neither proof claims fleet admission or authorization. The
pair needs credential approval, exact source/UUID/run-read grant proofs, normal
release and an owning disposable live canary before takeover.
