# Agent Loop State Model

Shared vocabulary for the agent loop. Tags carry the queryable current phase.
Comments carry the append-only audit trail and handoff payloads. When a comment
and structured state disagree, structured state wins.

## Phase Tags

The loop acts only on open todos; completed todos are out of scope. Each open
todo carries exactly one phase tag. An open todo with no phase tag is treated
as `draft`, so any open todo enters the loop without manual tagging.

- `draft` — open, content not yet validated against current `main`.
- `ready` — content validated against `main`; dispatchable.
- `prepared` — worktree created, dependencies installed, tests green.
- `implementing` — implementer is working.
- `reviewing` — reviewer is reviewing (and looping with the implementer).
- `approved` — reviewer signed off; awaiting strategic merge.
- `done` — merged to `main`; todo completed.

## Attention Tags

Coexist with a phase tag:

- `changes-requested` — reviewer found in-scope fixes; todo stays `reviewing`.
- `needs-direction` — a product, architecture, or safety decision is required.

Only the actor holding the todo lock mutates that todo's tags.

## Comment Labels

Terminal signals the orchestrators poll for. The agent that finishes a step
posts the label as the first token of a comment; payload follows on the same
comment.

- `PREPARED` — mechanical: worktree green (end of stage 1).
- `IMPLEMENTED` — implementer: one implement round complete (stage 2 and each
  fix round).
- `REVIEW_FINDINGS` — reviewer: issues found; body lists the findings.
- `APPROVED` — reviewer: review clean (end of stage 3).
- `MERGE_REQUESTED` — mechanical: asked strategic to merge this todo.
- `MERGED` — strategic: branch landed on `main` (end of stage 4).
- `READY` — strategic: content validated against `main` (end of stage 5).
- `NEEDS_DIRECTION` — any agent: a decision is required; body states the choice.
- `BLOCKED` — any agent: cannot proceed; body states why.

## Process Names

- `MECHANICAL` — persistent clock.
- `STRATEGIC` — persistent warm service.
- `IMPLEMENTER-<todo_id>` — long-lived through review; closed after merge.
- `REVIEWER-<todo_id>` — closed when it posts `APPROVED`.

## Worktree Convention

- Path: `.worktrees/agent-loop-<todo_id>`.
- Branch: `agent-loop-<todo_id>`.

That worktree is the only checkout the implementer, reviewer, and merge step
use. No role implements from `main` or a shared dirty checkout.

## Handoff Protocol

Mechanical drives strategic with one `send_input` message; strategic answers
with one comment label that mechanical polls for.

- Merge (stage 4): mechanical sends `merge <todo_id>`; strategic posts `MERGED`
  or `NEEDS_DIRECTION`.
- Readiness (stage 5): mechanical sends `validate readiness <todo_ids> against
  main`; strategic posts `READY` (and tags `ready`) per validated todo, or
  tags `needs-direction` with a `NEEDS_DIRECTION` comment.

Strategic is stateless between calls: it reads every todo's state fresh each
time it is messaged, acts, then returns to idle.
