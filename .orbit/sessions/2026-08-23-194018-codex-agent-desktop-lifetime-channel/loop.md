# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-agent-desktop-lifetime-channel
- Worktree: /home/nckrtl/orbit/.worktrees/codex-agent-desktop-lifetime-channel
- Branch: codex/agent-desktop-lifetime-channel

## Goal

Orbit Agent supports an explicit Desktop-owned lifetime channel that exits the
Agent when the owning Desktop process closes the channel, while standalone and
Linux Agent launches keep their existing behavior.

## Scope

- Owned: `apps/agent` lifetime-channel detection, EOF shutdown behavior, and
  focused Rust coverage; primitive=opt-in owner lifetime channel;
  transitions=success:desktop-owned Agent runs while owner stdin remains
  open|stop:EOF requests graceful Agent shutdown|standalone:absence of the
  desktop marker preserves current service behavior|failure:invalid channel
  setup fails closed without changing standalone semantics. Producers: Orbit
  Desktop child-process environment and stdin. Consumers: Orbit Agent HTTP
  service shutdown token and the later native Desktop supervisor slice.
- Constraints: preserve all standalone macOS and Linux launches; do not change
  listener identity, authentication, job polling, or CLI behavior; keep the
  boundary opt-in through the exact Desktop marker; prove the runtime result on
  retained Incus as a server/node runtime change.
- Out of scope: Tauri tray/window behavior, launch-at-login, legacy LaunchAgent
  migration, update installation, release assets, and Mac installation.

## Proof

- Verification:
  - focused: passed - apps/agent cargo fmt --check, cargo test, cargo check, cargo clippy --all-targets -- -D warnings
  - broader: passed - `composer quality-check` artifact `.orbit/quality-gates/quality-check-2026-08-23T172331Z-7e5f1f130f74.json`
  - runtime: passed - candidate=42c1708d262051a0ee03a8169928440ab6fb1818; venue=retained-incus; environment=dev-fixture; target=orbit-e2e-lt42c170-dev; expected=desktop-owned Agent stays healthy while a one-way owner stdin pipe remains open then exits after the parent write-end closes, standalone Agent stays healthy after stdin EOF; observed=desktop fd0=pipe flags=00 health ok while open then exit 0 after write-end close, standalone fd0=pipe flags=00 health ok after stdin EOF; result=passed; evidence=`.orbit/evidence/agent-desktop-lifetime-incus.txt`
- Retained topology proof: passed - topology id=lt42c170 kind=app-dev-clone host=beast instance=orbit-e2e-lt42c170-dev os=Linux source-mount=/home/orbit/orbit command=`incus exec orbit-e2e-lt42c170-dev -- python3 /tmp/orbit-agent-lifetime-proof.py`; evidence=`.orbit/evidence/agent-desktop-lifetime-incus.txt`
- Blast radius: complete - evidence=repository-wide spawn, environment, listener, auth, polling, release, CLI, SDK, gateway, and docs searches in the independent review handoff; result=no gaps, with the later apps/macos producer constrained to the exported marker, a child stdin pipe, and bounded hard-kill escalation
- Review: passed - independent Claude review; human-judgment=not-required;
  handoff=`.orbit/workers/handoff/agent-lifetime-review-42c1708d262051a0ee03a8169928440ab6fb1818.md`
- Reviewed feature tip: 42c1708d262051a0ee03a8169928440ab6fb1818
- Acceptance venue: retained-incus
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 42c1708d262051a0ee03a8169928440ab6fb1818
- Accepted main tip: b4d1d37d5452e5f25ec92d249b7e5310b1f1ec6d

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
