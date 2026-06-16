---
name: implementing-features
description: Use when implementing an Orbit feature, bug fix, command behavior change, documentation update, project Orbit skill sync, or scoped Solo todo handoff.
---

# Implementing Features

## Overview

Implement a scoped Orbit change from a clear handoff or Solo todo. Documentation
updates and project Orbit skill updates are implementation work here, alongside
tests and code.

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

## Retained Incus Inspection Gate

Retained Incus is the mandatory hands-on verification step before durable Incus
E2E whenever the change touches real VM/node behavior: OS package installs,
role baselines, doctor restore, tool repair, SSH/sudo, WireGuard, systemd,
host mutation, gateway API shims, source-mounted checkout behavior, or anything
that previously failed only inside an Incus topology.

Do not treat retained Incus as optional "extra confidence" for those changes.
Use it to prove the current worktree behaves on real VMs, then codify the same
finding in focused Pest E2E. The retained check is not the final signal by
itself; the durable prepared Incus lane must still pass afterward.

Acquire the topology from the implementation worktree and source-mount only the
roles that need the current checkout:

```bash
composer e2e:incus -- --start --topology=<kind> --checkout-roles=<roles> --json
```

Inspect through the configured Incus host from `.env.e2e`; do not assume a
host name unless the environment says so. Typical Beast-backed inspection looks
like:

```bash
ssh beast 'incus list --format csv -c ns | grep <retained-id> || true'
ssh beast 'incus exec <instance> -- sudo -u orbit bash -lc "cd /home/orbit/orbit-run && <orbit command>"'
ssh beast 'incus exec <instance> -- bash -lc "<host command such as gh --version>"'
```

For source-mounted Incus topologies, `/home/orbit/orbit` is the synced source
mount and `/home/orbit/orbit-run` is the VM-local runtime mirror. Run Orbit
commands from `/home/orbit/orbit-run` unless explicitly testing the source
mount itself. After local worktree edits, refresh a retained topology with:

```bash
composer e2e:incus -- --sync --id=<id>
```

That sync is one-way from the implementation worktree to the runner-host source
mount and then to each recorded VM runtime mirror. It transfers filesystem
deltas for included files to the runner host, not only Git-dirty files, and then
refreshes the VM-local runtime mirrors. Keep the implementation worktree as the
source of truth. VM-side edits in `/home/orbit/orbit-run` are scratch work and
are overwritten by the next sync; VM-side edits in `/home/orbit/orbit` mutate
the runner-host copy, not the local worktree.

Record the retained topology id, topology kind, checkout roles, inspected
instances, commands run, and observed result in the Solo report. If you mutate
state for a focused check, isolate unrelated prepared-state drift first and say
what was isolated.

Release and verify cleanup before starting durable E2E:

```bash
composer e2e:incus -- --stop --id=<id> --json
ssh <incus-host> 'incus list --format csv -c ns | grep <id> || true'
```

If `--stop` misses owned instances because the retained manifest is incomplete,
delete only the owned leftovers by exact instance name, verify the grep is
empty, and report the cleanup anomaly. Never leave retained VMs running while
moving on to durable E2E.

## Workflow

1. Set up the workspace with `bin/orbit-prepare-worktree`.
2. Read the handoff, `AGENTS.md`, `apps/docs/content/product-decisions.md`,
   relevant product docs under `apps/docs/content/**`, and relevant session
   context under `docs/superpowers/**`.
3. Confirm owned files or domains and existing dirty work before editing.
4. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs.
5. Check whether the project-owned Orbit skill under `skills/orbit/**` is
   affected. Update it in the same worktree when the change alters public CLI
   behavior, command signatures, node roles, state families, app/workspace
   runtime behavior, deployment/profile/update flows, or operational guidance
   another LLM would need to use Orbit correctly.
6. Follow TDD (see Test-Driven Development below): write or update failing Pest
   tests first, then implement.
7. Implement the smallest working vertical slice to make the tests pass.
8. For VM/node/tool/package/doctor/role-baseline behavior, run the retained
   Incus inspection gate from this worktree before durable Incus E2E. Retained
   topologies sync the current worktree into a runner-host source mount and
   execute from each VM's runtime mirror, so they are suitable for real VM
   inspection. Release and verify cleanup before continuing.
9. Run focused in-memory and prepared-topology feature verification.
10. Run artifact-backed feature verification when production artifact behavior
   matters and that lane exists for the provider.
11. Run provider provision gates only as final/nightly substrate verification
   when installer, host mutation, image, binary, or topology-preparation
   behavior changed. Docker provision is only for Docker artifact/image or
   Docker topology-preparer changes; do not run it as a generic post-`composer
   test:e2e` gate.
12. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

13. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

14. Commit the verified worktree changes on the worktree branch.
15. Merge the branch back into `main` from the primary `~/orbit` checkout,
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
  - Retained/live Incus topologies mount the initiating worktree through a
    synced source checkout at `/home/orbit/orbit`, then execute from a VM-local
    runtime mirror at `/home/orbit/orbit-run`. Remote Incus hosts rsync the
    current worktree to `/tmp/orbit-e2e-sources/<worktree>-<hash>` before
    acquisition, then mount that synced checkout. After local edits, use
    `composer e2e:incus -- --sync --id=<id>` to refresh the source mount and
    runtime mirrors. Keep the local worktree as source of truth; VM-side mirror
    edits are disposable unless copied back explicitly. Node-local mutable
    Orbit state remains under `/home/orbit/.config/orbit`.
  - Use retained/live Incus topologies to develop and test against real VM
    behavior before or while writing the durable E2E test. For
    VM/node/tool/package/doctor/role-baseline changes, this retained check is a
    required gate before `composer test:e2e:incus`, not an optional diagnostic.
    It is still not proof by itself; durable prepared-topology E2E must pass.
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
- Keep the project-owned Orbit skill in sync with product and implementation
  changes. `skills/orbit/SKILL.md` is the concise external-LLM entry point;
  `skills/orbit/references/*.md` carries command-family detail. If a change
  affects how another LLM should operate Orbit, update the matching skill
  reference file in the same commit. If the skill is not affected, say so in
  the report instead of leaving the check implicit.
- When the change lands a direction change or reversal, append a one-line entry
  to `apps/docs/content/product-decisions.md` (newest first,
  `- YYYY-MM-DD — <decision with topic noun>. (solo todo #NNNN)`), linking the
  Solo todo being executed. Skip routine work — flags, fixes, refactors.
- When a decision removes or renames a public command, flag, or product term,
  also append an entry to `banned_terms` in `apps/docs/config/librarian.php`
  in the same change (terms, decision line, replacement wording, allow paths).
  `composer docs-lint` then fails on every stale mention of the retired term
  until the docs sweep is complete, and keeps it from coming back.
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

Orbit skill:
- <updated files, or not affected>

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
