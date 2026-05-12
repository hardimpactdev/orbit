# `orbit workspace:new [name]`

[Back to Workspaces commands.](../README.md)

**Purpose:** Create a new workspace for an app.

**Description:** Writes initial gateway workspace intent for an application,
creates the workspace source through the effective workspace source driver, and
then runs the workspace setup pipeline.
Used to organize development environments and establish a scope for shared
resources and configurations.

**Owner:** `workspace`.

**Effects:** Write, stream.

**Prerequisites:**
- The CLI caller can reach the Orbit gateway.
- The current node identity is authorized to manage the target app.
- The gateway can reach the owning app node over SSH.

**Behavior:**
- Creates initial workspace intent on the gateway.
- Creates a new workspace source for the parent app on the owning app node.
  Generic and OpenCode-backed sources use git worktrees; Polyscope-backed
  sources are provisioned through the Polyscope SDK.
- Runs the same setup behavior exposed by
  [`workspace:setup`](../2_workspace-setup/workspace-setup.md):
  - creates workspace-owned proxy routes;
  - renders workspace PHP-FPM artifacts;
  - converges inherited process artifacts;
  - executes configured workspace setup steps;
  - performs the setup-time HTTP probe.

**Inputs:**
- `name`: workspace slug; lowercase letters, digits, and hyphens only, max
  63 characters. The reserved name `main` is rejected. Must be unique within
  the parent app.
- `--app=<app>`: parent app. When omitted, Orbit infers the parent app
  from gateway-authoritative metadata: an `.orbit/config` marker on the
  caller filesystem, or a gateway path-ownership lookup that matches the
  current working directory against registered app and workspace paths.
- `--base=<ref>`: source git ref used to create the worktree. Default:
  `main`.
- `--php-version=<version>`: workspace PHP version override. When omitted,
  the workspace inherits the parent app's PHP version.
- `--json`: output structured JSON (forces non-interactive mode).

**Output:**
- Human output is a step tree covering worktree creation, runtime artifacts,
  proxy route registration, and setup step execution.
- JSON output includes the new workspace record.

**Examples:**
```bash
orbit workspace:new feature-a --app=my-app
orbit workspace:new bugfix-1 --app=my-app --base=production
```

**Related Commands:**
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md): Re-converge or repair an existing workspace.
- [`workspace:remove`](../5_workspace-remove/workspace-remove.md): Delete a workspace and its artifacts.
- [`doctor --family=workspace`](../workspace-doctor.md): Verify workspace reality.

---

[Technical Contract](technical/1_workspace-new.md)
