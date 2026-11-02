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

### WebSocket app creation: report-driven confirmation

`websocket.app_create` already follows R1: the Gateway saves the registry intent and Operation; the pinned Reverb server pulls the registry and its boot report closes the Operation. No workflow, Node command or shipped script is needed. The chart now records this path as migrated. The real Reverb proof accepts the new app's signed publish, retains the pre-existing Gateway connection, and verifies unchanged Reverb PID and boot identities, with no secret in the public Operation.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_create/20261102T213728Z-minimal.json`, source `ef5c581810`, minimal@11, Operation 3. One success: 12.17 s to terminal (sample p95 12.17 s), 13.9 s fixture wall time, 90 s budget; `queueTimeout` does not apply because no Swamp run starts. Scripts deleted: 0. Production code growth: 0; the new `swamp/` lines are proof fixtures and parity checks, including shared revocation checks for the next two kinds. Four stale CLI snapshots now describe the existing record-only Operation rendering.

Limits: one Reverb process, test apps, a VM-local Orbit CA and no production load. The shared topology supplies a notification token missing from the current role renderer. This is bootstrapping evidence, not proof of that renderer or multi-process scale. Existing Gateway tests cover multiple process boots, supersession, refusal and idempotency.

### WebSocket app removal: credential revocation without restart

`websocket.app_destroy` already uses R1 registry intent and Reverb boot reports, with no Swamp run or shipped script. The real Reverb proof creates and subscribes an app, deletes it through the API, verifies its credentials are rejected and its socket closes, and confirms the existing Gateway connection still receives an event. Reverb PID and boot identities stay unchanged. The socket check proves closure, not the exact close code.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_destroy/20261102T214441Z-minimal.json`, source `e8107facd6`, minimal@11, Operation 4. One success: 28.19 s to terminal (sample p95 28.19 s), 54.4 s wall time including app creation, 90 s budget; no applicable `queueTimeout`. Scripts deleted: 0; production growth: 0. New Swamp lines are the fixture and receipt index; parity reuses the creation proof helper. The single-process topology, test credentials, VM-local CA and notification-token bootstrap limits above still apply.

### Delete unused Node placements before stopping workers

The Node-worker audit found an unregistered `ai_router.account_update` workflow and the per-Node worker canary. Neither has a Gateway caller. The live AI account commands use `UpdateAiRouterAccountAction`; their behavior does not change. Both obsolete workflows and their private behaviors and tests are removed. Registered Node placements require their execution and authorization paths to move to 209 before the managed worker cleanup runs. Task VM group workers remain.

### WebSocket secret rotation: offline Instance and live revocation

`websocket.app_rotate` already uses R1 registry intent and boot reports. The topology proof attaches a test app to a stored Instance, stops that Instance's real Node, rotates through the API, and verifies the stored environment has the new secret. The app ID and key stay fixed and the generation advances once. Reverb accepts the new signature, rejects the old signature and closes the affected app's socket. Its PID and boot identities stay fixed and the existing Gateway connection still receives an event. The check proves socket closure, not the exact close code; environment synchronization and application reload remain separate commands.

Receipt: `/home/nckrtl/swamp-execution/evidence/proof/websocket.app_rotate/20261102T215444Z-minimal.json`, source `e66311cda8`, minimal@11, Operation 5. One success: 30.24 s to terminal (sample p95 30.24 s), 38.9 s fixture wall time, 90 s budget; no Swamp run or applicable `queueTimeout`. Scripts deleted: 0; production growth: 0. Swamp growth is proof setup, parity checks and the receipt index. Setup caught a discarded fixed ID and an obsolete Instance column; the fixture now assigns its ID and exits nonzero on unexpected exceptions without printing private data. The single-process, test-credential, VM-local CA and notification-token bootstrap limits above still apply.

### Bootstrap probe placement

`node.bootstrap_probe` already carries its complete intent and vault reference and uses official SSH. Its job and steps now select `role: swamp`, so it needs no worker on the Gateway. The Operation keeps the Gateway binding that owns its bootstrap identity; execution on 209 reads no Gateway callback or Gateway-local key path. The other first-contact roots still use a Gateway-local key path and cannot move by label alone.

One pinned native fixture passes through the official SSH behavior and result decoder, using a disposable host transport and a vault-held key; history and artifacts contain no private key. Its local wall time is 8.3 s, with 18 Gateway tests and two decoder tests passing. The full topology receipt is omitted under the outage exception: recipe `6a340f7049da` has no ready Beast or Shark image. Production latency remains unmeasured; a short read-only probe after release load must confirm the 209 placement.
