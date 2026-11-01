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

List and plans run in parallel; provider literal phase bounds give a 345-second critical path inside the approved 375-second allowance. F's final budget decision retains an explicit standalone `task_vm.provision` row of 3,900 seconds. Its legacy phase-ceiling basis is provider 375 + SSH preparation 600 + enrollment 1,500 + rollback 900 = 3,375 seconds, with 525 seconds of headroom. It fits below serve's 5,400-second `max-run-duration`. This is derived from legacy ceilings, not a native-history measurement. Replace the row with history × 1.5 once UpCloud provisions succeed. The Gateway test pins the row for both providers even with fast Incus success history.

`budget-check.json` records that decision and its follow-ups. The 40 SSH phases without explicit client bounds, aggregate SSH allowance and queue-bound coverage remain follow-up work under F's ruling; no new timer or wrapper is introduced here. The Tasks parent retains its own budget. No serve setting was changed.

The old 120-second harness failure was a fixture fault: `test_prefix_failure_cleans_unknown_uuid_without_guest_actions` at `fd42b901c1^` held the provider in maintenance without shortening the real 240-second poll. Python's per-command harness expired first. The fixture now replaces exactly one 240-second phase with one second and refuses a missed replacement. A fresh provider run reached `observe` exit 124 in 1.066 seconds; the parent retained the unknown server without guest actions or deletion. Both cases passed in 34.964 seconds. The harness stays at 120 seconds; this does not prove a real 240-second UpCloud transition.

Every provider command starts with a literal `timeout N` for shared failure projection. A's final OD2 ruling assigns timeout text to the shared F1 projector from exit 124 and the literal timeout. Scripts contain no diagnostic or wrapper. HTTP requests have a ten-second timeout inside the shortest fifteen-second phase. `fixture-receipt.json` records the local fixture proof; lane 26 PASS still gates merge. No live helper invocation, paid server or Operation admission is part of this check.
