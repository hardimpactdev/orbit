# `orbit workspace:remove [name]`

[Back to Workspaces commands.](../README.md)

Remove a workspace and its owned runtime artifacts.

`workspace:remove` tears down a development context and safely deletes its
gateway registry record and node-side artifacts. It is used when a workspace
is no longer needed or is being reset.

## Usage

```bash
orbit workspace:remove [name] [--app=<app>] [--keep-files] [--force] [--json]
```

Destructive consent is always required before removal side effects start.
Interactive input mode asks for confirmation unless `--force` is supplied.
Non-interactive input mode requires `--force` because prompts are unavailable.
`--json` never implies `--force`.

## Examples

### Remove a workspace with confirmation

```bash
orbit workspace:remove feature-api
```

### Remove the current workspace (CWD resolution)

```bash
cd ~/sites/my-app/workspaces/feature-docs
orbit workspace:remove
```

### Remove a workspace but keep the worktree files

```bash
orbit workspace:remove feature-api --keep-files
```

### Force removal without confirmation

```bash
orbit workspace:remove feature-api --force
```

## Arguments And Options

- `name`: workspace name. Optional when running from inside a registered
  workspace directory.
- `--app=<app>`: the parent app slug. Required only when the workspace name
  is ambiguous across multiple apps.
- `--keep-files`: remove Orbit intent and runtime artifacts (proxy routes,
  inherited processes, FPM pool) but leave the workspace worktree on the app
  node.
- `--force`: explicit destructive consent. Skips the interactive confirmation
  prompt. Required in non-interactive input mode. `--json` never implies
  `--force`.
- `--json`: Output JSON.

## Behavior Summary

`workspace:remove` performs gateway-orchestrated removal of a workspace. The
gateway owns the workspace registry record and enacts artifact cleanup on the
app node over SSH. The execution sequence has two phases.

1. **Pre-flight:** Resolve the target workspace from `name` or CWD; detect
   self-targeting when the caller is inside the worktree.
2. **Confirmation:** Prompt for destructive consent unless `--force` is used.
   With `--keep-files`, the prompt body explicitly states the worktree will
   be preserved.
3. **Phase A — Gateway intent (atomic, point of no return):** Delete
   workspace-owned proxy route rows and the `workspace` row in one
   transaction.
4. **Phase B — Node-side enactment (over SSH):** Stop traffic, stop
   inherited processes, run teardown steps, remove the FPM pool, and remove
   the worktree (skipped with `--keep-files`). Each step reports `removed`,
   `already_absent`, or `failed`; failures become structured warnings.
5. **Drift Monitoring:** Removed workspaces disappear from registry-backed
   workspace command output. Once Phase A succeeds, any failure during Phase B
   is reported as a non-fatal warning that points at the affected
   `doctor --family=<family> --fix`. Workspace-owned node artifacts are
   reported as orphaned workspace drift by
   [`workspace-doctor.md`](../workspace-doctor.md).

Workspace-owned databases are out of scope for this contract revision and are
not removed by `workspace:remove`. Express any database cleanup as a
workspace teardown step.

## Output Summary

- **Human:** Framed destructive confirmation followed by a step tree. Drift
  after Phase A renders as a footer with one line per affected family doctor.
- **JSON:** A single top-level `success` or `error` envelope. Partial cleanup
  is `success` with structured warnings under `success.meta.warnings[]` (each
  carrying `code`, `family`, `message`, and `next_command`).

## Requirements

- CLI caller must reach the Orbit gateway.
- Must be run from a control or gateway caller. App-node callers are denied
  before prompts or side effects.
- Authorized node identity for the target workspace or parent app.
- Gateway SSH access to the app node is used for artifact cleanup when
  available. If cleanup cannot finish after workspace intent removal, the
  command still succeeds and reports warnings with repair commands.
- Destructive consent is required through the interactive confirmation prompt
  or `--force`. Non-interactive input mode requires `--force`.

## Related Commands

- [`workspace:new`](../1_workspace-new/workspace-new.md) — create a new workspace
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md) — provision a workspace
- [`workspace:list`](../3_workspace-list/workspace-list.md) — list all workspaces
- [`doctor --family=workspace`](../workspace-doctor.md) — verify and repair workspace drift

## Technical Contract

See [`workspace:remove` technical contract](technical/1_workspace-remove.md).
