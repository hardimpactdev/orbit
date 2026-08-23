candidate=caa866fcbdbf3fb2c301e86b29d68ec7abd34905

# tauri-updater-key-impl handoff

Replaced the orphaned Tauri updater trust root with the Mini release public key.

## Candidate

- SHA: `caa866fcbdbf3fb2c301e86b29d68ec7abd34905`
- Branch: `codex/tauri-updater-key-provisioning`
- Worktree: `/Users/nckrtl/orbit/.worktrees/codex-tauri-updater-key-provisioning`
- Clean: yes

## Public-key fingerprint

- minisign id: `6D3598A45B5D960D`
- sha256 of committed pubkey string: `3f77cf4ff4fee0795199d27fcf0632a97b371513f0872a47cf706f46be73d473`
- source: `/Users/nckrtl/.config/orbit/release/tauri-updater.key.pub` (mode 0600)
- private key and password were not printed, copied into the repository, or recorded

## Changed files

- `apps/macos/tauri.conf.json`
- `apps/macos/src/installer.rs`
- `apps/cli/tests/Feature/InternalFleetUpdateInstallCliCommandTest.php`

## Focused

- red: `embeds_the_committed_tauri_conf_updater_pubkey` failed against the old key
- green: `cargo test --manifest-path apps/macos/Cargo.toml --lib` 36 passed
- macOS: `cargo fmt --check`, `cargo check`, `cargo clippy --all-targets -- -D warnings` passed
- CLI isolation: `InternalFleetUpdateInstallCliCommandTest` 25 passed
- `bin/orbit-secret-scan` passed

## Loop evidence

`.orbit/loop.md` Proof.Verification now names:

- focused: `cargo test --manifest-path apps/macos/Cargo.toml --lib` on host=mini Darwin arm64 macOS 27.0 26A5416b, evidence=`.orbit/evidence/tauri-updater-key-host-proof.txt`
- broader: `composer quality-check` candidate=`caa866fcbdbf3fb2c301e86b29d68ec7abd34905` artifact=`.orbit/quality-gates/quality-check-2026-08-23T203038Z-c7d07c149156.json` exit_code=0 dirty=false
- runtime: structured host-macos receipt, result=passed, evidence=`.orbit/evidence/tauri-updater-key-host-proof.txt`

## Broader

- `composer quality-check` passed
- artifact: `.orbit/quality-gates/quality-check-2026-08-23T203038Z-c7d07c149156.json`
- proof receipt: ok, venue=`host-macos`, dirty=false

## Host-macOS proof

- host: mini, Darwin arm64, macOS 27.0 (26A5416b)
- signed payload `orbit-desktop-updater-trust-root` with Mini private key via `TAURI_SIGNING_PRIVATE_KEY_PATH` and password env (not printed)
- committed pubkey verifies that signature through `verify_updater_signature`
- tampered payload and tampered signature are rejected
- evidence: `.orbit/evidence/tauri-updater-key-host-proof.txt`

## Notes

The first quality-check failed on Darwin because the optional Agent artifact install example probed the live host PATH (`systemctl` missing). Isolated that test from host systemd. Venue remains `host-macos` because the CLI change is under `/tests/`.
