# Orbit Feature Loop

Copying this template is handled by `bin/orbit-prepare-worktree`. Keep it as the
single anchor for the active feature. Raw feedback belongs in the immutable
`.orbit/feedback.jsonl` stream, never in this file.

- Session: feat-codex-tauri-updater-key-provisioning
- Worktree: /Users/nckrtl/orbit/.worktrees/codex-tauri-updater-key-provisioning
- Branch: codex/tauri-updater-key-provisioning

## Goal

The committed Tauri updater public key is the Mini release public key (minisign id 6D3598A45B5D960D), the build-time verifier embeds that same key, and an archive signed with the matching external private key verifies while a tampered archive or signature is rejected.

## Scope

- Owned: apps/macos/tauri.conf.json updater pubkey, apps/macos/build.rs embedding via updater_pubkey.rs, apps/macos installer verifier coverage
- Constraints: never print, copy into the repository, or record the Mini private key or password; public key and signatures are committable
- Out of scope: Apple Developer ID signing and notarization, a full desktop bundle/DMG rebuild, republishing already-released updater archives

## Proof

- Verification:
  - focused: passed - cargo test --manifest-path apps/macos/Cargo.toml --lib; host topology kind=host-macos; host=mini; os=Darwin arm64 macOS 27.0 26A5416b; evidence=`.orbit/evidence/tauri-updater-key-host-proof.txt`
  - broader: passed - composer quality-check; candidate=caa866fcbdbf3fb2c301e86b29d68ec7abd34905; artifact=`.orbit/quality-gates/quality-check-2026-08-23T203038Z-c7d07c149156.json`; exit_code=0; dirty=false
  - runtime: passed - candidate=caa866fcbdbf3fb2c301e86b29d68ec7abd34905; venue=host-macos; environment=dev-fixture; command=cargo test --manifest-path apps/macos/Cargo.toml --lib committed_pubkey_verifies_a_signature_from_the_release_private_key; expected=committed pubkey verifies the Mini-signed payload and rejects a tampered payload or signature; observed=cargo test passed and Mini-signed archive verified with tampered archive and signature rejected; result=passed; evidence=`.orbit/evidence/tauri-updater-key-host-proof.txt`
- Blast radius: complete - evidence=repo-wide `rg` for old key id 48FD9C80A2A514AF and old pubkey base64 (none) plus every apps/macos pubkey surface confirming the single source tauri.conf.json -> build.rs -> ORBIT_UPDATER_PUBKEY -> installer::trusted_updater_pubkey; result=no drift, trust root single-sourced and drift-locked by embeds_the_committed_tauri_conf_updater_pubkey
- Review: passed - VERDICT=PASS human-judgment=not-required; reviewer=review-1; candidate=caa866fcbdbf3fb2c301e86b29d68ec7abd34905
- Reviewed feature tip: caa866fcbdbf3fb2c301e86b29d68ec7abd34905
- Acceptance venue: host-macos
- Acceptance: accepted - automated - reviewer-confirmed no-human-judgment
- Accepted feature tip: caa866fcbdbf3fb2c301e86b29d68ec7abd34905
- Accepted main tip: 7b62e72cd8216897d7bbe1923c72f7dd272e8b8b

## Status

- State: accepted
- Blocker: none

## Feedback

- Events: `.orbit/feedback.jsonl`
