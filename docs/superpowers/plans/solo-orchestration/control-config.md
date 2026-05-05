# Solo Orchestration Control Config

Human-editable control file for the Orbit Solo orchestration loop.

## Control Values

```yaml
coordination_todo: 257

loop_clock:
  enabled: true
  interval_minutes: 30

pipeline:
  ready_target: 8
  filler_enabled: true
  dispatch_order: []

concurrency:
  max_active_implementers: 3
  reviewer_dispatch_enabled: true
  e2e_dispatch_enabled: true

agents:
  loop_clock: claude-sonnet-low
  orchestrator: claude-sonnet-low
  reconciler: claude-opus-xhigh
  pipeline_filler: claude-opus-xhigh
  workspace_setup: claude-sonnet-low
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
