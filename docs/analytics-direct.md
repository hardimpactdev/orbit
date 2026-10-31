# Analytics direct workflows

Analytics uses one native root workflow for each kind: enable, disable, and
update. The Gateway admits safe desired state and projects bounded domain
changes. Its `direct-operations.php` is the registration. No analytics kind
uses polled integration discovery, an executor, or Gateway child Operations.
Unported analytics role kinds return `operation.kind_unavailable`.

Enable converges the Plausible CE site and requests the Router tracking leaf through the canonical certificate signing step on Node 209. It uses the admitted account home and canonical certificate actions. Public Ingress uses Caddy automation and needs no Orbit leaf. The workflow publishes Router Caddy, verifies the private TLS hop from each Ingress, projects public activation, publishes Ingress Caddy, then converges its managed firewall. Canonical `route-publication-node-ID` methods serialize fresh whole-Node Caddy and DNS publication for every family.

After DNS publication, a native 31-second grace period keeps retired placements available. The workflow then projects placement cleanup, republishes old hosts and DNS, and removes unused Router leaves and LAN rules. Disable withdraws the ingress firewall and tracking hosts, publishes DNS, waits the same grace period, then removes old placement state and detaches the binding. It retains the provider site, history, and manual goals. Update checks or recreates the pinned Plausible container on its admitted Node and checks health before success.

Every effect step selects `orbit-node-N` and the matching string Node label.
The immutable admitted participant set contains each effect Node. The same
root run reads a fresh public view for the authenticated participant through
the shared GET. Only the root posts results. Public certificate requests go through the canonical `certificates.signBatch` step on Node 209. It reads the configured CA from the approved Swamp vault inside the signing effect. Leaf keys stay on their owning Nodes. CA private material stays inside the signing effect and never enters native arguments, resources, or results.

The Plausible management credential and runtime environment use their existing
fixed private-material sources. Bytes stay inside `withPrivateMaterial` effect
callbacks and never enter workflow arguments, resources, results, or logs. No
secret output store is needed. Running callbacks contain bounded desired publication checkpoints. Public certificate requests stay in native resources; they are not stored as Gateway results.

Light-box effects keep the exact owning Node target and label. Their configured proxy worker on Node 209 runs the direct system command on that Node. An absent worker leaves the native step waiting; the workflow does not select a substitute Node.

Focused checks use the shared native harness in `tests/native_harness.py`.
`tests/analytics_native_test.py` runs the enable and disable DAGs with real
Gateway HTTP/TLS and Plausible HTTP clients, fake local host effects, and a
whole-scratch plaintext/base64 secret scan. `analytics/update_native_test.py`
covers update and rollback with the same shared harness. These local tests
validate composition and privacy; they do not prove production placement.
