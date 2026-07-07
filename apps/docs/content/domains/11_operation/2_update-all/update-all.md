# `orbit update:all`

[Back to Operation commands.](../README.md)

Update the gateway, the local Orbit CLI, and every managed Orbit installation
selected for a fleet update.

This is the fleet update command. It is useful after a new Orbit release lands,
or while validating a release candidate from a topology-reachable manifest,
when the operator needs all Orbit-capable nodes to run the same asset set. It
updates Orbit installations only; it does not deploy apps or repair drift.
For release-candidate rehearsal, `orbit manifest:update <url>` may point the
gateway at a stable artifact channel, such as
`channels/live-test/orbit-release-manifest.json`; each run snapshots the
manifest that channel resolves to during `Checking for updates`. Use
`orbit manifest:remove` to return the gateway to the configured default source.

## Usage

```bash
orbit update:all [--json|--stream-json]
```

## Examples

```bash
orbit update:all
orbit update:all --json
orbit update:all --stream-json
```

## Arguments and options

- `--json`: Output the final result as a single JSON envelope.
- `--stream-json`: Stream progress frames as newline-delimited JSON while the
  fleet update runs, followed by one terminal frame. Mutually exclusive with
  `--json`.

## What Happens

`update:all` performs a fleet update authorized through the gateway:

1. Ask the gateway to authorize gateway-admin authority (`*` on the active gateway node). The gateway identifies the calling peer over WireGuard and applies authorization; the CLI does not classify itself.
2. Start a gateway operation. The gateway creates an operation row and returns
   the durable event stream URL promptly. When the request includes an inline
   manifest, the gateway also persists an immutable update plan keyed by
   `operation_run_id` in the start response. When the request omits an inline
   manifest, plan persistence is deferred to the runner so the CLI can keep
   visible progress while the latest release is resolved.
3. The gateway launches a one-shot runner. Deferred starts boot from the
   configured digest-pinned `orbit-gateway` image, or from the currently running
   digest-pinned `orbit_orbit-gateway` service image when no explicit bootstrap
   image is configured. Inline-manifest starts use the target digest from the
   persisted plan. The runner resolves and persists the immutable plan during
   `Checking for updates` when needed, then compares the desired artifact
   identity against the gateway database before any update side effects.

   If the tracked gateway image digest and workload CLI hashes already match the
   desired manifest artifacts, it skips the gateway, local, workload, and
   verification phases. A `topology-candidate` manifest updates when its desired
   artifact hash or digest differs, even if the semantic version is unchanged.
   If the gateway-selected manifest URL is a stable candidate channel, the
   channel is resolved only for this plan.

   After the plan exists, the runner uses only that immutable snapshot for the
   rest of the run.
4. When outdated installations exist, the runner updates the gateway first as
   the fleet version ceiling, then fans out to the caller-local CLI and selected
   workload nodes. Production installs update the native CLI binary artifact;
   source-dev topologies keep `/usr/local/bin/orbit` pointed at
   `<source>/apps/cli/orbit`.
   A later Orbit Agent update slice may let the same immutable update plan
   select both the CLI artifact and Orbit Agent artifact for agent-capable
   workload nodes. Orbit Agent artifact replacement, relaunch, and self-update
   mechanics are not implemented in the current runtime bootstrap.
5. The CLI follows the operation event journal over Server-Sent Events. If the
   gateway service is replaced mid-stream, the CLI reconnects with
   `Last-Event-ID` and replays only events it has not rendered.
6. The runner performs final verification: gateway health, scheduler health,
   CLI execution on selected nodes, and required role image availability.
7. Report every per-installation result and the terminal operation status,
   including partial failures.

When one workload node fails, fan-out continues for the remaining selected
nodes. The workload phase still fails before final verification if any selected
node did not update. The failure result includes the failed node results so
operators see the update failure directly instead of only a later version
verification error.

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

Use `--json` for a single machine-readable result envelope, or `--stream-json`
for progress frames as newline-delimited JSON followed by one terminal frame. See the
[JSON renderer contract](technical/6.2_update-all_output-render_json.md) for
the exact shape of both modes.

## Requirements

- The CLI caller can reach the Orbit gateway.
- The gateway authorizes the calling WireGuard peer with gateway-admin authority
  (`*` on the active gateway node).
- The gateway can reach every selected node through its node execution path
  (currently the classified host SSH update lane; a later Orbit Agent update slice may use
  typed Orbit Agent update jobs for agent-capable nodes, with SSH retained for
  bootstrap, recovery, and fallback).
- The gateway can persist operation rows, event journal rows, immutable update
  plans, and expiring update leases.
- The gateway can launch a one-shot runner from the target `orbit-gateway`
  image with the Docker socket and gateway config root mounted.
- Each selected workload installation has a writable Orbit install root and a
  host `orbit` launcher or an equivalent Orbit CLI entry point local to the node.
- The gateway requires access to the release or candidate CLI artifact source
  referenced by the resolved manifest. Workload targets download CLI binaries
  from the gateway's per-operation artifact endpoint, not directly from GitHub
  or the candidate source. Targets also need permission to write the binary and
  update the user-local launcher link.
- Orbit Agent artifact replacement and relaunch remain deferred. The bootstrap
  does not update itself; current workload targets update the Orbit CLI
  artifact, not an Orbit Agent artifact.
- Gateway update targets require Docker Engine/CLI, Docker Swarm, the
  digest-pinned `orbit-gateway` image or `ORBIT_GATEWAY_IMAGE_ARCHIVE`, the
  gateway config root, and Orbit CA/certificate material.
- Source-dev Docker/Incus development and E2E topology targets require
  access to the mounted checkout and keep `/usr/local/bin/orbit` pointed at
  `<source>/apps/cli/orbit`.

## Related Commands

Use these commands before or after running `orbit update:all`.

- [`update`](../1_update/update.md) - update only the local Orbit installation
- [`manifest:update`](../6_manifest-update/manifest-update.md) - point the
  gateway at a custom release manifest URL
- [`manifest:remove`](../7_manifest-remove/manifest-remove.md) - restore the
  configured default release manifest source
- `doctor` - verify drift after updates once the doctor command contract is
  converted

## Technical Contract

See [`update:all` technical contract](technical/1_update-all.md).
