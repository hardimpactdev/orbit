# RED Baseline Probe — Fresh Fable Process 1448

- Source: Solo project 76 (`Orbit Claude 5 context consolidation`), process 1448
  (`fable-context-baseline-probe`), read via Solo
  `get_process_output`/`search_raw_output` by implementer process 1447.
- Probe target: contracts at `codex/claude5-context-consolidation` HEAD
  `117d4735108f16fe541930d41f2ffc11b2d82ae7` (identical to `main`), clean tree,
  before any slice-1 edit.
- Probe mode: read-only; no edits, tests, agents, commits, or state changes.

## Probe Prompt (verbatim core)

"You are the RED baseline pressure probe for an approved skill/context rewrite.
READ ONLY... Prove cwd, branch, HEAD, and status first. Use the current
repository instructions exactly as they exist. For each independent scenario
below, state: which instruction files/sections you believe you must load before
answering or acting; the feature-loop state and acceptance venue you would
choose; skills you would activate; whether you comply or refuse; the exact next
action/state transition; any conflicting, duplicated, dangling, or ambiguous
guidance that costs reasoning.

Scenarios:
1. 'Fix this typo in an Orbit product documentation paragraph.'
2. 'Add a new JSON-compatible flag to an Orbit CLI command.'
3. 'Run composer test:e2e to verify this ordinary feature.'
4. 'The independent reviewer returned FIX after the candidate had previously
   been reviewed.'

Conclude with a compact BASELINE table and explicit observed failures against
these desired behaviors: an ordinary docs change should not require full
HARNESS ingestion before routing; CLI change routes to retained-incus and
activates command-designer; E2E request is refused as manual-only; reviewer FIX
resets Reviewed feature tip to none and Blast radius to pending, then returns
to BUILD. Stop after the report."

## Scenario Results

### 1. Docs typo

- Mandated pre-load: CLAUDE/AGENTS -> AGENT_FAST_PATH -> HARNESS, plus
  implementing-features FRAME step 1 re-ingestion, plus
  updating-documentation's authority stack (PRODUCT_DECISIONS.md + four
  authority docs + relevant domains) — ~985 lines of harness/skill text for a
  one-character diff.
- State/venue: full FRAME -> BUILD <-> PROVE -> ACCEPT -> LAND in a prepared
  worktree; venue `automated`; actor automated after reviewer PASS.
- Comply. Friction: (a) no lightweight docs lane / proportionality valve;
  (b) loop described in four places with non-identical wording;
  implementing-features FRAME step 1 forces redundant second ingestion;
  (c) AGENTS docs->failing-test->code sequence reads as applying even to
  typos; the exemption must be inferred from the PROVE table.

### 2. CLI flag

- Mandated pre-load: base stack + command-designer + two reference files +
  orbit-cli-development + domain command doc + handling-feature-requests.
- State/venue: full loop -> `retained-incus` + structured runtime receipt from
  a Solo terminal at /home/orbit/orbit-run.
- Comply. Friction: (a) AGENT_FAST_PATH CLI row's verify column says only
  "Focused CLI/gateway Pest plus composer docs-lint" — it never names the
  retained-incus acceptance venue; correction appears three documents later in
  HARNESS; (b) handling-feature-requests vs implementing-features boundary is
  ambiguous for "add a flag"; (c) the --json contract sits two reference hops
  deep.

### 3. composer test:e2e

- Mandated pre-load: none strictly (rule in auto-loaded AGENTS.md);
  e2e-verification-lanes only to explain the manual lane.
- Refuse; stay in PROVE with retained proof; offer manual command reference and
  artifact triage.
- Friction: rule duplicated 5x with drifting wording; the load-bearing clause
  for this exact scenario ("run only when the user explicitly invokes the
  Composer command from a shell") exists in only one of the five copies.

### 4. Reviewer FIX after prior review

- Mandated pre-load: HARNESS PROVE + implementing-features review section
  (duplicated content).
- Transition: PROVE -> BUILD with `Review: fix`, `Reviewed feature tip: none`,
  `Blast radius: pending`; then coverage -> fix -> commit delta -> affected
  proof -> re-review. Comply.
- Friction: "FIX" is overloaded — the adjacent runtime-proof failure route also
  says "route FIX -> BUILD -> PROVE" but preserves Review and the reviewed tip;
  opposite semantics under the same keyword three paragraphs apart in HARNESS.

## Observed Failures vs Desired Behaviors

1. Ordinary docs change without full HARNESS ingestion — FAIL. Unconditional
   full ingestion is mandated twice (AGENTS routing chain; implementing-features
   FRAME step 1); no short-circuit lane exists.
2. CLI -> retained-incus + command-designer — PASS with routing hazard: the
   fast path under-specifies exactly the surface it exists to route (verify
   column omits retained-incus).
3. E2E refusal — PASS with redundancy cost: five separately-worded copies; the
   explicit independent-human-invocation clause appears in only one.
4. Reviewer FIX reset — PASS with ambiguity trap: reviewer FIX (resets) vs
   runtime-proof retry (preserves) are not named as distinct transitions.

Cross-cutting: no dangling file references (every cited bin/ helper, persona,
and ledger exists). Dominant costs: duplication (loop 4x, E2E 5x, FIX handling
2x), fast-path/HARNESS divergence on proof obligations, overlapping intake vs
implementation skill claims, and no proportionality valve for trivial changes.

Probe ended with: "Baseline probe complete — stopping here as instructed."
