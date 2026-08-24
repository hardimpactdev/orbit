# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-macos-menu-bar-only
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-macos-menu-bar-only
- Branch: codex-macos-menu-bar-only

## Goal

Orbit Desktop runs only as a macOS menu-bar lifecycle owner, opens https://app.orbit in the default browser, and exits after explicit Quit only when every provably Orbit-owned local runtime has stopped with the supervised Agent stopped last.

## Scope

- Owned: `apps/macos/**` and matching product documentation for the native lifecycle; primitive=menu-bar lifecycle owner; transitions=success:no owned local activity and app exits|failure:menu stays active with shutdown failure|retry:explicit Quit retries discovery and stop|stop-restart:Restart stops Agent before relaunch and Quit stops all owned activity|stale:rediscover owned launchd and Docker state before verification
- Constraints: Keep tray status, node rows, launch at login, updater, refresh, restart, and Agent supervision. Remove the window, webview dashboard, and Dock presence. Treat `dev.hardimpact.orbit.*` launchd units and Docker containers labeled `orbit.managed=true` as bounded ownership producers; the shutdown coordinator is their consumer. Stop the Agent last. Fail closed if discovery, stop, or final verification fails. Never stop an unlabeled container, a launchd unit outside the Orbit prefix, or a shared provider without exclusive Orbit ownership.
- Out of scope: `apps/ui`, production `app.orbit` hosting, the production `ui` role, gateway delegation, gateway API changes, release signing, and unrelated local workloads.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-menu-only.md` | complete | c232fab48f909ccb77113141178d13843c8184cb |
| `.orbit/slices/02-owned-shutdown.md` | complete | a9b881ec63ee7986054cb7dcae0f763831ce42ca |
| `.orbit/slices/03-docs-and-cleanup.md` | complete | 8d7e13e2db0fe16ba8ace88a2f3762596a307683 |

## Proof

- Verification:
  - focused: passed - candidate=8d7e13e2db0fe16ba8ace88a2f3762596a307683; Rust=67 tests plus fmt/check/clippy; gateway=21 tests/187 assertions; real Tauri debug app bundle built; docs-lint passed
  - broader: passed - composer quality-check candidate=8d7e13e2db0fe16ba8ace88a2f3762596a307683; all 11 monorepo units passed; profile=`.orbit/quality-gates/quality-check-2026-08-24T151103Z-b1fc0b01190f.json`
  - runtime: passed - candidate=8d7e13e2db0fe16ba8ace88a2f3762596a307683; venue=host-macos; environment=nick.local Darwin 27.0.0 arm64 macOS 27.0 26A5416b; command=exact candidate Accessibility inspection plus error-path and isolated successful Quit exercises; expected=background-only zero-window menu opens https://app.orbit and explicit Quit leaves no owned launchd Docker or Agent activity; observed=menu had zero windows, Open Orbit opened the browser URL, the error path stayed active with durable text across Refresh, isolated Quit removed temporary launchd and labeled Docker activity then exited, and original host topology was restored; result=passed; evidence=`.orbit/evidence/macos-runtime-v2/receipt.md`
- Blast radius: complete - evidence=reviewer repository-wide residue search, release-path build, generated unit-map consumer checks, and launchd/Docker ownership-boundary inspection against restored host topology; result=no unresolved affected surface
- Review: passed - exact candidate checkout verified; all five defects from candidate 40fc740d8bb70fa28437098b130bbddb14d5f481 repaired; human-judgment=not-required; reviewer verdict PASS
- Reviewed feature tip: 8d7e13e2db0fe16ba8ace88a2f3762596a307683
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 8d7e13e2db0fe16ba8ace88a2f3762596a307683
- Accepted main tip: 9071bc82784fcd7a367dd16f349cf07101e7622d

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
