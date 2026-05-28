# Agent Loop Control Config

Runtime knobs for the agent loop. Read this file at the start of every
mechanical tick. Do not copy these values into scratchpads or KV.

```yaml
mechanical:
  enabled: true
  tick_interval_minutes: 10

queue:
  ready_target: 2

agents:
  mechanical: claude-sonnet-low
  strategic: claude-opus-xhigh
  implementer: cursor
  reviewer: codex-5.5-xhigh
```

- `mechanical.enabled` — when `false`, the mechanical tick stops without
  rescheduling. This is how the user stops the loop.
- `mechanical.tick_interval_minutes` — minutes between mechanical ticks.
- `queue.ready_target` — how many dispatchable todos (`ready` + in-flight)
  mechanical asks strategic to maintain.
- `agents.*` — `<tool>-<model>-<effort>` descriptors resolved per
  `docs/superpowers/plans/agent-loop/references/agent-specs.md` before spawning.
