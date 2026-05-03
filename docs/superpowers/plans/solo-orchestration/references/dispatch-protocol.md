# Dispatch Protocol

Shared process dispatch protocol for loop-clock and orchestrator.

1. Read `control-config.md` and `references/agent-specs.md`.
2. Resolve the configured `agents.*` value with `list_agent_tools`.
3. Spawn the process with the role-specific process name.
4. Wait until the process can receive input.
5. Apply setup from `references/agent-specs.md`.
6. Send one compact role prompt naming:
   - the role prompt file;
   - `control-config.md`;
   - `agent_spec`;
   - assigned todo id, blocker/comment id, or cycle instruction.
7. Verify prompt delivery.

Do not send follow-up guidance to a running role. Change prompt files or todo
state, then let the next cycle pick it up.
