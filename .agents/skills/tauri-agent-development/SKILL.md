---
name: tauri-agent-development
description: Use when working in apps/macos on the Tauri/Rust Orbit Agent macOS menu-bar runtime or tray/menu behavior, or when coordinating it with the apps/agent headless service, local agent config, gateway ping, job polling, or Cargo/Tauri verification.
---

# Tauri Agent Development

## Scope

Use this skill for Orbit Agent macOS UI changes under `apps/macos`, including
the native tray menu, status rows, service-status refresh, and Tauri build
surface. Use it with `apps/agent` changes only when the macOS UI is being
coordinated with the headless Orbit Agent service, local config loading,
gateway ping, polling worker, or typed Orbit Agent job execution.

Do not use this skill for gateway claim/report endpoint implementation,
node-orchestration behavior outside the local agent client boundary, installer
signing/notarization, self-update, approval UI, arbitrary privileged shell
execution, or SSH/RemoteShell replacement behavior unless the active feature
contract explicitly owns that scope.

## Required Context

- `AGENTS.md`
- `HARNESS.md`
- `apps/docs/content/generated/monorepo-unit-map.json`
- `apps/docs/content/architecture.md`
- `apps/docs/content/tech-stack.md`
- `apps/docs/content/domains/1_node/node-concepts.md`
- `apps/agent/Cargo.toml`
- `apps/macos/Cargo.toml`
- `apps/macos/tauri.conf.json`
- The changed Rust source and focused tests under `apps/agent/src` or
  `apps/macos/src`

When Tauri API details matter, fetch current Tauri v2 documentation before
changing assumptions. Tauri v2 tray work should use the current tray/menu APIs,
including `TrayIconBuilder`, `MenuBuilder`, `MenuItemBuilder`,
`on_menu_event`, and `on_tray_icon_event` where those surfaces apply.

## Workflow

1. Start from the assigned Orbit worktree and prove `pwd`, branch, and status.
2. Keep Tauri edits inside `apps/macos` and headless service edits inside
   `apps/agent` unless the handoff explicitly owns shared docs, quality gates,
   or harness changes.
3. For non-Markdown changes under `apps/macos`, treat the implementing macOS
   host as the live topology target. Prove the host with `hostname`, `uname -s`,
   and `sw_vers`; do not substitute retained Incus for Tauri/macOS behavior. If
   the implementation host is not Darwin, stop or move the slice to a Mac host.
4. Prefer focused Rust unit coverage for behavior that can be tested without a
   running macOS tray.
5. For tray/menu UI behavior, verify with Computer Use against the installed or
   locally launched macOS app when acceptance depends on native rendering.
6. Keep menu layouts compact and stable. Status labels and IP values must align
   predictably, avoid unnecessary width, and remain readable in the macOS tray
   dropdown.
7. Treat root `composer quality-check` as the broad handoff gate. During
   development, run the focused Cargo commands directly from the owning Rust
   surface.

## Verification

Run the narrowest useful check first:

```bash
cd apps/agent && cargo test
cd apps/macos && cargo test
```

Before handoff for an `apps/agent` or `apps/macos` source change, run:

```bash
cd apps/agent && cargo fmt -- --check
cd apps/agent && cargo check
cd apps/agent && cargo clippy --all-targets -- -D warnings
cd apps/macos && cargo fmt -- --check
cd apps/macos && cargo check
cd apps/macos && cargo clippy --all-targets -- -D warnings
```

For broad repository handoff, run:

```bash
composer quality-check
```

`composer quality-check` includes the same `apps/agent` and `apps/macos` Cargo
subgates. If native tray rendering changed, include the Computer Use
verification evidence or record why native verification was not applicable.

For native `apps/macos` diffs, the finalization packet's topology row must use
the existing label and host-Mac evidence shape:

```markdown
- Retained topology proof: passed - host topology kind=host-macos; host=<hostname>; os=<uname/sw_vers>; command=<exact command>; evidence=<terminal/session/Computer Use artifact>
```

This row proves the implementing Mac is the topology under test. A retained
Incus topology does not satisfy native Orbit Agent/Tauri app behavior.
