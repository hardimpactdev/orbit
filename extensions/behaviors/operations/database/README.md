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
