---
name: updating-documentation
description: Use when Orbit documentation, command contracts, product docs, architecture notes, or feature-request docs need to be created, corrected, scoped, or aligned with current product behavior.
---

# Updating Documentation

## Overview

Update Orbit documentation against the current product contract. Keep docs and
implementation handoff needs aligned before code changes.

## Workflow

1. Read the request and identify the documentation surface: architecture, concept docs, command contracts, orchestration docs, or feature handoff.
2. Read current product authority:
   - `AGENTS.md`
   - `PRODUCT_DECISIONS.md` (dated intent ledger — current direction)
   - `apps/docs/content/mission.md`
   - `apps/docs/content/architecture.md`
   - `apps/docs/content/tech-stack.md`
   - `apps/docs/content/concepts.md`
   - relevant `apps/docs/content/domains/**`

   `docs/superpowers/**` is session context (plans, specs, scratch notes), not
   product authority. Read it only for background on how a change came about,
   and never cite it to justify a contract.
3. Keep this pass focused on documentation; PHP and JavaScript implementation belongs to a separate implementation pass.
4. Keep changes scoped to the request.
5. Record open questions and unresolved decisions explicitly.
6. Intent-ledger backstop: if this pass lands a direction-changing edit to an
   authority doc, confirm a matching line exists in
   `PRODUCT_DECISIONS.md`. If not, append it (newest first,
   `- YYYY-MM-DD — <decision with topic noun>. (source: <codex:// or claude:// ref or spec path>)`). The ledger
   is the dated intent anchor the drift audit consults.
7. Run the documentation quality gate:

   ```bash
   composer docs-lint
   ```

## Documentation Rules

- Current docs are product authority.
- Do not use external historical Orbit repositories as reference material unless
  the user explicitly provides source material for the current task.
- Keep docs contract-first: behavior, inputs, outputs, failure modes, role boundaries, and tests.
- Keep documentation changes scoped to the request.
- Record open questions and unresolved decisions explicitly.

## Report Shape

```markdown
## Documentation Report

Changed files:
- <path>: <why>

Product decisions:
- <decision or none>

Open questions:
- <question or none>

Verification:
- `composer docs-lint`: <result>
```
