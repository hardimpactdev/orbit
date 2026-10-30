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

Workers run as the Node's configured system user and use that user's existing
passwordless sudo. The fixed local command receives the admitted agent digest
and a 30-second budget. The agent refuses a different binary digest, symlinked
path components, invalid ownership or a root writable by other accounts. The
workflow limits its own transport to 35 seconds and bounds stdout to 16 KiB in
memory; stderr is discarded. No raw output is persisted.

The Gateway's per-kind switch is currently scoped to root Node 209 requests,
matching this workflow's placement. Other Nodes and child admissions keep their
polled writer. The default map remains empty. A process-local direct canary must
succeed before the persisted switch changes; normal production success then
permits deletion of the path it replaced. Native cancellation proof alone is
insufficient. Setting `node.settings: polled` reverses new admissions; each
existing intent keeps its writer. The old path stays for callers outside the
proven scope until their exact-Node workers are live and proven.

The Node 209 binding canary (Operation 4756) projected cancellation without a
root call. The real sudo canary (5136) checked the existing `/home/orbit/apps`
root and projected `prepared: true`. It took 1.277 seconds from owning admission
to start acknowledgement; no old-path action latency baseline is available.
No worker unit, sudo policy, grant, token or package sandbox change is part of
this source slice. Nodes + Fleet remains unfinished until its other kinds move
and its total Gateway diff is negative.
