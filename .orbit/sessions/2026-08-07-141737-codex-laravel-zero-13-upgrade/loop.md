# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-laravel-zero-13-upgrade
- Branch: codex/laravel-zero-13-upgrade

## Goal

apps/cli runs on Laravel Zero 13 (laravel-zero/framework ^13.0, illuminate 13.x) with the test suite unified on Pest 5 / PHPUnit 13, the gateway Pest-version contract updated to six Pest 5 projects with no CLI exception, and the testing README plus root AGENTS.md no longer documenting the Pest 4 exception — proven by green focused CLI Pest and `composer quality-check`.

## Scope

- Owned: apps/cli/composer.json, apps/cli/composer.lock, apps/cli config/bootstrap/tests sync required by Laravel Zero 13, apps/gateway/tests/Feature/Architecture/PestVersionContractTest.php, apps/docs/content/testing/README.md (#pest-versions), root AGENTS.md Pest note
- Constraints: no CLI command behavior change; keep phpacker build path working; keep `provide: laravel/framework` consistent with installed illuminate line; no E2E lanes
- Out of scope: gateway/docs/e2e/core/sdk dependency changes; new CLI features; Rector/Mago major upgrades

## Proof

- Verification:
  - focused: passed - CLI Pest full suite incl. slow group 2525 passed / 10585 assertions on Laravel Zero 13 + Pest 5; gateway PestVersionContractTest red (Pest 4 constraint) then green after upgrade; apps/cli Mago at main parity (0 errors), format clean, Rector clean; `bin/orbit-build-cli-binary mac arm` built and `--version` smoke passed
  - broader: passed - `composer quality-check` exit 0 at candidate f5e34c3c0 recorded in `.orbit/quality-gates/quality-check-2026-08-07T120729Z-c668406b9afc.json`; first run's two CLI failures were load-induced flakes (concurrent reviewer + gate on one machine; file passes 3/3 isolated) and tsc-missing was a worktree bootstrap gap fixed by `npm ci` in packages/sdk-typescript; final-check clean with timing warnings only
  - runtime: passed - candidate=f5e34c3c0dcd5a2b275fcaebfd570023538cb9dc; venue=retained-incus; environment=dev-fixture; command=orbit --version + orbit node:list + orbit node:list --json + orbit app:show missing-app --json on operator VM of topology dev-4adc05; expected=human table and success envelope with exit 0 and error envelope app.not_found with exit 1 on the Laravel Zero 13 stack; observed=launcher resolves to source-mounted overlay running laravel-zero/framework v13.0.0 and symfony/process v8.1.0 with all four surfaces matching expectations; result=passed; evidence=`.orbit/evidence/laravel-zero-13-retained-incus-proof.txt`
- Blast radius: complete - evidence=repository-wide `rg "Laravel Zero 12|Pest 4"` excluding vendor/superpowers/lock files; result=only intentional historical notes in the two updated files; no stale version references in product docs, skills, or harness
- Review: passed - human-judgment=not-required; general reviewer verified lock discipline (targeted transitive closure only, Mago 1.41.0 retained), genuine red TDD anchor, contract strengthened with LZ ^13 pin, docs anchor preserved, zero CLI behavior change
- Reviewed feature tip: f5e34c3c0dcd5a2b275fcaebfd570023538cb9dc
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f5e34c3c0dcd5a2b275fcaebfd570023538cb9dc
- Accepted main tip: 8ec8cbab7daeb90c90611a21ede0890035fb708b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
