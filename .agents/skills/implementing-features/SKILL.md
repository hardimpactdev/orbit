---
name: implementing-features
description: Use when the user says to implement a crystallized Orbit feature or bug fix, or when implementing an Orbit feature, bug fix, command behavior change, documentation update, project Orbit skill sync, or scoped Solo todo handoff through Grok implementation sub-agents.
---

# Implementing Features

## Overview

Implement a scoped Orbit change from a crystallized discussion, handoff, or Solo
todo by acting as the Codex orchestrator for Grok implementation sub-agents.
Crystallization happens before this skill is triggered: the feature or bug fix
has already been discussed into a concrete product contract, scope, and
verification expectation. This skill starts when the user explicitly moves from
discussion to execution with wording such as `let's implement`, `start
implementation`, or an equivalent implementation request. Documentation updates
and project Orbit skill updates are implementation work here, alongside tests
and code.

## Preconditions

- The request has a clear product contract or handoff.
- Relevant docs have been checked, with gaps or contradictions captured in the
  handoff or identified during implementation.
- Acceptance criteria and verification commands are known.
- Owned files or domains are explicit enough to avoid unrelated edits.
- A dedicated worktree can be created for the change (see Workspace Setup
  below).
- Grok is available as a Solo agent runtime for implementation. If Grok cannot
  be spawned, stop and report the blocker instead of quietly implementing the
  feature yourself.

## Orchestrator Role

The agent using this skill is the feature owner and orchestrator, not the
primary implementer. Use Grok sub-agents for implementation work.

Responsibilities:

- Create and own the Codex goal for the crystallized implementation when the
  user explicitly starts implementation from the current thread.
- Prepare and own the dedicated Orbit worktree.
- Read the handoff, docs, and existing code enough to define clear Grok tasks.
- Spawn one Grok worker by default. Spawn multiple Grok workers only for
  disjoint slices with explicit ownership and merge order.
- Keep docs, tests, and code for the same behavior in the same Grok task unless
  the split is genuinely independent.
- Monitor Grok output, inspect diffs, ask for corrections, and keep unrelated
  dirty files untouched.
- Run or require the verification gates and independently read the results.
- Own final commit, merge-back, worktree cleanup, and the implementation report.

Do not make substantive implementation edits yourself unless the user explicitly
approves bypassing Grok. Small orchestration-only edits, conflict resolution
after review, and mechanical formatting fixes are acceptable only when they do
not replace the Grok implementation loop.

## Grok Delegation

When running inside Solo, discover the live Grok tool with `list_agent_tools`,
spawn it with `spawn_agent`, and prepend Solo's returned `agent_instructions` to
the first prompt. If the current environment exposes a different but equivalent
Grok spawn mechanism, use that mechanism and record it in the report.

Use this prompt shape:

```text
<prepend the agent_instructions returned by Solo spawn_agent>

You are implementing one scoped Orbit feature slice in a dedicated worktree.
Other workers may be active, so keep ownership narrow and do not revert
unrelated changes.

Worktree:
<absolute path to .worktrees/<branch-name>>

Read and follow:
- AGENTS.md
- HARNESS.md
- LOOP.md.example, then copy it to ignored LOOP.md for this active worktree
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/skills/implementing-features/SKILL.md
- relevant docs and tests named in the handoff

Feature handoff:
<paste the crystallized handoff or owned slice>

Rules:
- Edit only inside the assigned worktree.
- Keep docs, tests, and code aligned.
- Use TDD: failing Pest coverage first, then implementation.
- Run the focused verification assigned to this slice.
- Capture implementation signals as they appear. When a failure, review
  correction, docs conflict, or setup problem reveals missing durable context,
  search harness-signals/ for prior occurrences, then use LOOP.md and
  HARNESS_SIGNALS.md to select the smallest guardrail target. Update or create
  a signal record only when it belongs to this slice; otherwise report a scoped
  follow-up.
- For CLI command changes, create or update Pest coverage first and E2E
  coverage next. After the tests exist and the focused command behavior is
  working, run or request the retained ingress VM Solo-terminal gate before
  durable E2E or any live/release-candidate deployment. Report the topology
  id, VM instance, terminal/session, exact commands, observed human/JSON/failure
  results, and whether user verification is pending or complete.
- Do not merge to main, delete the worktree, force-push, reset, clean, stash, or
  touch unrelated dirty files.

Report changed files, tests, harness signals, verification commands/results,
blockers, and risks.
```

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
Use `--with-e2e` when the change needs prepared-topology feature lanes. CLI
command changes need `--with-e2e` because they must define durable E2E coverage
and pass the retained ingress VM Solo-terminal gate before durable E2E or
live/release-candidate deployment. The
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

Any feature that creates or changes a CLI command must also pass a retained
ingress VM Solo-terminal gate before durable E2E or live/release-candidate
deployment. This includes new commands, flags, options, arguments, human output,
JSON schemas, validation, prompts, command side effects, and command-family
behavior.

For CLI changes, use this ordering:

1. Create or update focused Pest tests for the command contract.
2. Create or update durable E2E tests for the integrated command behavior.
3. Implement the smallest slice that makes the focused command behavior work.
4. Spawn or request a Solo terminal.
5. Acquire a retained Incus topology with the relevant source-mounted VM.
6. Run the changed command inside the relevant VM, usually the retained ingress
   VM, from `/home/orbit/orbit-run`.
7. Prove the observed human output, JSON output, prompts, failure paths, and
   side effects that changed.
8. Give the user a chance to inspect and confirm the VM-observed CLI behavior.
9. Only then run durable E2E and any broader quality or release-candidate flow.

Do not spend the live topology or release-candidate path on a CLI change before
this retained VM proof and user verification point. Do not treat retained Incus
as optional "extra confidence" for those changes. The retained check is the
operator-facing proof checkpoint; it is not the final automated signal by
itself, and the durable prepared Incus lane must still pass afterward when the
change requires it.

Acquire the topology from the implementation worktree and source-mount only the
roles that need the current checkout:

```bash
composer e2e:incus -- --start --topology=<kind> --checkout-roles=<roles> --json
```

For CLI command changes, use the topology that creates a dedicated ingress VM.
Source-mount the ingress role, plus only the other roles whose current checkout
is needed for the command under test:

```bash
composer e2e:incus -- --start \
  --topology=operator_gateway_app-dev_app-prod_ingress \
  --checkout-roles=ingress \
  --json
```

Identify the ingress instance from the retained topology output or manifest,
then open that retained ingress VM in a Solo terminal. Run the exact changed
command path from `/home/orbit/orbit-run`, covering human and `--json` output
when either contract is affected, plus the relevant failure or prompt path. If
the current Solo environment cannot open a terminal directly, use the
configured Incus host with `incus exec` as the fallback and report that fallback
explicitly.

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
instances, Solo terminal or fallback session, commands run, and observed result
in the Solo report. If you mutate state for a focused check, isolate unrelated
prepared-state drift first and say what was isolated.

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

1. Confirm the request is moving an already-crystallized feature or bug fix into
   implementation. Use the crystallized discussion, handoff, or Solo todo as
   the concrete implementation handoff. Create a Codex goal for the
   implementation and proceed as orchestrator. Do not route through retired
   orchestration skills.
2. Set up the workspace with `bin/orbit-prepare-worktree`.
3. Read the handoff, `AGENTS.md`, `HARNESS.md`, `LOOP.md.example`,
   `HARNESS_SIGNALS.md`, `harness-signals/README.md`,
   `PRODUCT_DECISIONS.md`, relevant product docs under `apps/docs/content/**`,
   and relevant session context under `docs/superpowers/**`. Copy
   `LOOP.md.example` to ignored `LOOP.md` for the active worktree and fill the
   goal contract before implementation.
4. Confirm owned files or domains and existing dirty work before editing.
5. Decide the Grok delegation plan. Use one Grok worker unless the feature has
   disjoint slices with explicit file/domain ownership and merge order.
6. Spawn the Grok worker(s) with the worktree path, handoff, owned scope,
   documentation authority, TDD requirement, focused verification, and the rule
   that Grok must not merge to `main` or clean up the worktree.
7. Monitor Grok, inspect diffs, and send correction prompts until the
   acceptance criteria are met or a blocker is explicit. When a correction
   reveals missing durable context, triage it through `LOOP.md` and
   `HARNESS_SIGNALS.md`.
8. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs. Prefer sending documentation corrections back through
   the Grok worker that owns the related behavior.
9. For every failed verification, review comment, human correction, docs
   conflict, setup problem, or agent mistake encountered during the slice,
   search `harness-signals/` for related prior records, then decide whether it
   is local cleanup or a durable harness signal. If the search returns stale,
   overlapping, or noisy records and curation belongs to this slice, update,
   consolidate, retire, or delete the records using `harness-signals/README.md`.
   If it is durable and belongs to the current slice, create or update the
   signal record and update the smallest guardrail target in the same worktree.
   If it is durable but broader than the slice, report a scoped follow-up
   instead of expanding ownership.
10. Check whether the project-owned Orbit skill under `skills/orbit/**` is
   affected. Update it in the same worktree when the change alters public CLI
   behavior, command signatures, node roles, state families, app/workspace
   runtime behavior, deployment/profile/update flows, or operational guidance
   another LLM would need to use Orbit correctly.
11. Ensure the Grok implementation followed TDD (see Test-Driven Development
   below): failing Pest tests first, then implementation.
12. Keep the smallest working vertical slice that makes the tests pass.
13. For CLI command behavior, ensure durable E2E coverage has been created or
    updated for the integrated behavior, then run the retained ingress VM
    Solo-terminal gate from this worktree before durable E2E. Give the user a
    chance to inspect the VM-observed CLI behavior before any
    live/release-candidate deployment. If this cannot be completed, report the
    blocker explicitly instead of widening the release path.
14. For VM/node/tool/package/doctor/role-baseline behavior, run the retained
   Incus inspection gate from this worktree before durable Incus E2E. Retained
   topologies sync the current worktree into a runner-host source mount and
   execute from each VM's runtime mirror, so they are suitable for real VM
   inspection. Release and verify cleanup before continuing.
15. Run focused in-memory and prepared-topology feature verification. For CLI
    behavior, durable E2E follows the retained VM/user-verification checkpoint.
    For non-CLI behavior, proceed to the relevant E2E lane once code and
    focused tests are ready; no retained CLI confirmation gate applies.
16. Run artifact-backed feature verification when production artifact behavior
    matters and that lane exists for the provider.
17. Run provider provision gates only as final/nightly substrate verification
    when installer, host mutation, image, binary, or topology-preparation
    behavior changed. Docker provision is only for Docker artifact/image or
    Docker topology-preparer changes; do not run it as a generic post-`composer
    test:e2e` gate.
18. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

19. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

20. Commit the verified worktree changes on the worktree branch.
21. Merge the branch back into `main` from the primary `~/orbit` checkout,
    remove the completed worktree/branch, and leave `~/orbit` on updated
    `main`. Preserve unrelated dirty files in `~/orbit`; if they overlap with
    the merge, stop for direction instead of discarding them.
22. If release was explicitly agreed or specifically discussed as part of the
    crystallized scope, run the release flow after merge. Capture live topology
    `doctor` status before publishing a release, run `orbit update:all` after
    the release artifacts are accepted, run `doctor` again, compare the before
    and after results, and either fix regressions immediately or record scoped
    follow-up tasks for intentional migration work.

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
    behavior while keeping durable E2E coverage in sync. For
    VM/node/tool/package/doctor/role-baseline changes, this retained check is a
    required gate before `composer test:e2e:incus`, not an optional diagnostic.
    It is still not proof by itself; durable prepared-topology E2E must pass.
  - CLI command changes always use the retained ingress VM Solo-terminal gate
    after focused Pest coverage and durable E2E coverage have been created or
    updated, and before durable E2E execution or live/release-candidate
    deployment. Prove the command behavior manually from
    `/home/orbit/orbit-run` inside the retained ingress VM, give the user a
    chance to inspect it, then run the durable E2E lane.
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
- Grok performs implementation edits; the Codex feature owner orchestrates,
  reviews, verifies, commits, merges, cleans up, and reports.
- Give each Grok worker one clear ownership boundary. If a boundary is hard to
  state, use one Grok worker serially instead of parallel workers.
- Apply documentation updates in the same implementation worktree as the related
  tests and code. Do not rely on a separate documentation-only implementation
  pass for feature work.
- Run the repo feedback loop from ignored `LOOP.md` during the slice, creating
  it from `LOOP.md.example` when needed. Every signal does not need a repository
  edit, but every durable signal needs either a curated `harness-signals/` record
  plus a guardrail target update in this worktree, or a scoped follow-up in the
  implementation report. Do not use `LOOP.md` or `HARNESS_SIGNALS.md` as event
  logs. Curate stale or noisy signal records when they are in the owned scope;
  otherwise report a focused curation follow-up.
- Keep the project-owned Orbit skill in sync with product and implementation
  changes. `skills/orbit/SKILL.md` is the concise external-LLM entry point;
  `skills/orbit/references/*.md` carries command-family detail. If a change
  affects how another LLM should operate Orbit, update the matching skill
  reference file in the same commit. If the skill is not affected, say so in
  the report instead of leaving the check implicit.
- When the change lands a direction change or reversal, append a one-line entry
  to `PRODUCT_DECISIONS.md` (newest first,
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

Grok delegation:
- Worker(s): <name/process id and owned scope>
- Corrections requested: <summary or none>

Changed files:
- <path>: <why>

Product-decisions ledger:
- <appended line, or none>

Orbit skill:
- <updated files, or not affected>

Harness signals:
Repeat this block for each durable signal, or write `none`.
- Record: <harness-signals/path.md, follow-up path, or none>
- Source: <signal source, or none>
- Missing context: <context gap, or none>
- Guardrail target: <updated target, follow-up target, or none>
- Verification: <how the guardrail target was checked, or not applicable>
- Follow-up: <scoped follow-up, or none>

Tests:
- Pest unit/feature: <test added or changed>
- Pest E2E: <test added or changed>

Verification:
- `php artisan test --compact`: <result>
- Retained CLI ingress VM Solo-terminal check: <topology id, instance,
  terminal/session, command results, or not applicable>
- User CLI verification: <confirmed, pending, blocked, or not applicable>
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
