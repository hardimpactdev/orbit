# Add a repository to dependency updates

New projects use one [private project extension](project-extensions.md). Start
with the [template](templates/project-extension/README.md). Project 20 is
`@nckrtl/project-nckrtl-com`; its paths, policy, check and branch live in that
extension's `mod.ts`. The Gateway keeps only its routing registration:

```yaml
projectExtensions:
  - projectId: 20
    type: "@nckrtl/project-nckrtl-com"
```

The shared `dependency-routine` and `dependency-package` factories select the
config by the group's Orbit Project ID. The Gateway refreshes that extension's
configuration resource through Swamp before each stage or package admission. It
validates the returned Project ID and settings before dispatch. All 19
maintained projects now use extensions; the legacy `dependencyProjects` list is
empty. See the [fleet inventory](maintained-project-extensions.md) for source
commits, paths and checks.

1. Install the repository's package managers and tools through Project setup,
   including local Vite+ when used. Configure `worker` with the Composer and JS
   paths and assertions against the repository's release-age policy. Paths must
   be relative, unique within each ecosystem, and stay inside the checkout.
   Policy keys must name configured paths. The VM base image needs Swamp and the
   Orbit root CA; see [worker maintenance](task-worker-maintenance.md). For a
   project without worker support, omit `worker` and supply a tracked,
   executable script accepting `routine`, `detect`,
   `package <composer|js>
   <name> <version> <major|security> [advisory-id ...]`,
   and `summary`.
2. Fill the extension config using the actual Orbit Project ID, exact
   `repository_url`, `task_check` and `default_branch` from
   `orbit project:show <id> --json`. Add its ID/type registration to
   `projectExtensions`. Retain `script` in the config identity until script
   projects are retired. A project with `worker` never falls back to it on
   worker failure. No factory copy, new Gateway endpoint or dependency-specific
   Orbit record is needed.
3. Run the local fixture tests. After review/merge, the operator applies the
   reviewed Swamp commit and restarts `swamp-serve`. Do not apply a rename while
   a dependency run is active. The renamed factory/workflow IDs stay unchanged;
   retain all factory, target, job and receipt data. Package admission refreshes
   the official factory overview, which reads historical runs by stable ID even
   when their stored model name predates the rename. Do not reset prior runs.
4. Only with live-run approval, create one ordinary external group with one
   child for this Project. For routine work, start `dependency-routine` with
   `workItem=tg-<id>`, then tick the `dependency-routine` workflow. For a single
   package, pass the existing package target JSON to `dependency-package`. These
   workflows never create groups. See [routine](website-routine.md) and
   [package](website-packages.md) for gates, target fields and repair limits.

Unknown/duplicate Project IDs and mismatched Project settings refuse work.
Worker paths, policy and the tracked script path come from the resolved config.
Provision and handoff still send only Orbit's supported request fields: the
Gateway starts from its Project's default branch and runs its Project's check.
Config asserts those values; it cannot override them or inject a command into an
operation. Request identity includes the selected config, so a changed config
cannot replay a stage under the same key. Reconcile existing receipts before
changing a running project's entry.

Routine work updates, checks, allows at most one repair, reruns worker
maintenance after repair, then checks again before publication or clean
no-change completion. Package behavior is unchanged: one explicit package per
fresh group, one repair plus maintenance/audit verification, then publish. Each
project's next package waits for its own previous package to merge and complete
with the VM destroyed, or to be cancelled, cleaned up and acknowledged. Work in
another configured project does not block it. Operator and safety assistance
still blocks work; clearing assistance is not consent.

Rollback: stop ticks, reconcile active Gateway operations, and restore the
previous reviewed definitions/config only after affected runs are drained.
Retain receipts and do not restart an old group as new work. This change starts
no groups, deploys no service and supplies no new interruption-recovery proof.
