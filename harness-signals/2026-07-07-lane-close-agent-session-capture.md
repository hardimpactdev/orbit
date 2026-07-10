# Signal: Lane-Close Agent Session Capture

Status: guarded
First seen: 2026-07-07
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: agent-session-capture-disambiguation
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-agent-session-capture, bin/orbit-session-archive, bin/orbit-codex-pre-tool-use-hook
Guardrail change: reject non-Codex incarnation floors and invalid explicit providers before staging; discover exact integer marker identities for every provider and classify every Codex candidate by exact cwd plus standalone primary identity before cardinality; expose bounded matched/owned diagnostics; construct each capture from validated raw declarations with checked writes/copies inside a canonical direct-child temp; atomically replace the final slug directory with rollback; exclude only direct foreign temp siblings from session archives without following directory symlinks or dropping backups
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
- `bin/orbit-session-archive` warns about and excludes foreign `.tmp-*` capture
  siblings only when they are direct slug siblings under
  `.orbit/agent-sessions/<provider>/`, without deleting them. A lone temp does
  not suppress archive-time fallback, `.backup-*` evidence is copied, unrelated
  temp-shaped `.orbit/evidence/` directories remain byte-identical, and copy or
  staged discovery never follows directory symlinks.

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
php -l bin/orbit-agent-session-capture
bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/AgentSessionArchiveTest.php
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

The high-model correction red is retained at
`.orbit/evidence/capture-review-corrections-red.txt`. It covers invalid
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

For a deliberate Codex process restart, prefer a fresh process id. If the id is
reused, record the restart time and pass the caller-attested floor; a stale
result requires an explicit lane-close waiver rather than capture acceptance.
Restarted Claude or Grok lanes require a fresh Solo process id or an explicit
capture waiver because incarnation floors are unsupported for those providers.

## Curation Notes

Keep separate from required-verification finalization gaps. This record is
about preserving the underlying agent-session evidence, not whether generic
verification rows were filled.
