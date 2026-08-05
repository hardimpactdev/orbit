# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-php-free-agent-update`
- Branch: `codex/php-free-agent-update`

## Goal

Fleet update Agent install succeeds on no-host-PHP nodes (Services1 class): after
self-contained CLI/Agent artifacts are present, the full generated installer
shell — Agent config/CA write and required role-image side effects — must not
invoke host `php`.

## Scope

- Owned:
  - `apps/cli/app/Services/Operations/LocalFleetUpdateInstallCliAction.php`
  - `apps/cli/app/Services/Operations/LocalFleetUpdateInstallCliEnvironment.php`
  - `apps/cli/tests/Feature/InternalFleetUpdateInstallCliCommandTest.php`
  - `apps/docs/content/domains/11_operation/README.md`
  - `apps/docs/content/domains/11_operation/2_update-all/technical/1_update-all.md`
- Constraints: no host PHP reintroduction; preserve Agent config/CA atomic write,
  permissions, ownership, and staging/fail-closed behavior; no
  `composer test:e2e*`
- Out of scope: live topology re-run during this slice, E2E provision, unrelated
  dirty work

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact tests/Feature/InternalFleetUpdateInstallCliCommandTest.php` (19 passed), including both no-PHP cases `writes Agent config and CA when host php is unavailable` and `loads role image side effects when host php is unavailable`
  - broader: passed - `composer quality-check` artifact `.orbit/quality-gates/quality-check-2026-08-02T182946Z-801369605d4f.json` bound to commit `692c73f65ae76819b4e24b243bc23acb2a95875b`
  - runtime: passed - retained topology dev-92f4b4 (kind=operator_gateway_agent, host=beast); source synced to /home/orbit/orbit-run; evidence=`.orbit/evidence/php-free-agent-update-retained.txt`
- Blast radius: complete - evidence=repository-wide read-only diff/search/inventory for fleet-update Agent install, config/CA, and role-image side effects; result=no actionable findings, HUMAN_JUDGMENT=not-required, VERDICT=PASS
- Review: passed - human-judgment=not-required; independent read-only review
  VERDICT=PASS; no actionable findings; BLAST_RADIUS=complete
- Reviewed feature tip: 692c73f65ae76819b4e24b243bc23acb2a95875b
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 692c73f65ae76819b4e24b243bc23acb2a95875b
- Accepted main tip: effe7a1e02b7c520553d69f4f101dcdfb1b8cd1e

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
