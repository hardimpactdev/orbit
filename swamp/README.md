# Orbit Swamp

Swamp lives in the Orbit monorepo under `swamp/`. Submit Gateway and Swamp changes in one `hardimpactdev/orbit` PR. The old `hardimpactdev/orbit-swamp` repository is frozen. Read the binding [Swamp shape guide](docs/swamp-shape.md) before changing operations.

Orbit's operational workflows and shared Swamp extensions live here. Swamp owns
execution. The Gateway owns state, validation, intent and terminal result
projection. The agent observes.

Read the binding [Swamp shape guide](docs/swamp-shape.md) before writing,
porting or reviewing an operation. It and the
[Swamp manual](https://swamp-club.com/manual) are the authoring and review
standard. Existing grandfathered implementations do not establish the intended
contract.

Right-shape conversions continue using the shared native template. Live pilot
readiness remains separate; existing family workflows do not all conform.

## Repository

- `workflows/` contains native workflow definitions.
- `extensions/behaviors/` contains typed behaviors and shared extension code.
- `tests/` contains specifications, fixtures and the native harness.
- [Project configuration](docs/project-extensions.md) and
  [manifest range policy](docs/website-packages.md) describe maintenance data.
- [Node workers](docs/node-workers.md),
  [placed commands](docs/node-command-runner.md) and
  [app accounts](docs/instance-sudo-steps.md) describe the required execution
  boundary.
- [Native retention](docs/native-retention.md) and
  [source loads](docs/node209-source.md) describe runtime ownership.

## Checks

Run the per-PR checks in the binding guide: validate every workflow on the
pinned runtime, run the operations Deno tests and the placement and Swamp-shape
gates. The existing-violation baseline may only shrink. Read native history and
reports to verify behavior; green checks alone do not establish conformance.

Lane 24 moves this repository under `swamp/` in `hardimpactdev/orbit`. The guide
moves unchanged to `swamp/docs/swamp-shape.md`. Preserve source history and
existing PR attribution. Runtime and loader changes belong to their platform
owner.

## Domain policies

Keep [dependency merge admission](docs/dependency-merge-contract.md),
[automatic deployment controls](docs/automatic-deploy.md),
[smoke failure classification](docs/production-smoke-failure-contract.md) and
[package isolation](docs/service-dependency-detection.md) while changing
execution. The binding guide replaces operational mechanisms, not these domain
rules.
