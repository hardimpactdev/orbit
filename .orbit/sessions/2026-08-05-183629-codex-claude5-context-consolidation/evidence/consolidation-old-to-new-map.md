# Slice 1 Consolidation — Old→New Contract Map

Every deleted or moved sentence maps to a surviving canonical statement.
Baseline: `main` @ `117d4735108f16fe541930d41f2ffc11b2d82ae7`. Design:
`~/shared-knowledge/projects/orbit/superpowers/2026-08-05-claude5-context-engineering.md`.

## 1. E2E prohibition (was 5 copies with drifting wording)

- Canonical (expanded, nothing softened): `HARNESS.md` Retained Incus
  Acceptance — human-only; explicit "run only when the user explicitly invokes
  the Composer command from a shell" (moved from AGENTS.md); "skills, hooks,
  release flows, and default scripts must not trigger them" (moved from
  AGENTS.md); "Agents never run, delegate, background, schedule, hook, script,
  or trigger them" (kept); "Never ask the user to run them for ordinary
  feature completion; use retained topology proof" (moved from AGENTS.md);
  artifacts readable, never authorize execution (kept).
- AGENTS.md Verification → one-sentence pointer ("lanes are human-only; agents
  never trigger them — the canonical rule ... is in `HARNESS.md`").
- AGENT_FAST_PATH.md → one-sentence pointer.
- SKILL.md Non-Negotiable Boundaries → one sentence + pointer (kept the full
  verb list "Never run, delegate, background, schedule, hook, script, or
  trigger").
- `e2e-verification-lanes` skill + its agent prompt: unchanged (self-contained
  execution surface, like `general.md`).

## 2. Runtime receipt schema (was 3 copies)

- Canonical: `HARNESS.md` PROVE — full field list (`candidate=`, `venue=`,
  `environment=`, `target=`/`command=`, `expected=`, `observed=`,
  `result=passed`, `evidence=` under `.orbit/evidence/` or
  `.orbit/quality-gates/`), plus live/dev-fixture environment rule in
  Acceptance Venues.
- Validator internals removed from prose (evidence-path
  traversal/dot-segment/symlink rules, scan-field selection, unknown-key
  rejection) → owned by `bin/orbit-feature-acceptance` /
  `bin/orbit-feature-finalization-check` teaching errors, now referenced
  explicitly. Enforcement itself is unchanged (tools untouched).
- SKILL.md PROVE → rule kept ("directly exercise the claimed final outcome",
  "cannot be recorded as `passed`", "structured runtime receipt") with the
  schema by pointer ("exactly as specified in `HARNESS.md` PROVE"; wording now
  "per `HARNESS.md` PROVE").
- `general.md` reviewer copy: unchanged (self-contained).
- Loop template (`LOOP.md.example` / seeded `loop.md` footer): unchanged.

## 3. Feedback promotion ladder (was 2 verbatim copies)

- Canonical: `HARNESS.md` Feedback And Protections — 6-step ladder, immutable
  events, in-memory redaction, waiver rules, rejected/accepted example pair,
  semantic-grader constraint, `UNKNOWN` never passes.
- SKILL.md → pointer ("Close actionable feedback via the `HARNESS.md` Feedback
  And Protections ladder before ACCEPT or LAND") plus the two agent-behavior
  rules kept inline: never solicit a waiver; record verbatim with source ref.
- Deleted skill sentence "Dogfood the concrete rejected and accepted pair
  first" → maps to HARNESS "Every promoted protection names one rejected
  example and one accepted example."
- HARNESS reference-example paragraph compressed; the concrete
  `Running -> Queued` / `bin/quality-check-progress-frame-check` pair remains
  documented in `apps/docs/content/ux/commands/README.md` (test-bound there).

## 4. LAND ordering (was 2 near-verbatim copies)

- Canonical: `HARNESS.md` LAND — full manual sequence: lint → cleanliness →
  artifacts/PASS/tips/merge-preview → validate exact merge mutation →
  `FINALIZATION: PASS` → execute separately → archive from feature worktree →
  commit archive/index → ordered cleanup (stop processes → delete Solo project
  → remove worktree → delete branch; Solo deletion before worktree removal) →
  validate each cleanup mutation the same way.
- SKILL.md LAND → coordinator command + "Manual LAND follows `HARNESS.md` LAND
  exactly" + the two invariants kept inline (validate-then-execute after
  `FINALIZATION: PASS`; commit archive/index before the ordered cleanup).
- Deleted skill sentence "Solo project deletion must complete before worktree
  removal" → HARNESS LAND step 8 (kept verbatim).
- HARNESS coordinator paragraph: tool-enforcement list (refused
  `SOLO_PROJECT_ID` match, `--confirm-stop-running`) compressed to "the tool
  refuses primary/root projects, self-cwd, and unsafe deletion flags" —
  enforcement lives in `bin/orbit-feature-land` (unchanged).
- HARNESS schema-v2/v3 receipt-compatibility paragraph compressed to "receipt
  the archive and finalization tools validate; historical and full archives
  remain valid" — version mechanics owned by the tools and their Pest suites
  (`FeatureFinalizationGateTest` schema fixtures unchanged and passing).

## 5. Blast radius (was 3 copies)

- Canonical: `HARNESS.md` PROVE — prevention hook inside the same general
  reviewer; `not-required - <reason>` vs `complete - evidence=...`; `gaps`
  cannot PASS or enter acceptance.
- SKILL.md → "Blast-radius closure and ESCALATE answers stay with that same
  general reviewer" + FIX reset of `Blast radius: pending`; the evidence-shape
  clause ("repository-wide search, inventory, or lintable check") lives only
  in HARNESS + `general.md`.
- `general.md` reviewer copy: unchanged (self-contained).

## 6. Worktree rule (4 anchors, intentionally kept per surface)

- `HARNESS.md` FRAME step 3 (canonical loop step), AGENTS.md Development Rules
  (priority over generic skills; "stop and report the blocker instead of
  silently falling back"), AGENT_FAST_PATH.md route step 2, SKILL.md boundary
  ("do not recreate the setup flow manually"). Each surface keeps its
  one-sentence anchor; no copy softened.

## 7. Accepted-tip identity

- Canonical: `HARNESS.md` Acceptance Identity — reviewed/accepted feature tip,
  accepted main tip = actual `git rev-parse main`, "If the feature tip moves,
  acceptance is invalid", main-advance re-proof path, conflict → BUILD.
  Unchanged.
- SKILL.md → "any HEAD change invalidates prior acceptance" + "If main
  advances, merge it in and return through PROVE".
- New disambiguation (design's probe-4 hazard): HARNESS PROVE now names the
  two FIX-adjacent transitions — "A same-candidate proof retry is not a
  reviewer FIX: the retry keeps Review and the reviewed tip; only a reviewer
  FIX resets them." No transition semantics changed; the two routes already
  behaved this way.

## 8. The eight high-stakes constraints (all explicit post-change)

1. E2E prohibition — HARNESS canonical + three pointers (see 1).
2. Worktree isolation via `bin/orbit-prepare-worktree`, stop-and-report —
   AGENTS Development Rules + SKILL boundary + HARNESS FRAME.
3. Never discard user changes / preserve unrelated dirty files — AGENTS
   Development Rules ("preserve unrelated dirty files, and never discard user
   changes to make a merge easier") + SKILL "Preserve unrelated user state."
4. Secret redaction before persistence — HARNESS Feedback And Protections
   ("redacted in memory before the event is appended").
5. Validate-then-execute — HARNESS LAND ("After `FINALIZATION: PASS`, execute
   that exact command separately", both merge and cleanup) + SKILL LAND.
6. Accepted-tip identity — HARNESS Acceptance Identity (see 7).
7. Human-judgment acceptance boundary — HARNESS Acceptance Venues ("needs
   explicit user acceptance before merge") + SKILL boundary.
8. Archive/index committed before cleanup, fixed order — HARNESS LAND steps
   7-8 ("Cleanup requires those archive and index bytes to be tracked and
   committed") + SKILL LAND ("commit the archive/index before the ordered
   cleanup").

Encoded as a permanent contract test:
`apps/gateway/tests/Feature/Architecture/McpConfigurationTest.php`
("keeps the eight high-stakes constraints explicit on the implementing path").

## Other mapped deletions

- AGENTS duplicate intro paragraphs → single routing sentence
  ("Route new work with `AGENT_FAST_PATH.md`; load `HARNESS.md` sections when
  the chosen lane reaches them").
- AGENTS TDD sequence ("check whether a corresponding test exists...") →
  HARNESS BUILD owns docs-tests-code alignment; AGENTS now says so.
- AGENTS search-discipline paragraph → AGENT_FAST_PATH Search Route (pointer
  kept with the `find .`/`rg -uu` prohibition inline).
- AGENTS `docs/superpowers/` line → reconciled: session artifacts live in the
  operator's shared-knowledge project folder; legacy copies under
  `docs/superpowers/`; still not product authority, still unlinted.
- SKILL FRAME transitions clause (verbatim template) → `HARNESS.md` FRAME +
  `LOOP.md.example` + `bin/orbit-loop-contract.php` teaching errors; skill
  keeps the `primitive=`/`transitions=` tokens and pointer.
- SKILL "Do not create standing observer/analyzer/capture/specialist lanes" →
  HARNESS "There are no standing specialist lanes" + Trigger-Only section;
  persona tombstone reinforces ("clean loops create no analyzer lane").
- post-feature-analyzer.md → retired in place with the HARNESS_SIGNALS-style
  historical banner; body preserved for archaeology; not referenced by any
  active file (contract-tested).

## Reviewer-FIX round (process 1453): byte-ceiling trims

New deterministic contract: combined literal bytes of HARNESS.md +
AGENT_FAST_PATH.md + implementing-features SKILL.md + the Orbit-authored
AGENTS.md section (split on the Boost marker) must be <= 33,160
(= 47,372 baseline x 70%), asserted in McpConfigurationTest. RED captured at
35,482; GREEN at 33,057 (30.2% reduction, 103-byte margin). Every trim maps
to a surviving canonical owner; no gate, command, or teaching pointer removed:

- AGENT_FAST_PATH Implementation Route steps 4-10 (LAND coordinator detail,
  authority-docs-first, reviewer/actor rules, finalization validate-then-
  execute, archive cwd/commit order, --full scope) → collapsed to steps 3-4
  pointing at the implementing skill and `HARNESS.md`; canonical detail lives
  in HARNESS LAND/PROVE/Acceptance Venues and the skill's LAND section (all
  exact commands retained in step 4).
- AGENT_FAST_PATH Verification Route per-surface bullets → one routing bullet;
  canonical routing in HARNESS PROVE + SKILL PROVE; E2E pointer bullet kept.
- AGENT_FAST_PATH Search Route examples/list → compressed; prohibited scan
  forms and exclusion defaults all retained.
- HARNESS reviewer-output enumeration (PASS/FIX/ESCALATE, BLAST_RADIUS,
  HUMAN_JUDGMENT strings) → owned by `.agents/review-personas/general.md`
  (self-contained, test-bound); HARNESS keeps the FIX/ESCALATE transition
  bullets and blast-radius contract.
- HARNESS FRAME lint exposition and feedback-tool output description →
  `bin/orbit-loop-contract.php` teaching errors / `LOOP.md.example` footer /
  `bin/orbit-feature-feedback` behavior; marker template and 'Omit the
  clause' rule retained in HARNESS.
- HARNESS LAND step 5 citation-validation phrase → archive tool enforcement
  ("the archive tool rejects invalid citations"); cite-files-never-
  directories rule retained.
- Pure wording compressions with zero semantic deletion (worker sentences,
  browser/macOS venue phrasing, PROVE bullet list, terminal-PASS recording
  sentence, ESCALATE bullet, trigger-only diagnostics sentence, AGENTS
  intro/worktree/docs-alignment bullets, fast-path lane rows).
- Overlong prose joins rewrapped at AGENTS.md:91 and HARNESS.md:85 (reviewer
  finding 2).
