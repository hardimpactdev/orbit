# Orbit Feature Slice

- Slice: 01-macos-app-icon-bundle
- Depends on: none

## Outcome

A clean native Orbit Desktop build produces an application bundle whose plist declares an icon and whose packaged `.icns` renders the approved Orbit logo in Finder.

## Scope

- Included: tracked app-icon assets under `apps/macos`, explicit Tauri bundle icon configuration, removal of the generated ignored fallback when obsolete, deterministic regression coverage for clean source and packaged bundle behavior, focused Cargo proof, and a single checkpoint.
- Excluded: tray icon behavior, menu layout, Agent lifecycle, updater semantics, release publication, loop artifacts, and product documentation unless a concrete contradiction is found.

## Authority

- Decisions: `PRODUCT_DECISIONS.md` 2026-08-23 exact-candidate native asset and Orbit Desktop ownership decisions.
- Product docs: `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, and `apps/docs/content/domains/1_node/node-concepts.md`.

## Proof

- Focused: demonstrate RED for the missing tracked/declarative icon contract, then GREEN with `cd apps/macos && cargo test`, `cargo fmt -- --check`, `cargo check`, and `cargo clippy --all-targets -- -D warnings`; inspect a clean native `.app` plist and `.icns` on the host Mac.
