---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, or scoped implementation handoff after the relevant documentation and product contract are aligned.
---

# Implementing Features

## Overview

Implement a scoped Orbit change after documentation is aligned. Keep docs,
tests, code, and old-repo evidence consistent.

## Preconditions

- The request has a clear product contract or handoff.
- Relevant docs have been updated or confirmed current.
- Acceptance criteria and verification commands are known.
- Owned files or domains are explicit enough to avoid unrelated edits.

## Workflow

1. Read the handoff, updated product docs, relevant `docs/domains/**`, and `AGENTS.md`.
2. Read relevant `../orbit-old-may/**` evidence for behavior that existed before the rebuild.
3. Confirm owned files or domains and existing dirty work before editing.
4. Write or update the narrowest useful Pest test first when behavior changes.
5. Implement the smallest working vertical slice.
6. Run focused verification.
7. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

8. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

## Implementation Rules

- Prefer existing Orbit and Laravel patterns.
- Treat current docs as product authority.
- Treat `../orbit-old-may` as evidence, not automatic authority.
- Keep docs, tests, and code aligned.
- Retire tests only when current docs reject the behavior or replacement coverage exists.
- Stop for direction if the docs and requested behavior conflict.
- Stay inside owned scope and leave unrelated dirty files untouched.
- Use standing live nodes only for explicitly allowed read-only or idempotent checks.

## Report Shape

```markdown
## Implementation Report

Changed files:
- <path>: <why>

Tests:
- <test added or changed>

Verification:
- `composer quality-check`: <result>

Blockers:
- <blocker or none>

Risks:
- <risk or none>
```
