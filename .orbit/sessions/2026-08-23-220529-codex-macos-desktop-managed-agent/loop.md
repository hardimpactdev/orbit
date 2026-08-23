# Orbit Feature Loop

- Session: feat-codex-macos-desktop-managed-agent
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-macos-desktop-managed-agent
- Branch: codex/macos-desktop-managed-agent

## Goal

Orbit Desktop is the sole macOS owner of the local Orbit Agent lifetime,
launches at login, hides its dashboard on window close, stops the Agent on
explicit Quit, and consumes one verified Desktop/Agent/CLI update handoff as a
visible Restart to Update action.

## Scope

- Owned: `apps/macos`, native release asset assembly and manifest publication,
  legacy standalone Agent launch migration, product docs, focused Rust/release
  tests, worker spawn PATH isolation, and Mini host-Mac proof;
  primitive=Desktop-owned Agent supervisor and immutable pending update;
  transitions=success:Desktop starts exactly one direct Agent child and Restart
  to Update installs the bound Desktop/Agent/CLI set|failure:invalid or
  incomplete handoff is rejected without replacing installed artifacts|retry:a
  staged valid handoff remains available for a later restart|stop-restart:window
  close hides while explicit Quit stops Agent and login launch restores both
  ownership and service|stale:mismatched operation/version/build/artifact
  identity is rejected. Producers: release native builder, release candidate
  manifest, gateway staged handoff, legacy launchd state, Tauri lifecycle
  events, login-item state, and the landed Agent lifetime channel. Consumers:
  Desktop supervisor, local Agent listener, tray menu/icon, updater installer,
  CLI launcher, native release verifier, GitHub release workflow, and product
  docs.
- Constraints: preserve the standalone CLI when Desktop is stopped; never
  leave a standalone or launchd Orbit Agent after Desktop Quit; do not kill an
  unverified unrelated listener; perform updates only from owner-user safe
  paths; require one version/build identity plus verified SHA-256 and Tauri
  signature before replacement; make update readiness visible in the tray;
  preserve unrelated Mini and NMBP checkout and launchd state; consume
  `orbit_agent::DESKTOP_LIFETIME_ENV` and use a child stdin pipe with bounded
  hard-kill escalation. Dangerous invariants: close is not Quit; Quit leaves no
  Orbit Agent; only Desktop owns the managed macOS Agent; CLI remains
  independently executable; partial replacement cannot be reported complete;
  published Desktop bytes are the accepted native bytes.
- Out of scope: acquiring Apple Developer credentials, claiming notarization
  without credentials, changing Linux Agent service ownership, broad launchd
  process inventory, arbitrary application update channels, and any new CLI
  command beyond the unified handoff already landed.

## Proof

- Verification:
  - focused: passed
  - broader: passed
  - runtime: passed - candidate=3fd8a965a9d61782c2d5ef6e22630c446979c691; venue=host-macos; environment=dev-fixture; command=`cd apps/macos && cargo run --bin orbit-macos`; expected=bound Desktop/Agent/CLI install from a staged handoff, and a truncated staged archive leaves hashes unchanged with the handoff retained and Restart to Update visible; observed=resume test installed the bound set, Desktop 95848 owned Agent 95947, truncated archive left hashes unchanged, owner handoff file retained, label Restart to Update Orbit 0.1.196; result=passed; evidence=`.orbit/evidence/macos-desktop-managed-agent-3fd8a965a/update-install-proof.txt`
- Retained topology proof: passed - host topology kind=host-macos; host=mini; os=Darwin 27.0.0 arm64 / macOS 27.0 26A5416b; command=`cd apps/macos && cargo run --bin orbit-macos`; evidence=`.orbit/evidence/macos-desktop-managed-agent-3fd8a965a/update-install-proof.txt`
- Blast radius: complete - evidence=feature-level handoff schema, Desktop lifetime environment, release-manifest consumer inventory, and exact corrective-delta review; result=all cross-boundary consumers agree and the retry fix is internal to apps/macos
- Review: passed - exact-tip re-review confirmed live state-machine wiring, success-only handoff deletion, visible failure retry, partial-replacement resume, 34 Rust tests, and candidate-bound Mini proof; human-judgment=not-required
- Reviewed feature tip: 3fd8a965a9d61782c2d5ef6e22630c446979c691
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: 3fd8a965a9d61782c2d5ef6e22630c446979c691
- Accepted main tip: 732e61be405b88c36be05aec3379f4bf6abfa1a2

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
