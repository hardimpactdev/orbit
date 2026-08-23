candidate=060b6fce0cf96e232c046c6e3b121f7bed4212f2

# General review: lean ordinary feature loop

VERDICT: PASS
HUMAN_JUDGMENT: not-required
BLAST_RADIUS: complete - evidence=`.orbit/workers/handoff/impl-harness-060b6fce0cf96e232c046c6e3b121f7bed4212f2.md` inventory plus targeted repository search for every active contract copy and executable consumer of the changed predicates (`orbit_worker_watch_snapshot`, `ORBIT_WORKER_HEARTBEAT_STATUSES`, `--status=handoff`, `Acceptance venue`, compact-archive entry allowlist); result=all active copies and consumers agree, no orphaned reference found.

## Checkout identity

- cwd: `/Users/nckrtl/orbit/.worktrees/codex-loop-targeted-corrections`
- branch: `codex/loop-targeted-corrections`
- tree: clean (`git status --porcelain` empty)
- HEAD: `060b6fce0cf96e232c046c6e3b121f7bed4212f2`
- base: `76be3a42484ee9654d381125d0ca0543319f0f47`
- merge base: `76be3a42484ee9654d381125d0ca0543319f0f47` (base is a direct ancestor)
- consumed receipt: `bin/orbit-feature-proof-receipt --json` -> `ok=true`, candidate `060b6fce0cf96e232c046c6e3b121f7bed4212f2`, gate `quality-check`, artifact `.orbit/quality-gates/quality-check-2026-08-23T085007Z-d224f82345bf.json`, venue `automated`, runtime `not applicable`. Not rerun.
- diff scope: 13 files, +523/-134.

## Dangerous invariants

1. Revision-sensitive watch acks - HOLDS. `bin/orbit-worker-watch:217-223` now derives the `blocked`/`stale` snapshot from note + `heartbeat_at` + worker log mtime instead of the constant `<id>:<event>`. An unchanged worker keeps the identical snapshot and stays suppressed under `--ack=`; any revised note, heartbeat, or log write yields a different snapshot and resurfaces. `handoff`/`exited` snapshots are untouched. Every ack mismatch fails toward surfacing an event, never toward hiding one. `orbit_worker_watch_snapshot` has exactly one caller (`bin/orbit-worker-watch:195`), so the new `$worktree` parameter has no stale consumer.

2. Atomic handoff, exact impl identity - HOLDS. `bin/orbit-worker-handoff:78-86` folds the optional `--note` into the same single `orbit_worker_write` that sets `status`, `handoff`, and `heartbeat_at`; that write is tmp+rename (`bin/orbit-worker-registry.php:132-155`), so the four fields land together or not at all. The impl gate at `bin/orbit-worker-handoff:41-61` still requires `candidate=<40-hex>`, `hash_equals` against clean HEAD, and `orbit_proof_receipt_problem`. Dropping `handoff` from `ORBIT_WORKER_HEARTBEAT_STATUSES` (`bin/orbit-worker-registry.php:9`) removes a way to claim a terminal state without the identity gate; `ORBIT_WORKER_STATUSES` still carries `handoff`, so stored entries and `bin/orbit-worker-stop:30` / `bin/orbit-worker-watch:123` keep reading. No remaining caller passes `--status=handoff`.

3. One-pass automated acceptance without weakened gates - HOLDS. `acceptanceAccept` (`bin/orbit-feature-acceptance:169-268`) runs the identical check set as `acceptanceReady`: clean tree, secret scan, feedback closure, `orbitLoopReviewPassed`, `orbitLoopReviewedIdentityProblem` against live HEAD, `acceptanceRequireCurrentMainIncluded`, blast radius, venue membership and `orbitLoopVenueSatisfies` against the route minimum, `orbitLoopRuntimeProofProblem`, `orbit_proof_receipt_problem`, and both human-judgment branches. Nothing was removed or made conditional. The new `orbitLoopSetLabel(... 'Acceptance venue', $venue)` at `bin/orbit-feature-acceptance:259` is what keeps `bin/orbit-feature-acceptance-contract.php:16` and `bin/orbit-session-index:267` readable after a `ready`-less accept.

4. Venue resolution never lands below the contract floor - HOLDS. `acceptanceResolvedVenue` (`bin/orbit-feature-acceptance:353-370`) returns the recorded venue only when it is a known venue that satisfies the route minimum, so a deliberate escalation above the minimum is preserved; otherwise it returns the route minimum, which is then re-validated at `:207-213`. A weaker or unparseable recorded value therefore resolves upward, and the stronger venue still has to clear `orbitLoopRuntimeProofProblem`. `FeatureAcceptanceTest.php` "validates and records an automated candidate in one accept command" pins this: seeded `venue: automated` on a CLI-file change ends at `- Acceptance venue: retained-incus` and only passes because the fixture also seeds a candidate-bound retained-incus runtime receipt.

5. Delayed human accept keeps TOCTOU protection - HOLDS. `ready` still arms and `accept` still revalidates every predicate against the current HEAD, current `main`, current tree, and the live proof receipt at accept time, so an arm followed hours later by `accept --actor=user` cannot ride a stale snapshot. `acceptanceRefuseUnresolvedRuntime` still drives an unresolved runtime back to PROVE. Covered by "keeps delayed human acceptance as a ready arm then later accept".

6. Bounded compact-archive diagnostics - HOLDS. `copyCompactDiagnosticEntries` (`bin/orbit-session-archive:1160-1225`) adds only top-level `workers/*.json`, `workers/handoff/*`, and quality-gate JSONs whose decoded `exit_code` is a non-zero int, mapped to `diagnostics/failed-gates/`. Symlinks and directories are skipped, so `workers/logs/`, `workers/briefs/`, `workers/inbox/`, `quality-gates/profiles/` (the JUnit XML), and `quality-gates/baselines/` stay out; the negative assertions in the new SessionArchiveTest case pin the log exclusion. Real artifacts are ~3KB structured JSON carrying `exit_code`, `duration_seconds`, and `subgate_durations`, which is the failure-plus-timing evidence the Goal asks for. Failed gates deliberately land under `diagnostics/`, not `quality-gates/`, so they cannot pollute the `receiptProofEntries !== $loopProofEntries` equality in `compact_archive_receipt_is_valid`. New bytes are still covered by the pre-swap `orbit-secret-scan --tree=` at `bin/orbit-session-archive:279-286`.

7. Historical archive compatibility - HOLDS. `compact_archive_receipt_is_valid` accepts only `schema_version` 2 or 3, so widening `compact_archive_proof_root_prefixes`' `default` branch is unreachable. The new allowlist arms at `bin/orbit-session-archive-receipt.php:213-221` are purely additive and still reject `.`/`..`. Adding `workers`/`diagnostics` to the `compact_archive_actual_entries` root set cannot change the verdict for a pre-existing compact archive, because compact archives before this candidate only ever contained `loop.md`, `feedback.jsonl`, and cited proof files - there is no historical archive with those directories to newly discover. Full-mode archives never enter this path.

8. Contract compression removed no protection - HOLDS. Every safety rule I checked survives in at least one active copy, and `HARNESS.md` and `.agents/skills/implementing-features/SKILL.md` state the shared worker/handoff/rearm/stop sentences identically (pinned by `McpConfigurationTest.php:296-340`). Specifically: human-only E2E (SKILL "Non-Negotiable Boundaries"), exact candidate identity (`Impl handoff names candidate=<40-character sha> and a valid SHA-bound bin/orbit-feature-proof-receipt`, now asserted in both copies), tmux inspectability and the `tmux kill*` boundary (HARNESS BUILD, SKILL LAND, validated by `bin/orbit-feature-finalization-check`), resumable fail-closed LAND (HARNESS LAND 1-8 intact). Two deletions I chased specifically: "Landing serializes per branch" has a mechanical backstop in `acceptanceRequireCurrentMainIncluded` plus `acceptanceReprove`, so removing the prose loses no enforcement; SKILL's "Preserve unrelated tmux sessions and files" is covered by the exact `=feat-<slug>` kill target, the repository-shell `tmux kill*` block, and HARNESS LAND's "without disturbing unrelated files". "Missing tmux, grok, or claude ... is a blocker" survives in BUILD in both copies.

9. Focused Mago is conditional - HOLDS. HARNESS PROVE and SKILL PROVE both read "When production PHP files changed, run focused Mago on those files before the first implementation handoff; skip focused Mago when none changed", with no unconditional gate and no new lint. Pinned by "keeps FRAME inventory and focused Mago conditional on the change kind".

10. FRAME inventory is conditional - HOLDS. Both copies scope the producer/consumer/invariant inventory to "the Goal changes a predicate, identity, vocabulary, or schema" and explicitly omit it for ordinary local changes. HARNESS keeps "Deterministic lint checks only marker syntax, not statefulness or prose" and "Do not add a new Scope row, lane, or semantic grader", and `bin/orbit-loop-contract.php` is unchanged, so nothing became lintable.

## Findings

DEFECT: none.

POLISH 1 - `bin/orbit-worker-handoff:74-83`: the handoff file is written at line 74 but `--note` is only newline-validated at line 81. A note containing a newline throws after the file exists and before `orbit_worker_write`, leaving an orphan handoff file that forces `--force` on the retry while the registry entry is untouched. The registry store itself stays atomic, so this is recoverable, not corrupting. Suggest parsing and validating `--note` above the `file_put_contents`.

POLISH 2 - `bin/orbit-feature-acceptance:353-370`: `--venue` is now reachable on `accept` and takes precedence over the recorded row, and an unrecognized recorded venue is silently replaced by the route minimum and rewritten into `loop.md`. Neither is a new privilege - `ready --venue=` could already re-arm any venue that satisfies the minimum, and the resolved value can never sit below the route floor - but the substitution is invisible and the flag is absent from both contract copies. Suggest either echoing the substitution on stdout or rejecting a non-empty recorded venue that is neither `pending` nor a known venue, and naming `--venue` in the HARNESS Acceptance Identity bullet if it is meant to be part of the surface.

POLISH 3 - `bin/orbit-worker-watch:217-223`: the `blocked`/`stale` snapshot now embeds the free-form note verbatim, so a snapshot routinely contains spaces and must be shell-quoted when pasted back as `--ack=<snapshot>`. An unquoted re-arm fails loudly with the usage error, so nothing is hidden, but hashing the revision tuple (`<id>:<event>:<sha1 of note|heartbeat|mtime>`) would keep the snapshot opaque, bounded, and copy-paste safe.

POLISH 4 - test naming/coverage: "validates and records an automated candidate in one accept command" actually asserts the weaker-recorded-venue upgrade to `retained-incus`; the name reads as if the venue stayed `automated`. Also uncovered: a recorded venue stronger than the route minimum being preserved, and `accept --venue=`.

## Residual risks

- A file in `.orbit/workers/handoff/` whose basename falls outside `[A-Za-z0-9._-]+` would now be copied into a compact archive and then rejected by `compact_archive_entry_path_is_allowed`, blocking cleanup after the archive already succeeded. Handoff names are derived from validated worker ids, so this needs a hand-placed file; it is the same failure shape the existing proof-root allowlist already has.
- A secret-shaped token inside a failed quality-gate JSON or a worker note now blocks the whole archive at the `--tree=` scan, with no per-file exclusion. Fail-closed and correct, but it is a new way LAND can stop late.
- Focused Mago and the FRAME inventory are prose contracts only; nothing executable enforces them, so they depend on worker compliance.
