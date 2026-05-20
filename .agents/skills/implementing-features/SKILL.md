---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, or scoped implementation handoff after the relevant documentation and product contract are aligned.
---

# Implementing Features

## Overview

Implement a scoped Orbit change after documentation is aligned. Keep docs,
tests, and code consistent.

## Preconditions

- The request has a clear product contract or handoff.
- Relevant docs have been updated or confirmed current.
- Acceptance criteria and verification commands are known.
- Owned files or domains are explicit enough to avoid unrelated edits.
- A dedicated worktree exists for the change (see Worktree Setup below).

## Worktree Setup

Implementation happens in an isolated worktree, never directly on `main` or a
shared branch. Use the `using-git-worktrees` skill to create one.

Orbit conventions:

- Worktrees live in `.worktrees/<branch-name>/` at the repo root. This directory
  is already gitignored.
- Name the branch and worktree directory after the feature or fix in kebab-case
  (e.g. `.worktrees/app-node-security-foundations`).
- After creation, run `composer install` and confirm the baseline is clean with
  `php artisan test --compact` before writing any new code.
- Do all editing, test runs, and verification from inside the worktree path.

## Workflow

1. Set up the worktree per the section above.
2. Read the handoff, updated product docs, relevant `docs/domains/**`, and `AGENTS.md`.
3. Confirm owned files or domains and existing dirty work before editing.
4. Follow TDD (see Test-Driven Development below): write or update failing Pest
   tests first, then implement.
5. Implement the smallest working vertical slice to make the tests pass.
6. Run focused verification.
7. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

8. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

## Test-Driven Development

Orbit is a TDD project. Every behavior change ships with Pest coverage that
fails before the implementation lands and passes after. No exceptions for
"trivial" changes — if behavior is worth changing, it is worth a test.

Two layers are required to prove a feature works:

- **Pest unit/feature tests** in `tests/Unit/` or `tests/Feature/` that pin the
  internal contract: command output shape, JSON schema, validation, branching
  logic, error paths. These run under `php artisan test --compact`.
- **Pest end-to-end tests** that exercise the feature against a real Orbit
  topology in `tests/E2E/`. The Docker-backed feature aggregate runs via
  `composer test:e2e` (or `composer test:e2e:docker` / `composer test:e2e:incus`
  for a single lane). Behavior that depends on real provisioning, WireGuard,
  systemd, or host mutation belongs in the ephemeral VM lane via
  `composer test:e2e:provision`. There is no standing live-node lane — see
  `TESTING.md` for the full lane map.

Workflow per change:

1. Write the failing Pest test(s) first — unit/feature for the contract, E2E
   for the integrated behavior.
2. Run them and confirm they fail for the expected reason.
3. Implement the smallest slice that turns them green.
4. Re-run both layers before reporting completion.

A feature is not done until both layers pass.

## Implementation Rules

- Prefer existing Orbit and Laravel patterns.
- Treat current docs as product authority.
- Keep docs, tests, and code aligned.
- Retire tests only when current docs reject the behavior or replacement coverage exists.
- Stop for direction if the docs and requested behavior conflict.
- Stay inside owned scope and leave unrelated dirty files untouched.

## Report Shape

```markdown
## Implementation Report

Worktree:
- `.worktrees/<branch-name>` on branch `<branch>`

Changed files:
- <path>: <why>

Tests:
- Pest unit/feature: <test added or changed>
- Pest E2E: <test added or changed>

Verification:
- `php artisan test --compact`: <result>
- `composer test:e2e` (or the appropriate ephemeral lane): <result>
- `composer quality-check`: <result>

Blockers:
- <blocker or none>

Risks:
- <risk or none>
```
