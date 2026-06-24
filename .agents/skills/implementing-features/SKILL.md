---
name: implementing-features
description: Use when the user says to implement a crystallized Orbit feature or bug fix, or when implementing an Orbit feature, bug fix, command behavior change, documentation update, project Orbit skill sync, or scoped Solo todo handoff through Solo-managed workers.
---

# Implementing Features

## Overview

Implement a scoped Orbit change from a crystallized discussion, handoff, Solo
scratchpad, or Solo todo by acting as the feature owner. The feature owner may
run in Codex CLI, the Codex app, Claude, or another capable LLM surface. Solo is
the required substrate for spawned workers and retained verification terminals.
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
- Solo can spawn the workers or retained terminals needed for the slice. If the
  required Solo worker or verification terminal cannot be spawned, stop and
  report the blocker instead of quietly replacing that lane with untracked work.

## Orchestrator Role

The agent using this skill is the feature owner and orchestrator. It may run in
any LLM app, but delegated implementation, documentation, review, and terminal
verification lanes must be tracked through Solo when the slice calls for
subagents or retained VM proof.

Responsibilities:

- Create and own the Done Contract for the active slice when the
  user explicitly starts implementation from the current thread or app.
- Prepare and own the dedicated Orbit worktree.
- Read the handoff, docs, and existing code enough to define clear worker tasks.
- For multi-slice features, keep one feature scratchpad as the roadmap and one
  feature worktree as the execution boundary. Use `.orbit/loop.md` for the
  active slice only, rewriting it when the next slice starts.
- Spawn one Solo implementation worker by default. Grok is the default
  implementation model when available, but another suitable Solo-managed worker
  may own the slice if that is the active toolchain. Spawn multiple workers only
  for disjoint slices with explicit ownership and merge order.
- Use the Solo role matrix in `HARNESS.md` when splitting work. Spawn a Claude
  documenter/librarian worker for substantial docs-first or documentation-heavy
  slices when its ownership is separable.
- Keep docs, tests, and code for the same behavior in one worker by default.
  Split documentation and code only when the feature owner can accept the docs
  contract before code relies on it, or when ownership is genuinely independent.
- Monitor worker output, inspect diffs, ask for corrections, and keep unrelated
  dirty files untouched.
- Run or require the verification gates and independently read the results.
- Run applicable reviewer personas from `HARNESS.md` after implementation
  evidence exists. For documentation-heavy changes, use
  `.agents/review-personas/docs-librarian.md`; for CLI command changes, use
  `.agents/review-personas/cli-command.md` before accepting the slice.
- Own final commit, merge-back, worktree cleanup, feature completion cleanup,
  and the implementation report.

Do not make substantive implementation edits yourself unless the user explicitly
approves bypassing the Solo worker lane. Small orchestration-only edits,
conflict resolution after review, and mechanical formatting fixes are acceptable
only when they do not replace the tracked worker loop.

## Solo Implementation Delegation

Discover the available Solo implementation worker for the current environment
and spawn it through Solo. Prefer Grok for bounded PHP/CLI/Pest implementation
when available, but the hard requirement is Solo tracking, not the model brand.
Prepend Solo's returned `agent_instructions` to the first prompt. If the current
feature owner cannot reach a Solo worker, stop and report the blocker instead of
using an untracked background model or shell.

Use this prompt shape:

```text
<prepend the agent_instructions returned by Solo spawn_agent>

You are implementing one scoped Orbit feature slice in a dedicated feature
worktree. This worktree may already contain earlier slices for the same feature.
Other workers may be active, so keep ownership narrow and do not revert
unrelated changes.

Worktree:
<absolute path to .worktrees/<branch-name>>

Read and follow:
- AGENTS.md
- HARNESS.md
- LOOP.md.example, then copy it to `.orbit/loop.md` for this active slice
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/skills/implementing-features/SKILL.md
- relevant docs and tests named in the handoff

Feature handoff:
<paste the crystallized handoff or owned slice>

Rules:
- Edit only inside the assigned worktree.
- If a feature scratchpad exists, read its slice outcomes before editing and
  treat `.orbit/loop.md` as the current slice contract, not feature history.
- Inspect the current branch diff or log enough to understand prior slices that
  already exist in this feature worktree.
- Keep docs, tests, and code aligned.
- The feature orchestrator owns the Done Contract in `.orbit/loop.md`. You may
  challenge or propose changes to it, but do not silently weaken scope,
  evidence, reviewer checks, stop conditions, or pivot conditions.
- Use TDD: failing Pest coverage first, then implementation.
- After reading the required local files named in this prompt, produce the
  first narrow diff before doing broad repository discovery. Prefer a
  test-only first diff for behavior changes or a docs-only first diff for
  documentation-owned work. If the first diff cannot be made from the handoff,
  report the missing context instead of continuing to search.
- Run the focused verification assigned to this slice.
- Capture implementation signals as they appear. When a failure, review
  correction, docs conflict, or setup problem reveals missing durable context,
  search harness-signals/ for prior occurrences, then use `.orbit/loop.md` and
  HARNESS_SIGNALS.md to select the smallest guardrail target. Update or create
  a signal record only when it belongs to this slice; otherwise report a scoped
  follow-up.
- For CLI command changes, create or update Pest coverage first and E2E
  coverage next. After the tests exist and the focused command behavior is
  working, run or request the retained ingress VM Solo-terminal gate before
  durable E2E or any live/release-candidate deployment. Report the topology
  id, VM instance, terminal/session, exact commands, observed human/JSON/failure
  results, and whether user verification is pending or complete.
- Do not commit, merge to main, delete the worktree, force-push, reset, clean,
  stash, or touch unrelated dirty files unless the feature owner explicitly
  gives you that exact boundary.

Report changed files, tests, harness signals, verification commands/results,
blockers, and risks.
```

## Claude Documenter / Librarian Delegation

Use a Claude documenter/librarian worker when documentation is substantial
enough to own separately: command contracts, product authority edits,
documentation-first handoffs, or focused docs drift analysis inside the changed
surface. Do not spawn it for routine small copy edits.

The documenter owns documentation artifacts, not final product decisions or code
implementation. The feature owner reviews the result and reconciles it with the
code worker before commit. Routine docs review covers changed files and named
authority docs; full-repo drift audits use
`.agents/skills/auditing-docs-drift/SKILL.md` only when explicitly requested.

When running inside Solo, discover the live Claude-capable tool with
`list_agent_tools`, spawn it with `spawn_agent`, and prepend Solo's returned
`agent_instructions` to the first prompt.

Use this prompt shape:

```text
<prepend the agent_instructions returned by Solo spawn_agent>

You are the documentation/librarian worker for one scoped Orbit feature slice.
Keep ownership narrow and do not edit code unless the feature owner explicitly
asks for a mechanical docs-support change.

Worktree:
<absolute path to .worktrees/<branch-name>>

Read and follow:
- AGENTS.md
- HARNESS.md
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/skills/updating-documentation/SKILL.md
- .agents/review-personas/docs-librarian.md
- .agents/skills/auditing-docs-drift/SKILL.md only if the handoff asks for
  drift, contradiction, stale terminology, or anchor analysis
- changed docs and immediate authority docs named in the handoff

Documentation handoff:
<paste the docs-owned slice>

Rules:
- Review and edit only the changed docs surface plus immediate authority docs
  needed to judge it.
- Do not turn focused docs review into a full-project audit.
- Keep process/harness docs separate from product behavior docs.
- If product intent is unclear, stop with the exact conflict instead of
  choosing silently.
- Run or request the assigned docs verification.

Report changed docs, authority docs read, docs-lint result, open questions,
harness signals, and whether the docs contract is stable enough for code work.
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
6. Verify which Orbit launcher will be exercised. For source-mounted retained
   topology proof, run the command through the source checkout
   (`./apps/cli/orbit` from `/home/orbit/orbit-run`) or prove that
   `/usr/local/bin/orbit` resolves to that source checkout. For
   release-candidate or live-node proof, use the installed binary path being
   validated.
7. Open an interactive shell inside the relevant retained VM, usually the
   ingress or operator VM, before running the changed command. The Solo
   terminal should land at `/home/orbit/orbit-run` inside the VM so the user can
   watch progress, spinners, blinking indicators, prompts, and streaming output
   while the command runs.
8. Run or request CLI reviewer PTY frame analysis from the same runtime context
   before asking the user to inspect human UX/output. For retained Incus proof,
   prefer running the capture script inside the Solo terminal shell attached to
   the target VM. The reviewer inspects `summary.txt`, `chunks.jsonl`, and
   `transcript.txt` for cadence, liveness, skipped frames, wrapping, ANSI
   framing, and final shape.
9. Prove the observed human output, JSON output, prompts, failure paths, and
   side effects that changed.
10. Give the user a chance to inspect and confirm the VM-observed CLI behavior
    only after the reviewer has summarized the PTY confidence basis or exact
    remaining mismatch.
11. Only then run durable E2E and any broader quality or release-candidate flow.

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

Identify the target instance from the retained topology output or manifest, then
open that retained VM in a Solo terminal and stop at a VM shell prompt before
starting the command. Run the exact changed command path from
`/home/orbit/orbit-run`, covering human and `--json` output when either contract
is affected, plus the relevant failure or prompt path. Record the launcher proof
(`command -v orbit`, `readlink -f`, or the explicit `./apps/cli/orbit` source
launcher command) next to the observed output.

A host-wrapped one-shot command such as
`ssh <host> 'incus exec <instance> -- ... <orbit command>'` is useful for
machine transcripts, JSON capture, or fallback diagnosis, but it does not
satisfy the retained Solo-terminal inspection gate for human rendering. The
gate is only satisfied when the Solo terminal is attached inside the VM before
the human command starts. If the current Solo environment cannot open that
interactive VM shell, use the configured Incus host with `incus exec` as a
fallback and report that the user-inspection gate was downgraded.

Inspect through the configured Incus host from `.env.e2e`; do not assume a
host name unless the environment says so. Typical Beast-backed inspection looks
like:

```bash
ssh beast 'incus list --format csv -c ns | grep <retained-id> || true'
ssh -tt beast 'incus exec <instance> -- sudo -iu orbit bash -lc "cd /home/orbit/orbit-run && exec bash -i"'
# then run inside the VM shell:
./apps/cli/orbit <command>
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
   implementation. Use the crystallized discussion, handoff, Solo scratchpad, or
   Solo todo as the concrete implementation handoff. If the feature contains
   multiple slices, use one lightweight scratchpad as the feature roadmap and
   one worktree as the execution boundary. Create the active slice Done Contract
   and proceed as feature owner. Do not route through retired orchestration
   skills.
2. Set up the workspace with `bin/orbit-prepare-worktree`.
3. Read the handoff, `AGENTS.md`, `HARNESS.md`, `LOOP.md.example`,
   `HARNESS_SIGNALS.md`, `harness-signals/README.md`,
   `PRODUCT_DECISIONS.md`, relevant product docs under `apps/docs/content/**`,
   and relevant session context under `docs/superpowers/**`. Copy
   `LOOP.md.example` to `.orbit/loop.md` for the active slice and fill the Done
   Contract before implementation. If this is a later slice in the same feature
   worktree, rewrite `.orbit/loop.md` for the current slice and keep earlier
   slice outcomes in the feature scratchpad.
4. Confirm owned files or domains and existing dirty work before editing.
5. Decide the Solo worker plan from `HARNESS.md`. Use one Solo implementation
   worker by default; Grok is the default implementation model when available.
   Add a Claude documenter/librarian worker only when documentation is
   substantial and the docs-owned surface is clear.
6. If a Claude documenter/librarian worker is used, spawn it first or in
   parallel only after the docs-owned slice is explicit. The feature owner must
   inspect the docs result and accept the docs contract before code relies on it.
7. Spawn the Solo implementation worker(s) with the worktree path, handoff,
   owned scope, documentation authority, TDD requirement, focused verification,
   and the rule that workers must not commit, merge to `main`, or clean up the
   worktree unless the feature owner explicitly assigns that exact step.
8. Monitor workers, inspect diffs, and send correction prompts until the
   acceptance criteria are met or a blocker is explicit. Start substantial
   slices with a first-checkpoint prompt that asks only for a test-only diff
   for behavior changes or a docs-only diff for documentation-owned work. Give
   that checkpoint a short timer in Solo when available. The first checkpoint is
   a narrow owned diff or an explicit missing-context blocker after the required
   local files are read; broad discovery without a first diff is a process
   problem to correct. After one explicit first-diff correction, if the worker
   still produces no diff or blocker, stand down the worker, mark the matching
   harness signal recurring, and replace the worker instead of letting the
   process stall. Assign the implementation phase only after the first diff
   exists and has been inspected. When a correction reveals missing durable
   context, triage it through `.orbit/loop.md` and `HARNESS_SIGNALS.md`.
9. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs. Use the Claude documenter/librarian for substantial
   docs-owned corrections; otherwise keep docs corrections with the worker that
   owns the related behavior.
10. For every failed verification, review comment, human correction, docs
   conflict, setup problem, or agent mistake encountered during the slice,
   search `harness-signals/` for related prior records, then decide whether it
   is local cleanup or a durable harness signal. If the search returns stale,
   overlapping, or noisy records and curation belongs to this slice, update,
   consolidate, retire, or delete the records using `harness-signals/README.md`.
   If it is durable and belongs to the current slice, create or update the
   signal record and update the smallest guardrail target in the same worktree.
   If it is durable but broader than the slice, report a scoped follow-up
   instead of expanding ownership.
11. Check whether the project-owned Orbit skill under `skills/orbit/**` is
   affected. Update it in the same worktree when the change alters public CLI
   behavior, command signatures, node roles, state families, app/workspace
   runtime behavior, deployment/profile/update flows, or operational guidance
   another LLM would need to use Orbit correctly.
12. Ensure the implementation worker followed TDD (see Test-Driven Development
    below): failing Pest tests first, then implementation.
13. Keep the smallest working vertical slice that makes the tests pass.
14. For CLI command behavior, ensure durable E2E coverage has been created or
    updated for the integrated behavior, then run the retained ingress VM
    Solo-terminal gate from this worktree before durable E2E. For human
    rendering, progress, prompts, or streaming output, run CLI reviewer PTY
    frame analysis before user inspection. Give the user a chance to inspect the
    VM-observed CLI behavior only after the reviewer has summarized the
    confidence basis or blocker, and before any live/release-candidate
    deployment. If this cannot be completed, report the blocker explicitly
    instead of widening the release path.
15. For VM/node/tool/package/doctor/role-baseline behavior, run the retained
   Incus inspection gate from this worktree before durable Incus E2E. Retained
   topologies sync the current worktree into a runner-host source mount and
   execute from each VM's runtime mirror, so they are suitable for real VM
   inspection. Release and verify cleanup before continuing.
16. Run focused in-memory verification for the active slice. For multi-slice
    features, do not spend full E2E on every internal slice by default; run the
    agreed E2E lane as the feature-level merge gate. For CLI behavior, durable
    E2E still follows the retained VM/user-verification checkpoint. For non-CLI
    behavior, proceed to the relevant E2E lane once code and focused tests are
    ready; no retained CLI confirmation gate applies.
17. Run the applicable reviewer persona from the `HARNESS.md` routing table once
    implementation evidence exists and before accepting the slice. For
    documentation-heavy changes, run `.agents/review-personas/docs-librarian.md`.
    For CLI command changes, run `.agents/review-personas/cli-command.md`.
    Resolve or explicitly report findings before commit.
    For small changed-files-only reviews, prompt the reviewer for a
    blockers-first verdict (`No blockers` or file/line blockers) before optional
    suggestions. Give Claude and comparable reviewers minute-scale read time
    when the output shows active file reads, tool use, or generation; do not
    interrupt productive review work just because it exceeds a short Solo wait
    deadline. Interrupt once with a verdict-only prompt only when the reviewer
    is clearly waiting for input, idle after loading context, or generating
    without useful progress for an extended window. If it still does not return,
    close or replace that reviewer and record the process finding through
    `harness-signals/` before proceeding only on defensible direct evidence.
18. Run artifact-backed feature verification when production artifact behavior
    matters and that lane exists for the provider.
19. Run provider provision gates only as final/nightly substrate verification
    when installer, host mutation, image, binary, or topology-preparation
    behavior changed. Docker provision is only for Docker artifact/image or
    Docker topology-preparer changes; do not run it as a generic post-`composer
    test:e2e` gate.
20. If PHP changed, run:

   ```bash
   vendor/bin/pint --dirty --format agent
   ```

21. Before reporting completion, run the project quality gate:

   ```bash
   composer quality-check
   ```

22. Before committing or reporting completion, inspect the timing evidence
    already produced by the applicable gates:

   ```bash
   composer quality-gate:final-check
   ```

    This final check must not rerun Pest, `composer quality-check`, Docker E2E,
    Incus E2E, provision gates, or live-node commands. If timing evidence is
    missing, report that the timing analysis was skipped and decide whether the
    feature needs another gate run before merge.
23. Before committing or reporting completion, run a Post-Feature Session
    Review. Review the feature thread or handoff, Solo worker sessions,
    reviewer output, retained terminal or PTY evidence when applicable,
    verification output, and human corrections. Identify mistakes found after a
    worker claimed or implied readiness, then decide whether they are local
    cleanup, already covered by existing `harness-signals/` records, or a new
    durable signal. Update, create, curate, retire, or intentionally leave
    `harness-signals/` records and the smallest guardrail target only for
    concrete durable signals. If no new durable signal remains, say that in the
    report. Re-run the narrow check that proves any changed guardrail target is
    reachable.
24. Commit the verified worktree changes on the worktree branch.
25. Merge the branch back into `main` from the primary `~/orbit` checkout,
    remove the completed worktree/branch, and leave `~/orbit` on updated
    `main`. Preserve unrelated dirty files in `~/orbit`; if they overlap with
    the merge, stop for direction instead of discarding them.
26. If release was explicitly agreed or specifically discussed as part of the
    crystallized scope, run the release flow after merge. Capture live topology
    `doctor` status before publishing a release, run `orbit update:all` after
    the release artifacts are accepted, run `doctor` again, compare the before
    and after results, and either fix regressions immediately or record scoped
    follow-up tasks for intentional migration work.
27. When the user confirms live topology behavior or explicitly says the
    feature is complete, run feature completion cleanup: archive the feature
    scratchpad, close or resolve related Solo todos, and stand down related
    Solo agents or retained terminals. Keep this scoped to the feature and
    report anything intentionally preserved.

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
    `/home/orbit/orbit-run` inside the retained ingress VM. Use
    `./apps/cli/orbit` for source-mounted proof unless you first verify that
    `/usr/local/bin/orbit` resolves to the source checkout; use the installed
    binary only for release-candidate or live-node proof. Give the user a
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
- Solo implementation workers perform substantive implementation edits; the
  feature owner orchestrates, reviews, verifies, commits, merges, cleans up, and
  reports.
- Give each worker one clear ownership boundary. If a boundary is hard to state,
  use one implementation worker serially instead of parallel workers.
- Apply documentation updates in the same implementation worktree as the related
  tests and code. Do not rely on a separate documentation-only implementation
  pass for feature work.
- Use a feature scratchpad for multi-slice feature intent, rough slice order,
  slice outcomes, and final gate notes. Keep Solo todos optional; create them
  only for asynchronous assignment, queueing, or explicit tracking outside the
  active orchestrator thread.
- Run the repo feedback loop from `.orbit/loop.md` during the active slice,
  creating it from `LOOP.md.example` when needed. `.orbit/loop.md` is
  current-slice state, not feature history; rewrite it when the next slice
  starts and preserve prior slice outcomes in the feature scratchpad. Every
  signal does not need a repository edit, but every durable signal needs either
  a curated `harness-signals/` record plus a guardrail target update in this
  worktree, or a scoped follow-up in the implementation report. Do not use
  `.orbit/loop.md` or `HARNESS_SIGNALS.md` as event logs. Curate stale or noisy
  signal records when they are in the owned scope; otherwise report a focused
  curation follow-up.
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
- Solo feature state does not stay open after the feature is accepted complete:
  archive the feature scratchpad, close or resolve related Solo todos, and stop
  or archive related Solo agents. Do this only after user/live acceptance, and
  do not touch unrelated Solo state.

## Report Shape

```markdown
## Implementation Report

Worktree:
- `.worktrees/<branch-name>` on branch `<branch>`

Solo implementation delegation:
- Worker(s): <model/tool, name/process id, and owned scope>
- Corrections requested: <summary or none>

Documenter/librarian delegation:
- Worker: <name/process id and owned docs scope, or not used>
- Docs contract status: <accepted, needs changes, blocked, or not applicable>

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

Reviewer personas:
- <persona path>: <findings resolved, findings deferred, not applicable, or blocked>

Post-feature session review:
- Evidence reviewed: <feature thread, Solo workers, reviewer output,
  terminal/PTY evidence, verification output, human corrections, or not
  applicable>
- Mistakes found after readiness claims: <summary or none>
- Existing signals covered: <harness-signals paths or none>
- New durable signal or guardrail change: <record/target and verification, or
  none>
- No-new-signal rationale: <why local cleanup was enough, or not applicable>

Tests:
- Pest unit/feature: <test added or changed>
- Pest E2E: <test added or changed>

Verification:
- `php artisan test --compact`: <result>
- Retained CLI ingress VM Solo-terminal check: <topology id, instance,
  terminal/session, command results, or not applicable>
- PTY frame analysis before user review: <artifact paths, launcher, exit code,
  max idle gap, cadence/transcript finding, or not applicable>
- User CLI verification: <confirmed, pending, blocked, or not applicable>
- `composer test:e2e` (or the appropriate ephemeral lane): <result>
- `composer quality-check`: <result>

Merge-back:
- Commit: <sha>
- Main checkout: `~/orbit` on `main` at <sha>
- Worktree cleanup: <removed or preserved with reason>

Feature completion cleanup:
- Completion trigger: <user live confirmation, user says complete, pending, or not applicable>
- Solo scratchpad: <archived, preserved with reason, none, or pending>
- Solo todos: <closed/resolved ids, preserved with reason, none, or pending>
- Solo agents/terminals: <stopped/archived ids, preserved with reason, none, or pending>

Blockers:
- <blocker or none>

Risks:
- <risk or none>

Next step:
- <next slice or next concrete action; do not leave this implicit>
```
