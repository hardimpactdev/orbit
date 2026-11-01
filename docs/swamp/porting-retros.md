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
| #2418 | F1 projector renders `<step> timed out after <N>s` from exit 124 plus a bare literal `timeout N` (lane 16) | None | Not a kind | None | A's review; synthetic-history tests | On e459 a failed `command/shell` step keeps no outputs, so the line cannot fire for `run:` steps yet. `/usr/bin/timeout` passes the gate but not the projector |
| #2378 | F1 core: progress, terminal and old-read diagnostics in the Gateway (lane 16) | None | Not a kind | None | Outage exception; A's review | Rebased onto #2372, which deleted the script wrapper; F1 is Gateway-only (A, decision (a)). Lane 26's PASS read the stale head 8a5cdd89 |
| #2391 | Development deploy restores the legacy Vite+ scope rule (E) | +36 / −9 | Unchanged | None | Outage exception; E's review | The #2243 port added a group/world-write refusal legacy never had. L17-PR-2391-01: check that every legacy refusal is kept |
| #2386 | Stuck `removing` guests fail through one rule, with 3,900 s cancel budgets (F) | None | Unchanged | Explicit 3,900 s | Outage exception; F's review | L17-PR-2386-01: no admission `lockKey`, so a second cleanup root can overlap the old native run |
| #2351 | Signed apt repositories for Tools, ADR 0242 (Rex lane) | +851 / −42 | Repository phase per Tool step | Provisional 1,500 s | Recorded F13 exception with live proof and revert | The macOS mutation write has only a client-side bound; the 1,500 s row awaits a measured p95 |
| #2346 | Per-Node firewall source allowlists, ADR 0241 (Rex lane) | +830 / −21 | 4 steps | Phase 60 s; `queueTimeout` 30 | Recorded F13 exception with live proof and revert | Refusal code projects through `okExitCodes`; shares the root-owned UFW host lock |
| #2405 | Sparse Route capture keeps the missing-Caddyfile refusal (lane 8) | +92 / −16 | Unchanged | None | Follow-up to #2383 | L17-2383-01 (unrelated sites dropped on reload) may stay open; C checks |
| #2404 | Explicit `queueTimeout` on database workflows | +64 / −0 | Unchanged | `queueTimeout` 30 | Config only | A per-family root list; A ruled one gate rule replaces it |
| #2214 | Metrics credential boundary | +273 / −431 | Unchanged | None | Lane 17 source review | L17-PR-2214-01: no `queueTimeout`, unbounded remote phases and HTTP connection |
| #2383 | Route publication capture kept below the native frame limit: affected groups only, equal artifacts stored once, numeric references (lane 8, B7) | +374 / −117 | Unchanged | None | Outage exception on B's independent review PASS | Op 6725 admitted 3,487,500 bytes; the 126-site regression fell to 9,394 bytes. The sparse `before` weakens the `caddy.current_config_missing` guard (lane 8 fix PR) |
| #2370 | F1 setup runner normalizes UTF-8 before one-pass redaction and keeps whole lines within 4 KiB (B) | +152 / −16 | Unchanged | None | Outage exception; held create and destroy | The second tail pass still reprocesses markers (bounded); an oversize newest line keeps nothing. L17-PR-2370-01: overlapping secrets leave a short suffix; merge match ranges |
| #2364 | `env:sync` CLI follows its Operation (B) | None | Not a kind | None | CLI and SDK tests | No receipt needed: no admission or projection change |
| #2359 | Cluster Router kinds get an explicit 3,900 s budget (C) | None | Unchanged | 3,900 s from op 6592's 2,417 s × 1.5 | Budget dataset tests | A measured basis, not a borrowed value; replace with the 14-day p95 after the first success |
| #2339 | Failed `task_vm.image_build` attempts settle, and completed cleanup releases its claim (C) | None | Unchanged | None | Gateway regressions | Build 147 stayed running after op 6689 timed out and blocked every retry. L17-PR-2339-01 (no `lockKey` on re-admission), L17-PR-2339-02 (no `queueTimeout`) |
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
| #2335 | `instance.run_steps` setup guard (old shape) | +15 / −34 | Unchanged | None | Outage exception; owner live check op 6798 (Instance 780, 42 s); topology re-proof owed | A stale "running means replay" guard failed every run in 2 s (ops 6703, 6704) after the Gateway began marking Operations running before the native run starts (#1894) |
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
| #2394 | Policy execution fixtures deleted; native admission data repaired | None | None | Test fixtures only |
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
| #2258, #2259 | At grant time, Swamp rejects a pattern with a quantified group holding `+` or `*`; use a flat alternation and run `bin/check-grant-patterns.ts` in `swamp/`. Name `forEach` models from `self._index`, with matching `@swamp/ssh` `globalArgs.name`. |
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
| None yet | | | | | | |
