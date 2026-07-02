# Lane B — Markdown Consolidation Fixes

CHECKOUT_PROOF: /Users/nckrtl/orbit/.worktrees/loop-hardening-followups | loop-hardening-followups | clean at lane start

## Changes

1. `.agents/review-personas/docs-librarian.md`: merged the two duplicate `## Default Agent` headings into one section that keeps both halves (Solo Role Matrix spawn, Codex-through-Solo via `list_agent_tools` -> `spawn_agent` with stop-and-report fallback, reviewer inspects/captures/reports without implementing or approving merge). The "Use this reviewer for..." and "focused reviewer persona" paragraphs now appear exactly once, after the merged section.
2. `LOOP.md.example`: replaced both archive-naming restatements (top preamble and Final Distillation preamble) with the pointer "`bin/orbit-session-archive` generates and enforces the archive directory name; run it instead of hand-writing timestamps, and see HARNESS.md Worktree-Local State for the naming contract." Preserved: default archive home (primary checkout `.orbit/sessions/<timestamp-feature-slug>/`), do-not-leave-doomed-worktree-as-only-copy, copy-everything-except-`.orbit/sessions/`, archive-before-rewriting-loop.md. Appendix compact-variant section untouched; no mechanical label lines changed.
3. `harness-signals/README.md`: (a) replaced "archive helper tooling and eval wiring are later slices" with the current state — tooling exists (`bin/orbit-session-archive`, `bin/orbit-agent-session-archive`) and session archives are a preferred eval trace source (`.agents/skills/_orbit-eval-references/session-mining.md`, path verified to exist); (b) replaced the naming-prose restatement in Relationship To Guardrails with the same pointer, preserving copy-everything-except-sessions semantics.

## Test Pin Reconciliation

`apps/gateway/tests/Feature/E2ESupport/AgentSessionArchiveTest.php` — test "documents session archive directory names as local date time and feature slug" previously required the full naming prose (`YYYY-MM-DD-HHMMSS-<feature-slug>` + the "Do not use compact timestamps..." sentence) verbatim in all five contract paths. That per-file loop asserts wording restatement, not behavior (the archive-name regex assertions are untouched). Reconciled: HARNESS.md must still state the full naming contract verbatim; every other contract path must either restate it or contain the pointer "`bin/orbit-session-archive` generates and enforces the archive directory name". Skill files that still restate the full prose continue to pass unchanged.

## Verification

- `bin/orbit-gateway-pest --compact --filter="FeatureFinalizationGate|SessionArchive|McpConfiguration|QualityGateArtifacts"`: failed 1/104 before reconciliation (AgentSessionArchiveTest.php:329, LOOP.md.example missing `YYYY-MM-DD-HHMMSS-<feature-slug>`); passed 104/104 (1027 assertions) after.
- `composer docs-lint`: passed, 0 errors; harness signal index up to date. 54 pre-existing `bullet_complexity` warnings in `docs/domains/24_solo/**` are unrelated.
- HARNESS.md `## Worktree-Local State` (line 53) confirmed as the canonical naming contract (lines 91-93).

## Notes

- `apps/gateway/tests/Feature/E2ESupport/SessionArchiveTest.php` appears modified in `git status` but was changed by another lane in this shared worktree, not Lane B.
