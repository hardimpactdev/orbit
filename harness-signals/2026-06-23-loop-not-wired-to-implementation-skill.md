# Signal: Manual Loop Was Not Wired Into Feature Execution

Status: recurring
First seen: 2026-06-23
Last seen: 2026-06-24
Last reviewed: 2026-06-24
Source worktree: codex/root-harness-anchor-review-ui; post-feature-session-review; post-feature-distillation-reviewer; doctor-progress-scheduler
Source commit: b269f590; post-feature-session-review; post-feature-distillation-reviewer slice
Signal type: review-comment
Guardrail target: .agents/skills/implementing-features/SKILL.md, HARNESS.md, LOOP.md.example, HARNESS_SIGNALS.md, .agents/review-personas/post-feature-distillation.md
Guardrail change: 38ff38aa; post-feature-session-review; current post-feature distillation reviewer slice; pending loop-hardening-session-guardrails commit
Related signals:
harness-signals/2026-06-23-cli-ux-needs-pty-analysis-before-human-review.md,
harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md,
harness-signals/2026-06-23-review-persona-needs-workflow-hook.md,
harness-signals/2026-06-23-handoff-needs-next-step.md
Superseded by: none
Tags: implementing-features, workflow, loop-engineering

## Signal

The root loop docs were useful, but a new feature implementation could still
skip them because `.agents/skills/implementing-features/SKILL.md` did not yet
require reading or reporting harness signals.

The same pattern reappeared after `HARNESS.md` gained a
`Post-Feature Session Review` section. The root harness named the review, but
the implementation workflow still did not make it an explicit completion-time
step or final-report section, so future feature owners could finish without
reviewing the feature thread, Solo workers, reviewer output, terminal evidence,
verification output, and human corrections.

## Prior Occurrences

No prior durable signal record existed when the original loop-wiring issue
surfaced. The issue appeared while walking through how a future feature
implementation would use the new harness files.

It then reappeared during the doctor CLI post-feature review. The doctor panel
and issue-label loops had concrete post-readiness corrections already captured
elsewhere: PTY analysis before human UX review, runtime/source-launcher proof,
real-payload CLI review, and explicit next-step reporting. The durable gap was
not another doctor-specific signal; it was that post-feature review itself was
still passive unless the user asked for it.

It reappeared again during quality-gate session distillation. The user had to
ask whether `.orbit/` evidence and long-session artifacts were already being
processed into real learnings. The answer was only partially yes: the workflow
required post-feature review, but the orchestrator could still act as its own
only judge, skip a durable review packet, or over-promote weak ephemeral
artifacts into guardrails.

It reappeared again during the doctor progress/fleet panel dogfood loop. The
outer loop improver captured guidance in the Solo scratchpad and kept nudging
the feature owner, but did not immediately improve the repo harness/skills when
the same process mistakes repeated. The user had to point out that the
scratchpad was only guidance for what still needed to be implemented.

## Missing Guardrail

The durable docs existed, but the main execution workflow did not make them
part of the agent path.

`HARNESS.md` described post-feature review, but the implementation skill did
not say when to run it, which evidence to inspect, or how to report that no new
durable signal remained.

After the later recurrence, the workflow also lacked a fresh-context reviewer
that could classify candidate learnings without being polluted by the feature
session, while still preserving the orchestrator's high-context adjudication.
It also lacked an explicit promotion gate that says raw `.orbit/` artifacts
produce candidates only, not automatic guardrails.

After the doctor progress recurrence, the workflow still under-specified the
active loop-improver role: loop hardening could be postponed to the scratchpad
instead of happening in a project worktree while the feature was still running.

## Guardrail Change

The implementation skill now makes agents read `HARNESS.md`, `LOOP.md`, and
`HARNESS_SIGNALS.md`; triage durable signals during the slice; and include a
`Harness signals` block in the implementation report.

The implementation skill now also requires a Post-Feature Session Review before
commit or completion reporting. That review inspects the feature thread or
handoff, Solo worker sessions, reviewer output, retained terminal or PTY
evidence when applicable, verification output, and human corrections. The
report shape now includes a dedicated `Post-feature session review` block for
evidence reviewed, mistakes found after readiness claims, existing signals
covered, new guardrail changes, and the no-new-signal rationale.

After the 2026-06-24 recurrence, the root harness and implementation skill now
require a local distillation packet for non-trivial loops, a fresh-context
post-feature distillation reviewer, and feature-owner adjudication before any
candidate becomes durable. `HARNESS_SIGNALS.md` defines the promotion gate:
concrete mistake or late catch, recurrence risk, existing-guardrail gap,
counterfactual prevention path, smallest clear target, and narrow
verification. `LOOP.md.example` and the implementation report now require
accepted, rejected, already-covered, deferred, and no-new-signal outcomes.

After the doctor progress recurrence, `HARNESS.md` now includes an Active Loop
Improvement section. It says the scratchpad is guidance/backlog, not the loop
implementation, and requires the loop improver to patch durable repo guardrails
or explicitly reject/defer repeated signals instead of waiting for the user to
ask.

## Verification

`rg -n "HARNESS.md|LOOP.md|HARNESS_SIGNALS.md|Harness signals|guardrail target|durable harness signal|feedback loop" .agents/skills/implementing-features/SKILL.md`
shows the workflow hooks, and `composer docs-lint` exited 0.

For the post-feature-review recurrence, run:

```bash
rg -n "Post-Feature Session Review|post-feature session review|feature thread|human corrections|harness-signals|post-feature distillation|promotion gate|Candidate classifications|Final Distillation" HARNESS.md LOOP.md.example HARNESS_SIGNALS.md .agents/skills/implementing-features/SKILL.md .agents/review-personas/post-feature-distillation.md harness-signals
```

This shows that the root harness, implementation workflow, report shape, and
signal record all expose the review.

For the active-loop recurrence, run:

```bash
rg -n "Active Loop Improvement|scratchpad is guidance|do not wait for the user|loop signal" HARNESS.md harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
```

This shows the active loop-improver duty is discoverable from the root harness
and this signal record.

## Reappearance Check

If future implementation reports omit durable signal triage or the
post-feature session review summary, keep this record `recurring` and tighten
the report template or add a machine-readable final-distillation warning before
merge. If fresh reviewers start promoting weak one-off findings, tighten the
post-feature distillation reviewer or the promotion gate instead of adding more
signal records.

If a future loop-improver only updates a scratchpad after repeated user
corrections and does not patch, reject, or explicitly defer the project
guardrail, keep this record `recurring` and tighten the active-loop section or
the loop-improver handoff.

## Curation Notes

Keep until several feature worktrees have produced implementation reports with
usable harness-signal and post-feature-review sections. Then retire or
consolidate into a broader implementation-report signal.
