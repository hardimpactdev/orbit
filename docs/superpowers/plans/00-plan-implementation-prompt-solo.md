# Solo Orchestration Prompt

This file is the stable entrypoint for starting the Solo implementation loop.
The role-specific prompts live in `solo-orchestration/` so each agent can be
started or recovered with the smallest prompt that matches its job.

## Configuration

Use these variables for this run:

```env
IMPLEMENTATION_PLAN=`2026-04-30-node-command-contract-contraction`
TASK_PREFIX=NC
PIPELINE_READY_TARGET=2

ORCHESTRATOR_AGENT=codex-gpt-5.4-mini-low
PIPELINE_FILLER_AGENT=claude
TAILER_AGENT=codex-gpt-5.5-xhigh
IMPLEMENTATION_AGENT=opencode-kimi-k2.6
REVIEWER_AGENT=codex-gpt-5.5-xhigh
RUBBER_DUCK1=gemini-3.1-pro-preview
RUBBER_DUCK2=claude
E2E_AGENT=claude
```

Agent variable format:

`<cli app>-<model>-<model-version>-<reasoning/thinking>`

The final reasoning/thinking segment may be omitted. When omitted, use that
CLI/model's configured default.

Examples:

- `opencode-kimi-2.6`
- `codex-gpt-5.5-xhigh`
- `claude-opus-4.7`

Use the variables throughout the run. Do not hard-code task prefixes,
implementation, fresh-review, plan-review, or blocker agents when a variable
exists.

## Start Here

1. Give `solo-orchestration/kickstarter.md` to the agent that should start or
   resume the loop.
2. The kickstarter starts or resumes:
   - one orchestrator using `solo-orchestration/orchestrator.md`;
   - one tailer using `solo-orchestration/tailer.md`.
3. The orchestrator keeps the todo pipeline filled, dispatches one unblocked
   worker-ready todo at a time, spawns `solo-orchestration/pipeline-filler.md`
   as a one-shot role when the ready queue is low, and uses
   `solo-orchestration/implementer.md` for each worker.
4. The tailer watches processes, locks, git status, scope drift, focused gate
   evidence, final diffs, and repeated todo-template friction while the
   orchestrator keeps assignment moving.
5. Fresh reviewers are optional escalation/final sign-off agents that use
   `solo-orchestration/fresh-reviewer.md` only when the tailer or orchestrator
   asks for one.

## Prompt Directory

- `solo-orchestration/README.md`: shared rules, lifecycle labels, quality gates,
  and source-of-truth policy.
- `solo-orchestration/kickstarter.md`: prompt for the agent that starts or
  resumes the loop.
- `solo-orchestration/orchestrator.md`: prompt for the agent that fills the
  todo pipeline, dispatches workers, handles blockers, and manages batch
  close-out.
- `solo-orchestration/pipeline-filler.md`: prompt for the one-shot agent that
  reads `docs/PORTING.md` and creates the next small worker-ready todos.
- `solo-orchestration/tailer.md`: prompt for the long-running supervisor that
  tails active agents, performs ongoing review, verifies completed worker
  output, and improves the todo template when needed.
- `solo-orchestration/implementer.md`: prompt for a single-task implementation
  worker.
- `solo-orchestration/fresh-reviewer.md`: optional escalation/final sign-off
  reviewer prompt.

## Product Authority

Use these as the source of product truth:

- `docs/BLUEPRINT.md`
- `docs/BUILDING-BLOCKS.md`
- `docs/commands/**`
- `docs/MISSION.md` when scope or capability questions arise
- `docs/PORTING.md` for migration order and current tracker state

Current code is implementation evidence, not the north star. The old repo at
`../orbit-old-may` is implementation evidence only.

## Non-Negotiables

- Do not implement code in the kickstarter, orchestrator, tailer, pipeline
  filler, or reviewer roles.
- Do not dispatch blocked todos or downstream todos whose prerequisites are not
  closed.
- Every implementation worker owns exactly one todo.
- The tailer is the normal ongoing reviewer for implementer work. Fresh
  reviewers are exceptional: high-risk work, tailer uncertainty, or batch/final
  sign-off.
- Standing live-node checks must stay read-only or idempotent.
- Provisioning, destructive, host-mutation, and repair/adoption flows require
  the ephemeral E2E validation described in `TESTING.md`.
- Do not use `git stash`, `git reset --hard`, `git checkout --`, broad
  `git restore`, or hidden reverts in the shared worktree.
