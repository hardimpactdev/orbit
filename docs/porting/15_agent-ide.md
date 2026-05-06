# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.

## Status

Not started. The family port is blocked until the remaining supporting
prerequisites are in place:

- `docs/abstractions/15_agent-ide.md` exists and must be read with
  `docs/abstractions/cross-cutting.md` before implementing Agent IDE command
  slices.
- The activity-logging contract allowlist cannot include `agent-ide:*` until
  the command surface is implemented.

## Commands

- [~] `agent-ide:message`
  - [x] Command docs are converted into the current split-file shape.
  - [x] Family abstraction seed exists.
  - [x] Gateway-local explicit app-target JSON slice: resolves effective
    adapters through current app/node intent, validates the core adapter
    registry, uses a container-bound `AgentIdeMessageAdapter` seam for active
    session lookup and delivery, returns documented success, no-adapter, and
    no-active-session JSON envelopes, and proves messaging does not mutate
    app/workspace/process/node/tool state.
  - [!] Configured control/app caller forwarding is blocked until the gateway
    API endpoint and typed `SendAgentIdeMessageRequest`/response DTO are ported.
    Current command behavior fails non-gateway callers before local state can be
    treated as authority. Next concrete action: add the gateway API endpoint,
    authorization path, typed gateway request/DTO, and command forwarding for
    explicit app targets.
  - [!] Workspace targets, current-directory target inference, stdin input,
    human progress-tree rendering, adapter delivery diagnostics under
    `error.data`, and real core adapter transports remain unported. Next
    concrete action after forwarding: extend the command in narrow slices that
    each add one documented input/rendering/delivery behavior with focused Pest
    coverage.
