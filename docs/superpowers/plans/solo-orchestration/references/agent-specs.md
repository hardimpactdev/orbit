# Agent Specs

Reference for resolving `agents.*` values from `control-config.md`.

## Format

Agent specs use `<tool>-<model>` or `<tool>-<model>-<effort>`.

- `tool`: first segment; match Solo `tool_type` from `list_agent_tools`.
- `model`: middle segment(s), if present.
- `effort`: final segment only for Claude specs when it is `low`, `medium`,
  `high`, `xhigh`, or `max`.

Examples:

- `claude-sonnet-low` -> tool `claude`, model `sonnet`, effort `low`.
- `claude-opus-xhigh` -> tool `claude`, model `opus`, effort `xhigh`.
- `gemini-3.1-pro-preview` -> tool `gemini`, model `3.1-pro-preview`.
- `opencode-kimi-k2.6` -> tool `opencode`, model `kimi-k2.6`.

## Setup

For Claude specs with model and effort, send these setup lines before the role
prompt and wait for the process prompt after each line:

```text
/model <model>
/effort <effort>
```

For non-Claude specs, resolve by tool prefix and include the full `agent_spec`
in the role prompt context.
