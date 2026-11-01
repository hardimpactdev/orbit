# UpCloud start-state fixture proof

C approved fixture-only proof for this follow-up because UpCloud overflow is off. No paid server or real credentials may be used. This directory describes the local native fixture; it is not an executable F13 topology request or a topology receipt. The first caller also proves the HTTP-helper relocation from #2366.

`inputs.json` records the full public child envelope. The original parent kind is preserved. The native fixture creates the names-only vault copies locally and routes the unchanged HTTP helper to a disposable loopback API. Its host key and private-key-shaped string are mock data; no real key is used.

From `swamp/`, run:

```sh
PYTHONPATH=tests python3 -m unittest task_vm_upcloud_wait_test task_vm_upcloud_provider_native_test task_vm_upcloud_composition_native_test
```

The provider suite prints native run IDs, status, duration and per-step timing data with `fixture_only: true`. These are scratch-run results, not production or F13 proof.

The legacy sequence is `6291bb036:apps/gateway/app/Infrastructure/TaskVms/UpCloudTaskVmProvider.php` and `UpCloudServers.php`: one create, exact detail observation, conditional one start, then poll until started (240 seconds, three-second interval). Maintenance and stopped remain nonterminal. HTTP errors and unsupported terminal states fail without another mutation.

Parity cases cover maintenance without an address until started, fixed polling interval, strict HTTP errors including 404, bad terminal state, phase timeout, lost create response without duplication, exact UUID/name/labels/image, conditional start, full provision, rollback custody and timeout retention. A shortened one-second timeout tests the real coreutils boundary; it does not wait 240 seconds.

List and plans run in parallel. The provider critical path has a 345-second phase bound, plus step queues (10 seconds each). Parent enrollment and rollback add their own phases. The review planning allowances are 375 seconds for the provider path and 600 seconds for SSH preparation (975 seconds before other enrollment, rollback and queues). OperationRunBudgets is unchanged. After confirmed live uptake of #2348, compare its computed task_vm.provision budget against this path; if shorter, report the 14-day p95 to C for A's measured-row decision. A's final OD2 ruling assigns timeout text to the shared F1 projector from exit 124 and the literal timeout; scripts contain no diagnostic or wrapper. `fixture-receipt.json` records nine provider and nine composition tests on pinned e459; `budget-check.json` derives the current 1,800-second bound-run budget from released #2348 source and zero successful standalone provision rows. This covers the 975-second planning allowance, not a complete enrollment/rollback worst-case sum. No live helper was invoked and no Operation was admitted. Lane 26 PASS still gates merge.
