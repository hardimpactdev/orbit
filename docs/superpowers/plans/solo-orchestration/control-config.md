# Solo Orchestration Control Config

Human-editable control file for the Orbit Solo orchestration loop.

## Control Values

```yaml
run_id: 2026-05-03-current-190
coordination_todo: 190

loop_clock:
  enabled: true
  interval_minutes: 10

pipeline:
  ready_target: 2
  filler_enabled: true

concurrency:
  max_active_implementers: 3

agents:
  loop_clock: claude-sonnet-low
  orchestrator: claude-sonnet-low
  pipeline_filler: claude-opus-xhigh
  todo_scout: gemini-3.1-pro-preview
  implementation: opencode-kimi-k2.6
  reviewer: gemini-3.1-pro-preview
  e2e: claude-opus-xhigh
  rubber_duck_1: gemini-3.1-pro-preview
  rubber_duck_2: claude-opus-max

safety:
  standing_infrastructure: not-a-test-lane
  destructive_flows: ephemeral-only
```
