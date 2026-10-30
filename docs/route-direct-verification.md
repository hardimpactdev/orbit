# Direct Route verification

`operation-route-verification` handles `route.workload_verify` and
`route.public_verify`. The Gateway validates Route ownership and forwarding
placement at admission and records the probe protocol, address, port, domain and
agent pin. The read endpoint returns that snapshot, even if stored Route state
changes later. It never accepts a worker-selected endpoint or command.

The workflow targets exactly `orbit-node-<admittedNodeId>`, declares no vaults,
and uses native placement, locking and recovery. Its Node worker runs the
existing read-only `route.verify-certificate` or `route.verify-http` command
locally as its non-root account. It never uses sudo, SSH, a Gateway execution
relay or a fallback worker. A colocated Router's admitted skip performs no probe.
The agent keeps its existing bounded network checks and error codes; raw output
and diagnostics never enter the Operation result.

The behavior uses `GatewayOperation` and `directOperation`. Only the shared
Gateway read/result protocol is involved. A missing start binding refuses the
first read before projection or a Node probe. A terminal result is read without
repeating the probe. Node-worker identity and run binding are checked by the
Gateway; arbitrary labels do not grant Node authority.

Both per-kind settings default to `polled` in `ORBIT_SWAMP_DIRECT_WRITERS`.
The source does not change a grant or live writer. Extend the one approved
Gateway run token's grant to this workflow only after review. Load the workflow
and deploy the Gateway declaration before a dev canary. Prove start binding,
first-read ordering and the actual non-root Node worker before selecting direct.
Rollback selects polled for new intents. Keep the previous workflow until direct
production success. Root-required certificate changes remain gated on the
approved privileged action boundary.
