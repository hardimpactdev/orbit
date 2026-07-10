# Data-Correction Review And Triage

## Scope

- Branch/base: `archive-evidence-metadata-corrections` at `bb89d9c1f4cd8679e908b4322c958758712c76c8`.
- Roadmap: `solo://proj/4/scratchpad/orbit-feature-loop-r--276` revision 116.
- Accepted work: frozen N3 exactly four byte-preserving `.json` to `.txt` evidence relabels; historical N7 removal of three false `release-candidates` copied-entry claims.
- Final reviewed diff: four R100 renames and three JSON array deletions; seven logical records, three inserted formatting lines, six removed lines; no code, tests, docs, index, timestamps, or payload bytes changed.

## Hash And Data Proof

- `gateway-operation-stream-run-after-final-refactor-follow`: Git blob `d18e2c744461a522b0b1dbf50cb5149545bd0b34` before/after in both archives.
- `operation-list-after-fixed-follow`: Git blob `17ba051dfcbe906a7118ebf5034c37865c4f5bf9` before/after in both archives.
- All four renamed targets fail parse-only JSON validation.
- Withdrawn claim: the initial `All 254 remaining .json files parse` result was false. `jq .` accepts empty input as a zero-value JSON stream.
- Fresh analyzer `1008` and an independent PHP sweep found exactly four zero-byte violations: `events-response-subscribe-replay.json` and `gateway-latest-operation-run-after-final-refactor-follow.json`, each duplicated under archives `003430` and `005902`. The full tracked archive corpus contains 1,714 `.json` files: these exact four known exceptions plus 1,710 valid single documents under PHP `JSON_THROW_ON_ERROR`, including top-level false/null.
- Roadmap revision 116 freezes those four newly discovered relabel candidates outside the revision-112 backlog and defers them as non-blocking. The corrected proof is exact exception accounting, not the withdrawn broad pass claim.
- Every remaining `copied_entries` item exists. No affected summary claims `release-candidates`, and no affected archive contains that entry.
- No tracked content references either old `.json` filename.
- `bin/orbit-session-index --check`: current; index hash stayed `277b96c1a5cefb87f9674a4ad4518b9927b2edf3`.
- `git diff HEAD --check`: passed.
- Scoped changed-record privacy scans: zero credential-shaped matches; no matched values printed.

## Solo Lanes

- Implementation worker `1006`: contract met; capture `.orbit/agent-sessions/codex/archive-evidence-metadata-corrections-worker-1006/manifest.json`, status `ok`.
- Independent changed-files reviewer `1007`: `No blockers`; `VERDICT: yes`; capture `.orbit/agent-sessions/codex/archive-evidence-metadata-corrections-reviewer-1007/manifest.json`, status `ok`.
- Fresh analyzer `1008`: `VERDICT: flawed`; capture `.orbit/agent-sessions/codex/archive-evidence-metadata-corrections-analyzer-1008/manifest.json`, status `ok`. Its verification finding is accepted and corrected; its additional file-label opportunity is deferred by roadmap revision 116.
- Reviewer `1007` covers the unchanged final seven-record diff; no post-review data change occurred.
- Expansion worker `1009` was stopped before mutation when the controlling goal amendment arrived. Post-stop capture returned `exact_marker_not_found`; the packet records the explicit no-implementation waiver.

## Aggregate Verification

- Exact-commit `composer quality-check`: passed, 43/43 subgates, artifact `.orbit/quality-gates/quality-check-2026-07-10T125128Z-ecefed6f48ab.json`, duration 83.0s, exit 0, branch `archive-evidence-metadata-corrections`, commit `9e0c38c7e86c1e9d7d118a984797d120049d3ee2`.
- `composer quality-gate:final-check`: exit 0; no gate rerun; timing warnings only.
- Timing classification: `stale/missing baseline`, high confidence. The baseline is 26.0s and names missing source artifact `quality-check-2026-06-26T141254Z-1dec81121c89.json`. Thirteen comparable passing July 10 artifacts span 77–131s with median 94s; the current 102s run is inside that distribution. No changed tests/code can explain a regression.
- Timing action: no rerun and no baseline refresh inside this slice. Existing `harness-signals/2026-06-24-cold-worktree-quality-gate-cache.md` and quality-gate-triage guidance already cover warning-first classification. Reconsider local baseline refresh at program close after the remaining compatible runs, without widening this data correction.

## Corrections And Steering Notes

- Worker `1006` briefly shadowed zsh `PATH` by using the special loop variable `path`; it diagnosed and reran the read-only probe before editing.
- The orchestrator's first independent parse sweep used `jq -e`, which rejects valid false/null JSON. Its replacement used `jq .`, which accepted four empty files as zero-value JSON streams; that false green survived reviewer `1007` and was caught by fresh analyzer `1008`. The corrected validator is a non-empty, single-document PHP `JSON_THROW_ON_ERROR` sweep across every tracked archive JSON file.
- The orchestrator initially treated fallback-generated `agent-sessions` as a copied source entry; the corrected declared-entry probe passed.
- Reviewer `1007` first omitted `--` before a secret regex beginning with hyphens; that read-only probe failed clearly and was rerun with zero matches.
- Analyzer `1008` reproduced the zsh special-variable class with read-only variable `status`; it self-corrected without mutation. The recurrence is deferred because no smallest discoverable guardrail is yet evidenced.
- Claude 943 initially recommended same-slice expansion, but the later user-authored controlling freeze at roadmap revision 116 excludes improvements discovered after revision 112 unless they block representative-trial correctness or security. The expansion worker was stopped before mutation and the four paths are deferred with owner/evidence.

## Product And Runtime Boundaries

- `PRODUCT_DECISIONS.md`: no impact; this is historical evidence metadata correction, not product direction.
- Product docs/tests/code: not affected.
- Orbit skill: not affected.
- Retained topology: not applicable; no runtime, node, CLI, or topology behavior changed.
- No `composer test:e2e*` command, provider provision command, live-node mutation, or release action occurred.
