# Instance outage re-proofs

Run these fixtures after lane 25 validates `bin/swamp-proof up`, before the source-setup and database-migration proofs for #2327. They use normal Gateway admission in an isolated app-dev topology. They do not change product code.

Use this order from D's `re-proof-owed.md` plan. Select each variant explicitly; alphabetical all-variant execution has a different order.

| Order | Kind | Variant | Owed PRs |
| --- | --- | --- | --- |
| 1 | `instance.create` | `branch-in-use` | #2266 |
| 2 | `instance.create` | `setup-fails-then-skips` | #2266 |
| 3 | `instance.create` | `empty-environment` | #2273 |
| 4 | `instance.create` | `setup-macros` | #2304 |
| 5 | `instance.create` | `setup-redaction` | #2355, #2370 |
| 6 | `instance.run_steps` | `already-running` | #2335 |

`instance:setup` admits `instance.run_steps`; there is no separate `instance.setup` kind. Its fixture first creates an Instance without setup commands, then adds a setup command and requests the list normally. The Gateway marks the Operation running before the workflow's first read. The workload marker and terminal projection prove the list executed; the fixture does not inject state or claim an independent trace of that first read.

Each fixture gets a fresh one-commit HTTPS repository on the topology. No GitHub credential is involved. The #2266 fixtures declare `github_token_path: not_exercised` in `limitations`, which the harness copies into its receipt. Branch refusal and skip-after-failure can be proved; the stateless GitHub token format remains outside this topology's coverage.

The redaction fixture generates disposable random values on the workload. Its first setup command writes a checkout-root `.env` for the outage runner's existing redaction reader. The failing command emits a double-quoted tab-escaped value, an unquoted value followed immediately by `#`, invalid UTF-8 inserted into those values, partial values, and a stdout canary. A later marker must remain absent. Parity reads only hashes of eight-byte windows from those private values. It checks the selected command output and the public Operation for matching fragments, requires a redaction marker, rejects command stdout fields, and counts stderr in UTF-8 bytes against 4096. No private value returns to the host or appears in argv. This does not claim a full datastore scan.

The macro fixture sends literal `@orbit/ci-caches` to the Gateway. The canonical supplier must expand it before execution. The repository contains a fixture cache-import tool, so the real macro can run without external caches. A following command must execute and retain a bounded failure tail.

## Source checks before a run

Stage main, plus at most one small F8 compliance PR as D permits. These selectors target the expanded outage workflow, before #2327's grouped setup rewrite. Do not use #2327 to close the outage rows. Recheck selectors if a required fix changes the workflow.

Main's create and setup workflows currently omit an explicit `queueTimeout`. The fixtures record the inherited native default of 600 seconds as a provisional value. The harness correctly refuses them until the owning F8 change declares a queue timeout and these values match it. A fixture is not an exception to that check. The 1800-second run ceilings are unmeasured proof ceilings, not p95 claims; reconcile them with the admitted kind budgets before running.

The fixture contract added `limitations` on 2026-11-01 at 14:08Z. The harness must accept and copy that field before these variants run. No receipt is claimed by local tests.

Run fixture tests without a topology:

```sh
python3 -m unittest discover -s swamp/proof/instance.create -p '*test.py'
ruff check swamp/proof/instance.create/outage_parity.py swamp/proof/instance.create/outage_test.py swamp/proof/instance.create/repository/setup-redaction swamp/proof/instance.create/repository/bin/ci-cache-ref
```
