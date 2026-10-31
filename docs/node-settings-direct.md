# Direct Node settings

`operation-node-settings` receives one admitted Operation identity. Its single
Node-local step uses `GatewayOperation` and `directOperation` from the shared
boundary. It reads only safe admitted storage input and the captured agent pin,
reads the configured account locally, checks its actual home and group, then
runs the fixed local `node.prepare-storage-root` primitive, and projects a
bounded result. Each step targets the admitted `orbit-node-<node_id>` with the
same Node label, including Node 209. Swamp owns placement, behavior locking,
recovery, cancellation and retention.

Only the Gateway start response binds the root native run ID. Worker reads and
results refuse until that binding exists. The configured control-plane peer
retains its authority; another Node worker cannot read or project this intent.
The Gateway declaration uses the shared `ORBIT_SWAMP_DIRECT_WRITERS` map,
default `{}`. No family token, controller, request, route or relay is added.

The admitted input contains the requested root or null, configured username and
a snapshot of protected and managed-checkout paths. Gateway admission performs
no account lookup or agent installation. Swamp resolves the actual home and
primary group; a null root selects that home’s `apps` directory. Home ancestors,
hidden home control paths and admitted excluded paths refuse before mutation.

The filesystem action is convergent. Native recovery after an uncertain reply
can repeat its check. Raw agent output is bounded in memory and never persisted.
There are no custom receipt resources, retention overrides or data-version pins.

Workers run as the Node's configured system user and use that user's existing
passwordless sudo. The fixed local command receives the admitted agent digest
and a 30-second budget. The agent refuses a different binary digest, symlinked
path components, invalid ownership or a root writable by other accounts. The
local account lookup has a 10-second command budget; storage preparation has a
30-second command budget. Each supervised call permits five seconds for
transport shutdown and bounds stdout to 16 KiB in memory; stderr is discarded.
No raw output is persisted.

The kind defaults to direct with the shared writer map empty. Explicit polled
admission refuses; rollback reverts the owning conversion. The owning slice
removes the polled registration, PHP execution contract and receipt tests. Other
Node kinds and enrollment preparation retain their current paths. Admission
requires a managed Node in the operator worker rollout; workerless VPN/usexit
refuse before recording an apps-root action. State-only patches remain
available. If the canary needs released code, canary a safe target immediately
after release and revert on failure. Source and fixture proof alone do not
establish live takeover.

Native fixtures use `tests/native_harness.py` for isolated CLI runs and scratch
repositories. The settings fixture exercises local account and storage checks on
disposable paths; the retained Node fixture covers remaining polled kinds.
Neither fixture proves live placement or the full-kind writer.

The earlier scoped Node 209 binding canary (Operation 4756) projected
cancellation without a root call. The real sudo canary (5136) checked the
existing `/home/orbit/apps` root and projected `prepared: true`. These precede
the local-account lookup and full-kind conversion, so they do not establish its
takeover. The earlier start acknowledgement took 1.277 seconds; no old-path
action latency baseline is available. No worker unit, sudo policy, grant, token
or package sandbox change is part of this source slice. Nodes + Fleet remains
unfinished until its other kinds move and its total Gateway diff is negative.
