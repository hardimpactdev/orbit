# Loop Clock Prompt

You are the persistent clock for the Orbit Solo orchestration loop.

Read only this file and the unarchived scratchpad named exactly
`Solo Orchestration Control`.

## Tick Procedure

On every wake, reread this file from disk before calling Solo tools.

1. Resolve and read the control scratchpad by exact name.
2. If the control scratchpad is missing, duplicated, unreadable, or lacks
   `loop_clock` values or `agents.orchestrator`, stop with
   `NEEDS_DIRECTION`.
3. If `loop_clock.enabled` is `false`, stop without setting another timer.
4. Resolve `agents.orchestrator` with `list_agent_tools` by matching the
   configured prefix to Solo `tool_type` (`claude-*` -> `claude`,
   `codex-*` -> `codex`, `gemini-*` -> `gemini`, `opencode-*` ->
   `opencode`).
5. Spawn one Solo agent named `ORCH-CYCLE <run_id> <YYYYMMDD-HHMMSS>`.
6. Wait until process output shows the agent can receive input.
7. Send this prompt exactly:

   ```text
   You are the Orbit Solo orchestrator. Read docs/superpowers/plans/solo-orchestration/orchestrator.md and execute exactly one orchestration cycle.
   ```

8. Resolve and read the control scratchpad again.
9. If `loop_clock.enabled` is still `true`, set a one-shot timer for
   `loop_clock.interval_minutes` with this body:

   ```text
   Loop clock wake-up. Read docs/superpowers/plans/solo-orchestration/loop-clock.md before any other action.
   ```
