# Review handoff (re-review of corrected tip): macos-managed-agent-review

CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/codex-macos-desktop-managed-agent; branch=codex/macos-desktop-managed-agent; head=3fd8a965a9d61782c2d5ef6e22630c446979c691; main=732e61be405b88c36be05aec3379f4bf6abfa1a2; status=clean

candidate=3fd8a965a9d61782c2d5ef6e22630c446979c691
base=732e61be405b88c36be05aec3379f4bf6abfa1a2
delta-from-prior=225b24dfe..3fd8a965a (single commit "Keep pending handoff until a bound update succeeds")
proof-receipt=ok (gate=quality-check, dirty=false, venue=host-macos)

Reviewed the delta (installer.rs +283, main.rs +89/-36, update_machine.rs +87)
against the FIX-1 findings, plus confirmed prior invariants are untouched.

## DEFECT 1 — RESOLVED

- Live state-machine wiring: `install_ready_update` now transitions on
  `UpdateEvent::BeginInstall` at entry, and `commit_install_attempt` drives
  `disposition_after_install` -> `transition` for the outcome. `transition`,
  `UpdateEvent`, and the new `disposition_after_install` are called from
  main.rs and installer.rs — no longer dead code. (main.rs:447-509,
  installer.rs:349-361, update_machine.rs:114-149)
- Success-only handoff deletion: `commit_install_attempt` removes the handoff
  file only when `disposition.remove_handoff`, which is true solely for
  `InstallAttemptResult::Succeeded`. Proven by
  `update_machine::tests::removes_handoff_only_after_complete_success`.
- Failure retry visibility: `apply_bound_update` classifies pre-replacement
  failures (desktop archive verify fail, staged bins absent, owner-bin
  HashMismatch — which verifies before replacing) as `FailedBeforeReplacement`
  -> `RestartReady`, and post-replacement failures as `Interrupted` ->
  `Verified`; both keep the handoff, both render "Restart to Update Orbit X"
  with the tray dot, and `install_ready_update` re-spawns the Agent child
  (spawn_or_mark_missing) instead of restarting, so the node is not left
  Agent-less. Proven by
  `installer::tests::keeps_handoff_and_returns_restart_ready_when_apply_fails_before_replacement`.
- Partial replacement resume: `bins_already_bound` short-circuit skips re-replacing
  already-bound owner binaries; with the preserved handoff, a later attempt
  completes the desktop bundle. Proven by
  `installer::tests::resumes_from_preserved_handoff_after_partial_owner_bin_replacement`
  (agent/cli replaced, desktop blocked by a 0o555 parent -> Interrupted -> handoff
  kept -> unlock -> resume installs the bound desktop-v2 -> Succeeded removes handoff).
- Failure classification is sound: owner binaries are hash-verified before any
  atomic replace, so a HashMismatch replaces nothing and is correctly
  FailedBeforeReplacement; only genuine post-replacement Io errors map to
  Interrupted.

## Proof

- Independent narrow reproduction: `cargo test --lib` = 34 passed / 0 failed,
  including the four named regression tests (installer + update_machine).
- Mini host-macOS runtime receipt, candidate-bound on Verification.runtime:
  expected/observed = bound Desktop/Agent/CLI install from a staged handoff
  (resume test) + truncated staged archive leaves hashes unchanged with the
  handoff retained and "Restart to Update Orbit 0.1.196" visible; Desktop 95848
  owned Agent 95947; launchd absent; owner handoff retained. Success removal is
  proven by the deterministic resume/commit tests; retain + visibility + lifecycle
  proven on host. evidence=`.orbit/evidence/macos-desktop-managed-agent-3fd8a965a/update-install-proof.txt`.

## Prior invariants unchanged

Delta touches only the three apps/macos Rust files (additive). Lifecycle
(close!=Quit, Quit leaves no Agent, sole ownership/canonical-only migration, CLI
independence), signing chain + notarization-fail-closed, native release assets +
manifest binding, docs, and the CLI<->Rust handoff schema are untouched and
remain valid from the prior review; no schema/transport/vocabulary change in this
delta.

## POLISH (non-blocking, carried over, unchanged)

- supervisor watcher accumulation (main.rs:276-323): one watcher thread per
  (re)start, never retired; self-heals via stdin-EOF, rate-bounded, does not
  breach any invariant. The new failure-path `spawn_or_mark_missing` reuses this
  path but does not worsen it materially.

## Blast radius

Feature-level inventory was completed in the prior review (handoff schema
producer/consumer agreement, `DESKTOP_LIFETIME_ENV` producer/consumer, manifest
`desktop_artifacts` consumers) and remains resolved; this delta adds no new
cross-boundary surface (internal update-install state machine within owned
apps/macos). No gaps.

BLAST_RADIUS: complete

HUMAN_JUDGMENT: not-required

VERDICT: PASS
