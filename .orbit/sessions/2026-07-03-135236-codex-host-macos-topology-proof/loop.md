# Orbit Current Slice State

This is the committed current-slice template. For non-trivial active work, copy
it to `.orbit/loop.md`, keep that local copy focused on the active slice, and
do not commit active `.orbit` state. Completed session archives under
`.orbit/sessions/` are committed so other machines can inspect them.

When a feature has multiple slices, archive the completed active `.orbit/`
session into the persistent project archive home before rewriting
`.orbit/loop.md` at the start of the next slice. The default archive home is
the primary checkout's `.orbit/sessions/<timestamp-feature-slug>/`.
`bin/orbit-session-archive` generates and enforces the archive directory name;
run it instead of hand-writing timestamps, and see HARNESS.md Worktree-Local
State for the naming contract. Do not leave the soon-to-be-removed feature
worktree as the only copy. Copy every active `.orbit/` entry except
`.orbit/sessions/`. Keep durable feature history, slice outcomes, and ordering
in the feature scratchpad and session archives. Keep code history in Git.

## Feature Context

- Scratchpad: none, single-slice
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-host-macos-topology-proof`
- Branch: `codex/host-macos-topology-proof`
- Completed slices:
  - none
- Current slice: host-Mac topology proof routing for native Orbit Agent/Tauri work

## Done Contract

- Single-slice: yes - one harness/test/docs behavior change with shared finalization files
- Parallelization: serial - the tests, helper, and documentation describe the same gate contract and should move together
- Done when:
  - `bin/orbit-codex-pre-tool-use-hook` detects native `apps/agent` diffs and requires `host-macos` topology proof on Darwin.
  - The same gate blocks native Orbit Agent finalization on non-Darwin hosts instead of accepting retained Incus as the substitute.
  - `HARNESS.md`, `LOOP.md.example`, `implementing-features`, the Tauri skill/reviewer, and testing docs describe the host-Mac topology path.
- Evidence:
  - Focused red/green Pest for finalization behavior.
  - Docs-lint and quality-check artifacts.
  - Finalization lint/merge gate proof before merge.
- Reviewer checks:
  - Self-review changed finalization and documentation contract; no spawned reviewer because this is harness policy plumbing, not native `apps/agent` source.
- Stop if:
  - Current finalization helper cannot classify native agent changes from the branch diff.
  - Required proof would force running manual E2E lanes or live node mutation.
- Pivot if:
  - Existing docs already define a topology target resolver elsewhere; update that authority instead of duplicating language.

## Progress

- Tried: Added red Pest cases for native Orbit Agent finalization on Darwin and non-Darwin hosts.
  Result: Red proof failed as expected: current gate allowed missing/non-Darwin host proof for native `apps/agent` diffs.
  Next: Implemented native Agent diff classification and host-Mac proof validation in `bin/orbit-codex-pre-tool-use-hook`.
- Tried: Updated `HARNESS.md`, `LOOP.md.example`, implementation/Tauri skills, Tauri reviewer, and testing docs.
  Result: Docs and skills now route native Orbit Agent live topology proof to the implementing Mac host.
  Next: Ran focused Pest, docs-lint, `composer quality-check`, and final-check.

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- 2026-07-03/Codex: native Orbit Agent live topology proof was described as retained Incus by default, but Tauri app behavior must use the implementing Mac host; promoting this into the finalization gate and Tauri guidance in this slice.

## Blockers

- none

## Evidence Links

- `pwd && git branch --show-current && git status --short --branch`: worktree `/Users/nckrtl/orbit/.worktrees/codex-host-macos-topology-proof`, branch `codex/host-macos-topology-proof`, clean.
- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php --filter='native Orbit Agent'`: red before implementation, 2 failing assertions showing missing host proof and non-Darwin proof were allowed.
- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`: passed, 37 tests, 81 assertions.
- `php -l bin/orbit-codex-pre-tool-use-hook`: passed.
- `bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/FeatureFinalizationGateTest.php`: passed after formatting.
- `composer docs-lint`: passed on commit `184209fd`; artifact `.orbit/quality-gates/docs-lint-2026-07-03T115138Z-9a702e93f1ee.json`; only pre-existing Solo command-doc warnings remained.
- `composer quality-check`: passed on commit `184209fd`; artifact `.orbit/quality-gates/quality-check-2026-07-03T115120Z-8362d1d12893.json`; default gate including Gateway Pest, docs-lint, CLI/docs/core/sdk Pest, and Agent Cargo checks.
- `composer quality-gate:final-check`: passed on commit `184209fd`; warning-only local timing deltas remained.
- Session archive: .orbit/sessions/2026-07-03-135236-codex-host-macos-topology-proof

## Harness Signals

- Searched: `rg -n "Tauri|agent|host-macos|live topology|retained topology|feature-finalization|quality-check|finalization" /Users/nckrtl/.codex/memories/MEMORY.md`; `rg` across `HARNESS.md`, `.agents/skills`, tests, and testing docs.
- Created or updated: no separate `harness-signals/` record; this approved process gap is covered directly by executable gate tests plus `HARNESS.md`, skills, reviewer, and testing docs.
- Deferred follow-up: none

## Final Distillation

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: not applicable - branch changes finalization harness, docs, skills, and tests; it adds host-Mac enforcement for future native `apps/agent` diffs but does not change native Agent source or topology runtime behavior itself.
  - `composer quality-check`: passed - `composer quality-check` exit 0 on commit `184209fd`; artifact `.orbit/quality-gates/quality-check-2026-07-03T115120Z-8362d1d12893.json`.
- Finalization gate fit:
  - Non-docs harness diff requires successful `composer quality-check`; no topology proof is required for this branch because the executable gate tests simulate the native `apps/agent` topology cases without changing Agent source.
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: yes - host-Mac topology proof routing for native Orbit Agent work.
  - Includes worker/reviewer/terminal/evidence pointers: yes - local direct implementation, focused Pest, docs-lint, quality-check, and final-check outputs are listed in Evidence Links.
  - Includes orchestrator steering notes: yes - this approved process gap was promoted directly into the gate, harness, skills, reviewer, docs, and tests.
- Fresh analyzer:
  - deferred - no Solo analyzer was spawned for this single-slice Codex-app harness change; local self-review and executable finalization tests covered the changed contract.
- Candidate signals:
  - native Orbit Agent topology proof target -> promote -> executable finalization gate and Tauri guidance now require host-Mac proof instead of retained Incus for native `apps/agent` diffs.
- Accepted durable updates:
  - `bin/orbit-codex-pre-tool-use-hook` and `FeatureFinalizationGateTest.php` enforce native Agent host-Mac finalization behavior.
  - `HARNESS.md`, `LOOP.md.example`, `.agents/skills/implementing-features/SKILL.md`, `.agents/skills/tauri-agent-development/SKILL.md`, `.agents/review-personas/tauri-agent.md`, and testing docs document the same host-Mac proof contract.
- Rejected or already-covered signals:
  - Separate `harness-signals/` record rejected - the approved gap is fully covered by the executable gate, tests, and durable guidance in the same diff.
- Deferred follow-ups:
  - none
- No-new-signal rationale:
  - The only durable signal from this slice was the host-Mac topology routing gap, and it was handled directly in the finalization gate plus the guidance surfaces agents already read for Tauri work.

---

## Appendix: Compact Single-Slice Variant

Use the full template above for multi-slice loops. When the request truly fits
one slice, you may collapse the start gate and the parallelization scan to one
line each while keeping every mechanical label the finalization gate keys on.

In the compact variant, replace the `Active slice start gate` and
`Parallelization scan` blocks under `## Done Contract` with exactly these two
lines:

- Single-slice: yes - <why this request fits one slice>
- Parallelization: serial - <concrete dependency, shared-state,
  provider-capacity, or merge-order reason>

Everything else keeps the same labels as the full template. The gate still
requires, verbatim: `## Final Distillation`, `- Loop outcome:`,
`- Required verification:` with the `Retained topology proof` and
`` `composer quality-check` `` rows, `- Fresh analyzer:` (a value of
`deferred - <reason>` is accepted with a warning), and at least one meaningful
value among `- Accepted durable updates:`,
`- Rejected or already-covered signals:`, `- Deferred follow-ups:`, or
`- No-new-signal rationale:`. The `- Loop outcome:` value must be exactly one
of `complete`, `blocked`, or `complete + loop improvement`.

Archiving is unchanged: `bin/orbit-session-archive` generates the archive
directory name; run it instead of hand-writing timestamps, and see the tool's
`--help` for slug and destination options.

Validate either variant before merge with:

```bash
bin/orbit-feature-finalization-check --lint .orbit/loop.md
```
