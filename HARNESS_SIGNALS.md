# Orbit Harness Signals

This file maps repo-development signals to durable guardrail targets. It is a
routing table, not a changelog. Do not append every session event here; update
it only when Orbit learns a new class of signal or changes where that signal
should become guidance or enforcement.

## Signal Map

| Signal Source | Capture | Triage Question | Default Guardrail Target | Verification |
|---------------|---------|-----------------|--------------------------|--------------|
| Human review comment | Exact comment and affected files | Is this likely to recur across agents or slices? | `.agents/skills/**`, `HARNESS.md`, or tests | Re-read the changed route and run the narrowest relevant check. |
| Failed Pest, Mago, Rector, or docs-lint run | Command and failure excerpt | Is the failure message enough for the next agent to self-correct? | Test assertion message, skill runbook, or static rule | Re-run the failing command. |
| E2E or retained-topology failure | Topology id, role, command, and observed output | Is this a product bug, verification-lane gap, or missing setup guidance? | `apps/docs/content/testing/**`, `e2e-verification-lanes`, product docs, or E2E coverage | Re-run the focused lane or retained check. |
| Live-node bug report | Node, version, command, and before/after evidence | Is the repo correct but the installed/runtime surface stale? | Runtime-user verification rule, release skill, product docs, or regression test | Verify against the actual runtime user or live node. |
| Docs drift audit finding | Finding id, conflicting files, and authority chain | Is authority unclear, is one downstream doc stale, or is reviewer scope too broad? | `.agents/review-personas/docs-librarian.md`, product docs, product-decision ledger, banned term, or `auditing-docs-drift` | `composer docs-lint` and focused drift re-check. |
| Agent implementation mistake | Prompt, changed files, and correction needed | Did the agent miss routing, ownership, or verification context? | `AGENTS.md`, `HARNESS.md`, `LOOP.md`, or relevant skill | Run through the discovery path and confirm the missing route exists. |
| Worktree/bootstrap failure | Worktree path, command, and setup output | Is the setup flow broken or did an agent bypass it? | `bin/orbit-prepare-worktree`, `AGENTS.md`, or `implementing-features` | Prepare a fresh worktree or re-run the failed setup step. |
| Command-contract drift | Command, docs path, test path, and observed output | Is the public contract wrong, under-tested, missing reviewer coverage, or just the implementation stale? | `command-designer`, `.agents/review-personas/cli-command.md`, command docs, Pest coverage, or product-decision ledger | Focused Pest for the command plus docs-lint when docs changed. |
| Security or secret-handling concern | Affected surface and exposure path | Is this a concrete leak, missing review rule, or product policy change? | `spatie-security`, tests/static checks, product docs, or banned term | Focused security regression and relevant quality gate. |

## Guardrail Target Selection

- Treat raw `.orbit` artifacts, persisted `.orbit/sessions/` archives, session
  transcripts, scratchpads, and reviewer comments as candidate signals only.
  They do not become durable guardrails until the post-feature analyzer reviews the completed loop and the orchestrator adjudicates that recommendation against session context.
  Session archives are trace evidence for post-feature analysis and future eval
  construction; `harness-signals/` remains curated distilled learning and
  guardrail history, not raw session storage.
- Promote a candidate only when all of these are true:
  - A concrete mistake, late catch, expensive diagnosis, or high-risk near miss
    happened.
  - The same class of mistake is likely to recur across future features,
    worktrees, or agents.
  - Existing harness docs, skills, personas, tests, failure messages, and signal
    records did not already cover it clearly enough.
  - The proposed guardrail would likely have prevented the exact mistake or
    caught it earlier.
  - The smallest useful guardrail target is clear.
  - A narrow verification can prove the new target is reachable.
- Eliminate non-signals before considering promotion:
  - one-off handoffs or machine moves
  - lessons already covered by current code, tests, docs, skills, personas,
    signal records, static checks, or failure messages
  - reviewer findings fixed before merge when reviewer guidance already caught
    the issue
  - stale historical `.orbit/` artifacts that do not reveal a current gap
  - ordinary feature work that belongs in the active diff, not the harness
- Reject or mark `already-covered` when any promotion condition is missing.
  A final review that creates no durable guardrail is a valid loop result.
- Search `harness-signals/` before treating a durable signal as new. If a
  related signal is already `guarded` and the issue reappears, mark or treat the
  signal as `recurring` and evaluate whether the guardrail target needs to be
  tightened.
- Also check whether a later slice already absorbed the lesson in current code,
  tests, product or harness docs, skills, personas, static checks, or command
  failure messages. If it did, classify the candidate as `already-covered` and
  name the durable coverage instead of creating a duplicate record.
- Fix only the current diff when the signal is local and unlikely to recur.
- Create or update a `harness-signals/` record when the signal should remain
  searchable across worktrees. Do not copy raw session archives from
  `.orbit/sessions/` into `harness-signals/`; distill the lesson into a curated
  record instead.
- Update a skill when the signal is about workflow, command usage, ownership,
  verification, or environment setup.
- Update a reviewer persona when the signal is about post-test review criteria,
  evidence interpretation, or repeated mistakes that a focused review checklist
  should catch.
- Update the Solo role matrix or implementation skill when the signal is about
  which agent should own orchestration, documentation, implementation,
  verification, or review.
- Update product docs when the signal changes or clarifies user-facing Orbit
  behavior.
- Update tests or static checks when a future failure can be detected
  mechanically.
- Update root harness docs when the signal changes repo-wide discovery,
  feedback-loop routing, or the boundary between harness and product docs.
- Add a product-decision entry only for direction changes or reversals, not
  routine clarification.

## Minimum Evidence

Every distilled signal needs evidence that the chosen guardrail target works:

- Signal-ledger target: a searchable record under `harness-signals/` links the
  source, recurrence status, guardrail target, and verification.
- Documentation target: `git diff --check` and, when product docs changed,
  `composer docs-lint`.
- Skill target: read the skill from the root discovery path and confirm the new
  trigger or step is reachable.
- Test/static target: show the focused command failing before the fix when
  practical, then passing after.
- E2E/live target: record the exact lane, topology, node, command, and observed
  result.

Required E2E that cannot be completed is first a `blocked` feature-loop
outcome, not a candidate signal. Promote it only when the cause reveals a
recurring verification-lane, setup, or process gap.

## Current Manual Cadence

Run the loop during implementation handoff and final reporting. Periodic
distillation over CI, Solo todos, and review comments is intentionally deferred
until a later automation slice proves the manual guardrail-target map is stable.
Broad history or worktree mining is separate from the regular feature loop and
should run only when explicitly requested.
