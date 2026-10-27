# Routine dependency updates

Project 20 resolves its config from the private `@nckrtl/project-nckrtl-com`
extension. Its scheduled entry starts a weekly group and the shared minute tick
advances it. The manual workflow below still ticks one existing work item. See
[project extensions](project-extensions.md) for schedules and detection tasks.

One external Orbit group with one child runs routine maintenance in its task VM.
Project 20 (`nckrtl/website`) uses the shared Composer and JS extensions on the
[group worker](task-worker-maintenance.md). Projects without worker support use
their configured tracked script. Repositories retain dependency constraints,
release-age rules and package-manager configuration. Add repositories through
[project config](dependency-projects.md).

`provision → maintenance → handoff check → publish → wait for merge → complete`

The official `@swamp/software-factory` definition is `dependency-routine`.
`orbit-driver-routine` uses the existing tick driver. The manual
`dependency-routine` workflow ticks one explicit work item. It has no schedule
and creates no groups. Status, dispatch, evidence and advance retain the factory's
era and cycle rules.

The `script` stage name is retained in the factory contract. For a worker project,
it starts `tasks.worker`, confirms Swamp enrollment, then places the native
`maintenance-worker` workflow on `group=<id>`. The extensions receive the VM
checkout and configured paths/policy. For other projects, it starts `tasks.script`
with `{subtask, script: <configured path>, args: ["routine"]}`. A worker failure
never selects the script path. Both paths produce the same scoped factory
artifact, including outcome and complete working-tree identity.

One failed maintenance command or local check gets one implementer turn. On the
worker path the agent fixes the cause or failing check, then Swamp reruns
maintenance before another handoff check. On the script path the repair turn
finishes the routine command and returns for a handoff recheck. A blocked turn,
second failure or unknown execution parks for assistance. Infrastructure failures
spend no repair turn. Clearing board assistance does not approve a factory gate.

Gateway handoff runs the Project's check and commits the tree. Publication uses
that exact check. Before publication, the same execution path runs `summary`
against the provision starting commit. Worker summary must leave the checked
HEAD and tree intact. Its PR text includes the Gateway check evidence. Invalid
summary stops publication. A later publish cycle for the same check reuses the
saved summary after the child has ended. If maintenance changed nothing and the
checked tree matches, the group completes without a PR.

Start and tick one authorized group explicitly:

```sh
swamp model method run dependency-routine start --input workItem=tg-1234
swamp workflow run dependency-routine --input workItem=tg-1234
swamp model method run dependency-routine summary --input workItem=tg-1234
```

For live acceptance, follow [deployment and proof](task-worker-maintenance.md#deployment-and-proof).
Record operation identities, worker placement, workflow run, lockfile diff,
checks, PR or no-change completion, and cleanup. Nick owns review, merge and
deployment.

Local validation:

```sh
~/.swamp/deno/deno test --allow-all tests/maintenance_worker_test.ts tests/maintenance_resources_test.ts tests/routine_stage_test.ts tests/maintenance_factory_test.ts tests/routine_routing_test.ts
python3 -B -m unittest discover -s tests -p maintenance_workflow_test.py -v
```

The factory tests use disposable repositories, the installed official extension
and a loopback Gateway. The native workflow test uses one real local Swamp worker,
fixture package commands and isolated vault/state. It covers mixed Composer/JS
routine, detect, package, summary and completed failure receipts. These tests do
not update website dependencies or use live groups.

Rollback: stop ticks, reconcile active operations and Swamp runs, then use the
existing cancellation/cleanup path. Retain target and receipt state. Restore
reviewed definitions only after affected runs drain. Never reset an uncertain
write run to make it run again.
