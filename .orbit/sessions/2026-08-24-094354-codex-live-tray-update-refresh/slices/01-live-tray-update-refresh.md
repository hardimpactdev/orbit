# Orbit Feature Slice

- Slice: 01-live-tray-update-refresh
- Depends on: none

## Outcome

A running Orbit Desktop re-reads the pending update handoff on tray refresh and
updates both the enabled Restart to Update row and the update-ready tray icon.

## Scope

- Included: failing focused Rust coverage, refresh-time handoff consumption,
  update-row enabled-state synchronization, and focused Cargo proof.
- Excluded: gateway/CLI producers, installer semantics, release construction,
  and public publication.

## Authority

- Decisions: `PRODUCT_DECISIONS.md` 2026-08-23 Orbit Desktop ownership and
  Restart to Update decision.
- Product docs: `apps/docs/content/architecture.md`,
  `apps/docs/content/domains/1_node/node-concepts.md`, and
  `apps/docs/content/tech-stack.md`.

## Proof

- Focused: RED/GREEN unit coverage plus `cargo fmt -- --check`, `cargo test`,
  `cargo check`, and `cargo clippy --all-targets -- -D warnings` for
  `apps/macos`.
