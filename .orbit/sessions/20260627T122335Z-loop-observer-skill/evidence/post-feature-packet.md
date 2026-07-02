# Post-Feature Packet: Loop Observer Skill

## Objective

Create `.agents/skills/loop-observer` so an LLM can be assigned as a live,
read-only observer while another orchestrator implements a feature through
`implementing-features`. The observer measures wrong turns and friction in
practice without replacing the post-feature analyzer.

## Worktree

- Path: `/Users/nckrtl/orbit/.worktrees/codex-loop-observer-skill`
- Branch: `codex-loop-observer-skill`
- Base commit: `12ecf32c9e58cd0ab450801f822147e2e8c384e2`

## Changed Diff

```bash
git diff -- .agents/skills/loop-observer
```

Changed files:

- `.agents/skills/loop-observer/SKILL.md`
- `.agents/skills/loop-observer/agents/openai.yaml`

## Worker Evidence

- Solo worker: `2186`, `loop-observer-skill-worker`, Grok.
- Worker output captured before deletion:
  `.orbit/evidence/loop-observer-skill-worker-2186.raw.txt`.
- Worker process was deleted after capture.

## Analyzer Evidence

- Solo analyzer: `2187`, `loop-observer-post-feature-analyzer`, Claude Opus.
  Raw output capture failed because the orchestrator mistakenly attempted
  capture and deletion in parallel. Reconstructed report:
  `.orbit/evidence/post-feature-analyzer-2187-reconstructed.md`.
- Final Solo analyzer: `2188`, `loop-observer-final-analyzer`, Claude Opus.
  Raw output captured before deletion:
  `.orbit/evidence/post-feature-analyzer-2188.raw.txt`.
  Verdict: `complete`, `proper with issues`, `ready`, `correct-noop`.

## Verification

- `python3 /Users/nckrtl/.codex/skills/.system/skill-creator/scripts/quick_validate.py .agents/skills/loop-observer`
  - Result: passed, `Skill is valid!`
- `git diff --check -- .agents/skills/loop-observer`
  - Result: passed.
- `composer docs-lint`
  - Result: passed.
  - Artifact:
    `.orbit/quality-gates/docs-lint-2026-06-27T122510Z-78d67ae54b0f.json`.
- `composer quality-check`
  - Result: passed.
  - Artifact:
    `.orbit/quality-gates/quality-check-2026-06-27T122456Z-9ba048233eae.json`.
- `composer quality-gate:final-check`
  - Result: passed; no warnings; did not rerun quality-check or E2E.

## Guardrail And Signal Notes

- Candidate durable signal: none accepted yet.
- Existing coverage checked:
  - `HARNESS.md` and `implementing-features` already require worker worktree
    checks and serialized Solo cleanup.
  - `harness-signals/2026-06-23-worktree-target-before-editing.md` covers
    wrong-worktree worker risk.
  - `harness-signals/2026-06-23-loop-not-wired-to-implementation-skill.md`
    covers loop wiring/post-feature analysis recurrence.
- No new harness signal is proposed from this ordinary skill-creation loop.

## Explicit Deferrals

- Forward-test `loop-observer` during a future real feature loop. This slice
  creates the skill, but does not claim it has already improved live loop
  outcomes.
- Do not wire the skill into `HARNESS.md` role tables or `AGENT_FAST_PATH.md`
  by default yet; the user asked for a skill they can reference explicitly.

## Required Verification Fit

- Retained topology proof: not applicable; no CLI, VM, runtime, or topology
  behavior changed.
- `composer quality-check`: passed. The merge gate treats `.agents/skills/**` as
  a non-product-docs diff even though no PHP/runtime behavior changed.
