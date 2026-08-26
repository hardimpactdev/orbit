# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-fix-macos-activation-policy
- Worktree: /Users/nckrtl/orbit/.worktrees/fix-macos-activation-policy
- Branch: fix-macos-activation-policy

## Goal

The native macOS app keeps its Accessory activation policy on macOS while the
required Linux quality gate can compile the shared Rust source.

## Scope

- Owned: `apps/macos/src/main.rs` and exact verification evidence.
- Constraints: preserve the macOS menu-bar lifecycle and Accessory activation
  policy; make only the platform boundary explicit; do not change dependencies,
  product behavior, or run human-only E2E lanes.
- Out of scope: release workflow changes, UI changes, updater changes, and other
  quality-gate failures.

## Slices

| Slice | State | Checkpoint |
| --- | --- | --- |
| `.orbit/slices/01-macos-activation-policy.md` | complete | a716d1eea10463ed4f73ae70e02f973fb9c13b14 |

## Proof

- Verification:
  - focused: passed - candidate=a716d1eea10463ed4f73ae70e02f973fb9c13b14; host-macos cargo fmt/check/test/clippy passed with 68 tests; Beast/Linux cargo check/test/clippy passed with 68 tests
  - broader: passed - exact candidate a716d1eea10463ed4f73ae70e02f973fb9c13b14 passed all 51 `composer quality-check` subgates; artifact `.orbit/quality-gates/quality-check-2026-08-26T192642Z-836f81c15022.json`; final-check passed with timing warnings only
  - runtime: passed - candidate=a716d1eea10463ed4f73ae70e02f973fb9c13b14; venue=host-macos; environment=dev-fixture host=nick.local os=Darwin 27.0 26A5421a; command=`cargo fmt -- --check`, `cargo check`, `cargo test`, and `cargo clippy --all-targets -- -D warnings` from `apps/macos`; expected=macOS compiles the Accessory activation-policy call while non-macOS builds omit the platform-only call; observed=Darwin and Linux compile/test/clippy passed and the exact source retains the cfg-gated call; result=passed; evidence=`.orbit/evidence/macos-activation-policy-platform-proof.md`
- Blast radius: complete - evidence=`.orbit/evidence/macos-activation-policy-blast-radius.md`; result=repository-wide inventory confirms the exact diff adds one cfg attribute to the sole activation-policy call, with no dependency, UI, lifecycle, updater, workflow, or documentation change
- Review: passed - independent Claude reviewer confirmed the one-line cfg attribute scopes only the platform-only Tauri statement, independently reproduced Darwin and Linux proof, found the blast radius complete, and returned VERDICT=PASS; human-judgment=not-required; report=`.orbit/workers/reports/feature-review-a716d1ee.md`
- Reviewed feature tip: a716d1eea10463ed4f73ae70e02f973fb9c13b14
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: a716d1eea10463ed4f73ae70e02f973fb9c13b14
- Accepted main tip: ca29251f3035e809c77f59d56aabd5b3bdb70d98

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
