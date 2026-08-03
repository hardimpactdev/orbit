# `orbit tool:remove <tool>`

[Back to Tool commands.](../README.md)

Remove a managed tool from a node when supported.

`tool:remove` is a destructive command. It removes Orbit-managed artifacts for a
tool and removes the gateway tool row only through the tool definition's
supported removal path.

## Usage

```bash
orbit tool:remove <tool> [--instance=<project.instance>] [--node=<node>] [--force] [--json]
```

## Examples

```bash
orbit tool:remove composer --node=app-1
orbit tool:remove opencode-cli --instance=docs.development --force
orbit tool:remove composer --node=app-1 --json --force
```

## Arguments and options

- `tool`: Tool name from Orbit's tool catalog.
- `--node`: Target node. Defaults to local `node:default` when configured.
- `--instance`: Resolve the target node from a concrete instance. Bare logical
  shorthand is valid only when exactly one instance is visible.
- `--force`: Confirm destructive removal or skip the interactive confirmation
  prompt.
- `--json`: Output JSON and select non-interactive input mode. It does not
  provide destructive consent.

Target context is required when neither `--node`, `--instance`, nor local
`node:default` resolves a node. The command never guesses the only visible instance
instance as the target. Every non-interactive removal requires `--force`, including
JSON use. Interactive TTY use prompts for confirmation when `--force` is absent.

## What Happens

Run this command to remove Orbit-managed artifacts for a tool and delete its gateway row through the supported removal path.

`tool:remove`:

1. Resolves the target node and registered tool row.
2. Verifies the tool supports managed removal.
3. Requires destructive consent from `--force` or an interactive confirmation
   prompt. Output mode never grants consent.
4. When the tool definition declares a `relatedProcess()`, removes that
   process intent and runtime unit first (matched by process `name` and
   `tool`), before tool binary/home teardown, so a restarting unit cannot
   race cleanup. Process runtime-unit warnings from that step are returned
   on the removal result when present.
5. Removes managed node artifacts through the gateway.
6. Removes tool-owned credential material and service endpoint configuration when the
   selected tool owns those artifacts.
7. Removes the gateway tool row when cleanup succeeds.
8. Removes matching tool-owned proxy routes for that tool on the target node
   through the same backend/TLS cleanup path as `proxy:remove --force`
   (`ProxyRouteFixer::removeExtra`), then deletes the registry rows. When
   backend cleanup fails the registry row is kept so the operator can retry.
9. Reports partial cleanup if gateway configuration and node reality diverge.

The gateway cleans up its tool row and tool-owned configuration locally.
Target-node cleanup uses Agent push; `tool:remove` exposes no node transport
selector and never falls back to SSH.

Tools that currently declare a related process include `hermes` (`orbit-hermes-dashboard`), `opencode-cli`
(`opencode-server`), and `polyscope-server` (`polyscope-server`).

The command does not remove unrelated user-managed data unless the tool
definition explicitly owns that data.

### OpenClaw removal-only migration

OpenClaw is **not** a supported first-party agent tool (no install, update,
reconfigure, or credentials). The exact slug `openclaw` remains accepted by
`tool:remove` as a **removal-only migration**:

```bash
orbit tool:remove openclaw --node=<agent-node> --force --json
```

That path runs even when no `NodeTool` row remains. It stops residual
OpenClaw systemd/user units, uses privileged `sudo ss` to terminate
listeners on historical port `18789` (including agent-owned PIDs), kills
leftover agent-owned OpenClaw processes, deletes `/home/agent/.openclaw`,
clears matching process intent, and only then removes tool-owned proxy
backend/TLS plus registry rows. Hermes is not affected.

Host cleanup success is **verified**: the script exits nonzero (and
`tool:remove` fails with `tool.remote_action_failed`, without deleting
proxy/tool rows after the script step) if port `18789` is still listening,
an OpenClaw process remains, or `/home/agent/.openclaw` still exists.
Successful historical process-unit removal alone is not sufficient proof
that the runtime is gone.

## Output

Use `--json` to get a machine-readable result; omit it for progress.

Human output shows progress for confirmation, node cleanup, and gateway
configuration removal.

Use `--json` for the machine-readable removal outcome and any partial-cleanup
warnings.

## Requirements

- The CLI caller can reach the Orbit gateway, or the command is running on the
  gateway.
- The current node identity is authorized to manage tools for the selected node
  or instance's serving node.
- The tool is registered for the resolved node.
- The tool definition supports managed removal.
- The gateway can reach the target node through Orbit's node execution
  primitive.

## Related Commands

Use these commands to audit managed tool state without removing the tool
capability.

- [`doctor --family=tool`](../tool-doctor.md) - report leftover managed artifacts

## Technical Contract

See [`tool-remove` technical contract](technical/1_tool-remove.md).
