# Instance outage re-proofs

Run these fixtures after lane 25 validates `bin/swamp-proof up`, before the source-setup and database-migration proofs for #2327. They use normal Gateway admission in an isolated app-dev topology. The F8 source change lives separately in [#2412](https://github.com/hardimpactdev/orbit/pull/2412), which supersedes #2411 and covers the Instance roots and their native children. Its owner must finish the final queue-value and budget evidence updates before the proofs run. This branch contains fixtures only.

Use this order from D's `re-proof-owed.md` plan. Select each variant explicitly; alphabetical all-variant execution has a different order.

| Order | Kind | Variant | Owed PRs | needs_caddy |
| --- | --- | --- | --- | --- |
| 1 | `instance.create` | `branch-in-use` | #2266 | false |
| 2 | `instance.create` | `setup-fails-then-skips` | #2266 | false |
| 3 | `instance.create` | `empty-environment` | #2273 | false |
| 4 | `instance.create` | `setup-macros` | #2304 | false |
| 5 | `instance.create` | `setup-redaction` | #2355, #2370 | false |
| 6 | `instance.run_steps` | `already-running` | #2335 | false |

All six variants declare `needs_caddy: false` and run before variants that need app-dev Caddy. The legacy baseline `6291bb036` supplies this boundary: `ProjectType::isWebServing()` includes only LaravelApp and SymfonyApp; `Instance::requiresRoute()` uses that rule; `NativeDevelopmentInstanceProvisioner::complete()` calls `completeWithoutRoute()` for the other types. Current Project documentation likewise gives `node-package` only an explicit Route. These fixtures already use `node-package` and never create an explicit Route. Each receipt limitation names the unexercised Route phase. Parity refuses an unexpected Route on the Instance.

The empty-environment variant still expects successful creation after accepting the empty stored map. It is not converted into a refusal to avoid Caddy. Its no-Caddy classification follows the legacy no-Route Project type, as do the successful default-Instance preparations for branch-in-use and already-running. This checkpoint contains no `needs_caddy: true` variant and proves no Route publication. A future web Project variant must declare `true` and wait for C’s app-dev converge fix; it must not use a no-Route substitution.

`instance:setup` admits `instance.run_steps`; there is no separate `instance.setup` kind. Its fixture first creates an Instance without setup commands, then adds a setup command and requests the list normally. The Gateway marks the Operation running before the workflow's first read. The workload marker and terminal projection prove the list executed; the fixture does not inject state or claim an independent trace of that first read.

Each fixture gets a fresh one-commit HTTPS repository on the topology. No GitHub credential is involved. The #2266 fixtures declare `github_token_path: not_exercised` in `limitations`, which the harness copies into its receipt. Branch refusal and skip-after-failure can be proved; the stateless GitHub token format remains outside this topology's coverage.

The redaction fixture generates disposable random values on the workload. Its first setup command writes a checkout-root `.env` for the outage runner's existing redaction reader. The failing command emits a double-quoted tab-escaped value, an unquoted value followed immediately by `#`, invalid UTF-8 inserted into those values, partial values, and a stdout canary. A later marker must remain absent. Parity reads only hashes of eight-byte windows from those private values. It checks the selected command output and the public Operation for matching fragments, requires a redaction marker, rejects command stdout fields, and counts stderr in UTF-8 bytes against 4096. No private value returns to the host or appears in argv. This does not claim a full datastore scan.

The macro fixture sends literal `@orbit/ci-caches` to the Gateway. The canonical supplier must expand it before execution. The repository contains a fixture cache-import tool, so the real macro can run without external caches. A following command must execute and retain a bounded failure tail.

## Source checks before a run

Stage main, plus at most one small F8 compliance PR as D permits. These selectors target the expanded outage workflow, before #2327's grouped setup rewrite. Do not use #2327 to close the outage rows. Recheck selectors if a required fix changes the workflow.

B’s worker-wait values are `queueTimeout: 10` for create and `30` for setup, destroy and their native children. Both are strictly below the shared 60-second minimum run budget. The fixtures expect those source values; they do not add them themselves. The queue timeout is separate from the run budget. Create fixtures use the PLAN F8 60-second budget. The setup fixture uses 120 seconds, derived with the existing `OperationRunBudgets` whole-minute rounding and 60-second floor, not a new fixed Gateway budget. The normal `orbit operation:list --kind=instance.run_steps --status=succeeded` query returned 14 successes, with one after #2335’s running-list change: Operation 6798 (started 2026-11-01 14:03:58Z, finished 14:04:40Z). Its retained native run `e90c20c8-f551-476e-8b2a-50814e033a83` took 42.499 seconds (14:03:59.528Z–14:04:42.027Z), so the existing formula `max(60, ceil(42.499 × 1.5 / 60) × 60)` gives 120 seconds. The full public successful-Operation sample has 14 rows and a longest started-to-finished time of 72 seconds; its rounded derivation also gives 120 seconds. The current-shape native sample has one retained success, not a p95 or a fleet-wide maximum; it predates #2391’s Vite permission relaxation, which leaves the workflow graph and step-list shape unchanged. D has been asked for current-shape destroy timing. Setup and destroy retain the shared runtime budget policy; there is no new fixed kind budget or workflow deadline field. A destroy derivation requires bound native evidence, so older public destroy successes are not borrowed as that evidence. The proof must meet its budget; there is no runner exception.

The fixture contract added `limitations` on 2026-11-01 at 14:08Z. Lane 25’s runner supports it in #2314 at `9a438741`, copying it into the receipt. No receipt is claimed by local tests.

Run fixture tests without a topology:

```sh
python3 -m unittest discover -s swamp/proof/instance.create -p '*test.py'
ruff check swamp/proof/instance.create/outage_parity.py swamp/proof/instance.create/outage_test.py swamp/proof/instance.create/repository/setup-redaction swamp/proof/instance.create/repository/bin/ci-cache-ref
```
