# Signal: Required Verification Finalization Gap

Status: guarded
First seen: 2026-06-25
Last seen: 2026-06-25
Last reviewed: 2026-06-25
Source worktree: php command no-human-renderer loop on Mini
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-codex-pre-tool-use-hook
Guardrail change: required verification rows are now part of the finalization gate
Related signals: harness-signals/2026-06-24-codex-hook-best-effort-finalization-check.md
Superseded by: none
Tags: finalization, e2e, verification, loop-engineering

## Signal

A loop improver allowed a feature report to treat the implementation as complete
even though end-to-end proof was not performed. The user caught the gap after
reviewing `solo://proj/4/process/loopimprover-php-use--618`: the final summary
showed no completed E2E lane, but the loop improver did not force a blocked
feature outcome.

## Prior Occurrences

Related finalization work already existed in
`harness-signals/2026-06-24-codex-hook-best-effort-finalization-check.md`, but
that signal covered whether the hook/finalization check runs. This signal covers
the content of the final packet when the gate does run.

## Missing Guardrail

The finalization gate required a `Final Distillation` section and meaningful
signal labels, but it did not mechanically require explicit verification rows.
That left room for an agent to mark a feature complete while durable E2E,
retained CLI proof, or `composer quality-check` was missing, deferred, blocked,
or described only as follow-up prose.

## Guardrail Change

- `LOOP.md.example` now contains `Required verification` rows for Durable E2E,
  retained CLI ingress VM Solo-terminal proof, and `composer quality-check`.
- `HARNESS.md` and the implementation skill now state that blocked, pending,
  skipped, missing, deferred, unresolved, or not-run required verification means
  the loop outcome is `blocked`, not complete.
- `bin/orbit-codex-pre-tool-use-hook` now blocks merge/cleanup when the final
  packet omits required verification rows or records blocked/incomplete status.

## Verification

```bash
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/QualityGateArtifactsTest.php
php -l bin/orbit-codex-pre-tool-use-hook
```

The new feature-finalization tests cover missing verification rows, a missing
individual row, blocked Durable E2E, ambiguous blocked outcome text, and the
allowed `not applicable` case.

## Reappearance Check

If a future loop marks a feature complete with skipped E2E, retained PTY proof,
or `composer quality-check`, inspect `.orbit/loop.md` first. If the required
verification rows are absent or incomplete and the merge/cleanup still passed,
tighten `bin/orbit-codex-pre-tool-use-hook` and add a test for the missed row
shape. If the rows are present and correctly blocked, classify the feature as
blocked instead of adding another guardrail.

## Curation Notes

Keep separate from the hook best-effort signal: this record is about the
semantics of accepted finalization content, not whether Codex invoked the hook.
