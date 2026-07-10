# Signal: Lane-Close Agent Session Capture

Status: guarded
First seen: 2026-07-07
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: agent-session-capture-disambiguation
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-agent-session-capture, bin/orbit-session-archive, bin/orbit-session-archive-filesystem.php, bin/orbit-codex-pre-tool-use-hook
Guardrail change: reject non-Codex incarnation floors and invalid explicit providers before staging; discover exact integer marker identities for every provider and classify every Codex candidate by exact cwd plus standalone primary identity before cardinality; expose bounded matched/owned diagnostics; construct captures and complete session archives in checked sibling transactions with rollback and recovery state; validate the closed staged-manifest contract; never follow source symlinks; exclude direct provider temp/backup residue while preserving unrelated evidence
Related signals: harness-signals/2026-06-25-required-verification-finalization-gap.md
Superseded by: none
Tags: finalization, agent-sessions, solo, loop-engineering

## Signal

Agent-session preservation was previously resolved during session archive, after
the Solo process row and runtime-scoped identity could already be gone. That
made capture fragile and let empty or wrong provider archives look acceptable,
especially when a parent orchestrator transcript contained a child worker's Solo
marker text.

The signal recurred on 2026-07-10 when exact-marker discovery found both a
target Codex session and either a foreign session carrying an inherited target
marker or a stale session from the wrong cwd. The helper treated both cases as
indistinguishable even though the transcripts exposed deterministic provider,
cwd, and primary-identity context.

It recurred again when a deliberately restarted Codex process retained the same
marker and lane-close capture accepted the sole pre-restart rollout. The marker
still proved session ownership, but not that the rollout belonged to the active
process incarnation.

The same review found numeric-prefix marker matches, unowned Codex singletons,
and direct writes into reused slug directories. Those paths could select the
wrong transcript or mix stale success artifacts into a later success or failure.

The high-model review then found that provider containment remained lexical,
pre-replacement writes could fail without cleanup, ambiguity evidence named
arbitrary scan-order files, the private seam collided with generic globals, and
session archiving copied incomplete foreign temp siblings. Those defects made
the claimed transaction and retained evidence weaker than the signal record.

The archive-integrity review then found that archive refresh still mutated the
old final before a replacement was complete, accepted open-ended or incomplete
staged manifests, followed file symlinks, retained transaction-shaped provider
residue, and used unchecked in-place bookkeeping writes. Fallback extraction
also recorded its temporary construction path in the final top-level manifest.

The R2 capture-health review found the same ownership and no-follow contract was
not yet applied to real Claude and Grok provider shapes. Those providers decided
cardinality before exact cwd and standalone primary identity, a missing canonical
Solo process row could fall back to a colliding `spawned_processes` row, unknown
commands defaulted to Codex or disappeared from fallback extraction, and provider
discovery/raw copy could follow required or optional artifact symlinks.

The independent R2 review then found that an unresolvable nonempty Solo-row cwd
fell back to caller `--cwd`, lstat checks were separated from later path-based
opens/copies, and the first Claude/Grok fixture pass did not enforce agreement
between each provider's structural cwd root and its row/prompt-context cwd. The
owner's final symlink adjudication kept Codex global fail-closed while limiting
Claude/Grok required-artifact vetoes to their expected encoded-cwd roots.

The second independent R2 review found that discovery still traversed a
symlinked `.codex`, `.claude`, or `.grok` ancestor below canonical home, and
that unknown-provider results retained the complete command and its arguments
across public and archived output.

## Prior Occurrences

This is adjacent to
`harness-signals/2026-06-25-required-verification-finalization-gap.md`: both
concern finalization proof, but this record covers whether the worker/reviewer
session evidence exists and belongs to the named lane.

The 2026-07-10 recurrence was captured by the behavioral red in
`apps/gateway/tests/Feature/E2ESupport/AgentSessionArchiveTest.php` on worktree
`agent-session-capture-disambiguation`: two expected successes instead returned
`ambiguous_duplicate_markers`, while the pre-existing truly duplicate fixture
remained the required loud-failure safety case.

## Missing Guardrail

The harness did not require lane-close capture while the Solo process row was
alive, did not distinguish exact spawned-runtime markers from arbitrary
transcript mentions, and allowed finalization to proceed with zero healthy
captures unless a reviewer noticed the archive contents manually.

After exact-marker joining was introduced, the Codex path still lacked a safe
second-stage rule for duplicate marker candidates. It could not distinguish a
foreign primary identity or mismatched normalized cwd, and it had no manifest
vocabulary that kept timestamps explicitly corroborative rather than
selection-capable.

After disambiguation, the Codex path also lacked a caller-attested restart floor
that could reject a stale singleton without turning timestamps into candidate
ownership or selection evidence.

The helper also silently canonicalized explicit slugs and wrote captures
directly into the final directory, so reruns could retain files from a previous
capture and had no rollback boundary for replacement failure.

The replacement boundary did not yet cover construction failures or canonical
provider-root containment, and recursive delete re-derived its boundary from
the target path. Failure manifests retained `checked` but omitted the actual
matched and owned candidates. Archive discovery also treated manifest-bearing
`.tmp-*` siblings as valid staged captures.

The outer session archive had no equivalent complete-build transaction. A
refresh could destroy the previous final during construction, swap failure had
no deterministic rollback seam, and post-swap loop/index failure had no retained
backup or recovery contract. Staged evidence also lacked a closed schema and
artifact completeness gate.

## Guardrail Change

- `bin/orbit-agent-session-capture` stages provider artifacts by exact
  `Solo process ID: <id>` marker joins during lane close.
- `bin/orbit-session-archive` prefers staged `.orbit/agent-sessions/` captures
  and uses archive-time extraction only as a no-staging fallback.
- `bin/orbit-codex-pre-tool-use-hook` and `bin/orbit-feature-finalization-check`
  block named worker/reviewer/analyzer lanes when active or archived manifests
  contain zero healthy captures and no explicit waiver row.
- `HARNESS.md`, `LOOP.md.example`, and the implementation skill now document
  the lane-close capture and waiver contract.
- On duplicate Codex marker matches, `bin/orbit-agent-session-capture` now
  uses one all-occurrences exact-integer parser for provider discovery, then
  filters every candidate by exact normalized cwd and a separate standalone-line
  primary identity channel. A sole no-primary legacy candidate is visibly
  partial, a full owner outranks partial candidates, and multiple full or
  partial-only owners preserve `ambiguous_duplicate_markers`. Timestamp evidence
  remains corroborative and never selects or ranks candidates.
- `--incarnation-started-at=<ISO8601>` is a Codex-only, caller-attested
  validate-after-selection floor. Its syntax is checked before Solo DB access or
  staging; ambiguity returns before activity validation; and a unique candidate
  must have a parseable top-level timestamp at or after the floor on a
  non-`session_meta` row. Before-floor, missing, or unparseable activity returns
  `stale_pre_restart_session` with the floor, source, rollout id, and observed
  last activity, while no-flag captures retain their prior output and manifest.
- Claude and Grok reject `--incarnation-started-at` with
  `incarnation_floor_unsupported_provider` before staging mutation. Restarted
  Claude or Grok lanes require a fresh Solo process id or an explicit capture
  waiver.
- Explicit `--slug` input must already be non-empty and canonical; invalid input
  fails before Solo DB access or staging. Every success or capture-failure result
  is completed in a unique temporary directory directly under the provider root,
  then replaces the final slug directory through sibling rename, backup, and
  rollback steps. Direct-child assertions guard every rename and recursive
  delete, successful replacement removes the backup, and coherent failure
  replacement cannot retain stale success artifacts.
- Explicit `--provider` accepts only the closed `codex`, `claude`, `grok`, and
  `terminal` set before Solo DB or staging access. The helper creates and
  canonicalizes `.orbit/agent-sessions`, rejects symlinked agent-session and
  provider roots, and proves the canonical provider directory is exactly one
  child below the canonical agent-session root before deriving staging paths.
- Filesystem construction and replacement live in one bin-local procedural
  include with project-prefixed functions. Checked manifest, usage, messages,
  and raw-copy syscalls clean only the direct-child temp on construction
  failure and leave final/backup state untouched. Declared raw sources must
  exist and archive names must be basenames within `raw/`. Recursive deletion
  receives and reasserts the canonical non-symlinked provider root, its real
  path, and the candidate parent real path at the delete operation itself. A
  temp symlink is rejected and unlinked without following its target.
- Codex ambiguity and no-owned failures retain legacy `checked` and add bounded
  `matched_candidates` and `owned_candidates` records with actual paths,
  ownership class, normalized cwd, and primary Solo identity in both the
  manifest and stderr. These diagnostics never rank or select candidates.
- Claude and Grok now apply the same ownership order to their actual transcript
  and prompt-context/session-root shapes. Claude requires its normalized
  nonalphanumeric-to-dash project root to agree with row cwd when present; Grok
  requires its normalized raw-url-encoded root to agree with current
  `working_directory` or legacy `cwd` when present. Exact structural roots may
  supply cwd when the provider field is absent. Full owners outrank partials, a
  sole identity-less exact owner is `partial`, diagnostics remain bounded to 20,
  and either disagreement direction is foreign/nonhealthy.
- Lane-close capture now requires the canonical `processes` row and reports
  `solo_process_not_found` without consulting `spawned_processes`. Unknown
  lane-close and fallback commands use the existing `unsupported` status with
  the fixed, argument-free `unsupported_provider` reason instead of defaulting,
  being omitted, or retaining command text. A nonempty row cwd remains
  authoritative even when it cannot `realpath`; caller cwd is used only when
  the row has no nonempty cwd value.
- Provider readers and raw staging pin each immediately checked regular file by
  lstat, one open, and matching fstat device/inode before consuming bytes.
  Before discovery, every provider-root component below canonical home is
  lstat-checked and must resolve to its expected canonical directory, so a
  symlinked `.codex`, `.claude`, or `.grok` ancestor cannot expose external
  provider files.
  Codex required-artifact symlinks remain globally fail-closed. Claude/Grok veto
  required symlinks only inside the same expected encoded-cwd root used for
  ownership, so foreign-root symlinks cannot poison an exact regular owner.
  Owned-root required symlinks remain nonhealthy, optional Grok symlinks remain
  omitted and diagnosed, and external target bytes are never consumed or staged.
- `bin/orbit-session-archive` accepts staged capture statuses only from the
  exact `ok`, `partial`, `ambiguous`, `capture_failed`, `extraction_failed`,
  `invalid`, `missing`, `solo_process_not_found`, `stale`, and `unsupported`
  allowlist after source evidence has been copied into the complete archive
  temp. Every non-transaction direct provider/slug directory in that temp must
  contain a non-symlink regular manifest; each manifest must be schema v1,
  match its path, name a positive integer Solo process id, and provide a
  trimmed reason when non-ok. `ok` additionally requires non-empty regular
  usage, messages, and raw artifacts.
- `bin/orbit-session-archive-filesystem.php` provides the narrow checked-write,
  atomic active-loop/index write, recursive cleanup, and injected-rename swap
  seam. The command constructs the semantically final archive in a unique
  sibling temp, normalizes fallback manifest `archive_dir`, captures the final
  path's `lstat` identity before construction, and revalidates it immediately
  before swap. An unexpected final is untouched while only the temp is cleaned;
  activation failure without a prior final retains the complete temp without
  claiming rollback, and failed activation plus failed rollback retains complete
  temp and backup. Active-loop, index, and backup-cleanup failures report
  phase-correct recovery, and the old backup is removed only after active-loop
  and index bookkeeping succeeds.
- Archive roots, explicit destinations, and source `.orbit` roots reject
  symlinks, including source-root dot/dot-dot aliases found by lexical endpoint
  normalization before canonicalization; the original spelling still owns
  actual `realpath` resolution. Explicit destinations are canonical direct
  children of their owned archive root. Copying never follows root or nested
  file/directory symlinks, omits top-level `release-candidates`, and reports only
  copied source entries. Direct provider `.tmp-*` and `.backup-*` siblings are
  warned and excluded without deletion, while unrelated backup-shaped evidence
  remains byte-exact.

## Verification

```bash
bin/orbit-gateway-pest --compact --filter=AgentSessionArchive
bin/orbit-gateway-pest --compact --filter=SessionArchive
bin/orbit-gateway-pest --compact --filter=FeatureFinalizationGate
bin/orbit-agent-session-capture 801 --orbit-dir=.orbit --cwd=/Users/nckrtl/orbit/.worktrees/lane-close-agent-session-capture --slug=lane-close-capture-worker-801
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='(inherited marker|wrong cwd)'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='duplicate markers'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter=incarnation
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='stage 2 exact identity'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='stage 3 staging replacement'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php tests/Feature/E2ESupport/SessionArchiveTest.php --filter='review corrections|session archive excludes|session archive does not treat'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionArchiveTest.php
php -l bin/orbit-agent-session-capture
php -l bin/orbit-session-archive
php -l bin/orbit-session-archive-filesystem.php
bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/AgentSessionArchiveTest.php
bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/SessionArchiveTest.php
git diff --check
bin/orbit-harness-signal-index --write
bin/orbit-harness-signal-index --check
```

The live capture command staged a real Solo-spawned Grok worker session with
`status: ok` under `.orbit/agent-sessions/grok/lane-close-capture-worker-801/`.
The 2026-07-10 recurrence verification passed with 2 focused disambiguation
tests / 9 assertions, 1 duplicate-marker safety test / 3 assertions, and the
incarnation-floor filter at 7 tests / 74 assertions. The provider-floor
hardening additionally covers Claude and Grok rejection before staging
mutation. The exact-identity/ownership filter passed at 11 tests / 66 assertions,
and atomic staging replacement passed at 13 tests / 116 assertions, including
the private procedural rollback matrix accepted by Claude 943. The full owning
file passed at 47 tests / 545 assertions; the related `SessionArchive` and
`FeatureFinalizationGate` filters passed at 56 / 607 and 47 / 111 respectively.
PHP syntax and the gateway-test-only Mago format check passed. The new `main()`
boundary is structurally indented and the replacement transaction follows
project style; unrelated legacy statements inside and after `main()` retain the
pre-existing baseline and are not claimed Mago-clean.

The R1 archive-integrity file passed at 50 tests / 328 assertions. Both archive
bin files passed PHP syntax, the focused app-relative Mago check passed, and the
harness signal index was regenerated and checked. The correction after the
first focused run canonicalized the explicit destination's parent for macOS
`/var` versus `/private/var` containment while preserving the requested
destination spelling. The exact-tree review then routed index replacement
through the checked atomic helper and made generated-destination symlink
rejection explicit. The spec-review correction rejected terminal-dot source
aliases before archive-root construction and moved staged precedence validation
onto the copied, final-semantic archive temp. The security-review correction
closed nested-parent source aliases and manifestless capture publication,
revalidated destination identity before swap, retained both recovery trees when
rollback failed, and split active-loop, index, and backup-cleanup guidance by
the state that each phase had already committed.
The path-alias quality correction keeps generated final, summary, and manifest
paths on the normalized caller/default root spelling while the canonical root
continues to own transaction construction, scanning, security, and index work;
the isolated wrapper passed at 1 test / 7 assertions and the full owning file at
63 tests / 842 assertions.

The R2 review-correction matrix passed at 17 tests / 99 assertions, the combined
R2 provider/capture matrix passed at 34 tests / 515 assertions, and the full
`AgentSessionArchiveTest.php` file passed at 94 tests / 1,342 assertions. The
second-review focused matrix passed at 4 tests / 75 assertions after a RED of 4
tests / 4 failures / 15 assertions (1,872 bytes, SHA-256
`ee207f5cb39df8ee339c4d57485afabeb4733b29e73b6bc237ae25bfd61ba95f`). The
owned scripts and test passed PHP syntax, the owned test file passed Mago format
check, and the signal index write/check plus `git diff --check` completed after
the signal update.

The shared lane-B hook correction RED was 3 tests / 2 passed / 1 failed / 5
assertions (749 bytes, SHA-256
`d66dbaacbc58e1d1ed1e062773fb96c850c2a6fb81944737dc19580db6a2b9dc`). Its
backup filter passed at 3 tests / 6 assertions, full
`FeatureFinalizationGateTest.php` at 50 tests / 117 assertions, and hook syntax,
Mago, and diff checks passed. The predicate is `backup-.+`, so an empty backup
suffix remains visible.

The high-model correction red is retained at
`.orbit/sessions/2026-07-10-105744-capture-evidence-integrity-hardening/evidence/capture-review-corrections-red.txt`. It covers invalid
providers, symlinked roots, construction write/copy failures, native false-write
checking and native success, delete-site containment, actionable ambiguity and
no-owned diagnostics, include idempotence beside a generic `main`, and foreign
temp archive hygiene before production changes.

## Reappearance Check

If a future feature loop names Solo worker/reviewer/analyzer lanes but the
session archive has zero healthy captures, inspect whether lane-close capture
was skipped, whether the provider is unsupported and needs a waiver, or whether
the exact marker parser missed a runtime-specific prompt shape. Tighten the
capture helper and add a provider fixture before weakening finalization.

For duplicate Codex markers, also inspect whether provider context, exact
normalized cwd, and standalone primary Solo identity leave exactly one full
owner or one allowed partial-only legacy candidate. Numeric marker comparison
must remain exact, and a mid-sentence child mention must never establish primary
ownership. Never use session-meta timestamp, file order, mtime, or newest-file
selection to rescue or tie-break the match; session-meta timestamp remains
manifest corroboration only, while the caller floor validates non-session-meta
activity only after one candidate remains.

For repeated slugs, inspect the final directory as one coherent capture and
check for lingering sibling temp or backup directories. Never restore direct
in-place writes or delete the previous final before a complete sibling capture
is ready; replacement failure must preserve or roll back the prior coherent
capture and report the exact involved paths.

For construction or containment failures, inspect the canonical
agent-session/provider roots, the direct-child temp cleanup result, and the
bounded matched/owned diagnostics. Never derive recursive-delete authority from
the deletion target itself, accept a symlinked capture root, ignore a false
write/copy result, or archive a foreign `.tmp-*` sibling as completed evidence.

For outer session-archive failures, inspect the hidden sibling temp/backup paths,
the final top-level agent-session manifest `archive_dir`, and the printed
recovery paths. Never refresh the final in place, follow source symlinks, accept
unknown staged statuses or incomplete `ok` artifacts, write the active loop in
place, or remove the previous backup before checked index bookkeeping succeeds.

For a deliberate Codex process restart, prefer a fresh process id. If the id is
reused, record the restart time and pass the caller-attested floor; a stale
result requires an explicit lane-close waiver rather than capture acceptance.
Restarted Claude or Grok lanes require a fresh Solo process id or an explicit
capture waiver because incarnation floors are unsupported for those providers.

## Curation Notes

Keep separate from required-verification finalization gaps. This record is
about preserving the underlying agent-session evidence, not whether generic
verification rows were filled.
