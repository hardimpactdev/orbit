# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level command status lives
in [`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.

## Status

In progress. The family abstraction and first explicit app-target messaging
slices are in place; broader input modes, forwarding coverage, and real adapter
transports remain open.

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
  - [x] Explicit app-target gateway API and configured control-caller
    forwarding: `POST /api/agent-ide/message`, typed
    `SendAgentIdeMessageRequest` / `AgentIdeMessageResponse`, gateway-owned app
    authorization, preserved structured errors, and command forwarding without
    local adapter delivery on control callers.
  - [x] Explicit app-target app-node caller forwarding uses the same typed
    gateway request as control callers; app-node local adapter state is not
    treated as delivery authority, and gateway authorization is covered for
    authorized app callers.
  - [x] Explicit workspace targets: gateway-local delivery, typed gateway
    forwarding, API authorization, workspace-level adapter overrides including
    `none`, and app/node inherited target metadata are covered for
    `--workspace=<workspace>`.
  - [x] Current-directory target inference resolves workspace paths before app
    paths on gateway callers and forwards normalized cwd paths from configured
    non-gateway callers to the gateway API for authorization and delivery.
  - [x] Stdin input reads the command input stream, preserves multiline bodies
    while removing one shell-added trailing newline, and fails before target
    resolution when combined with a positional message.
  - [x] Human success rendering includes the documented completed progress tree
    and app/workspace delivery summaries without leaking JSON envelopes.
  - [x] Adapter delivery diagnostics under `error.data` are preserved for
    gateway-local JSON errors, gateway API errors, and forwarded command
    failures.
  - [x] Activity logging for `POST /api/agent-ide/message` emits safe write
    metadata for authorized app/workspace delivery attempts without storing
    full message bodies, adapter credentials, raw adapter output, or secrets;
    the command tech contract is enforced by docs-lint.
  - [!] Real core adapter transports remain unported. Next concrete action:
    choose and implement the smallest real core adapter transport slice, or
    defer it explicitly if external adapter process semantics need a product
    decision.
