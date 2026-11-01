# Database lifecycle fixture preparation

These F13 fixtures prepare `database.create`, `database_server.create` and `database_server.destroy` on `["app-dev+database"]`. Each kind has MySQL, PostgreSQL and Valkey variants. They use the real CLI through the topology operator, top-level Operation IDs, captured resource IDs and partial Operation inputs. Managed creation generates credentials in the Gateway. No password, key or secret appears in fixture arguments. They do not allocate a topology or change a product kind.

## Readiness

This PR prepares parity checks. It carries no topology receipt or measured native p95. The fixture budgets below use current-execution-path public Operation timing and the existing shared F8 policy; they are not new phase measurements. No `secrets` or `target_setup` fields use the retired pre-F13 format.

The database extra role and real Swamp Gateway installation must work in the proof topology before these fixtures can run. One-shot topology teardown owns all created servers, connections, volumes and marker files. Persistent topologies retain them until `down`. Destruction deliberately preserves its named volume; the parity check must not remove it to make cleanup look complete. Production Connection 47 and historical 6624 target-v2 remain outside these fixtures; the load owner retains their runtime custody.

## Legacy mapping and checks

The spec is migration baseline `6291bb036`, not the earlier Swamp ports. Gateway files are under `apps/gateway/app/Actions/DatabaseConnections/` and `DatabaseServers/`; legacy cases are in `tests/Feature/Api/DatabaseServersTest.php` and `ValkeyDatabaseServersTest.php`.

| Kind | Legacy sequence | Prepared parity |
| --- | --- | --- |
| `database.create` | Admit names and active server; check inventory; create credentials and database/grants (or validate index and create ACL user); record and broadcast; attach when Instance-owned | Connection/server identity, generated credential presence, SQL owner identity and current database through an independent normal query; Valkey hashed GET-only ACL for exactly `proof:*`, with no channel grants; duplicate slug refusal and in-use server refusal without record changes |
| `database_server.create` | Reserve server and generated admin password; create its Node Docker Process; wait for admin readiness; SQL initialization-variable removal and readiness again; activate. Valkey writes its hashed ACL before Process/readiness | Active server/Process identity, running owned Docker container, correct image/data mount/private port bindings, initialization secret absent from container environment, exact retry retains resource IDs; Valkey admin ACL file present and hashed |
| `database_server.destroy` | Refuse connections; ordinary Process removal; delete server record; keep named data volume | Public server absence and empty server list membership, all containers for the admitted Process gone, named volume and pre-request marker retained |

The fixtures map current native phase names without changing their order. Current creation splits SQL effects across child steps; its end-to-end parity verifies the resulting owner/database through the public query path. SQL phase timings currently cover probes/user authentication but omit the per-database create/grant fan-out. Destroy times its plan but omits its dynamic remove fan-out. Both need complete phase timing maps in the owning rebuild before a receipt can claim F9/F8 coverage. Current server creation's `initial`/`scrub` children include readiness. These mappings are observations, not approval of those shapes. Server creation checks an actual running container; only the separate SQL `database.create` variants independently authenticate through generated client credentials. Valkey parity checks the persisted hashed ACL, not client authentication: the public CLI has no Valkey command execution request, and parity does not read credentials from the Gateway. Its explicit GET rule avoids ACL category expansion; it accepts normalization of redundant denials and rejects broader key, channel or command grants.

Instance-owned test databases, PostgreSQL parallel pools and locale, adoption, failed-readiness recovery, active-Valkey reconciliation, foreign-container refusal and failure-preserves-record behavior still need topology variants. Their existing Gateway/native tests do not count as execution of these fixtures. SQLite registration is a record-only API operation, not `database.create`, and is not represented as a native lifecycle run. `database.sqlite_seed` is **kept for clone/transfer F9**.

## How checks run

Parity runs on the proof VM host with the staged worktree as cwd and reads only the five allowed `PROOF_*` variables. Every CLI or workload read uses `PROOF_EXEC`; no production CLI profile or SSH key is copied. CLI follows set the guest timeout to 1,800 seconds with a 30-second outer margin, avoiding the topology executor's 60-second default. `observe.py` runs on `app-dev` through that executor as sudo Python. It captures Docker/private ACL data locally and emits only fixed booleans and selected public metadata. It never echoes Docker diagnostics, environment values, ACL hashes or credentials.

Destroy's `before_request` and parity both run from the staged repository root, with repo-relative script paths and no fallback. After the normal server-create prepare call, it verifies the owned container/volume and writes a small run-specific marker into that disposable named volume. Parity checks the exact marker after normal destruction. It never writes Gateway or Swamp state. A missing marker fails before admission; no deletion or silent replacement repairs the evidence.

Local checker tests inject wrong targets, native outcomes, changing transport request IDs, unsafe bindings, residual secrets, missing ownership, ACL mistakes, leftover containers and lost volume contents. They test the checker; they are not an F13 receipt. Run them with `python3 -I swamp/proof/database-lifecycle/parity_test.py`.

## Budget binding

The reviewed #2404 workflow source is `aa7604ea772b629d074ad8587de36932defc49ae`, with queue timeout 30.

This draft is based on its actual merge `c44798d264d0a551d0aebc7c6b838a60e8eee670`; the database workflows and catalog match that reviewed source. No receipt or runtime acceptance is inferred from the merge. Test that staged product source with the canonical #2314 runner (lane 25's branch until it reaches main). Every fixture records queue 30, strictly below its run budget. No workflow or shared Operations regression is changed here. These drafts still wait for D's individual receipts; no topology run is implied.

The saved Gateway succeeded-list snapshot is from 2026-11-01 14:40:14Z. Samples belong to the current SSH execution paths below. The existing policy applies `max(60, ceil(longest_seconds * 1.5 / 60) * 60)`; no current-shape success in this snapshot uses 1800. Raw values are shown separately. This caller-visible snapshot is not complete 14-day native history, and zero selected successes does not prove no historical success. These are kind-wide budgets, not engine-specific or p95 measurements.

| Kind | Current execution path | Successes | Longest seconds | Raw × 1.5 | Budget seconds |
| --- | --- | ---: | ---: | ---: | ---: |
| `database.create` | #2150/c0da681743 (#2208 extends accepted kind/vault pattern only, no ordinary-create effect change) | 2 | 77 | 115.5 | 120 |
| `database_server.create` | #2106/03af844b07 | 0 | — | — | 1800 |
| `database_server.destroy` | #2116/8102c7fc1f | 0 | — | — | 1800 |

Earlier-shape Operations excluded: 1162.

Each phase ceiling is capped at the kind budget. Those ceilings are not additive and are not measured phase budgets. Supplementary parity calls retain their bounded transport timeout. The source classification and raw values come from `evidence/database-queue-timeouts-current-shape-fixture-budgets.json` in the execution evidence directory; the same table is recorded in #2404.
