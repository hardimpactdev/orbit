# Retire target Swamp workers

Node209 alone runs Serve and all Swamp workers. Every target action uses official `@swamp/ssh` from209, with the approved existing key, full stored host pins and existing sudo. Node bootstrap installs no Swamp worker or binary and mints no worker token. The former issuer capability request is cancelled.

Lane21 owns the inventory and retirement execution. Family owners move their callers. Keep each existing worker until its accepted placements, saved roots and worker-installation paths are cleared. Parked families need an orchestrator resume before implementation. This plan does not resume a run, change a pause or stop a worker.

## Inventory checkpoint: 2026-10-31

The read-only managed-host inventory covers all21 registered Nodes. Host reads ran18:59–19:11Z. The source scan is main `7a8791b0e97862e7405269c8ccf305252bdfedd4`:188 workflow files and1390 steps.1201 steps have selectors that may depend on a non209 worker. Dynamic expressions are potential dependencies, not proof that each Node currently runs each workflow. The separately accepted publication receipt is `eb51fd28bfec35ee1587dbaff7f824afde95f8c4` at19:06:27Z; the source scan does not claim that later main is served.

| Node | Observed worker/unit state | Observed native binary paths | Retirement dependencies |
| --- | --- | --- | --- |
|1 VPN | Local worker masked/failed; logical proxy active on209 | `/usr/local/bin/swamp` | Roles, removal participant DNS/network/restore, Metrics; retain local mask |
|18 usexit | Local worker masked/inactive; logical proxy active on209 | `/usr/local/bin/swamp` | Roles, removal and Metrics; retain local mask |
|14 Gateway | Worker active/enabled | `/usr/local/bin/swamp` | Horizon and Process/Schedule controls, Routes, Nodes, Doctor/observation, Conn |
|9 Beast | Worker active/enabled | `/usr/local/bin/swamp`, `/home/nckrtl/.local/bin/swamp`, upgrade/scratch cache copies | Dev Instance/deploy, Nodes, Routes, databases, Tools/Firewall, task providers, local test/operator CLI use |
|13 Shark | Worker active/enabled | `/usr/local/bin/swamp` | TaskVM/Incus, Tasks, roles, Routes, cleanup/reaper and runtime paths |
|12 Sabre | Worker active/enabled | `/usr/local/bin/swamp` | TaskVM/Incus, roles, Routes, Tools/Firewall and cleanup |
|202/206/207/208 TaskVMs | `orbit-swamp-worker.service` failed/static on each | `/usr/local/bin/swamp` on each | Guest runtime/Pi, Tasks, cleanup and enrollment paths that can recreate workers |
|17 Services | Worker activating/enabled; former Serve unit disabled/inactive | `/usr/local/bin/swamp`, `/home/orbit/.local/bin/swamp` | Databases, Routes/DNS/certificates, Analytics, Metrics, Process/Schedule controls |
|4 app-prod | Worker active/enabled | `/usr/local/bin/swamp` | Production Instances/deploy, Routes/certificates, Metrics, Process/Schedule controls |
|11 mini | No matching systemd worker found; macOS | `/Users/nckrtl/.local/bin/swamp` | Operator/CI CLI consumers and macOS account/launch-agent accounting |
|8 nick | No matching systemd worker found; macOS | No binary observed in completed candidate checks | Full filesystem scan timed out; macOS account/launch-agent accounting remains |
|7 orbit-ops;10 polar;15 ipad;45 nick-vm | No pinned managed route; not inspected | Unknown | Owner-supplied pinned route or explicit unmanaged-client disposition; absence is not established |
|210/212 TaskVMs | Pinned address unreachable | Unknown | TaskVM owner must account for failed/removing guests and retained storage; no destroy action implied |
|209 swamp | Retain Serve, normal worker and migration/services pools; obsolete1/18 proxy units active | `/usr/local/bin/swamp`, `/home/orbit/.local/bin/swamp` | Retire only obsolete logical proxies after family clearance; preserve209 pools, SSH identity and host pins |

Broad scans timed out on8/9/11/13/17/209. A targeted supplement hashes installed and known cache paths; it is not a complete filesystem absence proof. Workload `/usr/local/bin/swamp` copies observed hash `38b81986c7203f1e93806fc7b6d38e560a86456ea755a4369a89b17b64d4fbe3`. The209 Serve and Beast official e459 copies hash `bfd72a112412ce48d4c11e8e80a35caefa811683146f7e30795d498382b20768`. Retain exact path/hash rows for other cache copies without executing or classifying an unknown artifact.

The authenticated native read at19:10:55Z found one running `maintenance-projects` root (`84d217e8-c24a-4412-aa8a-5bfda8a270a3`),32 interrupted roots and zero suspended roots. Interrupted Doctor Operation6194/run `546a5044-d3e0-476c-9fd4-8ff1ca037a64` retains Node14. These are observations, not retirement clearance.53 schedules remain; fleet-maintenance, rotation, runtime-retention and subtasks-driver remain paused. Preserve failed history and do not resume or settle roots just to clear retirement.

Lane2's separate read-only saved-record snapshot at19:15:07Z found552 durable automatic-deploy admissions,15 TaskVM reaper admissions and5 worker-rotation admissions. Its pending-ID sample is explicitly truncated. These are pending admissions, not native run IDs; the worker queue read does not cover them. Lane21 must account for the five rotations and the accepted remote installer before retirement, while Deploy2 and TaskVM owners account for their admissions. No pending records were removed.

Machine-readable receipts are held by Lane21: `single-node-fleet-inventory.json`, `single-node-source-placement-inventory.json`, `single-node-active-roots.json`, worker roster/queue/token metadata and the family inventories named in the rollout receipt. Each exact selector row includes file, workflow UUID, job/step, effective target, labels and task type/method.

## Migration order and owners

1. **Light1, then18:** use the existing proved SSH route; Nodes0a/lane11 replace role/converge/Provision selectors, Removal13 replaces participant DNS/network/residue and compensation, Metrics12 replaces runtime/exporter fan-outs. Keep both logical proxy units until all three families' accepted parents stop selecting1/18.
2. **Gateway14, then Beast9:** establish the tracked existing-key contribution and official SSH proof. Processes9 moves Horizon and Process/Schedule controls; Routes8 moves publication/certificate parents; Nodes0a/lane11, Removal13, instance/deploy owners, databases10 and integrations23 move their admitted target selectors. Gateway14's successful historical Horizon run proves the old placement only. Beast operator/native-test binary dependencies must also move before its binaries disappear.
3. **Shark13, Sabre12, then TaskVMs202/206/207/208:** TaskVM5/6037/Tasks owners replace provider/guest/runtime/Pi/cleanup/reaper selectors and remove guest-worker installation from accepted enrollment/image paths. Account for protected groups and retained failed roots. Failed units alone are not clearance. Unreachable210/212 remain separately unresolved.
4. **Services17, then app-prod4:** finish all database, Analytics, Metrics, Route/certificate, Process/Schedule and Instance/deploy selectors. Keep serving work intact. A retirement window removes only the obsolete Swamp footprint; it moves no production data.
5. **Client/operator binaries:** resolve managed routes, macOS launch agents and CLI/CI consumers individually for8/11 and uninspected7/10/15/45. No account or access edge is created by this plan.

This is a dependency order, not permission to bypass a family that is parked or still uses the old source. Other families can build their source changes while earlier Nodes wait. No family switch ETA substitutes for actual accepted-source and root clearance.

## Per-Node retirement procedure

1. Capture the Node's current worker identity, unit/process/binary paths, worker-token and server-access-token names, and the exact source/publication receipt. Recheck release maintenance before a runtime change and announce the Node and unit. Coordinate with Platform; do not touch Serve.
2. Through the normal tracked converge/footprint path, preserve other authorized keys and add the existing209 public key to the managed account. Capture the full public host key. Use the [shared public descriptor](node-official-ssh.md#move-an-ordinary-node); do not copy private keys or hand-edit authorized_keys. For first-key publication only, Nick permits209 to use the existing Gateway14 Node-SSH identity execution copy through official SSH. Restrict that temporary identity to the tracked key-policy action. Its exact workflow must be registered and have installed run/read authority before admission; an existing grant for another workflow name does not cover it. After every target verifies SSH with209's own identity, remove the Gateway-identity execution copy and confirm removal. Do not use it for other family effects.
3. Prove official `@swamp/ssh` from209 with the systemd identityFile, stored pin and actual managed user, then existing sudo where needed. Record run ID, host and result. No fallback transport or new key.
4. Accept and publish every owning family placement change to209 plus official SSH, including parent/recovery/fan-out selectors. Remove accepted paths that reinstall or rotate a target worker. Retain209's own worker maintenance. Account for active/queued dispatches, interrupted/saved roots and owner-supplied checkpoints; resolve them through their owning operation policy. Keep schedule pauses unchanged.
5. Once the source and root prerequisites are clear, stop and disable only that Node's obsolete worker. Remove its tracked unit/footprint and obsolete binary paths, including owned CLI/cache copies when no remaining caller needs them. On1/18 also retire the corresponding proxy unit on209; keep the target's local mask. Preserve209 Serve/workers and the existing SSH key/pins/credential source.
6. Revoke that worker's enrollment token and its worker-specific server-access token through supported native commands. Derive the exact names from the inventory; do not revoke shared Gateway/operator or retained209 pool tokens. Recheck roster/queue, absence of target Swamp processes/binaries/worker units, official SSH reachability and accepted209-only placement. Record the terminal retirement receipt per Node. Preserve run history and audit receipts.

Token revocation and file removal occur only after the same per-Node clearance as worker stop. An idle roster, zero Gateway-bound operations, a source-only draft or a successful standalone canary is insufficient.
