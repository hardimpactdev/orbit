# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.
Abstraction seed: `docs/abstractions/15_agent-ide.md`.

## Commands

- [~] `agent-ide:message`
  - [x] Gateway-local + Saloon forwarding for explicit app/workspace
    targets and cwd-inferred targets, with stdin input, human progress-tree
    rendering, and adapter diagnostics under `error.data`. Pest under
    `tests/Feature/Commands/AgentIde/` covers explicit app/workspace,
    cwd inference, stdin, gateway forwarding, and authorization.
  - [x] Gateway API endpoint `POST /api/agent-ide/message` with typed
    `SendAgentIdeMessageRequest` / `AgentIdeMessageResponse`, app/workspace
    authorization, and activity logging that emits safe write metadata
    without storing message bodies, adapter credentials, or secrets.
  - [x] OpenCode core message transport: container-bound adapter resolves
    app/workspace targets via stored `agent_ide_workspace_id`, uses managed
    `opencode-server` endpoint and credentials, and posts to the async
    prompt endpoint. Covered by HTTP-faked command tests.
  - [!] Polyscope transport unported. The clean rebuild has no Polyscope
    SDK dependency, typed HTTP transport contract, or credential schema
    beyond legacy evidence. Next: decide whether to add a first-party
    Polyscope HTTP adapter, an SDK dependency, or defer until the
    tool-family Polyscope credential/config workstream is ported.
  - [ ] E2E coverage. Lane TBD: Docker feature for adapter HTTP fakes;
    `lane=none` may be defensible if all adapter wiring is exercised at the
    Pest layer with HTTP fakes.

## Activity backfill

- [x] Tech-contract section enforced by docs-lint for `agent-ide:message`.
