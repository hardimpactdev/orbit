# Direct Route verification

`operation-route-verification` handles `route.workload_verify` and
`route.public_verify`. The Gateway validates Route ownership and forwarding
placement at admission and records the probe protocol, address, port, domain and
agent pin. The read endpoint returns that snapshot, even if stored Route state
changes later. It never accepts a worker-selected endpoint or command.

The workflow uses the existing Node 209 workers with `pool=migration`, declares
no vaults, and uses native placement and recovery. Its non-root worker runs the
existing read-only `route.verify-certificate` or `route.verify-http` command.
The commands perform network requests, not router-local inspection. TLS probes
use the admitted workload or Router WireGuard address, including colocated
Router/workload placements. Public Process checks retain their validated
published listener. No sudo, SSH, Gateway execution relay or Node-local state is
needed. The agent keeps its existing bounded checks and error codes; raw output
and diagnostics never enter the Operation result.

The behavior uses `GatewayOperation` and `directOperation`. Only the shared
Gateway read/result protocol is involved. A missing start binding refuses the
first read before projection or a Node probe. A terminal result is read without
repeating the probe. The configured control-plane peer and run binding are
checked by the Gateway; worker placement does not change the Route intent's
owning Node.

Both per-kind settings default to `polled` in `ORBIT_SWAMP_DIRECT_WRITERS`. The
source does not change a grant or live writer. Extend the one approved Gateway
run token's grant to this workflow through the approved exact-workflow grant
process. Load the workflow and deploy the Gateway declaration before a dev
canary. Prove start binding, first-read ordering and an actual non-root 209
worker probing the real published endpoint before selecting direct. Rollback
selects polled for new intents. Keep the previous workflow until direct
production success. Root-required certificate changes remain gated on the
approved privileged action boundary.
