# Routine dependency updates

One external Orbit group, with one child, runs its configured tracked script in `routine` mode in its task VM. Each repo owns all dependency constraints and commands. Add repos through the [project config](dependency-projects.md); `nckrtl/website` keeps `scripts/dependencies` and `composer check`. This flow has no inventory, report, proposal or analysis stage.

`provision → script → handoff check → publish → wait for merge → complete`

A failed script or failed local check gets one implementer turn, then a handoff recheck. The turn must finish the routine command if it failed, preserve allowed ranges and policy, and fix the checks. A blocked/failed turn or a second red check parks for assistance. Infrastructure failures park without spending a repair turn. There is no automatic consult, review agent, major upgrade or second repair. Successful checks end the child; publish uses that exact check. Before it publishes, it runs `summary` through the same script and uses that title and text. A failed, truncated, or unreadable summary stops publication and does not fall back to the task brief. If the script changed nothing and the final checked tree still matches its tree, complete without a PR.

`orbit-gateway.routine` reuses the existing keyed request/poll client. The `script` stage calls `tasks.script` with `{subtask, script: <configured path>, args: ["routine"]}`. It stores the request before dispatch, replays the same key after a lost reply, honors poll delays, and accepts only matching operation/group/input evidence. Repair input comparison normalizes the documented `TaskTurnData` defaults (`model`, `check`, `analysis`: null); all supplied fields and any non-null default still have to match. It never treats a failed operation as a failed local check. Existing group/child assistance semantics apply. Clearing board assistance does not approve a factory gate or start a retry.

The official `@swamp/software-factory` definition is `dependency-routine`. `orbit-driver-routine` uses the existing tick driver. The manual `dependency-routine` workflow ticks one explicitly supplied work item. It has no schedule and does not create groups. `status`/dispatch/evidence/advance retain the existing era and cycle rules. No live runtime state is copied into this branch.

After all three PRs merge and the Gateway release is verified, Nick and the operator can create one ordinary external group for `nckrtl/website`, with one child and the usual deliverable, then explicitly start `dependency-routine` for `tg-<id>`. Tick only that item. Bound acceptance to one script operation and at most one repair turn, a passed handoff before PR publication, or a clean no-change completion. Inspect operation identity, logs, lockfile diff, checks, PR and VM cleanup. Fixtures prove routing and reconciliation; a real script run, worker/orchestrator interruption and VM cleanup still need live acceptance.

Rollback before acceptance: do not start the factory; no service changes are required by this PR. During acceptance: stop ticking this explicit item, reconcile any active Gateway operation, and use existing cancel/complete operations as appropriate. Do not delete local state while a receipt or lease is unresolved. Revert the Swamp definitions/client, website script and additive Orbit endpoint only after no `tasks.script` operation remains active. Nick owns merge, deployment and live acceptance.

After separate authorization, start and tick one existing group explicitly:

```sh
swamp model method run dependency-routine start --input workItem=tg-1234
swamp workflow run dependency-routine --input workItem=tg-1234
swamp model method run dependency-routine summary --input workItem=tg-1234
```

Local validation: `~/.swamp/deno/deno test --allow-all tests/routine_stage_test.ts tests/routine_routing_test.ts tests/gateway_stage_test.ts tests/group_assistance_test.ts tests/driver_test.ts`. The routing test copies only source and the already-installed official extension into a disposable repo and uses a loopback fixture Gateway. It does not install packages, run website updates or use live groups. A null script exit code means interruption/unknown execution and parks without a repair turn. A retained dispatch rechecks current assistance before replay; the API still has the accepted final-read race and comment duplication limits.
