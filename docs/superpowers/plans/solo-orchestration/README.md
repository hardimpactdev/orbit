# Solo Orchestration Loop

This directory contains copy-ready prompts for the agent roles used to implement
an Orbit plan through Solo.

## Role Map

1. **Kickstarter** starts or resumes the loop.
2. **Orchestrator** fills the todo pipeline, dispatches workers, manages
   blockers, and closes batches.
3. **Tailer** supervises active agents, locks, scope, git state, and template
   friction.
4. **Implementer** owns exactly one todo.
5. **Implementer Reviewer** reviews exactly one implementer's work.

## Shared Inputs

Every role should read only the context it needs:

- `docs/superpowers/plans/00-plan-implementation-prompt-solo.md`
- this `README.md`
- the role-specific prompt file
- `IMPLEMENTATION_PLAN`
- `docs/PORTING.md`
- relevant `docs/commands/**`
- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/MISSION.md` when scope or capability questions arise
- `../orbit-old-may` only as legacy implementation evidence
- Solo scratchpad `131` for the worker todo template

Current docs are product authority. Current implementation and the old repo are
evidence only.

## Decision Evidence Stack

When a fork appears, do not let an implementation worker choose between broad
architecture paths mid-stream. Pause the implementation todo and create a
focused decision/audit todo.

The decision worker must resolve the fork from this evidence stack, in order:

1. current docs as product authority;
2. `docs/PORTING.md` for migration order and current tracker state;
3. old repo evidence from `../orbit-old-may`;
4. existing code, tests, and todo comments as implementation evidence.

The worker may choose a path only when that stack clearly supports one option as
simpler, safer, and aligned with the clean rebuild. If the stack does not decide
the fork, the worker must stop with `NEEDS_DIRECTION`.

Agents must not pick the option that sounds best merely to keep the pipeline
moving.

## Lifecycle Labels

Use these exact labels in Solo comments so work can resume after compaction:

- `PIPELINE_READY`: todo is unblocked, scoped, and ready for assignment.
- `ASSIGNED process=<id>`: orchestrator assigned a worker process.
- `WORKER_STARTED`: worker confirmed task scope, dependencies, and gate.
- `REVIEW_STARTED process=<id>`: worker spawned its reviewer.
- `REVIEW_DONE verdict=APPROVED|CHANGES_REQUESTED|BLOCKED`: reviewer result.
- `WORKER_DONE status=DONE|DONE_WITH_CONCERNS|BLOCKED|NEEDS_DIRECTION`:
  worker handoff result.
- `TAILER_VERIFIED`: tailer verified lifecycle, gate evidence, scope, and locks.
- `ORCHESTRATOR_CLOSED`: orchestrator closed the todo lifecycle.
- `PROMPT_RECOVERY`: prompt delivery or stalled-process recovery was performed.
- `SCOPE_DRIFT`: worker or reviewer touched/proposed out-of-scope work.
- `LOCK_STALE`: a Solo lock is stale or externally owned and needs recovery.
- `TEMPLATE_FRICTION`: repeated todo-shape issue that should improve
  scratchpad `131`.
- `NEEDS_DIRECTION`: product or architecture decision needs human input.

## Todo Pipeline Rules

- Keep a small queue of unblocked `PIPELINE_READY` todos.
- Prefer docs-first and decision/audit todos when docs or architecture are
  ambiguous.
- Do not create todos for phases or command-group headings. Command groups are
  sequencing context, not assignable work.
- Every todo must state objective, sequencing rules, dependencies, product
  authority, legacy evidence, owned files/domains, non-goals, quality gate,
  reviewer requirements, lock hygiene, and reporting requirements.
- A todo is worker-ready only when it has a single implementation or decision
  path. If it contains alternatives, create a decision todo first.

## Quality Gates

Each implementer must run the exact focused gate listed on its todo.

If PHP files changed, also run:

```bash
vendor/bin/pint --dirty --format agent
```

Before batch sign-off, the orchestrator must get a fresh final review and then
ensure these gates have passed:

```bash
composer rector
composer analyse
composer format
composer test
```

Use `composer quality-check` only when the plan or todo explicitly accepts the
combined gate. Do not replace a todo's focused gate with a broader gate unless
the todo says so.

## Safety Rules

- Do not run destructive, provisioning, host-mutation, or repair/adoption flows
  against standing live nodes.
- Standing live-node checks on gateway, beast, and mini must stay read-only or
  idempotent.
- Provisioning and destructive validation must use only the ephemeral nodes or
  VMs described in `TESTING.md`.
- Agents must not use `git stash`, `git reset --hard`, `git checkout --`, broad
  `git restore`, or hidden reverts.
- Baseline evidence should use read-only commands such as `git log`,
  `git show <ref>:<path>`, or `git diff <ref>..HEAD -- <path>`.

## Blocker Handling

When an implementer hits a blocker:

1. Keep the todo open.
2. Create a focused decision/audit todo when the blocker is architectural or
   product-level.
   The decision/audit todo must require the decision evidence stack above.
3. Use `RUBBER_DUCK1` and `RUBBER_DUCK2` for independent solution proposals
   only when the active todo requires blocker resolution and the product docs do
   not already decide the answer.
4. Record proposals and route them back to the owning implementer or reviewer.
5. Ask the user only for genuine product direction that cannot be decided from
   current docs, `docs/PORTING.md`, and legacy evidence.

## Completion

The loop is complete only when:

- all plan todos in scope are completed or explicitly deferred with evidence;
- every completed implementation todo has worker-review evidence;
- a fresh batch reviewer approves;
- intentional changes are committed to `main`;
- applicable E2E validation in `TESTING.md` has passed or a tracked blocker
  explains why it cannot run yet;
- discovered follow-up work is captured in Solo and, when durable, in
  `docs/PORTING.md`.
