---
name: handling-feature-requests
description: Use when receiving, refining, triaging, or preparing an Orbit feature request, product change, command behavior change, scope question, or Solo todo handoff.
---

# Handling Feature Requests

## Overview

Capture feature intent as scoped Solo todo work. This skill is intake-only: it
does not update files, documentation, tests, or implementation code.

Before refining intent, use the `brainstorming` superpower for product shaping
or behavior changes. Once intent is clear, use this skill to write the outcome,
contract, scope, and verification expectations into Solo todos. Actual work,
including documentation updates, happens through the `implementing-features`
skill.

## Workflow

1. Restate the requested outcome in concrete Orbit terms.
2. Identify the affected product surface: command, service, API, docs-only behavior, orchestration flow, E2E lane, or project tooling.
3. Use the `brainstorming` superpower when intent, behavior, scope, or product
   direction still needs refinement.
4. Read current product authority before writing Solo todos:
   - `AGENTS.md`
   - `apps/docs/content/product-decisions.md` (dated intent ledger — read current direction before proposing a change, so a new decision does not contradict or duplicate an existing one)
   - `apps/docs/content/mission.md`
   - `apps/docs/content/architecture.md`
   - `apps/docs/content/tech-stack.md`
   - `apps/docs/content/concepts.md`
   - relevant `apps/docs/content/domains/**`
   - relevant `docs/superpowers/**`
5. Flag missing or contradictory docs in the Solo todo body; do not fix them in
   this skill.
6. Define the smallest useful vertical slice that can be documented, tested,
   implemented, and verified.
7. Create or update Solo todo(s) with the handoff shape below.
8. Leave implementation, including documentation edits and product-decision
   ledger entries, to the agent using `implementing-features`.

## Product Decisions

Use this decision order when evidence conflicts:

1. Current docs are product authority.
2. Current tests describe expected implementation behavior only when they match the docs.
3. Current code explains what exists, not necessarily what should exist.

If a new decision is needed, make it explicit in the Solo todo:

```markdown
Decision needed: <question>
Known evidence:
- Current docs: <path and summary>
- Current code/tests: <path and summary>
Recommended direction: <specific choice and why>
```

## Handoff Shape

Use this shape when creating or updating Solo todos:

```markdown
## Feature Request
<one-paragraph outcome>

## Product Contract
- Authority docs:
- Behavior to add/change:
- Behavior explicitly out of scope:
- Existing code/test evidence:

## Acceptance Criteria
- <observable behavior>
- <observable behavior>

## Implementation Scope
- Owned files/domains:
- Foreign scope to avoid:
- Data or migration impact:

## Test Plan
- New/changed tests:
- Focused verification command:
- Broader verification command, if needed:
- E2E/live-node lane:

## Risks
- <risk or none>
```

## Solo Delegation Rules

- This skill may create or update Solo todos, comments, blockers, and tags.
- This skill must not edit repository files or spawn implementation agents.
- Documentation updates, product-decision ledger entries, tests, and code
  changes all belong to a later implementation pass using
  `implementing-features`.
- The Solo todo body is the handoff. Include enough context for a later
  implementation agent to start from the todo without reconstructing intent
  from the conversation.

## Implementation Handoff

```markdown
Use `.agents/skills/implementing-features/SKILL.md` when executing this todo.

Task:
<paste the implementation handoff>

Required context:
- `AGENTS.md`
- `apps/docs/content/product-decisions.md` (dated intent ledger — current direction)
- relevant product docs under `apps/docs/content/**`
- relevant session context under `docs/superpowers/**`
- current code and tests in owned scope

Return changed files, tests, verification, blockers, and risks.
```

## Stop Conditions

- Stop and ask for direction if current docs contradict the requested behavior.
- Stop and ask for direction if the request requires destructive live-node work without an ephemeral E2E plan.
- Stop and narrow scope if the request mixes unrelated product surfaces.
- Stop before editing repository files; switch to `implementing-features` for
  actual changes.
- Stop before spawning implementation agents; leave execution to the Solo todo
  workflow.

## Common Mistakes

- Treating an implementation guess as product authority.
- Editing docs, code, tests, or skills while using this intake skill.
- Spawning an implementation agent instead of capturing the work in Solo todos.
- Creating broad abstractions before the vertical slice proves they are needed.
- Splitting documentation into a separate implementation track instead of
  capturing the doc gap in the Solo todo for `implementing-features`.
