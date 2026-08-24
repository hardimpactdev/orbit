# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-restart-ready-desktop-update
- Worktree: /home/nckrtl/orbit/.worktrees/codex-restart-ready-desktop-update
- Branch: codex/restart-ready-desktop-update

## Goal

Every reachable macOS node selected by `update:all` receives a newer
`restart-ready` Desktop handoff, so Orbit Desktop can offer “Restart to Update”
instead of installing the Desktop update merely because the app starts. The
corrected candidate uses `0.1.197` because the rejected `0.1.196` candidate is
already installed on the live Macs.

## Scope

- Owned: `WorkloadNodeUpdater` Desktop handoff production, focused gateway coverage, the update contract text, and the root `VERSION` advance to `0.1.197`; primitive=pending Desktop handoff; transitions=success:explicit Restart to Update installs the bound Desktop/Agent/CLI set|failure:verified install failure preserves recovery state|retry:operator selects Restart to Update again|stop-restart:app restart occurs only after explicit selection|stale:stale or mismatched handoff fails closed.
- Constraints: Keep all-node selection, pre-mutation skip behavior, owner-only paths, immutable artifact identity, and deferred Agent restart unchanged. Producer: gateway `pendingDesktopUpdatePayload`. Consumers: CLI pending-handoff validation/write and Tauri startup/menu state. Dangerous invariant: changing the mode must not weaken hash/signature/path checks or auto-restart the Agent.
- Out of scope: Desktop menu refresh implementation, artifact signing, node selection, and public GitHub publication before explicit approval.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-restart-ready-handoff.md` | complete | 255459189ebcd808dee7bc4d3c997f3b007711a6 |
| `.orbit/slices/02-version-0-1-197.md` | complete | 735572e9796f854305e2dc48ba4b07b9c9556673 |
| `.orbit/slices/03-restart-ready-test-language.md` | complete | 5115fa8f9fb578bd1bc39efc7ddec02e3b7762f4 |

## Proof

- Verification:
  - focused: passed - gateway Pest 40 tests/406 assertions; focused implementation Mago clean; test-file Mago reports the file's existing unbaselined factory typing diagnostics; docs lint passed with existing warnings; VERSION reports 0.1.197
  - broader: passed - `composer quality-check`, `composer docs-lint`, and evidence-only final-check passed on 5115fa8f9fb578bd1bc39efc7ddec02e3b7762f4 with no final-check warnings
  - runtime: passed - candidate=5115fa8f9fb578bd1bc39efc7ddec02e3b7762f4; venue=retained-incus; environment=dev-fixture; target=dev-4d4653 gateway `/home/orbit/orbit-run`; expected=exact candidate emits restart-ready macOS Desktop handoff payloads at version 0.1.197; observed=local and VM aggregate 395769c68ec560be723c705226adf4839d6e0e32a91e778a20e2c4a69c88ab94 and 40 tests/406 assertions passed; result=passed; evidence=`.orbit/evidence/restart-ready-handoff.txt`
- Blast radius: complete - evidence=bounded repository-wide `install_mode` producer/consumer search across gateway, CLI, Tauri, Agent, core, and SDK; result=gateway is the sole producer, CLI accepts both modes, Tauri routes `restart-ready` to the explicit action, and no unresolved affected surface exists
- Review: passed - Claude general reviewer found no actionable findings; VERDICT=PASS; human-judgment=not-required
- Reviewed feature tip: 5115fa8f9fb578bd1bc39efc7ddec02e3b7762f4
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 5115fa8f9fb578bd1bc39efc7ddec02e3b7762f4
- Accepted main tip: 3c1326469d6523f3be313f323865496bcfac4df8

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
