# SQL user fixture preparation

These four F13 variants exercise MySQL and PostgreSQL reader and writer users on `["app-dev+database"]`. Public CLI preparation creates a managed server, a database and an owned table. The Gateway generates the owner credentials. The request uses D's 14:05 API contract because `database:user:create` accepts passwords only in argv. No CLI or product code changes. The actual `storeUser` response contains `data.id`, so the fixture selects `$.data.id`, not the design's illustrative `$.data.operation.id`.

The fixture declares `user_password` as `generate:hex32`. Only `request.api.body.password` binds it. The canonical runner sends JSON on stdin under the operator Node's normal Gateway identity and redacts the receipt. Parity reads the generated password from the 0600 `PROOF_SECRETS` file. It uses the existing topology executor's `--argv-file` form to send a guest stdin payload. The workload helper writes a temporary 0600 Docker client environment file and removes it after use. No secret appears in an argument, public result or diagnostic. The runner owns deletion of `PROOF_SECRETS` after parity.

F9 follows `6291bb036:apps/gateway/app/Actions/DatabaseConnections/CreateDatabaseUserAction.php`: validate the managed SQL target and username ownership, check PostgreSQL replication prerequisites when requested, ensure the login, replace its grants, then record the user. These variants cover ordinary readers and writers, not replication or updating an existing user. Native phase maps observe `apply`, `grant` and `cleanup`; no execution sequence is changed.

Parity checks exact Operation, server, Connection and Node identities, native/public result equality and the projected public user privileges. An official SQL client in the owned container uses the Node's published private endpoint. An incorrect password must fail before the generated password authenticates; container loopback trust cannot prove this. MySQL grants must be exactly global USAGE plus the escaped database grant, without a grant option. PostgreSQL users must have no elevated role attributes. Readers must receive the expected INSERT permission refusal; writers insert one owned row. An owner query verifies rows. Temporary user-action files must be absent.

These are draft fixtures with no topology receipt or measured native p95. D's canonical API runner in #2314 must be present before execution; no local API transport substitutes for it. The user budget below comes from current-path Operation 6533 and the existing shared F8 policy. Replication, username conflicts, grant replacement and failure-recovery topology variants remain pending. One-shot teardown owns the servers, databases, users and volumes; persistent topologies keep them until `down`. Connection 47 and historical target-v2 cleanup are excluded.

Run checker tests with `python3 -I swamp/proof/database_user.create/parity_test.py`. They exercise local parsers and private-file handling without allocating infrastructure or admitting an Operation. All fixtures stay draft until their individual D receipts.

## Budget binding

These fixtures are stacked on #2404 at `aa7604ea772b629d074ad8587de36932defc49ae`, whose explicit workflow queue timeout is 30 seconds. Test that staged product source with the canonical #2314 runner (lane 25's branch until it reaches main). Every fixture records queue 30, strictly below its run budget. No workflow or shared Operations regression is changed here. #2404 and these drafts wait for D's receipts; no topology run is implied.

The saved Gateway succeeded-list snapshot is from 2026-11-01 14:40:14Z. Samples belong to the current SSH execution paths below. The existing policy applies `max(60, ceil(longest_seconds * 1.5 / 60) * 60)`; no current-shape success in this snapshot uses 1800. Raw values are shown separately. This caller-visible snapshot is not complete 14-day native history, and zero selected successes does not prove no historical success. These are kind-wide budgets, not engine-specific or p95 measurements.

| Kind | Current execution path | Successes | Longest seconds | Raw × 1.5 | Budget seconds |
| --- | --- | ---: | ---: | ---: | ---: |
| `database_user.create` | #2179/c4c3dd985a | 1 | 15 | 22.5 | 60 |

Earlier-shape Operations excluded: 5544.

Each phase ceiling is capped at the kind budget. Those ceilings are not additive and are not measured phase budgets. Supplementary parity calls retain their bounded transport timeout. The source classification and raw values come from `evidence/database-queue-timeouts-current-shape-fixture-budgets.json` in the execution evidence directory; the same table is recorded in #2404.
