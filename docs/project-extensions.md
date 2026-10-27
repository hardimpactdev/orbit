# Private project extensions

Each maintained project has one private `@nckrtl/project-<slug>` extension in
`extensions/behaviors/projects/<slug>/`. It contains the project's config and
scheduled workflow entry points. It contains no package-manager commands,
version-selection rules, repair loop, PR writer or worker lifecycle code.

This follows the Swamp manual's [extension packaging](https://swamp-club.com/manual/reference/extensions),
[behaviors and methods](https://swamp-club.com/manual/explanation/models-types-and-methods),
[workflows](https://swamp-club.com/manual/reference/workflows) and
[schedules](https://swamp-club.com/manual/how-to/run-workflows-on-a-schedule).
The extension is a package; its config is a behavior resource; its entry points
are workflows with `trigger.schedule`. The official `@swamp/software-factory`
continues to own execution state and transitions.

## Config and delivery

Before project extensions, `orbit-gateway.globalArguments.dependencyProjects`
held Project 20 and Project 18 config. It asserts the Gateway's repository URL, check command and
default branch. Project 20 also supplies worker paths and release-age policy.
There were no dependency schedules. Repository `maintainer.json` and
package-manager policy remain the source when preparing later project entries.

Project 20 moves to `@nckrtl/project-nckrtl-com`. Its config method writes the
same values as before. The Gateway selects registered project extensions through
Swamp cross-behavior calls, validates their returned config, and passes the
resolved object to the existing routine/package client. It never uses cached
config after a failed config call. Duplicate Project IDs and Gateway setting
mismatches refuse dispatch. Existing unmigrated entries remain until their own
extension is added. A project with worker config cannot fall back to a script. Routing registration
contains only its ID and extension type. Each stage loads only the matching
extension, so adding projects does not multiply nested calls in a driver tick.

Private manifests declare the Composer/JS extension dependencies and
`visibility: private`. Delivery stays in the private Git repository and validated
Services deployment bundle, as for the ecosystem extensions. Nothing is pushed
to the registry. Registry publication requires a separate decision from the
orchestrator. Project workflow YAML lives under `workflows/` and is listed in its private
manifest. The normal repo loader finds both the behavior and workflows. No
local source registration or registry pull is needed. Their IDs are created by
Swamp and remain stable.

## Schedules and shared work

A weekly routine starts one external group with one child and starts the existing
`dependency-routine` factory. The dependency drivers reserve nested-call capacity
with `maxCallsPerTick: 60`. The shared schedule tick rotates four active groups
at a time, so parked work cannot starve other projects. A daily detection run uses a separate instance of
the official factory: provision, detect on the task worker, check, finish without
a PR. Detection failures or a changed checkout park for assistance. Detection
never starts a repair turn or publishes changes.

A shared minute tick advances scheduled work with the existing Orbit driver.
It records schedule receipts in Swamp data. Repeated ticks reuse the group and
factory run. Group creation has no Gateway idempotency contract: save intent
before POST, reconcile a lost reply by the exact saved group brief, and never
repeat an uncertain create. Preserve operator and safety assistance.

Successful detection creates one ordinary external package group per path,
ecosystem and major/advisory finding. Repeated scans reuse an unresolved finding.
Each task contains the detection evidence and project policy. A major with a
stable detected version can enter `dependency-package`; security findings without
a verified fixed version remain backlog tasks for explicit target selection.
Never invent a fixed version or weaken policy. Package runs retain the existing
per-project sequence: the previous package must merge and complete, or be
cancelled, cleaned up and acknowledged, before the next is admitted. The package
extension still verifies advisories after applying a selected version.

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

Only after that proof, add the other maintained projects in a second PR using
their current config and setup. Leave Horizon New Dawn to its own thread. Do not
change project repositories; removing maintainer there is phase 5.
