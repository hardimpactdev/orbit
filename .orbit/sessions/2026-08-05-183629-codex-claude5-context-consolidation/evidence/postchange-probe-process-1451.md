# Post-Change Probe — Fresh Fable Process 1451

- Source: Solo project 76, process 1451 (`fable-context-postchange-probe`),
  fresh `claude --dangerously-skip-permissions` in this worktree — the same
  instrument as baseline probe 1448. Read via Solo raw output by implementer
  process 1447. Probe was read-only; nothing persisted or edited by it.
- Probed state: slice-1 GREEN candidate files (HARNESS.md, AGENTS.md,
  AGENT_FAST_PATH.md, implementing-features SKILL.md, tombstoned analyzer
  persona) before the fast-path Start-here correction noted below.
- Probe prompt: identical four scenarios as baseline (docs typo, CLI flag,
  run E2E, reviewer FIX) with the same required semantics, plus an explicit
  self-comparison against
  `.orbit/evidence/red-baseline-probe-process-1448.md`.

## Verdict (verbatim)

"Verdict: IMPROVED — All four required semantics hold; the baseline's one
FAIL and all three named frictions/hazards are resolved. No deviations that
change route, venue, safety boundary, or state transition."

## Comparison table (as reported)

| Desired behavior | Baseline (1448) | Post-change (1451) |
| --- | --- | --- |
| Docs typo without full HARNESS ingestion | FAIL (forced ~985-line ingestion, no proportionality valve) | PASS — fast-path valve + "load sections when reached" + skill's "open only the current state's section" |
| CLI -> retained-incus + command-designer | PASS w/ hazard (venue omitted from fast path; --json two hops deep) | PASS, hazard removed — venue named in row 2; --json one hop via reference map |
| E2E refusal | PASS w/ cost (5 drifting copies; key clause in one) | PASS, consolidated — one canonical copy holding the clause + labeled pointers |
| Reviewer FIX resets tip/blast-radius, return BUILD | PASS w/ ambiguity (FIX vs proof-retry unnamed) | PASS, distinction explicit in HARNESS and the skill |

## Key scenario detail (as reported)

- E2E: refuse; boundary quoted from the canonical HARNESS copy ("runs only
  when the user explicitly invokes the Composer command from a shell"); agent
  offers retained topology proof, artifact triage, or the manual reference
  only on explicit request. Rule now one canonical copy + pointers.
- Reviewer FIX: `Review: fix`, `Reviewed feature tip: none`,
  `Blast radius: pending`, return to BUILD; the overloaded-"FIX" trap is
  "closed by name" — same-candidate proof retry preserves Review/tip, only a
  reviewer FIX resets them.

## Residual minor items reported (non-blocking)

1. Fast-path row 1 still listed `HARNESS.md` in "Start here" — RESOLVED in
   this slice immediately after the probe: the cell now reads
   "`AGENTS.md`, `.agents/skills/implementing-features/SKILL.md` (loads
   `HARNESS.md` sections per state)". Focused contract tests re-run GREEN
   after the change.
2. Skill ACCEPT names diff-routed `composer quality-check` for `automated`
   surfaces including docs — pre-existing wording carried over from the old
   skill (the "diff-routed" qualifier defers to the HARNESS PROVE routing
   where docs-only means `composer docs-lint`). Left unchanged; candidate for
   later polish.
3. `updating-documentation` skill still carries its unconditional
   authority-stack preload — OUT OF SCOPE for this approved slice; recorded
   as residual follow-up only.
