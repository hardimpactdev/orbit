# Direct Node settings

`operation-node-settings` receives one admitted Operation identity. Its single
Node-local step uses `GatewayOperation` and `directOperation` from the shared
boundary. It reads only safe admitted storage input and the captured agent pin,
runs the fixed local `node.prepare-storage-root` primitive, and projects a
bounded result. Its exact target is `orbit-node-<node_id>`; there is no
fallback. Swamp owns placement, behavior locking, recovery, cancellation and
retention.

Only the Gateway start response binds the root native run ID. Worker reads and
results refuse until that binding exists. The configured control-plane peer
retains its authority; another Node worker cannot read or project this intent.
The Gateway declaration uses the shared `ORBIT_SWAMP_DIRECT_WRITERS` map,
default `{}`. No family token, controller, request, route or relay is added.

The filesystem action is convergent. Native recovery after an uncertain reply
can repeat its check. Raw agent output is bounded in memory and never persisted.
There are no custom receipt resources, retention overrides or data-version pins.

Keep this kind polled until the exact Node worker is enrolled, the existing run
credential has its approved exact workflow scope, the root action has an
approved thin privilege boundary, and a dev/canary run proves
start-binding/first-read ordering and the final projection. Preserve the current
worker unit. This source changes no live privilege, credentials, grants,
enrollment or writer selection. Use native Swamp cancellation/recovery tooling.

After dev proof, select `node.settings: direct` reversibly in the shared map.
After production success, delete the replaced settings path and tests. Existing
polled intents retain their original writer. Nodes + Fleet remains unfinished
until its other kinds move and its total Gateway diff is negative.
