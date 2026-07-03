---
name: tauri-agent-development
description: Use when working in apps/agent on the Tauri/Rust Orbit Agent macOS menu-bar runtime, tray/menu behavior, headless worker mode, local agent config, gateway ping, job polling, or Cargo/Tauri verification.
---

# Tauri Agent Development

## Scope

Use this skill for Orbit Agent changes under `apps/agent`, including the macOS
menu-bar app, native tray menu, status rows, local config loading, gateway ping,
polling worker, and typed Orbit Agent job execution.

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
- `apps/agent/tauri.conf.json`
- The changed Rust source and focused tests under `apps/agent/src`

When Tauri API details matter, fetch current Tauri v2 documentation before
changing assumptions. Tauri v2 tray work should use the current tray/menu APIs,
including `TrayIconBuilder`, `MenuBuilder`, `MenuItemBuilder`,
`on_menu_event`, and `on_tray_icon_event` where those surfaces apply.

## Workflow

1. Start from the assigned Orbit worktree and prove `pwd`, branch, and status.
2. Keep Rust/Tauri edits inside `apps/agent` unless the handoff explicitly owns
   shared docs, quality gates, or harness changes.
3. Prefer focused Rust unit coverage for behavior that can be tested without a
   running macOS tray.
4. For tray/menu UI behavior, verify with Computer Use against the installed or
   locally launched macOS app when acceptance depends on native rendering.
5. Keep menu layouts compact and stable. Status labels and IP values must align
   predictably, avoid unnecessary width, and remain readable in the macOS tray
   dropdown.
6. Treat root `composer quality-check` as the broad handoff gate. During
   development, run the focused Cargo commands directly from `apps/agent`.

## Verification

Run the narrowest useful check first:

```bash
cd apps/agent && cargo test
```

Before handoff for an `apps/agent` source change, run:

```bash
cd apps/agent && cargo fmt -- --check
cd apps/agent && cargo check
cd apps/agent && cargo clippy --all-targets -- -D warnings
```

For broad repository handoff, run:

```bash
composer quality-check
```

`composer quality-check` includes the same `apps/agent` Cargo subgates. If
native tray rendering changed, include the Computer Use verification evidence
or record why native verification was not applicable.
