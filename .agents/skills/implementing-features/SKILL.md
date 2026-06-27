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
- If the request spans multiple slices, a feature roadmap scratchpad exists and
  its `solo://` URL is known before worktree setup or worker dispatch.
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
- Preserve the raw user contract in the handoff. If the user provided output
  samples, transcripts, screenshots, failure text, or negative examples, keep
  them in `.orbit/loop.md`, the feature scratchpad, or the worker prompt. When
  decomposing into slices, explicitly mark which parts are in scope now, which
  are deferred, and why the deferral is compatible with acceptance.
- Prepare and own the dedicated Orbit worktree.
- Read the handoff, docs, and existing code enough to define clear worker tasks.
- For multi-slice features, keep one feature scratchpad as the roadmap and one
  feature worktree as the execution boundary. Use `.orbit/loop.md` for the
  active slice only, rewriting it when the next slice starts. Before rewriting
  `.orbit/loop.md`, archive the completed active `.orbit/` state into the
  persistent project archive home defined by `HARNESS.md`, copying every active
  `.orbit/` entry except `.orbit/sessions/`. If the source roadmap lives in
  another Solo project or machine, create a reachable execution-project
  scratchpad that links back to the source and mirrors the source roadmap's
  feature request, slice order, current-slice acceptance criteria, deferred
  slices, and open decisions before spawning workers.
- Run a dependency scan before spawning workers. If slices or verification lanes
  have disjoint ownership and neither needs the other's result, dispatch them in
  parallel through Solo by default. Use one worker serially only when ownership,
  shared state, provider capacity, or merge order cannot be stated clearly.
- Grok is the default implementation model when available, but another suitable
  Solo-managed worker may own the slice if that is the active toolchain.
- Use the Solo role matrix in `HARNESS.md` when splitting work. Spawn a Claude
  documenter/librarian worker for substantial docs-first or documentation-heavy
  slices when its ownership is separable.
- Make each worktree-scoped Solo worker prove its starting directory and branch
  before broad reads or edits: `pwd` must be the assigned worktree and
  `git branch --show-current` must match the assigned branch. If Solo
  `spawn_agent` opens at the project root, relaunch through a Solo terminal
  that first `cd`s into the worktree.
- In parallel-worker mode, do not let workers run broad dirty-file formatters
  or fixers. Workers run owned-file-only formatting/checks; the feature owner
  runs broad dirty-file tooling after worker diffs are reconciled.
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
- Build the local post-feature packet before completion, request fresh-context
  post-feature analysis when the loop was non-trivial, and adjudicate analyzer
  findings before any durable guardrail is changed.
- Follow `HARNESS.md` for final-distillation, merge-boundary, post-feature
  signal-audit, session-archive, and cleanup policy. Use `LOOP.md.example` for
  the local packet shape and `bin/orbit-feature-finalization-check` for
  executable gate usage.
- Own final commit, merge-back, preserved-worktree post-analysis handoff,
  feature completion cleanup, and the implementation report.

Do not make substantive implementation edits yourself unless the user explicitly
approves bypassing the Solo worker lane. Small orchestration-only edits,
conflict resolution after review, and mechanical formatting fixes are acceptable
only when they do not replace the tracked worker loop.

## Codex App To Solo Orchestrator Handoff

When implementation starts from the Codex app or another non-Solo-visible
discussion surface, the app session is a handoff owner by default. Its job is to
turn the crystallized request into a Solo-tracked implementation loop, not to
stay as the long-running feature owner.

Use this mode unless the user explicitly asks the current app session to keep
watching or to bypass the Solo orchestrator handoff:

1. Preserve the raw user feature contract, acceptance criteria, explicit
   deferrals, and source thread pointer in the feature scratchpad or the
   handoff prompt.
2. Prepare the dedicated worktree with `bin/orbit-prepare-worktree`.
3. Spawn a Solo-managed Codex orchestrator for the same Solo project.
4. Record the Solo project id, orchestrator process id/name, worktree path,
   branch, source thread pointer, and prompt/scratchpad pointer in the feature
   scratchpad and `.orbit/loop.md` when that file exists.
5. Send the Solo Codex orchestrator a complete implementation prompt.
6. After prompt delivery and a visible first checkpoint or explicit delivery
   blocker, report the handoff result and stop. Resume only if the user asks for
   watcher or recovery work.

The Solo Codex orchestrator continues this same skill from Orchestrator Role.
It owns `.orbit/loop.md`, worker planning, implementation delegation, review,
verification, finalization, merge-back, and cleanup. It must spawn
implementation, documenter, reviewer, analyzer, and retained-terminal lanes
from inside Solo so descendant processes are linked under its
`parent_process_id`. That Solo process tree is the telemetry root for session
token extraction and post-analysis.

Use this prompt shape:

```text
<prepend the agent_instructions returned by Solo spawn_agent>

You are the Solo-managed Codex feature orchestrator for one Orbit
implementation loop. Continue .agents/skills/implementing-features/SKILL.md
from Orchestrator Role.

Solo identity:
- Solo project id: <project id>
- Orchestrator process id: <process id returned by Solo>
- Source discussion: <Codex thread id/transcript pointer or none>
- Feature scratchpad: <solo://... or none>

Worktree:
<absolute path to .worktrees/<branch-name>>

Branch:
<branch-name>

Raw feature contract:
<paste the crystallized request, acceptance examples, transcripts, screenshots,
negative examples, and explicit deferrals>

Rules:
- First prove `pwd`, `git status --short --branch`, and the active Solo process
  identity before broad reads or edits.
- Keep all implementation work inside the assigned worktree.
- Treat this process id as the telemetry root. Record it in `.orbit/loop.md`
  and the feature scratchpad, then spawn all child workers/reviewers/terminals
  from inside Solo so they inherit this process as `parent_process_id`.
- Fill the active Done Contract before implementation worker dispatch.
- Follow the normal Solo worker, TDD, review, verification, finalization,
  merge-back, and cleanup gates in this skill without weakening them.
- Do not ask the source Codex app session to keep coordinating unless delivery,
  Solo identity, or worktree setup is blocked.

Report the first checkpoint with the recorded process id, worktree, branch,
Done Contract location, candidate worker plan, and any blocker.
```

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

Raw acceptance examples and explicit deferrals:
<paste the concrete user-provided output samples, transcripts, failure text,
negative examples, or state `none`; list deferred parts with reason and owner>

Rules:
- Edit only inside the assigned worktree.
- If a feature scratchpad exists, read its slice outcomes before editing and
  treat `.orbit/loop.md` as the current slice contract, not feature history.
- Inspect the current branch diff or log enough to understand prior slices that
  already exist in this feature worktree.
- Keep docs, tests, and code aligned.
- Do not silently drop a user-provided sample, transcript, or negative example
  when turning the request into a slice. If an example cannot be implemented in
  the current slice, report the exact deferred part before editing.
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
- For CLI command changes, create or update Pest coverage first. After the
  focused command behavior is working, run or request retained topology proof
  from the relevant VM before any live/release-candidate deployment. Report the
  topology id, VM instance, terminal/session, exact commands, observed
  human/JSON/failure results, and whether user verification is pending or
  complete.
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

## Post-Feature Analyzer Delegation

Use a fresh-context analyzer before commit, merge-back, final reporting, or
post-feature signal audit when the feature loop used implementation workers,
reviewer corrections, retained terminal or PTY proof, quality-gate artifacts,
human steering, guardrail decisions, or any other non-trivial evidence. Skip
only for tiny local changes where `.orbit/loop.md` can honestly record that no
worker, reviewer, terminal, quality-gate, guardrail, or human-correction
evidence exists.

First write a small local packet under `.orbit/evidence/` or the final section
of `.orbit/loop.md`. Do not commit the packet. Include objective, final diff or
commit, verification results, the Solo orchestrator process id when the feature
was handed off from the Codex app, worker/reviewer ids or summaries, terminal or
PTY artifacts, quality-gate evidence, human corrections, and factual
orchestrator steering notes. Include the Codex thread id or transcript path for
the feature orchestrator when available, plus Solo scratchpads or process ids
needed to inspect worker and reviewer reports.

Spawn a fresh Solo-managed analyzer as Claude Opus at medium effort: discover
the enabled `Claude` tool with `list_agent_tools`, then `spawn_agent` with
`extra_args=["--model", "opus", "--effort", "medium"]`. If Claude Opus is not
available through Solo, stop and report the blocker instead of substituting
another model. Give it only the packet, Codex/Solo session pointers, changed
diff, relevant harness docs, and named evidence pointers. Use
`.agents/review-personas/post-feature-analyzer.md`. The analyzer reports
whether the loop was performed properly and classifies guardrail decisions as
`correct-noop`, `missed`, `redundant`, `wrong-target`, or `defer`. It must not
steer live work, edit code, update `harness-signals/`, change skills, approve
merge, or run cleanup.

The analyzer prompt must start with a checkout-proof block before any file
reads or review:

```text
cd <absolute worktree path>
pwd
git branch --show-current
git status --short --branch
```

Require the analyzer to print `CHECKOUT_PROOF` with the resulting path, branch,
and status before reading the packet or diff. The feature owner must inspect that
proof before trusting the analyzer report. If the proof is missing or points at
`~/orbit`, `main`, or any checkout other than the assigned worktree, interrupt
once with the exact proof commands and require a verdict from already gathered
evidence after the proof. If the analyzer still does not produce a verdict,
close or replace it, record the analyzer lane as blocked, and continue only from
defensible direct evidence.

The feature owner accepts, rejects, or defers the analyzer recommendation using
full session context. A durable guardrail is allowed only when the candidate has
a concrete mistake or late catch, recurrence risk, an existing guardrail gap, a
counterfactual prevention path, the smallest useful target, and a narrow
verification. `No durable guardrail needed` is a valid result.

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
Do not use `--with-e2e` for feature work. CLI command changes require focused
Pest coverage plus retained topology proof when topology behavior is in scope.
The script creates or reuses `.worktrees/<branch-name>/`, installs
dependencies, prepares Laravel app state, links `.env.e2e` when available, and
runs `composer test` unless `--skip-tests` is explicitly passed.

If preparation fails, leave the worktree in place for inspection and report the
failing step instead of dispatching implementation onto a broken checkout.

## E2E Readiness And Resource Pool

This section is for reading shared E2E provider state and existing artifacts
only. Agents must not run `composer test:e2e*` commands. Normal feature
completion uses retained topology proof instead of prepared E2E.

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

When inspecting a user-run E2E lane in a worktree:

1. `composer e2e:preflight` — verifies the configured Incus host is
   reachable. This is a manual diagnostic command; do not run it as an agent
   unless the task is retained topology setup rather than E2E testing.

2. Treat the provider pool as a blocking lease pool. If the user reports a long
   wait at startup, it usually means every configured slot is currently leased
   by another worktree. Do not reap merely because resources exist on a
   provider.

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

4. If the shared pool is busy, report that the user-run command can wait or
   reduce only that command's temporary demand. For Docker, the user can pass a
   smaller `ORBIT_E2E_DOCKER_TEST_RUNNERS` value or
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

Retained Incus is the mandatory hands-on verification step whenever the change
touches real VM/node behavior: OS package installs, role baselines, doctor
restore, tool repair, SSH/sudo, WireGuard, systemd, host mutation, gateway API
shims, source-mounted checkout behavior, or anything that previously failed
only inside an Incus topology.

Any feature that creates or changes a CLI command must also pass a retained
ingress VM Solo-terminal gate before live/release-candidate deployment. This
includes new commands, flags, options, arguments, human output, JSON schemas,
validation, prompts, command side effects, and command-family behavior.
CLI retained topology proof must run in a Solo terminal, not only through a
detached host command or captured artifact. Keep that Solo terminal open
through feature completion and leave it available afterward. The preserved
terminal lets the user later validate the addressed CLI commands and their output.
The retained topology may be reaped after feature completion, but the Solo
terminal stays preserved as the validation anchor.

For CLI changes, use this ordering:

1. Create or update focused Pest tests for the command contract.
2. Implement the smallest slice that makes the focused command behavior work.
3. Spawn or request a Solo terminal.
4. Acquire a retained Incus topology with the relevant source-mounted VM.
5. Verify which Orbit launcher will be exercised. For source-mounted retained
   topology proof, run the command through the source checkout
   (`./apps/cli/orbit` from `/home/orbit/orbit-run`) or prove that
   `/usr/local/bin/orbit` resolves to that source checkout. For
   release-candidate or live-node proof, use the installed binary path being
   validated.
6. Open an interactive shell inside the relevant retained VM, usually the
   ingress or operator VM, before running the changed command. The Solo
   terminal should land at `/home/orbit/orbit-run` inside the VM so the user can
   watch progress, spinners, blinking indicators, prompts, and streaming output
   while the command runs.
7. Run or request CLI reviewer PTY frame analysis from the same runtime context
   before asking the user to inspect human UX/output. For retained Incus proof,
   prefer running the capture script inside the Solo terminal shell attached to
   the target VM. The reviewer inspects `summary.txt`, `chunks.jsonl`, and
   `transcript.txt` for cadence, liveness, skipped frames, wrapping, ANSI
   framing, and final shape.
8. Prove the observed human output, JSON output, prompts, failure paths, and
   side effects that changed.
9. Give the user a chance to inspect and confirm the VM-observed CLI behavior
    only after the reviewer has summarized the PTY confidence basis or exact
    remaining mismatch.
10. Then run broader quality or release-candidate flow when applicable.

Do not spend the live topology or release-candidate path on a CLI change before
this retained VM proof and user verification point. Do not treat retained Incus
as optional "extra confidence" for those changes. The retained check is the
operator-facing proof gate for feature completion.

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

`--sync` refreshes files on disk but does not reload the long-running gateway
API container. After a sync, the gateway lease container keeps serving the
pre-sync code and returns HTTP 500 on every gateway call (including paths the
change never touched, e.g. single-node `doctor`), which reads as a product
regression but is not one. Restart the gateway lease containers on the gateway
VM before re-verifying:

```bash
ssh <incus-host> 'incus exec <gateway-instance> -- bash -lc \
  "docker restart orbit-gateway-e2e-topology-lease-http orbit-gateway-e2e-topology-lease-tls"'
```

Then re-run the changed command. A 500 on an unrelated gateway path right after
`--sync` is a stale-container signal, not a code defect — restart first, then
classify.

Record the retained topology id, topology kind, checkout roles, inspected
instances, Solo terminal or fallback session, commands run, and observed result
in the Solo report. If you mutate state for a focused check, isolate unrelated
prepared-state drift first and say what was isolated.

After feature completion, release and verify retained topology cleanup:

```bash
composer e2e:incus -- --stop --id=<id> --json
ssh <incus-host> 'incus list --format csv -c ns | grep <id> || true'
```

If `--stop` misses owned instances because the retained manifest is incomplete,
delete only the owned leftovers by exact instance name, verify the grep is
empty, and report the cleanup anomaly. Do not close or archive the Solo terminal
as part of topology cleanup; preserve it for later user validation of the
command address/output transcript.

## Workflow

1. Confirm the request is moving an already-crystallized feature or bug fix into
   implementation. Use the crystallized discussion, handoff, Solo scratchpad, or
   Solo todo as the concrete implementation handoff. If the feature contains
   multiple slices, use one lightweight scratchpad as the feature roadmap and
   one worktree as the execution boundary. Preserve raw user-provided output
   samples, transcripts, screenshots, failure text, and negative examples in
   the scratchpad or `.orbit/loop.md` before narrowing the scope. Create the
   active slice Done Contract and proceed as feature owner. When execution is
   delegated to a different Solo project or machine than the source handoff,
   create a local execution-project roadmap scratchpad that links the source
   and mirrors the feature request, slice order, current-slice acceptance
   criteria, deferred slices, and open decisions. Do not prepare the worktree or
   spawn workers for multi-slice work until the feature roadmap scratchpad URL
   and mirrored roadmap contents are recorded. Do not route through retired
   orchestration skills.
2. Set up the workspace with `bin/orbit-prepare-worktree`.
3. If implementation started from the Codex app or another non-Solo-visible
   discussion surface, use Codex App To Solo Orchestrator Handoff now. The
   Solo-managed Codex orchestrator resumes this workflow from the assigned
   worktree. The app handoff session stops after delivery and first-checkpoint
   proof or an explicit delivery blocker.
4. Read the handoff, `AGENTS.md`, `HARNESS.md`, `LOOP.md.example`,
   `HARNESS_SIGNALS.md`, `harness-signals/README.md`,
   `PRODUCT_DECISIONS.md`, relevant product docs under `apps/docs/content/**`,
   and relevant session context under `docs/superpowers/**`. Copy
   `LOOP.md.example` to `.orbit/loop.md` for the active slice and fill the Done
   Contract before implementation. For multi-slice work, the top of
   `.orbit/loop.md` must link the feature roadmap scratchpad before any
   implementation worker is spawned. If this is a later slice in the same feature
   worktree, rewrite `.orbit/loop.md` for the current slice and keep earlier
   slice outcomes in the feature scratchpad. The Done Contract must include raw
   acceptance examples or a precise pointer to them, plus explicit deferrals for
   any part of the user's request that is not in the current slice.
5. Confirm owned files or domains and existing dirty work before editing.
6. Decide the Solo worker plan from `HARNESS.md`. First list candidate slices,
   verification lanes, owned files, provider resources, shared temp/state paths,
   and dependencies in `.orbit/loop.md`, the feature scratchpad, or the worker
   plan. A serial plan for isolated goals, slices, or lanes is incomplete unless
   it names the concrete dependency, shared state, provider capacity limit, or
   merge-order reason. Being part of one goal or feature is not a dependency.
   If two tasks are isolated, dispatch them in parallel through Solo. Treat
   in-memory/Pest optimization and `composer quality-check` optimization as
   separate lanes by default. Do not split out Docker E2E or Incus E2E lanes;
   agents do not run or delegate `composer test:e2e*` commands. Do not overlap
   full `composer quality-check` with active user-run provider E2E lanes unless
   shared E2E support state is proven isolated. In parallel-worker mode, forbid broad
   dirty-file formatters/fixers inside workers; scope formatting/checks to the
   worker's owned files and run broad dirty-file tooling only after worker diffs
   are reconciled. Add a Claude documenter/librarian worker when documentation
   is substantial and the docs-owned surface is clear.
7. If a Claude documenter/librarian worker is used, spawn it first or in
   parallel only after the docs-owned slice is explicit. The feature owner must
   inspect the docs result and accept the docs contract before code relies on it.
8. Spawn the Solo implementation worker(s) with the worktree path, handoff,
   owned scope, documentation authority, TDD requirement, focused verification,
   and the rule that workers must not commit, merge to `main`, or clean up the
   worktree unless the feature owner explicitly assigns that exact step. Require
   each worker's first command to prove `pwd` and `git branch --show-current`.
   If the agent starts in `~/orbit` or another checkout, stop it and relaunch
   through a Solo terminal that `cd`s into the assigned worktree before starting
   the agent.
9. Monitor workers, inspect diffs, and send correction prompts until the
   acceptance criteria are met or a blocker is explicit. Worker handoffs must
   request the complete owned outcome in one continuous feature loop: align docs
   when needed, create the first failing test or docs diff, implement the fix,
   run focused verification, and report evidence or a blocker. Do not split the
   worker handoff into staged-stop-only prompts, and do not ask the worker to
   stop after "step 1" or wait for routine feature-owner approval between
   normal phases. The first narrow owned diff or explicit missing-context
   blocker is still expected after the required local files are read. Broad
   discovery without that diff or blocker is a process problem to correct.
   After one explicit first-diff correction, if the worker still produces no
   diff or blocker, stand down the worker, mark the matching harness signal
   recurring, and replace the worker instead of letting the process stall. If a
   replacement worker also fails on a tiny known patch shape, the feature owner
   may apply the first test or docs diff directly as a documented loop
   exception, then update the matching signal before continuing. When a
   correction reveals missing durable context, triage it through
   `.orbit/loop.md` and `HARNESS_SIGNALS.md`. If a reviewer or human points to
   behavior already present in the raw request, reclassify it as a blocking
   contract gap unless it was explicitly deferred before editing.
10. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs. Use the Claude documenter/librarian for substantial
   docs-owned corrections; otherwise keep docs corrections with the worker that
   owns the related behavior.
11. For every failed verification, review comment, human correction, docs
   conflict, setup problem, or agent mistake encountered during the slice,
   search `harness-signals/` for related prior records, then decide whether it
   is local cleanup or a durable harness signal. If the search returns stale,
   overlapping, or noisy records and curation belongs to this slice, update,
   consolidate, retire, or delete the records using `harness-signals/README.md`.
   If it is durable and belongs to the current slice, create or update the
   signal record and update the smallest guardrail target in the same worktree.
   If it is durable but broader than the slice, report a scoped follow-up
   instead of expanding ownership.
12. Check whether the project-owned Orbit skill under `.agents/skills/orbit/**` is
   affected. Update it in the same worktree when the change alters public CLI
   behavior, command signatures, node roles, state families, app/workspace
   runtime behavior, deployment/profile/update flows, or operational guidance
   another LLM would need to use Orbit correctly.
13. Ensure the implementation worker followed TDD (see Test-Driven Development
    below): failing Pest tests first, literal failing output captured, then
    implementation. A narrative claim that a test failed is not sufficient when
    the failure was used to prove red.
14. Keep the smallest working vertical slice that makes the tests pass.
15. For CLI command behavior, run retained topology proof from this worktree
    when the behavior touches topology, VM runtime, or operator-visible command
    execution. For human rendering, progress, prompts, or streaming output, run
    CLI reviewer PTY frame analysis before user inspection. Give the user a
    chance to inspect the VM-observed CLI behavior only after the reviewer has
    summarized the confidence basis or blocker, and before any
    live/release-candidate deployment. If implementation changes after PTY or
    retained-VM evidence was captured, refresh the evidence in a new artifact
    directory and label older artifacts as stale in the report. If this cannot
    be completed, report the blocker explicitly instead of widening the release
    path.
16. For VM/node/tool/package/doctor/role-baseline behavior, run the retained
   Incus inspection gate from this worktree. Retained
   topologies sync the current worktree into a runner-host source mount and
   execute from each VM's runtime mirror, so they are suitable for real VM
   inspection. Release and verify cleanup before continuing.
17. Run focused in-memory verification for the active slice. For multi-slice
    features, do not spend prepared E2E on every internal slice by default. Run
    retained topology proof as the feature-level topology gate when the final
    branch diff needs real topology evidence. When retained topology proof is
    required for feature acceptance and cannot be completed, stop the loop if
    the blocker cannot be resolved inside this slice. Do not commit, merge,
    clean up, or run final loop-improvement extraction while required retained
    topology proof is still blocked; record the blocker, owner, and unblock
    condition in `.orbit/loop.md` and the report.
18. Run the applicable reviewer persona from the `HARNESS.md` routing table once
    implementation evidence exists and before accepting the slice. For
    documentation-heavy changes, run `.agents/review-personas/docs-librarian.md`.
    For CLI command changes, run `.agents/review-personas/cli-command.md`.
    Spawn any code-reviewer or CLI-reviewer persona as Claude Opus through Solo
    at medium effort: discover the enabled `Claude` tool with
    `list_agent_tools`, then `spawn_agent` with
    `extra_args=["--model", "opus", "--effort", "medium"]`. If Claude Opus is
    not available through Solo, stop and report the blocker instead of
    substituting another model.
    Resolve or explicitly report findings before commit.
    If the reviewer finds a mismatch between implementation evidence and the
    raw user examples, treat it as a contract mismatch first; only downgrade it
    to a follow-up when the Done Contract already names the deferral.
    For small changed-files-only reviews, prompt the reviewer for a
    blockers-first verdict (`No blockers` or file/line blockers) before optional
    suggestions. Every reviewer prompt must begin with the same checkout-proof
    block required for post-feature analyzers: `cd <absolute worktree path>`,
    `pwd`, `git branch --show-current`, and `git status --short --branch`.
    Require a `CHECKOUT_PROOF` line before the reviewer reads files or returns a
    verdict. Include the exact diff command and base the reviewer should inspect,
    such as `git diff HEAD -- <owned files>` for an uncommitted post-review fix,
    instead of leaving the reviewer to choose a broad branch comparison. Give
    Claude and comparable reviewers minute-scale read time when the output shows
    active file reads, tool use, or generation; do not interrupt productive
    review work just because it exceeds a short Solo wait deadline. Interrupt
    once with a proof-and-verdict prompt only when the reviewer is in the wrong
    checkout, clearly waiting for input, idle after loading context, or
    generating without useful progress for an extended window. If it still does
    not return, close or replace that reviewer and record the process finding
    through `harness-signals/` before proceeding only on defensible direct
    evidence.
    Reviewer findings fixed before merge are normal feature-loop work. Promote
    them to loop improvements only when the review process itself missed a
    recurring class of issue, or existing reviewer guidance would not catch the
    same issue next time.
19. Do not run artifact-backed E2E verification. If the user manually runs an
    artifact-backed E2E command, record the provided output or existing
    `.orbit/quality-gates/` artifact.
20. Do not run provider provision gates. If the user manually runs a provider
    provision command, record the provided output or existing artifact and keep
    it separate from the retained topology feature gate.
21. If PHP changed, run:

   ```bash
   vendor/bin/mago format --check
   ```

22. Before reporting completion, run the verification gate implied by the final
    diff:

   ```bash
   composer docs-lint       # docs-only markdown diffs
   composer quality-check   # PHP or broader repo diffs
   ```

    `HARNESS.md` -> `Merge Boundary Gate` is the authority for the exact
    finalization requirement. The gate reads the branch diff and existing
    `.orbit/quality-gates/` artifacts: docs-only diffs need successful
    docs-lint or broader quality-check evidence, other diffs need successful
    quality-check evidence, and production PHP diffs also need retained
    topology proof. Do not rerun expensive gates from the finalization check.

23. Before committing or reporting completion, inspect the timing evidence
    already produced by the applicable gates:

   ```bash
   composer quality-gate:final-check
   ```

    This final check must not rerun Pest, `composer quality-check`, Docker E2E,
    Incus E2E, provision gates, or live-node commands. If timing evidence is
    missing, report that the timing analysis was skipped and decide whether the
    feature needs another gate run before merge.
    Finalization reads the latest matching `.orbit/quality-gates/` artifacts
    for docs-lint and quality-check rows and blocks missing or non-zero evidence
    without rerunning the gates. Retained topology proof is recorded in
    `.orbit/loop.md` or `.orbit/evidence/`; the finalization hook validates the
    row status but does not run topology commands.
24. Before committing or reporting completion, run a Post-Feature Session
    Review. Treat `.orbit/loop.md` as the canonical local final packet and
    point it at the feature thread or handoff, Solo worker sessions, reviewer
    output, retained terminal or PTY evidence when applicable, verification
    output, human corrections, and factual orchestrator steering notes. Record
    candidate signals as they appear so final review classifies an existing
    packet instead of reconstructing the session. Include the orchestrator
    Codex thread id or transcript path when available. For non-trivial loops,
    spawn a fresh post-feature analyzer with
    `.agents/review-personas/post-feature-analyzer.md`; for tiny local changes,
    fill the `.orbit/loop.md` final-distillation section with the no-analysis
    rationale. Use the analyzer report to classify each guardrail decision as
    correct-noop, missed, redundant, wrong-target, or deferred; then classify
    each candidate as local cleanup, already covered by existing
    `harness-signals/` records, rejected, deferred, or a new durable signal.
    The feature owner adjudicates the recommendation using session context.
    Before creating a new guardrail, check whether a later slice already
    absorbed the lesson in code, tests, docs, skills, signal records, or clearer
    failure messages; if so, classify it as `already-covered` and name that
    coverage. Eliminate
    one-off handoffs, stale historical artifacts, reviewer findings fixed
    before merge, and ordinary feature work before considering promotion.
    Required verification that cannot be completed is a `blocked` feature-loop
    outcome first, not a candidate signal; promote it only when the blocker
    exposes a recurring process gap. Fill `.orbit/loop.md` `Required
    verification` with explicit `passed`, `blocked`, or `not applicable` rows
    for retained topology proof and `composer quality-check`. A passed retained
    topology row must name the topology id/kind, inspected roles or nodes, exact
    command, and captured terminal/session or artifact evidence. Do not mark the
    loop `complete` or `complete + loop improvement`
    while any diff-required verification is blocked, pending, skipped, missing,
    deferred, unresolved, or not run. Update, create, curate, retire, or intentionally
    leave `harness-signals/` records and the smallest guardrail target only for
    concrete durable signals that pass the promotion gate in
    `HARNESS_SIGNALS.md`. Report the loop outcome as `complete`, `blocked`, or
    `complete + loop improvement`. If no new durable signal remains, say that
    in `.orbit/loop.md` and the report. Re-run the narrow check that proves any
    changed guardrail target is reachable. After final distillation is complete,
    archive the completed active `.orbit/` state into the persistent project
    archive home before cleanup and before any later `.orbit/loop.md` rewrite.
    Copy every active `.orbit/` entry except `.orbit/sessions/`.
25. Commit the verified worktree changes on the worktree branch.
26. Merge the branch back into `main` from the primary `~/orbit` checkout by
    following `HARNESS.md` merge and cleanup boundaries. Leave `~/orbit` on
    updated `main`. Preserve unrelated dirty files in `~/orbit`; if they
    overlap with the merge, stop for direction instead of discarding them.
27. If release was explicitly agreed or specifically discussed as part of the
    crystallized scope, run the release flow after merge. Capture live topology
    `doctor` status before publishing a release, run `orbit update:all` after
    the release artifacts are accepted, run `doctor` again, compare the before
    and after results, and either fix regressions immediately or record scoped
    follow-up tasks for intentional migration work.
28. When the user confirms live topology behavior or explicitly says the
    feature is complete, run feature completion cleanup: archive the feature
    scratchpad, close or resolve related Solo todos, and stand down related
    Solo agents or retained terminals. If the completed active `.orbit/` session
    has not already been archived, archive it per `HARNESS.md` before cleanup.
    Keep this scoped to the feature and report anything intentionally preserved.

## Test-Driven Development

Orbit is a TDD project. Every behavior change ships with Pest coverage that
fails before the implementation lands and passes after. No exceptions for
"trivial" changes — if behavior is worth changing, it is worth a test.

Normal feature work follows a staged verification model:

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
    behavior. For VM/node/tool/package/doctor/role-baseline changes, this
    retained check is a required feature-completion gate, not an optional
    diagnostic.
  - CLI command changes always use the retained ingress VM Solo-terminal gate
    after focused Pest coverage and before live/release-candidate deployment.
    Prove the command behavior manually from `/home/orbit/orbit-run` inside the
    retained ingress VM. Use
    `./apps/cli/orbit` for source-mounted proof unless you first verify that
    `/usr/local/bin/orbit` resolves to the source checkout; use the installed
    binary only for release-candidate or live-node proof. Keep that Solo
    terminal open through feature completion, leave it available afterward, and
    give the user a chance to inspect it.
  - Release retained topologies with `composer e2e:incus -- --stop --id=<id>`
    or `composer e2e:incus -- --stop --all` when finished.
- **Source-prepared feature E2E** via `composer test:e2e` or a provider lane
  (`composer test:e2e:docker` / `composer test:e2e:incus`) is manual-only. The
  user may run these commands from a shell; agents must not run or delegate
  them. These lanes consume prepared topologies containing the current source
  and write timing artifacts when the user runs them.
- **Artifact-backed feature E2E** is also manual-only. When the user runs it,
  it consumes the built CLI binary and gateway image.
- **Provider provision gates** are manual-only substrate verification commands.
  Docker provision refreshes Docker runtime/support images, prepared role
  images, Docker host artifact distribution, or Docker topology-preparation
  behavior. Incus provision proves the fresh VM path: installer, `node:new`,
  base image, WireGuard, systemd, package installation, and host mutation.
  Agents do not run provider provision commands or the aggregate
  `composer test:e2e:provision`. There is no standing live-node lane; see
  `apps/docs/content/testing/README.md` for the full lane map.

Workflow per change:

1. Write the failing Pest test(s) first for the contract. Do not add, update, or
   run E2E tests unless the user's request is specifically about maintaining the
   E2E test suite itself; even then, do not execute `composer test:e2e*`.
2. Run them and capture the exact command and failing output that proves they
   fail for the expected reason. Store it in `.orbit/loop.md`, the feature
   scratchpad, or `.orbit/evidence/` when the red state matters to review.
3. Implement the smallest slice that turns them green.
4. Re-run the smallest staged lane that proves the changed behavior before
   reporting completion.

A feature is not done until the relevant in-memory checks and retained topology
proof pass. Provider provision gates are not part of agent-run feature
verification.

## Implementation Rules

- Prefer existing Orbit and Laravel patterns.
- Treat current docs as product authority.
- Keep docs, tests, and code aligned.
- Solo implementation workers perform substantive implementation edits; the
  feature owner orchestrates, reviews, verifies, commits, merges, cleans up, and
  reports.
- Give each worker one clear ownership boundary. If multiple boundaries are
  clear and independent, use parallel workers. If a boundary is hard to state,
  use one implementation worker serially instead.
- Apply documentation updates in the same implementation worktree as the related
  tests and code. Do not rely on a separate documentation-only implementation
  pass for feature work.
- Use a feature scratchpad for multi-slice feature intent, rough slice order,
  slice outcomes, and final gate notes. Keep Solo todos optional; create them
  only for asynchronous assignment, queueing, or explicit tracking outside the
  active orchestrator thread. Missing a feature scratchpad before multi-slice
  worker dispatch is a process miss; a cross-project scratchpad that only links
  the source without mirroring the roadmap substance is also a process miss.
  Pause, create or fix the scratchpad, and record the correction in
  `.orbit/loop.md` final distillation.
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
  changes. `.agents/skills/orbit/SKILL.md` is the concise external-LLM entry point;
  `.agents/skills/orbit/references/*.md` carries command-family detail. If a change
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

Raw contract:
- User-provided examples/transcripts preserved at: <.orbit/loop.md,
  scratchpad section, prompt excerpt, or none>
- Explicit deferrals: <deferred request parts with reason/owner, or none>

Solo implementation delegation:
- Orchestrator: <model/tool, name/process id, Solo project id, parent/source
  thread pointer, or not handed off>
- Worker(s): <model/tool, name/process id, parent process id when available,
  and owned scope>
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
- Loop outcome: <complete, blocked, or complete + loop improvement>
- Required verification:
  - Retained topology proof: <passed, blocked, or not applicable with topology
    id/kind, inspected roles or nodes, exact command, terminal/session/artifact
    evidence, blocker, or reason>
  - `composer quality-check`: <passed, blocked, or not applicable with
    command/evidence, blocker, or reason>
- Finalization gate fit: <why the branch diff makes docs-lint, quality-check,
  and retained topology proof passed, blocked, or not applicable>
- Distillation packet: <.orbit/loop.md canonical packet, not applicable, or
  blocked>
- Fresh post-feature analyzer: <persona/process id/verdict, not used with
  rationale, or blocked>
- Evidence reviewed: <feature thread, Solo workers, reviewer output,
  terminal/PTY evidence, verification output, human corrections, or not
  applicable>
- Session archive: <path/status, not yet needed before cleanup or loop rewrite,
  or blocked>
- Mistakes found after readiness claims: <summary or none>
- Candidate classifications: <promote/already-covered/reject/defer summary, or
  none>
- Existing signals covered: <harness-signals paths or none>
- Accepted durable signal or guardrail change: <record/target and verification,
  or none>
- Rejected or already-covered signals: <summary and rationale, or none>
- Deferred follow-ups: <scoped follow-up, owner, trigger, or none>
- No-new-signal rationale: <why local cleanup, existing guardrails, rejection,
  or deferral was enough, or not applicable>

Tests:
- Pest unit/feature: <test added or changed>
- Pest E2E: <not applicable, or suite-maintenance diff without execution>
- Red-test evidence: <literal failing command/output path or not applicable>

Verification:
- `php artisan test --compact`: <result>
- Retained topology proof: <topology id/kind, instance or node, terminal/session
  or artifact, command results, or not applicable>
- PTY frame analysis before user review: <artifact paths, launcher, exit code,
  max idle gap, cadence/transcript finding, or not applicable>
- User CLI verification: <confirmed, pending, blocked, or not applicable>
- `composer quality-check`: <result>

Merge-back:
- Commit: <sha>
- Main checkout: `~/orbit` on `main` at <sha>
- Worktree cleanup: <status per `HARNESS.md` Feature Cleanup>

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
