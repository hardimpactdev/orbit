# Agent IDE Concepts

This document defines agent-IDE-domain vocabulary and invariants. It supports
the agent IDE command contracts and related node/app Agent IDE commands; it does
not override the [Blueprint](../../BLUEPRINT.md).

## Adapter Model

- **Agent IDE integration:** Orbit adapter surface for developer- and
  agent-facing workflows. Adapter implementations may live in core Orbit or in
  installed Orbit extensions.
- **Agent IDE adapter:** Gateway-registered adapter implementation that can be
  selected for app or workspace workflows. Core adapter names are `opencode` and
  `polyscope`.
- **Agent IDE adapter registry:** Gateway-owned registry of supported adapter
  names. Commands validate adapter input against this registry; CLI callers do
  not use local manifests or hard-coded fallback lists as authority.
- **Active Agent IDE session:** Adapter-resolved live session for a resolved app
  or workspace context. Messaging commands may deliver to it but do not create
  it.
- **Workspace discovery capability:** Optional adapter capability that reports
  adapter-known workspaces for an app. It informs app-scoped pruning but does
  not make the adapter the owner of Orbit workspace state.

## Resolution

- **Node Agent IDE default:** Node-owned gateway intent set through
  `node:agent-ide`. It is the root of the current inheritance chain.
- **App Agent IDE override:** App-owned gateway intent set through
  `app:agent-ide`. It may select an adapter, clear the app override with
  `inherit`, or explicitly disable Agent IDE resolution for the app and its
  workspaces with `none`.
- **Effective Agent IDE adapter:** Adapter resolved for an app or workspace
  consumer. Resolution checks a future workspace-level override first when it
  exists, then app override, then owning node default, then no configured
  adapter.
- **Agent IDE input token:** Reserved non-adapter input handled by the owning
  command. `inherit` is valid only at scopes with a parent setting; `none`
  clears the node default at node scope and stores an explicit no-adapter
  override at app scope.

## Communication

- **Agent IDE message:** Best-effort communication sent to the active Agent IDE
  session for a resolved app or workspace context. Accepted delivery means the
  adapter accepted the message; it does not mean the requested work completed.

## Boundaries

- **Agent-IDE-domain boundaries:** Agent IDE commands own the `agent-ide:*`
  command prefix, adapter communication vocabulary, target-context messaging,
  and effective-adapter consumption rules. They do not own a state family, node
  defaults, app overrides, workspace state, process crash-event history, tool
  lifecycle, adapter server credentials, or Agent IDE session creation.
