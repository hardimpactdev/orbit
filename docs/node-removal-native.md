# Node removal

`operation-node-remove` is one native run. The Gateway admits `node.remove` and keeps the Node and Route associations until the run finishes. Begin and restoration are steps, not child Operations.

The run restores public SSH on an online Linux target, retires Metrics access and fleet state, publishes the hub, and cleans each admitted serving participant. It retires the target observer and worker from the Gateway over the admitted pinned SSH connection. Every serving effect uses its exact `orbit-node-N` placement. The shared Node 1 and 18 proxy transport keeps those identities; removal has no replacement worker. The shared command runner selects the proxy from its installed worker cgroup; workflow input cannot select SSH or a host. DNS publication and account reads use that same runner. Proxy units and their pinned custody must be installed before those effects can run.

On failure, the same run restores membership, hub state, required certificates, Route publications, DNS and Metrics. It reports incomplete restoration when a required step fails or final worker retirement has an uncertain outcome. An offline target keeps its host files. A reachable target requested with `--offline` must pass the captured online guards.

The shared hub model is `wireguard-hub-N`. Its lock includes the fresh private read and the effect. Restoration ignores cancellation only for a live root that has recorded restoration intent. Private SSH, VPN and Instance environment material stays inside its effect callback. Certificate leaf keys stay on the serving Node, and signing uses the existing control signer.

TaskVM parents own their admission, planner and custody. They can reuse `nodeRemovalActions(...).prepare/step/restore` and `nodeRemovalLocalActions(...)` as placed steps. They must never call cross-Node cleanup locally or contact a provider-deleted guest. Gateway `NodeRemovalPublicInput::participant` supplies the fresh network, residue and DNS input selected by the parent; it does not admit a caller.

Run focused Deno checks on `extensions/behaviors/operations/node/removal_*_test.ts`. The shared native harness in `removal_native_test.py` validates and runs the real DAG against disposable HTTP and system boundaries, including failure compensation, Metrics order, callback-private material and restored certificates.
