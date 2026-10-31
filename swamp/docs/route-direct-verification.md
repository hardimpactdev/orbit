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

Both proven verification kinds select `direct` in `ORBIT_SWAMP_DIRECT_WRITERS`.
Their old polled execution and discovery entries are removed. Disabling either
writer refuses new child admission instead of restoring the removed executor.
Existing direct Operations keep their stamped writer and bound run. No root
socket, sudo, or Node-local state is needed for these network checks.
Certificate mutations retain their current executor until their Node-user sudo
workflow passes its own cutover proof.
