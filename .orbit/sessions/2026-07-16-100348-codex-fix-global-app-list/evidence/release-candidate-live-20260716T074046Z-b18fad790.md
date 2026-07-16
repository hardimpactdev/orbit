# Live release-candidate acceptance

- Timestamp: 2026-07-16 07:38-08:01 UTC
- Version: `0.1.190`
- Build id: `20260716T074046Z-b18fad790`
- Source commit: `b18fad7902a2a913be78cfdaa4e43d37009de82b`
- Primary `main`: `b18fad7902a2a913be78cfdaa4e43d37009de82b`
- `origin/main`: `b18fad7902a2a913be78cfdaa4e43d37009de82b`
- Channel manifest: `https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json`
- Immutable asset prefix: `https://s3.hardimpact.dev/orbit/candidates/20260716T074046Z-b18fad790`
- Gateway image: `ghcr.io/hardimpactdev/orbit-gateway:0.1.190-candidate-20260716T074046Z-b18fad790`
- Gateway digest: `sha256:db3834a24151f8fcd59d4d76a97657abebc70ec001bb490c5ede3c43917b0313`
- Reverb image: `ghcr.io/hardimpactdev/orbit-reverb:0.1.190-candidate-20260716T074046Z-b18fad790`
- Reverb digest: `sha256:246472fe67d38d5a02cd709356cb9afc2746529eadab0cd6a6e948dff5a44d16`

## Artifact verification

- Linux CLI: `84af3d91ec255ae11ae9535090f48695ae9fe27ac39869365224fa5e1a4874af`
- macOS CLI: `65821469b4b7b6ab5033612f32ddc67df39a2375108367aabf2d3105c820a761`
- Linux Agent: `a284dc1f07c4cbf95e50f9505c20b5da8176861312e66866e7dc871d6e4f7f38`
- macOS Agent: `b4f904ef8c665a1242cad82cace493fb8bf41faa4d7267bfed8823aac8f91b48`
- `bin/orbit-release-candidate verify`: PASS for all four hashes.
- Registry inspection resolved the candidate gateway tag to the recorded digest.

## Live topology baseline

- Gateway status: Orbit `0.1.190`.
- Registered fleet: 8 active nodes: `NMBP`, `agent`, `beast`, `database1`,
  `gateway`, `ingress1`, `main1`, and `mini`.
- Pre-update command: `./apps/cli/orbit doctor --all --stream-json`
- Pre-update result: 174 existing issues.
- Per-node counts: NMBP 1, agent 7, beast 159, database1 3, gateway 1,
  ingress1 1, main1 2, mini 0.

## Candidate rollout

- First `update:all` operation: `353b38e3-e13e-4afb-8060-fa8573d1f0ec`
- First activity: `178229`
- First result: failed when Mini's Agent listener dropped during
  `internal:fleet-update:install-cli`; the gateway and preceding workload nodes
  had already updated.
- Recovery proof: `10.6.0.8:9477` became reachable again.
- Successful command:
  `ORBIT_RELEASE_MANIFEST_URL=https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json ./apps/cli/orbit update:all --stream-json`
- Successful operation: `29a54848-648a-407c-b8d6-8ae00c74617c`
- Successful activity: `178361`
- Result: `status=completed`, `manifest_source=topology-candidate`,
  `target_version=0.1.190`, and the recorded gateway digest matched.
- The stream completed with `status=succeeded`.
- Gateway, scheduler, all workload CLI artifacts, all Orbit Agent artifacts,
  and required role images verified.

## Post-update verification

- Gateway and scheduler both run the candidate gateway image pinned to
  `sha256:db3834a24151f8fcd59d4d76a97657abebc70ec001bb490c5ede3c43917b0313`.
- Operations Reverb runs the candidate Reverb image pinned to
  `sha256:246472fe67d38d5a02cd709356cb9afc2746529eadab0cd6a6e948dff5a44d16`.
- `gateway`, `NMBP`, `agent`, `beast`, `database1`, `ingress1`, `main1`, and
  `mini` each report Orbit `0.1.190`.
- `orbit node:list --json` returns all 8 nodes active.
- `orbit app:list --json` returns 25 globally listed logical apps.
- Human `orbit app:list` renders one global table.
- Post-update command: `./apps/cli/orbit doctor --all --stream-json`
- Post-update result: the same 174 issues with identical per-node counts.
- Doctor delta: no new regressions.

## Publication boundary

- No GitHub release was created.
- No GitHub tag was pushed.
- No final `ghcr.io/hardimpactdev/orbit-gateway:0.1.190` image tag was moved.
- The live gateway remains selected on the `live-test` candidate channel.
