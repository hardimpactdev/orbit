---
name: updating-documentation
description: Use when Orbit documentation, command contracts, product docs, architecture notes, or feature-request docs need to be created, corrected, scoped, or aligned with current product behavior.
---

# Updating Documentation

## Overview

Update Orbit documentation against the current product contract. Keep docs,
legacy evidence, and implementation handoff needs aligned before code changes.

## Workflow

1. Read the request and identify the documentation surface: architecture, concept docs, command contracts, orchestration docs, or feature handoff.
2. Read current product authority:
   - `AGENTS.md`
   - `docs/ARCHITECTURE.md`
   - `docs/MISSION.md`
   - `docs/CONCEPTS.md`
   - relevant `docs/commands/**`
   - relevant `docs/superpowers/**`
3. Read relevant `../orbit-old-may/**` evidence when the behavior may already have existed.
4. Keep this pass focused on documentation; PHP and JavaScript implementation belongs to a separate implementation pass.
5. Keep changes scoped to the request.
6. Record open questions and unresolved decisions explicitly.
7. Run the documentation quality gate:

   ```bash
   composer docs-lint
   ```

## Documentation Rules

- Current docs are product authority.
- Use `../orbit-old-may` as historical evidence before changing behavior Orbit already solved.
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
