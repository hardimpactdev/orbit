---
name: implementing-features
description: Use when the user says to implement a crystallized Orbit feature or bug fix, or when implementing an Orbit feature, bug fix, command behavior change, documentation update, project Orbit skill sync, or scoped Solo todo handoff through Solo-managed workers.
---

# Implementing Features

## Overview

Implement a scoped Orbit change from a crystallized discussion, handoff, Solo
scratchpad, or Solo todo by acting as the feature owner. The feature owner may
run in Codex CLI, the Codex app, or another capable LLM surface. Solo is
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
- Preserve accepted design or panel adjudications verbatim or by a precise
  pointer in feature-owner and worker handoffs, and name them in the active Done
  Contract Reviewer checks when they exist.
- Prepare and own the dedicated Orbit worktree. `bin/orbit-prepare-worktree`
  seeds `.orbit/loop.md` when it is missing; the feature owner enriches that
  seeded packet with source context and the active Done Contract before worker
  dispatch.
- Read the handoff, docs, and existing code enough to define clear worker tasks.
- For multi-slice features, keep one feature scratchpad as the roadmap and one
  feature worktree as the execution boundary. Use `.orbit/loop.md` for the
  active slice only, rewriting it when the next slice starts. Archive policy,
  naming, and cross-project roadmap mirroring are owned by `HARNESS.md` ->
  `Session Archives` and `Feature Slices`; `bin/orbit-session-archive`
  generates and enforces the archive directory name.
- Run a dependency scan before spawning workers. If slices or verification lanes
  have disjoint ownership and neither needs the other's result, dispatch them in
  parallel through Solo by default. Use one worker serially only when ownership,
  shared state, provider capacity, or merge order cannot be stated clearly.
- Grok is the default implementation model when available, but another suitable
  Solo-managed worker may own the slice if that is the active toolchain.
- Use the Solo role matrix in `HARNESS.md` when splitting work. Spawn a Codex
  documenter/librarian worker for substantial docs-first or
  documentation-heavy slices when its ownership is separable.
- Pin each worktree-scoped worker's working directory using the canonical
  `HARNESS.md` -> `Solo Role Matrix` spawn recipe, then require the worker to
  prove `pwd` and branch before broad reads or edits. If launch pinning fails,
  relaunch through a Solo terminal that first `cd`s into the worktree.
- In parallel-worker mode, do not let workers run broad dirty-file formatters
  or fixers. Workers run owned-file-only formatting/checks; the feature owner
  runs broad dirty-file tooling after worker diffs are reconciled.
- Keep docs, tests, and code for the same behavior in one worker by default.
  Split documentation and code only when the feature owner can accept the docs
  contract before code relies on it, or when ownership is genuinely independent.
- Monitor worker output, inspect diffs, ask for corrections, and keep unrelated
  dirty files untouched.
- When a Solo worker, reviewer, analyzer, or disposable terminal is no longer
  needed, serialize cleanup: capture required output or summary evidence,
  verify the artifact exists and is non-empty or record why no output is
  expected, then stop or delete the process in a separate command. Never run
  output capture and process deletion in parallel.
- Run or require the verification gates and independently read the results.
- For native Orbit Agent changes under `apps/agent`, resolve live topology to
  the implementing macOS host. On Darwin, record the `Retained topology proof`
  row as `passed - host topology kind=host-macos; host=<hostname>; os=<Darwin
  and sw_vers>; command=<exact command>; evidence=<terminal/session/Computer Use
  evidence>`. On non-Darwin, stop or delegate the slice to a Mac implementation
  host instead of substituting retained Incus topology.
- Run applicable reviewer personas from `HARNESS.md` after implementation
  evidence exists. For documentation-heavy changes, use
  `.agents/review-personas/docs-librarian.md`; for CLI command changes, use
  `.agents/review-personas/cli-command.md`; for Orbit Agent Tauri changes, use
  `.agents/review-personas/tauri-agent.md` before accepting the slice.
- Build the local post-feature packet before completion, classify obvious
  signals, and record `not used - <rationale>` for compact loops unless
  HARNESS.md calls for a fresh analyzer. Adjudicate analyzer findings before
  any durable guardrail is changed when an analyzer runs.
- Follow `HARNESS.md` for final-distillation, merge-boundary, post-feature
  signal-audit, session-archive, and cleanup policy. Use `LOOP.md.example` for
  the local packet shape and `bin/orbit-feature-finalization-check` for the
  executable gate instead of duplicating finalization mechanics here.
- Own final commit, merge-back, preserved-worktree post-analysis handoff,
  feature completion cleanup, and the implementation report.

Solo `spawn_agent` returns `agent_instructions`. Prepend them before everything
else in every spawned first prompt; the prompt shapes in this skill assume that
prefix, and nothing else may come before it.

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
2. Prepare the dedicated worktree with `bin/orbit-prepare-worktree`, which
   seeds `.orbit/loop.md` when it is missing.
3. Fill or update the seeded `.orbit/loop.md` with the source discussion
   pointer, feature scratchpad URL or `none`, worktree path, branch, current
   slice, and initial Done Contract. If `.orbit/loop.md` is missing after
   worktree prep, stop and report the setup blocker.
4. Discover the enabled `Codex` tool with `list_agent_tools`, then spawn a
   Solo-managed Codex orchestrator for the same Solo project with
   `spawn_agent`. If Codex is not available through Solo, stop and report the
   blocker instead of substituting another model.
5. Record the Solo project id, orchestrator process id/name, worktree path,
   branch, source thread pointer, and prompt/scratchpad pointer in the feature
   scratchpad and `.orbit/loop.md`.
6. Send the Solo Codex orchestrator a complete implementation prompt.
7. After prompt delivery and a visible first checkpoint or explicit delivery
   blocker, report the handoff result and stop. Resume only if the user asks for
   watcher or recovery work.
8. If the Solo Codex wrapper wedges or disappears before a visible first
   checkpoint, recover only with another tracked Codex route for the same Solo
   project, such as a Solo terminal running `codex exec` in the prepared
   worktree. If no tracked Codex route is available, stop and report the
   blocker. Do not substitute Grok or another implementation worker as feature
   owner.

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
- Source discussion: <source thread id/transcript pointer or none>
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
- Read `.orbit/loop.md` before reconstructing scope; treat it as the active
  current-slice packet seeded by the preparer.
- Treat this process id as the telemetry root. Record it in `.orbit/loop.md`
  and the feature scratchpad, then spawn all child workers/reviewers/terminals
  from inside Solo so they inherit this process as `parent_process_id`.
- Validate and complete the active Done Contract before implementation worker
  dispatch.
- Follow the normal Solo worker, TDD, review, verification, finalization,
  merge-back, and cleanup gates in this skill without weakening them.
- Do not ask the source Codex app session to keep coordinating unless delivery,
  Solo identity, or worktree setup is blocked.

Report the first checkpoint with the recorded process id, worktree, branch,
Done Contract location, candidate worker plan, and any blocker.
After that checkpoint, continue through normal implementation, review,
verification, finalization, merge-back, and cleanup phases without waiting for
routine acknowledgment; stop only for the blocker and Stop-if cases above or
for an explicit user pause.
```

## Solo Implementation Delegation

Discover the available Solo implementation worker for the current environment
and spawn it through Solo. Prefer Grok for bounded PHP/CLI/Pest implementation
when available, but the hard requirement is Solo tracking, not the model brand.
If the current feature owner cannot reach a Solo worker, stop and report the
blocker instead of using an untracked background model or shell.

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
- LOOP.md.example as template reference, then read existing `.orbit/loop.md`.
  If `.orbit/loop.md` is missing, report a setup blocker before editing.
- HARNESS_SIGNALS.md
- harness-signals/README.md
- .agents/skills/implementing-features/SKILL.md
- relevant docs and tests named in the handoff

Feature handoff:
<paste the crystallized handoff or owned slice>

Raw acceptance examples, accepted design/panel adjudications, and explicit deferrals:
<paste the concrete user-provided output samples, transcripts, failure text,
negative examples, and accepted adjudication lines or precise pointer, or state
`none`; list deferred parts with reason and owner>

Rules:
- Edit only inside the assigned worktree.
- Do not create `.orbit/loop.md`; worktree preparation and the feature
  orchestrator own the active packet.
- If a feature scratchpad exists, read its slice outcomes before editing and
  treat `.orbit/loop.md` as the current slice contract, not feature history.
- Inspect the current branch diff or log enough to understand prior slices that
  already exist in this feature worktree.
- Keep docs, tests, and code aligned.
- Do not silently drop a user-provided sample, transcript, or negative example
  when turning the request into a slice. If an example cannot be implemented in
  the current slice, report the exact deferred part before editing.
- Do not silently drop an accepted design or panel adjudication. Carry its lines
  or precise pointer through the handoff and compare implementation evidence
  against it in the Done Contract Reviewer checks when one exists.
- The feature orchestrator owns the Done Contract in `.orbit/loop.md`. You may
  challenge or propose changes to it, but do not silently weaken scope,
  evidence, reviewer checks, stop conditions, or pivot conditions.
- Use TDD: failing Pest coverage first, then implementation.
- Read budget: scale required reading to the size of the expected diff. Read
  the files listed above plus the docs and tests the handoff names; the
  harness-injected AGENTS.md content does not need to be re-read. Skip
  discovery reads a small diff does not need.
- First-outcome budget: after the required reads, report the first narrow diff
  or an explicit missing-context blocker within roughly a dozen commands.
  Prefer a test-only first diff for behavior changes or a docs-only first diff
  for documentation-owned work. Do not spend broad repository discovery before
  that first narrow diff or blocker. If the first diff cannot be made from the
  handoff, report the missing context instead of continuing to search.
- Run the focused verification assigned to this slice with the app-local
  runners; there is no root `artisan` or root `vendor/bin`. Use
  `bin/orbit-gateway-pest --compact` for apps/gateway, `bin/orbit-cli-pest`
  for apps/cli, `bin/orbit-docs-pest` for apps/docs,
  `cd packages/<pkg> && vendor/bin/pest --compact` for packages, and
  `bin/orbit-gateway-vendor-bin mago format --check` or
  `cd apps/cli && vendor/bin/mago format --check` for owned-file formatting.
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

## Codex Documenter / Librarian Delegation

Use a Codex documenter/librarian worker when documentation is substantial
enough to own separately: command contracts, product authority edits,
documentation-first handoffs, or focused docs drift analysis inside the changed
surface. Do not spawn it for routine small copy edits.

The documenter owns documentation artifacts, not final product decisions or code
implementation. The feature owner reviews the result and reconciles it with the
code worker before commit. Routine docs review covers changed files and named
authority docs; full-repo drift audits use
`.agents/skills/auditing-docs-drift/SKILL.md` only when explicitly requested.

When running inside Solo, discover the live Codex-capable tool with
`list_agent_tools`, then spawn it with `spawn_agent`. If Codex is not available
through Solo, stop and report the blocker instead of substituting another model.
Prepend Solo's returned `agent_instructions` to the first prompt.

Use this prompt shape:

```text
<prepend the agent_instructions returned by Solo spawn_agent>

You are the Codex documentation/librarian worker for one scoped Orbit feature slice.
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
post-feature signal audit only when HARNESS.md calls for it: explicit user
request, multi-slice work, parallel workers, topology/live-node proof,
product-contract change, release scope, messy human steering, reviewer dispute,
suspected guardrail drift, or analyzer/loop guardrail changes. Compact loops
still fill `.orbit/loop.md`, record verification, classify obvious signals, and
write `not used - <rationale>` in the `- Fresh analyzer:` row when no trigger
applies.

First write a small local packet under `.orbit/evidence/` or the final section
of `.orbit/loop.md`. Do not commit the packet. Include objective, final diff or
commit, verification results, the Solo orchestrator process id when the feature
was handed off from the Codex app, worker/reviewer ids or summaries, terminal or
PTY artifacts, quality-gate evidence, human corrections, and factual
orchestrator steering notes. Include the source thread id or transcript path for
the feature orchestrator when available, plus Solo scratchpads or process ids
needed to inspect worker and reviewer reports.

When the analyzer runs, spawn a fresh Solo-managed Codex analyzer: discover the
enabled `Codex` tool with `list_agent_tools`, then `spawn_agent`. If Codex is
not available through Solo, stop and report the blocker instead of substituting
another model. Give it only the packet, orchestrator/Solo session pointers,
changed diff, relevant harness docs, and named evidence pointers. Use
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
will pass. Skip any of these and the app-local Pest runs (for example
`bin/orbit-gateway-pest`) fail at boot
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

This skill only decides when E2E evidence is in or out of scope. Agents must not run `composer test:e2e*` commands. For shared provider-pool etiquette, manual
user-run E2E artifact handling, reaping boundaries, and configured host
discovery, use `HARNESS.md` -> `E2E Readiness And Resource Pool`.

## Retained Incus Inspection Gate

This skill only names retained topology as a required verification lane when
the diff calls for it. `HARNESS.md` -> `Retained Incus Inspection Gate` owns
the detailed trigger list, CLI Solo-terminal gate, source-mount launcher proof,
sync/restart behavior, and retained-topology cleanup rules. Workflow steps
below preserve when to invoke that gate and what evidence to record.

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
5. Lint the local packet shape at the first checkpoint: once the Done Contract
   is filled, run `bin/orbit-feature-finalization-check --lint` (defaults to
   `./.orbit/loop.md`) and fix any BLOCKED finding early instead of
   discovering it at the merge boundary. Re-run `--lint` after final
   distillation is filled.
6. Confirm owned files or domains and existing dirty work before editing.
7. Decide the Solo worker plan from `HARNESS.md`. First list candidate slices,
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
   are reconciled. Add a Codex documenter/librarian worker when documentation
   is substantial and the docs-owned surface is clear.
8. If a Codex documenter/librarian worker is used, spawn it first or in
   parallel only after the docs-owned slice is explicit. The feature owner must
   inspect the docs result and accept the docs contract before code relies on it.
9. Spawn the Solo implementation worker(s) with the worktree path, handoff,
   owned scope, documentation authority, TDD requirement, focused verification,
   and the rule that workers must not commit, merge to `main`, or clean up the
   worktree unless the feature owner explicitly assigns that exact step. Pin
   each worker's cwd at spawn: pass the assigned worktree through `extra_args`
   using the agent CLI's working-directory flag per the canonical spawn recipe
   in the `HARNESS.md` Solo Role Matrix, or, for agent CLIs without such a
   flag, spawn a Solo terminal that `cd`s into the worktree and launch the
   agent inside it. Still require each worker's first command to prove `pwd`
   and `git branch --show-current`. If the agent starts in `~/orbit` or another
   checkout, stop it and relaunch through a Solo terminal that `cd`s into the
   assigned worktree before starting the agent.
10. Monitor workers, inspect diffs, and send correction prompts until the
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
   When a Solo worker, reviewer, analyzer, or retained agent lane reaches lane
   close, run `bin/orbit-agent-session-capture <solo-process-id>` while that
   Solo process row is still alive. Record the staged path and status in
   `.orbit/loop.md`. If a provider is unsupported or cannot be captured, add
   the explicit `- Agent session capture waivers:` row naming the provider and
   reason; do not rely on archive-time lookup as the primary capture path.
   Prefer a fresh process id. For a deliberately restarted Codex lane, record
   the restart time, pass it as `--incarnation-started-at=<ISO8601>`, and
   require an explicit waiver if capture fails. Incarnation floors are
   Codex-only; restarted Claude or Grok lanes require a fresh Solo process id
   or an explicit capture waiver.
11. Align documentation inside this worktree when the handoff identifies missing
   or contradictory docs. Use the Codex documenter/librarian for substantial
   docs-owned corrections; otherwise keep docs corrections with the worker that
   owns the related behavior.
12. For every failed verification, review comment, human correction, docs
   conflict, setup problem, or agent mistake encountered during the slice,
   search `harness-signals/` for related prior records, then decide whether it
   is local cleanup or a durable harness signal. If the search returns stale,
   overlapping, or noisy records and curation belongs to this slice, update,
   consolidate, retire, or delete the records using `harness-signals/README.md`.
   If it is durable and belongs to the current slice, create or update the
   signal record and update the smallest guardrail target in the same worktree.
   If it is durable but broader than the slice, report a scoped follow-up
   instead of expanding ownership.
13. Check whether the project-owned Orbit skill under `.agents/skills/orbit/**` is
   affected. Update it in the same worktree when the change alters public CLI
   behavior, command signatures, node roles, state families, app/workspace
   runtime behavior, deployment/profile/update flows, or operational guidance
   another LLM would need to use Orbit correctly.
14. Ensure the implementation worker followed TDD (see Test-Driven Development
    below): failing Pest tests first, literal failing output captured, then
    implementation. A narrative claim that a test failed is not sufficient when
    the failure was used to prove red.
15. Keep the smallest working vertical slice that makes the tests pass.
16. For CLI command behavior, run retained topology proof from this worktree
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
17. For native Orbit Agent changes, use the implementing Mac as the live
   topology target. On Darwin, capture host identity with commands such as
   `hostname`, `uname -s`, and `sw_vers`, then prove the Agent feature with the
   focused Cargo checks and Computer Use or terminal evidence that exercises the
   native app behavior. Record `host topology kind=host-macos`, `host=`, `os=`,
   `command=`, and `evidence=` in the `Retained topology proof` row. On
   non-Darwin, stop or move the implementation to a Mac host; do not use
   retained Incus as the substitute proof for a Tauri/macOS app.
18. For VM/node/tool/package/doctor/role-baseline behavior, run the retained
   Incus inspection gate from this worktree. Retained
   topologies sync the current worktree into a runner-host source mount and
   execute from each VM's runtime mirror, so they are suitable for real VM
   inspection. Release and verify cleanup before continuing.
19. Run focused in-memory verification for the active slice. For multi-slice
    features, do not spend prepared E2E on every internal slice by default. Run
    the matching topology proof as the feature-level topology gate when the final
    branch diff needs real topology evidence. When topology proof is
    required for feature acceptance and cannot be completed, stop the loop if
    the blocker cannot be resolved inside this slice. Do not commit, merge,
    clean up, or run final loop-improvement extraction while required topology
    proof is still blocked; record the blocker, owner, and unblock
    condition in `.orbit/loop.md` and the report.
20. Run the applicable reviewer persona from the `HARNESS.md` routing table once
    implementation evidence exists and before accepting the slice. For
    documentation-heavy changes, run `.agents/review-personas/docs-librarian.md`.
    For CLI command changes, run `.agents/review-personas/cli-command.md`.
    For Orbit Agent Tauri changes, run `.agents/review-personas/tauri-agent.md`.
    Spawn any code-reviewer, CLI-reviewer, or Tauri-reviewer persona using the
    spawn recipe and unavailability rule in the `HARNESS.md` Solo Role Matrix.
    Preserve the reviewer report itself as the evidence artifact when the
    selected reviewer has no provider-session archive support.
    Resolve or explicitly report findings before commit.
    If the reviewer finds a mismatch between implementation evidence and the
    raw user examples or an accepted design/panel adjudication named by the Done
    Contract Reviewer checks, treat it as a contract mismatch first; only
    downgrade it to a follow-up when the Done Contract already names the
    deferral.
    For small changed-files-only reviews, prompt the reviewer for a
    blockers-first verdict (`No blockers` or file/line blockers) before optional
    suggestions. Every reviewer prompt must begin with the same checkout-proof
    block required for post-feature analyzers: `cd <absolute worktree path>`,
    `pwd`, `git branch --show-current`, and `git status --short --branch`.
    Require a `CHECKOUT_PROOF` line before the reviewer reads files or returns a
    verdict. Include the exact diff command and base the reviewer should inspect,
    such as `git diff HEAD -- <owned files>` for an uncommitted post-review fix,
    instead of leaving the reviewer to choose a broad branch comparison. Give
    Antigravity and comparable reviewers minute-scale read time when the output shows
    active file reads, tool use, or generation; do not interrupt productive
    review work just because it exceeds a short Solo wait deadline. Interrupt
    once with a proof-and-verdict prompt only when the reviewer is in the wrong
    checkout, clearly waiting for input, idle after loading context, or
    generating without useful progress for an extended window. If it still does
    not return, close or replace that reviewer and record the process finding
    through `harness-signals/` before proceeding only on defensible direct
    evidence. If the reviewer already produced findings, evidence, or
    substantive analysis but omitted the required machine-parseable verdict
    line, send one narrow verdict-line checkpoint prompt that asks for only the
    required final `VERDICT:` line from already gathered evidence. If it still
    does not emit the line, close or replace that reviewer and record the
    finding; do not keep prompting a reviewer that has stalled at the final
    verdict contract.
    Reviewer findings fixed before merge are normal feature-loop work. Promote
    them to loop improvements only when the review process itself missed a
    recurring class of issue, or existing reviewer guidance would not catch the
    same issue next time.
20. Do not run artifact-backed E2E verification. If the user manually runs an
    artifact-backed E2E command, record the provided output or existing
    `.orbit/quality-gates/` artifact.
21. Do not run provider provision gates. If the user manually runs a provider
    provision command, record the provided output or existing artifact and keep
    it separate from the retained topology feature gate.
22. If PHP changed, run the owning app's Mago check; there is no root
    `vendor/bin/mago`:

   ```bash
   bin/orbit-gateway-vendor-bin mago format --check   # apps/gateway
   cd apps/cli && vendor/bin/mago format --check      # apps/cli (same shape for apps/docs and packages/*)
   ```

    `composer mago:format:check` fans the same check across every app/package.

23. Before reporting completion, run the verification gate implied by the final
    diff:

   ```bash
   composer docs-lint       # docs-class diffs
   composer quality-check   # PHP or broader repo diffs
   ```

    `HARNESS.md` -> `Merge Boundary Gate` is the authority for the exact
    finalization requirement, and `bin/orbit-feature-finalization-check` is
    the executable gate: it derives the required proof from the branch diff
    and reads existing `.orbit/quality-gates/` artifacts. Do not rerun
    expensive gates from the finalization check.

24. Before committing or reporting completion, inspect the timing evidence
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
25. Before committing or reporting completion, run the Post-Feature Session
    Review in `HARNESS.md`: fill `.orbit/loop.md` as the canonical final packet,
    run the fresh analyzer only for explicit requests or escalation triggers,
    record `not used - <rationale>` for compact loops, classify guardrail
    candidates, record required verification rows, and archive the completed
    active `.orbit/` state with `bin/orbit-session-archive` before cleanup or
    before a later `.orbit/loop.md` rewrite. `HARNESS.md` owns analyzer
    adjudication, signal promotion, final packet labels, session archive policy,
    and the exact required verification evidence.
26. Commit the verified worktree changes on the worktree branch.
27. Merge the branch back into `main` from the primary `~/orbit` checkout by
    following `HARNESS.md` -> `Merge Boundary Gate` and `Feature Cleanup`. Run
    `bin/orbit-feature-finalization-check git merge <branch>` at the boundary,
    resolve any BLOCKED verdict, leave `~/orbit` on updated `main`, and
    preserve unrelated dirty files. Do not call the feature done until the
    verified branch is merged back to `main`.
28. If release was explicitly agreed or specifically discussed as part of the
    crystallized scope, run the release flow after merge. Capture live topology
    `doctor` status before publishing a release, run `orbit update:all` after
    the release artifacts are accepted, run `doctor` again, compare the before
    and after results, and either fix regressions immediately or record scoped
    follow-up tasks for intentional migration work.
29. When the user confirms live topology behavior or explicitly says the
    feature is complete, run feature completion cleanup per `HARNESS.md` ->
    `Feature Cleanup`: archive feature Solo state, close related todos, stand
    down related agents or terminals, and keep any intentionally preserved state
    named in the report.

## Test-Driven Development

Orbit is a TDD project. Every behavior change ships with Pest coverage that
fails before the implementation lands and passes after. No exceptions for
"trivial" changes — if behavior is worth changing, it is worth a test.

Normal feature work follows a staged verification model:

- **Pest unit/feature tests** in `tests/Unit/` or `tests/Feature/` that pin the
  internal contract: command output shape, JSON schema, validation, branching
  logic, error paths. These run under the app-local Pest runners:
  `bin/orbit-gateway-pest --compact`, `bin/orbit-cli-pest`, or
  `bin/orbit-docs-pest`.
- **Retained topology proof** when the branch diff needs real VM, node, CLI, or
  native host evidence. `HARNESS.md` owns the retained Incus and host-Mac proof
  mechanics; `apps/docs/content/testing/README.md` owns the full lane map.
- **Manual-only E2E and provision lanes**. The user may run those Composer
  commands from a shell; agents do not run, delegate, background, schedule, or
  hook `composer test:e2e*` or provider provision commands.

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
proof pass and the verified branch has been merged back into `main`. Provider
provision gates are not part of agent-run feature verification.

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
- Completed work does not stay stranded in `.worktrees/`: it is committed and
  merged into `main` unless the user explicitly asks for a PR or preserved
  branch. The worktree and branch stay intact after merge until the
  post-feature signal audit completes or the user explicitly approves cleanup
  (`HARNESS.md` Feature Cleanup).
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
    evidence; or host topology kind=host-macos with host=, os=, command=, and
    evidence=; blocker, or reason>
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
- Focused Pest (`bin/orbit-gateway-pest --compact` or the owning app's
  runner): <result>
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
