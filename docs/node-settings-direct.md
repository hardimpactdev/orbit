# Direct Node settings

`operation-node-settings` receives one admitted Operation identity. Its single
Node-local step uses `GatewayOperation` and `directOperation` from the shared
boundary. It reads only safe admitted storage input and the captured agent pin,
runs the fixed local `node.prepare-storage-root` primitive, and projects a
bounded result. During the migration canary, placement is `pool=migration`
on the existing Node 209 workers. Both workflow input and behavior arguments
accept only Node 209 so the local action cannot run for another Node. Exact
`orbit-node-<node_id>` placement follows per-Node worker enrollment. Swamp owns
placement, behavior locking, recovery, cancellation and retention.

Only the Gateway start response binds the root native run ID. Worker reads and
results refuse until that binding exists. The configured control-plane peer
retains its authority; another Node worker cannot read or project this intent.
The Gateway declaration uses the shared `ORBIT_SWAMP_DIRECT_WRITERS` map,
default `{}`. No family token, controller, request, route or relay is added.

The filesystem action is convergent. Native recovery after an uncertain reply
can repeat its check. Raw agent output is bounded in memory and never persisted.
There are no custom receipt resources, retention overrides or data-version pins.

Keep this kind polled while proving the Node 209 canary. Lane 16 extends the
existing run credential for this workflow with run-and-status access only and
records the change in `FLEET-CHANGES`; the orchestrator pre-approved these
workflow extensions. The root action still needs the approved thin privilege
boundary. A dev/canary run must prove start-binding/first-read ordering and the
final projection before takeover. A run cancelled before the action can prove
ordering without calling root; it does not prove storage success. Preserve the
current worker unit. This source changes no live privilege, credentials, grants,
enrollment or writer selection. Use native Swamp cancellation/recovery tooling.

Admit the Node 209 canary with a process-local writer override; keep the
persisted map polled. Do not enable the kind across the fleet while the canary
accepts only Node 209. After exact Node placement and dev proof, select
`node.settings: direct` reversibly in the shared map.
After production success, delete the replaced settings path and tests. Existing
polled intents retain their original writer. Nodes + Fleet remains unfinished
until its other kinds move and its total Gateway diff is negative.
