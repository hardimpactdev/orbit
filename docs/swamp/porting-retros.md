---
title: "Swamp port retros"
description: "One row per merged PR that touches swamp/: what it ported, steps per legacy call, budget, proof and review findings, and one row per topology proof receipt."
---

# Swamp port retros

Lane 26 adds one row for each merged PR that changes a kind's behavior, through `swamp/` or the Gateway. The lessons go into the [porting reference](/swamp/porting). No PR below has an F10 receipt; the evidence column lists what the PR ran instead. Every PR below merged before F10, so every behavior-changing row needs a re-proof, as do earlier ports such as #2238, #2243, #2244 and #2246. Step counts are estimates from workflow definitions, not run history. Swamp lines are the PR's additions and deletions under `swamp/` (F15). Only #2310 and #2369 had a lane 26 review before merge; #2281 and #2304 were reviewed after.

## Behavior-changing PRs

These PRs change what a kind does. Each one needs an F10 re-proof unless its row says otherwise.

| PR | Ported or changed | Swamp lines | Steps per legacy call | Budget | Pre-F10 evidence | Findings |
| --- | --- | --- | --- | --- | --- | --- |
| Process rule 22 (`a69f99320b`, `942614e7c7`) | All six direct Process kinds; cfgmgmt, SSH argv and native end checks; All 26 scripts removed | Net decreases; workflow growth gives start, stop and restart their own charts and native checks | Start: 3 common systemd steps; restart: 4; no script staging | 120 s, `queueTimeout` 30; six successful samples 13.2–23.6 s; nearest-rank p95 23.6 s across these six kinds | minimal@6 receipts below; native systemd/Docker, private cfgmgmt files, Vite readiness and parent composition tests | Start proof caught old-state projection. Q-SECRET passed on Beast. Docker uses a private env file and one create argv. |
| pilot `instance.create` | F9 rebuild: 8 steps (prepare, source, environment, setup, processes, finish); Gateway mints the token and renders `.env` at admission; run returns only the commit; development Processes after setup (pilot) | +1,026 / −3,153 | 6 phase steps for about 10 legacy calls plus one per setup command | 60 s + setup timeouts + 30 s; `queueTimeout` 10; measured 8.4 s phases, 24.7 s with one Process | Receipts on minimal@2 at e28cdeec | An unused `vp_materialize` in the create intent needed the agent delivery manifest. Two reviews: setup timeout, one mint rule, profile refusal as a fact, already-active writes nothing |
| #2383 | Route publication capture kept below the native frame limit: affected groups only, equal artifacts stored once, numeric references (lane 8, B7) | +374 / −117 | Unchanged | None | Outage exception on B's independent review PASS | Op 6725 admitted 3,487,500 bytes; the 126-site regression fell to 9,394 bytes. The sparse `before` weakens the `caddy.current_config_missing` guard (lane 8 fix PR) |
| #2370 | F1 setup runner normalizes UTF-8 before one-pass redaction and keeps whole lines within 4 KiB (B) | +152 / −16 | Unchanged | None | Outage exception; held create and destroy | The second tail pass still reprocesses markers (bounded); an oversize newest line keeps nothing |
| #2364 | `env:sync` CLI follows its Operation (B) | None | Not a kind | None | CLI and SDK tests | No receipt needed: no admission or projection change |
| #2359 | Cluster Router kinds get an explicit 3,900 s budget (C) | None | Unchanged | 3,900 s from op 6592's 2,417 s × 1.5 | Budget dataset tests | A measured basis, not a borrowed value; replace with the 14-day p95 after the first success |
| #2339 | Failed `task_vm.image_build` attempts settle, and completed cleanup releases its claim (C) | None | Unchanged | None | Gateway regressions | Build 147 stayed running after op 6689 timed out and blocked every retry |
| #2322 | WebSocket parent signing limited to the admitted phase's leaves (B) | None | Unchanged | None | 6 issuance cases red before | Signing is receipt class; lane 23's F13 proof covers it |
| #2312 | Pinned Swamp load entry installed before the release gate needs it (lane 16) | None | Not a kind | None | 21 Python regressions | A's rulings, per lane 17's policy record: entry never restarts serve; a served/disk mismatch pauses the release; enabling the runner is receipt class |
| #2366 | Gate follows sibling-module imports in poll helpers; strict UpCloud `poll_read()` (lane 0b) | +256 / −84 | Not a kind | Gate rule | Recorded receipt exception for the `upcloud.py` move (A's merge feed); gate tests | The DNS allowance is gone; only external HTTPS polls qualify |
| #2363 | Club key delivered only to disposable proof Task VMs (lane 25) | +314 / −10 | One `@swamp/ssh` exec step | Action 30 s; `queueTimeout` 10 | Bootstrap exception; the first `up` is the receipt | Lane 26 review: on exit 1 without `okExitCodes`, the refusal code is lost. No workflow-level `queueTimeout` (L17-PR-2363-02). Its `sh -c` timeout wrapper predates the OD2 projector rule |
| #2360 | Gate proves external polling origins and HTTP failure propagation (lane 0b) | +387 / −42 | Not a kind | Gate rule | Gate tests | Closes L17-PR-2325-01 |
| #2355 | Setup keeps only a fully redacted stderr tail (B) | +74 / −33 | Unchanged | None | Outage exception | Decoding after redaction joined split secrets, and the second pass grew short-value tails; #2370 (open) fixes both |
| #2332 | Automatic-deploy policy returns one checkpoint handle per resource name; `swamp.vault_sync_unavailable` with 0 attempts and a null result is a definite pre-submission failure (E) | +67 / −15 | Not a kind | None | Outage exception on E's independent review PASS against the skill; receipt after `up` | A duplicate `development-315` output broke policy and tick. The body lacked "merges without a topology receipt". CI does not check `deno fmt`, and main is not fmt-clean |
| #2369 | Task VM guest Node removal skips the token phases (boundary decision 8 (a)) | +49 / −10 | Unchanged: a guard on five existing steps | Unchanged | Outage exception; native guest and ordinary-Node regressions; live check after load | Dead `allows()` conjunct. A pre-release intent lacks `task_vm_guest`, so its guard fails. Token selection uses `node_id`, not the Node's own worker principal (decision 8 (b)) |
| #2253 | `route.create` and Route removal from native children | +1,957 / −5,706 | About 100 steps for about 5 to 7 calls | None | Native tests; no live run | All-pairs `always` edges; result takes `${{ steps }}` plus an echo step; one SSH `true` per Node |
| #2251 | Cluster routers reach task VM web ports through vpn1 | +126 / −47 | About 17 steps for 1 call | None | 3 fixture cases | One `exec` per UFW command; op 6650 failed after merge in the "Frame too large" loop |
| #2249 | `fleet.recipe` from 209 over `@swamp/ssh` | +6,405 / −6,765 | 127 step definitions for about 7 to 9 calls | None | Native tests; no live run | Asset, exec and decode triplets; six window asserts; one-line JSON YAML; `python3 -c` without `-I` |
| #2248 | UpCloud TaskVM provision, enrollment and destroy | +1,924 / −269 | About 85 to 100 steps for about 12 to 15 calls | None | Scratch fixtures | One-shot observe where legacy polled; nonzero exit loses `error_code`; enrollment triplets |
| #2317 | `queueTimeout: 60` on three observation workflows | +3 / −0 | Unchanged | queueTimeout only | A's recorded exception: no receipt, failure timing only | Maintain and Doctor still lacked `queueTimeout`; #2334 fixed Doctor, Maintain stays open (L17 2201-01) |
| #2302 | Observation-context run flood stopped | None | Not a kind | None | Outage exception | Re-proof candidate under the corrected F10 scope, not a violation |
| #2300 | Gateway expires native Operations from captured run budgets | None | Not a kind | Expiry policy | Pre-scope-rule; re-proof candidate | A late native terminal within budget must replace the `timed_out` mark (L17-PR-2300-01) |
| #2348 | F8 budgets, late native terminal and no-run lock release (lane 16) | +250 / −0 | Not a kind | 600 s no-run, explicit wins, else 30 min then 1.5× longest success | Outage exception; re-proof pending. The F1 wrapper is unwired and must drop partial-secret lines | Closes L17-PR-2324-01. Open: L17-PR-2300-01 (a timed-out Operation keeps `timed_out` despite an on-time terminal) and L17-PR-2348-01 (after a failed projection, its queued marker may block expiry; source-reasoned) |
| #2325 | Bounded external-state polls in phase scripts (lane 0b) | +847 / −15 | Not a kind | Gate rule | Gate tests | Python poll acceptance had no URL or HTTP failure check (L17-PR-2325-01), fixed in #2360 |
| #2320 | F11 cancel of owned routed workspaces (C) | +138 / −18 | Inspect, withdraw, teardown, VM: the legacy order | Workflow `queueTimeout` 60 | Live-fix path: cancel groups 2195, 2199, 2201, 2203 | F9 reason and parity disposition pending from C |
| #2335 | `instance.run_steps` setup guard (old shape) | +15 / −34 | Unchanged | None | Outage exception; live proof `instance:setup` 780 pending | A stale "running means replay" guard failed every run in 2 s (ops 6703, 6704) after the Gateway began marking Operations running before the native run starts (#1894) |
| #2334 | Doctor reads bounded by `@swamp/ssh` per-host timeouts | +25 / −2 | Unchanged | Read bound 60 s; queueTimeout 60 | Outage exception | Result step takes `results: ${{ steps }}` over a fleet fan-out (L17-PR-2334-01, current policy only) |
| #2304 | `instance.create` setup macros and failure output | +176 / −11 | Unchanged (zero new steps) | None | Native regressions, 2 cases in 72 s; outage exception | Passed `@orbit/ci-caches` to Bash raw (op 6659, exit 127); merged 2.5 min after opening with no lane 26 review |
| #2310 | Observation context read bounded to one record (B7) | +55 / −4 | Unchanged | None | Native tests; outage exception, live proof after load | `data.query` in step inputs grew to 847 records, 117 MB per dispatch; three touched workflows lack `queueTimeout` |
| #2281 | `task_vm.sweep_host` on 209 | +1,840 / −1,453 | About 22 steps for about 6 calls | p95 2.57 s (n=7, harness), budget 300 s | None | Legacy parity tests in `6291bb036:apps/gateway/tests/Feature/TaskVms/TaskVmSweepTest.php` (about 23 cases) not run against the port; TypeScript rewrites a script; no phase `timeout` |
| #2273 | `instance.create` with no stored environment | +12 / −0 | Unchanged | None | Native fixture, 12.7 s | PHP sent an empty map as `[]` |
| #2271 | Analytics site management in one `site.py` | +507 / −948 | Fewer: ten scripts became one | None | None | Nonzero exit without `ignoreExitCode` loses `error_code` |
| #2266 | `instance.create` token format and `branch_in_use` | +514 / −142 | Unchanged | None | Native scratch tests | Strict token regex; refusal after a write; reads of skipped outputs; no stderr in op 6636 |
| #2265 | TaskVM destruction from native ending parents | +55 / −0 | Unchanged (2-line Swamp change) | None | Native suite, 7 cases in 76.4 s | Ten commits, two merges from main |
| #2262 | Remaining Route primitives | +554 / −2,678 | 10-step DNS publish on one Node | None | None | Compensation reruns the forward child; no budget |
| #2261 | Agent rekey, settings and converge from 209 | +1,051 / −2,774 | Converge 66 steps, settings 1 to 8 | None | None | TypeScript envelope and decode steps; about 35 dependencies on one step; JSON-in-YAML generator |
| #2259 | Per-iteration model names; 46 redundant `labels` removed | +60 / −144 | Unchanged | None | None | Behavior change merged as a cleanup |
| #2258 | Grant pattern check in CI | +192 / −1 | Not a kind | None | Live stopgap at 08:36Z | Swamp rejected a nested-quantifier pattern at grant time and blocked create |
| #2257 | Role certificate publication as a child per leaf | +487 / −1,111 | Not measured | None | None | Behavior change merged as a retirement |
| #2237 | Project maintenance through native schedules | +7,366 / −14,334 | 26-step maintenance path | None | None | Diff over 20,000 lines blocked the audit; three workflows without `vaults:` |

## Deletion, docs and test PRs

Fix-forward is allowed for these. They need no proof.

| PR | Deleted | Swamp lines | Baseline rows | Findings |
| --- | --- | --- | --- | --- |
| #2372 | Unreachable Swamp code and the nested `swamp/.github` CI copies (slimming lane; first F15 deletion) | +5 / −3,677 | 1 removed (`php/shared_program.ts` `inline-script`) | The nested copies never ran. The root `.github/workflows/swamp-shape.yml` is the only CI copy |
| #2358 | Test fixtures leaking anonymous Docker volumes (tests only) | +16 / −13 | None | Forced `rm` beat `--rm`; about 1,000 leaked volumes a day on beast until cleanup passed `--volumes` |
| #2341 | Unused SQLite seed-abandon kind | +9 / −78 | None | Deletion, −129 net |
| #2331 | Unreachable standalone certificate kinds (C) | +29 / −780 | None | Deletion, −911 net |
| #2318 | Instance clone diagrams (lane 27, docs) | None | None | L17-PR-2318-01: the parity map cites `InstanceDatabaseCloneTest.php`, which tests database copy, not `instance:clone`. L17-PR-2318-02: "one SSH call per phase" ignores Gateway-only and multi-Node phases |
| #2296 | Route-comment LAN write branches | +26 / −118 | One count lowered from 260 to 233 | Deletion, −90 net |
| #2288 | Unused TypeScript Schedule removal; three Ruff allowances | +82 / −279 | 3 removed | None |
| #2287 | `@nckrtl/certificates` dispatcher | +276 / −1,060 | One count lowered from 125 to 39 | Partial cleanup lowers a count rather than deleting the row |
| #2286 | Shared `operation-executor` client and runner | +88 / −6,021 | 7 removed | Kept the `maintenance` method Conn still calls; listed 27 remaining consumers |
| #2276 | Database removal executors and RESP client | +13 / −762 | 1 removed | Stated "F8: not applicable" with a reason |
| #2272 | Porting skill v1 | +5 / −1 | None | Docs only |
| #2270 | Route target-set adapter | +0 / −442 | 1 removed | Closed L17-PR-422-03 and -05 at source |
| #2269 | Unused firewall inspection wrapper | +0 / −120 | None | Unreachable code |
| #2264 | Route mutation helpers and Valkey ACL writer | +2 / −572 | 3 removed | Added a retirement procedure to the shape gate guide |
| #2252 | PHP Instance create and destroy executors | +1 / −4,851 | 10 removed | Full Gateway suite red before the PR |

## Documented F9 deviations

A port may change the legacy phase order only with a written reason that a sub-orchestrator approves. Each approved deviation is listed here, with the parity tests that cover it.

| Kind | Approved by | Legacy order | Native order | Reason | Parity tests |
| --- | --- | --- | --- | --- | --- |
| `websocket.app_attach`, `websocket.app_detach` (lane 23) | B, 2026-11-01 | Remote read-only inspection, then the Gateway commits the attachment and environment, then the Gateway builds Caddy | Admission records immutable prospective intent only; native read-only inspection; lane 8's prospective Route child; terminal projection stores the attachment and environment only if inspection succeeded, also when publication fails | A Gateway commit between native phases would need a forbidden step callback | Refusal leaves state unchanged; a failed build keeps the desired state |

For the WebSocket attach deviation, unknown or cancelled roots keep their unresolved intent.

## Lesson history

The porting reference's earlier changelog rows, newest first; lane 17 wrote v0 in #2268. Each lesson now lives in the rule it changed.

| Source, newest first | Lesson |
| --- | --- |
| #2258, #2259 | Use flat alternatives and pinned `serve check-config` through `bin/check-grant-patterns.py`. Match `forEach` behavior names and SSH globals. |
| #2252, #2264, #2270, #2286, #2287 | Deletion PRs check imports, loaders and manifests first, remove baseline rows or lower a count, and keep methods live workflows still call. |
| #2273, #2358, lane 9 | Op 6639 took 957 s for 4.6 s of SSH work. Encode empty maps as objects. Forced removal beats `--rm`, so real-Docker cleanup passes `--volumes` (moby 29.1.3). |
| Lane 17 on #2201, #2208, #2210, #2304, #2310 | No step receives a Gateway macro; #2304 expands it through `GatewaySetupSteps` after checking admitted digests. Read `data.latest`, and set `queueTimeout` everywhere. #2210 rewrote a script. |
| PLAN F9–F15 | Port inside a versioned dev topology, built from Node roles, through `bin/swamp-proof`; the receipt names the topology version. Split `swamp/` growth by part beside the Orbit net (F15). |
| #2335 (B) | When the Gateway start contract changes, such as `StartSwampRun` marking an Operation `running` before its run starts (#1894), re-check every step-start status guard. A proof dates from everything the kind runs. |
| #2304 (B), lane 17, A | Keep legacy exit-code and error-name cases or explain each change. Private stdout never enters native history; F1 output is a stderr tail, redacted before and after cutting. |
| PLAN F8, F11, L17-PR-2324-01 (closed by #2348), OD1 | Every kind expires: measured budget, else 30 minutes, then the longest success times 1.5. No-run rows expire at 600 s but keep their lock until native lookup proves no run. |
| Retro #2237, #2248, #2249, #2251, #2253, #2261, #2281, lanes 17 and 27 | Asset, exec and decode triplets, all-pairs `always` edges, window asserts and one `exec` per command gave up to 20 steps per legacy call (#2253). F9 means legacy code and tests at `6291bb036`. |
| #2266, lane 5, v2 review | Refuse before the first write and accept documented token formats. A nonzero `command/shell` exit drops outputs; use `ignoreExitCode` plus an `assert`. `forEach` fails over same-job outputs. |

## Proof receipts

Lane 26 adds one row for each receipt from the topology loop ([F13](/swamp/porting#port-inside-the-topology-f13)). The fault column says whether the proof failed because of the kind or the topology. A topology fault fixes the topology and bumps its version in the same PR.

| Receipt | Kinds | Topology and version | Result | What the proof caught | Fault | Lesson |
| --- | --- | --- | --- | --- | --- | --- |
| `evidence/proof/tool.install/20261102T142720Z-minimal.json` | `tool.install` | minimal@8, `8a778cef33` | Pass: 26.65 s | Worker key verification, binary publication and deb822 source | None | Verify the key before any Node contact |
| `evidence/proof/tool.update/20261102T142802Z-minimal.json` | `tool.update` | minimal@8, `8a778cef33` | Pass: 16.53 s | Signed source update and unchanged package | None | Official file resources publish key and source |
| `evidence/proof/tool.remove/20261102T143206Z-minimal.json` | `tool.remove` | minimal@8, `403c6f05c9` | Pass: 14.53 s | Final signed source removal | None | Source, binary key and preferences are absent together |
| `evidence/proof/tool.remove.source-failure/20261102T142909Z-minimal.json` | `tool.remove` | minimal@8, `8a778cef33` | Pass: 12.48 s | Package removed despite immutable source | None | Source cleanup remains best effort |
| `evidence/proof/tool.install.constraint-blocked/20261102T142918Z-minimal.json` | `tool.install` | minimal@8, `8a778cef33` | Pass: 6.46 s | Candidate refusal leaves package absent | None | The native decision retains the refusal code |
| `evidence/proof/tool.install/20261102T124136Z-minimal.json` | `tool.install` | minimal@7, `4d1065e2cc` | Pass: 12.55 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/tool.update/20261102T124202Z-minimal.json` | `tool.update` | minimal@7, `4d1065e2cc` | Pass: 8.49 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/tool.remove/20261102T142829Z-minimal.json` | `tool.remove` | minimal@8, `8a778cef33` | Pass: 10.45 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/tool.install.constraint-blocked/20261102T124236Z-minimal.json` | `tool.install.constraint-blocked` | minimal@7, `4d1065e2cc` | Pass: 6.49 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/tool.remove.source-failure/20261102T124319Z-minimal.json` | `tool.remove.source-failure` | minimal@7, `4d1065e2cc` | Pass: 14.57 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/tool.install.first-use/20261102T142953Z-minimal.json` | `tool.install.first-use` | minimal@8, `8a778cef33` | Pass: 30.70 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/metrics.credentials_reset/20261102T124421Z-minimal.json` | `metrics.credentials_reset` | minimal@7, `4d1065e2cc` | Pass: 6.46 s | Native end state and legacy public parity | None | Official resources or bounded argv replace package scripts |
| `evidence/proof/metrics.credentials_reset/20261102T114754Z-minimal.json` | `metrics.credentials_reset` | minimal@6, `63b33e155b` | Pass: 4.44 s | Integer target IDs need a string environment assignment in argv; parity reads `target_id` | Fixture | Bounded curl argv takes private config on stdin and exposes only HTTP status |
| `evidence/proof/vault.setup/20261102T114220Z-minimal.json` | `vault.setup` | minimal@6, `20ac40bacc` | Pass: 32.57 s; verification 23.420 s, cleanup 6.523 s | The fixture's stale APT index referenced a removed package; refresh it before installing test dependencies | Fixture | Native cleanup removes the Bitwarden session before the end assert; the terminal reader projects only public metadata |
| `evidence/proof/instance.create/20261101T214907Z-minimal.json`, `…setup-failure/20261101T214919Z-minimal.json` | `instance.create` (success with a development Process; setup failure) | minimal@2 | Pass: 24.7 s and 8.5 s, up to receipt 414 s | An unused `vp_materialize` in the create intent needed the agent delivery manifest (HTTP 500) | Kind | Compute only what the workflow reads; the minimal topology finds hidden build-output dependencies |
| `evidence/proof/process.horizon_handoff/20261102T125030Z-minimal.json` | `process.horizon_handoff` | minimal@7, `93ebd83c63` | Pass: 19.5 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.horizon_handoff.active/20261102T125050Z-minimal.json` | `process.horizon_handoff.active` | minimal@7, `93ebd83c63` | Pass: 20.2 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.create/20261102T125104Z-minimal.json` | `process.create` | minimal@7, `93ebd83c63` | Pass: 13.7 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.start/20261102T125119Z-minimal.json` | `process.start` | minimal@7, `93ebd83c63` | Pass: 15.5 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.stop/20261102T125134Z-minimal.json` | `process.stop` | minimal@7, `93ebd83c63` | Pass: 14.8 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.restart/20261102T125149Z-minimal.json` | `process.restart` | minimal@7, `93ebd83c63` | Pass: 14.9 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.update/20261102T125213Z-minimal.json` | `process.update` | minimal@7, `93ebd83c63` | Pass: 23.5 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.destroy/20261102T125236Z-minimal.json` | `process.destroy` | minimal@7, `93ebd83c63` | Pass: 23.5 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.create.docker/20261102T125249Z-minimal.json` | `process.create.docker` | minimal@7, `93ebd83c63` | Pass: 12.8 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.stop.docker/20261102T125314Z-minimal.json` | `process.stop.docker` | minimal@7, `93ebd83c63` | Pass: 25.2 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.create.docker.environment/20261102T125329Z-minimal.json` | `process.create.docker.environment` | minimal@7, `93ebd83c63` | Pass: 14.8 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/process.restart.missing-unit/20261102T125343Z-minimal.json` | `process.restart.missing-unit` | minimal@7, `93ebd83c63` | Pass: 13.7 s | Native end check and public parity after script removal | None | Node effects and terminal projection agree |
| `evidence/proof/task_vm.hub_converge/20261102T231348Z-minimal.json` | `task_vm.hub_converge` | minimal@12, `6d69e0cc3b` | Pass | Exact live nft chain lines, filter file and unit, UFW rules, ready result | None | One native assert over the read-back replaces the decode behavior |
| `evidence/proof/node.role_relocate/20261103T051535Z-minimal.json` | `node.role_relocate` (metrics, Node 212 to 213) | minimal@16, `545384092b` | Pass | Two earlier runs failed when the proof VM ran short (peer unreachable, then the 209 worker dropped) | Topology | Moving Metrics between two nested Nodes needs a large proof VM; Project 62 now gets one |
| Live op 8295 | `task_vm.fill_package_cache` | Fleet, beast (Node 9), release `715b402786` | Pass | Earlier live runs failed on a large vault value (serve's redaction pattern) and a token-form check | Kind | Keep vault values to the secret member |
| Live ops 8374–8376 | `task_vm.sweep_host` | Fleet scheduled sweeps, release `4461721c8c` | Pass | — | None | — |
| Live Swamp run `ebf878d0-1dd0-4319-a0ef-4b70ae8dd6c3` (cancel op 8429) | `task_vm.destroy` | Fleet, proof `down` | Pass | — | None | — |
| Live op 8513 | `task_vm.resume` | Fleet, task VM 389 (already running) | Pass | Covers the read-back, agent and Pi checks; starting a stopped VM is covered only by native tests | None | — |
| None | `task_vm.upcloud_image_build` | — | Not proven: ships on its native tests, with Nick's approval (2026-11-03) | A live build creates billed UpCloud servers | — | The first real build is the live receipt |

Q-SECRET evidence: `swamp/proof/receipts/qsecret-file.json`, minimal@6 on Beast at `fbeeb6f7fb`, pinned Swamp e459 and cfgmgmt 2026.10.27.1. The probe checked create, content change, drift, compliant reruns and a refused directory write. None of the generated values or their base64 form appeared in 39 inspected surfaces: data, file changes, run history, logs and behavior definitions. The file retained exact bytes and root:root 0600. The VM was destroyed after the proof. The pinned CLI has no separate data-diff command; the file behavior's changes array is its diff.

For `vault.setup`, the measured sample is one success: 32.57 s, so the observed p95 is 32.57 s. The proof budget is 180 s and every step inherits `queueTimeout: 30`. The Gateway keeps the shared history-derived run deadline. The proof uses disposable Vaultwarden 1.37.5, generated credentials and the proof CA; it covers no production data or scale.

The former setup callback becomes verification on a Swamp role worker, completed-dependency session cleanup, then a native assert. The official Bitwarden CLI omits collection write permissions, so verification creates a harmless note and cleanup deletes it permanently. A new request checks credentials again; native resume retains completed verification. Native failure exit codes preserve public refusals without a report step or failed-step stdout.

Before this retro, the Orbit change is +884 lines. Swamp grows by 763: behaviors +11 for public asset paths, workflows +98 for the native DAG, scripts +158 for the uncovered Vaultwarden account/session lifecycle, tests and fixtures +438 for refusal, cleanup and real-service parity, and proof/config +58 for the fixture, grants and baseline removal. The scripts run only on Swamp workers and never copy code to Nodes. The extension README names the official cfgmgmt/vault gap.

## Rule 22 Tools, AI-router and Metrics follow-up

### Tools package scripts

The package conversion deletes 15 scripts and their manifest entries. Nine shared files remain for frozen adoption and the existing setup of a Tool Manager on first use. Installation is still partial under rule 22; update and removal use native steps throughout. No new script or named gap is added.

Pass 3 folds Homebrew into the common SSH mutation and check steps, shares one package write for install/update, and removes redundant key deletion: `apt_repository` already removes its key. Worker key verification, binary copy, text files, scoped index refresh, package checks and the native assertion keep separate labels. The commit contains the chart-to-workflow table; `swamp-evaluation.md` records open candidate improvements. The label gate excludes steps that a literal kind guard always skips.

Seven fixtures pass on shared minimal@8 at `8a778cef33` (the final signed-removal fixture refresh is `403c6f05c9`): signed install 26.65 s, unchanged update 16.53 s, ordinary removal 10.45 s, failed source cleanup 12.48 s, constraint refusal 6.46 s, Vite+ first use 30.70 s, and signed removal 14.53 s. Signed removal verifies the source, key and preferences are absent together. The observed p95 is 30.70 s for seven runs. Budgets are 300 seconds, except first use at 900; queue timeout is 60 seconds. Eight native graph tests, 25 Deno tests and 74 Gateway cases pass, including shared Homebrew paths and zero Node calls on a mismatched key.

The earlier Tools changes removed 31 net Orbit lines; pass 3 removes 12 more. Swamp shrinks by 24 lines: workflows -45 through shared branches, behavior documentation +2, native tests +13 for Homebrew and fixtures +6 for repository cleanup. Typed behaviors and scripts add no lines. The proof uses disposable Linux Nodes, public test packages and the proof CA, with no production data or scale. The native transport tests cover Homebrew; the live minimal topology covers apt and Vite+.

The setup follow-up deletes `phase.py` and its tests. Native argv checks readiness; official apt installs prerequisites. The bootstrap family retains pinned installers, and eight scripts serve frozen adoption. Installation remains partial. Three native setup cases and eight package cases pass. At `1312dcce41`, minimal@9 receipts `evidence/proof/tool.install.first-use/20261102T151749Z-minimal.json` (34.76 s) and `evidence/proof/tool.install/20261102T151824Z-minimal.json` (26.63 s) pass. Observed p95: 34.76 seconds, two samples; budgets 900/300 seconds, queue timeout 60. Orbit loses 74 net lines: script -49, workflow -4, tests -24, typed shaping +1, proof notes +2.

### Metrics and AI-router

Metrics removed its Python HTTP action. Its final minimal@7 receipt at `4d1065e2cc` passes in 6.46 seconds with a 180-second budget and 60-second queue timeout. The native selector fix is included. AI-router source removal had no production caller and was deleted with its tests; deletion needs no execution receipt.

### Proof VM cleanup

Group 2511 completed the earlier proofs. Cancel Operation 7792 destroyed task VM 266 and reported the provider absent and settled. Group 2507 then hosted the Decision 7 proofs on task VM 264; Cancel Operation 7835 destroyed it. Group 2563 hosted pass 3 on task VM 290; Cancel Operation 7890 destroyed it at 14:35:56Z. No proof VM remains. No production serve restart or reload was used.

## Rule 22 Process and Horizon follow-up

The Process/Horizon audit follow-up removes the final Process Docker script and all three Horizon scripts. Process uses private cfgmgmt files and public Docker argv. Horizon uses six steps: inspect, guarded write/enable, graceful handoff or start, a check for the start branch, and a native assertion. Five Horizon native cases and eleven Gateway cases pass. The active branch still accepts termination without waiting for current jobs.

The final Process/Horizon proof passed all twelve fixtures on minimal@7 at `93ebd83c63`, after a fresh `up` on Beast task VM 269. The six Process kinds took 13.7–23.5 s (six successes, nearest-rank p95 23.5 s); their budgets are 120 s with `queueTimeout: 30`. Docker create, stop and private environment cases took 12.8, 25.2 and 14.8 s within their 180 s budgets.

Horizon start and active handoff took 19.5 and 20.2 s within 120 s. The Horizon fixture exercises a PHP artisan boundary without a Redis job engine.

Live development Process 168 on Beast restarted successfully as Operation 7796 at 12:54:05–08Z. The proof VM was destroyed through `bin/swamp-proof down`, with Incus absence verified. The earlier WebSocket 429 and capacity failures left no passing receipts and were cleaned up before this proof.


### Task turn Git actions and private data copies (rule 22, partial)

Task turn removes three Node scripts (`candidate`, `snapshot`, `restore`) and their manifest entries. The admitted workspace and analysis evidence replace runtime ownership and provenance checks, as the audit directs. Git snapshots copy the real index first, then use two `GIT_INDEX_FILE` argv calls. This preserves ignored tracked files and leaves the real index unchanged. Reviewer cleanup uses quiet reset and clean.

Diff argv writes files on the Node. Official SSH copy brings only data files to a private worker directory. Existing worker scripts redact receipt and diff text before producing history. Ten native cases, six action cases, eight data tests and four Gateway tests pass.

The shared minimal@8 receipt is `~/swamp-execution/evidence/proof/tasks.turn/20261102T131318Z-minimal.json`, source `a4e77c1627`, Gateway operation 3, native run `65faf379-c831-4952-9b74-730135552b09`. One sample takes 53.49 seconds; p95 equals the sample. The budget is 180 seconds and queue timeout is 60 seconds. The proof uses real Gateway, vault, SSH and Git, with a synthetic Pi server. It makes no model request and uses no production data. Provisioning first failed on SSH before staging; restaging succeeded.

This is not rule 22 done. Only `receipt_prepare.py` and its agent-facing `receipt_helper.py` still reach the Node, pending a way to install the tool in the image. No new shipped script was added. The task-turn chart is still pending. The assigned tighten2 charts follow this conversion.

Orbit grows by 788 lines. Swamp grows by 775: workflows +569 declare Git commands, data copies and cleanup; proof fixtures +290 exercise the Gateway and real SSH without model calls. Behavior metadata and documentation grow by 51 lines to expose public hashes and name the remaining gap. Scripts shrink by 122 lines and tests by 13, removing replaced actions and their mirrored tests. TypeScript still only shapes public data.


## L22-TIGHTEN3-PROCESS: chart labels follow native primitives

Decision 5 and pass 3 are proved for all six Process lifecycle kinds, Horizon handoff and Schedule run on minimal@8 on Beast. Seventeen passing receipts cover both Horizon branches, Docker lifecycle and environment cases, stopped creation, and missing-unit refusal. The receipt index is `swamp/proof/receipts/process-tighten3.json`. Operation p95 and maximum were 16.49 seconds across these 17 samples; Process/Horizon queueTimeout stays 30 seconds, and Schedule stays 300. Task VM 289 was destroyed by operation 7881.

Pinned systemd apply publishes unitFile but does not restart an active process after a change or stop an activating unit. Keep those native actions separate, along with check and native assert. The charts show their exact labels; the commit maps each expansion. No wrapper or shipped script was added. Process and Horizon have removed 29 scripts in total, with no retained script gap. Candidate improvements are recorded in `swamp-evaluation.md`.

Docker checks store their observed environment in the vault under a bounded native-output grant. Proof now loads serve's production grants as well as Gateway execution-input grants. Earlier receipt failures exposed that missing grant, then outdated phase labels and an obsolete env-file cleanup expectation. The corrected proofs verify root-owned 0600 managed env files until destroy. Schedule setup and command failures share `schedule.run_failed` with native diagnostics; target admission retains `schedule.target_unavailable`.

## Task turn native outcome check (rule 22, partial)

Task turn replaces `outcome.py` with a native assert over the existing public shaper's typed success flag. One script and its manifest entry are deleted. The public result, failure codes, step name and dependencies stay the same. Ten native, eight data, six action and four Gateway tests pass.

Shared minimal@9 receipt: `~/swamp-execution/evidence/proof/tasks.turn/20261102T151441Z-minimal.json`, source `f41a857e5b`. One sample takes 51.39 seconds (nearest-rank p95 51.39), within 180 seconds; queue timeout stays 60 seconds. The Gateway, vault, SSH and Git are real; Pi is synthetic. This run found no topology fault.

Nine task-turn scripts remain. The receipt helper needs an image-owned installation and image identity update. Worker API and private-file redaction replacements must preserve the admitted Pi session and sanitize data before history.

Vault setup and sync use the pinned Bitwarden CLI directly in bounded worker commands because the registry has no account/item behavior. Their eleven shipped scripts and manifest entries are removed; private environment and stdin carry credentials and payloads, while typed data methods filter raw CLI responses before native history. Native setup, sync and ledger tests cover refusal codes, reconciliation and summary resume. Both conversion proofs pass on minimal@10, as recorded below.


## Vault CLI without shipped assets

Every Vault CLI action is one plain command in the native graph. Typed data methods compare the frozen configuration, shape account and cipher metadata, and materialize private cipher stdin. They never start a subprocess or call HTTP. Credentials and session keys use native sensitive fields in `orbit-control-inputs`; later commands resolve the generated key names. The Gateway has read/write grants only for those exact native field patterns. Native tests scan all recorded history and logs for private fixture values.

The earlier shell composition passed the shape gate but still hid several actions in one setup step. The review split those into the real CLI primitives and moved selection, KDF checks and response filtering into typed methods. The binding comparison is restored. The Gateway maps native typed refusal codes and failed CLI step names, with no numeric exit-code protocol. Cleanup dependencies include skipped steps explicitly because `completed` alone does not cover them on the pinned runtime.

Both rule 22 proofs pass at `bf8c71ad51ac47abbfde83848bcc771e94a0c0bc` on shared minimal@10: `evidence/proof/vault.setup/20261102T170847Z-minimal.json` takes 51.07 seconds, and `evidence/proof/vault.sync/20261102T171308Z-minimal.json` takes 44.86 seconds. Each has one sample, so its nearest-rank p95 equals its observed time. Setup keeps its 180-second budget; sync keeps 1,800 seconds because one item does not measure production scale. Both queue timeouts are 30 seconds. The receipt index is `swamp/proof/receipts/vault-rule22.json`.

The proof uses a real Gateway, Swamp and the pinned Bitwarden CLI against a disposable TLS Vaultwarden with the proof CA. Setup verifies collection write access and removes its probe. Sync creates one encrypted Database item and projects only its public ledger. Both verify private-cache removal. Native setup (4), sync (11), planning (9) and Gateway (48) tests also pass, including refusals, configuration mismatch, secret filtering, cleanup and resume with more than ten writes. No production credentials or scale are exercised. Capacity initially refused provisioning; the authorized 17:00Z retry obtained one VM for both fixtures.

Before receipt notes, Orbit grows by 609 lines: Gateway +15, root documentation +26, and Swamp +568. Swamp comprises behaviors and their documentation +417 for typed private-data shaping, workflows +482 to expose the CLI primitives, scripts −381, tests and fixtures −251 after removing obsolete asset tests, proof fixtures +300 for real-service parity, and grants +1. No execution wrapper, shipped script or Node script copy remains.

Cancellation operation 8068 destroyed task VM 320 and completed provider cleanup. The first status read failed during a Gateway release; a read confirmed success and the proof tool recorded destruction without admitting another cancellation. Both receipts contain the destruction record. The rebased workflows, behaviors and proof files match the proven source.

The first pushed CI failed the registry boot check: the intended policy included native credential-field grants, but the Vault kind declarations omitted them. The follow-up declares that same already-proven grant for setup and sync. It changes no rendered authority or workflow execution. The registry entrypoint and all 60 Vault and grant-policy tests pass. The skill now requires both Orbit's `bin/check-operation-kinds` and native `serve check-config`: they check different contracts.

## L22-NODE-REMOVAL: native resources replace shipped scripts

Node and role removal use official cfgmgmt behaviors and SSH argv. Eleven shipped scripts and their manifest are deleted. Publication withdrawal precedes runtime cleanup, Metrics membership changes precede exporter removal, and the control key goes last. Native checks confirm removal. User data, unrelated keys and unrelated firewall rules stay. No script gap remains.

Four passing minimal@10 receipts cover online Node removal, offline consent, WebSocket role removal and a drained CI host. Operation p95 and maximum are 42.89 seconds across four samples, with queueTimeout 30 seconds. The receipt index is `swamp/proof/receipts/node-removal.json`. Task VM 322 is destroyed. The WebSocket fixture uses a disposable sleep service; no production data is involved.

Proof failures found a nested Caddy field and sparse Route block metadata passed to the strict full-publication contract. Admission now captures that contract directly. Routed UFW deletion uses one shared argv builder with `route` before `delete`. Fixture checks follow the recorded keys and role firewall catalog, and offline parity removes its test SSH block. Eleven native removal tests and 25 Gateway removal tests pass.

## Fleet observer and role files (rule 22, partial)

Fleet recipe and retained role callers delete five scripts and their manifest entries: public files, observer identity and checkout cleanup, PHP runtime and private DNS footprint. They use official cfgmgmt resources, bounded SSH argv and shared native children. DNS rollback is removed. The worker renderer runs only in the release checkout; its manifest entry is deleted. `recipe.py` remains the approved gap for package policy, Incus drain and repacked PHP. CLI removal is a separate conversion owned by the docs lane; Fleet recipe is not declared done yet. Eight Tools adoption scripts remain frozen for the Node observer pass.

Fresh proofs on shared minimal@10 pass at `e949c0d501`. The receipt index is `swamp/proof/receipts/fleet-rule22-files.json`.

| Kind or fixture | Operation seconds, one sample | Run budget / queue timeout |
| --- | --- | --- |
| `fleet.recipe` | 118.71 | 1800 / 60 s |
| `node.converge` | 34.89 | 1800 / 30 s |
| `node.role_converge` | 24.70 | 900 / 30 s |
| `tool.install.first-use` | 77.38 | 900 / 60 s |
| `tool.install` | 26.66 | 300 / 60 s |
| `tool.update` | 16.53 | 300 / 60 s |
| `tool.remove` | 14.45 | 300 / 60 s |

Each fixture's nearest-rank p95 equals its one sample. The recipe uses the actual current recipe and frozen agent, with public CLI 0.8725.0 from the existing test catalog. Tool first use installs the real Vite+ manager from no store; the apt cases download and fingerprint-check the signing key on 209 before Node work. The retained add/relocate roots have native graph coverage only. The role proof covers a sole Database role. The fixture fleet has no production data or scale.

The complete native recipe exposed two dependency errors after reconciliation with main. An empty peer-DNS set skipped unrelated writes. A required footprint failure could still reach delivery. Native dependencies now cover completion of the optional set and require footprint success before delivery; Route residue remains best effort. Eleven native recipe cases, two retained-role cases and 39 Gateway cases pass. Deno, registry, chart, documentation, shape, naming and placement checks pass.

Parity reads the full Operation from `PROOF_OPERATION_FILE`, avoiding the operating system environment size limit. A real subprocess test covers a 1 MiB intent. New Node removal and Vault parity readers follow the same contract. No parity assertion is removed.

Cancellation 8123 confirms task VM 323 destroyed at 2026-11-02T18:08:51Z. The lane holds no VM. No production serve restart or reload was used.

Orbit shrinks by 974 lines. Swamp behaviors shrink by 337, replaced scripts by 789, tests and fixtures by 440, and configuration by 9. Workflows grow by 11 for native declarations and dependencies. Proof fixtures grow by 217 for the actual recipe, public CLI catalog and receipt index. The other 373 lines cover chart labels, Gateway intent and parity transport.

CI found one remaining consumer of the deleted file script in task VM hub convergence. The parallel fix `aa4362ae72` writes its nft filter and unit with one pinned SSH install argv each, with modes 0600 and 0644. This follow-up preserves that fix, bounds the commands and publishes its exact-label chart. Three native tests cover success, invalid policy, activation failure and missing rules. It adds no full hub receipt and claims no completed hub kind. The seven family receipts remain valid because their execution paths are unchanged.

## Fleet CLI without a shipped script (rule 22)

`cli.py` is gone. Every caller runs the native `node-cli-install` child: role add and relocate, `fleet.recipe`, and, through the new `fleet-release-node` child, `fleet.rollout` and `fleet.cli_converge`. The child plans on Node 209, publishes with `@swamp/cfgmgmt` fetch, link and file, keeps newer and commit builds, and writes the profile 0600 and root-owned. A file that reports no valid Orbit version is replaced by the admitted release; the Gateway records no foreign CLI state, and the rollout set has no `foreign_cli` reason.

The fleet admissions carry each installing Node's public SSH descriptor. `fleet-release-node` shapes the result on 209; only `fleet.rollout` runs the residual `observer` step, still on the target Node's worker, so `fleet.rollout` is not all-native yet. A failed child records `cli.install_failed`; the result carries no separate download, checksum or busy code.

| Fresh receipt on minimal@10 at `8394451478` | Runtime, one sample | Run budget / queue timeout |
| --- | --- | --- |
| `evidence/proof/fleet.cli_converge/20261102T183108Z-minimal.json` | 26.89 s | 900 / 60 s |
| `evidence/proof/fleet.recipe/20261102T183327Z-minimal.json` | 135.06 s | 1800 / 60 s |

The `fleet.cli_converge` fixture plants a script that prints `custom-build` at `/usr/local/bin/orbit` and checks it before the request; the run replaced it with Orbit 0.8725.0 and wrote the 0600 profile. `swamp/proof/receipts/fleet-cli-rule22.json` indexes both receipts. One shipped script is deleted. `fleet.rollout` is not proved here and is not rule 22 done while its observer step runs on the Node.

## Fleet rollout observer from 209 (rule 22)

`fleet.rollout` publishes the pinned observer with official resources from 209: `@swamp/cfgmgmt/fetch` writes `/usr/local/bin/orbit-agent` with its checksum, one SSH read prints the running binary's digest, `systemctl restart` runs only when the file changed or the running digest differs, and the same read is the end check. No step runs on the target Node's worker. The embedded observer program, `publishObserver` and its tests are deleted, and the rollout keeps no `.previous` copy, ownership check or update lock. A failed fetch records `agent.download_failed`, a failed restart `agent.restart_failed`, a failed check `agent.observer_unhealthy`. The gate checks the window and cancellation once per Node; nothing rechecks them between the CLI and the observer.

| Fresh receipt on minimal@11 at `a5533d93fe` | Runtime, one sample | Run budget / queue timeout |
| --- | --- | --- |
| `evidence/proof/fleet.rollout/20261102T212038Z-minimal.json` | 14.34 s | 900 / 60 s |
| `evidence/proof/fleet.cli_converge/20261102T211857Z-minimal.json` | 16.33 s | 900 / 60 s |

The proof Gateway runs from a checkout, so the rollout fixture sets `ORBIT_GATEWAY_CHECKOUT` to it; the release gate then reads it as served in place.

## Task turn receipt without a helper (rule 22)

Task turn ships no script. The agent writes `.git/orbit/receipt.json` itself, following the schema that the turn prompt states. `receipt_prepare.py` and `receipt_helper.py` are deleted with their manifest entries and the copy step. Two pinned `@swamp/ssh exec` argv steps create `.git/orbit` and remove an earlier receipt. The image-owned tool that the audit proposed is not needed.

The helper's checks move to the TypeScript result shaper, with one fixed `receipt_error` code per rule. A broken receipt is evidence, not a failed turn: no helper refuses bad input during the turn, so Swamp decides what to do with it. The Gateway's unused legacy receipt path goes too: `resources/tasks/turn`, `TaskTurnReceipts`, `RemoteTaskTurnReceipts` and the metadata `turn`, `read`, `legacy` and `clear` operations. Gateway prompts now show the receipt JSON instead of the command. No live proof receipt exists for this change yet.

## Vault teardown follows its command chart

`vault.teardown` now uses `operation-vault-teardown`, preserving the old workflow identity. All work runs on Swamp role workers, with the pinned Bitwarden CLI and the existing typed private-data methods. The old custom Vaultwarden HTTP and cryptography client, its callback wrapper and replaced tests are removed. There is no new TypeScript behavior, shipped script, private-material request or result callback. The three chart actions expand to nineteen real CLI, data and native-check steps; the commit maps those expansions.

When `--keep-items` is set, the Gateway records a completed Operation and clears its records without any Swamp or vault call. All Vault admissions use the same target lock and return `vault.sync_busy` on overlap. Normal teardown selects exact ledger IDs and sources, refuses every shared item before deletion, and guards each native deletion. Cleanup follows skipped and failed work. Failure preserves the setup and removes only ledger entries whose deletion succeeded. Native status projection now keeps every fan-out status and summary count; output details retain their existing 256-step and 4-KiB limits. A 301-item Gateway regression covers partial completion beyond the old status cap.

Both proofs pass on minimal@10 at `dba6cc48150066255fd7bb46812fb8b9c7e38ccd`: `evidence/proof/vault.teardown/20261102T183625Z-minimal.json` takes 40.88 seconds; `evidence/proof/vault.teardown-keep-items/20261102T184050Z-minimal.json` takes 0.10 seconds and starts no run. Each branch has one sample, so its observed time is also its nearest-rank p95. The run keeps the initial 1,800-second budget and 30-second queue timeout; one item does not measure production scale. The proof checks actual deletion or retention in a disposable TLS Vaultwarden, Gateway records and private-cache removal. It uses the proof CA and no production credentials.

Four native tests, 68 Gateway/grant/status tests, PHPStan, Pint, shape, names, placement, flow, docs, registry and native rendered-grant validation pass. Provisioning first waited for recipe images; the five-minute retry obtained one VM for both branches. Fixture failures exposed an unsupported Python file opener and omitted fields in a parity read; neither was a workflow failure. Both fixture faults were corrected before the passing receipts.

Task VM 330 was destroyed by cancellation 8164 after both proofs. Before receipt notes, Orbit grows by 96 lines: Gateway +118, root docs +15, config -25, embedded script -299, tests and fixtures -340, legacy behavior -183, proof +275, workflows +535. Native workflow declarations replace the hidden program; proof fixtures check both real-service outcomes.


## Native Schedule creation

`schedule.create` publishes one `schedule-<uuid>` workflow and its cron trigger on 209, without a Node visit or a control run. Each fire calls the existing native `task-command-run` child. Node and Instance Schedules both accept `--no-start`; their workflow exists without a trigger. The CLI, admission, chart and reference now agree. Production schedulers that fire every minute keep their Node timers. No shipped script is added or removed in this follow-up.

Three fresh minimal@10 receipts at `412ee26b39` cover enabled creation and a successful fire, a failed fire, and disabled Node creation. Admission takes 0.18, 0.20 and 0.17 seconds; its observed p95 is 0.20 seconds for three samples. Calendar waits take 52.46 and 58.56 seconds, and the native root fires take 1.93 and 1.34 seconds. The failed fire records `schedule.run_failed`. Fixtures keep a 150-second budget and the shared child's 300-second queue timeout. Every parity reader uses `PROOF_OPERATION_FILE`, verifies no Node units, and checks the workflow and trigger. The receipt index is `swamp/proof/receipts/schedule-create-native.json`.

The local checks pass: 58 Gateway cases, 73 CLI cases, six native command cases, charts, registry, docs and PHP analysis. The proof uses disposable Linux Nodes, a proof CA and test commands, with no production data, load or serve restart.

## Conn pairing revocation uses native HTTP steps

`conn.pairing_revoke` follows its command chart with nine native action labels. One bounded curl request lists the T3 sessions, native fan-out revokes the admitted sessions and links, and typed data methods read private replies. The session child, duplicate ownership reads and result-reporting method are gone. Stable indexed behavior slots avoid the 60-second shared behavior lock; their input bounds prevent per-run definition growth. Every step runs on the Swamp role. No script, Node contact or new transport is added.

When a link fails, the Gateway records only pairings whose session phase and link reply completed. The remaining pairings stay open. Admission freezes the links and labels, refuses an expired admin session and locks one environment at a time. A fresh command reconciles missing sessions and incomplete links. Mandatory cleanup removes private replies, so a direct native resume at an HTTP step cannot reuse the deleted directory. This limitation is explicit in the command reference; no custom recovery chain is added.

Three minimal@10 receipts pass at `bd9af9de1b08f4045b03d7a727dd8589ccb635b0`. Each case has one sample, so its observed time is its nearest-rank p95. The fixture covers two sessions and links against a disposable T3 HTTP contract server, a real Gateway and Swamp. It checks partial projection, the current admin, foreign labels, private output filtering and cleanup. It does not exercise a production T3 server, TLS or scale. The initial budget remains 1,800 seconds, with queueTimeout 30 seconds.

| Case | Receipt under `evidence/proof/` | Seconds |
| --- | --- | --- |
| Success | `conn.pairing_revoke/20261102T192331Z-minimal.json` | 10.45 |
| Partial link failure | `conn.pairing_revoke-partial/20261102T192346Z-minimal.json` | 11.47 |
| Invalid inventory | `conn.pairing_revoke-invalid-list/20261102T192306Z-minimal.json` | 6.34 |

Eleven native workflow tests, three typed data tests and 108 Gateway tests pass. PHPStan, Pint, registry, chart, documentation, shape, naming and placement checks pass. The proof first exposed unexpanded phase names in the fixture; corrected fixtures produced the receipts above. A failed provision used an old parent against the new workspace input; the normal Gateway release resolved that mismatch. A later status-read failure was confirmed succeeded before the proof tool reused that same provision. No production serve restart or reload was used.

Cancellation 8222 destroyed task VM 344 at 2026-11-02T19:27:26Z. The lane holds no VM. The rebased runtime files match the proven source.

## Native Schedule manual run

`schedule.run` already uses the shared one-step `task-command-run` workflow with official SSH argv and vault stdin; no execution rewrite or shipped script is needed. Its chart now says that command output stays in the Node journal. The fresh minimal@10 receipt at `f0ac508f0d` takes 2.34 seconds, verifies the marker file and no Node units, and proves that running a disabled Node Schedule leaves its trigger absent. Its one-sample p95 is 2.34 seconds; the fixture budget is 120 seconds and queue timeout 300. The receipt index is `swamp/proof/receipts/schedule-run-native.json`.

Native command success, failure, timeout, context and secret isolation retain their six passing local cases. The proof uses test commands and a proof CA, with no production data or scale.


## Native Schedule enable

`schedule.enable` sets an existing workflow's trigger without rewriting its fixed intent. A copied definition with no workflow still publishes its command and workflow first. The regression fails against the previous extra edit, covers repeat enable, and retains the first-copy case. Fifty-two Gateway cases, PHP analysis, formatting, charts and docs pass.

The fresh minimal@10 receipt at `bf68a00fae` enables a disabled Node Schedule in 0.17 seconds, with no Node units or control run. This is one sample, so observed p95 is 0.17 seconds; its budget is 60 seconds and the shared fire child's queue timeout is 300. The index is `swamp/proof/receipts/schedule-enable-native.json`. No script is added or deleted. The proof uses a test calendar and proof CA, without production data or scale.


## Native Schedule disable

The fresh minimal@10 receipt at `6c0be3e948` covers initial disable and a repeated disable. The measured request takes 0.11 seconds, its one-sample p95, with a 60-second budget and shared fire queue timeout of 300. The workflow remains, its trigger is absent, no Node units appear and no control run starts. The existing native implementation needs no rewrite or shipped script. The chart is marked migrated; `swamp/proof/receipts/schedule-disable-native.json` indexes the passing receipt. The proof uses a test calendar and proof CA, without production data or scale.

## Conn pairing creation keeps each credential separate

`conn.pairing_create` follows its chart with five native labels. One bounded curl request mints the token; the existing data type reads the private reply and stores a native sensitive field. It performs no HTTP call. Gateway admission supplies the complete request body and the same administrative scopes. The reader keeps every HTTP 2xx success, matching the former fetch path. Declared cleanup removes the private reply, and the native assert is the end check.

Each pairing uses its own resource instance and native vault key. The former fixed instance reused the key across issues and could overwrite an unread credential. A native regression issues two pairings before reading either key and checks both values. Gateway projection imports and deletes the exact admitted pairing's key through the existing native frame helpers. Its owner endpoint returns the encrypted link once; a terminal replay makes no further vault read or delete.

Both minimal@10 proofs pass at `657b9c4f40901c1de647d5eee7bd706b8942b2f7`: `evidence/proof/conn.pairing_create/20261102T200448Z-minimal.json` takes 6.38 seconds, and `evidence/proof/conn.pairing_create-rejected/20261102T200458Z-minimal.json` takes 6.37 seconds. Each has one sample and the same observed nearest-rank p95. The initial budget stays 1,800 seconds, with queueTimeout 30 seconds. The fixture uses a disposable T3 HTTP contract service, a real Gateway and Swamp, and the proof account. It checks key deletion without a second secret read, a foreign Node's refusal, one owner read and a rejected admin session. It exercises no production T3 server, TLS or scale.

Five native workflow tests, six data tests and 109 Gateway tests pass, as do PHPStan, Pint, registry, chart, documentation, shape, naming and placement checks. Pinned serve accepts both rendered grant files and their 65 grants in an isolated repository with the normal signed-in account.

When the first success receipts failed parity, the native Operation had succeeded. The metadata CLI needed the harness account environment and an absolute launcher. Its missing-key reply says `vault_inspect_failed` with “does not exist”. Expected HTTP refusals return a nonzero driver status. These fixture checks were corrected before the passing receipt; the production path was unchanged.

Cancellation 8268 destroyed task VM 356 at 2026-11-02T20:08:43Z. The lane holds no VM. The rebased workflow, data type, Conn Gateway code and shared native read/delete helpers match the proven source.


## Native Schedule destroy

`schedule.destroy` removes the trigger, workflow and command key, then records success without a control run or Node visit. The minimal@10 recovery proof exposed two pinned-runtime details: an absent UUID loses the canonical-name grant match, and `workflow.delete` wraps a missing resource as `workflow_delete_failed`. Deletion now uses the stable `schedule-<uuid>` name and accepts only the exact missing-workflow response for that name. Authorization, storage failures and a missing response keep the Schedule `removing`. No grant is widened.

Two fresh receipts at `f42b8ba0e9` prove ordinary removal in 0.12 seconds and removal after the trigger and workflow are already gone in 0.35 seconds. Both verify the command key is gone, with no Node units or control run. The observed p95 is 0.35 seconds for two samples; each fixture has a 60-second budget and the shared fire child's queue timeout of 300. `swamp/proof/receipts/schedule-destroy-native.json` indexes both receipts. Forty-four Gateway cases, PHP analysis, formatting, registry, charts and docs pass; the name and missing-resource regressions failed before the fix. No script is added or removed.

The proof uses a test calendar and proof CA, without production data, load or scale.

## Task publication uses native Git commands and terminal projection

`tasks.publish` keeps its workflow identity. Official SSH argv builds an alternate index and exports the commit; separate worker Git commands initialize and import a private bare repository. The Gateway freezes public execution inputs, supplies small token/header execution copies, and takes the group lock before minting. The task VM receives no token. The Gateway reads PR metadata from its producer and status from the native end assert. Five ordering-only child workflows and result programs are removed; shared `tasks.check` helpers remain.

F9 deviation: the successful non-force push establishes the admitted tip, so PR planning drops its redundant head-SHA comparison. Base and duplicate-PR refusals remain. Pinned e459 drops failed command outputs, so private CLI replies pass immediately to typed readers that fail with public refusal codes. Raw replies stay out of history. Nullable intent also needs `default: null`, even with `anyOf`. Both native limitations are open candidates in `swamp-evaluation.md`.

When cleanup removes scratch, callers start a new command. Native resume cannot recreate the directory from a completed step. The new run gets a fresh token, safely repeats the push and looks up an existing PR. Revocation and remote cleanup are best effort; local cleanup is required. No rollback or runtime ownership check is added.

Three minimal@11 receipts at `b27f2ad40d` pass:

- PR publication: 22.90 seconds; unchanged caller prose and watched fields.
- Push-only publication: 18.58 seconds; no PR call or watched PR.
- Moved workspace: 10.90 seconds; `tasks.head_moved` before export or push.

The observed nearest-rank p95 is 22.90 seconds for three samples, one per branch. The initial run budget is 1,800 seconds and queue timeout is 30. `swamp/proof/receipts/tasks-publish.json` indexes the receipts. Real SSH and Git use a local HTTPS GitHub contract server, a one-file repository and the proof CA. CI is recorded evidence; no production GitHub or scale is exercised. Parity also proves token revocation, scratch removal and the unchanged real Git index.

Eight native publication tests, nine Gateway tests and nine shared data tests pass. Fifty related Gateway tests cover maintenance, dependency merge and workflow grants. PHP analysis, native validation, shape, naming, placement, registry, chart and docs checks pass. Fixture retries exposed Project uniqueness, task-VM ownership, TLS framing and the top-level Task scope; the workflow needed no change.

Cancellation 8321 destroyed task VM 363 at 2026-11-02T21:14:01Z. The lane holds no VM. The publication workflow, data, admission, projection and shared terminal/SSH helpers match the proven source after rebase.

Orbit grows by 190 lines, including the proof index. Runtime code shrinks by 270. Swamp grows by 82: behaviors shrink by 116, workflows by 193 and tests by 79; proof declarations, parity fixtures and the receipt index add 470. The growth proves real SSH, Git and TLS behavior without writing to an external repository. No shipped script is added or removed.

## Instance rollback runs its chart with official link and SSH steps

`instance.rollback` was refused at admission, and its native root was one node-placed TypeScript step that read the Operation back from the Gateway. It now follows its chart on `role=swamp`. Admission holds the Instance, checks the release against the releases its deployment history retains, freezes the release, commit, PHP-FPM service and restartable Processes, and records the running rollback row, so automatic deployment pauses at once.

Six steps run: `Activate release` (`@swamp/cfgmgmt/link` forEach, `current.next` last), `Swap current` (one SSH argv, `mv -fT current.next current`, an atomic rename), `Refresh PHP cache` (one SSH argv, `systemctl reload`), `Restart Processes` (SSH argv forEach, `reload-or-restart` or `docker restart`), `Read current back` (SSH argv `readlink`) and `Check the selection` (native assert on that read). Official `cfgmgmt/link` runs `ln -sfn`, which unlinks before it links, so it never touches the live `current`. The Gateway records the outcome, the selected checkout and the rolled-away commit block from native run data.

F9 deviations: admission reads no Node; the Gateway applies the retention rule to its deployment history. The PHP step reloads instead of converging the pool, because a rollback changes no Instance-level PHP input. The Process step uses SSH argv for both runtimes. The supersede of a queued deploy is not carried over: `StartOperationAction` ignored it already, and production deploys are still refused.

Native finding (pinned e459): a failed `forEach` iteration does not stop later iterations, even with `concurrency: 1`. The `current.next` item therefore guards on a failed earlier `Activate release-` item, and `Swap current` depends on the whole step; the native test proves `current` stays put when the release is gone. An empty `forEach` records no step, so a `dependsOn` on it never resolves; the end check depends only on steps that always run.

Receipt `/home/nckrtl/swamp-execution/evidence/proof/instance.rollback/20261102T233020Z-minimal.json`, source `1fd6a22e70`, minimal@11, Operation 3: proof_pass true, 8.79 s admission to terminal; Activate release 2.20 s, Swap current 1.19 s, Refresh PHP cache 1.57 s, Read current back 1.51 s and Check the selection 0.01 s against a 180 s fixture budget. Parity also proves the production account owns `current` and no `current.next` remains. One sample; the run budget is a provisional 900 s and `queueTimeout` 30. The fixture has no Process: `process.create` on the app-prod peer refuses at its start step in this topology. Four native tests cover the restart path, a failed restart and a removed release. The proof group was cancelled and its VM destroyed after the run.

Orbit shrinks by 689 lines. Swamp behaviors lose 924 lines; the workflow adds 86, native tests change by -29, and the proof fixture adds 103. No shipped script is added or removed.

### WebSocket app creation: report-driven confirmation

`websocket.app_create` already follows R1: the Gateway saves the registry intent and Operation; the pinned Reverb server pulls the registry and its boot report closes the Operation. No workflow, Node command or shipped script is needed. The chart now records this path as migrated. The real Reverb proof accepts the new app's signed publish, retains the pre-existing Gateway connection, and verifies unchanged Reverb PID and boot identities, with no secret in the public Operation.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_create/20261102T213728Z-minimal.json`, source `ef5c581810`, minimal@11, Operation 3. One success: 12.17 s to terminal (sample p95 12.17 s), 13.9 s fixture wall time, 90 s budget; `queueTimeout` does not apply because no Swamp run starts. Scripts deleted: 0. Production code growth: 0; the new `swamp/` lines are proof fixtures and parity checks, including shared revocation checks for the next two kinds. Four stale CLI snapshots now describe the existing record-only Operation rendering.

Limits: one Reverb process, test apps, a VM-local Orbit CA and no production load. The shared topology supplies a notification token missing from the current role renderer. This is bootstrapping evidence, not proof of that renderer or multi-process scale. Existing Gateway tests cover multiple process boots, supersession, refusal and idempotency.

### WebSocket app removal: credential revocation without restart

`websocket.app_destroy` already uses R1 registry intent and Reverb boot reports, with no Swamp run or shipped script. The real Reverb proof creates and subscribes an app, deletes it through the API, verifies its credentials are rejected and its socket closes, and confirms the existing Gateway connection still receives an event. Reverb PID and boot identities stay unchanged. The socket check proves closure, not the exact close code.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_destroy/20261102T214441Z-minimal.json`, source `e8107facd6`, minimal@11, Operation 4. One success: 28.19 s to terminal (sample p95 28.19 s), 54.4 s wall time including app creation, 90 s budget; no applicable `queueTimeout`. Scripts deleted: 0; production growth: 0. New Swamp lines are the fixture and receipt index; parity reuses the creation proof helper. The single-process topology, test credentials, VM-local CA and notification-token bootstrap limits above still apply.

### Delete unused Node placements before stopping workers

The Node-worker audit found an unregistered `ai_router.account_update` workflow and the per-Node worker canary. Neither has a Gateway caller. The live AI account commands use `UpdateAiRouterAccountAction`; their behavior does not change. Both obsolete workflows and their private behaviors and tests are removed. Registered Node placements require their execution and authorization paths to move to 209 before the managed worker cleanup runs. Plain task VMs have no workers either; only expanded proof topologies run sandbox serve (Recall `task-vms-swamp-via-ssh`).

### WebSocket secret rotation: offline Instance and live revocation

`websocket.app_rotate` already uses R1 registry intent and boot reports. The topology proof attaches a test app to a stored Instance, stops that Instance's real Node, rotates through the API, and verifies the stored environment has the new secret. The app ID and key stay fixed and the generation advances once. Reverb accepts the new signature, rejects the old signature and closes the affected app's socket. Its PID and boot identities stay fixed and the existing Gateway connection still receives an event. The check proves socket closure, not the exact close code; environment synchronization and application reload remain separate commands.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_rotate/20261102T215444Z-minimal.json`, source `e66311cda8`, minimal@11, Operation 5. One success: 30.24 s to terminal (sample p95 30.24 s), 38.9 s fixture wall time, 90 s budget; no Swamp run or applicable `queueTimeout`. Scripts deleted: 0; production growth: 0. Swamp growth is proof setup, parity checks and the receipt index. Setup caught a discarded fixed ID and an obsolete Instance column; the fixture now assigns its ID and exits nonzero on unexpected exceptions without printing private data. The single-process, test-credential, VM-local CA and notification-token bootstrap limits above still apply.

### Bootstrap probe placement

`node.bootstrap_probe` already carries its complete intent and vault reference and uses official SSH. Its job and steps now select `role: swamp`, so it needs no worker on the Gateway. The Operation keeps the Gateway binding that owns its bootstrap identity; execution on 209 reads no Gateway callback or Gateway-local key path. The other first-contact roots still use a Gateway-local key path and cannot move by label alone.

One pinned native fixture passes through the official SSH behavior and result decoder, using a disposable host transport and a vault-held key; history and artifacts contain no private key. Its local wall time is 8.3 s, with 18 Gateway tests and two decoder tests passing. The full topology receipt is omitted under the outage exception: recipe `6a340f7049da` has no ready Beast or Shark image. Production latency remains unmeasured; a short read-only probe after release load must confirm the 209 placement.

### WebSocket sync: confirm the current registry without mutation

`websocket.sync` already records an R1 confirmation task without a Swamp run. The real Reverb proof compares the registry version and a private digest of every app and credential before and after sync. Both stay unchanged. Current required boots confirm the existing revision. Reverb keeps the same PID and boot identities. The Gateway connection stays open and receives an event. The chart drops its inaccurate superseded branch: independent syncs do not supersede each other.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.sync/20261102T220216Z-minimal.json`, source `8cfc3d6dc6`, minimal@11, Operation 3. One success: 4.14 s to terminal (sample p95 4.14 s), 5.6 s fixture wall time, 90 s budget; no applicable `queueTimeout`.

Scripts deleted: 0; production growth: 0. New Swamp lines are the proof declaration, shared parity branch and receipt index. The same single-process, test-credential, VM-local CA and notification-token bootstrap limits apply. After the offline-Node rotation proof, held `up` waited for the stopped nested target; starting that owned Node let the same setup finish. No shared topology or production service changed.

Cancellation 8381 destroyed group 2757's VM and completed its cleanup. Across these four kinds, Orbit grows by 415 lines: proof fixtures and receipts add 387, documentation adds 30, and CLI snapshots shrink by two. Production code, behaviors, workflows and shipped scripts have no net growth. The added proof code exercises real Reverb signatures, socket closure and connections through TLS; 62 Gateway tests and 47 CLI tests also pass.

### Gateway session cleanup from 209

`conn.gateway_sessions_revoke` now calls the existing typed Conn revocation workflow from 209, with its complete public intent and an execution-copy vault reference. The parent captures the child's public session receipt; the Gateway records the admitted revision after the one terminal history read. The resource's Gateway Node ID stays its owner, with no Node-bound callback or private-material endpoint. The old callback behavior and secret-source wrapper are removed. The existing scheduled Conn publisher remains separate; moving that admission to registration is outside this worker-placement fix. No shipped script is added.

Registration and revocation share the recorded environment lock. T3 validation runs outside the SQLite transaction; a revocation admitted during validation makes the registration refuse before saving its candidate. Native HTTP or reply failures now use `conn.revocation_failed`, matching the shared revocation path, instead of the old callback's request-specific codes (F9). A failed or cancelled cleanup leaves its revision pending and never revokes a device pairing.

Validation: 114 Gateway tests and 12 pinned native HTTP fixtures pass, including the actual parent/child receipt, current-admin preservation, one inventory read, invalid replies, partial failures, clean reruns and secret scanning. The fixture suite takes 66 s locally. Production p95 is unmeasured; the run budget starts at 1,800 s with a 30 s queue timeout. The topology receipt is omitted under the outage exception: recipe `6a340f7049da` still lacks ready Beast and Shark images. A short read-only source check after release load will verify uptake.

### Mac inspection without a Gateway worker

`node.macos_inspect` now runs two official SSH reads on 209, shapes their public observations, and ends at a native assert. It reads only `uname -sm` and `ifconfig`, as the chart requires. The old agent digest and account ownership checks are removed. Missing stored SSH host keys refuse before the execution copy. The Operation keeps its Gateway identity owner; SSH behavior names use the effect Node, so parallel first-contact Operations do not rewrite one Gateway-wide definition. The existing probe uses the same naming correction.

The native result preserves platform, architecture and missing-tunnel refusal codes. Twenty-three Gateway tests, four data and legacy tests, and two pinned native Mac fixtures pass. Native fixture wall time is 17.9 seconds, including setup. They use a disposable SSH transport fixture with real vault-held keys; no Mac is contacted. Full topology proof is unavailable: recipe build 155 remains `window_closed` for recipe `6a340f7049da`, and the shared Linux topology cannot prove macOS. Production p95 remains unmeasured; the initial budget is 1,800 seconds with a 30-second queue timeout. A served-source check after the release must verify uptake.

Orbit grows by 303 lines. Swamp grows by 232: behaviors add 62, workflows 104 and tests 66; scripts and fixtures add zero. No shipped script is added. The growth supplies the typed observation decoder, declarative DAG and native tests. The decoder performs no transport, execution or runtime ownership check. The cleanup below removes unused writer roots; the native parent remains separate.
### tasks.dependency_merge: native evidence and expected-head recovery

The admission lock now freezes local policy and lineage and mints two repository-scoped tokens. Native commands fetch GitHub and Packagist evidence; existing pure manifest, lock and version policy is retained. The old runtime configuration callback and four credential callbacks are removed. F9: local policy changes after admission apply to the next request, and the duplicate post-evidence PR read is removed because the chart uses GitHub's expected-head PUT.

Native HTTP transport failures now report `dependency_merge.merge_refused`; typed policy refusals keep their specific codes. Failed CLI steps retain no private reply output for the Gateway to classify. Lost replies reconcile on a fresh request after mandatory token and scratch cleanup, rather than resuming a cleaned run. No Project is enabled by this port.

The chart's combined read expands to independent native `gh api` reads, paginated content fan-out and typed evidence shaping. These are separate pinned command operations, not wrapper behavior execution. Required checks and smoke remain exact-head policy checks. The topology uses a disposable HTTPS GitHub contract server and an Orbit CA; it proves admission, scoped minting, native execution, refusal and terminal projection without writing a real repository or testing production scale.

Proof receipts: success `/home/nckrtl/swamp-execution/evidence/proof/tasks.dependency_merge/20261102T225737Z-minimal.json` (20.84 s), breaking-version refusal `tasks.dependency_merge-major/20261102T225800Z-minimal.json` (18.93 s), and merged-head recovery `tasks.dependency_merge-merged/20261102T225816Z-minimal.json` (12.69 s). Source `d7a6aa5ad9`, minimal@11, one sample per case; each sample's p95 equals its duration. Queue timeout is 30 s. The initial run budget remains 1,800 s because the small fixture does not measure populated Composer metadata or production scale. Native HTTP operations are bounded individually.

The first topology run caught that Ubuntu's installed GitHub CLI 2.46 lacks `--slurp`. The workflow now uses supported `--paginate` output, and TypeScript parses separate JSON pages with preserved boundaries. The corrected proof passes all three cases, verifies both token revocations, removes worker scratch and keeps credentials out of public outputs. The lane destroyed its one proof VM after the final run. The 102 data tests, five pinned native scheduler cases and 29 Gateway tests pass. Receipt details and scope limits are indexed in `swamp/proof/receipts/tasks-dependency-merge.json`.

### Remove unreachable bootstrap writer scaffolds

The audit found no production caller of `AdmitNodeBootstrapAction` or `StartNodePhpPackagesAction`; only tests called them. The current `node:add` entrypoint refuses before admission because `node.add` has no registered execution contract. Production deploy captures its PHP package plan directly and does not call the removed admission. The four standalone writer roots had no queued or running Operations during the live audit. Their latest recorded runs were from the older execution shape.

The unused install, host, verify and PHP-package roots, admissions, private identity source and callback tests are removed. The two native bootstrap reads remain. Their intent contains only SSH read preconditions. The shared observer delivery program stays because the role parent still uses it; only its unused host-bootstrap program is deleted. The retained WireGuard native fixture keeps its transport type, without the dead implementation. No script, worker or token is added, and public parent availability is unchanged. Role and production-deployment workflows still use Node placements. Fleet workers stay until those paths run on 209.

Validation: 17 Gateway tests, seven bootstrap data tests and ten observer delivery tests pass. The WireGuard fixture type still checks. The shape gate drops to 4,558 embedded lines and 32 files with Gateway calls; the registry has 109 kinds. Generated API and MCP outputs are rebuilt locally and stay out of Git. This removal needs no topology receipt. A stale test setup now creates its queued hold as a record; it does not try to start an unconfigured native run.

### Gateway session cleanup at registration commit

`conn.gateway_sessions_revoke` now follows its chart from registration through terminal projection. Registration commits its encrypted admin session and incremented revision before admitting the existing native cleanup. Dispatch refusal preserves the successful registration response and leaves the revision pending. The minute-based Conn publisher and its transport wrapper are deleted. F9: cleanup starts immediately after commit instead of waiting for a scheduled scan; manual retry remains explicit.

The full parent intent now records the admitted revision. Its typed child receives only the URL, labels and links it uses. The chart keeps the parent's real `Revoke old sessions` boundary; the shared child still exposes its native HTTP, data shaping, cleanup and assert steps. No shipped script or custom execution behavior is added.

All four minimal@12 cases pass at source `fc5dc5f3fd`: success, invalid inventory, partial revocation failure and explicit retry. The main success receipt is `/home/nckrtl/swamp-execution/evidence/proof/conn.gateway_sessions_revoke/20261103T000919Z-minimal.json`. It follows the registration-admitted Operation to terminal in 12.32 s; that measure excludes the earlier registration admission. The native child takes 10.64 s. The invalid-list, partial-failure and retry receipts take 4.21, 8.21 and 8.41 s respectively. Each case has one sample, so its observed p95 equals its duration.

The initial budget remains 1,800 s with a 30 s queue timeout: two old sessions on a local HTTP contract server do not measure production session counts or network latency. Each HTTP command is independently bounded. Parity checks one inventory read, exact revocations, current-admin and pairing preservation, pending and completed revisions, a completed revision's 204 with no new run, private scratch removal, and public outputs without secret bytes. The registration response remains successful during cleanup failure. No production T3 server, TLS or scale is exercised.

Validation: 117 Gateway, 12 pinned native HTTP and three typed-data tests pass, along with PHP analysis, formatting, shape, naming, placement, registry, chart and docs checks. Isolated-account validation omits this lane's `SWAMP_API_KEY_FILE`; the existing account passes native workflow validation and rendered-grant `serve check-config` without starting serve. No shipped script is added or removed.

Cancellation 8585 destroyed VM 398 at 2026-11-03T00:12:08Z. The lane holds no VM. `swamp/proof/receipts/conn-gateway-sessions-revoke.json` indexes all four receipts.

Orbit grows by 191 lines, including receipts and this report; runtime code shrinks by 110. Swamp grows by 119: behaviors shrink by 106, workflows by 13, tests by 137 and configuration by 17; proof declarations, parity fixtures and receipts add 392. That growth proves registration-triggered execution and recovery through the existing native child without a new execution behavior.

### Project observation: explicit consent and typed terminal evidence

`project.observe` has two root steps: Read the watched PRs, then Refresh the findings index. The input schema replaces the input-read step. The Gateway captures the refresh step's typed result instead of a printed report. PR reads run in parallel with a 60-second command bound. A failed read prevents partial evidence from reaching the refresh step. Observe owns the evidence child; inspect uses its own workflow.

The batch uses main's explicit maintenance setting and its enable/disable actions. Disabled Projects are refused at admission. Consent withdrawn during a run also prevents terminal observation from changing task groups. A Project with no watched PRs needs no GitHub credential. Observe uses a repository-scoped read token and emits no Git authorization copy. The unused authorization grant and obsolete clone-acquisition test are removed. The inspect result and credential branches, `open` flag and routine → observe → detect schedule order are preserved.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/project.observe/20261103T002444Z-minimal.json`, source `b573769a4e`, minimal@13, Operation 3. One success: 4.33 seconds to terminal (sample p95 4.33 seconds), 7.7 seconds fixture wall time. Both root phases pass: 1.547 and 1.267 seconds. Queue timeout is 30 seconds. The initial 1,800-second budget remains because the fixture does not measure a populated Project's parallel GitHub reads.

The topology proves explicit opt-in, the Schedule's internal admission action, native execution and typed terminal projection. It has no watched PRs and creates no task group. It does not prove live GitHub token issuance, a real PR read or production scale. Three native observe cases cover empty input, a merged PR and failed GitHub reads. Parity reads `PROOF_OPERATION_FILE`; the fixture binding names only its internally admitted Operation. The topology uses a VM-local Orbit CA.

All eight native observe/routine cases pass in 81.818 seconds. The 50 Gateway maintenance and grant tests pass with 303 assertions, as do 17 metadata and inspection tests, the two tests for the proof driver, scoped static analysis, formatting, chart and registry checks. The hourly selection update also passes seven metadata and ten Gateway schedule tests. Routine's new selector stays unshipped because e459's user-behavior context has no native history reader; the evidence is recorded in `swamp-evaluation.md`.

Cancellation 8618 destroyed the proof VM after the run. The first cancellation start was refused before native execution; the admitted failed Operation was identified before the new cancellation was requested.

Orbit grows by 251 lines. Swamp grows by 152: behaviors add 31, workflows 22, and proof fixtures, receipts and their documentation 114; tests shrink by 14 and policy by 1. Scripts add zero. The growth supplies typed evidence, declarative input schemas, and proof coverage; the root flow loses two steps.

### Node role addition: native resources

`node.role_add` now freezes role-owned packages, files, accounts, services, containers and publications at admission. Official cfgmgmt resources and single-command SSH steps apply them from Node 209. Their checks feed the native terminal assertion. Node convergence owns the observer and CLI. Role additions require neither a target worker nor an observer hash, and expose no execution callback. No shipped script or rollback chain is added.

The proofs exposed separate topology and kind faults. The caller-only operator needed its synthetic SSH identity removed in minimal@13. Minimal@14 gives the proof worker production's 16 dispatch slots; the shared exporter child orders downloads after package installation. The parent omits duplicate exporter Caddy and PHP publications where it already owns them, preserving its prospective certificate view. A complete Docker CE installation satisfies Docker prerequisites; bounded APT installs cannot remove or upgrade existing packages.

Five passing role fixtures cover AppDev, Database, Reverb, CI and Metrics. The latest receipt is `/home/nckrtl/swamp-execution/evidence/proof/node.role_add.metrics/20261103T005940Z-minimal.json`, source `6daf44fe6f`, minimal@14: 291.56 s, including Metrics HTTPS parity. Fixture p95 is 291.56 s across five role cases, not a production estimate. The budget remains 1,800 s and queue timeout 30 s. The index records each earlier source and runtime. VM 409 is physically absent; fresh teardown operation 8708 completed after the first hub-peer check failed.

Five native resource tests, 32 updated Gateway contract cases, changed-file Deno checks, PHPStan and workflow validation pass. A wider role suite was compared with unchanged main to separate stale legacy expectations from this change. The role-converge and enrollment drafts remain separate and unproved. Growth replaces the hidden role executor with placed resource steps, typed public renderers and real proof fixtures; its measured breakdown is in `swamp/proof/receipts/node-role-add.json`. This batch deletes no shipped script and retains no script gap.

The first push for role addition failed the shape check under the prior rules: that checker allowed vault content in private file apply but not check. The current checker already treats both methods alike. Regression cases now require whole vault content, mode `0600` and control-worker placement for each method. The forward push verifies both current and prior policy against the landed rule; it changes no workflow or credential authority.


## Rule 14: tasks.analyze captures its producer output

The Gateway captures `shape.result_json` directly from its producer in the existing terminal history read; the native outcome assertion still rejects a failed analysis. A failed producer has no captured report and stays failed. The public result, closed error code and exact artifact bytes stay unchanged, including reports larger than the generic native stdout summary.

Minimal@14 proof `tasks.analyze/20261103T014946Z-minimal.json` passed on `ed78065766` in 16.93 seconds (one sample, 20.0 seconds fixture wall time), with a 120-second budget and 30-second queue timeout. The synthetic analyzer's exact report digest and provenance match, the checkout and real index stay unchanged, and temporary output is removed. Four native cases cover report retention, private-output rejection and stale admission; nine Gateway cases cover success, closed failure, absent producer output and a 6,000-byte artifact. No production repository, data or scale was exercised.

The workflow drops from 20 to 19 steps. Separate SSH argv steps for each snapshot and the official copy remain visible; no wrapper behavior combines them. The new command chart replaces its pending entry and accounts for 106 of the 126 net added Orbit lines. Production code drops by 13 lines. Swamp drops by five lines: behaviors zero, workflows minus 19, scripts zero, tests plus three, and proof fixtures plus 11. No shipped script or named gap is added.


## Rule 14: tasks.script captures its producer output

The Gateway captures the bounded public script result from `shape.result_json` in its terminal history read. The workflow drops its shell result printer and keeps the native outcome assertion. A nonzero script exit remains a successful Operation completion; unavailable executables and failed producer output keep their native failure codes. A 5,200-byte Gateway fixture checks that native summary truncation cannot shorten the public result.

Minimal@14 proof `tasks.script/20261103T023838Z-minimal.json` passed on `f195719312` in 16.56 seconds (one sample, 19.2 seconds fixture wall time), with a 120-second budget and 30-second queue timeout. Literal arguments arrive unchanged, the real Git index and checkout stay unchanged, and private output is removed. The native suite passes eight cases for the script and analysis workflows, including redaction, nonzero exits, refusal and resume after later cleanup failure. The Gateway passes 21 cases. No production repository or scale was exercised.

The script workflow drops from 19 to 18 steps. Its new chart leaves the pending list and keeps each official SSH argv visible. Production code drops by 13 lines; the new chart accounts for most of the documentation growth. No shipped script or named gap is added.

## 2026-11-03: tasks.turn captures its public producer output

The turn workflow has 37 steps after removing the terminal result printer. The Gateway captures `shape_result.result_json` in its existing history read. Native `outcome` still follows required cleanup. A producer refusal retains its public code; missing output records a native failure. A later cleanup failure keeps the Operation failed while retaining the completed Pi observation on the AgentThread. Native resume reuses the accepted Pi send.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/tasks-turn-rule14.json) records source `2777a2e00b`, minimal@14 and run `bd0ec7e9-3aff-4f49-a885-43e6db2542ec`. The Operation took 31.11 seconds and the fixture took 34.9 seconds (one sample; budget 180 seconds; queueTimeout 60 seconds). Real SSH and Git proved reviewer restoration, redacted private evidence, one synthetic Pi send and removal of private artifacts. This uses a synthetic Pi server, with no model provider or production repository. The Gateway passes seven cases with 73 assertions. The native fixture passes 14 cases, including refusal, failure and resume, in 174.938 seconds.

The source change adds 177 net Orbit lines, including a new chart of 160 lines. Production code removes 14 net lines. Swamp removes 11 net lines: workflows remove 20, native tests add two and proof fixtures add seven. The chart records the separate official SSH operations and private readers. No custom behavior grows, no shipped script is deleted and no new gap is named. The receipt index and this retro add evidence. The inherited Doctor MDX placeholder is marked as code so Mint can parse the page.

## 2026-11-03: tasks.check reads its execution and CI producers

The check parent captures the push producer receipt and uses native `Check the result` instead of a reporting shell. The scheduled CI reader takes rows directly from `collect`; its reporting shell is removed too. A missing producer receipt records a native failure. Existing failed or changed check evidence, scoped token cleanup and the original CI run binding stay intact. The pinned e459 runtime requires map membership for a step name accessed with brackets; `has()` rejects that form.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/tasks-check-rule14.json) records source `241859d8b4`, minimal@14 and run `49e394f0-f531-4705-b648-fbfade2ccd9c`. The baseline Operation took 22.71 seconds and the fixture took 25.9 seconds (one sample; budget 120 seconds; queueTimeout 30 seconds). Real SSH and Git proved setup ordering, the public receipt, an unchanged index, no candidate or hosted CI, and removal of scratch files. Local native fixtures cover handoff, hosted CI, failures, redaction and token cleanup: 12 cases pass in 80.675 seconds. The Gateway passes 17 cases with 160 assertions, and 22 tests pass for typed data. No production repository or GitHub write is involved in the live proof.

The source removes nine net Orbit lines. Production code removes 28 net lines. Swamp removes 30: workflows remove 26, native tests remove four, and behaviors and fixtures have no net growth. The updated chart adds two net lines and the reference adds two. The receipt index and this retro add evidence. No shipped script is deleted and no new script gap is named.
## Production Instance deployment: native resources

`instance.deploy` keeps the production clone layout agreed with the clone and image-fallback lanes: a private `orbit-app-<ProjectID>` home, durable `.env`, `releases/initial`, and the unchanged root-owned NUL-delimited `release-layout` marker. Deployment creates no account and writes no marker or Node commit file. The first deploy supplies the dedicated PHP-FPM configuration that clone intentionally leaves absent; a compliant native service check supplies the identity the Gateway records.

The legacy reference is `6291bb036`. The native run clones the admitted source and proves that a pinned commit belongs to the branch. It links durable paths, writes the environment and prepares Vite+. Before steps finish before web-root access and the atomic `current` swap. PHP, Processes and Node timers converge before after steps and release cleanup. Branch refusal now precedes shared-directory changes. A populated shared directory is never seeded again.

When cleanup cannot scan a candidate release’s processes, it keeps the release and records a warning. A release with running processes also stays. There is no rollback chain or runtime ownership scan. Captured Node timers use official file and systemd resources after their account group is read; disabled cron Schedules need no Node action. Captured Processes preserve their desired state, so a stopped copy is installed without starting it.

F9 changes: canonical Process definition rows are captured transactionally at admission, while their native installation stays after activation and preserves their desired state. Release retention uses recorded deployment results rather than discovering unrecorded folders. Private user commands receive the rendered environment through native SSH stdin, run as the admitted app account, and receive empty stdin themselves. Their output, including Bash syntax errors, is suppressed before parsing, so a failure records the step and exit status without the legacy output tail. Operation progress replaces duplicate executor events in deployment history. The app-owned Vite+ cache is separate from the shared Node CLI.

The real Vite+ CLI prints its version on the first line, followed by environment details. Its first invocation bootstraps the app cache, so the version step declares `writes: true`. The native check parses only that first line. The disposable Git fixture removes its host aliases after parity so a later topology restage can download real artifacts.

Source preparation failures now use `deployment.source_failed` in place of the legacy aggregate `deployment.prepare_failed`; the native failed step identifies the source action.

When native submission is definitely refused, its admission history row closes without claiming Node effects. An uncertain submission keeps the row running. A successful current-link read overrides earlier swap evidence. With no read-back, a failure before activation keeps the recorded selection; the first clone has none.

The chart retains separate operations exposed by pinned behaviors: credential file apply/removal, clone/checkout and Git reads, PHP file apply/test/systemd apply/reload/check, and selection/commit/service read-backs. The final native assertion consumes resource compliance; no custom report/fail wrapper combines them. Candidate resource improvements are recorded in `swamp-evaluation.md`.

Final production receipts are indexed in `swamp/proof/receipts/instance-deploy-production.json`: first deploy 77.76 s, repeat 47.39 s, Vite+ 77.54 s, before failure 23.50 s, after failure 34.93 s, and off-branch refusal 8.78 s. All six pass on `45769a3352`, minimal@14. Each case has one sample; p95 across the three successful fixtures is 77.76 s. The fixture budget is 960 s and queue timeout 30 s. The production budget remains the configured command timeouts plus 900 s.

The source change removes 4,828 net Orbit lines before the receipt index and this evidence. Swamp behaviors remove 3,891 lines, tests remove 3,455, workflows add 1,315, fixtures add 857, and configuration removes 130. Explicit resource steps replace inline Node programs; the HTTPS Git fixture, real runtimes and six parity cases account for fixture growth. No shipped script file is added or deleted. These fixtures do not measure a production repository, real GitHub token issuance, the release runner or production scale.

## 2026-11-03: tasks.park reads native provider completion

The park workflow drops its terminal Python printer and keeps two steps: `eligible` and `provider`. The canonical provider child still checks the stopped state. The Gateway records the admitted IDs and stop confirmation only after the child succeeds. Failed, interrupted or cancelled runs retain the VM without a stop confirmation. Gateway tests cover the native history read, replay and refusal; 22 cases pass with 161 assertions. Two native parent cases pass in 8.332 seconds.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/tasks-park-rule14.json) records source `fef9c0aed0`, minimal@14 and run `1e33402d-1985-4efe-a81b-77f7e2c78807`. The Operation took 10.77 seconds and the fixture took 35.4 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). A blank nested Incus VM stopped through the real provider, and parity checked its state and admitted IDs. The workspace Node is a stand-in record. No production repository, real PR, GitHub request or UpCloud API is exercised.

The first live attempt correctly failed without recording stop confirmation. Its fixture waited for the Incus agent before logind was ready to handle shutdown. The fixture now uses the minimal Node cloud-init and network setup and starts logind under one bounded command before admission. Production provider behavior stays unchanged.

The source adds 168 net Orbit lines, including a new 60-line chart and 88 lines of proof fixtures. Production code removes 35 net lines. Swamp adds 79 net lines: workflows remove 36, native tests add 27 and proof fixtures add 88. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

## 2026-11-03: task maintenance captures its producer receipt

The maintenance parent and path child replace their reporting shells with native end assertions. The existing producer still computes the bounded receipt from the two Git observations. The Gateway captures its complete `result_json` in the same terminal history read; failed producers retain native failure without a success receipt. Guarded continuation still happens once, after projection commits. Pinned e459 omits guarded steps from CEL, so the path check uses the captured mode before reading optional synchronization.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/tasks-maintenance-rule14.json) records source `7a787170ab`, minimal@16 and native run `43edabf4-42cb-4bd7-a982-c772db17a7eb`. The Operation took 26.62 seconds and the fixture took 30.7 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). Real SSH, Composer and Git proved one routine path with a local package, unchanged admitted identities, HEAD and index. The real Tasks switch is disabled after root admission to prevent a handoff; this fixture proves only the root. It exercises no private repository, GitHub write, model provider or full maintenance chain.

The first live run failed before Node work because PHP sent an empty policy map as an array. The Gateway now encodes that map as an object in native inputs. Tests cover empty and configured policies. Composer emits no JSON audit for an empty lock, so the fixture uses one real local path package; empty locks are outside this proof. Gateway tests pass 17 cases with 80 assertions. Six native cases pass in 29.831 seconds for routine, exact Composer and JS solves, range restoration, changed Git evidence and failed update; nine tests pass for typed data.

Source adds 217 net Orbit lines: the new chart adds 59 and proof fixtures add 101. Production code removes three net lines. Swamp adds 106 net lines: workflows remove ten, native tests add 15 and proof fixtures add 101. Custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

## Native role convergence

`node.role_converge` now uses the role-add resource graph with intent captured at admission. It removes the retained-role branch, sole-role dispatcher and dead add-direct workflow. Official cfgmgmt resources apply and check packages, files, services and containers. Native assertions finish each child and the parent. Node convergence retains ownership of the observer and CLI; Analytics convergence does not create a Process or migrate a database.

Matching Reverb files and units preserve its PID. Matching Metrics containers preserve their IDs and start times. Prometheus configuration is copied onto the mounted inode before SIGHUP, so a running container sees the new content. The official Docker check rejects literal environment arrays because that argument is sensitive; the parent checks the supported public fields and private-file mounts. The unsupported environment comparison remains an evaluation candidate.

Same-Node resource batches run one at a time to bound dispatch memory. Publication and exporter children still run across Nodes. Native tests exercise dispatch intervals, directory repair, service checks and failure paths. The real proof needs a 12 GiB outer VM: three nested Nodes plus Swamp and Gateway exhausted 8 GiB even after the dispatch bound. No production runtime guard or rollback chain is added.

The receipt index is `swamp/proof/receipts/node-role-converge.json`. AppDev repairs mode drift and preserves PHP-FPM PIDs (199.56 s); Reverb preserves its PID (100.51 s). Metrics preserves both container identities (146.32 s) on source `3ec41a9109`, minimal@16. The shared Metrics role-add and exporter-enable proofs pass in 148.24 s and 36.98 s. Each case has one sample; p95 across convergence fixtures is 199.56 s. The operation budget remains 1,800 s and queue timeout 30 s.

Before this receipt index and retrospective, the source removes 632 net Orbit lines. Workflows remove 725 net lines and behaviors remove 350. Charts and references add 210 net lines; proof fixtures add 203 and native tests add 76. No shipped script is added or deleted and no script gap is retained. VM 464 was destroyed through normal cancellation 9073; physical absence was verified.

## 2026-11-03: Schedule definition parent reads native completion

The parent removes its reporting shell and records native `Check the members` completion. The check runs in a separate dependent job: pinned e459 skips a check in the same job when its `forEach` has no members. Copies converge in sequence because the pinned child shares resource definitions on one Node. The Gateway records only public readiness and preserves each copy's desired state. The shared `schedule.create` child remains queued for its own reporting-step conversion.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/schedule-install-definitions-rule14.json) records source `a10320918f`, minimal@16 and native run `a28dc1cb-42b3-4166-8563-2741737fb894`. The Operation took 14.42 seconds and the fixture took 17.6 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). Official resources installed two real service/timer pairs. Parity confirmed root ownership, mode 0644, and disabled and inactive timers. The fixture supplies the public captured plan at the production admission boundary; it does not prove account lookup through the frozen agent, a full production clone, enabled fires or production scale.

Nine Gateway cases pass with 65 assertions. Two native cases pass in 7.835 seconds for empty and two-member plans and a failed child. Native refusal skips the final check. The empty-set regression failed until the end check moved to a dependent job.

Source adds 205 net Orbit lines, including a new chart of 44 lines, 69 lines of proof fixtures and 56 lines of native tests. Production adds two net lines for the separate job, queue timeout and bounded fan-out. Custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

## 2026-11-03: retained Schedule creation checks native resources

The retained Node timer helper drops its reporting shell. Official file checks verify both units and the absence of obsolete files; systemd checks the captured timer state. A native assertion requires all checks to be compliant, because a check method can complete while recording drift. Mutating steps declare `writes: true`. The Gateway reads the end check in its existing history request and records the same public readiness. The chart keeps the retained timer exception beside the native cron path.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/schedule-create-timer-rule14.json) records source `b0df4e0ce6`, minimal@16 and native run `07e53a0f-388b-423f-ae66-7baa104f4cd4`. The Operation took 8.36 seconds and the fixture took 11.5 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). Real file and systemd resources proved root ownership, mode 0644, a disabled and inactive timer, and absence of obsolete files. The fixture supplies captured intent at production admission. It does not exercise agent account lookup, enabled fires, command credentials or a production clone.

The first native operation and parity passed, but the receipt phase named `files-0` instead of the scalar iteration `files-service`. After correcting only the fixture, a fresh run passed. Two native cases pass in 10.203 seconds for compliant resources and file or timer drift. Gateway tests pass 27 cases with 207 assertions across creation and its definition parent.

Source adds 257 net Orbit lines. Charts and references add 35, proof fixtures add 104 and native tests add 62. Production adds 34 lines for the separate official checks and native assertion; custom behavior code stays unchanged. Swamp adds 200 net lines, including fixtures and tests. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

## 2026-11-03: retained Schedule enable reads systemd compliance

The retained Node timer helper drops its reporting shell. Official systemd apply declares `writes: true`; its check records compliance, and the native end assertion refuses drift. The Gateway reads that assertion through its existing terminal history request, preserves desired state on failure and ignores terminal replay. Native cron enable and disable keep their record-only path.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/schedule-enable-timer-rule14.json) records source `9d35c651c8`, minimal@16 and run `e3c3244f-602b-43b2-a853-3f8795961900`. The Operation took 2.18 seconds and the fixture took 13.9 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). Prerequisite native creation took 8.46 seconds. Real systemd parity confirmed an enabled and active timer. Its calendar starts in 2099, so this proof fires no command. The fixture supplies captured intent at production admission; frozen agent account lookup, credentials and a production clone remain outside its scope.

Gateway tests pass 27 cases with 209 assertions across controls and definition copies. Two native cases pass in 7.448 seconds, including drift recorded by a completed official check. Source adds 208 net Orbit lines, chiefly the proof and regression coverage; custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence. Normal cancellation 9133 destroyed VM 472 at 06:37:36Z.

## 2026-11-03: Gateway renders release grants

The Gateway freezes both grants files from the prepared target release before admission. Two parallel official cfgmgmt file resources write them after checkout, as `orbit:orbit`, mode `0640`. The Python helper retains only `release-restart` for Lab #4282. Required checks call the same Gateway renderer and pinned `serve check-config`: complete CEL conditions and JavaScript RegExp safety checks remain Swamp's responsibility.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/gateway-swamp-load-grants.json) records source `d6e3d73024`, minimal@16 and native run `9afa8a87-02a3-4725-93ae-7c15af0cb726`. The real release loader took 13.032 seconds (one sample; budget 600 seconds; queue timeout 120 seconds). Parity checks exact grant content, owner, mode and the served workflow catalog. The fixture uses a local Git origin, prepared manifests and a TLS loopback relay. It does not prove full release preparation, migrations, switching or production scale.

Gateway admission ships before the workflow requires its new fields; the old mode retires only after its served caller is replaced. A checkout moves before workflow reload, so removing both together would break the introducing release. The first proof faults were in fixture queue wiring and missing `jq`; the sandbox now installs the existing release prerequisites. Native CI moves to a hosted runner because the restricted runner refuses PHP setup's sudo. Five Swamp unit cases, ten Gateway cases and five native parser regressions pass.

Before the receipt index and this retrospective, the source adds 356 net Orbit lines. Swamp adds 180: workflows add 47, scripts remove 58, tests remove five and proof fixtures add 196; custom behaviors stay unchanged. Growth captures immutable public grant material and proves the real release boundary. No shipped script or new script gap is added.

## 2026-11-03: retained Schedule removal checks absence

The retained Node timer helper removes its result printer and its idle shell. One bounded SSH argv reads the service state. A native assertion accepts only inactive, failed or unknown before standalone removal. Cascade removal skips that read and stops the service through official systemd. Mutating steps declare `writes: true`. Official file checks and a systemd check feed the native assertion: four paths must be absent and the timer compliant and unloaded. Reload is one bounded SSH argv. The Gateway records native completion and deletes the copy only on success.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/schedule-destroy-timer-rule14.json) records source `8a2becb9ce`, minimal@16 and native run `77aa527f-b015-4d66-b19e-e86f9ee68c3a`. The Operation took 8.34 seconds and the fixture took 20.1 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). Prerequisite creation took 8.32 seconds. Real resource checks and parity confirm four absent files and timer `LoadState=not-found`. The fixture supplies captured intent at production admission. It exercises no frozen agent account lookup, active fire, credentials or production clone.

Gateway tests pass 29 cases with 221 assertions. Three native cases pass in 27.635 seconds: active and activating service refusal before writes, standalone and cascade completion, and file, timer or loaded-unit drift. Source adds 270 net Orbit lines: production adds 50, charts and references add 41, native tests add 65 and proof fixtures add 93; Gateway tests add 21. Custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

Final parity review caught that systemd exit 3 also covers services that are starting or stopping. The first positive proof had passed, but accepting that code alone widened the idle allowlist. The separate native assertion preserves the previous refusal during activation. A fresh topology proved the corrected source.


## Native Node enrollment

`node.add` admits one native enrollment Operation through the public API with HTTP 202. The SDK returns that Operation, the CLI follows its terminal record, and the web form follows it before loading the Node. Trust and architecture checks precede mutations. Official resources apply the baseline, tunnel, observer and optional roles. The native Node assertion determines completion. No script ships and no script gap is retained.

The minimal@16 proof on Shark passes in 75.35 seconds at `e6df5ceb0c`, with a 12 GiB, six-CPU outer VM. The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/node-add.json) records its native run and teardown. Real parity checks managed SSH, the WireGuard handshake, the observer binary and private secret, and DNS requester publication.

This is one roleless Linux sample; Mac branches use native tests, and role children have separate receipts. It exercises no production data or scale. The observed p95 is 75.35 seconds. The full enrollment budget remains 1800 seconds, including optional roles; queue timeout is 30 seconds.

Failed proofs exposed two real defects. A peer-only `wg syncconf` clears the interface key, so both Nodes compose the full sync configuration from their existing private-key file and public peer plan before applying it. The key stays on its Node; native tests scan data and logs. DNS publication requires captured before and after states. CLI publication runs only for an eligible captured plan, after role assignment. The charts show these native steps, and `swamp-evaluation.md` records the remote key-file composition question.

Seven native enrollment cases pass in 108.014 seconds. Gateway tests pass 154 cases with 1106 assertions, CLI tests 27 cases with 141 assertions, SDK tests five cases with 19 assertions, and typed-plan tests six cases. Four browser form tests, ten demo cases, web checks and build pass. Phone and desktop screenshots have no clipping or overlap; console errors are empty. WebKit emulation does not prove physical iOS behavior. The batch removes tests of synchronous HTTP host effects. Native transport, admission, projection and real-topology checks cover that path.

Before the receipt index and retro, this batch adds 1210 net Orbit lines. The new native DAG, typed admission/projection and client contract replace the synchronous public endpoint. Swamp grows by 1624 lines: behaviors +127, configuration +2, proof fixtures +113, tests +280, workflows +1102. The DAG exposes native resources; typed behaviors only bind public observations and frozen plans. It ships no script.

The cleanup deletes the unused Node, certificate and route executor chain.

## 2026-11-03: Schedule lifecycle tests read native completion

The newly ported lifecycle suite still looked for the deleted destroy printer. It now reads `Check the removal`, as creation and enable read their own native checks. Cascade service stop uses one bounded SSH argv: official systemd `ensure: stopped` compares only the `active` state and leaves an activating oneshot alone. The explicit stop preserves the previous cascade behavior without a shell. End checks remain official file and systemd checks plus the native assertion.

Fresh standalone and cascade receipts on `ff8ad3169f` pass: `schedule.destroy.timer/20261103T073252Z-minimal.json` and `schedule.destroy.timer.cascade/20261103T073618Z-minimal.json`. Three destroy lifecycle cases pass in 60 seconds; the activating-service regression passes in 13 seconds. No case is dropped.

## Node role executor cleanup

The native Node parents own enrollment, convergence and relocation. Their shared certificate and publication steps leave the former relocate-direct workflow and its executor graph unused. This batch deletes that workflow, the Node dispatch and footprint helpers, the Caddy and certificate removal programs, and the retained-role Python policy. The fleet manifest drops its unused Node role entry. Sixteen matching shape-baseline entries are removed. The live tests still check grant rendering and release behavior when shipped files change.

Scripts deleted: three programs, comprising one Python source file and two embedded Python programs. No script gap remains for this cut. The tests of the deleted executor and policy are removed because their code has no caller. The remaining Node, certificate and route Deno suites pass 160 cases; both live grant and release tests also pass after rebase. All 202 remaining workflows validate, and the shape and frozen-base gates pass.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/node-role-executor-removal.json) records a fresh `node.role_relocate` proof at `18100d06e9` on minimal@16 and Shark. It passes in 162.30 seconds. Real Metrics parity checks both target containers and readiness, then checks that both source containers and the source Prometheus configuration are absent. This is one sample, with observed p95 162.30 seconds, budget 1800 seconds and queue timeout 30 seconds. The 12 GiB, six-CPU proof VM was destroyed after the run. It exercises no production data, billed hosts or production scale.

The two `role_files_native_test.ts` cases are deleted with the old workflow they load. The native parent receipts cover the resource path.

Before its receipt and retro, the cleanup removes 8319 net Orbit lines. It deletes 46 files and changes five more. The Node enrollment push adds 1268 lines including its evidence; together the two batches reduce Orbit by 7051 lines before this cleanup evidence.

## 2026-11-03: automatic deployment policy captures its own events

The retained scheduled policy drops its result shell. Its existing failure assertion now depends directly on the typed policy producer. The Gateway captures full `fire_json` in the same history request and decodes it before recording events, including partial events from a failed fire. No policy, admission or deployment behavior changes. Native tests cover success and a failed assertion with a producer receipt larger than 6 KiB. Gateway tests cover complete receipts larger than the stdout summary limit, failed fires and replay.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/automatic-production-deploy-rule14.json) records source `4d4f963eb3`, minimal@16 and native run `b3580230-961d-4c32-bca9-54b1b18879cd`. Native steps took 1.124 seconds; run and projection took 3.7 seconds, and the fixture took 4.7 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). The registered root was started manually against empty real Gateway discovery. The production subscriber and queue recorded its exact run ID. There was no deployment Operation, GitHub request, production Node, model provider or calendar timing test.

The proof runner now accepts a scheduled root without fabricating an Operation. `scheduled_run` supplies public inputs, and `after_run` drives the existing production subscriber. Phase budgets and native history remain shared with Operation proofs. Parity still reads `PROOF_OPERATION_FILE`, containing null here, and receives the exact native ID through `PROOF_NATIVE_RUN`.

Source adds 156 net Orbit lines. Production removes eight net lines; shared proof support adds 25, native tests add 45, Gateway tests add 24, proof fixtures add 60 and charts and references add ten. Custom behavior code stays unchanged. Five Gateway cases pass with 28 assertions; two native cases pass in 15 seconds. No shipped script is deleted and no new script gap is named. The retained fleet policy architecture remains outside this reporting-step cleanup.

## 2026-11-03: Project maintenance captures its native selection

The hourly maintenance root drops its result printer. Its native selector is the terminal producer, and the Gateway captures complete `result_json` as `selection_json` in the shared history request. The family decodes that capture before normal admission. Routine, observe and inspect retain that priority order; opt-in, consent, replay and admission rules stay unchanged. Gateway regressions cover the captured selection entering normal routine admission, and native input tests cover the selection order and recorded slots.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/maintenance-projects-rule14.json) records source `f9b28ffc79`, minimal@16 and native run `120623b5-b4b5-479d-8621-ea8f14edd6c5`. The selector took 0.997 seconds; run and projection took 3.53 seconds, and the fixture took 4.6 seconds (one sample; budget 180 seconds; queue timeout 30 seconds). The registered root was started manually against a real Gateway snapshot containing a configured opt-out Project. The production subscriber recorded its exact run ID and admitted no Project work. This proves neither cron timing nor an opted-in downstream Operation, GitHub request, production Node or model provider. Normal cancellation 9206 destroyed VM 480 at 08:15:06Z.

Source adds 96 net Orbit lines, chiefly proof fixtures and regression coverage; production removes ten net lines. Eleven Gateway cases pass with 52 assertions, and six native input cases pass in 30 milliseconds. Custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The receipt index and this retro add evidence.

## 2026-11-03: remove the unused Route LAN wrapper

`route.lan_remove` had no production admission caller. Route parents already pass their captured tuples directly to `route-lan-apply`; no workflow calls the standalone wrapper. This cut deletes that wrapper and its result shell, its Gateway declaration and projector, its unused admission branch and its four dedicated Gateway tests. The shared LAN child stays intact. The eight native LAN cases now invoke that child directly and still cover sharing, withdrawal, reruns, failed writes, ignored writes, multiple tuples and host-key refusal. Eight remaining Gateway verification cases pass with 50 assertions. Static, shape, flow and registry checks pass.

The source removes 216 net Orbit lines, including 93 workflow lines and 70 production PHP lines. No shipped script is deleted and no new gap is named. This is deletion of an unused kind, not a converted kind: no `rule 22 done` claim or new live receipt applies. LAN work remains a step in its parent's run, as the chart rules require.

## 2026-11-03: Caddy publication ends at a native resource check

The complete Node Caddy publication drops its result printer and inline file-write shell. A pinned SSH connection check rejects a wrong request pin before cfgmgmt applies the public Caddyfile as root:caddy, mode 0640. Validation and reload are separate bounded SSH argvs; mutating steps declare writes. The official file check runs after reload, and a native assertion requires compliance. The Gateway captures the validated Caddy version in the existing terminal history read and records ready and published without re-rendering current state.

The [receipt index](https://github.com/hardimpactdev/orbit/blob/main/swamp/proof/receipts/route-caddy-converge-rule14.json) records source `a1cab2ae47`, minimal@16 and native run `58aab1f7-93bd-4b7f-880f-cc0e8a1e9325`. The Operation took 6.33 seconds and the fixture 9.4 seconds (one sample; observed p95 6.33 seconds, budget 180 seconds, queue timeout 30 seconds). Real parity confirms the exact content hash, root:caddy ownership, 0640 mode, valid configuration and active Caddy service. The fixture uses normal internal admission and the real Node renderer. It exercises no public build CLI, full Gateway release switching, populated Route catalog or production data or scale.

Five Caddy native cases cover symlink replacement, reruns, validation and reload failures, drift after reload and wrong-pin refusal before writes. Together with LAN, DNS and publication delta coverage, 27 native cases pass in 91 seconds. Eleven Gateway cases pass with 70 assertions. PHP static checks, docs, shape, placement, names, flow parity and registry checks pass. The flow checker resolves this shared workflow through its exact Gateway declaration; it rejects a changed chart label without restricting the child's parent-kind inputs.

Normal cancellation 9228 destroyed VM 481 at 09:02:15Z. Source adds 164 net Orbit lines, mainly a missing chart, real proof fixture and regression coverage. Custom behavior code stays unchanged. No shipped script is deleted and no new script gap is named. The evaluation records the separate pinned operations and the host-pin and fail-on-drift questions without adding a wrapper.

## 2026-11-03: remove the unused standalone private DNS wrapper

`route.private_dns_publish` has no production admission caller. Node, Route, Metrics and analytics parents already capture their DNS contribution and invoke `route-dns-publish` within their own native run. This cut deletes the unused standalone workflow, including its reporting shell and rollback chain, its direct declaration and its projector branch. It removes the tests of that wrapper; live DNS and publication delta coverage remains. Shared submission tests use a test contribution instead of relying on an unused production kind. The legacy unconverted-envelope test is removed with its unused caller.

Eleven native DNS and publication delta cases pass in 75 seconds; 40 Gateway cases pass with 174 assertions. Static, docs, flow and registry checks pass. No live receipt applies to deleting this unused kind, and no `rule 22 done` claim is made. Source removes 239 net Orbit lines before this retro. No shipped script is deleted, and no new gap is named. The shared DNS child is unchanged.


## 2026-11-03: remove unused standalone Route verification kinds

`route.workload_verify` and `route.public_verify` had only test callers. Native Route parents already own their captured endpoint probes. This cut removes the unused admission action, probe planner, projector, direct declarations, workflow and dedicated tests. The workflow's result printer disappears with it. Shared native probes and parent workflows stay unchanged. Dispatch and certificate-signing tests use a test-only projection, so their coverage does not depend on an unused Route kind.

The four Route workflow cases and 104 Gateway cases pass, including 676 Gateway assertions. This deletes unused code and starts no replacement workflow; no new live receipt or `rule 22 done` claim applies. No shipped script is deleted, and no new gap is named.
