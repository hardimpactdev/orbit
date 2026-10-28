# Independent task-group workflows

Each active task group must advance independently under one `swamp serve`. A
failed group must not stop another group. The official `@swamp/software-factory`
remains the source of stage state and approvals.

## Runtime boundary

Checked against Swamp `20261022.021804.0-sha.983e2929` on 2026-10-27:

- Workflows are finite DAGs. They support behavior steps, child workflows,
  assertions, manual approvals, and `wait_for_signal`.
- A signal wait persists its deadline and result. With `autoResume: true`, serve
  continues the run after a signal or timeout, including after a restart. A
  timeout takes the step's failed route; it does not repeat a previous step.
- There is no repeat, repeat-until, or dynamic back-edge in the workflow schema.
  `forEach` expands a finite collection, and workflow recursion is refused.
- Each behavior definition has its own method lock. Different definitions can
  run in parallel. A long method on a shared driver holds every group behind it.
- The 100 cross-behavior-call limit covers one method invocation tree. It is not
  a lifetime allowance for a task group.

Sources:
[workflow reference](https://swamp-club.com/manual/reference/workflows),
[execution model](https://swamp-club.com/manual/explanation/the-workflow-execution-model),
[schedules](https://swamp-club.com/manual/how-to/run-workflows-on-a-schedule),
[behavior locking](https://swamp-club.com/manual/reference/behavior-definitions#per-behavior-method-locking).
The installed `swamp workflow schema get --json` and workflow skill also expose
`wait_for_signal`; the fetched public workflow reference omits that task.

A single workflow run cannot drive an arbitrary number of factory transitions
and operation polls with these primitives. A timer wait alone does not supply
repetition. Do not hide a polling loop in a behavior, reset a completed step,
pre-expand thousands of polls, or fork the factory to simulate it.

Nick approved successive bounded runs on 2026-10-27. At most one run owns a
group at a time. Ownership is keyed by work item and generation.

## Bounded runs

Each child workflow receives exactly one factory and work item. Swamp's workflow
fan-out reconciles the groups in parallel. Swamp expands `forEach` before a
workflow's steps run. Discovery therefore passes its saved lists into the typed
`group-fanout` child workflow, whose collections are already present at expansion.
A dependency on the discover step alone cannot defer expansion. Discovery does
not drive their stages and does not select only four active groups. Shared
admission reserves at most four maintenance VMs across routine, detect
and package work. Confirmed idle review parking releases the execution slot and
retains project ownership; a parked row without stopped proof still holds a slot. It runs under the
orbit-gateway behavior lock before group creation and every maintenance stage.
Maintenance method work uses the standalone Swamp method entry so it acquires
the target lock. Nested runModel calls inherit only the driver lock. Other
groups advance independently between stage calls. See [project batches](website-packages.md).

Each child uses a driver definition named by work item, with its factory saved
in the generation intent. It performs bounded guarded advances and returns when
work must wait. A short Swamp-native schedule discovers all active official
factory work items and reconciles each group through its own launcher
definition. Each child ends its bounded work at a `wait_for_signal` deadline
with `autoResume: true`. The timeout is allowed and the run then finishes. The
launcher starts the next generation only after the owner run has a terminal
status. No process sleeps or busy-polls. A terminal factory needs no wait or
successor. Do not use a fake manual approval as a timer.

Group creation and provision keep their saved schedule receipts and existing
factory start. `maintenance-projects` reconciles an interrupted create or start;
once factory state exists, discovery includes it on the next native schedule
fire. Discovery also includes active external groups without schedule receipts.
It excludes groups whose schedule receipt already records completion or
cancellation. The project reconciler checks Orbit cancellation and cleanup
before requesting factory status. A cancelled group with no VM and no remaining
assistance can retire its schedule receipt even if its old factory stage no
longer exists. Factory state and effect receipts stay intact. Groups with
remaining children, cleanup, or assistance keep their receipt and capacity
claim until that evidence is settled. It never calls `start` for an existing
factory state.

Discovery reads current factory state without materializing another status report.
Run native `swamp run doctor --fix` once after an interrupted serve restart, before
resuming schedules, to settle dead owners that still appear to be running. Interrupted group work uses Swamp's guarded
checkpoint recovery and resumes with the same generation and owner. If recovery
finished but resume was interrupted, the next pass resumes that suspended run. A
native wait stays with its owner until Swamp settles it. A new bounded
invocation reconciles the same effect before advancing.

Use native workflow failure handling at the group boundary. A child failure must
be recorded for that group and must not cancel sibling children. The existing
multi-item tick must also catch each item's failure independently; its persisted
tick records the error and continues with the remaining items.

## Generation admission

The launcher persists a generation intent before asking Swamp to start
`group-run`. It records `<workItem>.g<generation>` as the workflow input key and
retains the returned run ID. Duplicate triggers serialize on the launcher's
native behavior lock. While the owner is running or suspended, they start
nothing. A suspended wait belongs to that generation until Swamp settles it.

A lost launch response is searched by the exact key. The group driver's first
step claims that generation under the same native behavior lock as its launcher,
durably saving the owning workflow run ID before any factory or Gateway
operation. A repeated claim from the owner is safe; another run ID cannot drive
that generation. The drive method checks ownership again, including when
recovering an unknown step. A delayed launch of an older generation is also
refused.

Swamp's workflow-run API has no idempotency-key field. Therefore a repeated
transport request may produce another workflow envelope. Such an envelope exits
after its refused claim and performs no group work. The invariant is one
admitted owner, including across lost replies and delayed duplicate launches.
Search the saved owner before deciding that a generation ended; a rejected
duplicate's terminal result is never evidence that the owner ended.

The launcher, claim, and drive methods use the same group definition. A detached
start returns its run ID before its claim needs that lock. Checkpoint recovery
and resume use a separate recovery definition, after the launcher method
returns, so the resumed claim or drive can acquire the group lock. Factory
definitions remain shared, with short methods that record or start operations
and return. No lock is held across a durable wait.

## Repeated run failures

Three consecutive failed owner generations park the group. Save the failure
count and the generation already counted before permitting another launch.
Repeated supervisor visits, duplicate workflow records, and recovery of the same
generation cannot spend another attempt. A successful owner run clears the
streak. Successful work inside a run is not enough: a later workflow failure
still spends an attempt. Interrupted owners still recover their existing
generation.

Every failed drive updates the durable failure resource, including its current
reason, attempt count, parked flag, and timestamp. Once parked, each supervisor
visit reconciles assistance again without starting group work. Persist parking
before notifying the Gateway: a lost assistance response never permits a fourth
attempt. Notification failure stays retryable on later supervisor visits.
Preserve existing human and safety requests while a failed observation cannot
prove they cleared.

The assistance request names `driver-retry-g<generation>`. It is a driver retry
request, separate from the factory's stage gates. It uses the existing child or
post-child comment route and preserves operator replacements and safety causes.
An explicit `retry` on the group's driver definition records the actor and a
retry decision for that exact failed generation. It resets only the failure
budget; it never resets factory state, approves a factory gate, or dispatches an
effect. A board resolution alone does not restart the driver. Post-child request
cleanup requires the matching saved driver retry decision, just as factory
requests require their matching factory approval.

```sh
swamp behavior run orbit-group-tg-1234 retry --input actor=nick --input note="Fixed the driver failure"
```

## Stage launches, recovery, and manual triggers

Workflow-mode stages save launch receipts on the group driver, before sending
any launch request. Each receipt includes the immutable stage inputs and launch
attempt. Reconcile an uncertain reply by exact workflow, work item, stage key,
and attempt. A missing or ambiguous receipt parks for assistance after the
startup grace period; it never resends an uncertain launch. Copy a prior fleet
receipt into the group definition before continuing an in-flight stage.
Existing operation keys and recorded factory evidence remain unchanged.

Checkpoint recovery uses a separate bounded budget for the same native owner.
Persist each attempted recovery under the group lock before calling the CLI.
Three failed or interrupted attempts park recovery and raise the existing
`driver-retry-g<generation>` assistance request. Every later supervisor visit
refreshes assistance without calling recover or starting a successor. Explicit
retry clears the recovery budget and resumes the same owner and generation.
Successful checkpoint recovery clears that budget; successful work alone does
not clear the owner failure streak.

Manual `dependency-routine` and `dependency-package` workflows invoke
`group-supervise`, so they use the same group lock and admitted owner as the
schedule. They never call a fleet driver's tick. Package admission still checks
its saved target before joining that owner.

A work item active in more than one configured factory is excluded from fan-out
and raises assistance naming all conflicting factories. The native schedule
retries each notification in its own `allowFailure` fan-out step. Even many
conflicts cannot spend the discovery method's cross-behavior-call budget or
stop healthy groups. A saved conflict identity allows post-child assistance
and lost-response reconciliation without selecting an arbitrary factory era. Factory repair is
an operator decision; discovery never resets either factory.

## Exactly-once effects and migration

Keep existing factory names, work-item IDs, stage cycles, dispatch records,
artifacts, evidence, pre-approvals, and run-era checks. Scheduling changes must
not reset a factory or create a new operation identity.

- Gateway operations retain their existing keys and saved request payloads.
  After a lost response, replay or reconcile that key; do not invent a new one.
- Group creation has no keyed POST. Persist the attempted request first. An
  uncertain response requires the existing GET reconciliation against that
  request. Never resend an uncertain create.
- Worker execution keeps its saved intent, workflow run ID, enrollment-bound
  worker name, receipt, and uncertain-launch reconciliation. Changing the driver
  must not launch a second maintenance operation.
- Completion persists before best-effort credential revocation. The existing
  independent cleanup reconciler continues retrying failed revocations.

At cutover, stop the old stage-driving schedules before enabling new group
invocations. Let any running old tick finish, or recover its interrupted effect
from its saved receipt. The new discovery includes every active factory state,
including unscheduled external groups. The project reconciler keeps incomplete
schedule receipts, pending admission, and finding creation recoverable from
their saved requests. Never run old and new drivers against the same group
concurrently during migration.

`groups-driver` discovers groups at second zero of each minute. Discovery and
fan-out took 35–47 seconds in the first live wave; a five-second cron was faster
than one invocation and filled Swamp's shared scheduler queue. The project
reconciler fires at second 40 and worker cleanup at second 35, rather than
putting all three coordinators into the queue at the minute boundary. Swamp
skips a fire while its workflow is running but queues another while it is
waiting for a slot; it has no skip-if-queued setting. Monitor queue delay when
group count or run time grows. The schedule controls discovery and successor
admission; admitted groups still execute independently in parallel.

Each child has a ten-second
durable wait. Serve's continuation sweep controls when the wait finishes: the
default sweep interval is thirty seconds. The coordinator can shorten it with
`--continuation-sweep-interval 5s` when deploying; it is not a second driver.
`maintenance-projects` retains admission, finding creation, and receipt
completion. `maintenance-workers` retains credential cleanup. Neither drives a
group stage.

Each driver invocation keeps a conservative call budget for its own group, with
at most six guarded steps and sixty direct behavior calls, leaving forty calls
for nested configuration and worker calls. Existing pre-approvals remain on
their named fleet driver; a consumption receipt on the group definition prevents
applying the same approval again. Increasing group count must increase
independent workflow runs, not the size of one invocation tree.

## Required validation

- Many groups all advance in the same discovery pass, without a fleet call cap.
- A first or middle group's exception leaves every other group advancing.
- Same-group overlapping invocations cannot create duplicate effects.
- A restart after dispatch, POST acceptance, or receipt persistence resumes the
  existing operation without resetting state.
- Lost group-create and worker-launch responses reconcile their saved intent.
- In-flight groups retain their factory era, approvals, keys, and evidence.
- Only affected tests run locally, with at most four workers; full suites run in
  CI.

For the initial migration, follow [the Services cutover](services-group-cutover.md).
For a normal later deployment, pause `groups-driver` and `maintenance-projects` with
Swamp's schedule commands, then let active short methods finish. The existing
Services deployment procedure checks for active runs before it stops serve.
Suspended signal waits are durable and can survive that restart. Deploy the new
definitions together, then resume the two schedules. Preserve all other schedule
pauses, especially the historical host-side `subtasks-driver` pause. Confirm the
first group generations use the existing operation IDs and that one group's
error does not stop another group's history. No runtime data copy, factory
reset, or operation re-key is part of cutover.

## Local proof

The native integration test uses an isolated Swamp server and the official
factory. It starts with four task groups. One group blocks after its effect receipt is saved; one
fails; the other two record work independently. A duplicate launch of a waiting
generation exits without a second effect. Killing serve before factory evidence
is recorded exercises `run doctor --fix`, guarded checkpoint recovery, and
same-owner resume. A native wait survives the restart, and its next generation
advances the existing factory. The permanently failing group stops at three
attempts, stays parked on repeated supervisor visits, and starts a fourth
generation only after an explicit retry. The same test makes native recovery
fail three times, verifies parking, restores the workflow source, and retries
the same owner. Both manual dependency workflows retain an already scheduled
owner. The actual discovery and fan-out path starts a fifth healthy group even
when conflict assistance fails. An empty discovery list also completes safely.

Run affected contracts with one test worker:

```sh
~/.swamp/deno/deno test --allow-all tests/group_runs_test.ts tests/stage_launch_test.ts tests/driver_test.ts tests/project_detect_progress_test.ts tests/maintenance_worker_test.ts tests/group_assistance_test.ts
python3 -m unittest tests/group_workflow_test.py
```

The native test needs `swamp` on PATH and the official factory installed in the
development clone. It copies those package files into a temporary repository; it
never installs into or changes the live checkout. CI also runs this native test
with the pinned Swamp release and official factory version. The native job uses
repository secrets `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`, with extension-read
and serve scopes, in isolated account directories removed after the job.
Missing credentials fail the job instead of skipping the test. The
pure contracts cover 119 discovered groups, forty concurrent drives above one
invocation's aggregate call limit, lost launch requests and responses, delayed
duplicates, generation admission, and saved pre-approvals. The existing worker
contracts retain uncertain worker POST and run receipts.

## Bounded runtime state

Discovery keeps at most four active group owners, checking their saved native
run records before admitting a successor, with a rotating cursor. Unknown owners
retain their slots. Existing excess owners drain before new admission. It excludes completed receipts and terminal factories. A group parked
at a human gate records a fingerprint of its factory state, evidence, artifacts,
approvals and preapprovals. It gets no new drive until those inputs change.
Running operations still get bounded polls; unknown failures remain retryable.
The per-group ensure method also checks terminal factory state, so a delayed or
manual supervisor cannot start a successor after completion.

Driver resources retain one current version (plus one hour online to protect
in-progress publication). A separate bounded native pruner runs every five minutes. Compaction additionally preserves
exact versions and workflow runs reachable from live receipts and unfinished
workflow records. See [Services compaction](services-compaction.md). No factory
state, operation key, approval or effect receipt is reset.
