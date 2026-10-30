# Direct branch recording canary

`operation-instance-lifecycle-direct` handles branch-only `instance.rename` on
exactly `orbit-node-<admittedNodeId>`. It uses the shared direct Operation
read/result protocol and the existing read-only account agent command. It does
not change HEAD, files or the origin. Repository identity and the requested
checked-out branch must match before the Gateway records the branch.

The writer defaults to `polled` through `ORBIT_SWAMP_DIRECT_WRITERS={}`. A
canary requires an approved grant on the existing shared run token, the exact
Node worker running as the enrolled account, and the admitted agent pin already
installed. No worker unit, root privilege boundary, token or grant changes are
part of this slice. Use native defaults, `vaults: []` and a stable behavior per
Node. Verify Gateway start binding before the first worker read. Unbound reads
refuse without a local agent call; native recovery owns retry. Cancellation
before inspection skips it; an in-flight inspection finishes before projection.

In direct mode a request containing a domain change refuses before admission.
Route composition and the other lifecycle kinds remain on their existing Swamp
path. Keep that path until dev/canary and production proof, then delete it.

# Instance lifecycle execution

Swamp chooses the order of lifecycle effects. The Gateway validates requests,
reserves Instance and Route intent, and binds each descriptor to that intent.
Node commands always run on `Operation.node_id`. Protected Git and environment
values resolve only inside the Gateway.

The parent kinds are `instance.create`, `instance.clone`, `instance.register`,
`instance.rename`, `instance.destroy`, and `instance.transfer`. A transfer uses
a destination child because each Operation has one Node. Route, runtime,
environment, database, and setup-list effects use their owning family's child
kinds. Create proves environment synchronization and Vite+ preparation before
Route activation. Clone captures definitions, then installs Processes stopped
and Schedules disabled through the runtime family. Removal uses those same
owning families to remove runtime before Route cleanup.

Child admission sends only the stable child key. The Gateway builds its kind,
Node and input from recorded parent intent. A pending child releases the parent
lease through the shared wait route, so the workflow ends until the runner can
reclaim it after its children settle.

The placed family invocation claims one kind, then saves its Operation
assignment before running effects. A retry renews that assignment and never
claims another Operation. After a lost completion reply, it confirms the exact
terminal result through the ordinary read API without a new lease write.

Stable keys identify effects. Before retrying an uncertain effect, Swamp reads
its keyed receipt. A terminal receipt has already passed the Gateway family hook
and becomes step proof; a running receipt is polled, and only an unknown receipt
permits an invocation with the same key and descriptor. A saved successful step
skips the effect. An expired lease stops execution. Swamp saves completion
intent before it posts completion, so a lost completion response does not start
another effect. Unknown transport outcomes escape without triggering destructive
rollback.

Only the create that activated an Instance may remove it after a confirmed setup
failure. Busy, cancelled, and uncertain setup results retain the checkout.
Teardown must also have a confirmed outcome before rollback can remove source.

Removal withdraws each Route and the Instance's PHP projection before deleting
source. It rebuilds Caddy and removes unused artifacts after source deletion,
including for unrouted task workspaces. Route records remain until withdrawal
succeeds.

Production removal records every shared-pool target and affected Node. It
detaches only the selected target, proves Caddy publication on those Nodes,
clears the current release and removes runtime. Only then may it remove the
recorded production home and account. It runs no setup or teardown list.

Transfer renders destination environment through `instance.sync_environment`.
The Gateway reads source environment in memory, imports only missing keys, and
records their names on the transfer before destination writes. Before cutover,
rollback removes only that imported inventory. After cutover, recovery only
moves forward and retires source Route projections before deleting source. The
transfer parent records the SQLite seed after source capture and before
destination admission. The destination uses that retained descriptor. Rollback
derives seed abandonment from the same intent and its stamped ancestry.

Rollback restores the source runtime before SQLite abandonment or destination
discard. The Gateway requires that exact successful child before it admits
destination cleanup, so an offline destination does not keep the source paused.

The focused native check is
`python3
extensions/behaviors/operations/instance-lifecycle/native_test.py`. It
validates the runner, dispatcher and family workflows with the pinned Swamp CLI
in a disposable repository. Separate processes recover a lost claim reply, a
lost effect reply and a lost completion reply. Its Gateway is a fixture;
deployed verification remains required. The disposable fixture removes remote
placement labels so these checks exercise native process persistence locally;
they do not prove worker placement.

The Gateway registers these kinds only when the kit enforces child Operations,
receipt recovery and list results. Otherwise admission returns
`operation.kind_unavailable` before recording new Instance intent. Focused tests
load the family declarations explicitly to exercise pending integration without
enabling incomplete contracts in the normal registry.

Transfer admission also requires `archive_relay`, `compensations` and
`cancel_guard`, plus complete destination and activation handlers. Those
handlers are not defined in this slice, so transfer remains unavailable even
when the relay capability lands. Their Gateway kinds and Swamp handlers must
ship together. The family does not relay archive bytes or invent a substitute
transport.

A cancelled capture with an unknown receipt can end with Gateway-proven
`rollback_pending`, retaining the original attempt for recovery. It never
restores the source or discards the destination on unknown evidence. New
transfer admission refuses while that marker remains. This slice does not
implement new-parent recovery from a terminal pending attempt.
