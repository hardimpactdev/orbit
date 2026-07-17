# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: user request in this Codex thread
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-macos-php-runtime-reconciliation
- Branch: fix-macos-php-runtime-reconciliation

## Goal

Make Docker-backed PHP image inventory and selection reliable on macOS app nodes, and make process doctor detect and restore a missing FrankenPHP runtime for every concrete Orbit app instance.

## Scope

- Owned: PHP tool eligibility/probing and image facts; php:list/php:use availability behavior; app-instance FrankenPHP process-doctor reconciliation; matching product docs and focused regression tests.
- Constraints: preserve the docker-compatible provider requirement; distinguish unavailable inventory from confirmed missing images; verify the nckrtl.nmbp behavior through the approved host-macos or retained runtime lane; one independent review; no composer test:e2e commands.
- Out of scope: release publication, force-pushes, unrelated baseline failures, non-FrankenPHP process reconciliation, or reverting a3d280b96.

## Proof

- Verification:
  - focused: passed - 234 tests, 1,761 assertions across PHP runtime, tool catalog/probe/show, doctor reconciliation, controller, and app-show seams
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=2 composer quality-check` passed every app/package lane; gateway profile: `.orbit/quality-gates/profiles/2026-07-17T14-26-28Z-494378859072/gateway_pest.junit.xml`.
  - runtime: passed - topology=host-macos; host=NMBP; os=macos_26-5-1; commands=`php:list --app=nckrtl.nmbp --live`, repeated `php:use 8.5 --app=nckrtl.nmbp`, `tool:show php --node=NMBP --live`, and process doctor verify/restore/recheck; result=approved PHP 8.5 inventory confirmed, selection idempotent, missing mealou.nmbp FrankenPHP unit restored, final doctor healthy; evidence=`.orbit/evidence/runtime-proof.txt`
- Blast radius: complete - evidence=`rg -n "availableImageVersions|image_inventory_status|image_inventory_available|SUPPORTED_OPERATING_SYSTEMS|runtime_process_missing|EnsureFrankenPhpRuntimeProcess" apps/gateway apps/docs/content/domains/14_php apps/docs/content/domains/3_tool apps/docs/content/domains/7_process`; result=inventory state is centralized and explicit statuses are preserved, provider/isolation constraints remain intact, and missing-process recreation is limited to managed FrankenPHP app intent
- Review: passed - human-judgment=not-required - no actionable findings after formatter correction and current-main integration
- Reviewed feature tip: 4943788590720f6e09a69019113ea46c9ddbbe92
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 4943788590720f6e09a69019113ea46c9ddbbe92
- Accepted main tip: 5831ec8f4d35403002df7334cba5c360486cb79c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record the human-judgment decision and exact committed HEAD.
Blast radius must be complete before acceptance; gaps return to BUILD. Proof
files retained by the compact archive must be cited as exact inline-code paths.
