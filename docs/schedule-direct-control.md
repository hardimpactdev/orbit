# Direct Schedule control

`operation-schedule-control` runs one Gateway-admitted `schedule.enable` or
`schedule.run` intent on exactly `orbit-node-<admitted Node ID>`. The temporary
Node 209 migration-pool placement was used only for the completed binding canary
and is removed from source. Actual actions remain gated on per-Node workers and
the approved privilege boundary. It uses the shared
[direct Operation boundary](direct-operations.md): `StartSwampRun`, the one
`ORBIT_SWAMP_RUN_TOKEN`, the `swamp.direct_writers` map, `GatewayOperation` and
`directOperation`. The immutable writer stamp is `schedule_control_writer`.

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

## Live binding proof

The 2026-10-30 migration-pool canary passed for both kinds on the loaded
`f89832f0382df1f22bfb8b16c87ddb39a509cbd4` source. The Gateway recorded
cancellation inside each admission transaction before after-commit native start.
Both native runs succeeded and projected `cancelled`; the local Schedule action
was never invoked. The dev Schedules kept their prior desired state, status,
error fields and last-run timestamps.

| Kind              | Gateway Operation | Native run                             | Start acknowledgement | Native run duration |
| ----------------- | ----------------- | -------------------------------------- | --------------------- | ------------------- |
| `schedule.run`    | 4740              | `20502013-3ec0-430b-9c0a-eb4ad40df12a` | 926 ms                | 23,826 ms           |
| `schedule.enable` | 4745              | `8e7fa96b-fc27-4e90-bee3-b29318b6562a` | 82 ms on recovery     | 25,718 ms           |

The initial enable submission was unconfirmed. Filtered native history had no
run for Operation 4745, and the latest family run was the completed 4740 canary.
The operator submitted the existing intent once through shared `StartSwampRun`;
the Gateway bound its returned root ID. No new admission, worker-supplied
binding or automatic retry was added. A broad native-history query timed out at
its default 30 seconds; no runtime setting changed.

The grant is `ea06ca4c-dbbd-45b5-b8f7-d393d7554617`, run/read only for the
existing Gateway principal and this workflow. Actual starts authenticated as
`user:orbit-gateway`. The preliminary access diagnostic was inconclusive and is
not permission proof. Both successful read/projection paths used the binding
checks from Orbit #1711. No Gateway worker, claim attempt or target hold was
created.

These are binding/read/projection timings, including native dispatch and
reporting. They are not Schedule-action latencies or a before/after performance
comparison. Both shared per-kind writers remain `polled`. No root privilege,
worker-unit or production writer changed. Exact-Node placement restoration in
source must be loaded normally; full action E2E and production proof still
precede takeover and deletion. Evidence is recorded under
`~/swamp-execution/evidence/schedule-binding-canary-20261030` on Beast.
