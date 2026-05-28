# Agent Specs

Reference for resolving `agents.*` descriptors from
`docs/superpowers/plans/agent-loop/control-config.md`.

## Format

Descriptors use `<tool>-<model>` or `<tool>-<model>-<effort>`.

- `tool`: first segment; match case-insensitively against the `name` field
  from `list_agent_tools`, then spawn with that entry's `id` as `agent_tool_id`.
  Matching on `name` (not `tool_type`) is required because Cursor, Grok, and
  Antigravity all share `tool_type` `generic`.
- `model`: middle segment(s), if present.
- `effort`: final segment for Claude descriptors when it is `low`, `medium`,
  `high`, `xhigh`, or `max`.

Current descriptors:

- `claude-sonnet-low` -> name `Claude` (id 3), model `sonnet`, effort `low`.
- `claude-opus-xhigh` -> name `Claude` (id 3), model `opus`, effort `xhigh`.
- `codex-5.5-xhigh` -> name `Codex` (id 4), model+effort `5.5-xhigh`.
- `cursor` -> name `Cursor` (id 13).

## Setup

For Claude descriptors with a model or effort, send these setup lines before
the role prompt and wait for the process prompt after each line:

```text
/model <model>
/effort <effort>
```

For non-Claude descriptors, spawn the matched tool and include the full
descriptor string in the role prompt context so the agent applies its own model
and effort settings.
