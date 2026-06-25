# Signal: Manual Loop Was Not Wired Into Feature Execution

Status: guarded
First seen: 2026-06-23
Last seen: 2026-06-24
Last reviewed: 2026-06-25
Source worktree: codex/root-harness-anchor-review-ui; post-feature-session-review; post-feature-distillation-reviewer; doctor-progress-scheduler; pre-merge-finalization-hook
Source commit: b269f590; post-feature-session-review; post-feature-distillation-reviewer slice; pending pre-merge-finalization-hook commit
Signal type: review-comment
Guardrail target: .agents/skills/implementing-features/SKILL.md, HARNESS.md, LOOP.md.example, HARNESS_SIGNALS.md, .agents/review-personas/post-feature-distillation.md, .codex/hooks.json, .claude/settings.json, bin/orbit-codex-pre-tool-use-hook
Guardrail change: 38ff38aa; post-feature-session-review; current post-feature distillation reviewer slice; loop-hardening-session-guardrails; pending pre-merge-finalization-hook commit
Related signals:
harness-signals/2026-06-23-cli-ux-needs-pty-analysis-before-human-review.md,
harness-signals/2026-06-23-runtime-proof-vs-repo-proof.md,
harness-signals/2026-06-23-review-persona-needs-workflow-hook.md,
harness-signals/2026-06-23-handoff-needs-next-step.md,
harness-signals/2026-06-24-codex-hook-best-effort-finalization-check.md,
harness-signals/2026-06-24-multi-slice-feature-scratchpad-pre-dispatch.md,
harness-signals/2026-06-24-raw-contract-dropped-during-slicing.md
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

It reappeared again during the Mago replacement feature
(`019efad4-787d-7ba0-b7db-9f58a498990b`). The feature branch was committed,
merged to `main`, quality-checked, and cleaned up, but the final report only
listed verification and cleanup. It did not show a post-feature distillation
packet, fresh-context review, candidate classifications, accepted/rejected
signals, deferred follow-ups, or no-new-signal rationale. The user also named
another Codex thread (`019efa5e-35b1-7d40-8c21-2ccc5e3660e7`) with similar
symptoms, but that exact transcript was not present in the local JSONL, logs,
or memory stores when this guardrail was tightened.

It reappeared again during the Mago baseline cleanup handoff. The loop improver
merged and pushed the feature correctly, but only started the durable loop
hardening after the user pointed out that waiting time should be used for
process fixes, not just worker check-ins or steering. This was already covered
by the Active Loop Improvement section, but the harness did not name the
concrete waiting-time behavior.

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

After the Mago recurrence, Orbit now has project-local Codex and Claude Code
`PreToolUse` hooks. The hooks watch only Bash git merge and feature-cleanup
boundaries. They block `git merge`, `git worktree remove`, and `git branch -d`
when the targeted feature worktree has no `.orbit/loop.md` final-distillation
section or still contains template/pending finalization fields. This turns the
existing manual requirement into a cheap boundary check without mining sessions
on every run or auto-promoting raw `.orbit/` artifacts.

After the Mago baseline cleanup recurrence, `HARNESS.md` now states that
waiting for a worker, reviewer, retained terminal, or quality gate is active
loop time. The loop improver should use that time to inspect evidence, search
signals, update durable scratchpad state, or patch a small guardrail in a
separate harness worktree, and should reserve steering for blockers, idle
workers, or contract drift.

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

For the waiting-time recurrence, run:

```bash
rg -n "Waiting for a feature owner|active loop time|repeated steering" HARNESS.md harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
```

This shows that waiting time is explicitly reserved for evidence inspection,
signal triage, scratchpad updates, and small guardrail work unless the worker is
blocked, idle, or drifting.

For the merge-boundary recurrence, run:

```bash
bin/orbit-codex-pre-tool-use-hook-test
rg -n "Merge Boundary Gate|orbit-codex-pre-tool-use-hook|PreToolUse|Final Distillation" HARNESS.md .codex/hooks.json .claude/settings.json bin/orbit-codex-pre-tool-use-hook harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md
```

This shows the Codex and Claude Code hooks are installed, block missing or
templated final-distillation state, and are discoverable from the root harness
and signal record.

## Reappearance Check

If future implementation reports omit durable signal triage or the
post-feature session review summary, keep this record `recurring` and first
verify whether `bin/orbit-feature-finalization-check` was run before merge or
cleanup. Treat the project-local Codex hook as best-effort only; it may not
intercept every shell execution path. If the explicit check was skipped,
tighten the implementation workflow. If the explicit check ran and allowed
pending final-distillation state, tighten `bin/orbit-codex-pre-tool-use-hook`
and add the missing command shape to `bin/orbit-codex-pre-tool-use-hook-test`.
If fresh reviewers start promoting weak one-off findings, tighten the
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

Reviewed in the 2026-06-25 uniqueness pass. Keep as the broad loop-wiring
record. Related records stay separate when they govern a narrower start gate,
raw-contract preservation check, evidence proof, reviewer hook, handoff surface,
or finalization-enforcement surface with its own reappearance check.
