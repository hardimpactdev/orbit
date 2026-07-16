# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad: codex://threads/019f6975-2bcc-7421-b9b3-95ead3db81c3
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-app-list-datatable-contract
- Branch: codex/fix-app-list-datatable-contract

## Goal

`orbit app:list` uses Laravel Prompts `datatable` with the literal Name, Repository, Instances, and Workspaces columns and opens the selected app's existing `app:show` drill-down, while command UX authority and reviewer prompts explicitly reject the custom grouped property renderer as a substitute for that component.

## Scope

- Owned: `PRODUCT_DECISIONS.md`; app-list command docs and command UX primitive docs under `apps/docs/content/`; `apps/cli` app-list renderer and focused tests; the custom grouped-property prompt vocabulary; `.agents/skills/command-designer/SKILL.md`; `.agents/review-personas/cli-command.md`; `.agents/review-personas/general.md`.
- Constraints: preserve the global logical-app API and JSON payload; use `Laravel\Prompts\datatable` in interactive human mode; preserve the existing app-show placement drill-down; promote the literal rejected and accepted output pair into executable coverage and review guidance.
- Out of scope: changing app visibility, count aggregation, app-show placement data, dependency ownership, gateway APIs, releases, or manual E2E lanes.

## Proof

- Verification:
  - focused: passed - 20 CLI tests / 99 assertions for app list, app show, and tool property list; 25 gateway architecture tests / 274 assertions
  - broader: passed - `composer quality-check`; all app/package gates passed; receipt recorded in `.orbit/quality-gates/quality-check-2026-07-16T103015Z-5f56fd7d2df9.json`
  - runtime: passed - retained Incus topology `dev-dcb298` (`operator_gateway`); candidate source hash matched operator and gateway; interactive PTY exit 0; exact datatable headers, Enter drill-down, instance/workspace URLs, and 120-column fit recorded in `.orbit/evidence/app-list-datatable-pty.txt`
- Blast radius: complete - evidence=repository-wide implementation/docs/reviewer inventory plus `McpConfigurationTest`; result=the custom grouped renderer is renamed PropertyList, legacy custom DataList files are absent, remaining old-name mentions are explicit rejection guidance, tool:list docs use property-list, and app:list JSON remains unchanged
- Review: passed - concrete Laravel Prompts datatable symbol, literal headers, stable row-key app:show click-through, complete retained PTY frame, unchanged global JSON inventory, PropertyList rename, and hardened reviewer guidance verified; human-judgment=not-required
- Reviewed feature tip: 47ae83cbb43473dbcce0d24fcfdcfb1c5c1e5d90
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 47ae83cbb43473dbcce0d24fcfdcfb1c5c1e5d90
- Accepted main tip: 6785901667eef234b8896e622d443ff3e721b26f

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`

Allowed states are `frame`, `build`, `prove`, `accept`, `accepted`, `land`, and
`blocked`. Review must be `passed`, `fix`, or `escalate`. Acceptance must be
`pending`, `accepted`, `not-applicable`, or `changes-requested`. A terminal
review PASS must record `human-judgment=required|not-required` and the exact
committed HEAD in `Reviewed feature tip`. Blast radius must be recorded as
`not-required` with a reason or `complete` with repository-wide evidence and a
result summary before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path; prose, directories, padded code spans, and partial paths are
not proof citations.
