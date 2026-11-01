# Adopted Valkey server-close fixture

This fixture reproduces the two historical adopted servers inside a disposable `["app-dev+database"]` topology, as approved by sub-orchestrator B. It never selects services, beast or another shared Node. Product adoption scope stays unchanged. Preparation uses only public commands: create an open persistent Valkey Process, register its passwordless connection, adopt both, then remove that connection. The request closes the adopted server. No secret appears in argv, fixture JSON or observation output.

The F9 spec is `6291bb036:apps/gateway/app/Actions/DatabaseServers/CloseDatabaseServerAction.php` and the adoption/closure cases in `tests/Feature/Api/ValkeyDatabaseServersTest.php`. Legacy closure requires an active Redis server and no passwordless dependent connections or foreign default clients, closes and saves the default ACL, then records closure. Already-closed requests preserve the server. This preparation changes no phase or kind behavior.

Before admission, the hook checks the exact owned Process, named volume, private port and open default ACL through the workload Node. An anonymous official `valkey-cli` PING must work. The hook adds a small exclusive marker in that volume. After closure, parity checks the exact public Operation against its native result, the same active server and Process, the saved hashed default ACL and an anonymous PING refusal. A second public close must succeed without changing identities or the ACL file. Both scratch directories must be absent, and the marker and volume must survive.

The workload observer captures Docker and ACL data locally and emits only fixed booleans. It does not read a Gateway password or return ACL hashes. The PHP SDK omits `default_open` from CLI server output, so this fixture verifies the effective and saved access state independently rather than inventing that field. The adopted passwordless connection must be absent. Both hooks run from the staged repository root with repo-relative paths and the frozen `PROOF_*` environment.

This is draft fixture preparation, not a topology receipt or measured native p95. The current close path has no successful sample in the saved caller-visible list; the 1,800-second default below applies. Earlier-shape closes are excluded. Foreign-client refusal, failed-save compensation and unknown-access recovery still need separate topology variants; local checker tests do not prove them. D owns the execution receipt. Topology teardown owns the disposable Process, server, volume and marker; this fixture deletes none of them. Connection 47 and historical target-v2 cleanup are excluded.

Run local checker tests with `python3 -I swamp/proof/database_server.close/parity_test.py`. They allocate no infrastructure and start no Operation.

## Budget binding

The reviewed #2404 workflow source is `aa7604ea772b629d074ad8587de36932defc49ae`, with queue timeout 30.

This draft is based on its actual merge `c44798d264d0a551d0aebc7c6b838a60e8eee670`; the database workflows and catalog match that reviewed source. No receipt or runtime acceptance is inferred from the merge. Test that staged product source with the canonical #2314 runner (lane 25's branch until it reaches main). Every fixture records queue 30, strictly below its run budget. No workflow or shared Operations regression is changed here. These drafts still wait for D's individual receipts; no topology run is implied.

The saved Gateway succeeded-list snapshot is from 2026-11-01 14:40:14Z. Samples belong to the current SSH execution paths below. The existing policy applies `max(60, ceil(longest_seconds * 1.5 / 60) * 60)`; no current-shape success in this snapshot uses 1800. Raw values are shown separately. This caller-visible snapshot is not complete 14-day native history, and zero selected successes does not prove no historical success. These are kind-wide budgets, not engine-specific or p95 measurements.

| Kind | Current execution path | Successes | Longest seconds | Raw × 1.5 | Budget seconds |
| --- | --- | ---: | ---: | ---: | ---: |
| `database_server.close` | #2169/024e7e4599 | 0 | — | — | 1800 |

Earlier-shape Operations excluded: 5610, 5604.

Each phase ceiling is capped at the kind budget. Those ceilings are not additive and are not measured phase budgets. Supplementary parity calls retain their bounded transport timeout. The source classification and raw values come from `evidence/database-queue-timeouts-current-shape-fixture-budgets.json` in the execution evidence directory; the same table is recorded in #2404.
