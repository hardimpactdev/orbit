# Private project configuration

`extensions/behaviors/operations/project-maintenance/projects.json` holds the
repository identity, maintenance paths, package managers, check, branch and
release-age policy of every maintained Project, one entry each. The
`project-maintenance-input` extension is its only reader: it imports the file and
validates an entry against `_lib/dependency_project_schema.ts` when a run names a
Project. A Project without an entry is refused with
`project.maintenance_not_configured`. To maintain a new Project, add its entry.
Shared ecosystem policy belongs in the Composer and JavaScript extensions.
[Recorded project configuration](maintained-project-extensions.md) and its
fixtures preserve the selected paths and existing release-age policy; they do
not authorize a live run.

Execution follows the binding [Swamp shape guide](swamp-shape.md). Supply full
non-secret intent as workflow inputs and secret references as vault names and
keys. Do not copy the retired Gateway-client task driver or an existing
grandfathered workflow as a template. The native pilot settles the remaining
dispatch and terminal-output details.

Keep operational control flow in native workflow steps. Preserve private
distribution and the project's approval settings. A configuration change grants
no new credential, worker or production authority.

The retained shared native launcher uses Node 209's monorepo Swamp repository,
`/home/orbit/apps/orbit-monorepo-staging/checkout/swamp`. The shared
`SWAMP_REPOSITORY` constant supplies the working directory, launcher script,
local CLI repository argument and group discovery path. Retained worker-model callers use that repository. Project maintenance uses native workflow steps instead. The frozen `orbit-swamp`
checkout is not a fallback.

Project inspection and routine entrypoints are shared: `project-maintenance-inspect` and
`project-routine` take the full Gateway-selected Project snapshot as run inputs.
Both run on Node209. The configuration file holds data only; it does not publish a pair of workflows or a baked-in Project schedule. Producers
supply the Project and group state when they admit a run.

The native-host dispatcher's `project_scan` and `project_routine` actions are
removed. The `package_admit`, `project_work` and dependency scan dispatcher branches are also removed.

## Gateway inputs and native project results

Project maintenance admits the Project repository, default branch, check, current groups and public operation evidence as run inputs. `projects.json` keeps the maintenance paths and release policy. A step never fetches Project or Task state from the Gateway. Git acquisition is one placed native Git command, followed by the dependency scan and canonical native result publication. Repository credentials use the admitted Operation and the existing scoped GitHub App authority; the scanner has no project-token callback.

Routine and batch selection produce typed requests in the terminal result. The Gateway applies those requests through its normal group and Operation admissions. Swamp does not create groups, post task Operations or start nested CLI processes. Native failures keep their original status; they do not fabricate a successful downstream admission.

The shared workspace child only prepares a directory. VM provider, bootstrap, hub and model/Pi composition require the canonical TaskVM provision child. Workspace readiness alone cannot admit provision completion or mark a group running.

The frozen Orbit rule accepts vault values in command environments, so acquisition uses a fixed native `command/shell` Git invocation with its authorization header in the Git environment. The Gateway derives that header from the same scoped token before writing its execution copy. Native PR reads receive the token through a separate execution copy. This adds no Git transport or credential helper.

## Scheduled producer boundary

Scheduled maintenance publishes the full captured public intent into native Swamp schedules. Steps never POST downstream admissions to the Gateway. The Projects family publishes committed snapshots and validates their revision, exact input and terminal replay identity before normal guarded admission. The existing shared schedule subscriber activates publication and delivers native terminal results. Task and Project changes invalidate the snapshot; failed publication keeps it unaccepted. Only this declared producer requests exact workflow-write authority for native trigger inputs.

Maintenance execution uses the canonical TaskVM provision root, the shared `task-vm-workspace` directory child, and separate official SSH actions from209 for package and routine work. Native results admit check, at most one Pi repair, checked publication and parking. No-change completion and ended-PR completion use the owning native cleanup root. A capacity-refused Todo group can be selected again by a later native observation without creating another group. See [the maintained contract](../../docs/reference/instance-dependency-contracts.md).
