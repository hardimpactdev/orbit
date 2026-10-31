# Horizon New Dawn project extension

Private `@nckrtl/project-horizon-new-dawn` moves upstream detection out of the
package's maintenance script. `config.ts` holds Project 57, repository identity,
paths, ledger location, checks and Nick's merge approval. The ledger stays in
the package. This extension does not use generic dependency maintenance.

- `configuration` writes the project config resource.
- `detect({cwd})` runs on the group's VM worker. It writes the script-compatible
  `detection` resource and a `plan` with one ordered task per candidate. A
  missing ledger writes one baseline task instead. Invalid ledgers fail. The
  checkout must point to the package repository and its ledger must be
  committed.
- The Gateway extension's `horizon_worker` verifies the existing enrollment
  receipt and open package group. It never changes worker lifecycle state.
- `horizon_tasks({plan})` on `orbit-gateway` creates one external **backlog**
  group containing the planned tasks. It selects package Project 57 and checks
  the Project's exact repository and requires automatic review/merge to be off.
  A repeated scan reuses the group's receipt. An uncertain create stops for
  reconciliation.

`@nckrtl/project-horizon-new-dawn-detect` verifies worker placement on the
server, runs detection on that worker and materializes tasks on the server using
Swamp data references. It requires an existing enrolled package task group, its
exact worker name and its VM checkout path. The Project ID comes from config. It
has no schedule and starts no factory run. The orchestrator must authorize a
live call. When started, parity tasks run through the existing official software
factory and Gateway; PRs wait for Nick. No factory engine is copied.

The merged package baseline is already at `v5.50.1`. Do not repeat it. Detection
cannot proceed on Project 27 `horizon-new-dawn-website`: that Project points to
the separate website repo. Project 57 `horizon-new-dawn` is the package Project.
Its check is `composer format:test && composer analyse && composer test`, and
review and merge remain off so that parity PRs wait for Nick.

Delivery stays in the private orbit-swamp deployment bundle. Do not publish this
manifest to the registry without the orchestrator's approval. After merge, the
orchestrator deploys from Nick's Mac and restarts serve. Leave
`scripts/horizon-sync` in the package until phase 5.

Run affected checks from orbit-swamp (one test worker):

```sh
~/.swamp/deno/deno test --allow-all extensions/behaviors/projects/horizon-new-dawn/mod_test.ts extensions/behaviors/projects/horizon-new-dawn/queue_test.ts extensions/behaviors/projects/horizon-new-dawn/gateway_test.ts
~/.swamp/deno/deno check extensions/behaviors/projects/horizon-new-dawn/mod.ts extensions/behaviors/projects/horizon-new-dawn/gateway.ts
```

See [the parity contract](../../../../../docs/horizon-new-dawn.md) for the
baseline history and fixture tag pairs.

Detection refreshes `origin/main` before comparing the checkout. Fetch failures
stop before planning tasks. Empty or non-JSON Gateway responses report the HTTP
status and require reconciliation. A non-object ledger reports an invalid
`synced_tag` before reading upstream.
