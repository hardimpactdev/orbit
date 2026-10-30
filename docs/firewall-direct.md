# Direct Firewall intent

`operation-firewall-direct` runs one admitted `firewall.allow`, `firewall.deny`,
or `firewall.remove` Operation on the existing Node209 `pool=migration` workers
for canary proof. Admit only Node209 during this phase. Move to exact
`target: orbit-node-<admitted-node-id>` with the later per-Node workers. The
Gateway keeps only intent and domain projection. Swamp executes one fixed local
`firewall.rule` action under a stable per-Node behavior identity, using native
locking, recovery and placement defaults.

The family reuses [the shared direct Operation boundary](direct-operations.md):
`StartSwampRun`, the one `ORBIT_SWAMP_RUN_TOKEN`, the `swamp.direct_writers`
JSON map, and `GatewayOperation` with `directOperation`. Its Gateway
`direct-operations.php` declares the workflow, immutable `firewall_writer` stamp
and `FirewallIntentProjection`. There is no family token, controller, request,
route or Gateway effect relay. The optional shared primitive endpoint is not
declared for Firewall.

The projection exposes only admitted rule fields and the Node ID. It validates
bounded backend/refusal results and projects the rule through the existing
completion handler. Worker calls cannot bind a run. The Gateway start response
alone records the root run ID; first reads and results refuse until bound.
Configured Node peers can access only their admitted Node; the existing
configured control-plane peer retains its authority.

## Node action and proof gate

The action converges exact rule ownership, so native resume can repeat an
uncertain action. A lost terminal projection reads its existing result without
another effect. The mutating step declares `writes: true`; `vaults: []` sends no
vault data.

The worker runs as the Node's configured user and uses its existing passwordless
sudo. The Gateway records the frozen agent digest with direct intent. The action
checks that digest, the fixed root-owned binary and its parent paths, then runs
`sudo -n -- /usr/local/bin/orbit-agent command firewall.rule` with the admitted
rule and the native 120-second budget. Output is bounded to16KiB; stderr and
refusal details never enter workflow resources. No socket, broker, new account,
sudo rule, token, grant or runtime unit is installed by this family.

Prove the actual sudo action and start-binding/first-read ordering on a safe
Node209 rule before changing a writer. Fixture tests are not live proof.

Gateway HTTP calls remain bounded and do not inherit the workflow cancellation
signal. Check cancellation before the Node action; after it starts, let it
return and project the outcome. No custom claim, lease, heartbeat, retry, wait
tuple, assignment, target hold or keyed receipt is added.

## Takeover and removal

`ORBIT_SWAMP_DIRECT_WRITERS` defaults to `{}`. Each exact Firewall kind
therefore selects `polled`, the previous Swamp writer. Set only a proven kind to
`direct`; the Operation keeps its recorded writer through later setting changes.
Revert that map entry to `polled` for rollback, never PHP.

Keep the old workflow, registration and tests until dev/canary proof and
production success. Record Operation/run IDs and before/after latency. Then
delete each replaced execution path; remove the old schedule once all Firewall
kinds and outstanding polled work are settled. Shared Gateway executor core
stays with the boundary lane.

The 2026-10-30 Node209 profile in
`~/swamp-execution/evidence/run-producers-20261030/attribution.json` measured
44/44 empty Firewall discovery runs and about 6,266 inodes/hour at the
ten-second live cadence. The direct workflow has no schedule. No after-cutover
reduction is claimed yet.

## Checks

`direct_test.ts` covers all three kinds, shared endpoints, bound identity,
cancellation, bounded refusals and uncertain outcomes. `direct_native_test.py`
runs the pinned DAG with fixture Gateway/Node adapters, all kinds, lost
projection, uncertain action recovery, and a first read refused until binding.
Placement is removed only in this disposable fixture. Gateway
`FirewallIntentsTest.php` covers shared admission/projection, first-read
ordering, the admitted Node peer and a foreign peer.

Run Deno's tests/check/lint/fmt and
`SWAMP_BIN=/path/to/pinned/swamp python3 -m unittest discover -s extensions/behaviors/operations/firewall -p direct_native_test.py`.
Live canary, takeover and family net-negative deletion remain separate gates.
