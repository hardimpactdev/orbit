# Agent Loop Control Config

Runtime knobs for the agent loop. Read this file at the start of every
mechanical tick. Do not copy these values into scratchpads or KV.

```yaml
run_id: <active-run-id>

mechanical:
  enabled: true
  tick_interval_minutes: 10

queue:
  ready_target: 2

agents:
  mechanical: { tool: Claude, agent_tool_id: 3, model: haiku }
  strategic:  { tool: Codex, agent_tool_id: 4, model: "5.5", reasoning: xhigh }
  implementer: { tool: Cursor, agent_tool_id: 13 }
  reviewer:   { tool: Codex, agent_tool_id: 4, model: "5.5", reasoning: xhigh }
```

- `mechanical.enabled` — when `false`, the mechanical tick stops without
  rescheduling. This is how the user stops the loop.
- `mechanical.tick_interval_minutes` — minutes between mechanical ticks.
- `queue.ready_target` — how many dispatchable todos (`ready` + in-flight)
  mechanical asks strategic to maintain.
- `agents.*` — `agent_tool_id` is the spawn target passed to `spawn_agent`.
  Re-verify each id against `list_agent_tools` at the start of a run, since ids
  are environment-specific. `model` and `reasoning` are applied through the
  agent CLI's own configuration, not by Solo.
