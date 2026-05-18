---
name: handling-feature-requests
description: Use when receiving, refining, triaging, or preparing an Orbit feature request, product change, command behavior change, scope question, or implementation handoff that needs documentation and implementation delegated through Solo.
---

# Handling Feature Requests

## Overview

Turn a feature request into a scoped Orbit product contract, then delegate the
documentation and implementation work through Solo MCP.

## Workflow

1. Restate the requested outcome in concrete Orbit terms.
2. Identify the affected product surface: command, service, API, docs-only behavior, orchestration flow, E2E lane, or project tooling.
3. Read current product authority before proposing implementation:
   - `AGENTS.md`
   - `docs/mission.md`
   - `docs/architecture.md`
   - `docs/tech-stack.md`
   - `docs/concepts.md`
   - relevant `docs/commands/**`
   - relevant `docs/superpowers/**`
4. Check `../orbit-old-may` for prior behavior when the request touches behavior Orbit may already have solved.
5. Flag missing or contradictory docs before implementation and record unresolved decisions explicitly.
6. Define the smallest useful vertical slice that can be documented, tested, implemented, and verified.
7. Prepare the handoff shape below.
8. Spawn the Solo documentation agent using the Documentation Agent config below and prompt it to use `updating-documentation`.
9. After documentation is aligned, spawn the Solo implementation agent using the Implementation Agent config below and prompt it to use `implementing-features`.

## Product Decisions

Use this decision order when evidence conflicts:

1. Current docs are product authority.
2. Current tests describe expected implementation behavior only when they match the docs.
3. `../orbit-old-may` is historical evidence, not automatic authority.
4. Current code explains what exists, not necessarily what should exist.

If a new decision is needed, make it explicit in the handoff:

```markdown
Decision needed: <question>
Known evidence:
- Current docs: <path and summary>
- Old Orbit: <path and summary>
- Current code/tests: <path and summary>
Recommended direction: <specific choice and why>
```

## Handoff Shape

Use this shape when preparing work for the documentation and implementation
skills:

```markdown
## Feature Request
<one-paragraph outcome>

## Product Contract
- Authority docs:
- Behavior to add/change:
- Behavior explicitly out of scope:
- Old Orbit evidence:

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

- Documentation work goes through a Solo Claude agent using the `updating-documentation` skill.
- Implementation work goes through a Solo Codex agent using the `implementing-features` skill only after product docs are aligned.
- Each spawned Solo agent must receive the handoff plus current request context.
- Track returned Solo process ids in the response or working notes so follow-up can inspect them.

## Solo Agent Configs

Before spawning, call `mcp__solo__.list_agent_tools` and resolve the current
tool id by `tool_type`. Spawn the generic Solo CLI tool, then use the spawned
CLI's interactive `/model` selector before sending the task prompt. The
selected model option includes the reasoning/effort level.

Documentation agent:

1. Resolve `tool_type: claude` (currently `agent_tool_id: 3`).
2. Call `mcp__solo__.spawn_process` with `kind: "agent"` and that tool id.
3. Send `/model` with `mcp__solo__.send_input`, wait for the interactive selector, and select `opus` with `max` effort.

Implementation agent:

1. Resolve `tool_type: codex` (currently `agent_tool_id: 4`).
2. Call `mcp__solo__.spawn_process` with `kind: "agent"` and that tool id.
3. Send `/model` with `mcp__solo__.send_input`, wait for the interactive selector, and select `gpt-5.5` with `medium` effort.

For both agents, prepend the `agent_instructions` returned by
`spawn_process` to the first real prompt.

## Solo Prompt Templates

Documentation prompt:

```markdown
<agent_instructions from Solo>

Use the Updating Documentation skill at `.agents/skills/updating-documentation/SKILL.md`.

Request:
<paste the documentation request or feature handoff>

Required context:
- `AGENTS.md`
- `docs/mission.md`
- `docs/architecture.md`
- `docs/tech-stack.md`
- `docs/concepts.md`
- relevant `docs/commands/**`
- relevant `docs/superpowers/**`
- relevant `../orbit-old-may/**` evidence

Return changed files, product decisions, unresolved questions, and verification performed.
```

Implementation prompt:

```markdown
<agent_instructions from Solo>

Use the Implementing Features skill at `.agents/skills/implementing-features/SKILL.md`.

Task:
<paste the implementation handoff>

Required context:
- `AGENTS.md`
- updated product docs named in the handoff
- relevant `docs/commands/**`
- relevant `../orbit-old-may/**` evidence
- current code and tests in owned scope

Return changed files, tests, verification, blockers, and risks.
```

## Stop Conditions

- Stop and ask for direction if current docs contradict the requested behavior.
- Stop and ask for direction if the old repo solved the behavior but the requested direction rejects it without rationale.
- Stop and ask for direction if the request requires destructive live-node work without an ephemeral E2E plan.
- Stop and narrow scope if the request mixes unrelated product surfaces.

## Common Mistakes

- Treating an implementation guess as product authority.
- Skipping `../orbit-old-may` for behavior that likely existed before the rebuild.
- Creating broad abstractions before the vertical slice proves they are needed.
- Starting implementation before the documentation Solo process has aligned the product contract.
