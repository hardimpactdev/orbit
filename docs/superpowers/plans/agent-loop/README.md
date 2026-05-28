# Agent Loop

A simple Solo orchestration loop. Every todo flows through five stages:

1. **Prepare** — create the worktree, install dependencies, verify tests green.
2. **Implement** — a fresh implementer agent implements the todo.
3. **Review** — a fresh reviewer agent goes back and forth with the implementer
   until the work is approved.
4. **Complete** — merge the worktree to `main`, resolve conflicts, complete the
   todo.
5. **Verify readiness** — re-validate the remaining draft todos against the new
   `main` and promote the valid ones to dispatchable.

## Cast

| Agent | Lifetime | Owns | Prompt |
| --- | --- | --- | --- |
| Mechanical | persistent; self-reschedules its own timer | the clock + stages 1-3; the only spawner | `mechanical.md` |
| Strategic | persistent; warm and idle, reacts to `send_input` | stages 4-5, on demand | `strategic.md` |
| Implementer | fresh per todo; stays open through review | writes code, fixes review feedback | `implementer.md` |
| Reviewer | fresh per todo; lives through one review loop | reviews, sends findings, approves | `reviewer.md` |

The mechanical orchestrator is the clock — there is no separate loop-clock role.
It is the only agent that spawns processes. The strategic orchestrator never
holds a timer; it only acts when the mechanical orchestrator messages it, then
returns to idle. If strategic is not alive, mechanical re-spawns it.

Handoffs are event-driven: when an agent finishes a step it nudges the next
owner (`send_input`) instead of waiting to be polled. The implementer nudges
mechanical after `IMPLEMENTED` so the reviewer is spawned; the reviewer nudges
mechanical on `APPROVED`; strategic nudges mechanical after `MERGED`/`READY`.
The implementer/reviewer back-and-forth in stage 3 happens directly between the
two agents via `send_input`. The mechanical tick timer is only a safety net that
catches missed nudges or dead agents.

## State Machine

The loop acts only on open todos that carry an agent-loop phase tag; completed
and untagged todos are out of scope. A todo is enrolled into the loop by tagging
it `draft`. One phase tag per enrolled todo. Mechanical advances `ready ->
prepared -> implementing -> reviewing -> approved`. Strategic produces `ready`
(stage 5) and consumes `approved` (stage 4).

```
        STRATEGIC S5                MECHANICAL S1   S2            S3
draft ───────────────► ready ──────► prepared ──► implementing ──► reviewing
  ▲   (validate vs main)                                              │  ▲
  │                                                                   │  │ changes-requested
  │                                                                   ▼  │ (loops)
  │                                                              approved │
  │                                          STRATEGIC S4 (merge)    │     │
  │                                                                  ▼     │
  └──────────  re-validate after merge  ◄───────  done ◄── merging ──┘
                                                 (completed)
```

The tag, comment, process-name, and handoff vocabulary is defined once in
`state-model.md`. Runtime knobs live in `control-config.md`.

## State Authority

Structured Solo state wins over comments when they disagree. Read state in this
order: control-config, completion state, locks, tags, blockers, processes,
timers, then lifecycle comments. Comments are an append-only audit and the
carrier for handoff payloads (such as review findings); tags are the queryable
authority for a todo's current phase. Read Solo state only through Solo tools;
never read `.claude/**`, tool-result files, or other CLI internals.

## Starting The Loop

The launcher starts the loop by spawning one mechanical orchestrator:

1. Spawn the `agents.mechanical` runtime named `MECHANICAL`, applying its
   descriptor setup lines from
   `docs/superpowers/plans/agent-loop/references/agent-specs.md`.
2. Send the bootstrap:

   ```text
   You are the Orbit agent-loop mechanical orchestrator. Read docs/superpowers/plans/agent-loop/mechanical.md before any other action.
   ```

Mechanical spawns and keeps the strategic orchestrator warm on its first tick.
To stop the loop, set `mechanical.enabled` to `false` in `control-config.md`.
