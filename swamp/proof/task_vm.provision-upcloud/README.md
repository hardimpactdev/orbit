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

List and plans run in parallel. The provider critical path has a 345-second literal phase bound and a 375-second review allowance. The explicit standalone `task_vm.provision` row is 7,500 seconds: `(375 provider + 600 SSH preparation + 1,500 enrollment + 900 rollback + 1,600 queues) × 1.5 = 7,462.5`, rounded up to five minutes. The enrollment and rollback allowances retain the migration-baseline job ceilings (`EnrollTaskVm.php`, `DestroyTaskVm.php` at `6291bb036`). Queue reserve counts all 160 fixed native steps of the longer late-rollback path at 10 seconds, including guarded steps conservatively; the prefix-failure alternative has 76. The separate 600-second SSH allowance is also retained conservatively beside the legacy enrollment ceiling.

`budget-check.json` records the inputs, the historical no-history fallback of 1,800 seconds, and the removal condition. Remove the explicit row once successful UpCloud history supplies a measured replacement covering both providers; a fast Incus run must not shrink the cloud path. A Gateway regression pins both providers with a fast Incus success in history. The Tasks parent retains its separate budget.

Every provider command starts with a literal `timeout N` for shared failure projection. A's final OD2 ruling assigns timeout text to the shared F1 projector from exit 124 and the literal timeout. Scripts contain no diagnostic or wrapper. HTTP requests have a ten-second timeout inside the shortest fifteen-second phase. `fixture-receipt.json` records the local fixture proof; lane 26 PASS still gates merge. No live helper invocation, paid server or Operation admission is part of this check.
