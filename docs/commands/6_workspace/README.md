# Workspace Commands

Workspace commands manage gateway-owned workspace intent and the app-node
artifacts derived from that intent. A workspace belongs to an app, has a
canonical name, and owns one workspace route lifecycle.

## Domain Rules

- The gateway owns workspace intent.
- Workspace artifacts are enacted by the gateway over SSH on the owning app
  node.
- Workspace name is the canonical Orbit workspace identity. Source-control
  branch/ref metadata is optional and may be absent; when recorded, it is
  descriptive metadata rather than a separate workspace identity.
- Workspace names are identity slugs: lowercase letters, digits, and hyphens
  only. They cannot start or end with a hyphen and are limited to 63
  characters.
- Workspace PHP version is gateway-tracked intent. A workspace inherits the
  parent app PHP version unless a workspace override is stored on the workspace
  row.
- Orbit must not create, require, read, or trust `.php-version` files in app or
  workspace project trees.
- During `doctor --family=workspace --adopt`, project files are adoption hints
  only. `composer.json` is the only project file Orbit may inspect for PHP
  version hints, and only when the workspace is a PHP project.
- Workspace hostnames are represented in `proxy_route` as workspace-owned
  route records. Workspace commands create, update, and remove the workspace
  intent that owns those routes; proxy route registry and backend artifact
  convergence belong to the `proxy_route` family.
- A workspace hostname is the workspace slug prepended to the parent app's
  primary hostname. For a development app this yields
  `{workspace}.{app}.{tld}`.
- Workspaces inherit app process definitions as runtime artifacts. Inherited
  process-unit convergence belongs to the `process` family.
- Workspace setup and teardown step definitions are gateway-owned workspace
  policy. Adding, listing, removing, and ordering those definitions are explicit
  workspace commands, not doctor repair actions.
- Workspace setup and teardown step runs are durable workspace history.
  `workspace:history` and `workspace:log` read that history; doctor verifies
  current workspace reality.

Read commands over workspace registry state are fast gateway database reads
unless their command contract explicitly opts into live inspection. Workspace
runtime drift belongs to [`workspace-doctor.md`](workspace-doctor.md).

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
  "path": "/home/orbit/apps/docs/workspaces/feature-docs",
  "url": "https://feature-docs.docs.test",
  "php_version": "8.5",
  "php_inherited": true,
  "adopted": false,
  "lifecycle_status": "expected"
}
```

| Field | Type | Meaning |
| --- | --- | --- |
| `name` | string | Workspace identity slug. Unique within the parent app. |
| `app` | string | Parent app slug. |
| `node` | string | Owning app-node slug inherited from the parent app. |
| `path` | string | Absolute workspace path on the owning app node. |
| `url` | string | Primary intended workspace URL. |
| `php_version` | string | Effective PHP version for the workspace. This remains flat until Orbit defines a broader version-reporting object for intent, observed node versions, and framework metadata. |
| `php_inherited` | boolean | `true` when the workspace row stores no PHP override and inherits the parent app PHP version; `false` when the workspace row stores an explicit override. |
| `adopted` | boolean | `true` once the workspace path was adopted through `workspace:setup`; `false` for workspace rows created by `workspace:new` or first set up without adoption. |
| `lifecycle_status` | string | Registry intent lifecycle, currently `expected` or `setup-pending`. This is not setup-run status and not a live readiness result. |

Structural fields are always present. Use `null` only for structural fields
whose value is inapplicable; the current canonical workspace entity has no
nullable structural fields.

## Terminology

- **Setup step definition:** gateway-owned workspace policy record created by
  `workspace-setup-step:add` and ordered by setup-step commands.
- **Setup steps phase:** the `workspace:new` / `workspace:setup` execution phase
  that runs setup step definitions sequentially after core workspace artifacts
  are prepared.
- **`phase=setup_steps`:** JSON enum value used when a setup step execution
  fails during the setup steps phase.

## Workspace Caller Role Rule

- Control and gateway callers may run workspace read and write commands when
  authorized by gateway-owned access policy.
- App-node callers may run workspace read commands when authorized.
- `workspace:setup` is the only workspace write command currently allowed from
  an app-node caller, as defined by
  [BLUEPRINT.md#app-node](../../BLUEPRINT.md#app-node). It is a local workflow
  exception for preparing the workspace the caller is already working inside.
- App-node callers may not run `workspace:new`, `workspace:remove`, setup-step
  mutation, teardown-step mutation, or other gateway-owned workspace policy
  mutations unless a command explicitly documents a future exception.
- Local app-node context may resolve defaults, but it is not authorization.

## Lifecycle Step Environment

Workspace setup and teardown steps run in the workspace path on the owning app
node. They receive a lifecycle environment that is separate from process runtime
environment.

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

1. [`orbit workspace:new [name]`](1_workspace-new/workspace-new.md)
2. [`orbit workspace:setup [name]`](2_workspace-setup/workspace-setup.md)
3. [`orbit workspace:list`](3_workspace-list/workspace-list.md)
4. [`orbit workspace:show [name]`](4_workspace-show/workspace-show.md)
5. [`orbit workspace:remove [name]`](5_workspace-remove/workspace-remove.md)
6. [`orbit workspace:history [name]`](6_workspace-history/workspace-history.md)
7. [`orbit workspace:log [run]`](7_workspace-log/workspace-log.md)
8. [`orbit workspace-setup-step:add`](8_workspace-setup-step-add/workspace-setup-step-add.md)
9. [`orbit workspace-setup-step:list`](9_workspace-setup-step-list/workspace-setup-step-list.md)
10. [`orbit workspace-setup-step:remove`](10_workspace-setup-step-remove/workspace-setup-step-remove.md)
11. [`orbit workspace-teardown-step:add`](11_workspace-teardown-step-add/workspace-teardown-step-add.md)
12. [`orbit workspace-teardown-step:list`](12_workspace-teardown-step-list/workspace-teardown-step-list.md)
13. [`orbit workspace-teardown-step:remove`](13_workspace-teardown-step-remove/workspace-teardown-step-remove.md)

## Related

- [`doctor --family=workspace`](workspace-doctor.md)
- [`doctor --family=app`](../5_app/app-doctor.md)
- [`doctor --family=node`](../1_node/node-doctor.md)
