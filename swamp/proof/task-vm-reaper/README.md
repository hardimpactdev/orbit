# Task VM reaper proof inputs

This is a standalone decision-6 scheduled producer. It has no original Operation
or Gateway kind. Its workflow inputs are `expectations_hash` and `expectations`.
The latter contains `proof_project_id` (62 in the proof fixture) and the complete
recorded VM identity/work facts captured by `TaskVmReaperInput`.

The native selector uses Task `created_at` for proof age. It uses existing work
and assistance timestamps for ordinary groups and excludes unclaimed warm pool
VMs. Project 62 uses only its age limit, including persistent topologies. No title,
runner deadline, budget field or heartbeat participates in policy.

`selection-timings.json` records three isolated official-native runs. These are
selection measurements, not an F10 topology or cleanup receipt. Selection budget:
30 seconds; native `queueTimeout`: 15 seconds. Cleanup remains the existing
`tasks.cancel` / `task_vm.destroy` workflows with their own phase budgets.

The F13 request fixture format requires an admitted Gateway Operation. B must
supply its schedule mode before this producer can attach a valid
`bin/swamp-proof` fixture; this directory does not invent a kind or binding.
The topology proof must exercise expired proof and ordinary groups, a claimed
orphan, an unclaimed pool VM, changed expectations, hung turn/check native cancel,
workspace/Route ownership refusal and completed-group residual cleanup through
the owning Tasks seam. Proven terminal cleanup must retain completed status and
remove only the captured VM/workspace. A native cancel acknowledgment alone must
never remove records.

Parity commands available now:

- `deno test --allow-env --allow-read swamp/extensions/behaviors/operations/task-vm-reaper/metadata_test.ts`
- `python3 -m unittest discover -s swamp/tests -p task_vm_reaper_native_test.py`
- Gateway Pest: `TaskVmReaperInputTest`, `ScheduledTaskVmReaperTest`, `CancelOperationActionTest`.
