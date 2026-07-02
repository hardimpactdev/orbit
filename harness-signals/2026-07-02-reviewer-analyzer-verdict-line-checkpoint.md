# Signal: Reviewer Analyzer Verdict Line Checkpoint

Status: guarded
First seen: 2026-07-02
Last seen: 2026-07-02
Last reviewed: 2026-07-02
Source worktree: codex/orbit-agent-v1-contract
Source commit: pending
Signal type: agent-mistake
Guardrail target: .agents/skills/implementing-features/SKILL.md; .agents/review-personas/docs-librarian.md; .agents/review-personas/post-feature-analyzer.md
Guardrail change: current worktree
Related signals: harness-signals/2026-06-23-worker-first-diff-checkpoint.md
Superseded by: none
Tags: solo, reviewer-boundary, analyzer, verdict, implementation-skill

## Signal

During the Orbit Agent v1 product-contract documentation slice, reviewer and
analyzer lanes repeatedly reached the evidence or analysis phase but stalled
before emitting the required machine-parseable final verdict line. Docs reviewer
processes `2234` and `2235` provided scoped review evidence or no-findings
analysis without the exact final line, and analyzer process `2236` stalled after
reading the packet and diff. The feature owner had to replace or bypass those
lanes and use alternate analyzer process `2237` for a final classification.

## Prior Occurrences

No earlier canonical signal was found for this late-stage verdict-line failure.
It is related to `harness-signals/2026-06-23-worker-first-diff-checkpoint.md`,
but that record covers implementation workers that fail to produce an initial
owned diff or blocker. This signal covers reviewers and analyzers that already
did substantive review work but failed the final machine-verdict contract.

## Missing Guardrail

The reviewer personas already required a final `VERDICT:` line, but the
orchestration flow did not define a narrow recovery checkpoint for the case
where useful findings or classifications existed and only the final line was
missing. Without that checkpoint, feature owners could keep prompting a stalled
reviewer or analyzer instead of requesting the line once, then closing or
replacing the lane.

## Guardrail Change

`.agents/skills/implementing-features/SKILL.md` now tells feature owners to
send one verdict-line checkpoint prompt when a reviewer or analyzer has already
produced substantive evidence but omitted the required final verdict line. If
the lane still does not emit the line, the owner should close or replace it,
prefer an alternate runtime for analyzer replacement when practical, and record
the candidate signal before continuing from defensible direct evidence.

`.agents/review-personas/docs-librarian.md` and
`.agents/review-personas/post-feature-analyzer.md` now tell the reviewer or
analyzer to respond with only the required `VERDICT:` line when corrected for a
missing final line after substantive output.

## Verification

`rg -n "verdict-line checkpoint|only the required .*VERDICT|final verdict line" .agents/skills/implementing-features/SKILL.md .agents/review-personas/docs-librarian.md .agents/review-personas/post-feature-analyzer.md harness-signals/2026-07-02-reviewer-analyzer-verdict-line-checkpoint.md`
shows the checkpoint is discoverable from the orchestration skill, both affected
personas, and this signal record.

## Reappearance Check

If a future reviewer or analyzer produces findings, evidence, classifications,
or substantive analysis but omits the exact required final verdict line, prompt
once for only that line from already gathered evidence. If the lane still does
not emit the line, close or replace it instead of continuing to prompt. Treat
another recurrence after this guardrail as evidence that the reviewer/analyzer
launch prompt or runtime selection needs stronger enforcement.

## Curation Notes

Keep this separate from the worker first-diff checkpoint while reviewer and
analyzer lanes have a distinct final-output contract. Consolidate only if a
future harness change introduces a single lifecycle checkpoint that covers both
early worker diffs and late reviewer/analyzer verdict emission.
