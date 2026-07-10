FINDINGS

No P0, P1, or P2 findings remain. The prior four findings and the later root/re-review corrections are closed by the current uncommitted correction diff and its focused evidence.

CHECKOUT PROOF

- Solo `whoami`: process `990`, actor `mcp-aca844d290eab03c`, process name `capture-integrity-final-rereviewer`, project `orbit` (`4`).
- `pwd`: `/Users/nckrtl/orbit/.worktrees/capture-evidence-integrity-hardening`.
- Branch: `capture-evidence-integrity-hardening`.
- HEAD: `52e5ea2503ab92cd04b283a2d5a286927fb07d20`.
- Tracked status is exactly the expected uncommitted correction diff: `apps/gateway/tests/Feature/E2ESupport/AgentSessionArchiveTest.php`, `apps/gateway/tests/Feature/E2ESupport/SessionArchiveTest.php`, `bin/orbit-agent-session-capture`, `bin/orbit-session-archive`, `harness-signals/2026-07-07-lane-close-agent-session-capture.md`, and `harness-signals/index.json`. The expected new include `bin/orbit-agent-session-capture-filesystem.php` is untracked. No unrelated tracked change was present.
- Reviewed committed range `f1acfee5de5d74432656c8d46a11e9d1bb5bff54..52e5ea2503ab92cd04b283a2d5a286927fb07d20`: `15602b850`, `0c4cd0002`, and `52e5ea250`, plus the full current diff and untracked include.
- Read the named repository guidance, `PRODUCT_DECISIONS.md`, `.orbit/loop.md`, the unchanged reviewer-988 report, current scratchpad 276 revision 96, and retained reds `capture-review-corrections-red.txt`, `capture-root-review-gaps-red.txt`, and `capture-archive-symlink-red.txt`. Revision 95 was not exposed by the current Solo read API; revision 96 retains the capture correction adjudication verbatim and adds an unrelated session-index note.
- Orchestration friction: after the valid checkout proof, an orchestrator Ctrl-C interrupted the first turn because it trusted a stale terminal header. The user confirmed the shell proof was authoritative. Review resumed from that checkpoint without repeating or broadening checkout discovery.

CONTRACT MATRIX 1-8

| # | Result | Evidence |
|---|---|---|
| 1 | PASS | `bin/orbit-agent-session-capture:69-75` rejects explicit providers outside the closed set before Solo DB access; `:138-177` canonicalizes the agent-session/provider roots, rejects symlink roots, and proves provider direct-child containment. `bin/orbit-agent-session-capture-filesystem.php:5-31` reasserts a canonical non-symlinked provider root and exact direct-child parent at destructive sites. Provider traversal and root/provider symlink fixtures pass. |
| 2 | PASS | `bin/orbit-agent-session-capture-filesystem.php:90-182` checks manifest, usage, messages, raw-source existence, basename-only raw declarations, and raw copies, and removes only the canonical direct-child temp on construction failure. `:185-220` preserves coherent rename/backup/rollback states and reasserts containment before recursive cleanup. False/native writes, write/copy failures, missing raw sources, invalid archive names, native success, and the existing rename double-failure matrix are covered. |
| 3 | PASS | `bin/orbit-agent-session-capture:561-622` derives diagnostics from actual marker matches and ownership classifications, retains legacy `checked`, and bounds `matched_candidates` and `owned_candidates` to 20. The ambiguity/no-owned fixtures assert actual paths, ownership class, cwd, primary identity, and bounded counts. |
| 4 | PASS | The includable seam is the bin-local `bin/orbit-agent-session-capture-filesystem.php`; all five declared functions use the `orbitAgentSessionCapture` prefix. `bin/orbit-agent-session-capture:6` uses `require_once`, and the entry point is `orbitAgentSessionCaptureMain`. The idempotent double-include fixture beside a predeclared generic `main()` passes without executing the CLI. |
| 5 | PASS | `bin/orbit-session-archive:391-473` applies foreign `.tmp-*` exclusion only at direct provider-child depth, warns without deleting source state, and preserves `.backup-*` and non-agent-session temp-shaped evidence. `:682-725` prevents a lone temp manifest from suppressing fallback. Focused tests prove exclusion, fallback, backup preservation, and byte-identical unrelated evidence preservation. |
| 6 | PASS | `bin/orbit-session-archive:411-443` skips root and nested directory symlinks during copying; `:682-716` rejects a symlinked staged root and skips nested directory symlinks during discovery. Root and nested external manifest/sentinel fixtures prove no traversal or copy and no source deletion. `bin/orbit-agent-session-capture-filesystem.php:45-83` unlinks symlink entries rather than following them during contained cleanup. |
| 7 | PASS | Current corrections do not change Stage 1 provider-floor semantics or Stage 2/3 ownership, incarnation, and atomic selection contracts beyond the accepted safety/diagnostic seams. The narrow Stage 1/2/3 rerun passed 24 tests / 182 assertions, including exact identity, ownership precedence, incarnation-floor behavior, and deterministic replacement/rollback. |
| 8 | PASS | `harness-signals/2026-07-07-lane-close-agent-session-capture.md:10-11,79-143` now matches the implemented closed-provider, containment, checked construction, bounded diagnostics, direct temp exclusion, backup preservation, and no-directory-symlink-follow behavior. `harness-signals/index.json:725-749` carries the single corresponding generated-row update, and `bin/orbit-harness-signal-index --check` passes. The procedural include is the smallest accepted local seam; no new class, dependency, public flag, product abstraction, or speculative product decision was introduced. |

TEST/VERIFICATION GAPS

- No blocking gap remains in the reviewed integrity contract. This re-review ran only narrow read-only checks: correction/root/re-review filters passed 21 tests / 335 assertions; Stage 1/2/3 filters passed 24 / 182; PHP syntax passed for `bin/orbit-agent-session-capture`, the new filesystem include, and `bin/orbit-session-archive`; the signal index check and `git diff --check` passed.
- The retained red artifacts are credible and contract-specific: initial reviewer corrections failed 14/14 definitions with 28 assertions; root corrections retained 5 intended failures plus 1 existing pass / 22 assertions; nested archive-symlink traversal retained 1 intended failure / 2 assertions. The later root `agent-sessions` symlink fixture is green-only because the non-follow guard had already landed; `.orbit/loop.md` records the independent source-first finding and explicit no-revert instruction.
- `composer quality-check` remains pending in `.orbit/loop.md` and is still required before merge/finalization. It was intentionally not rerun because this assignment prohibited broad aggregate quality. No E2E command was run.
- Scratchpad revision 95 could not be fetched as a historical revision through the available Solo read surface; current revision 96 was read and its capture-integrity section matches the revision-95 correction contract referenced by `.orbit/loop.md`.

PRODUCT_DECISIONS IMPACT

None. The slice hardens repository-development evidence capture, archive hygiene, focused tests, and an existing harness signal. It does not establish or reverse Orbit product direction, so `PRODUCT_DECISIONS.md` should remain unchanged.

VERDICT: pass
