# Task VM reaper proof inputs

This is a standalone decision-6 scheduled producer. It has no original Operation
or Gateway kind. Its workflow inputs are `expectations_hash` and `expectations`.
The latter contains `proof_project_id` (62 in the proof fixture) and the complete
recorded VM identity/work facts captured by `TaskVmReaperInput`.

The native selector uses Task `created_at` for one-shot `proof …` groups (45 minutes).
Persistent `topology …` groups use the existing root-owned host marker
`/var/lib/orbit/swamp-proof/last-run`: sixty minutes without a run, with an eight
hour creation cap. A single pinned official SSH `stat -c %Y` reads it. ENOENT
means creation; failed access proves no idle time. No nested topology serve,
heartbeat, extra Gateway timestamp or runner budget field participates.

`selection-timings.json` records three isolated official-native runs. These are
selection measurements with the official SSH executable fixture seam, not an F10
topology or cleanup receipt. Selection budget:
30 seconds; each SSH read is bounded by `timeoutSec: 15` and remote coreutils
`timeout 10`; native `queueTimeout`: 15 seconds. Cleanup remains the existing
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
