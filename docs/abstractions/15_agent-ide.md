# Agent IDE Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
Agent IDE command ports.

Product behavior remains owned by `docs/domains/15_agent-ide/**` and the
top-level product docs.

## Domain Constraints

- The `agent-ide` command domain owns the `agent-ide:*` command prefix and
  communication workflows with active Agent IDE sessions.
- The domain does not own a state family. There is no
  `doctor --family=agent-ide` contract.
- Node defaults are gateway intent owned by the node family via `node:agent-ide`.
- App overrides are gateway intent owned by the app family via `app:agent-ide`.
- Workspace state, setup, teardown, history, and future workspace-level adapter
  overrides belong to the workspace family.
- Adapter server lifecycle, credentials, and managed adapter tools belong to
  the tool family.
- Process crash notification policy and crash-event history belong to the
  process family.
- Agent IDE commands may resolve an app or workspace context, resolve the
  effective adapter, find an active adapter session, and deliver a message.
  They must not create apps, workspaces, sessions, processes, tools, nodes, or
  repair drift.
- App-node callers may use Agent IDE commands as gateway clients when
  authorized. Local app/workspace context helps target resolution only; it is
  not authorization and it is not adapter authority.

## Adapter Registry Pattern

- `App\Services\AgentIde\AgentIdeAdapterRegistry` is the gateway-owned support
  service for adapter descriptors and command-scoped choices.
- Core descriptors for `opencode` and `polyscope` are always present.
- Reserved command tokens such as `none` and `inherit` are never adapters.
- Configured operator and app callers query the gateway through typed
  `App\Http\Gateway\Requests\AgentIde` requests when command contracts require
  gateway-owned adapter choices or validation.
- The registry response exposes descriptor metadata needed for prompts,
  validation, and capability checks: name, label, source, and capabilities.
- The registry must not expose adapter credentials, local operator-machine
  manifests, active session state, or process output.
- Extension-registered adapters must enter through a gateway-side registration
  surface before commands accept them. Do not scan local extension manifests on
  operator or app callers.

## Effective Adapter Pattern

- Resolve the effective adapter through the shared inheritance chain:
  workspace override when a future workspace-level override exists, app
  override, owning node default, then no configured adapter.
- App scope treats `none` as an explicit no-adapter override and should fail
  messaging with `no_effective_adapter`.
- Node scope treats `none` as clearing the node default and allowing resolution
  to fall through to no configured adapter.
- `inherit` is valid only where the command contract defines a parent setting.
  It is not valid at node scope.
- Formatter or resolver services should return both the adapter name and the
  source scope (`workspace`, `app`, `node`, or `default`) so JSON renderers can
  report the documented source without duplicating resolution logic.

## Target Resolution Pattern

- Explicit command input wins over current-directory inference.
- `--workspace` targets a workspace context and must include or resolve its
  parent app.
- `--app` targets the app main context and must not imply a workspace.
- When inferring from the current directory, workspace context takes precedence
  over parent app context.
- Gateway callers resolve target visibility from gateway database state.
- Operator and app callers forward target-sensitive operations through the
  gateway API so authorization, hidden-target behavior, and adapter delivery
  are decided by gateway-owned state.
- Hidden or unauthorized targets must return `authorization_failed` instead of
  leaking existence.

## Message Delivery Pattern

- Adapter delivery is best-effort communication. Accepted delivery means the
  adapter accepted the message for an active session, not that the requested
  work completed.
- Deliver exactly the message produced by input-mode resolution. Positional
  input trims surrounding whitespace; stdin preserves body content except for
  the one trailing newline commonly added by shells.
- Do not echo full message bodies in command JSON, activity properties, or
  durable Orbit state.
- Do not retry indefinitely. A single adapter delivery failure is a command
  failure with adapter context and stable diagnostics when available.
- Adapter session lookup is adapter-specific and must not cross app or
  workspace authorization boundaries.
- Adapter server health and credentials are repaired through tool-family doctor
  paths, not `agent-ide:message`.

## Gateway API Pattern

- Non-gateway callers use `GatewayConnector` and typed requests under
  `App\Http\Gateway\Requests\AgentIde`.
- Gateway API endpoints return the shared `success` / `error` envelope and
  preserve structured gateway errors through `GatewayApiException`.
- Handlers on the gateway side perform target resolution, authorization, effective
  adapter resolution, registry validation, active-session lookup, and adapter
  delivery.
- Activity logging for `agent-ide:*` should use the cross-cutting Loggable
  contract after the command surface lands. Activity properties must avoid full
  message bodies, credentials, raw adapter output, and secrets.

## Adapter Driver Pattern

- Introduce an adapter delivery contract only when implementing a command that
  needs active-session lookup or message delivery.
- Keep the contract small and command-facing: resolve an active session for an
  app/workspace target, deliver a message, and return stable delivery metadata.
- Workspace creation/removal belongs to workspace commands, even when an
  adapter has workspace capabilities.
- Workspace discovery is a capability consumed by app/workspace pruning flows;
  it does not make the adapter the owner of Orbit workspace state.
- Core adapters can start as deterministic fakes or null-capability adapters in
  in-memory tests until real adapter transport is intentionally ported.

## Test Pattern

- In-memory Pest tests own deterministic command, service, renderer, gateway
  API, authorization, and adapter-delivery coverage.
- Command tests should prove no session creation and no app/workspace/process/
  node/tool mutation occurs during messaging.
- Human and JSON renderer tests should mirror the command-doc renderer files.
- Gateway forwarding tests should fake Saloon requests with
  `MockClient` / `MockResponse`.
- Adapter behavior should be tested through a fake adapter driver registered in
  the container, not by calling external Agent IDE services.
- E2E gate selection is `none` for docs-only abstraction work. Real adapter
  delivery or live transport slices need a separate E2E decision before they
  can be marked fully ported.

## Evidence Pointers

- `docs/domains/15_agent-ide/README.md`
- `docs/domains/15_agent-ide/agent-ide-concepts.md`
- `docs/domains/15_agent-ide/1_agent-ide-message`
- `docs/domains/1_node/10_node-agent-ide`
- `docs/domains/5_app/9_app-agent-ide`
- `docs/abstractions/cross-cutting.md`
- Old evidence: `../orbit-old-may/app/Contracts/AgentIdeAdapterRegistry.php`
- Old evidence: `../orbit-old-may/app/Services/AgentIde/CoreAgentIdeAdapterRegistry.php`
- Old evidence: `../orbit-old-may/app/Contracts/AgentIdeDriver.php`
- Old evidence: `../orbit-old-may/app/Services/AgentIdeManager.php`
- Old evidence: `../orbit-old-may/app/Actions/Processes/HandleProcessEvent.php`
- Old evidence: `../orbit-old-may/app/Actions/Apps/PruneAppWorkspaces.php`

