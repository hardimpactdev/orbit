# Workspace Commands

Workspace commands manage gateway-owned workspace configuration and the
app-role artifacts derived from that configuration. A workspace belongs to an
app, has a canonical name, and owns one workspace route lifecycle.

## Domain Rules

These rules govern all workspace family commands.

- The gateway owns workspace configuration.
- Workspace artifacts are applied by the gateway over SSH on the owning app
  node.
- Workspace name is the canonical Orbit workspace identity. Source-control
  branch/ref metadata is optional and may be absent; when recorded, it is
  descriptive metadata rather than a separate workspace identity.
- Workspace names are identity slugs: lowercase letters, digits, and hyphens
  only. They cannot start or end with a hyphen and are limited to 63
  characters.
- Workspace PHP version is gateway-tracked configuration. A workspace inherits
  the parent app PHP version unless a workspace override is stored on the
  workspace row.
- Orbit must not create, require, read, or trust `.php-version` files in app or
  workspace project trees.
- During `doctor --fix --family=workspace --adopt`, project files are adoption hints
  only. `composer.json` is the only project file Orbit may inspect for PHP
  version hints, and only when the workspace is a PHP project.
- Workspace hostnames are represented in `proxy` as workspace-owned
  route records. Workspace commands create, update, and remove the workspace
  configuration that owns those routes; proxy route registry and backend
  artifact convergence belong to the `proxy` family.
- A workspace hostname is the workspace slug prepended to the parent app's
  primary hostname. For a development app this yields
  `{workspace}.{app}.{tld}`.
- Workspaces inherit app process definitions as runtime units. Each inherited
  runtime unit becomes a separate Supervisor program owned by the workspace.
  It has its own program name, working directory, environment block, and log
  paths — distinct from the main app instance and from sibling workspaces.
  The parent app's process definition supplies the shared fields (command,
  restart policy, crash notification policy). The workspace context supplies
  the per-instance fields (working directory, workspace-specific URL,
  Orbit-managed TLS material, and log paths scoped to the program name).
  Runtime unit convergence belongs to the `process` family.
- Workspace setup and teardown step definitions are gateway-owned workspace
  policy. Adding, listing, removing, and ordering those definitions are explicit
  workspace commands, not doctor repair actions.
- Workspace setup and teardown step runs are durable workspace history.
  `workspace:history` and `workspace:log` read that history; doctor verifies
  current workspace reality.

## Workspace Source Drivers

Workspace source creation is driver-owned. `workspace:new` resolves the parent
app's effective agent IDE adapter from app configuration, then node defaults,
then no adapter. The selected source driver creates the source directory and
returns the physical path that Orbit stores on the gateway workspace record.

- **Generic worktree driver:** used when no effective adapter exists. It
  creates a Git worktree at `<app path>/.worktrees/<workspace>` using branch
  `<workspace>` from the requested `--base` ref. Generic worktree rows store
  `agent_ide.adapter=null` and `agent_ide.workspace_id=null`.
- **OpenCode driver:** used when the effective adapter is `opencode`. It
  resolves the parent OpenCode project, asks OpenCode to create a UI-visible
  workspace, then aligns the returned workspace worktree to branch
  `<workspace>` from the requested `--base` ref. Orbit stores
  `agent_ide.adapter=opencode`, the returned workspace path, and the OpenCode
  session id when session creation succeeds (stored on a best-effort basis).
- **PolyScope driver:** used when the effective adapter is `polyscope`. It
  creates the workspace through the PolyScope SDK using the node's
  PolyScope server identity and the parent app's PolyScope repository id.
  Orbit stores the PolyScope-returned path and workspace id. PolyScope paths
  are allowed to live outside the parent app path, for example under
  `~/.polyscope/clones/...`.

No workspace command may derive a physical path as `<app path>/<workspace>`.
Setup, runtime rendering, doctor checks, and teardown use the path stored on
the workspace row.

Read commands over workspace registry state are fast gateway database reads
unless their command contract explicitly opts into live inspection. Workspace
runtime drift belongs to [`workspace-doctor.md`](workspace-doctor.md).
Implementation-shape details for the process manager, Supervisor programs, and
gateway-to-node application live in
[tech-stack.md#process-manager](../../tech-stack.md#process-manager),
[tech-stack.md#scheduler](../../tech-stack.md#scheduler), and
[tech-stack.md#gateway-to-node](../../tech-stack.md#gateway-to-node).

Workspace registry-only reads — `workspace:show`, `workspace:history`,
`workspace:list`, and `workspace:log` for stored history — do not require a
live process manager. `workspace:new`, `workspace:setup`, and
`workspace:remove` require a live process manager on the owning node
when they create, update, remove, or verify inherited runtime units.

## Workspace JSON Entity

Workspace-family JSON renderers that return a workspace entity embed the same
canonical shape under `success.data.workspace`. Command-specific result state,
such as the git ref used by `workspace:new`, belongs beside the entity rather
than inside it.

```json
{
  "name": "feature-docs",
  "app": "docs",
  "node": "app-1",
  "path": "/home/orbit/apps/docs/.worktrees/feature-docs",
  "url": "https://feature-docs.docs.test",
  "php_version": "8.5",
  "php_inherited": true,
  "agent_ide": {
    "adapter": null,
    "workspace_id": null
  },
  "adopted": false,
  "lifecycle_status": "expected"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Workspace identity slug. Unique within the parent app. |
| `app` | string | Parent app slug. |
| `node` | string | Owning app-role slug inherited from the parent app. |
| `path` | string | Absolute workspace path on the owning node. |
| `url` | string | Primary intended workspace URL. |
| `php_version` | string | Effective PHP version for the workspace. This remains flat until Orbit defines a broader version-reporting object for configuration, observed node versions, and framework metadata. |
| `php_inherited` | boolean | `true` when the workspace row stores no PHP override and inherits the parent app PHP version; `false` when the workspace row stores an explicit override. |
| `agent_ide` | object | Adapter metadata captured for the workspace source. `agent_ide.adapter` is `null` for generic worktrees; `agent_ide.workspace_id` stores the adapter-side workspace id when one exists. |
| `adopted` | boolean | `true` once the workspace path was adopted through `workspace:setup`; `false` for workspace rows created by `workspace:new` or first set up without adoption. |
| `lifecycle_status` | string | Registry configuration lifecycle, currently `expected` or `setup-pending`. This is not setup-run status and not a live readiness result. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable, such as generic worktrees without an adapter-side
workspace id.

## Terminology

The terms below define the key vocabulary used across workspace command contracts.

- **Setup step definition:** A workspace policy record owned by the gateway, created by
  `workspace-setup-step:add` and ordered by setup-step commands.
- **Setup steps phase:** the `workspace:new` / `workspace:setup` execution phase
  that runs setup step definitions sequentially after core workspace artifacts
  are prepared.
- **`phase=setup_steps`:** JSON enum value used when a setup step execution
  fails during the setup steps phase.

## Authorization

The CLI is a thin gateway client. The gateway authenticates the caller's
WireGuard peer and applies the scoped permission set stored on the grant that
connects the caller to the workspace's owning app-role node. The CLI does not check or branch on caller role locally.

Self-targeting workspace commands — `workspace:setup` from inside a workspace
path on the owning app-role node — flow through the gateway like any other
command and are authorized by the node's self-grant. See [Architecture:
Self-grants and
self-serving](../../architecture.md#self-grants-and-self-serving). The
gateway dispatches setup steps back to the same node through `RemoteShell`;
the CLI never applies artifacts locally.

Local context on the caller filesystem may resolve defaults (parent app,
workspace identity), but it is never used as authorization.

## Lifecycle Step Environment

Workspace setup and teardown steps run in the workspace path on the owning app
node. They receive a lifecycle environment that is separate from process runtime
environment.

Lifecycle step command text is stored as supplied and is not a template.
Scripts that need Orbit context should read the environment variables below
instead of depending on command-string substitution.

| Variable | Value | Why it is exposed |
| --- | --- | --- |
| `ORBIT_APP` | Parent app slug | Lets scripts identify the app that owns the workspace. |
| `ORBIT_APP_PATH` | Parent app root path | Lets scripts inspect or copy files from the main app. |
| `ORBIT_WORKSPACE_NAME` | Workspace slug | Lets scripts branch on workspace identity. |
| `ORBIT_WORKSPACE_PATH` | Workspace path | Lets scripts use the workspace path without recomputing it. |
| `ORBIT_URL` | Workspace HTTPS URL | Lets scripts write canonical URL config such as `.env` values. |
| `ORBIT_PHP_VERSION` | Effective workspace PHP version | Lets scripts run PHP-version-specific setup. |
| `VITE_APP_URL` | Workspace HTTPS URL | Keeps Vite-aware app config aligned with the workspace URL. |
| `VITE_VALET_HOST` | Workspace host without scheme | Supports Laravel Vite TLS detection compatibility. |

## Commands

The following commands are available in the `workspace` family.

### Core workspace commands

These commands create, inspect, and tear down workspaces themselves.

1. [`orbit workspace:new [name]`](1_workspace-new/workspace-new.md)
2. [`orbit workspace:setup [name]`](2_workspace-setup/workspace-setup.md)
3. [`orbit workspace:list`](3_workspace-list/workspace-list.md)
4. [`orbit workspace:show [name]`](4_workspace-show/workspace-show.md)
5. [`orbit workspace:remove [name]`](5_workspace-remove/workspace-remove.md)
6. [`orbit workspace:history [name]`](6_workspace-history/workspace-history.md)
7. [`orbit workspace:log [run]`](7_workspace-log/workspace-log.md)

### Step management commands

These commands manage the setup and teardown step policy that runs during workspace lifecycle events.

8. [`orbit workspace-setup-step:add`](8_workspace-setup-step-add/workspace-setup-step-add.md)
9. [`orbit workspace-setup-step:list`](9_workspace-setup-step-list/workspace-setup-step-list.md)
10. [`orbit workspace-setup-step:remove`](10_workspace-setup-step-remove/workspace-setup-step-remove.md)
11. [`orbit workspace-teardown-step:add`](11_workspace-teardown-step-add/workspace-teardown-step-add.md)
12. [`orbit workspace-teardown-step:list`](12_workspace-teardown-step-list/workspace-teardown-step-list.md)
13. [`orbit workspace-teardown-step:remove`](13_workspace-teardown-step-remove/workspace-teardown-step-remove.md)

## Related

These doctor commands verify the families that workspace commands depend on.

- [`doctor --family=workspace`](workspace-doctor.md)
- [`doctor --family=app`](../5_app/app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
