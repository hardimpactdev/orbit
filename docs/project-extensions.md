# Private project extensions

Each maintained project has one private `@nckrtl/project-<slug>` extension in
`extensions/behaviors/projects/<slug>/`. It contains the project's config and
scheduled workflow entry points. It contains no package-manager commands,
version-selection rules, repair loop, PR writer or worker lifecycle code.

This follows the Swamp manual's
[extension packaging](https://swamp-club.com/manual/reference/extensions),
[behaviors and methods](https://swamp-club.com/manual/explanation/models-types-and-methods),
[workflows](https://swamp-club.com/manual/reference/workflows) and
[schedules](https://swamp-club.com/manual/how-to/run-workflows-on-a-schedule).
The extension is a package; its config is a behavior resource; its entry points
are workflows with `trigger.schedule`. The official `@swamp/software-factory`
continues to own execution state and transitions.

## Config and delivery

Before project extensions, `orbit-gateway.globalArguments.dependencyProjects`
held Project 20 and Project 18 config. It asserts the Gateway's repository URL,
check command and default branch. Project 20 also supplies worker paths and
release-age policy. There were no dependency schedules. The private extension
now owns maintenance paths and policy. Maintenance does not read repository
`maintainer.json` or run a repository maintenance script.

Project 20 moves to `@nckrtl/project-nckrtl-com`. Its config method writes the
same values as before. The Gateway selects registered project extensions through
Swamp cross-behavior calls, validates their returned config, and passes the
resolved object to the existing routine/package client. It never uses cached
config after a failed config call. Duplicate Project IDs and Gateway setting
mismatches refuse dispatch. The 18-project rollout completes this migration; the
Gateway keeps an empty `dependencyProjects` list. See
[the fleet inventory](maintained-project-extensions.md). Every project requires
worker config; the strict schema rejects the former `script` field. Routing
registration contains only its ID and extension type. Each stage loads only the matching extension, so adding
projects does not multiply nested calls in a driver tick.

Configuration resources use schema version 3. A configuration read migrates the
unversioned v1 resource and schema 2 records before the Gateway consumes them.
Version 3 includes the project exception records. See
[release-age exceptions](project-maintenance-exceptions.md). Current resources and new config remain
strict. Stage input identities also use version 2 and include only the fields
that define maintenance work. Pre-retirement identities are migrated before
comparison, including on completed jobs; changes to paths, policy, targets or
handoff evidence still conflict under the same key.

Private manifests declare the Composer/JS extension dependencies and
`visibility: private`. Delivery stays in the private Git repository and
validated Services deployment bundle, as for the ecosystem extensions. Nothing
is pushed to the registry. Registry publication requires a separate decision
from the orchestrator. Project workflow YAML lives under `workflows/` and is
listed in its private manifest. The normal repo loader finds both the behavior
and workflows. No local source registration or registry pull is needed. Their
IDs are created by Swamp and remain stable.

## Schedules and shared work

A weekly routine starts one external group with one child and starts the
existing `dependency-routine` factory. The dependency drivers reserve
nested-call capacity with `maxCallsPerTick: 60`. The shared schedule tick
rotates four active groups at a time, so parked work cannot starve other
projects. Detection ticks take up to three guarded steps per selected group.
This lets a ready provision result be recorded, its transition taken, and the
worker start requested in the same visit. A running operation still ends that
visit immediately. The driver retains its call budget and defers remaining
work when needed. With nineteen groups, the four-group rotation visits each
about every five minutes; one step per visit used to leave ready VMs idle
across several rotations before worker startup.

A daily detection run uses a separate instance of the official
factory: provision, detect on the task worker, check, finish without a PR.
Detection failures or a changed checkout park for assistance. Detection never
starts a repair turn or publishes changes.

Every generated child declares typed deliverables in shared `project_schedule.ts`:

- `project-check` is a command deliverable. It runs the configured `check` from
  the repository root (`directory: .`), without a failing-on-base requirement.
- A review deliverable records workflow and worker evidence, the Gateway check,
  the PR or no-change result, and worker departure. Detection records its reports
  and confirms the checkout stayed unchanged instead of requesting a PR.
- Each package child also declares `dependency-lock`, a file deliverable with
  `change: modified` in the detected path: `composer.lock` for Composer, or
  `{bun.lock,package-lock.json,npm-shrinkwrap.json,pnpm-lock.yaml}` for JavaScript. The latter is Orbit's supported
  glob alternative for the lockfiles recognized by dependency inventory.
  Ecosystem maintenance still owns package-manager admission.

Routine runs may finish with no changes, and detection is read-only, so neither
requires a file diff. Package work requires its selected lockfile to change.
These lists stay below Orbit's five-deliverable limit and apply to major and
advisory children, including advisory tasks that await a selected fixed version.
The Gateway validates existing file paths against the repository base.

Orbit allows empty deliverables in backlog but refuses the move to todo with
`tasks.subtask_deliverables_missing`. The old proof groups supplied a review
deliverable before entering the workflow; the project generator omitted it.
The scheduler Gateway fixture now enforces typed request shapes and this todo
guard. Its regression tests create generated groups, move them to todo, and
prove the former empty child is rejected without changing group status.

A shared minute tick advances scheduled work with the existing Orbit driver. It
records schedule receipts in Swamp data. Repeated ticks reuse the group and
factory run. Group creation has no Gateway idempotency contract: save intent
before POST, reconcile a lost reply by the exact saved group brief, and never
repeat an uncertain create. Preserve operator and safety assistance. A cancelled
Orbit group retires its schedule receipt once every child has ended, assistance
is clear, and its VM is destroyed or was never allocated. This also applies
while the official factory still reports provision as active. Keep the factory
history, stop driving the cancelled work, and allow the next detection slot.

Successful detection creates one ordinary external package group per path,
ecosystem and major/advisory finding. Repeated scans reuse an unresolved
finding. Each task contains the detection evidence and project policy. A major
with a stable detected version can enter `dependency-package`; security findings
without a verified fixed version remain backlog tasks for explicit target
selection. Never invent a fixed version or weaken policy. Package runs retain
the existing per-project sequence: the previous package must merge and complete,
or be cancelled, cleaned up and acknowledged, before the next is admitted. The
package extension still verifies advisories after applying a selected version.

The weekly entry defaults to Monday 02:00 UTC, detection to daily 03:00 UTC.
Swamp's schedule pause/resume and trigger override commands control activation.
Deploy only after reconciling active work. Do not change an in-flight group's
resolved config or worker enrollment. The orchestrator reviews, merges and runs
`bin/deploy-services` from Nick's Mac; implementation threads never deploy or
merge.

## Acceptance and rollout

The first PR supplies a template, Project 20, focused fixtures and this shared
support. It preserves worker placement, enrollment, operation requests, check
behavior, repair limits and PR summaries. After merge and Mac deployment, run
one Project 20 routine through the project extension. Record its Swamp run and
work item, Gateway operation IDs, enrolled worker name, check, and PR/head or
clean no-change completion. Do not claim that local fixtures prove deployment.

The orchestrator authorized preparing the other maintained projects in the
second PR while deployment and the first live proof are pending. Their source
config and setup are captured in the fleet inventory. Leave Horizon New Dawn to
its own thread. Do not change project repositories; removing maintainer there is
phase 5.

## Fleet rollout and scheduler isolation

The fleet rollout adds the other 18 maintained projects from their current
repository config and Orbit Project settings. Project repositories stay
unchanged. Horizon New Dawn keeps its separate upstream-parity extension.

Each pending, active or queued schedule row is reconciled independently. A
failed row writes a named scheduler issue with its Project ID, receipt key and
reason; the minute tick still returns runnable work for other rows. Detection
fan-out also isolates individual finding creates. A stuck row remains visible
until a later successful reconciliation resolves its issue. Uncertain creates
are never repeated.

When another routine/detection run in the same Project is uncompleted, the next
scheduled fire succeeds as a skip. Its resource records the requested slot,
blocking receipt and reason. It creates no group and does not retry that skipped
slot from the minute tick. The next scheduled fire can run after the blocker is
resolved. Operator and safety assistance remain untouched.

Reading an unchanged config reuses its current resource without writing another
resource version. A changed config writes a new version.

## CI coverage

CI runs the config, schedule, Gateway stage and worker-contract fixtures. The
three real Swamp routing tests (`maintenance_factory_test.ts`,
`package_routing_test.ts`, `routine_routing_test.ts`) also run locally before
handoff. They use the pinned official extensions, not a fork or test
replacement.

Those three routing tests do not run in CI yet. Their contract job does not
install the Swamp CLI. The separate Maintenance worker native job installs the
pinned CLI and official factory and runs `group_workflow_test.py`, including
parallel progress, restart, recovery parking, and manual-workflow ownership.
That job uses the dedicated `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN` repository
secrets. Never copy a developer's key or production datastore into a runner.
A passing contract job is not evidence that the three routing tests ran in CI;
the PR records their local results separately.

Read stuck rows and recorded skips on the deployed server:

```bash
swamp data query 'modelName == "orbit-gateway" && name.startsWith("scheduler-issue-") && attributes.status == "open"' --select attributes --json --server wss://swamp.orbit
swamp data query 'modelName == "orbit-gateway" && name.startsWith("scheduler-skip-")' --select attributes --json --server wss://swamp.orbit
```

The latest `project-work` resource also lists open issues alongside the runnable
work items. Fix a stuck row's cause and let reconciliation resolve its issue.
Never remove a saved create intent just to force another POST.
