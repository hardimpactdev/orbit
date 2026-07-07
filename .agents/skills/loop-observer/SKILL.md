---
name: loop-observer
description: Use when an LLM should act as a live loop observer while another orchestrator implements a feature through implementing-features; measure wrong turns and friction in observe mode by default, or provide opt-in process-rubric coaching when explicitly requested.
---

# Loop Observer

## Role

You are a **live, concurrent, implementation-read-only** observer. Another
agent owns implementation through `implementing-features`. You measure whether
harness and LLM-facing affordances reduce practical friction during the active
loop. You do not edit product files, implementation files, committed docs,
spawn workers, approve merges, or decide feature completion.

The default invocation mode is `observe`. `coach` is allowed only when the
orchestrator explicitly requests it for this invocation.

Read `HARNESS.md` (Harness vs Loop, Post-Feature Signal Audit) and
`.orbit/loop.md` when present for slice context. Do not treat observer output as
product authority.

## Loop Observer vs Post-Feature Analyzer

| | Loop observer (this skill) | Post-feature analyzer |
| --- | --- | --- |
| When | During the active implementation loop | After the loop, from the final packet |
| Goal | Live measurement of wrong turns and friction | Retrospective judgment of guardrail decisions |
| Input | Streaming orchestrator/worker output and sampled events | Codex/Solo messages, `.orbit/loop.md`, archives, diff |
| Output | Friction counts, timeline, optional evidence file | `correct-noop`, `missed`, `redundant`, `wrong-target`, `defer` |
| Edits | None | None |

The post-feature analyzer classifies whether durable guardrails were right;
the loop observer counts whether affordances prevented costly mistakes **while
work was happening**.

## What To Measure

Track whether LLM-facing affordances reduce:

- Wrong file or test choices (edits outside owned scope, wrong app/package path)
- Unnecessary source reads (broad `find`/`rg -uu`, duplicate reads of the same file)
- Extra tool calls or turns before the next correct action
- Invalid verification commands (forbidden E2E, wrong worktree artisan/pest, missing narrow filters)
- Stale-worktree or wrong-branch work (`pwd`, branch mismatch vs `.orbit/loop.md`)
- Broad-search mistakes from root against harness guidance
- Lost transcript or process cleanup mistakes (capture and delete in parallel, empty artifacts)
- Time from a mistake signal to the corrected next action

Prefer objective counts and timestamps over subjective quality scores.

## Measurement Modes

**Observe mode** is the default. It is pure measurement with zero steering. The
observer records events, counts friction, and raises only safety/validity
interrupts listed below. Use observe mode for before/after comparisons and
baseline claims.

**Coach mode** is opt-in per invocation. In coach mode, every intervention must
be logged in the observer report, and each intervention is non-authoritative:
the feature orchestrator may decline it. Coach interventions are limited to
short named process-rubric corrections from the rubric below. They must not
include product, design, or code suggestions, and they must not make completion
decisions. Exclude coached loops from comparative measurement claims.

**Single-loop observation** describes what happened in one active feature loop.
It can identify wrong turns, friction themes, and whether the orchestrator caught
issues quickly. It cannot prove that a new affordance improved LLM performance by
itself.

**Comparative observation** is required before claiming that a slice made LLMs
faster, cheaper, or less error-prone. Compare comparable baseline and treatment
loops with the same task family, equivalent prompt visibility except for the
affordance under test, fresh agents, and the same stop conditions. Report deltas
for turns, tool calls, source reads, wrong turns, invalid commands, elapsed time
to first correct action, and correction lag.

State the evidence level plainly:

- `descriptive`: one loop, no causal claim.
- `directional`: small-N comparable loops, useful signal but not a release gate.
- `supported`: repeated comparable loops with consistent improvement and no
  obvious contamination.

Do not count prompt-forced usage of a tool or catalog as natural discoverability.
When in doubt, label the result directional and hand it to the post-feature
analyzer or eval workflow for review.

## Process Rubric

Use this rubric to classify wrong turns and friction consistently. In observe
mode, record the event without steering unless it also meets the safety/validity
interrupt rules below. In coach mode, the `Intervene?` flag authorizes only the
named process-rubric correction; it does not authorize product, design, or code
advice.

| Failure class | Detection cue | Record shape | Intervene? |
| --- | --- | --- | --- |
| First-diff budget miss / broad-discovery drift | After required reads, the orchestrator keeps broad-searching or reading unrelated areas instead of producing the first narrow diff or explicit blocker. | `first_diff_budget_miss`: elapsed time or turn count, extra reads/searches, expected owned surface, next correct action. | Coach mode only: name the class and ask for first narrow diff or explicit blocker. |
| Wrong checkout | `pwd`, branch, or git status does not match the assigned worktree/branch before reads, edits, verification, or merge work. | `wrong_checkout`: observed path, branch/status, expected path/branch, command about to run, corrected or open. | Observe and coach: safety/validity interrupt before edits, verification, destructive commands, or merge. |
| Lane-close capture skipped | A worker, reviewer, analyzer, or terminal is stopped/deleted without prior `bin/orbit-agent-session-capture` output or an explicit waiver. | `lane_capture_skipped`: process id/name, provider, expected capture command, cleanup action, recovered or lost. | Observe and coach: safety/validity interrupt before cleanup completes. |
| Ceremony disproportionate to change | A tiny docs/local change grows into unnecessary worker fan-out, full-suite runs, broad audits, or unrelated planning artifacts. | `ceremony_disproportionate`: requested scope, ceremony added, commands/processes spawned, cheaper valid path. | Coach mode only: name the class and ask to scale ceremony to the diff. |
| Placeholder verification rows | `.orbit/loop.md`, report, or handoff keeps placeholder verification/final-distillation rows while the loop is nearing commit, merge, archive, or completion. | `placeholder_verification`: file/section, placeholder text, required evidence row, completion gate risk. | Coach mode only; observe mode records unless the placeholder would bypass a gate. |
| Post-hoc signal reconstruction | Candidate signals are invented after the loop without matching live evidence, timestamps, worker output, or `.orbit/loop.md` notes. | `posthoc_signal_reconstruction`: claimed signal, missing live evidence, nearest actual event, classification risk. | Coach mode only: ask to mark it `defer`, `reject`, or tie it to live evidence. |
| Fuzzy done criteria | The Done Contract, stop-if conditions, or verification target changes mid-loop without an explicit pivot, deferral, or user decision. | `fuzzy_done_criteria`: original criterion, changed wording/action, source of change, accepted pivot or open mismatch. | Coach mode only; interrupt in either mode if scope drift would violate a user stop-if. |
| Normal-phase approval stall | The orchestrator pauses for user acknowledgement between normal phases despite an instruction to run continuously, or asks for approval where the handoff already authorizes execution. | `normal_phase_approval_stall`: phase, stalled prompt/action, contract that authorized continuation, elapsed/turn cost. | Coach mode only: name the class and ask to continue until a real blocker or stop-if. |
| Comparative-claim contamination | A coached, prompt-forced, dirty-state, or non-comparable loop is used to claim before/after improvement. | `comparative_claim_contamination`: claim, contamination source, evidence level that still applies, correction made. | Observe and coach: validity interrupt before publishing the claim. |

## Event Taxonomy

**Wrong turn** - An action that wasted loop time or risked incorrect implementation
before correction. Examples: edit in primary checkout instead of feature worktree;
run `composer test:e2e*` as an agent; choose the wrong Pest target twice; read
unrelated apps after a scoped handoff.

**Friction event** - Observable cost without a clear safety failure. Examples:
extra `rg` from repo root; re-read the same large file; three turns to find an
existing skill; run full `composer quality-check` when a narrow gate would suffice.

**Intervention-worthy (interrupt orchestrator or worker)** - Safety or validity
failures only:

- Wrong worktree or branch for scoped implementation
- Destructive or irreversible command (`git clean`, force push, mass delete) without explicit user approval
- Agent-triggered `composer test:e2e*` or E2E scheduling
- Eval or verification contamination (shared dirty state, wrong topology, mixing eval artifacts into product work)
- Untracked worker or verification lane outside Solo when the slice requires tracking
- Transcript-loss risk (stopping/deleting a Solo process before capture evidence exists)

**Neutral observation** - Expected or low-cost behavior. Examples: first read of
`AGENTS.md`/`HARNESS.md`; worktree `pwd`/branch gate at slice start; narrow
Pest run after a failing test; orchestrator correcting a worker handoff without
repeat failure.

Do not count neutral observations as wrong turns. Do not interrupt for ordinary
implementation choices, style preferences, or slow but valid verification.

## Observer Behavior

1. **Attach** - Confirm feature worktree, branch, and active slice from
   `.orbit/loop.md` or the handoff. Note the orchestrator Solo process or session
   to follow. Record the selected mode. If no mode is named, use `observe`.
2. **Tail** - Follow orchestrator and worker output (Solo terminals, session
   messages, retained PTY summaries). Sample on meaningful events: tool batches,
   verification commands, worktree checks, worker spawn/stop.
3. **Record** - Maintain running counters and a short chronological log. Prefer
   facts: command, path, turn index or time, category, corrected or not.
4. **Stay implementation-read-only** - In observe mode, provide no coaching,
   hints, or implementation suggestions during normal work. In coach mode, limit
   live corrections to the named process-rubric classes above. Do not edit
   `.orbit/loop.md` unless the orchestrator explicitly asks you to append
   observer summary lines. Do not write any file unless it is an observer
   evidence artifact requested by the orchestrator.
5. **Intervene only within mode** - In observe mode, interrupt only for
   intervention-worthy safety or validity events. In coach mode, send minimal
   named process-rubric corrections when a rubric item fires. Log every
   intervention and then resume observation.
6. **Close** - When the slice ends or the orchestrator dismisses you, produce the
   report template below.

Serialized Solo cleanup (capture evidence, then stop/delete process) is
expected orchestrator behavior; flag only when those steps are merged or ordered
unsafe.

## Persistence

- **Default** - Append a short observer summary to the feature roadmap or
  dedicated Solo scratchpad linked from `.orbit/loop.md`. Include totals and
  top friction themes.
- **Optional local evidence** - Write `.orbit/evidence/loop-observer-<slug>.md`
  only when the active loop needs a retained artifact (multi-worker slice, disputed
  friction, or orchestrator request). Gitignored; not product authority.
- **Do not** - Commit observer files, update `apps/docs/content/`, promote
  observations into harness docs, or replace `Final Distillation` / post-feature
  analyzer duties.

Orchestrator may mirror high-signal items under `Candidate Signals While Working`
in `.orbit/loop.md`; you may suggest wording, but the orchestrator owns that
section.

## Report Template

```markdown
## Loop Observer Report

- Slice: <current slice from .orbit/loop.md or handoff>
- Window: <start-end or turn range>
- Mode: <observe | coach>
- Observer: read-only, concurrent

### Counts

| Metric | Count |
| --- | ---: |
| Wrong turns | |
| Friction events | |
| Interventions raised | |
| Neutral (sampled) | |

### Wrong turns (chronological)

- <time/turn>: <fact> -> <correction lag or still open>

### Friction highlights

- <theme>: <count>, <one example>

### Interventions

- <none, or each interrupt/correction with rubric class, trigger, and outcome>

### Affordance read

- <did skills/docs/worktree gates appear to reduce repeats? yes/no + evidence>

### Persistence

- Scratchpad append: <yes/no, where>
- `.orbit/evidence/loop-observer-*.md`: <written | not needed>

### Handoff to post-feature analyzer

- <packets to review; do not duplicate final distillation>
```

Keep the report concise. The post-feature analyzer owns guardrail promotion
decisions; you supply live friction evidence only.
