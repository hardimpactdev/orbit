# App development setup defaults blast radius

Candidate `f6be6ef4ed483793a0bb46b7715db36984dbdacb` changes 70 files with
3,081 insertions and 77 deletions.

Repository-wide searches classified the affected surface as follows:

- 45 files reference the app-development default model, API, CLI command
  family, schema, tests, or docs.
- 46 files reference Bun or `BunTool`; the relevant changed surface is the
  tool definition, catalog registration, both app role baselines, convergence,
  tests, and tool docs.
- Four files reference `CopyAppDevelopmentSetupSteps`: the action, both
  creation producers, and the action test.
- Nine gateway app/test files contain the creation predicates used to check
  `InstanceController::store`, `AppRegistrar::registerAppRecord`, and
  `wasRecentlyCreated` behavior.
- The diff spans the product decision ledger, CLI, public and technical docs,
  generated command catalog, gateway actions/controllers/models/services,
  migration, API routes, OpenAPI SDK classification, and focused tests.

The consumer inventory covers:

- app default create, list, update, and remove;
- instance creation through the API controller and registration service;
- copied instance setup-step list and execution;
- existing-instance and repeat-registration behavior;
- app-development, app-production, and Laravel Cloud exclusion predicates;
- Bun install, update, remove, probe, role registration, and node convergence;
- authorization, activity logging, command output, product docs, and generated
  catalog/OpenAPI surfaces.

Focused tests, the exact-candidate monorepo quality gate, and retained Incus
proof cover these producers and consumers. No unmatched production deployment,
release-directory, or zero-downtime inheritance path was added.
