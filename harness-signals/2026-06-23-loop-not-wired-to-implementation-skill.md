# Signal: Manual Loop Was Not Wired Into Feature Execution

Status: recurring
First seen: 2026-06-23
Last seen: 2026-06-23
Last reviewed: 2026-06-23
Source worktree: codex/root-harness-anchor-review-ui; post-feature-session-review
Source commit: b269f590; post-feature-session-review
Signal type: review-comment
Guardrail target: .agents/skills/implementing-features/SKILL.md
Guardrail change: 38ff38aa; post-feature-session-review
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

## Missing Guardrail

The durable docs existed, but the main execution workflow did not make them
part of the agent path.

`HARNESS.md` described post-feature review, but the implementation skill did
not say when to run it, which evidence to inspect, or how to report that no new
durable signal remained.

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

## Verification

`rg -n "HARNESS.md|LOOP.md|HARNESS_SIGNALS.md|Harness signals|guardrail target|durable harness signal|feedback loop" .agents/skills/implementing-features/SKILL.md`
shows the workflow hooks, and `composer docs-lint` exited 0.

For the post-feature-review recurrence, run:

```bash
rg -n "Post-Feature Session Review|post-feature session review|feature thread|human corrections|harness-signals" HARNESS.md .agents/skills/implementing-features/SKILL.md harness-signals
```

This shows that the root harness, implementation workflow, report shape, and
signal record all expose the review.

## Reappearance Check

If future implementation reports omit durable signal triage or the
post-feature session review summary, keep this record `recurring` and tighten
the report template or add a review-persona check before merge.

## Curation Notes

Keep until several feature worktrees have produced implementation reports with
usable harness-signal and post-feature-review sections. Then retire or
consolidate into a broader implementation-report signal.
