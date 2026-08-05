# `orbit workspace:remove [name]`

[Back to Workspaces commands.](../README.md)

Remove a workspace and its owned runtime artifacts.

`workspace:remove` tears down a development context and safely deletes its
gateway registry record and node-side artifacts. Use it to decommission a
workspace or reset its state.

## Usage

Run this command to tear down a workspace and its owned artifacts.

```bash
orbit workspace:remove [name] [--instance=<app.instance>] [--keep-files] [--force] [--json]
```

Destructive consent is always required before removal side effects start.
Interactive input mode asks for confirmation unless `--force` is supplied.
Non-interactive input mode requires `--force` because prompts are unavailable.
`--json` never implies `--force`.

## Examples

### Remove a workspace with confirmation

Use this form for an interactive removal that prompts for destructive consent.

```bash
orbit workspace:remove feature-api
```

### Remove the current workspace (CWD resolution)

Run inside a registered workspace worktree to omit the name argument.

```bash
cd ~/sites/my-app/.worktrees/feature-docs
orbit workspace:remove
```

### Remove a workspace but keep the worktree files

Pass `--keep-files` to clean up Orbit configuration while preserving the worktree on disk.

```bash
orbit workspace:remove feature-api --keep-files
```

### Force removal without confirmation

Use `--force` in non-interactive environments or scripts that have already obtained consent.

```bash
orbit workspace:remove feature-api --force
```

## Arguments and options

- `name`: workspace name. Optional when running from inside a registered
  workspace directory.
- `--instance=<app.instance>`: the parent app slug or instance selector. Use dot
  notation such as `happie.nmbp` to target one concrete instance.
  Required only when the workspace name is ambiguous across visible targets.
- `--keep-files`: remove Orbit configuration and runtime artifacts (proxy
  routes, inherited processes, runtime container) but leave the workspace worktree on
  the node.
- `--force`: explicit destructive consent. Skips the interactive confirmation
  prompt. Required in non-interactive input mode. `--json` never implies
  `--force`.
- `--json`: Output JSON.

## Behavior Summary

The following steps describe the removal sequence in order.

`workspace:remove` performs gateway-orchestrated removal of a workspace. The
gateway owns the workspace registry record and applies artifact cleanup on the
node through Agent push. The execution sequence has two phases.

1. **Pre-flight:** Resolve the target workspace from `name` or CWD; detect
   self-targeting when the caller is inside the worktree.
2. **Confirmation:** Prompt for destructive consent unless `--force` is used.
   With `--keep-files`, the prompt body explicitly states the worktree will
   be preserved.
3. **Phase A — Gateway configuration (atomic, point of no return):** Delete
   workspace-owned proxy route rows and the `workspace` row in one
   transaction.
4. **Phase B — Node-side application (through Agent push):** Stop traffic, stop
   inherited processes, run teardown steps, remove the runtime container, and remove
   the worktree (skipped with `--keep-files`). Each step reports `removed`,
   `already_absent`, or `failed`; failures become structured warnings.
5. **Drift Monitoring:** Removed workspaces disappear from registry-backed
   workspace command output. Once Phase A succeeds, any failure during Phase B
   is reported as a non-fatal warning that points at the affected
   `doctor --family=<family> --restore`. Workspace-owned node artifacts are
   reported as orphaned workspace drift by
   [`workspace-doctor.md`](../workspace-doctor.md).

Workspace-owned databases are out of scope for this contract revision and are
not removed by `workspace:remove`. Express any database cleanup as a
workspace teardown step.

## Output Summary

The output format depends on whether `--json` is passed.

- **Human:** Framed destructive confirmation followed by progress. Drift
  after Phase A renders as a footer with one line per affected family doctor.
- **JSON:** A machine-readable output. Partial cleanup
  is reported with structured warning metadata and repair guidance.

## Requirements

- CLI caller must reach the Orbit gateway.
- Caller identity must have `workspace:remove` on the workspace's owning node.
  Every workspace uses its selected instance node for cleanup.
- Agent push handles runtime, process, teardown-step, and worktree cleanup on
  the concrete instance node. If cleanup
  cannot finish after workspace configuration removal,
  the command still succeeds and reports warnings with repair commands.
- Destructive consent is required through the interactive confirmation prompt
  or `--force`. Non-interactive input mode requires `--force`.

## Related Commands

These commands work alongside `workspace:remove` when creating, listing, or repairing workspaces.

- [`workspace:new`](../1_workspace-new/workspace-new.md) — create a new workspace
- [`workspace:setup`](../2_workspace-setup/workspace-setup.md) — provision a workspace
- [`workspace:list`](../3_workspace-list/workspace-list.md) — list all workspaces
- [`doctor --family=workspace`](../workspace-doctor.md) — verify and repair workspace drift

## Technical Contract

See [`workspace:remove` technical contract](technical/1_workspace-remove.md).
