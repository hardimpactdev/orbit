# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-live-tray-update-refresh
- Worktree: /home/nckrtl/orbit/.worktrees/codex-live-tray-update-refresh
- Branch: codex/live-tray-update-refresh

## Goal

While Orbit Desktop is already running, opening or refreshing its tray menu
detects a newly written restart-ready Desktop handoff, shows and enables
“Restart to Update Orbit 0.1.197”, and marks the tray icon as update-ready.

## Scope

- Owned: `apps/macos` tray refresh, pending-handoff consumption, update-row enabled state, and focused Rust coverage; primitive=live pending Desktop handoff; transitions=success:the running tray exposes an enabled Restart to Update action|failure:invalid handoff remains unavailable|retry:a later tray refresh re-reads the handoff|stop-restart:install begins only after explicit action|stale:stale or mismatched handoff fails closed.
- Constraints: Preserve startup auto-install only for the legacy `automatic` mode, the owner-only path and identity checks, explicit restart-ready install action, Agent lifecycle ownership, and existing tray topology refresh behavior. Producer: CLI pending-handoff writer. Consumers: Tauri startup and tray refresh. Dangerous invariant: observing a restart-ready handoff must not install it or stop the Agent before the user selects the action.
- Out of scope: gateway handoff production, release artifact construction, Apple signing/notarization, CLI or Agent behavior, and public GitHub publication.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-live-tray-update-refresh.md` | complete | f72abc493fd956c323951488c3f256be4d94dfbc |

## Proof

- Verification:
  - focused: passed - `cd apps/macos && cargo fmt --all -- --check && cargo test && cargo check && cargo clippy --all-targets -- -D warnings` at f72abc493fd956c323951488c3f256be4d94dfbc (55 tests)
  - broader: passed - `composer quality-check` at f72abc493fd956c323951488c3f256be4d94dfbc; artifact `.orbit/quality-gates/quality-check-2026-08-24T074005Z-136c925aac8f.json`; `composer quality-gate:final-check` passed with no warnings and did not rerun quality-check or E2E lanes
  - runtime: passed - candidate=f72abc493fd956c323951488c3f256be4d94dfbc; venue=host-macos; environment=dev-fixture; target=Mini exact detached source worktree and native tray process; expected=running Desktop observes restart-ready handoff creation and removal without Refresh, synchronizes the enabled update action and update-ready tray presentation, and remains responsive when handoff changes race with menu opening; observed=native action changed `Orbit 0.1.197|enabled=false` -> `Restart to Update Orbit 0.1.197|enabled=true` -> `Orbit 0.1.197|enabled=false`, both immediate menu interactions completed, and the process remained responsive with matching watcher-driven tray icon updates; result=passed; evidence=`.orbit/evidence/live-tray-update-refresh-f72abc493fd9.txt`
- Blast radius: not-required - change stays inside `apps/macos/src/main.rs`, consumes existing handoff schema/constants unchanged, and bounded orphan-reference search found no affected external surface; reviewer evidence `.orbit/workers/handoff/review-1.md`
- Review: passed - same Claude general reviewer closed all three prior defects at the corrected tip, found no new defect, and returned VERDICT=PASS; human-judgment=not-required; handoff `.orbit/workers/handoff/review-1.md`
- Reviewed feature tip: f72abc493fd956c323951488c3f256be4d94dfbc
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: f72abc493fd956c323951488c3f256be4d94dfbc
- Accepted main tip: 3c23686d9f8f4e1400cb1ae9c207106e3c412b5c

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
