# Private project configuration

Each `extensions/behaviors/projects/<slug>/` extension owns its repository
identity, maintenance paths, package managers, check, branch and policy. Read
its `mod.ts`, `config.ts` where present, and `manifest.yaml` for the typed
fields. Shared ecosystem policy belongs in the Composer and JavaScript
extensions.

The `configuration` method exposes typed non-secret configuration as Swamp data.
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
local CLI repository argument and group discovery path. Project maintenance
and worker-model callers use that same repository. The frozen `orbit-swamp`
checkout is not a fallback.

Project detection and routine entrypoints are shared: `project-detect` and
`project-routine` take the Gateway-selected positive `projectId` as a run input.
Both run on Node209. Project extensions contain configuration only; they no
longer publish a pair of workflows or a baked-in project schedule. Schedule
producers must supply the project explicitly when they admit a run.

This consolidation removes the native-host dispatcher's `project_scan` and
`project_routine` actions. The two entrypoints select the existing scanner and
Gateway-model methods directly. Those methods still contain Gateway reads and
nested dispatch; their conversion to full state inputs and terminal-result
admission is the next lane25 slice. This change does not establish live
readiness or authorize a new scheduled run. Tasks/maintenance and Dependency
dispatcher actions remain until their consumers move.
