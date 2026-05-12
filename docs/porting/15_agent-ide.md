# 15_agent-ide — Agent IDE Workstream

Detail file for the agent-ide command family. Top-level status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/15_agent-ide/`.
Abstraction seed: `docs/abstractions/15_agent-ide.md`.

## Commands

- [x] `agent-ide:message`
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
    prompt endpoint. Covered by HTTP-faked command tests and Docker feature
    E2E `tests/E2E/AgentIdeMessageTest.php`.
  - [-] Polyscope live message transport is intentionally deferred. Polyscope
    remains a core descriptor/default value for node/app/workspace intent.
    Workspace creation is reintroduced for `workspace:new` through the
    Polyscope SDK, but message delivery still needs a first-party HTTP contract
    equivalent to OpenCode's prompt endpoint before it can be marked complete.
  - [x] Docker feature E2E:
    `composer test:e2e:docker -- --filter='sends a workspace message through the managed OpenCode transport'`.

## Activity backfill

- [x] Tech-contract section enforced by docs-lint for `agent-ide:message`.
