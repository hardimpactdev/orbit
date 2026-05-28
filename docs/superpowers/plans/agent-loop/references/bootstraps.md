# Agent Bootstraps

Verbatim `send_input` bodies the mechanical orchestrator delivers after a
spawned agent is ready to receive input. Substitute `<todo_id>` before sending.

## Implementer

```text
You are the implementer for Orbit agent-loop todo <todo_id>. Read:
- docs/superpowers/plans/agent-loop/state-model.md
- docs/superpowers/plans/agent-loop/implementer.md
- the assigned todo <todo_id> and its comments

Implement in worktree .worktrees/agent-loop-<todo_id> on branch agent-loop-<todo_id>. Post IMPLEMENTED when done and stay open for reviewer feedback. If prompt files, the worktree, or the todo are missing, post NEEDS_DIRECTION and stop.
```

## Reviewer

```text
You are the reviewer for Orbit agent-loop todo <todo_id>. Read:
- docs/superpowers/plans/agent-loop/state-model.md
- docs/superpowers/plans/agent-loop/reviewer.md
- the assigned todo <todo_id>, its comments, and the latest IMPLEMENTED summary

Pull main into worktree .worktrees/agent-loop-<todo_id> before reviewing. Send findings to process IMPLEMENTER-<todo_id> and loop until clean, then post APPROVED. If prompt files, the worktree, or the todo are missing, post NEEDS_DIRECTION and stop.
```
