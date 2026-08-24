# Orbit Feature Slice

- Slice: 01-menu-only
- Depends on: none

## Outcome

Orbit Desktop has no native window or Dock presence, and its menu contains an `Open Orbit` action that opens `https://app.orbit` in the default browser while all existing tray lifecycle controls remain.

## Scope

- Included: Tauri configuration, Rust tray behavior, browser opener integration, removal of window-specific commands and code, focused Rust coverage, and removal of frontend build inputs that are no longer required.
- Excluded: Complete Quit shutdown orchestration and product-documentation changes.

## Authority

- Decisions: Approved 2026-08-24 browser UI and menu-bar design.
- Product docs: `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, and `apps/docs/content/domains/1_node/node-concepts.md`.

## Proof

- Focused: `cd apps/macos && cargo test` plus `cargo fmt -- --check`, `cargo check`, and `cargo clippy --all-targets -- -D warnings`.
