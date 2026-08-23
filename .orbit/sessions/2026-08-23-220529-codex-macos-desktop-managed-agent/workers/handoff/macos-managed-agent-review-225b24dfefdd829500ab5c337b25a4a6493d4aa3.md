# Review handoff (CORRECTED — supersedes prior PASS): macos-managed-agent-review

CHECKOUT_PROOF: cwd=/Users/nckrtl/orbit/.worktrees/codex-macos-desktop-managed-agent; branch=codex/macos-desktop-managed-agent; head=225b24dfefdd829500ab5c337b25a4a6493d4aa3; main=732e61be405b88c36be05aec3379f4bf6abfa1a2; status=clean

candidate=225b24dfefdd829500ab5c337b25a4a6493d4aa3
base=732e61be405b88c36be05aec3379f4bf6abfa1a2
proof-receipt=ok (gate=quality-check, dirty=false, venue=host-macos)

Reassessment note: my first pass returned PASS with this issue logged as POLISH.
On re-check against loop authority it violates a DECLARED transition, so it is a
blocking DEFECT. Corrected verdict below. All other prior findings unchanged;
the watcher-accumulation item stays POLISH.

## DEFECT 1 — retry transition is violated; failure/retry state machine is dead code

Loop Scope declares transitions: `retry: a staged valid handoff remains
available for a later restart`, `failure: invalid or incomplete handoff is
rejected without replacing installed artifacts`, and dangerous invariant
`partial replacement cannot be reported complete`.

Evidence:
- `apps/macos/src/main.rs:442-456` `install_ready_update`:
  `let _ = apply_verified_update(&update); let _ = std::fs::remove_file(path);`
  — the apply result is discarded and the handoff file is removed
  UNCONDITIONALLY (success or failure), then `app.restart()`.
- `apps/macos/src/main.rs` writes `update_state` only at :424
  (`ready_state_for_handoff`, consume time). It is NEVER transitioned to
  `Failed` or back to `RestartReady` after a failed/interrupted apply.
- `rg transition\(|UpdateEvent apps/macos/src` (non-test): every `UpdateEvent`
  and `update_machine::transition` reference lives inside `update_machine.rs`
  and its own `#[cfg(test)]` block. The tested transitions
  `Installing + InstallFailedBeforeReplacement -> RestartReady`,
  `Installing/Relaunching + InterruptedInstall -> Verified`,
  `IntegrityFailed -> Failed{retryable:false}` are unreachable from the runtime
  install path.

Impact vs declared transitions:
- retry: VIOLATED. `apply_verified_update` fails BEFORE any replacement in the
  common cases — desktop archive SHA-256/signature mismatch
  (`desktop_archive_ready`), or staged Agent/CLI absent while installed hashes
  do not match (`main.rs:483-487` returns Err). The state machine says this
  returns to `RestartReady` (retry available); the code instead deletes the
  handoff, so it is NOT available for a later restart. Directly reachable on the
  automatic path (`consume_pending_update_handoff -> Installing ->
  install_ready_update` at setup) if staging is incomplete/raced, and on the
  Restart-to-Update click if staged bytes were tampered/truncated after the
  handoff was written.
- failure: replacement half still holds (invalid handoff replaces nothing), but
  the handoff is discarded rather than left for retry.
- partial replacement recoverability: owner bins are replaced BEFORE the desktop
  bundle (`main.rs:474-491`); if `install_desktop_bundle` fails after
  `install_owner_binaries` succeeds, the system is left Agent/CLI-new +
  Desktop-old, the error is swallowed, and the handoff is deleted — so the
  designed resume (`installer::reconcile_installed_identity`) can never run. No
  completion is textually "reported", but the mixed state is unrecoverable by
  the declared retry mechanism.
- "visible Restart to Update": on failure the tray never shows Failed/RestartReady;
  the update silently vanishes.

Failure scenario (concrete): valid RestartReady handoff; user clicks Restart to
Update; staged `Orbit.app.tar.gz` was truncated after the handoff was written ->
`desktop_archive_ready` returns HashMismatch -> `let _ =` swallows it ->
`remove_file` deletes `~/.config/orbit/pending-desktop-update.json` -> app
restarts on the OLD version with NO pending handoff and NO visible failure. Later
restarts cannot retry. Retry transition broken.

Smallest correction (no files edited here):
- In `install_ready_update` (and the automatic setup path), stop discarding the
  apply outcome and stop the unconditional `remove_file`. On `Ok(())`: remove the
  handoff and proceed to restart. On `Err(_)`: DO NOT remove the handoff;
  transition `update_state` through the existing `update_machine::transition`
  to `RestartReady` (failed-before-replacement) so the tray stays visible and
  the next launch's `consume_pending_update_handoff` re-derives RestartReady for
  retry. (A definitively non-retryable integrity failure may instead set
  `Failed{retryable:false}`, but the loop's retry clause is about a valid staged
  handoff, so preserve-and-RestartReady is the safe default.)
- This one change also fixes the ordering/mixed-state case: the preserved handoff
  lets the existing `reconcile_installed_identity` resume converge the bound set
  on the next attempt. Ordering need not change if the handoff survives.
- To make it provable without a live Tauri app, extract the post-apply decision
  (keep-vs-delete handoff + next UpdateState) into a pure function, matching the
  module's existing pure-decision style (`crash_action`,
  `dashboard_close_action`), and call `update_machine::transition` from it so the
  state machine is no longer dead code.

Proof expectations for the corrected tip:
1. Focused Rust test: given a valid RestartReady handoff whose apply returns Err
   before replacement (staged desktop SHA-256 mismatch, or staged bins absent +
   installed hashes not matching), assert installed artifacts unchanged, handoff
   file still present, and resulting UpdateState == RestartReady (or Failed).
   Today's code deletes the file, so this test pins the regression.
2. Resume test: owner bins replaced then desktop install fails -> handoff
   survived -> a second apply run converges to the fully-installed bound set via
   `reconcile_installed_identity`.
3. Host-macOS runtime receipt (venue is host-macos), candidate-bound on the
   `Verification.runtime` row, exercising the headline outcome end-to-end at
   least once: valid staged handoff -> visible Restart to Update installs the
   bound Desktop/Agent/CLI set (success transition, currently unproven at
   runtime); plus one negative — tampered/incomplete staged archive -> artifacts
   intact, handoff retained, tray shows Restart to Update/Failed.

Because the success install and the retry transition are unproven at runtime and
retry is actively contradicted by code, `Verification.runtime` cannot stand as
`passed` for the update-install outcome; it currently proves only the lifecycle
(start-one-child, migrate, hide, Quit), which remains valid.

## POLISH (non-blocking, unchanged)

- supervisor watcher accumulation — `watch_child_crashes` (main.rs:276-323)
  spawns a new watcher per (re)start without retiring the prior one; after >=1
  restart two watchers can double-observe one exit and briefly double-spawn.
  Self-heals via stdin-EOF and is rate-bounded, so "Quit leaves no Agent" holds;
  leaks one thread per crash. Suggest a single long-lived supervisor loop or a
  generation guard.

## Still-valid confirmations from the first pass

Lifecycle invariants (close!=Quit, Quit leaves no Agent, sole ownership /
canonical-only migration, CLI independence) are proven on host Mini
(lifetime-proof.txt, quit-stop.txt). Signing chain, notarization-fail-closed,
release asset binding, and the CLI<->Rust handoff schema agreement all hold.
DESKTOP_LIFETIME_ENV producer/consumer pair confirmed. Blast-radius inventory
complete (product decision, ownership boundary, shared env vocab, shared handoff
+ manifest schema — all agree).

BLAST_RADIUS: complete

HUMAN_JUDGMENT: not-required

VERDICT: FIX
