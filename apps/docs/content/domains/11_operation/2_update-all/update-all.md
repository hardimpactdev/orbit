# `orbit update:all`

[Back to Operation commands.](../README.md)

Update the local Orbit installation and every managed Orbit installation selected
for a fleet update.

This is the fleet update command. It is useful after a new Orbit release lands
and the operator needs all Orbit-capable nodes to run the same version. It
updates Orbit installations only; it does not deploy apps or repair drift.

## Usage

```bash
orbit update:all [--json]
```

## Examples

```bash
orbit update:all
orbit update:all --json
```

## Arguments and options

- `--json`: Output JSON.

## What Happens

`update:all` performs a gateway-authorized fleet update:

1. Ask the gateway to authorize gateway-admin authority (`*` on the active gateway node). The gateway identifies the calling peer over WireGuard and applies authorization; the CLI does not classify itself.
2. Update the caller-local installation and the gateway installation using the
   same role-aware sequence as [`orbit update`](../1_update/update.md):
   production installs update the native CLI binary artifact, while
   source-mounted Docker/Incus development and E2E lanes keep
   `/usr/local/bin/orbit` pointed at `<source>/apps/cli/orbit` and update by
   changing the mounted source. Gateway dependencies and migrations run inside
   gateway `orbit-runtime`.
3. After the gateway installation succeeds, the gateway updates selected
   remote app/workload-role installations in parallel, up to four targets at a
   time. Source-mounted topology nodes update through the mounted source and
   keep the CLI symlink pointed at `<source>/apps/cli/orbit`. Workload/app-role
   nodes are gateway clients with role-specific runtime containers; any
   remaining workload-node `orbit-runtime` usage is compatibility scope
   outside the source-mounted live topology contract. The gateway is the only
   node that opens SSH connections to nodes; the CLI never SSHes to other
   nodes itself.
4. Report every per-installation result, including partial failures.

`update:all` updates the local installation, the gateway, and active nodes.
**Clients other than the caller are never remote update targets.** Each
client is an operator workstation and updates through `orbit update` on
that machine. When the gateway is the calling peer, the command therefore
updates the gateway installation and selected nodes only.

The command does not create nodes, deploy apps, change app runtime artifacts, or
repair unrelated family drift. Run doctor after the update when the operator
needs convergence verification.

## Output

Run `orbit update:all` to see per-node progress and a final summary of updated and failed nodes.

Human output shows per-node progress and a final summary. Rows for selected
targets whose update has not started yet show `Waiting`.

Use `--json` for machine-readable output. See the
[JSON renderer contract](technical/6.2_update-all_output-render_json.md) for
the exact shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin authority
  (`*` on the active gateway node).
- The gateway can reach every selected node through its node execution path (SSH via `RemoteShell`).
- Each selected installation has a writable Orbit install root and a host
  `orbit` launcher or equivalent node-local Orbit CLI entry point.
- Production artifact update targets require a reachable release source for the
  CLI binary plus permission to write the binary and update the launcher link.
- Gateway runtime update targets require Docker and gateway `orbit-runtime` for
  dependency installation and migrations.
- Source-mounted Docker/Incus development and E2E topology targets require
  access to the mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

## Related Commands

Use these commands before or after running `orbit update:all`.

- [`update`](../1_update/update.md) - update only the local Orbit installation
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
