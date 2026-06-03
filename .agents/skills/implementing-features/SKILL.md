---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, documentation update, or scoped Solo todo handoff.
---

# Implementing Features

## Overview

Implement a scoped Orbit change from a clear handoff or Solo todo. Documentation
updates are implementation work here, alongside tests and code.

## Preconditions

- The request has a clear product contract or handoff.
- Relevant docs have been checked, with gaps or contradictions captured in the
  handoff or identified during implementation.
- Acceptance criteria and verification commands are known.
- Owned files or domains are explicit enough to avoid unrelated edits.
- A dedicated worktree exists for the change (see Workspace Setup below).

## Workspace Setup

Implementation happens in an isolated worktree, never directly on `main` or a
shared branch. Use `bin/orbit-prepare-worktree` from the main checkout to
create, bootstrap, and verify the worktree. Do not hand-roll these steps.

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

Run this from the main checkout:

```bash
bin/orbit-prepare-worktree <branch-name>
```

Use `--base=<ref>` when the todo must start from a branch other than `main`.
Use `--with-e2e` when the change needs prepared-topology feature lanes. The
script creates or reuses `.worktrees/<branch-name>/`, installs dependencies,
prepares Laravel app state, links `.env.e2e` when available, and runs
`composer test` unless `--skip-tests` is explicitly passed.

If preparation fails, leave the worktree in place for inspection and report the
failing step instead of dispatching implementation onto a broken checkout.

## E2E Readiness And Resource Pool

The E2E provider pools are shared across every Orbit worktree on this machine.
Worktrees symlink `.env.e2e` back to the main checkout, so provider selection,
runner capacity, lease directories, and host pools are shared state. Read the
active providers from `.env.e2e`, not from hard-coded hostnames: Docker feature
tests use `ORBIT_E2E_DOCKER_TEST_RUNNERS`, and Incus preparation/topology work
uses the configured Incus host variables such as `ORBIT_E2E_INCUS_HOSTS` and
`ORBIT_E2E_INCUS_HOST_SLOTS`.

Active `orbit-e2e` containers, networks, volumes, VMs, or lease files on those
configured providers are normal while other features run in other worktrees.
Resource existence or container count alone is not a stale-resource signal.

Before running E2E in a worktree:

1. `composer e2e:preflight` — verifies the configured Incus host is
   reachable. Required for the Incus lane; safe to skip if only Docker
   lanes are exercised.

2. Treat the provider pool as a blocking lease pool. Run the assigned lane and
   let workers wait for free slots. A long wait at startup usually means every
   configured slot is currently leased by another worktree. Do not reap merely
   because resources exist on a provider.

3. Use host diagnostics only after a wait exceeds
   `ORBIT_E2E_SLOT_WAIT_SECONDS`, or when recovering from an interrupted run.
   Derive Docker hosts from `.env.e2e`:

   ```bash
   set -a; [ ! -f .env.e2e ] || . ./.env.e2e; set +a

   printf '%s\n' "${ORBIT_E2E_DOCKER_TEST_RUNNERS:-}" \
     | tr ',' '\n' \
     | while IFS=: read -r host slots container_cap; do
         [ -n "$host" ] || continue

         if [ "$host" = "local" ]; then
           docker ps \
             --filter "name=orbit-e2e" \
             --format '{{.Names}}\t{{.Status}}'
         else
           DOCKER_HOST="ssh://$host" docker ps \
             --filter "name=orbit-e2e" \
             --format '{{.Names}}\t{{.Status}}'
         fi
       done
   ```

   Interpret output as context only. The resources may belong to live runs from
   other worktrees.

4. If the shared pool is busy, wait or reduce only this command's temporary
   demand. For Docker, pass a smaller `ORBIT_E2E_DOCKER_TEST_RUNNERS` value or
   `ORBIT_E2E_PARALLEL_PROCESSES=<n>` in the command environment. Do not commit
   or permanently edit `.env.e2e` just to make one worktree smaller; that file
   controls every worktree.

5. Reaping is destructive recovery, not pre-run hygiene. Use the dry run only
   as inventory, and remember that its output can include legitimate resources
   from other worktrees:

   ```bash
   composer e2e:reap-docker
   composer e2e:reap-incus
   ```

   Run with `--force` only after confirming the resources belong to an
   interrupted run you own, or after confirming no live E2E process can be using
   the configured providers:

   ```bash
   composer e2e:reap-docker -- --force
   ```

   Do not use `--older-than=0m` on shared providers unless intentionally
   deleting retained or abandoned resources you own.

The slot pool is a blocking lease pool: workers that cannot lease a slot
wait, they do not fail loudly. A long hang at the start of `composer
test:e2e:docker` or `composer test:e2e:incus` usually means the configured
pool is fully booked by another worktree, not a real failure.

## Workflow

1. Set up the workspace with `bin/orbit-prepare-worktree`.
2. Read the handoff, `AGENTS.md`, `apps/docs/content/product-decisions.md`,
   relevant product docs under `apps/docs/content/**`, and relevant session
   context under `docs/superpowers/**`.
3. Confirm owned files or domains and existing dirty work before editing.
4. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs.
5. Follow TDD (see Test-Driven Development below): write or update failing Pest
   tests first, then implement.
6. Implement the smallest working vertical slice to make the tests pass.
7. When real topology behavior needs hands-on diagnosis before codifying the
   final E2E assertion, use a disposable source/dev topology from this worktree.
   Retained/live Incus topologies source-mount the current worktree after rsync
   and are suitable for developing against real VM behavior. Release them when
   finished, and turn findings into Pest E2E coverage.
8. Run focused in-memory and prepared-topology feature verification.
9. Run artifact-backed feature verification when production artifact behavior
   matters and that lane exists for the provider.
10. Run provider provision gates only as final/nightly substrate verification
   when installer, host mutation, image, binary, or topology-preparation
   behavior changed. Docker provision is only for Docker artifact/image or
   Docker topology-preparer changes; do not run it as a generic post-`composer
   test:e2e` gate.
11. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

12. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

13. Commit the verified worktree changes on the worktree branch.
14. Merge the branch back into `main` from the primary `~/orbit` checkout,
    remove the completed worktree/branch, and leave `~/orbit` on updated
    `main`. Preserve unrelated dirty files in `~/orbit`; if they overlap with
    the merge, stop for direction instead of discarding them.

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
  - Incus: `composer e2e:incus -- --start --topology=<kind>` acquires a
    retained disposable topology. `composer e2e:incus -- --live
    --topology=<kind>` additionally mints a local operator identity and can
    bring up a local WireGuard tunnel.
  - Retained/live Incus topologies source-mount the initiating worktree at
    `/home/orbit/orbit`. Remote Incus hosts rsync the current worktree to
    `/tmp/orbit-e2e-sources/<worktree>-<hash>` before acquisition, then mount
    that synced checkout. Node-local mutable Orbit state remains under
    `/home/orbit/.config/orbit`.
  - Use retained/live Incus topologies to develop and test against real VM
    behavior before or while writing the durable E2E test. They are disposable
    diagnosis loops, not standing live infrastructure and not proof by
    themselves.
  - Release retained topologies with `composer e2e:incus -- --stop --id=<id>`
    or `composer e2e:incus -- --stop --all` when finished.
- **Source-prepared feature E2E** via `composer test:e2e` or a provider lane
  (`composer test:e2e:docker` / `composer test:e2e:incus`). These lanes consume
  prepared topologies containing the current source and are the normal durable
  E2E signal.
- **Artifact-backed feature E2E** when production artifacts matter and an
  artifact lane exists. This consumes the built CLI binary and gateway image.
- **Provider provision gates** only as final/nightly substrate verification.
  Docker provision refreshes Docker runtime/support images, prepared role
  images, Docker host artifact distribution, or Docker topology-preparation
  behavior. Incus provision proves the fresh VM path: installer, `node:new`,
  base image, WireGuard, systemd, package installation, and host mutation.
  Agents run the relevant provider-specific command only; never run the
  aggregate `composer test:e2e:provision`. There is no standing live-node lane —
  see `apps/docs/content/testing/README.md` for the full lane map.

Workflow per change:

1. Write the failing Pest test(s) first — unit/feature for the contract, E2E
   for the integrated behavior.
2. Run them and confirm they fail for the expected reason.
3. Implement the smallest slice that turns them green.
4. Re-run the smallest staged lane that proves the changed behavior before
   reporting completion.

A feature is not done until the relevant in-memory and prepared-topology feature
signals pass. Provider provision gates are required only when the change touches
that provider's substrate or production artifact preparation; Docker provision
is not required after ordinary `composer test:e2e` runs.

## Implementation Rules

- Prefer existing Orbit and Laravel patterns.
- Treat current docs as product authority.
- Keep docs, tests, and code aligned.
- Apply documentation updates in the same implementation worktree as the related
  tests and code. Do not rely on a separate documentation-only implementation
  pass for feature work.
- When the change lands a direction change or reversal, append a one-line entry
  to `apps/docs/content/product-decisions.md` (newest first,
  `- YYYY-MM-DD — <decision with topic noun>. (solo todo #NNNN)`), linking the
  Solo todo being executed. Skip routine work — flags, fixes, refactors.
- Retire tests only when current docs reject the behavior or replacement coverage exists.
- Stop for direction if the docs and requested behavior conflict.
- Stay inside owned scope and leave unrelated dirty files untouched.
- Completed work does not stay stranded in `.worktrees/`: it is committed,
  merged into `main`, and cleaned up unless the user explicitly asks for a PR or
  preserved branch.

## Report Shape

```markdown
## Implementation Report

Worktree:
- `.worktrees/<branch-name>` on branch `<branch>`

Changed files:
- <path>: <why>

Product-decisions ledger:
- <appended line, or none>

Tests:
- Pest unit/feature: <test added or changed>
- Pest E2E: <test added or changed>

Verification:
- `php artisan test --compact`: <result>
- `composer test:e2e` (or the appropriate ephemeral lane): <result>
- `composer quality-check`: <result>

Merge-back:
- Commit: <sha>
- Main checkout: `~/orbit` on `main` at <sha>
- Worktree cleanup: <removed or preserved with reason>

Blockers:
- <blocker or none>

Risks:
- <risk or none>
```
