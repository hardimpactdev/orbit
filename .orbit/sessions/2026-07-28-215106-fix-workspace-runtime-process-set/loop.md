# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Scratchpad:
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-fix-workspace-runtime-process-set
- Branch: codex/fix-workspace-runtime-process-set

## Goal

Workspace setup, first-request activation, and workspace show all use the exact effective workspace-owned process set: one workspace FrankenPHP runtime plus inherited non-web instance processes rendered and started as workspace-specific units.

## Scope

- Owned: `apps/gateway/app/Services/Processes/**`, `apps/gateway/app/Actions/Workspaces/SetupWorkspace.php`, `apps/gateway/app/Services/Workspaces/WorkspaceShowPayload.php`, focused gateway Pest coverage for those surfaces
- Constraints: TDD first with literal RED, reuse one centralized effective workspace process-set rule if it prevents divergence cleanly, no E2E, no production/runtime mutation, preserve unrelated work
- Out of scope: rollback redesign, production fixes, broader runtime topology changes, doc edits unless authority is actually incomplete

## Proof

- Verification:
  - focused: passed - `bin/orbit-gateway-pest --compact tests/Feature/Services/Processes/ProcessOwnerContextTest.php tests/Feature/Actions/Workspaces/SetupWorkspaceActionTest.php tests/Feature/Services/Workspaces/WorkspaceShowPayloadTest.php tests/Feature/Commands/Workspaces/WorkspaceShowJsonRendererTest.php` -> `{"tool":"pest","result":"passed","tests":38,"passed":38,"assertions":252,"duration_ms":1458}`
  - broader: passed - `composer quality-check` -> exit 0; Pest profile set 2026-07-28T19-47-42Z-cff907882357
  - runtime: passed - retained Incus `operator_gateway_app-dev` setup, effective process inventory, healthy workspace container, and exact workspace URL HTTP 200; evidence `.orbit/evidence/runtime-proof.txt`; the subsequent `main` integration added archive files only and did not change executable source
- Blast radius: complete - evidence=repository-wide consumer inventory plus `composer quality-check`; result=workspace lifecycle targeting, setup, show payloads, logs, and hibernation use the centralized effective process-set rule and the merged HEAD passed the full quality gate
- Review: passed - human-judgment=not-required
- Reviewed feature tip: cff90788235753657fb1a88ca1fda911e653df1d
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: cff90788235753657fb1a88ca1fda911e653df1d
- Accepted main tip: 139edabf5a01486fc3afb9a5553c22e5cad81755

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
reason` or `complete - evidence=repository-wide search, inventory, or
lintable check; result=summary` before acceptance; `gaps` returns to BUILD.
Proof files retained by the compact archive must be cited as one exact
inline-code path, for example `.orbit/evidence/runtime-proof.txt`; prose,
directories, padded code spans, and partial paths are not proof citations.
