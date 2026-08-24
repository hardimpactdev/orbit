CHECKOUT_PROOF: cwd=/home/nckrtl/orbit/.worktrees/codex-live-tray-update-refresh; branch=codex/live-tray-update-refresh; head=f72abc493fd956c323951488c3f256be4d94dfbc; main=3c23686d9f8f4e1400cb1ae9c207106e3c412b5c; status=clean

# Corrected Terminal Review - live-tray-update-refresh

Worker: review-1. Persona: `.agents/review-personas/general.md`. This is the
delta review of the corrected tip; the prior round's findings are closed below
in the same process.

## Reviewed Candidate

Corrected candidate `f72abc493fd956c323951488c3f256be4d94dfbc` reviewed against
prior candidate `401b6bc109a4d74783a24de9f9079304f423f47c`. The prior candidate
was amended, so the branch carries a single commit above `main`
(`3c23686d9f8f4e1400cb1ae9c207106e3c412b5c`).

- Correction delta (`401b6bc1..f72abc49`): `apps/macos/src/main.rs`, +90/-26.
- Full feature diff vs `main`: `apps/macos/src/main.rs` only, +298/-17.

Refreshed proof receipt: `bin/orbit-feature-proof-receipt --loop=.orbit/loop.md`
returns `ok:true`, `dirty:false`, candidate `f72abc493fd956c323951488c3f256be4d94dfbc`,
gate `quality-check`, artifact
`.orbit/quality-gates/quality-check-2026-08-24T074005Z-136c925aac8f.json`, venue
`host-macos`, and a candidate-bound runtime row with evidence
`.orbit/evidence/live-tray-update-refresh-f72abc493fd9.txt`. Focused proof moved
from 54 to 55 tests, matching the one added focused case.

## Prior Findings - Closure

### D1 - CLOSED: watcher no longer overrides in-flight install states

`next_restart_ready_state` (`apps/macos/src/main.rs:479-498`) now early-returns
`None` when `current` is `Installing { .. }`, `Relaunching { .. }`, or
`Verified { .. }`, before consulting the handoff. The in-flight install states
survive a handoff that is still on disk, and the fail-closed `Verified` state
produced by `transition(Installing, InterruptedInstall)` can now persist instead
of being re-asserted as an enabled `RestartReady` every 500 ms. Including
`Verified` in the guard is correct and slightly stronger than the smallest
correction I proposed: in this app `Verified` is only reachable from an
interrupted install, so it is precisely the fail-closed state that had to be
preserved after a partial replacement. The success path is unaffected because a
fresh handoff is adopted from `Idle`, and startup still seeds state through
`consume_pending_update_handoff`.

New focused coverage closes the gap I flagged:
`live_handoff_reconciliation_preserves_install_states_with_restart_ready_handoff`
(`apps/macos/src/main.rs:1690`) asserts `None` for `Installing`, `Relaunching`,
and `Verified` against a `restart-ready` handoff - the case that actually occurs
during an install.

### D2 - CLOSED: no lock held across a blocking main-thread dispatch

The watcher now clones the row handle out and releases the mutex before touching
the menu (`apps/macos/src/main.rs:515-518`):

    let update_item = menu_items.lock().ok().map(|items| items.update.clone());
    if let Some(update_item) = update_item {
        TrayMenuItems::update_update_item(&update_item, &runtime);
    }

The temporary `MutexGuard` is dropped at the end of that `let` statement, so the
blocking `set_text` / `set_enabled` calls in `update_update_item`
(`apps/macos/src/main.rs:850-859`) run with no lock held. `run_item_main_thread!`
(`tauri-2.11.5/src/menu/mod.rs:25`) can still block the watcher on `rx.recv()`
until the main thread drains the queue, but the main thread can now always take
`menu_items` and reach the event loop, so the inversion is gone.

Audited every remaining `menu_items.lock()` site: `apps/macos/src/main.rs:874`,
`:887`, and `:893` are all inside `refresh_menu`, which runs on the main thread,
where `send_user_message` (`tauri-runtime-wry-2.11.4/src/lib.rs:239`) takes the
inline branch and never blocks. `tray.set_icon` in the watcher
(`apps/macos/src/main.rs:519`) blocks but holds no lock. No inversion remains.

Note on the cloned handle: if `refresh_menu`'s replace branch swaps
`*items = tray_menu.items` (`apps/macos/src/main.rs:887`) between the clone and
the write, the watcher writes to a detached item. That is a benign no-op, not
stale UI, because `refresh_menu` calls `reconcile_restart_ready_handoff` first
and `build_tray_menu` rebuilds the row from the reconciled state. Not a finding.

Supporting empirical evidence: the Mini interaction-race proof
(`.orbit/evidence/live-tray-update-refresh-f72abc493fd9.txt`, steps 2 and 3)
changed the handoff and immediately opened the native tray in both directions,
recording `candidate_responsive=passed` after 1.5 s each time, then quit through
the real Quit Orbit item (`candidate_quit=passed`). A timing probe cannot prove
the absence of a race on its own; the structural fix above is the closure, and
this run is corroboration on the exact candidate.

### D3 - CLOSED: a valid `automatic` handoff no longer clears the retry row

`observe_pending_handoff` (`apps/macos/src/main.rs:447-457`) replaces the
`Option` collapse with an explicit three-way `ObservedPendingHandoff`
(`RestartReady` / `Automatic` / `Missing`), and `next_restart_ready_state` maps
`Automatic` to `None` (`apps/macos/src/main.rs:494`). A `RestartReady` row left
by a failed legacy `automatic` startup install is preserved while its valid
handoff is still on disk, matching pre-feature behavior. Covered by the extended
`live_handoff_reconciliation_preserves_automatic_install_state`
(`apps/macos/src/main.rs:1713`), which now asserts both `Installing` and
`RestartReady` against an `Automatic` observation.

## New Findings

None. No DEFECT was introduced by the correction delta.

## POLISH - Carried Forward (non-blocking)

- `apps/macos/src/main.rs:1554` - `tray_refresh_updates_update_action_state`
  still asserts on the source text of `main.rs`
  (`self.update.set_enabled(update_enabled)`,
  `reconcile_restart_ready_handoff(&runtime)`). It pins implementation strings
  rather than behavior; the real coverage lives in
  `update_menu_presentation_enables_only_restart_ready_updates` and the
  `next_restart_ready_state` cases. The `include_str!` pattern has precedent on
  `main` (`apps/macos/src/main.rs:1461`, `:1469`), so this stays convention-
  consistent and non-blocking.
- `apps/docs/content/tech-stack.md:475` still describes the Desktop menu as a
  "one-shot gateway/status refresh when the macOS menu opens". No contradiction:
  that bullet covers gateway/status, and the new watcher polls a local
  owner-only file, not the gateway. But the Desktop now runs a permanent 500 ms
  handoff poll that can change the update row and tray icon with no menu
  interaction, and no doc records it. One sentence in the Orbit Desktop
  paragraph would prevent later drift. PASS is not contingent on this; whether
  to land it here or in a docs follow-up is the operator's call.
- `apps/macos/src/main.rs:455` - `ObservedPendingHandoff::Missing` is returned
  for every `PendingUpdateError`, so a present-but-`Malformed` or `UnsafePath`
  handoff is named "missing". Behavior is correct and fails closed per the loop
  contract; only the variant name conflates absent with invalid.

## Blast Radius

BLAST_RADIUS: not-required - the correction stays inside
`apps/macos/src/main.rs`. `ObservedPendingHandoff` is a private, file-local
enum; `INSTALL_MODE_RESTART_READY`, `INSTALL_MODE_AUTOMATIC`, and the
`PendingDesktopUpdate` schema are consumed unchanged; no transport, CLI,
gateway, or shared vocabulary surface is touched, and no docs file changed
(`git diff --name-only 3c23686d9..f72abc49 -- apps/docs` returns 0 files).
Bounded check for orphaned references after the rename:
`rg -n "update_update_only|restart_ready_handoff\b|ObservedPendingHandoff|update_update_item"`
across the repo returns hits only in `apps/macos/src/main.rs` - the removed
`update_update_only` and `restart_ready_handoff` symbols have no surviving
callers anywhere. Result: no cross-surface contract requires change.

BLAST_RADIUS: not-required
HUMAN_JUDGMENT: not-required
VERDICT: PASS
