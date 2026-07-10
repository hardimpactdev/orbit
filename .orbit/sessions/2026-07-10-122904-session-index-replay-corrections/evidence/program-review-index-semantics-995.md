CHECKOUT_PROOF: /Users/nckrtl/orbit | main | 1f08ce59f9b8b4df8605dfdcd2cf15245d26303d | M .orbit/sessions/index.json; 20 untracked session archives; untracked docs/superpowers/plans/2026-07-08-instance-runtime-mounts.md

## Findings

No P0 or P1 findings. Two P2 correctness defects remain on current `main`.

### P2 — Explicit nested analyzer verdicts lose provenance and precedence

- Location: `bin/orbit-session-index:195`, `bin/orbit-session-index:205`, and `bin/orbit-session-index:530`.
- Defect: `parse_analyzer_verdict_raw()` returns any non-empty same-line `Fresh analyzer:` value before inspecting the explicit nested `Verdict:` child. When it does reach the child, it returns only the child value, so `normalize_analyzer_verdict()` cannot distinguish authoritative nested verdict grammar from same-line/free-text prose. The closed yes/no matcher then accepts only an exact whole value, leaving explicit nested `yes` heads with rationale noncanonical.
- Reproduction/evidence: `bin/orbit-session-index` reports 85 records but only 5 canonical `fresh_analyzer_verdict=yes` values. Eleven archives contain an explicit nested `Verdict: yes...` child yet remain noncanonical: `2026-07-02-104653-loop-plumbing-hardening`, `2026-07-07-104621-loop-observer-rubric-coach-modes`, `2026-07-07-122041-loop-review-skill`, `2026-07-07-125620-verify-evals-skill`, `2026-07-07-132751-loop-ceremony-simplification`, `2026-07-08-010023-loop-analyzer-on-demand`, `2026-07-10-014331-session-index-facet-normalization`, `2026-07-10-030001-cli-pest-noninteractive-baseline`, `2026-07-10-030353-cli-pest-noninteractive-baseline`, `2026-07-10-063601-agent-session-capture-incarnation-floor`, and `2026-07-10-105744-capture-evidence-integrity-hardening`. The two CLI-Pest packets also prove the precedence failure: their same-line `Fresh analyzer: passed ...` prose wins over the nested explicit `Verdict: yes` row.
- Impact: the generated index is byte-reproducible but semantically undercounts accepted analyzer verdicts, so session selection and aggregate review metrics consume the wrong facet.
- Required correction: preserve typed source provenance, make an explicit nested `Verdict:` child authoritative over generic same-line analyzer prose, and canonicalize the closed yes/no head-plus-rationale grammar only for that provenance. Keep embedded/same-line prose and values such as `Verdict: No blockers` raw.

### P2 — Nine exact no-blocker entries are indexed as active blockers

- Location: `bin/orbit-session-index:294` and `apps/gateway/tests/Feature/E2ESupport/SessionIndexTest.php:462`.
- Defect: the conservative allowlist accepts `none` and several other exact forms but excludes the historically accepted whole entries `none currently` and `no blocker currently`. The focused test currently asserts those exact values are blockers, codifying the false-positive behavior.
- Reproduction/evidence: all nine archives containing only an exact `None currently.`/`none currently` or `No blocker currently.` entry return `blockers_present=true`: `2026-06-26-171601-linked-test-lane-c`, `2026-06-26-174315-linked-test-lane-a`, `2026-06-26-175717-linked-test-lane-a`, `2026-06-26-180840-linked-test-lane-e`, `2026-06-26-191921-linked-test-lane-f`, `2026-06-26-194810-linked-test-lane-b`, `2026-06-26-213627-linked-test-catalog-drift`, `2026-07-06-093219-agent-transport-hardening`, and `2026-07-09-015306-todo-197-schedule-agent-push`. Current corpus count is therefore 28 blocker-positive records instead of 19 under the accepted grammar.
- Impact: the primary first-stop index falsely prioritizes nine completed/nonblocked sessions as blocker-bearing evidence.
- Required correction: accept only the exact normalized whole entries `none currently` and `no blocker currently`; keep punctuation continuations, qualifiers, contrast clauses, continuation lines, and mixed bullets fail-safe `true`.

## Replay-correction contract assessment

Scratchpad 276's accepted current contract closes both findings without broadening semantics: typed explicit-child provenance and precedence address the eleven analyzer promotions, while two exact whole-entry blocker literals address the nine false positives. Its updated 85-record expectation (`yes` 5 -> 16, blockers 28 -> 19), named promotion/clear sets, verbatim backticked-yes fixture, and zero-other-field-delta proof are the right safeguards against hiding unrelated movement.

That is a contract assessment only. Per scope, I did not inspect or edit the active replay-correction worktree, so its implementation is not verified here. Current `main` still contains both P2 defects and therefore remains changes-required until that bounded correction is reviewed, merged, and the authoritative index is regenerated.

## Other reviewed surfaces

- Merge integrity is sound: feature/merge trees match exactly for `88c86af15`/`ded3b388a` and `13586275a`/`f1acfee5d`.
- Raw facet fields, column-zero same-line label boundaries, closed analyzer vocabulary, conservative qualified-blocker handling, deterministic sorting, and byte-for-byte `--check` behavior otherwise match the reviewed contracts.
- The accepted-adjudication guardrail is intentionally split across root discoverability (`HARNESS.md:938`), execution handoff/reviewer enforcement (`.agents/skills/implementing-features/SKILL.md:53`, `:253`, `:270`, `:647`), and the curated recurrence record. No duplicate reviewer-persona rule or new signal was added; this is policy routing, not actionable duplication.
- The archived packets and exact-commit artifacts are internally consistent: `88c86af15` quality-check passed 43/43 subgates in 79s; `13586275a` quality-check passed 43/43 in 89s; its docs-lint artifact passed in 6s. These gates establish tree health, not semantic corpus correctness.

## Focused verification

- `bin/orbit-session-index --check` — passed before archive reads.
- `bin/orbit-session-index | cmp - .orbit/sessions/index.json` — byte-identical.
- `bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/SessionIndexTest.php` — 4 passed, 221 assertions.
- `php -l bin/orbit-session-index` — no syntax errors.
- `bin/orbit-harness-signal-index --check` — up to date.
- `git diff --check` for both merge deltas — passed.
- No `composer test:e2e*` command was run.

VERDICT: changes-required
