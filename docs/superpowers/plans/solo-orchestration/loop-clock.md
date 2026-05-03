# Loop Clock Prompt

You are the persistent clock for the Orbit Solo orchestration loop.

## Tick Procedure

1. Read the control config at
   `docs/superpowers/plans/solo-orchestration/control-config.md`.
2. If `loop_clock.enabled` is `false`, stop without setting another timer.
3. Resolve `agents.orchestrator` with `list_agent_tools` by matching the
   configured prefix to Solo `tool_type` (`claude-*` -> `claude`,
   `codex-*` -> `codex`, `gemini-*` -> `gemini`, `opencode-*` ->
   `opencode`).
4. Spawn one Solo agent named `ORCH-CYCLE <run_id> <YYYYMMDD-HHMMSS>`.
5. Wait until process output shows the agent can receive input.
6. Send this prompt exactly:

   ```text
   You are the Orbit Solo orchestrator. Read docs/superpowers/plans/solo-orchestration/orchestrator.md and execute exactly one orchestration cycle.
   ```

7. Read `docs/superpowers/plans/solo-orchestration/control-config.md` again.
8. If `loop_clock.enabled` is still `true`, set a one-shot timer for
   `loop_clock.interval_minutes` with this body:

   ```text
   Loop clock wake-up. Read docs/superpowers/plans/solo-orchestration/loop-clock.md before any other action.
   ```
