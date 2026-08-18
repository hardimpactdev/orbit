# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: /private/tmp/claude-501/-Users-nckrtl-orbit/9d84cd44-8ab7-472d-94d1-62c9fa19f7c9/scratchpad/brief-216.md
- Worktree: /Users/nckrtl/orbit/.worktrees/solo-hardening-skill-plan-dto
- Branch: solo-hardening-skill-plan-dto

## Goal

Re-type skill-install target resolution as a final readonly SkillTargetResolution DTO and restore final on SkillTargetResolver with no plan/execute behavior change.

## Scope

- Owned: apps/cli skill-install plan flow (SkillTargetResolution, SkillTargetResolver, SkillInstallActions, skill-install unit test types)
- Constraints: no behavior change to 769 immutable plan/execute split, re-validation, or destructive-consent fail-closed; do not move the DTO to packages/core
- Out of scope: gateway, docs, command UX, live nodes, merge/push

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact --filter=SkillInstall` 25 passed 104 assertions on the pre-merge tip; full CLI suite 2617 passed
  - broader: passed - `composer quality-check` on clean merged commit 7a67e768be68711ec05dae6b646fedb22bc754e2 exit 0, 45/45 subgates (`.orbit/quality-gates/quality-check-2026-08-18T185942Z-8b1ccbbd64da.json`)
  - runtime: passed - candidate=7a67e768be68711ec05dae6b646fedb22bc754e2; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-ebcbbb-operator; expected=exact candidate resolves skill install targets through the restored readonly DTO with unchanged plan-execute and destructive-consent behavior in the routed retained operator environment; observed=matching SkillTargetResolution sha256 ec070e8170ef and 25 tests passed 104 assertions in the retained operator instance; result=passed; evidence=`.orbit/evidence/solo-hardening-skill-plan-dto-retained-incus-runtime.txt`
- Blast radius: complete - evidence=rg inventory of the resolution shape producers and consumers plus full CLI Pest suite; result=array shape replaced by the final readonly DTO at every producer and consumer in the skill-install plan flow, SkillTargetResolver final again with the subclass spy test replaced by a behavioral resolve-once case, no cross-package moves, full CLI suite 2617 passed
- Review: passed - orchestrator Claude reviewer VERDICT PASS: pure typing refactor with DTO restored beside its sibling plan and result types, immutable plan-execute split and destructive-consent fail-closed path untouched, dropped redundant null-narrowing matches the pre-769 resolver; human-judgment=not-required
- Reviewed feature tip: 7a67e768be68711ec05dae6b646fedb22bc754e2
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 7a67e768be68711ec05dae6b646fedb22bc754e2
- Accepted main tip: b6550e6ad44e8bf6618d71351939cb7cabbbb8e1

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be `not-required -
concrete reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
For stateful, lifecycle, or concrete UX work, optionally append one compact
clause on the existing Scope `Owned` row (do not add a permanent new row):
`primitive=exact requested primitive; transitions=success:terminal success|failure:terminal failure|retry:retry behavior|stop-restart:stop or restart|stale:stale-state or n/a`.
Omit the clause for ordinary/local changes. When `primitive=` or `transitions=`
is present, deterministic lint requires both fields, the five known transition
keys without duplicates or empty values, and rejects template placeholders; it
does not grade prose or decide whether the feature is stateful. Explicit `n/a`
values are fine when a transition does not apply. After FRAME, run
`bin/orbit-feature-acceptance route` for the read-only
diff-derived venue before expensive PROVE work. For non-`automated` venues,
`Verification.runtime: passed` must use one candidate-bound structured receipt
on that same single line. Required fields are candidate=, venue=, environment=,
expected=, observed=, result=passed, and evidence= as one exact inline-code path
under the worktree evidence or quality-gates trees. Use exactly one of target= or command=.
Live/production claims require exact environment=live; ordinary retained
topology may use environment=dev-fixture. Semicolons separate fields,
so values must not embed raw semicolon-delimited pseudo-fields. Known keys
only. Example evidence citation: write a real receipt and cite one exact regular
file below the worktree evidence tree (not a directory root). A failed,
excluded, still-required, or deferred final hop cannot be recorded as passed;
remain in PROVE, disarm any armed or recorded acceptance, and follow FIX ->
BUILD -> PROVE before ACCEPT. Keep a still-valid Review and Reviewed feature tip
on proof-only retries; a HEAD change still needs a refreshed review. Automated
venues keep `runtime: not applicable`. Proof files retained by the compact
archive must be cited as one exact inline-code path; prose, directories, padded
code spans, and partial paths are not proof citations.
