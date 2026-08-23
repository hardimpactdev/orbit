candidate=caa866fcbdbf3fb2c301e86b29d68ec7abd34905

# review-1 handoff

Independent general review of the Tauri updater trust-root candidate.

## Verdict

- VERDICT: PASS
- BLAST_RADIUS: complete
- HUMAN_JUDGMENT: not-required
- reviewer: review-1
- persona: `.agents/review-personas/general.md`

## Reviewed identity

- candidate SHA: `caa866fcbdbf3fb2c301e86b29d68ec7abd34905`
- base SHA: `7b62e72cd8216897d7bbe1923c72f7dd272e8b8b`
- branch: `codex/tauri-updater-key-provisioning`
- worktree: `/Users/nckrtl/orbit/.worktrees/codex-tauri-updater-key-provisioning`
- checkout: clean; HEAD == candidate; main == base

## Delta reviewed

Two commits `b2675aca2` (pubkey replacement) + `caa866fcb` (CLI test isolation):

- `apps/macos/tauri.conf.json` — updater pubkey now the Mini release key
- `apps/macos/src/installer.rs` — two new trust-root tests
- `apps/cli/tests/Feature/InternalFleetUpdateInstallCliCommandTest.php` — fake-systemd isolation for one case

## Findings (no DEFECT, no POLISH)

- Trust-root chain cannot drift: single source tauri.conf.json -> build.rs -> ORBIT_UPDATER_PUBKEY -> installer::trusted_updater_pubkey. `embeds_the_committed_tauri_conf_updater_pubkey` asserts committed == embedded == env and the decoded key contains `minisign public key: 6D3598A45B5D960D`.
- Committed pubkey is the Mini release key: base64 decodes to id 6D3598A45B5D960D; fixture signature key id and `signature from tauri secret key` comment match.
- Private-key possession proven without exposure: `committed_pubkey_verifies_a_signature_from_the_release_private_key` verifies a deterministic committed signature through the real `verify_updater_signature` path. The signature is public, committable output, not key material.
- Fail-closed intact: tampered payload and tampered signature (base64 index 80, inside signature region) both return `Err(InstallError::InvalidSignature)`; runtime evidence shows the cargo test ok plus host archive verify PASS and both rejections.
- CLI change is test-isolation only: prepends a fake systemd bin to PATH matching the file's established pattern; no production source touched, behavior under test unchanged.
- No secret leakage: branch delta, `.orbit/`, and `apps/macos/` contain no private key or password value; only env-var names and a "never printed" note appear.

## Blast radius

- classification: complete (updater pubkey is the trust root for the desktop auto-update transport — a product decision on a transport/trust boundary)
- evidence: repo-wide `rg` for old key id `48FD9C80A2A514AF` and the old pubkey base64 (none) plus every apps/macos pubkey surface; single source confirmed
- result: no drift; trust root single-sourced and drift-locked by the embeds test

## Human judgment

- not-required: all remaining acceptance is deterministic cargo tests an agent runs and inspects; the runtime claim (fixture verification) is fully proven; auto-update-against-GitHub is out of scope per the Goal.

## Loop record

`.orbit/loop.md` Proof updated: Blast radius=complete, Review=passed (human-judgment=not-required), Reviewed feature tip=caa866fcbdbf3fb2c301e86b29d68ec7abd34905.

## Next action

Orchestrator proceeds to acceptance / LAND on venue host-macos. No fixes required.
