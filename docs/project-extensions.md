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

A weekly routine records one intent. Shared admission reserves capacity before
creating one external group with one child and starting the
existing `dependency-routine` factory. Independent native group workflows advance
each admitted factory run; the minute admission workflow only reconciles schedule
receipts and findings. The shared reservation cap limits new maintenance VMs,
not the advancement of existing work. See [per-group workflows](per-group-workflows.md).

A daily detection run now scans a pinned default-branch checkout on Services.
It creates no Orbit group, worker or VM and continues while package PRs wait
for review. It writes a versioned Project/ref index and immutable reports,
then leaves a complete verified plan for ordinary batch admission. See
[Services detection and its release gate](service-dependency-detection.md).
Every JS path pins its lockfile manager in `worker.jsPackageManagers`.

## Historical VM detect groups

The following recovery rules apply only to existing VM-era groups. New detect
entries use Services. Deployment neither retries nor cancels historical groups.

A failed scan with an unchanged checkout can be retried on its existing group
and VM after its cause is fixed. The operator approves `detect-retry` and grants
one `cycle-override:script` on the `dependency-detect` factory for that work item.
The normal group driver follows the `retry` transition back to `script` with a
fresh cycle and operation key. Old jobs, workflow receipts and failure evidence
remain readable. Repeating a driver tick or either approval does not redispatch
an already executed entry. No new group, factory reset or manual data clearing
is part of recovery. Changed-checkout scans and provisioning failures cannot use
this transition. A repeated failed scan requires fresh approvals for its new
assistance entry.

```bash
swamp behavior run dependency-detect approve --input workItem=tg-1234 --input gateId=detect-retry --input actor=nick
swamp behavior run dependency-detect approve --input workItem=tg-1234 --input gateId=cycle-override:script --input actor=nick
```

Run these against the authenticated server only after explicit retry
authorization. The normal driver clears its own mirrored assistance when it
resumes, before dispatching the new entry. Assistance for the current entry,
including an uncertain workflow launch, stays parked. Independent operator or
safety assistance remains a blocker.


Detection installs no dependencies and never invokes Gateway handoff checks.
Orbit's external-task contract permits completing a child without a handoff
check; its handoff operation always runs the configured Project check, even
when a child declares only review deliverables. The detect finish stage instead
requires a successful worker scan, one report per configured ecosystem, an
unchanged HEAD and tree, the matching open child, and no assistance or PR. It
completes that child and uses the existing group completion and worker cleanup.
The shared stage client refuses check, repair, turn and publish stages for detect.

Existing detect groups created with `project-check` and failed handoff jobs do
not migrate on deployment. Preserve their scan reports, then have the operator
cancel them before a replacement scan. Do not reset failed checks, rewrite
running deliverables or invent a passing handoff receipt.


## Routine and package deliverables

Every generated child declares typed deliverables in shared `project_schedule.ts`:

- Routine and package children have a `project-check` command deliverable. It
  runs the configured `check` from the repository root (`directory: .`), without
  a failing-on-base requirement. Services detection creates no child.
- A review deliverable records workflow and worker evidence, the Gateway check,
  the PR or no-change result, and worker departure.
- Each package child also declares `dependency-lock`, a file deliverable with
  `change: modified` across the selected paths: `composer.lock` for Composer, or
  `{bun.lock,package-lock.json,npm-shrinkwrap.json,pnpm-lock.yaml}` for JavaScript. The latter is Orbit's supported
  glob alternative for the lockfiles recognized by dependency inventory.
  Ecosystem maintenance still owns package-manager admission.

Routine runs may finish with no changes, and detection is read-only, so neither
requires a file diff. Package work requires its selected lockfile to change.
These lists stay below Orbit's five-deliverable limit and apply to the single
batch child. Findings without an eligible target have no Orbit child.
The Gateway validates existing file paths against the repository base.

Orbit allows empty deliverables in backlog but refuses the move to todo with
`tasks.subtask_deliverables_missing`. The old proof groups supplied a review
deliverable before entering the workflow; the project generator omitted it.
The scheduler Gateway fixture now enforces typed request shapes and this todo
guard. Its regression tests create generated groups, move them to todo, and
prove the former empty child is rejected without changing group status.

A shared minute workflow reconciles schedule receipts and admits work. Independent
group workflows advance the official factory runs. Schedule receipts stay in Swamp data. Repeated ticks reuse the group and
factory run. Group creation has no Gateway idempotency contract: save intent
before POST, reconcile a lost reply by the exact saved group brief, and never
repeat an uncertain create. Preserve operator and safety assistance. A cancelled
Orbit group retires its schedule receipt once every child has ended, assistance
is clear, and its VM is destroyed or was never allocated. This also applies
while the official factory still reports provision as active. Keep the factory
history, stop driving the cancelled work, and allow the next detection slot.

Successful detection saves one project findings document with eligible batch
selections and blocked reasons. It creates no package groups. Shared admission
reserves one of four maintenance VM places before creating one batch group per
project, with security priority and fair project rotation. Routine and detect
intents share the same budget. See [project batches](website-packages.md).

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

CI checks project extension types, formatting and lint through the `contracts`
job. It runs no test suites or native Swamp workflows. All fixture and routing
test files remain available for local verification. The `driver` job checks
the maintenance worker modules in the same way. Neither job needs Swamp account
credentials.

Read stuck rows and recorded skips on the deployed server:

```bash
swamp data query 'modelName == "orbit-gateway" && name.startsWith("scheduler-issue-") && attributes.status == "open"' --select attributes --json --server wss://swamp.orbit
swamp data query 'modelName == "orbit-gateway" && name.startsWith("scheduler-skip-")' --select attributes --json --server wss://swamp.orbit
```

The latest `project-work` resource also lists open issues alongside the runnable
work items. Fix a stuck row's cause and let reconciliation resolve its issue.
Never remove a saved create intent just to force another POST.


### Review parking capacity

At most one unfinished group belongs to a Project across routine, package and VM detection. [Project batches](website-packages.md) defines execution-slot release, retry and cleanup rules.

A merge-wait stage does not run package tools. Adding or changing JS manager pins does not change its saved input identity; command stages still bind those pins. Other Project settings and batch targets keep their existing identity checks.
