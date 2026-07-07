---
name: loop-review
description: Use when a human explicitly requests a batch review of Orbit feature-loop archives, session indexes, or recurring repo-development loop signals across completed sessions.
---

# Loop Review

## Role

Run a budgeted, human-invoked batch review over completed Orbit feature loops.
The review finds recurrence evidence that a single post-feature analyzer cannot
see, then writes one findings scratchpad for human adjudication.

This skill is read-only for the repository. It never edits guardrails, skills,
signal records, product docs, tests, or tooling. It feeds the existing promotion
gate in `HARNESS_SIGNALS.md`; it is not a second promotion gate.

## Budgets

- Cadence: run after about 10 new session archives or monthly, whichever comes
  first, when a human explicitly asks for it.
- Archive reads: open at most 15 archive directories in one pass. Use
  `.orbit/sessions/index.json` to cover the rest.
- Turn budget: one setup turn, one analysis turn, and one findings turn. Stop
  instead of expanding into an open-ended audit.
- Token budget: keep the pass under roughly 80k input tokens by reading index
  facets first and opening only flagged archive files.
- Hard stop: if `bin/orbit-session-index --check` fails, or the index is
  missing when archives exist, stop and report the stale-index blocker. Do not
  read around a stale index.

## Procedure

1. **Find the previous review window** - Locate the latest findings scratchpad
   tagged `loop-review`. If none exists, start from the earliest indexed archive
   or the human-specified window. Record the window start and end.
2. **Read the index first** - Run `bin/orbit-session-index --check`, then read
   `.orbit/sessions/index.json`. Use facets such as outcome, blocker presence,
   analyzer verdict, candidate classifications, capture status, and token usage
   to choose which archives need deeper inspection.
3. **Open only flagged archives** - Inspect the selected archive `loop.md`,
   `agent-sessions/*/manifest.json`, quality-gate artifacts, and evidence files.
   Stay under the archive-read budget.
4. **Group recurring failure classes** - Group repeated blockers, analyzer
   criticisms, merge-gate failures, worker friction, verification misses, and
   first-diff-budget misses. Include counts and archive pointers.
5. **Check guardrail coverage** - Search `harness-signals/index.json` and then
   matching `harness-signals/` records. If an existing guarded record did not
   prevent recurrence, produce a `tighten` candidate. If no record covers a
   recurring class, produce a `promote` candidate. If evidence is weak, produce
   `reject` or `defer`.
6. **Compute index metrics** - Report sessions per period, outcome mix, blocked
   rate, analyzer-verdict mix, capture-status mix, and token totals where the
   index has usage data.
7. **Spot-check distillation** - Audit 1-2 accepted durable updates or archived
   distillations from the window against shipped behavior. Flag inverted or
   stale distillations as candidate findings; do not fix them directly.
8. **Check observer usage** - Record whether a `loop-observer` lane was requested
   during the window, which mode was used, and whether its output affected final
   distillation. This informs the observer keep/fold decision.

## Findings Scratchpad

Write one Solo scratchpad tagged `loop-review`. Use this shape:

```markdown
# Loop Review Findings - <date>

## Coverage Window
- Previous review: <scratchpad/id or none>
- Archives covered: <start> through <end>
- Archives opened: <count/list>

## Metrics
- Sessions per period:
- Outcome mix:
- Blocked rate:
- Analyzer verdict mix:
- Capture-status mix:
- Token totals:

## Recurring Classes
- <class>: <count>, <archive pointers>, <evidence summary>

## Distillation Spot-Checks
- <guardrail/distillation>: <kept | stale | inverted | unclear> - <evidence>

## Observer Usage
- Requested: <yes/no>
- Mode/effect:

## Candidates For Human Adjudication
- <promote | tighten | reject | defer>: <target>, <reason>, <evidence>

## Open Questions
- <question or none>
```

End the scratchpad with a clear reminder: the human adjudicates candidates
through `HARNESS_SIGNALS.md`, and a follow-up implementation loop owns any
guardrail edits.

## Do Not Build

- Do not create a scheduled, hook-driven, CI, nightly, or unattended invocation.
- Do not create dashboards, embeddings, clustering, or A/B infrastructure.
- Do not edit `harness-signals/`, `HARNESS.md`, `.agents/skills/`, product docs,
  or tests during the review.
- Do not treat one weak archive as recurrence. Mark it `defer` with the needed
  evidence.
