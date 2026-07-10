# Signal: Lane-Close Agent Session Capture

Status: guarded
First seen: 2026-07-07
Last seen: 2026-07-10
Last reviewed: 2026-07-10
Source worktree: agent-session-capture-disambiguation
Source commit: none
Signal type: agent-mistake
Guardrail target: HARNESS.md, LOOP.md.example, .agents/skills/implementing-features/SKILL.md, bin/orbit-agent-session-capture, bin/orbit-session-archive, bin/orbit-codex-pre-tool-use-hook
Guardrail change: after exact-marker discovery, disambiguate duplicate Codex candidates only by provider context, exact normalized cwd, and the transcript's primary Solo identity; retain loud ambiguity when multiple candidates survive; after one candidate remains, an optional caller-attested incarnation floor validates canonical activity without selecting or ranking candidates
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
  filters by the already-selected provider context, exact normalized cwd, and
  the transcript's own primary Solo identity. It selects only one survivor,
  preserves `ambiguous_duplicate_markers` otherwise, and records both a stable
  `disambiguation_basis` and non-selecting timestamp corroboration in successful
  manifests.
- `--incarnation-started-at=<ISO8601>` is a Codex-only, caller-attested
  validate-after-selection floor. Its syntax is checked before Solo DB access or
  staging; ambiguity returns before activity validation; and a unique candidate
  must have a parseable top-level timestamp at or after the floor on a
  non-`session_meta` row. Before-floor, missing, or unparseable activity returns
  `stale_pre_restart_session` with the floor, source, rollout id, and observed
  last activity, while no-flag captures retain their prior output and manifest.

## Verification

```bash
bin/orbit-gateway-pest --compact --filter=AgentSessionArchive
bin/orbit-gateway-pest --compact --filter=SessionArchive
bin/orbit-gateway-pest --compact --filter=FeatureFinalizationGate
bin/orbit-agent-session-capture 801 --orbit-dir=.orbit --cwd=/Users/nckrtl/orbit/.worktrees/lane-close-agent-session-capture --slug=lane-close-capture-worker-801
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='(inherited marker|wrong cwd)'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter='duplicate markers'
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php --filter=incarnation
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/AgentSessionArchiveTest.php
php -l bin/orbit-agent-session-capture
bin/orbit-gateway-vendor-bin mago format --check tests/Feature/E2ESupport/AgentSessionArchiveTest.php
```

The live capture command staged a real Solo-spawned Grok worker session with
`status: ok` under `.orbit/agent-sessions/grok/lane-close-capture-worker-801/`.
The 2026-07-10 recurrence verification passed with 2 focused disambiguation
tests / 9 assertions, 1 duplicate-marker safety test / 3 assertions, and the
incarnation-floor filter at 7 tests / 74 assertions, including the non-Codex
compatibility case. The full archive file passed at 22 tests / 359 assertions;
PHP syntax and the gateway-test-only Mago format check passed. The root helper
retains its pre-existing baseline style and is not claimed Mago-clean.

## Reappearance Check

If a future feature loop names Solo worker/reviewer/analyzer lanes but the
session archive has zero healthy captures, inspect whether lane-close capture
was skipped, whether the provider is unsupported and needs a waiver, or whether
the exact marker parser missed a runtime-specific prompt shape. Tighten the
capture helper and add a provider fixture before weakening finalization.

For duplicate Codex markers, also inspect whether provider context, exact
normalized cwd, and primary Solo identity leave exactly one candidate. Never
use session-meta timestamp, file order, mtime, or newest-file selection to
rescue or tie-break the match; session-meta timestamp remains manifest
corroboration only, while the caller floor validates non-session-meta activity
only after one candidate remains.

For a deliberate process restart, prefer a fresh process id. If the id is
reused, record the restart time and pass the caller-attested floor; a stale
result requires an explicit lane-close waiver rather than capture acceptance.

## Curation Notes

Keep separate from required-verification finalization gaps. This record is
about preserving the underlying agent-session evidence, not whether generic
verification rows were filled.
