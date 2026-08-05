# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: none
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-zsh-wildcard-permissions
- Branch: codex/zsh-wildcard-permissions

## Goal

A normally installed/configured zsh user can run the unquoted command
`orbit node:permissions beast main1 --add=process:*` without zsh NOMATCH, and
Orbit receives the literal argv `--add=process:*`, via supported shell
install/update integration only (no global NONOMATCH, no permission-vocabulary
change).

## Scope

- Owned: `bin/install-orbit`; CLI update/install shell integration under
  `apps/cli/app/Services/Updates/` (and fleet CLI install script if required for
  upgrade path parity); focused Pest coverage under `apps/cli/tests` and
  installer contract tests under `apps/gateway/tests`; product docs under
  `apps/docs/content/` for install/update and namespace-wildcard shell behavior
  (tech-stack, update, node-permissions/concepts as needed).
  Feedback pointer: `.orbit/feedback.jsonl`. Authority:
  `apps/docs/content/` (namespace wildcards product-authoritative;
  `process:*` vocabulary unchanged), `PRODUCT_DECISIONS.md` install launcher
  decisions. Source request: zsh NOMATCH on unquoted `--add=process:*`.
- Constraints: do not set global NONOMATCH or weaken globbing for non-Orbit
  commands; do not change permission vocabulary; preserve unrelated work; never
  run `composer test:e2e*`; TDD with red shell-boundary regression first; commit
  candidate after focused checks; do not merge/land/cleanup/retained topology.
- Out of scope: permission string grammar changes; global zsh option changes;
  E2E/retained topology proof; merge/land; non-zsh shells beyond documenting
  bash default literal unmatched globs.

## Proof

- Verification:
  - focused: passed - `bin/orbit-cli-pest --compact tests/Feature/Commands/Operation/VersionCommandTest.php tests/Feature/Services/Updates/ZshShellIntegrationTest.php` → 28 passed (167 assertions); `bin/orbit-gateway-pest --compact tests/Feature/InstallOrbitLauncherTest.php` → 21 passed (137 assertions)
  - broader: passed - `ORBIT_QUALITY_CHECK_CPU_BUDGET=1 composer quality-check` at exact HEAD `90bce21b4b3daf5e3ed6c0e05c135fe0af553208`; artifact `.orbit/quality-gates/quality-check-2026-08-05T211655Z-efc15960179a.json`; exit_code=0; git.dirty=false; all subgates 0
  - secret scan: passed - `bin/orbit-secret-scan` → `SECRET_SCAN: PASS` at exact HEAD `90bce21b4b3daf5e3ed6c0e05c135fe0af553208`
  - runtime: passed - candidate=90bce21b4b3daf5e3ed6c0e05c135fe0af553208; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-dev-66d5de-operator topology dev-66d5de overlay /home/orbit/orbit-run; expected=pre-integration NOMATCH then first-upgrade bridge via version --local --json ZDOTDIR not HOME places rc under ZDOTDIR only fresh interactive unquoted process:* preserves argv non-Orbit globs still NOMATCH second bridge idempotent; observed=NEGATIVE_EXIT=1 bridge exit0 HOME_ZSHRC_EXISTS=no ZDOTDIR_ZSHRC_EXISTS=yes ARG:node:permissions/beast/main1/--add=process:* POSITIVE_EXIT=0 NON_ORBIT_EXIT=1 SECOND_BRIDGE_EXIT=0 MARKER_COUNT=1 SNIPPET_COUNT=1 RUNTIME_PROOF=PASS; result=passed; evidence=`.orbit/evidence/zsh-wildcard-permissions-runtime-90bce21b4.md`
- Blast radius: complete - evidence=repository-wide search across install/update/shell integration, version --local bridge, ZDOTDIR/root path parity, docs, and installer/Pest coverage; result=no open product gaps for the scoped zsh noglob feature
- Review: passed - human-judgment=not-required - independent final reviewer: no actionable findings at clean tip 90bce21b4b3daf5e3ed6c0e05c135fe0af553208; BLAST_RADIUS complete; VERDICT PASS
- Reviewed feature tip: 90bce21b4b3daf5e3ed6c0e05c135fe0af553208
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 90bce21b4b3daf5e3ed6c0e05c135fe0af553208
- Accepted main tip: fe91055eff6d55e8f3cc65210ef309d0495f675c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
