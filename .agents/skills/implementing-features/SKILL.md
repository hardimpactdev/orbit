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
- A dedicated worktree exists for the change (see Workspace Setup below).

## Workspace Setup

Implementation happens in an isolated worktree, never directly on `main` or a
shared branch. Use the `using-git-worktrees` skill to create one.

A new worktree starts empty — Orbit ignores `.env`, `vendor/`, and `.env.e2e`,
so the worktree must be bootstrapped before any test or quality-check command
will pass. Skip any of these and `php artisan test` fails at boot
(encrypted-cast tests need `APP_KEY`; E2E composer scripts source `.env.e2e`).

Orbit conventions:

- Worktrees live in `.worktrees/<branch-name>/` at the repo root. This directory
  is already gitignored.
- Name the branch and worktree directory after the feature or fix in kebab-case
  (e.g. `.worktrees/app-node-security-foundations`).
- Do all editing, test runs, and verification from inside the worktree path.

Run these from inside the new worktree, in order:

1. `composer install` — install dependencies.
2. `cp .env.example .env && php artisan key:generate --force --no-interaction`
   — application bootstrap. Without `APP_KEY` set, encrypted-cast Pest tests
   fail to boot.
3. `cp ../../.env.e2e .env.e2e` (only if E2E lanes will be run in this
   worktree) — copy the project-local E2E env from the primary checkout.
   `.env.e2e` is gitignored and carries sidecar / Incus host config that
   `composer test:e2e*` and `composer e2e:*` source automatically.
4. `php artisan test --compact` — baseline Pest suite must pass before any
   new code lands. If this fails on a fresh worktree, the bootstrap above is
   incomplete.
5. (Only when this change needs the E2E lane.) Run the E2E readiness checks
   below before the first prepared-topology feature lane. Provider provision
   gates are final/nightly substrate verification, not the normal feature-loop
   precondition.

## E2E Readiness And Resource Pool

The E2E Docker lane uses a shared slot pool across the configured sidecar
hosts (e.g. `ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:4,sidecar2:4`). The same
sidecars also serve every other worktree on the machine, so the pool is
shared infrastructure, not per-worktree. Two situations cause "no slots
available" surprises:

1. Another worktree is running E2E right now and has the pool leased.
2. A previous run was interrupted and orphaned containers are still on the
   sidecars consuming slots.

Before running E2E in a worktree:

1. `composer e2e:preflight` — verifies the configured Incus host is
   reachable. Required for the Incus lane; safe to skip if only Docker
   lanes are exercised.

2. Capacity probe per sidecar. Run this **first**, before any reap:

   ```bash
   for host in sidecar1 sidecar2; do
     count=$(DOCKER_HOST=ssh://$host docker ps --filter "name=orbit-e2e" --format '{{.Names}}' | wc -l)
     echo "$host: $count active orbit-e2e containers"
   done
   ```

   - 0 containers per sidecar → pool is free, proceed.
   - A few containers younger than your E2E runtime → another worktree is
     mid-run; do not reap. Either wait for it to finish or reduce this
     worktree's slot allocation (e.g.
     `ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2` with
     `ORBIT_E2E_PARALLEL_PROCESSES=4`) so the pool is not fully booked by a
     single worktree.
   - Many containers older than ~6 hours → stale leftovers; safe to reap.

3. Only reap when the probe surfaced confirmed stale containers:

   ```bash
   composer e2e:reap-docker -- --force
   ```

   The reap command's `--older-than=30m` default means in-flight runs from
   other worktrees are not touched — only orphaned containers older than
   thirty minutes (comfortable headroom over the typical ~1-2 minute lane
   and ~10-20 minute longest realistic run). Without `--force` the command
   reports without deleting. **Do not** routinely run reap as a pre-run
   hygiene step; it shares the same providers across worktrees, and the
   age default is the only thing protecting concurrent runs.

4. For the Incus lane, the same shape applies. `composer e2e:reap-incus`
   reaps stale VMs (also age-gated) and `e2e:preflight` reports
   reachability.

The slot pool is a blocking lease pool: workers that cannot lease a slot
wait, they do not fail loudly. A long hang at the start of `composer
test:e2e:docker` usually means the pool is fully booked by another
worktree, not a real failure.

## Workflow

1. Set up the workspace per the section above (worktree + bootstrap + E2E readiness if needed).
2. Read the handoff, updated product docs, relevant `docs/domains/**`, and `AGENTS.md`.
3. Confirm owned files or domains and existing dirty work before editing.
4. Follow TDD (see Test-Driven Development below): write or update failing Pest
   tests first, then implement.
5. Implement the smallest working vertical slice to make the tests pass.
6. Run focused in-memory and prepared-topology feature verification.
7. Run artifact-backed feature verification when production artifact behavior
   matters and that lane exists for the provider.
8. Run provider provision gates only as final/nightly substrate verification
   when installer, host mutation, image, binary, or topology-preparation
   behavior changed.
9. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

10. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

## Test-Driven Development

Orbit is a TDD project. Every behavior change ships with Pest coverage that
fails before the implementation lands and passes after. No exceptions for
"trivial" changes — if behavior is worth changing, it is worth a test.

Normal feature work follows a staged E2E model:

- **Pest unit/feature tests** in `tests/Unit/` or `tests/Feature/` that pin the
  internal contract: command output shape, JSON schema, validation, branching
  logic, error paths. These run under `php artisan test --compact`.
- **Source/dev topology checks** for behavior that needs a live topology during
  iteration. Use source-mounted Docker feature tests or retained Incus dev
  topologies for fast diagnosis, then codify the finding in Pest.
- **Source-prepared feature E2E** via `composer test:e2e` or a provider lane
  (`composer test:e2e:docker` / `composer test:e2e:incus`). These lanes consume
  prepared topologies containing the current source and are the normal durable
  E2E signal.
- **Artifact-backed feature E2E** when production artifacts matter and an
  artifact lane exists. This consumes the built CLI binary and gateway image.
- **Provider provision gates** only as final/nightly substrate verification for
  installer, host mutation, image, binary, `node:new`, or topology-preparation
  behavior. Agents run `composer test:e2e:provision:docker` or
  `composer test:e2e:provision:incus`; never run the aggregate
  `composer test:e2e:provision`. There is no standing live-node lane — see
  `apps/docs/content/testing/README.md` for the full lane map.

Workflow per change:

1. Write the failing Pest test(s) first — unit/feature for the contract, E2E
   for the integrated behavior.
2. Run them and confirm they fail for the expected reason.
3. Implement the smallest slice that turns them green.
4. Re-run the smallest staged lane that proves the changed behavior before
   reporting completion.

A feature is not done until the relevant in-memory and prepared-topology feature
signals pass. Provider provision gates are required only when the change touches
the provider substrate or production artifact preparation.

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
