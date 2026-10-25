# Add a repository to dependency updates

The shared `dependency-routine` and `dependency-package` factories use the
existing Orbit Project ID on each group to select one entry in
`models/@nckrtl/orbit-gateway/orbit-gateway.yaml`, under
`globalArguments.dependencyProjects`. Website is the first entry:

```yaml
dependencyProjects:
  - projectId: 20
    repository: git@github.com:nckrtl/website.git
    script: scripts/dependencies
    check: composer check
    defaultBranch: main
```

1. Merge the repo's tracked, executable dependency script. It must accept
   `routine`, `detect`, and `package <composer|js> <name> <version>
   <major|security> [advisory-id ...]` as literal arguments. The repo owns
   constraints, audits, release-age rules and package-manager commands. Its
   Project setup must install the tools the script needs (including local Vite+
   for repos that use it).
2. Add one entry using the actual Orbit Project ID, exact `repository_url`,
   `task_check` and `default_branch` from `orbit project:show <id> --json`.
   Set `script` to the tracked path relative to the repo root. No factory copy,
   new Gateway endpoint or dependency-specific Orbit record is needed.
3. Run the local fixture tests. After review/merge, the operator applies the
   reviewed Swamp commit and restarts `swamp-serve`. Do not apply a rename while
   a dependency run is active. The renamed factory/workflow IDs stay unchanged;
   retain all factory, target, job and receipt data. Package admission refreshes
   the official factory overview, which reads historical runs by stable ID even
   when their stored model name predates the rename. Do not reset prior runs.
4. Only with live-run approval, create one ordinary external group with one
   child for this Project. For routine work, start `dependency-routine` with
   `workItem=tg-<id>`, then tick the `dependency-routine` workflow. For a single
   package, pass the existing package target JSON to `dependency-package`.
   These workflows never create groups. See [routine](website-routine.md) and
   [package](website-packages.md) for gates, target fields and repair limits.

Unknown/duplicate Project IDs and mismatched Project settings refuse work.
The script path comes from this config. Provision and handoff still send only
Orbit's supported request fields: the Gateway starts from its Project's default
branch and runs its Project's check. Config asserts those values; it cannot
override them or inject a command into an operation. Request identity includes
the selected config, so a changed config cannot replay a stage under the same
key. Reconcile existing receipts before changing a running project's entry.

Routine behavior is unchanged: update, check, at most one repair, recheck,
publish or clean no-change completion. Package behavior is unchanged: one
explicit package per fresh group, one repair plus script/audit verification,
then publish. Each project's next package waits for its own previous package
to merge and complete with the VM destroyed, or to be cancelled, cleaned up and
acknowledged. Work in another configured project does not block it. Operator
and safety assistance still blocks work; clearing assistance is not consent.

Rollback: stop ticks, reconcile active Gateway operations, and restore the
previous reviewed definitions/config only after affected runs are drained.
Retain receipts and do not restart an old group as new work. This change starts
no groups, deploys no service and supplies no new interruption-recovery proof.
