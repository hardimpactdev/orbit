# Agent IDE Concepts

This document defines agent-IDE-domain vocabulary and invariants. It supports
the agent IDE command contracts and related node/app Agent IDE commands; it does
not override the [Architecture](../../architecture.md).

## Naming convention

Each agent IDE product is referenced by three related identifiers:

| Form | Where it appears | Example |
|---|---|---|
| Marketing name | Prose and product docs | PolyScope, OpenCode |
| Adapter id | Command input (`node:agent-ide`, `instance:agent-ide`), JSON output `agent_ide.adapter` | `polyscope`, `opencode` |
| Tool slug | Tool catalog entries (`tool:install <slug>`) | `polyscope-server`, `opencode-cli` |

The adapter id is the stable string the gateway uses to identify the integration.
The tool slug names the installed capability that can host the adapter on
a node; not every adapter requires a separate tool (some adapters run
entirely client-side). OpenCode uses `opencode-cli` as the tool slug and
`opencode-server` as the related process/runtime unit name.

Autonomous agent tools (`openclaw`, `hermes`) follow a simpler convention: a single tool slug names the runtime, and they have no separate adapter id because they are not interactive IDE adapters — they are Orbit clients running on the `agent` role.

## Adapter Model

These terms define the adapter model that Agent IDE commands use to reach an active session.

- **Agent IDE integration:** Orbit adapter surface for developer- and
  agent-facing workflows. Adapter implementations may live in core Orbit or in
  installed Orbit extensions.
- **Agent IDE adapter:** Gateway-registered adapter implementation that can be
  selected for app or workspace workflows. Core adapter names are `opencode` and
  `polyscope`.
- **Agent IDE adapter registry:** Gateway-owned registry of supported adapter
  names. Commands validate adapter input against this registry; CLI callers do
  not use local manifests or hard-coded fallback lists as authority.
- **Agent IDE adapter registry model:** Internal gateway service that returns
  registered adapter descriptors. A descriptor includes the stable adapter name,
  human label, source (`core` or `extension`), and capability flags such as
  message delivery and workspace discovery. Core descriptors for `opencode` and
  `polyscope` are always present. Extension descriptors are added only through
  the extension registration surface on the gateway.
- **Agent IDE adapter choices API:** Typed gateway read path used by CLI
  gateway clients before prompts and validation. It returns the same registry
  descriptors the gateway uses internally, plus command-owned reserved tokens
  when the request names a scope that supports them. It never returns adapter
  credentials, local manifests, or active session data.
- **Active Agent IDE session:** Adapter-resolved live session for a resolved app
  or workspace context. Messaging commands may deliver to it but do not create
  it.
- **Workspace discovery capability:** Optional adapter capability that reports
  adapter-known workspaces for an app. It informs instance-scoped pruning but does
  not make the adapter the owner of Orbit workspace state.
- **Workspace path resolution capability:** Optional adapter capability that
  reverse-maps an absolute filesystem path to an adapter-managed workspace
  descriptor (workspace name, parent project slug, absolute path, adapter
  workspace id). It enables CWD-driven adoption flows such as
  `workspace:setup` registering a PolyScope worktree on first run. The
  adapter is not the owner of Orbit workspace state; resolved descriptors
  feed an Orbit-side registration that goes through the workspace family's
  standard adoption path.

## Resolution

These terms define the inheritance chain used to resolve the effective adapter for an app or workspace.

- **Node Agent IDE default:** Node-owned gateway configuration set through
  `node:agent-ide`. It is the root of the current inheritance chain.
- **Instance Agent IDE override:** Instance-owned gateway configuration set through
  `instance:agent-ide`. It may select an adapter, clear the app override with
  `inherit`, or explicitly disable Agent IDE resolution for the app and its
  workspaces with `none`.
- **Effective Agent IDE adapter:** Adapter resolved for an app or workspace
  consumer. Resolution checks a future workspace-level override first when it
  exists, then app override, then owning node default, then no configured
  adapter.
- **Agent IDE input token:** Reserved non-adapter input handled by the owning
  command. `inherit` is valid only at scopes with a parent setting; `none`
  clears the node default at node scope and stores an explicit no-adapter
  override at instance scope.

## Communication

This section defines the message delivery model.

- **Agent IDE message:** Best-effort communication sent to the active Agent IDE
  session for a resolved app or workspace context. Accepted delivery means the
  adapter accepted the message; it does not mean the requested work completed.
- **Agent IDE launcher context:** Working-directory context held in
  `ORBIT_HOST_CWD`. The `apps/cli/orbit` entry point, which runs on the node,
  preserves a supplied value or initializes it from `getcwd()` when absent,
  while the host `orbit` launcher only resolves the repo root and execs the
  entry point.
  Production installs still use the native CLI binary artifact; source-mounted
  Docker and Incus development/E2E topologies point `/usr/local/bin/orbit`
  directly at `<source>/apps/cli/orbit`. Agent IDE commands may use it to
  resolve app/workspace defaults, but authorization still comes from the
  gateway's WireGuard peer and grant model.

## Boundaries

These boundaries define what Agent IDE commands own and what they must not touch.

- **Agent-IDE-domain boundaries:** Agent IDE commands own the `agent-ide:*`
  command prefix, adapter communication vocabulary, target-context messaging,
  and effective-adapter consumption rules. They do not own a state family, node
  defaults, app overrides, workspace state, process crash-event history, tool
  lifecycle, adapter server credentials, or Agent IDE session creation.
- **Registry boundary:** The shared adapter registry is gateway-owned support
  infrastructure consumed by node, app, and agent-IDE command domains. It is not
  a state family and does not make the `agent-ide:*` command domain the owner of
  node defaults or app overrides.

## Registry API Contract

The gateway resolves adapter support through an internal
`AgentIdeAdapterRegistry` service. CLI gateway clients issue a typed gateway
request before interactive choice rendering and before non-interactive
validation.

The registry response contains:

| Field | Meaning |
| --- | --- |
| `adapters[].name` | Stable adapter identifier accepted by command input, for example `opencode`. |
| `adapters[].label` | Human prompt label. Defaults to the adapter name when no extension label is registered. |
| `adapters[].source` | `core` for built-in adapters or `extension` for extension-registered adapters. |
| `adapters[].capabilities` | Capability flags such as `message_delivery` and `workspace_discovery`. |
| `reserved_tokens[]` | Command-scope tokens supplied by the requesting command, such as `none` for node scope or `inherit`/`none` for instance scope. Reserved tokens are not registry adapters. |

The registry must:

- treat `opencode` and `polyscope` as core descriptors;
- reject duplicate adapter names across core and extension descriptors;
- keep reserved tokens (`none`, `inherit`) out of the adapter list;
- be the only authority used by gateway validation, including the typed
  read path that CLI gateway clients call before prompts and validation;
- provide deterministic prompt ordering: reserved tokens first in the
  command-defined order, then adapters sorted by name unless a command contract
  defines a more specific order;
- expose only descriptors needed for prompts, validation, and capability
  checks, not credentials, process state, or session data.
