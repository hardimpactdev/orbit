# Database update proof preparation

These F13 variants drive the public CLI, normal Gateway admission, native execution and terminal projection. They do not admit a native workflow directly. The managed variant creates a disposable Valkey server and ACL connection through the CLI; the Gateway generates and supplies credentials. The record-only variant registers an existing SQLite path and changes the record without creating a database file. Neither fixture contains credentials.

The topology uses the frozen F13 array grammar: `app-dev+database`. It needs the database extra role in the topology supplier. The parity command runs on the proof VM host and reaches the CLI through `PROOF_EXEC operator`; it never uses a production CLI profile. CLI follows set the guest timeout to 1,800 seconds with a 30-second outer margin, avoiding the topology executor's 60-second default. One-shot proof teardown destroys the disposable topology, including its Valkey volume and records; a persistent topology keeps those owned fixtures until `down`.

## Readiness

This is fixture and parity preparation, not a proof receipt or a product port. No topology slot is allocated. Retired pre-F13 `secrets` fields are absent. Operation 5980 predates the current workflow and is not a successful current-shape sample. The managed variant checks scratch removal after the refused index: current main skips that cleanup on failure, so parity must fail until the owning port fixes it. The result step returns `{stdout, exitCode}`; parity decodes it and reads the projected record independently.

## Legacy parity

At migration baseline `6291bb036`, `UpdateDatabaseConnectionAction` validates the complete proposed profile and attachments, calls the update hooks, commits the record, and broadcasts after commit. The managed Valkey hook validates the index against the server limit. The current native contract defers the stored patch until successful terminal projection. The seven topology cases check the successful index and record projections, failure preservation, failed-attempt scratch cleanup, managed identity refusal, explicit Node clearing and driver-dependent field clearing. Cases are based on the legacy `Api/DatabaseConnectionsTest.php` and the current `DatabaseDirectConnectionUpdateTest.php`. Existing Gateway tests separately cover encrypted proposed passwords, attached SQLite Node mismatch, disjoint patches and duplicate terminal delivery; these fixtures do not claim topology coverage of those cases yet. The lost AI-router after-commit reconvergence needs its own topology variant when that extension is available; the current Gateway explicitly refuses that configured-cache update, and this preparation does not hide that gap.

`database.sqlite_seed_abandon` has no supported caller. It gets a deletion instead of a fabricated CLI request. The historical 6599/6624 sandbox is outside these fixtures; its target-v2 cleanup belongs to the load owner. Shared seed/export scratch cleanup remains available to its existing workflows.

## Budget binding

These fixtures are stacked on #2404 at `aa7604ea772b629d074ad8587de36932defc49ae`, whose explicit workflow queue timeout is 30 seconds. Test that staged product source with the canonical #2314 runner (lane 25's branch until it reaches main). Every fixture records queue 30, strictly below its run budget. No workflow or shared Operations regression is changed here. #2404 and these drafts wait for D's receipts; no topology run is implied.

The saved Gateway succeeded-list snapshot is from 2026-11-01 14:40:14Z. Samples belong to the current SSH execution paths below. The existing policy applies `max(60, ceil(longest_seconds * 1.5 / 60) * 60)`; no current-shape success in this snapshot uses 1800. Raw values are shown separately. This caller-visible snapshot is not complete 14-day native history, and zero selected successes does not prove no historical success. These are kind-wide budgets, not engine-specific or p95 measurements.

| Kind | Current execution path | Successes | Longest seconds | Raw × 1.5 | Budget seconds |
| --- | --- | ---: | ---: | ---: | ---: |
| `database.update` | #2183/bb1e265740 | 0 | — | — | 1800 |

Each phase ceiling is capped at the kind budget. Those ceilings are not additive and are not measured phase budgets. Supplementary parity calls retain their bounded transport timeout. The source classification and raw values come from `evidence/database-queue-timeouts-current-shape-fixture-budgets.json` in the execution evidence directory; the same table is recorded in #2404.
