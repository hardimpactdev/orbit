# Direct Vault collection operations

`vault.sync` and `vault.setup` run the collection program locally on the exact admitted Node
worker. Teardown keeps its old execution path until its owning slice. Shared private-material supplies the encrypted admission snapshot only
inside an effect-only callback; credentials use protected stdin, never workflow
arguments, native resources or logs. Public metadata is bounded and refuses
master password, client secret and hidden-field echoes before any public
request.

The job targets orbit-node-ID with the matching string Node label. On the
admitted Gateway Node14, it uses the existing public CA file
`/usr/local/share/ca-certificates/orbit-root.crt`, matching the shipped Conn
workflow and the verified worker TLS configuration. Injected native fixtures override
only this file path with `/dev/null`. A native
behavior lock serializes the Gateway's collection operations. HTTP deadlines and
the original900-second overall program deadline remain. Existing source
matching, crypto, duplicate/orphan adoption, Orbit-only TLS and foreign-item
preservation remain in the ported collection program.

Shared Gateway globals reject an empty CA path, so TLS cannot fall back to the
system trust store. The production sync canary records the current collection
before admission and compares every item afterwards. Items without `orbit.source`
are preserved; only Orbit-owned orphan entries may be deleted in this canary.
The preflight refuses duplicate-source or deleted-but-wanted entries whose
reconciliation would need another deletion.

The owning Gateway PRs switch sync and setup in separate per-kind slices and remove each old run/receipt
contract and tests. Rollback is revert. Credential approval, the exact loaded
workflow and installed Gateway-token run/read grant are required before writer
release. The real HTTP dev proof below supplements the authorized production
sync canary; injected transport regressions do not establish that proof. No production collection,
credential, grant or writer changes during preparation.

The shared secrets source is merged; family credential approval and runtime
readiness remain separate. If the direct writer needs released code, merge after
approval, verify auto-release/source load, then canary on a disposable or test
target within minutes. Revert at once on failure. The first Vault canary must use
the real `gatewayHttp` client and scan the complete scratch tree and captured
logs for private values. Injected Http fixtures alone do not satisfy that proof.

The polled integrations schedule does not discover `vault.sync`. Its executor registration is removed in the same cutover; rollback reverts the owning PR.

The polled integrations schedule does not discover `vault.setup`. Its executor registration is removed in the same cutover; rollback reverts the owning PR.

Native regressions reuse `tests/native_harness.py` for scratch setup, CLI calls
and secret scans. Vault fixtures retain injection, placement and domain checks.

The real HTTP dev canary uses the same shared harness with an isolated Gateway
SQLite database, a disposable Vaultwarden backend, and a placed container worker
running the pinned native runtime with Python and libcrypto. Only the worker's
container has the `vault-fixture.orbit` hostname. A disposable CA signs both TLS
endpoints; certificate and hostname verification remain enabled. No fleet DNS,
hosts file, worker, credential, production Vault configuration or ledger changes.
This dev proof does not establish the fleet workflow grant or loaded method.

Build the dev dependency and run the canary from this checkout:

```sh
docker build -t orbit-lane23-vault-worker:1602a617 -f extensions/behaviors/operations/vault/tests/Dockerfile .
SWAMP_BIN=/path/to/pinned/swamp VAULT_TEST_GATEWAY_ROOT=/path/to/orbit/apps/gateway python3 extensions/behaviors/operations/vault/real_http_native_test.py
```

Set `VAULT_TEST_ACTION=setup` to prove first setup with the same fixture. Its
Gateway starts without Vault configuration, admits the real setup action, and
stores the configured account only after the placed native run succeeds. All
private inputs and scan needles stay in the PHP fixture's memory.

The PHP fixture generates private values and retains its scan needles in memory.
It scans the whole scratch tree, native history and public Operation before
returning safe evidence; Docker containers and scratch are removed on completion.
Vault completion changes global settings and the item ledger, so a test
credential against the production Gateway is not an isolated target. The
orchestrator-authorized production sync uses the existing configured collection
and credentials, with foreign-item preservation verified before and after.
Setup and teardown require the same isolated dev boundary.
