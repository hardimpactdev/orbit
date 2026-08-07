# Live topology proof — Orbit 0.1.191 candidate acceptance

## Candidate identity

| Field | Value |
| --- | --- |
| Version | `0.1.191` |
| Build id | `20260807T132433Z-911458734` |
| Source commit | `911458734b7587bb4470a037311242d7005a2e2d` (local `main` == `origin/main`) |
| Candidate gateway image | `ghcr.io/hardimpactdev/orbit-gateway:0.1.191-candidate-20260807T132433Z-911458734` |
| Gateway digest | `sha256:d436710f643f0667a90c5ec6a0d83fc785ab8fa353458e5c6da3b341f32440f2` |
| Channel manifest | `https://s3.hardimpact.dev/orbit/channels/live-test/orbit-release-manifest.json` |
| FrankenPHP promoted digest | `sha256:97ceeea159594185de6b0d3d33c3d2fdc8ca321807c06f7dd67e7ff1fea78710` |

## What this proves

The 0.1.191 candidate was accepted on the live fleet: `update:all` succeeded from
the `topology-candidate` manifest, gateway and scheduler run the digest-pinned
candidate image, all nine workload nodes updated and verified, and fleet doctor
gained no new drift against the pre-release baseline.

## Boundary (do not overclaim)

- This is **live fleet acceptance**, not GitHub publication. No `v0.1.191` tag,
  no GitHub release, no release assets, and no move of the final
  `ghcr.io/hardimpactdev/orbit-gateway:0.1.191` tag were created. Operator
  instruction, verbatim: "That's not the point to github just yet Only life
  topology".
- Doctor exercises probes and convergence checks, **not** application runtimes.
  The launchd `process:start` behavior on NMBP under the new `symfony/process`
  8.1 major is **not** proven here; it needs an actual process start on that
  node. This is the operator's own live verification item.
- The 31 remaining doctor issues are pre-existing, carried from the baseline.

## Method

1. Captured pre-release baseline: `orbit doctor --all --stream-json`,
   `orbit gateway:status --json`, `orbit node:list --json`.
2. Built the candidate from the pushed `origin/main` commit with
   `bin/orbit-release-candidate build`.
3. Pointed the gateway at the candidate channel with `orbit manifest:update`.
4. Ran acceptance from the source CLI with the candidate manifest pinned:
   `ORBIT_RELEASE_MANIFEST_URL=<channel> ./apps/cli/orbit update:all --stream-json`.
5. Re-ran doctor and diffed issue counts keyed by (node, issue key).
6. Promoted the accepted FrankenPHP digest with
   `bin/orbit-release-candidate promote-runtime --accepted`.

## Observed

| Check | Result |
| --- | --- |
| `update:all` | exit 0, `status=succeeded`, `manifest_source=topology-candidate`, `target_version=0.1.191` |
| Gateway service | `orbit-gateway service healthy` then `verification.gateway ... verified`; `gateway:status` reports `0.1.191` (was `0.1.190`) |
| Scheduler service | stopped, restarted, `verification.scheduler ... verified` |
| Workload nodes | 9/9 updated: NMBP, agent, beast, database1, ingress1, main1, mini, services1, gateway |
| Artifact verification | `verification.cli`, `verification.agent`, `verification.role-images` all done |
| `node:list` | succeeds; all 9 nodes `active` |
| Doctor baseline | 42 issues, `healthy=false` |
| Doctor post-update | 31 issues, `healthy=false` |
| New or increased drift | **none** |
| Resolved drift | gateway `process.runtime_backend_unavailable` -4, `process.wireguard_self_route_unavailable` -3, `tool.capability_missing` -2, `proxy.node_probe_failed` -1; ingress1 `proxy.node_probe_failed` -1 |
| Runtime promotion | `PASS frankenphp_digest sha256:97ceeea1...` |

## Files

| File | Content |
| --- | --- |
| `update-all.jsonl` | Full `update:all` stream including per-node steps |
| `doctor-baseline-pre-update.jsonl` | Pre-release fleet doctor stream |
| `doctor-post-update.jsonl` | Post-update fleet doctor stream |
| `candidate.env` | Candidate identity, image, digest, and artifact hashes |
