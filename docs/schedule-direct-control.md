# Direct Schedule control

`operation-schedule-control` runs one Gateway-admitted `schedule.enable` or
`schedule.run` intent on exactly `orbit-node-<admitted Node ID>`. It uses the
shared [direct Operation boundary](direct-operations.md): `StartSwampRun`, the
one `ORBIT_SWAMP_RUN_TOKEN`, the `swamp.direct_writers` map, `GatewayOperation`
and `directOperation`. The immutable writer stamp is `schedule_control_writer`.

The Gateway declaration contains only the workflow, writer configuration/state
and `ScheduleControlProjection`. Its input method exposes the admitted Schedule
UUID, runtime user and working directory. Its result method validates bounded
native output and projects the Schedule's state through the shared projection.
There is no family token, controller, request, route or Gateway primitive/relay.

The Node behavior has a stable per-Node identity, so native behavior locking
serializes its controls. It reads admitted input from the shared endpoint,
checks cancellation before the action, invokes the fixed `schedule.activate` or
`schedule.run` local agent action, and projects `ready: true` or an error code.
No Gateway HTTP call inherits the cancellation signal: an action already in
progress must return its bounded result. The workflow has `writes: true` on its
Node job and `vaults: []`; concurrency, placement timeout and retention use
native defaults. It has no coordinator polling, custom resources, receipt
storage or fallback.

Native recover/resume owns uncertainty. If a terminal projection committed but
its reply was lost, the shared template reads that terminal result without
another action. After an uncertain Node mutation or an uncommitted result,
inspect systemd before manually resuming the action: native recovery can repeat
an external effect. A manual Schedule run starts its existing service without
waiting for the command to end; systemd suppresses another start while it is
active. This is not an exactly-once guarantee. Use native Swamp cancellation for
the bound run; there is no family Gateway cancellation implementation.

## Rollout gate

Keep `ORBIT_SWAMP_DIRECT_WRITERS` at its default `{}` or keep both kinds
`polled`. Example per-kind takeover is `{"schedule.run":"direct"}`; restoring
`{}` affects only new intents. Existing intents keep their admitted writer. Do
not deploy root actions or change the worker unit to obtain access: the fixed
local adapter requires the approved thin privilege boundary, which is still a
runtime prerequisite. No credential, grant, enrollment, worker unit or live
writer changes in this source slice.

The existing Gateway run credential needs orchestrator-approved run/read scope
for `workflow:operation-schedule-control`; do not create a family token. No
model, data, vault or admin grant is requested. Native implied approve/signal
permissions are inert because there are no approval or signal-wait steps.

The Gateway alone binds the root run ID from its start response. The first
worker read must occur after that binding. A read before binding fails with
`operation.run_unbound`; it does not establish the binding or invoke an action.
The canary must prove this ordering on the real serve/worker path, including
native recovery if the worker gets ahead of the start acknowledgement. Also
prove the exact Node identity check and root privilege boundary. Do not add a
family retry to mask an ordering failure.

Load the workflow before dormant Gateway source. Prove each kind on a throwaway
dev/canary Schedule, switch that kind reversibly, then prove production before
deleting its previous polled path. Keep `schedule.destroy` with Instance removal
and keep install/secret-bearing paths for their later slices. No live nckrtl.com
cutover Operation is touched.

Local verification uses six Deno behavior tests plus the shared helper tests,
and four pinned native workflow tests with injected HTTP/local actions. The
native fixture removes placement; it does not prove enrollment, privilege, live
binding order or systemd. Live latency and the family's negative Gateway net
remain unfinished until runtime proof and deletion.
