# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: `solo://proj/2/todo/p24-a-resolve-skill--769`
- Worktree: `/Users/nckrtl/orbit/.worktrees/solo-769-skill-install-immutable-plan`
- Branch: `solo-769-skill-install-immutable-plan`

## Goal

Skill installation resolves target + source EXACTLY ONCE into a single immutable
`SkillInstallPlan` (readonly), eliminating the current double-resolution race where the
command and `SkillInstallActions::install()` each re-run `prepare()`/`sourcePath()` and can
observe different filesystem state between consent and install. `install(SkillInstallPlan)`
takes the plan verbatim and only re-checks the two race-sensitive facts (target-exists +
source-validity) immediately before delete/copy. Force-consent semantics, destructive
consent, changed/missing-source revalidation, missing-target, and target-mapping behavior
are preserved with unchanged error codes/envelope.

## Scope

- Owned (all apps/cli): new readonly DTO `app/Services/Skill/SkillInstallPlan.php`
  (?provider, resolved target, resolved source, force, targetExistsAtPlan) with a
  `withForce()` consented-copy method; add `SkillInstallActions::plan(SkillInstallRequest):
  SkillInstallPlan|SkillInstallFailure` that runs targetResolver->resolve() + sourcePath() +
  validateSource() + ONE targetExists() once; rework `install()` to accept a
  `SkillInstallPlan` and only revalidate targetExists + validateSource before
  ensureDirectoryExists+copyDirectory (no re-resolution); update `SkillInstallCommand` to
  build one request, call plan() once, consent via `$plan->withForce()` (stop rebuilding
  `SkillInstallRequest`), then install($plan); REMOVE `SkillTargetResolution.php` (folded
  into SkillInstallPlan). Tests: extend `SkillInstallCommandTest`; add plan-built-once
  (resolve() called exactly once), install-time race revalidation, and missing-source-at-
  install coverage (likely a new `SkillInstallActionsTest`).
- Constraints: PRESERVE destructive_consent_required consent, --force, changed/missing-
  source revalidation at install (missing_source), missing-target (missing_home /
  SkillTargetResolver), target-mapping (provider defaults + explicit path), and the
  target-existence race revalidation; error codes + envelope shape unchanged. KEEP
  SkillInstallResult, SkillInstallFailure, SkillProvider enum, SkillTargetResolver.
  declare(strict_types=1); Mago/Rector clean. Do NOT run composer test:e2e*.
- Out of scope: NO zip/archive extractor and NO --dry-run flag (the "source archive" is a
  directory copied via File::copyDirectory; "dry run" is N/A for this command). Do not
  change SkillTargetResolver resolution logic or the output/error envelope contract.

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact --filter=SkillInstall` 25 passed; `bin/orbit-cli-pest --compact` 2612 passed
  - broader: passed - `composer quality-check` on clean commit `89b3dff3f4cced1aef2e0a838640329fbef6a99a` exit 0, dirty false, 45/45 subgates zero, Mago/Rector clean (`.orbit/quality-gates/quality-check-2026-08-18T033006Z-fafb94c20e46.json`)
  - runtime: passed - candidate=89b3dff3f4cced1aef2e0a838640329fbef6a99a; venue=retained-incus; environment=dev-fixture; expected=single-resolution SkillInstallPlan flow (plan() once, install() revalidation-only, consent/force/error-envelope preserved) green in retained operator VM; observed=24 passed 103 assertions 0 failures; result=passed; command=ssh beast incus exec orbit-e2e-dev-acba42-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/cli && HOME=/tmp XDG_CONFIG_HOME=/tmp php vendor/bin/pest tests/Feature/Commands/Skill/SkillInstallCommandTest.php tests/Unit/Services/Skill/SkillInstallActionsTest.php --compact'; evidence=`.orbit/evidence/solo-769-retained-incus-proof.md`
- Blast radius: complete - evidence=repository-wide rg sweep + full apps/cli Pest + quality-check; result=SkillTargetResolution removed, resolution happens once via SkillInstallPlan, install() revalidation-only, consent/force/error-envelope preserved (`.orbit/evidence/solo-769-blast-radius-inventory.md`)
- Review: passed - independent Claude reviewer (2503) VERDICT PASS, all 7 checks confirmed; human-judgment=not-required
- Reviewed feature tip: 89b3dff3f4cced1aef2e0a838640329fbef6a99a
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 89b3dff3f4cced1aef2e0a838640329fbef6a99a
- Accepted main tip: 0642bf5c1e7b62d29808e2f9aeed4ad4e7c26917

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
