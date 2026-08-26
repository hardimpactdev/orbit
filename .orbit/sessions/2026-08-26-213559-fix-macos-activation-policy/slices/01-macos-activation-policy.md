# Orbit Feature Slice

- Slice: 01-macos-activation-policy
- Depends on: none

## Outcome

The Tauri setup callback applies the Accessory activation policy only on macOS,
so Darwin keeps the menu-bar-only behavior and Linux can compile the shared
source during the repository quality gate.

## Scope

- Included: the activation-policy call in `apps/macos/src/main.rs` and focused
  Darwin/Linux compile proof.
- Excluded: menu behavior, lifecycle ownership, dependencies, updater behavior,
  other native source, and human-only E2E lanes.

## Authority

- Decisions: Linux `cargo check`, `cargo test`, and `cargo clippy` fail at
  `apps/macos/src/main.rs:114` because Tauri exposes `set_activation_policy`
  only on macOS; preserve the existing Darwin behavior while restoring the
  required cross-platform quality gate.
- Product docs: `apps/docs/content/architecture.md`,
  `apps/docs/content/tech-stack.md`, and
  `apps/docs/content/domains/node-concepts.md` define the native macOS Agent as
  a menu-bar runtime.

## Proof

- Focused: Darwin `cargo fmt -- --check`, `cargo check`, `cargo test`, and
  `cargo clippy --all-targets -- -D warnings`; Linux repeats the compile, test,
  and clippy lanes; the repository `composer quality-check` must pass; the exact
  host-macos candidate must retain successful native compilation and the
  Accessory activation-policy call in the macOS build.
