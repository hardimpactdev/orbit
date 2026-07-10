# Orbit Current Slice State

This is the committed current-slice template. For non-trivial active work, copy
it to `.orbit/loop.md`, keep that local copy focused on the active slice, and
do not commit active `.orbit` state. Completed session archives under
`.orbit/sessions/` are committed so other machines can inspect them.

Use the compact packet below by default. Escalate to the full multi-slice
variant in `Appendix: Full Multi-Slice Variant` when HARNESS.md routing calls
for it.

## Feature Context

- Scratchpad: `solo://proj/4/scratchpad/quality-check-perfor--300` (roadmap), design `solo://proj/4/scratchpad/quality-check-cpu-sc--299`
- Worktree: `/home/nckrtl/orbit/.worktrees/codex-quality-check-performance`
- Branch: `codex/quality-check-performance`
- Completed slices:
  - profiling: measured the current gate and identified CLI process shims, sleeps, and CPU oversubscription as the dominant costs
  - scheduler: added CPU-token admission, truthful queued/running states, phase-aware gateway/CLI scheduling, and interrupt cleanup
  - test acceleration: balanced the CLI suite, replaced avoidable PHP process shims/sleeps, and fixed a gateway test that leaked to live SSH
- Current slice: final verification, review, and merge preparation

## Done Contract

- Single-slice: no - scheduler/progress, CLI fakes, and verification are distinct but dependent slices
- Parallelization: local read-only investigation was parallelized; shared script and test edits are serialized to avoid overlapping mutations, with independent reviewer passes after each implementation slice
- Done when:
  - quality-check admits components by explicit CPU budget and leaves unadmitted components queued
  - Pest lanes emit profiles and the CLI uses its safe split runner without direct ParaTest
  - avoidable CLI process/sleep costs use deterministic fakes
  - two compatible warm `composer quality-check` runs pass and PTY output proves truthful queue/running transitions
- Evidence:
  - baseline artifact `.orbit/quality-gates/quality-check-2026-07-10T185328Z-2ab3c9ab51ac.json`: 152s total, CLI Pest 143.2s
  - final artifacts `.orbit/quality-gates/quality-check-2026-07-10T214937Z-d9bc6628de34.json` and `.orbit/quality-gates/quality-check-2026-07-10T215046Z-1b02befbfba6.json`: 62s/64s total and exit 0; current-commit confirmation `.orbit/quality-gates/quality-check-2026-07-10T215242Z-db6f0e435479.json`: 63s, CLI Pest 27.1s
- Reviewer checks:
  - implementation spec review and code-quality review for scheduler and CLI fake slices
- Stop if:
  - an E2E command would be required (E2E is explicitly out of scope)
- Pivot if:
  - CLI direct parallel execution remains bootstrap-incompatible; preserve the split runner and optimize deterministic tests instead

## Progress

- Tried:
  Result: implementation complete; two exact-final `composer quality-check` runs passed in 62s/64s with retained Pest profiles and truthful PTY queue transitions
  Next: archive the session and merge the verified branch

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- 2026-07-10/profile: fake executable PHP startup dominated CLI runtime; converted measured fake boundaries to lightweight deterministic executables and updated the existing CLI parallel-bootstrap signal
- 2026-07-10/profile: an Incus failure-path test reached live SSH during cleanup; added the missing host cleanup fake, reducing the isolated test from 60s to 89ms

## Blockers

- none

## Evidence Links

- `bin/orbit-prepare-worktree codex/quality-check-performance` - local isolated worktree bootstrap
- `bin/orbit-cli-pest --exclude-group=slow --profile --compact` - 2171 tests passed in 113.05s
- `bin/orbit-cli-pest-quality --exclude-group=slow --profile --compact` - 2170 tests and 9076 assertions passed in 18.7s standalone
- `.orbit/quality-gates/quality-check-2026-07-10T214937Z-d9bc6628de34.json` - exit 0, 62s total, CLI Pest 27.0s, gateway Pest 57.9s
- `.orbit/quality-gates/quality-check-2026-07-10T215046Z-1b02befbfba6.json` - exit 0, 64s total, CLI Pest 27.5s, gateway Pest 58.4s
- `.orbit/quality-gates/quality-check-2026-07-10T215242Z-db6f0e435479.json` - exact commit `71e100b1d`, exit 0, 63s total, CLI Pest 27.1s, gateway Pest 58.3s
- Session archive: .orbit/sessions/2026-07-10-235737-codex-quality-check-performance

## Harness Signals

- Searched: `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md`
- Created or updated: `harness-signals/2026-06-24-cli-pest-parallel-bootstrap-blocker.md` and generated `harness-signals/index.json`
- Deferred follow-up: none

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion
reporting for any non-trivial feature loop. Use `not applicable` only for truly
tiny local changes with no workers, reviewer findings, retained terminal/PTY
evidence, quality gate artifacts, or human steering.

This section is the canonical local final packet. Scratchpads, reviewer output,
and final reports should point back here instead of becoming parallel final
packets. After the slice or feature loop is complete, archive the active
`.orbit/` session into the persistent, committed project archive home before
worktree cleanup or before rewriting `.orbit/loop.md` for a new slice. The
default archive home is the primary checkout's
`.orbit/sessions/generated-feature-slug/`. `bin/orbit-session-archive`
generates and enforces the archive directory name; run it instead of
hand-writing timestamps, and see HARNESS.md Worktree-Local State for the
naming contract. Preserve every active `.orbit/` entry except `.orbit/sessions/`,
including `loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, and
`agent-sessions/` output from
lane-close `bin/orbit-agent-session-capture` runs. `bin/orbit-session-archive`
copies staged captures byte-for-byte and falls back to archive-time extraction
only when no staged captures exist. Provider session archives are grouped by
LLM and process/session slug and contain
`manifest.json`, `usage.json`, `messages.jsonl`, and raw provider files for
supported providers. Antigravity remains an explicit unsupported/missing
manifest entry or waiver until a reliable local session-file contract is known.
`harness-signals/` remains curated distilled learning, not raw session storage.

Keep the exact Markdown bullet-label shape below.
`bin/orbit-feature-finalization-check` uses those list labels as the mechanical
merge-boundary contract. Equivalent custom headings, bare label lines without
`- ` and `:`, or prose do not replace them. Before merge or cleanup, at least
one of `- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, or `- No-new-signal rationale:` must contain a
meaningful value.

Keep the `- Fresh analyzer:` row even for compact loops. Use an analyzer verdict
when an explicit request or escalation trigger ran the Solo analyzer; use
`not used - rationale` as the normal compact-loop analyzer result when no
trigger applies; use `deferred - reason` only when analyzer infrastructure was
required but unavailable.

- Loop outcome:
  - complete + loop improvement
- Required verification:
  - Retained topology proof: not applicable - quality tooling and deterministic test-fake changes do not alter integrated topology behavior; no E2E lane was executed
  - `composer quality-check`: passed - repeatability artifacts `quality-check-2026-07-10T214937Z-d9bc6628de34.json` and `quality-check-2026-07-10T215046Z-1b02befbfba6.json` passed in 62s/64s; exact-current-commit artifact `quality-check-2026-07-10T215242Z-db6f0e435479.json` passed in 63s
- Finalization gate fit:
  - Quality tooling contracts, docs, focused tests, formatting, PTY progress behavior, and two broad quality-check runs pass; retained topology proof is outside this tooling-only diff
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff: CPU-aware scheduling, safe CLI sharding, Pest timing retention, deterministic fakes, progress validation, docs and harness signal updates
  - Includes worker/reviewer/terminal/evidence pointers: local worktree, Solo scratchpads 299/300, final quality artifacts, PTY progress run, independent code review findings and fixes
  - Includes orchestrator steering notes: user stopped remote Solo execution and required all implementation/testing on this machine; no remote changes were copied
- Agent session capture waivers: Solo process context unavailable because the user stopped remote execution; all implementation, verification, and reviewer work ran in this local task
- Fresh analyzer:
  - Persona: not applicable
  - Solo process or analyzer: not used - compact local tooling loop had independent implementation/spec/code reviews and no analyzer escalation trigger
  - Verdict: not used - rationale above
- Candidate signals:
  - PHP fake executables and live SSH cleanup leakage -> promote -> recorded in the existing CLI parallel-bootstrap signal and covered by focused regression tests
- Accepted durable updates:
  - CPU-token scheduler contract, durable Pest timing profiles, process-tree cleanup, CLI mixed-shard guardrail, and updated parallel-bootstrap signal
- Rejected or already-covered signals:
  - Reviewer catches for config clearing, static peak accounting, interrupt cleanup, and Bash 3 compatibility were fixed before merge and covered by the final broad gate
- Deferred follow-ups:
  - none
- No-new-signal rationale:
  - not applicable - the existing CLI parallel-bootstrap signal was materially updated

Validate either variant before merge with:

```bash
bin/orbit-feature-finalization-check --lint .orbit/loop.md
```

---

## Appendix: Full Multi-Slice Variant

Use this variant for multi-slice features, parallel workers, topology-relevant
diffs, product-contract changes, release scope, or any other HARNESS.md routing
case that escalates beyond the compact packet.

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

- Scratchpad: <required `solo://...` feature roadmap for multi-slice features>
- Worktree:
- Branch:
- Completed slices:
  - <slice>: <one-line outcome>
- Current slice:

## Done Contract

- Active slice start gate:
  - Multi-slice feature roadmap scratchpad exists before worker dispatch:
  - `.orbit/loop.md` links the feature roadmap and names the current slice:
  - If the source scratchpad lives in another Solo project, execution-project
    scratchpad links back to it and mirrors the roadmap substance:
- Parallelization scan:
  - Candidate parallel lanes:
  - Serialized lanes, with required dependency/shared-state/provider-capacity/
    merge-order reason:
  - Deferred lanes (lane -> concrete reason -> owner):
  - Parallel dispatch started (lane -> Solo process or owner):
- Done when:
  -
- Evidence:
  -
- Reviewer checks:
  -
- Stop if:
  -
- Pivot if:
  -

## Progress

- Tried:
  Result:
  Next:

## Candidate Signals While Working

Record possible loop-improvement signals as they happen. The post-feature
analysis classifies this list; it should not have to reconstruct the session
from scattered artifacts.

- <time/source>: <candidate signal, evidence pointer, and current status>

## Blockers

- <blocker, owner, and unblock condition>

## Evidence Links

- <command, result, artifact, retained topology id, Solo terminal/session,
  commit, or report>

## Harness Signals

- Searched:
- Created or updated:
- Deferred follow-up:

## Final Distillation

Fill this before commit, merge-back, session archive, or final completion
reporting for any non-trivial feature loop. Use `not applicable` only for truly
tiny local changes with no workers, reviewer findings, retained terminal/PTY
evidence, quality gate artifacts, or human steering.

This section is the canonical local final packet. Scratchpads, reviewer output,
and final reports should point back here instead of becoming parallel final
packets. After the slice or feature loop is complete, archive the active
`.orbit/` session into the persistent, committed project archive home before
worktree cleanup or before rewriting `.orbit/loop.md` for a new slice. The
default archive home is the primary checkout's
`.orbit/sessions/<timestamp-feature-slug>/`. `bin/orbit-session-archive`
generates and enforces the archive directory name; run it instead of
hand-writing timestamps, and see HARNESS.md Worktree-Local State for the
naming contract. Preserve every active `.orbit/` entry except `.orbit/sessions/`,
including `loop.md`, `.orbit/evidence/`, `.orbit/quality-gates/`, and
`agent-sessions/` output from
lane-close `bin/orbit-agent-session-capture` runs. `bin/orbit-session-archive`
copies staged captures byte-for-byte and falls back to archive-time extraction
only when no staged captures exist. Provider session archives are grouped by
LLM and process/session slug and contain
`manifest.json`, `usage.json`, `messages.jsonl`, and raw provider files for
supported providers. Antigravity remains an explicit unsupported/missing
manifest entry or waiver until a reliable local session-file contract is known.
`harness-signals/` remains curated distilled learning, not raw session storage.

Keep the exact Markdown bullet-label shape below.
`bin/orbit-feature-finalization-check` uses those list labels as the mechanical
merge-boundary contract. Equivalent custom headings, bare label lines without
`- ` and `:`, or prose do not replace them. Before merge or cleanup, at least
one of `- Accepted durable updates:`, `- Rejected or already-covered signals:`,
`- Deferred follow-ups:`, or `- No-new-signal rationale:` must contain a
meaningful value.

Keep the `- Fresh analyzer:` row even for compact loops. Use an analyzer verdict
when an explicit request or escalation trigger ran the Solo analyzer; use
`not used - <rationale>` as the normal compact-loop analyzer result when no
trigger applies; use `deferred - <reason>` only when analyzer infrastructure was
required but unavailable.

- Loop outcome:
  - <complete | blocked | complete + loop improvement>
- Required verification:
  - Retained topology proof: <passed | blocked | not applicable> -
    <retained topology id/kind plus checkout roles or inspected nodes, or host
    topology kind=host-macos; host=<hostname>; os=<Darwin/sw_vers>; command=<exact command>;
    evidence=<terminal/session/artifact/Computer Use evidence>; blocker, or reason>
  - `composer quality-check`: <passed | blocked | not applicable> -
    <command/evidence, blocker, or reason>
- Finalization gate fit:
  - <why the branch diff makes docs-lint, quality-check, and retained topology
    proof passed, blocked, or not applicable; see HARNESS.md Merge Boundary
    Gate>
- Distillation packet:
  - Location: `.orbit/loop.md`
  - Includes objective/final diff:
  - Includes worker/reviewer/terminal/evidence pointers:
  - Includes orchestrator steering notes:
- Agent session capture waivers: <none | provider(s) and reason for missing or unsupported lane-close capture>
- Fresh analyzer:
  - Persona:
  - Solo process or analyzer:
  - Verdict:
- Candidate signals:
  - <candidate -> correct-noop | missed | redundant | wrong-target | defer |
    promote | already-covered | reject -> reason>
- Accepted durable updates:
  - <guardrail target, record, verification, or none>
- Rejected or already-covered signals:
  - <candidate, rationale, existing coverage when already-covered, and note if
    rejected because it was a one-off handoff, reviewer catch fixed before
    merge, stale historical artifact, or ordinary feature work>
- Deferred follow-ups:
  - <follow-up, owner, trigger, or none>
- No-new-signal rationale:
  - <why local cleanup, existing guardrails, already-landed fixes, or rejection
    was enough>
