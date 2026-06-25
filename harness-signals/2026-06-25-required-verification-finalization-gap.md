# Signal: Required Verification Finalization Gap

Status: guarded
First seen: 2026-06-25
Last seen: 2026-06-25
Last reviewed: 2026-06-25
Source worktree: php command no-human-renderer loop on Mini
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-codex-pre-tool-use-hook
Guardrail change: required verification rows are now part of the finalization gate; required proof is derived from the branch diff; docs-lint and quality-check proof require matching quality-gate artifacts; retained topology proof replaces Durable E2E as the topology gate
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
signal labels, but it did not mechanically require explicit verification rows
or artifact evidence based on what changed. That left room for an agent to mark
a feature complete while docs-lint, retained topology proof, or `composer
quality-check` was missing, deferred, blocked, or described only as follow-up
prose.

## Guardrail Change

- `LOOP.md.example` now contains `Required verification` rows for retained
  topology proof and `composer quality-check`.
- `HARNESS.md` and the implementation skill now state that blocked, pending,
  skipped, missing, deferred, unresolved, or not-run required verification means
  the loop outcome is `blocked`, not complete.
- `bin/orbit-codex-pre-tool-use-hook` now blocks merge/cleanup when the final
  packet omits required verification rows or records blocked/incomplete status.
- The hook derives required proof from the branch diff. Docs-only diffs require
  a successful `docs-lint` or broader `quality-check` artifact, other diffs
  require a successful `quality-check` artifact, and production PHP diffs also
  require retained topology proof to be `passed`.
- `composer docs-lint` now writes a `docs-lint` artifact under
  `.orbit/quality-gates/`, so docs-only finalization can be proven without
  rerunning Pest or E2E.
- A `Retained topology proof: passed` row must name the topology id/kind,
  inspected roles or nodes, exact command, and captured terminal/session or
  artifact evidence. The hook validates the row status but does not run topology
  commands.
- E2E commands and tests remain in the repository, but agents, skills, hooks,
  release flows, and default scripts must not run or delegate `composer
  test:e2e*`; those commands are manual user-run checks only.

## Verification

```bash
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/FeatureFinalizationGateTest.php
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/QualityGateArtifactsTest.php
php -l bin/orbit-codex-pre-tool-use-hook
composer docs-lint
```

The feature-finalization tests cover missing verification rows, a missing
individual row, blocked retained topology proof, ambiguous blocked outcome text,
and the allowed `not applicable` case.

Diff-derived tests cover docs-only finalization with and without docs-lint
evidence, PHP diffs without quality-check artifacts, non-docs diffs with
quality-check marked not applicable, production PHP diffs with retained topology
proof marked not applicable, and production PHP diffs with artifact-backed
quality-check plus retained topology proof. Quality-gate artifact tests also
pin the manual-only E2E policy across default Composer gates, helper scripts,
and active skills.

## Reappearance Check

If a future loop marks a feature complete with skipped docs-lint, retained
topology proof, or `composer quality-check`, inspect `.orbit/loop.md` and the
branch diff first. If the required verification rows or artifacts are absent or
incomplete and the merge/cleanup still passed, tighten
`bin/orbit-codex-pre-tool-use-hook` and add a test for the missed row/artifact
shape. Stale/timing warnings belong to `composer quality-gate:final-check` and
quality-gate triage. If the rows are present and correctly blocked, classify
the feature as blocked instead of adding another guardrail.

## Curation Notes

Keep separate from the hook best-effort signal: this record is about the
semantics of accepted finalization content, not whether Codex invoked the hook.
