---
title: "Swamp port retros"
description: "One row per merged PR that touches swamp/: what it ported, steps per legacy call, budget, proof and review findings, and one row per F10 proof receipt."
---

# Swamp port retros

Lane 26 adds one row for each merged PR that touches `swamp/`. The lessons go into the [porting reference](/swamp/porting#changelog). No PR below has an F10 receipt; the evidence column lists what the PR ran instead. Every PR below merged before F10, so every behavior-changing row needs a re-proof, as do earlier ports such as #2238, #2243, #2244 and #2246. Step counts are estimates from workflow definitions, not run history. None of these PRs had a lane 26 review before merge.

## Behavior-changing PRs

These PRs change what a kind does. Each one needs an F10 re-proof.

| PR | Ported or changed | Steps per legacy call | Budget | Pre-F10 evidence | Findings |
| --- | --- | --- | --- | --- | --- |
| #2253 | `route.create` and Route removal from native children | About 100 steps for about 5 to 7 calls | None | Native tests; no live run | All-pairs `always` edges; result takes `${{ steps }}` plus an echo step; one SSH `true` per Node |
| #2251 | Cluster routers reach task VM web ports through vpn1 | About 17 steps for 1 call | None | 3 fixture cases | One `exec` per UFW command; op 6650 failed after merge in the "Frame too large" loop |
| #2249 | `fleet.recipe` from 209 over `@swamp/ssh` | 127 step definitions for about 7 to 9 calls | None | Native tests; no live run | Asset, exec and decode triplets; six window asserts; one-line JSON YAML; `python3 -c` without `-I` |
| #2248 | UpCloud TaskVM provision, enrollment and destroy | About 85 to 100 steps for about 12 to 15 calls | None | Scratch fixtures | One-shot observe where legacy polled; nonzero exit loses `error_code`; enrollment triplets |
| #2304 | `instance.create` setup macros and failure output | Unchanged (zero new steps) | None | Native regressions, 2 cases in 72 s; outage exception | Passed `@orbit/ci-caches` to Bash raw (op 6659, exit 127); merged 2.5 min after opening with no lane 26 review |
| #2310 | Observation context read bounded to one record (B7) | Unchanged | None | Native tests; outage exception, live proof after load | `data.query` in step inputs grew to 847 records, 117 MB per dispatch; three touched workflows lack `queueTimeout` |
| #2281 | `task_vm.sweep_host` on 209 | About 22 steps for about 6 calls | p95 2.57 s (n=7, harness), budget 300 s | None | Legacy parity tests in `6291bb036:apps/gateway/tests/Feature/TaskVms/TaskVmSweepTest.php` (about 23 cases) not run against the port; TypeScript rewrites a script; no phase `timeout` |
| #2273 | `instance.create` with no stored environment | Unchanged | None | Native fixture, 12.7 s | PHP sent an empty map as `[]` |
| #2271 | Analytics site management in one `site.py` | Fewer: ten scripts became one | None | None | Nonzero exit without `ignoreExitCode` loses `error_code` |
| #2266 | `instance.create` token format and `branch_in_use` | Unchanged | None | Native scratch tests | Strict token regex; refusal after a write; reads of skipped outputs; no stderr in op 6636 |
| #2265 | TaskVM destruction from native ending parents | Unchanged (2-line Swamp change) | None | Native suite, 7 cases in 76.4 s | Ten commits, two merges from main |
| #2262 | Remaining Route primitives | 10-step DNS publish on one Node | None | None | Compensation reruns the forward child; no budget |
| #2261 | Agent rekey, settings and converge from 209 | Converge 66 steps, settings 1 to 8 | None | None | TypeScript envelope and decode steps; about 35 dependencies on one step; JSON-in-YAML generator |
| #2259 | Per-iteration model names; 46 redundant `labels` removed | Unchanged | None | None | Behavior change merged as a cleanup |
| #2258 | Grant pattern check in CI | Not a kind | None | Live stopgap at 08:36Z | Swamp rejected a nested-quantifier pattern at grant time and blocked create |
| #2257 | Role certificate publication as a child per leaf | Not measured | None | None | Behavior change merged as a retirement |
| #2237 | Project maintenance through native schedules | 26-step maintenance path | None | None | Diff over 20,000 lines blocked the audit; three workflows without `vaults:` |

## Deletion, docs and test PRs

Fix-forward is allowed for these. They need no proof.

| PR | Deleted | Baseline rows | Findings |
| --- | --- | --- | --- |
| #2296 | Route-comment LAN write branches | None | Deletion, −90 net |
| #2288 | Unused TypeScript Schedule removal; three Ruff allowances | 3 removed | None |
| #2287 | `@nckrtl/certificates` dispatcher | One count lowered from 125 to 39 | Partial cleanup lowers a count rather than deleting the row |
| #2286 | Shared `operation-executor` client and runner | 7 removed | Kept the `maintenance` method Conn still calls; listed 27 remaining consumers |
| #2276 | Database removal executors and RESP client | 1 removed | Stated "F8: not applicable" with a reason |
| #2272 | Porting skill v1 | None | Docs only |
| #2270 | Route target-set adapter | 1 removed | Closed L17-PR-422-03 and -05 at source |
| #2269 | Unused firewall inspection wrapper | None | Unreachable code |
| #2264 | Route mutation helpers and Valkey ACL writer | 3 removed | Added a retirement procedure to the shape gate guide |
| #2252 | PHP Instance create and destroy executors | 10 removed | Full Gateway suite red before the PR |

## Proof receipts

Lane 26 adds one row for each F10 receipt. The fault column says whether the proof failed because of the kind or the topology. A topology fault fixes the topology and bumps its version in the same PR.

| Receipt | Kinds | Topology and version | Result | What the proof caught | Fault | Lesson |
| --- | --- | --- | --- | --- | --- | --- |
| None yet | | | | | | |
