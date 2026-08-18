# Todo 769 — Blast Radius Inventory

Candidate: `89b3dff3f4cced1aef2e0a838640329fbef6a99a`
Base: `main` @ `0642bf5c1e7b62d29808e2f9aeed4ad4e7c26917`
Diff: 9 files changed, +324 / −75.

## Method

Repository-wide `rg` sweep for the removed carrier DTO + the new single-resolution
wiring, plus the full apps/cli Pest suite and `composer quality-check`.

## Result — single immutable resolution

- `SkillTargetResolution.php` — **removed**; `rg 'SkillTargetResolution' apps/cli` → **0**
  matches (folded into `SkillInstallPlan`).
- New `app/Services/Skill/SkillInstallPlan.php` — readonly DTO (?provider, resolved target,
  resolved source, force, targetExistsAtPlan) with `withForce()` consented-copy.
- `SkillInstallActions::plan(SkillInstallRequest): SkillInstallPlan|SkillInstallFailure` —
  runs `targetResolver->resolve()` + `sourcePath()` + `validateSource()` + one
  `targetExists()` **once**.
- `SkillInstallActions::install(SkillInstallPlan)` — takes the plan verbatim; only
  re-checks the two race-sensitive facts (targetExists + validateSource) before
  ensureDirectoryExists+copyDirectory. No re-resolution.
- `SkillInstallCommand` — builds `new SkillInstallRequest` **once** (line 27), calls
  `plan()` once (line 33), consents via `$plan->withForce()` (line 46; no request rebuild),
  then `install($plan)`. `confirmReplacement(SkillInstallPlan)`.
- `rg 'new SkillInstallRequest' apps/cli` → only the single command build + one test factory
  (no force-rebuild path remains).

## Preserved (unchanged contracts)

- Destructive consent (`destructive_consent_required`), `--force`, changed/missing-source
  revalidation at install (`missing_source`), missing-target (`missing_home` /
  `SkillTargetResolver`), target-mapping (provider defaults + explicit path), and the
  target-existence race revalidation. Error codes + envelope shape unchanged.
- `SkillInstallResult`, `SkillInstallFailure`, `SkillProvider` enum, `SkillTargetResolver`
  retained. `SkillTargetResolver::resolve()` keeps identical branching (missing-both guard,
  provider+path pair, lone path, unknown-slug-as-literal, provider default via home) but its
  return **carrier** migrated from the removed `SkillTargetResolution` DTO to an
  `array{provider: ?string, target: string}` shape consumed by `plan()`; `final` was dropped
  so a test spy can subclass it to count `resolve()` calls; the added `$path !== null` guard
  on the lone-path branch is redundant-but-safe (the preceding guards already ensure exactly
  one of provider/path is non-null). No resolution-behavior change.

## Out of scope (correctly not done)

- No zip/archive extractor and no `--dry-run` flag — the "source archive" is a directory
  copied via `File::copyDirectory`; dry-run is N/A for this command (documented).

## Tests

- `SkillInstallCommandTest` extended (envelope/data assertions preserved + new cases).
- New `SkillInstallActionsTest` (188 lines): plan-built-once (resolve() called exactly
  once), install-time race revalidation, missing-source-at-install.

## Verdict

BLAST_RADIUS: complete — evidence = repository-wide `rg` sweep + full apps/cli Pest +
`composer quality-check`; result = SkillTargetResolution removed, resolution happens once
via SkillInstallPlan, install() revalidation-only, consent/force/error-envelope preserved.
