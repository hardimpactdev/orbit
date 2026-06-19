# `orbit update:all`

[Back to Operation commands.](../README.md)

Update the gateway, the local Orbit CLI, and every managed Orbit installation
selected for a fleet update.

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

`update:all` performs a fleet update authorized through the gateway:

1. Ask the gateway to authorize gateway-admin authority (`*` on the active gateway node). The gateway identifies the calling peer over WireGuard and applies authorization; the CLI does not classify itself.
2. Start a gateway operation. The gateway creates an operation row, an ordered
   event journal, and an immutable update plan keyed by `operation_run_id`.
   That plan captures the target version, digest-pinned
   `ghcr.io/hardimpactdev/orbit-gateway` image, GitHub Release asset manifest
   snapshot, CLI artifact URLs/hashes, and required role image metadata.
3. The gateway launches a one-shot runner from the target `orbit-gateway` image.
   The runner checks the latest release and probes fleet versions before side
   effects. If every selected installation is already current, it skips the
   gateway, local, workload, and verification phases.
4. When outdated installations exist, the runner updates the gateway first as
   the fleet version ceiling, then fans out to the caller-local CLI and selected
   workload nodes. Production installs update the native CLI binary artifact;
   source-dev topologies keep `/usr/local/bin/orbit` pointed at
   `<source>/apps/cli/orbit`.
5. The CLI follows the operation event journal over Server-Sent Events. If the
   gateway service is replaced mid-stream, the CLI reconnects with
   `Last-Event-ID` and replays only events it has not rendered.
6. The runner performs final verification: gateway health, scheduler health,
   CLI execution on selected nodes, and required role image availability.
7. Report every per-installation result and the terminal operation status,
   including partial failures.

Workload-node fan-out continues remaining selected nodes after one target fails,
but the workload phase fails before final verification if any selected node did
not update. The failure result includes the failed node results so operators see
the update failure directly instead of only a later version-verification error.

`update:all` updates the gateway, the local installation, and active workload
nodes.
**Clients other than the caller are never remote update targets.** Each
client is an operator workstation and updates through `orbit update` on
that machine. When the gateway is the calling peer, the command therefore
updates the gateway installation and selected nodes only.

The command does not create nodes, deploy apps, or repair unrelated family drift.
It may change app runtime artifacts only when role image updates are required by
the release manifest. Run doctor after the update when the operator
needs convergence verification.

## Output

Run `orbit update:all` to see per-node progress and a final summary of updated and failed nodes.

Human output begins with release and fleet version checks, then shows per-node
progress as updates run. The active row blinks while work is in progress; settled
rows report `Done`, `Skipped: already up to date`, or a failure message. Progress
is rendered from the gateway operation event journal, so reconnecting during
gateway replacement does not lose already-recorded state. See the
[terminal output contract](technical/6.1_update-all_output-render_human.md) for
the exact layout.

Use `--json` for machine-readable output. See the
[JSON renderer contract](technical/6.2_update-all_output-render_json.md) for
the exact shape.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin authority
  (`*` on the active gateway node).
- The gateway can reach every selected node through its node execution path (SSH via `RemoteShell`).
- The gateway can persist operation rows, event journal rows, immutable update
  plans, and expiring update leases.
- The gateway can launch a one-shot runner from the target `orbit-gateway`
  image with the Docker socket and gateway config root mounted.
- Each selected workload installation has a writable Orbit install root and a
  host `orbit` launcher or an equivalent Orbit CLI entry point local to the node.
- Production artifact update targets require a reachable release source for the
  CLI binary plus permission to write the binary and update the user-local
  launcher link.
- Gateway update targets require Docker Engine/CLI, Docker Swarm, the
  digest-pinned `orbit-gateway` image or `ORBIT_GATEWAY_IMAGE_ARCHIVE`, the
  gateway config root, and Orbit CA/certificate material.
- Source-dev Docker/Incus development and E2E topology targets require
  access to the mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

## Related Commands

Use these commands before or after running `orbit update:all`.

- [`update`](../1_update/update.md) - update only the local Orbit installation
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
