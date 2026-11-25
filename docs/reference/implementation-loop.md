---
title: "Feature delivery"
description: "How a change reaches main: review evidence, CI, local checks, worktrees, and the merge queue."
covers:
  - apps/docs/app/{Documentation/{AdrLifecycle,DocsImpact}.php,Librarian/**}
  - bin/{review-check,task-check,test,ci-build-plan,ci-units,ci-delivery,ci-queue,ci-tree-receipt,ci-proof-eligibility,ci-proof-run,ci-proof-manual,ci-cache-ref,ci-runner-prerequisites,check-processes,php-build,swamp-shape-gate}
  - "{swamp/bin/{check-grant-patterns,validate-swamp-workflows,check-swamp-base-policy,swamp-shape-python,swamp_shape_host_tools}.py,swamp/bin/{check-swamp-shape,gate-test-support}.ts,swamp/config/swamp-shape-baseline.json,tools/check-processes.php}"
  - "{tools/check_budget.py,tools/pest_runtime.py,bin/pest-plain,swamp/tests/_support/slice.ts}"
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/{setup.sh,javascript.py,user-manager.sh},agent_command.py}
  - .github/{ci/**,workflows/*.yml,actions/php/action.yml}
  - apps/gateway/{phpunit.xml,database/migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php,tests/Support/TestDatabaseEnvironment.php,tests/Support/TestDatabaseGuard.php}
---

# Feature delivery

A feature reaches `main` as one complete pull request with code, tests, documentation, and any in-progress ADRs ([Contributor guide](/contributor-guide)). This page documents review evidence, CI jobs, budgets, local gates, and the merge queue ([stage 4](/reference/command-development-pipeline#4-pr-and-ci)).

## Review on Incus

Orbit's independent reviewer checks the complete PR and reproduces fleet behavior in a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy) before merge. The review records enough sanitized evidence for another maintainer to assess the result:

| Evidence | Content |
| --- | --- |
| Revision | Exact PR head and source revision run on the nested fleet |
| Environment | Node roles, OS, configuration, and resource identity |
| Acceptance | Actions, expected vs observed outcomes, exit codes, and state changes |
| CI | Passing `Required checks` for the reviewed head |
| Limitations | Unverified behavior, failed checks, and open findings |
| Verdict | Whether the feature is ready for maintainer approval |

Address every blocking finding and repeat affected checks before merging.

## CI

`.github/workflows/ci.yml` uses `.github/ci/checks.toml` and `bin/ci-build-plan` to select only the jobs whose inputs changed, then aggregates them in `Required checks`.

| Job | What it runs |
| --- | --- |
| `gateway-tests` | Gateway static checks and 5 Pest shards (`vendor/bin/pest --parallel --shard=N/5`, timed by `apps/gateway/tests/.pest/shards.json`) |
| `gateway-real-clients` | Gateway tests requiring real MySQL/PostgreSQL clients (`ORBIT_REAL_DATABASE_CLIENTS=1`) |
| `cli-tests` | CLI static checks and Pest suite |
| `sdk-tests` | PHP SDK static checks and Pest suite |
| `docs` | `composer docs-build`, `composer docs-lint`, `bin/docs-impact --gate`, and Docs Pest suite |
| `web` | `bun run check`, `bun run test:unit`, `bun run test:browser`, and `bun run build` (PRs and `merge_group`) |
| `agent` | `cargo check`, `cargo clippy --locked --workspace --all-targets -- -D warnings`, and `scripts/check-crates test` (4 min timeout) |
| `swamp` | Swamp control checks, workflow validation, and Deno operation tests |
| `proof-run` | Selected command proofs when `ORBIT_PROOF_ENABLED=true` |
| `ci-policy` | Runner policy, workflow hygiene, and `checks.toml` checks |

Pull requests and merge queue runs read the caches that `main` saved. `main` runs no tests, so three jobs there save those caches and gate nothing: `gateway-test-caches`, `web-test-caches`, and `agent-test-caches`. The `agent` job only restores the Cargo cache (the registry, Git dependencies, and `apps/agent/target`) and never saves it. After a lockfile change it starts from the newest entry built by the same compiler on the same operating system and architecture, and Cargo rebuilds what changed.

Local `bin/ci-units run` timing files (`ci-unit-times.json`) are ignored by Git and rejected by `Required checks` if force-added.

### One PHP build in CI

`.github/actions/php/action.yml` installs PHP 8.5 (`coverage: none`, no TIA) and Composer 2.10.3. Web jobs use Bun 1.4.2 and `.node-version`. Static PHP binary compilation (`bin/php-build`) validates pins on PRs and builds/publishes only on `main`.

### Full suites gate merges

Pull requests run fast unit/static/control checks and selected proofs. The `merge_group` run adds the selected Swamp fixture shards. Every changed PHP project runs its full Pest suite in CI; Test Impact Analysis (`--tia`, `--locally`, `PEST_TIA`) is disabled.

### Merge queue

Orbit merges PRs through GitHub's merge queue (`ALLGREEN`, squash merge, one group at a time, 20-minute timeout):

```bash
gh pr merge <number> -R hardimpactdev/orbit --squash --auto --match-head-commit <SHA>
```

### Deliver the queue's commit on main

Pushes to `main` run under a per-commit concurrency group (`ci-${{ github.workflow }}-${{ github.sha }}`). `bin/ci-tree-receipt` and `bin/ci-delivery` verify that the merge queue tested the exact tree, promote the queue's `web-queue-<run>-<attempt>` artifact to `web-dist-<sha>` (without re-running `web`), prepare Gateway release inputs, and dispatch `orbit-cli-release.yml` when CLI or agent sources (`apps/agent/scripts/source-commit`) changed.

### Self-hosted shard runners

Self-hosted runners live in the shared `self-hosted, hardimpact, orbit-shard` pool (`slots = 10`, five Sabre and five Shark runners). `tools/ci-runners/setup.sh` provisions each runner with `ACTIONS_RUNNER_ACTION_ARCHIVE_CACHE`, `job-started-hook.sh`, and pinned Deno/Python/Ansible tools.

### Self-hosted fixture shards

Selected Swamp shape fixtures run in `merge_group` and task-handoff pushes on the self-hosted shard pool (two workers per shard). Budget limits in `.github/ci/checks.toml` target a 5-minute PR wall time: `shard_seconds = 150`, `shard_deadline_seconds = 210`, `shard_ceiling_seconds = 240`, `unit_ceiling_seconds = 180`, `runner_shards = 21`, `pr_shards = 20`, `self_hosted_shards = 10`, `slowest_runner_factor = 1.15`. Run `bin/ci-units cuts` to inspect shard packing; jobs track elapsed time via `ORBIT_JOB_STARTED_AT`.

For a hosted fixture with a [known capacity gap](/decisions/0262-prove-changed-operations-on-the-pull-request), a resource rename can select just its `mandatory_cases`. The fixture and its shared inputs must differ only in references to the renamed path. If an independently declared `phase_inputs` path changes at the same time, its `mandatory_cases` also run. A change to test behavior, the registered case list, or any other input keeps the normal selection rule.

When a task for container hosts changes persistent fixture state, regenerate saved boundaries with `deno run -A swamp/tests/_support/regenerate_host_image_boundaries.ts --write` (`--check` to verify without writing).

### The proof run job

When `ORBIT_PROOF_ENABLED=true`, `proof-eligibility` and `proof-run` (plus `proof-outside.yml` for forks and `ci-proof-manual.yml` for manual runs) execute `bin/ci-proof-run` against the PR head (`timeout-minutes: 275`, `WAIT=270m`). See [The proof CI](/reference/feature-flow#the-proof-ci).

## Local checks and gates

### The scoped task check

In a task copy, `bin/task-check` runs the fast scoped gate for changed files (`--base`, `--budget` default `270`, `--only`, `--ci-time-limit`, `PIN_TESTS`) and writes `orbit-handoff/delivery.json`. It covers changed PHP tests, Pint, PHPStan, `bin/docs-impact`, `bin/swamp-shape-gate`, CI policy, and API fixtures.

### The candidate gate

Root `composer check` runs `bin/review-check`, which verifies formatting, PHPStan, `bin/build-api`, `bin/api-fixtures --check`, docs build/lint, `cargo fmt` when Rust files changed, and web checks when web files changed.

### Web app checks

When `apps/web` or API schemas change, `bin/review-check` runs `bun run check`, `bun run build`, and generated-types verification (`src/api/schema.d.ts`). Run `bun run test` inside `apps/web` for full unit, verification-daemon, and Chromium browser coverage.

### Swamp shape gate

`bin/swamp-shape-gate` checks Swamp control rules, linting, workflow validation, and shape conformance (`--only control|lint|validate|shape`). Without Swamp credentials, `--only validate` exits `78`.

### Formatters run in fix mode

Local `composer check` and `bin/task-check` run Pint and Rector in fix mode so style fixes land automatically; CI runs check-only verification.

### Findings are fixed, not silenced

PHPStan runs at level 9 across all four PHP projects. Fix root causes instead of adding baselines, `@phpstan-ignore`, `assert()`, or inline `@var` casts.

### Parallel processes

`bin/check-processes` (`tools/check-processes.php`) computes safe parallel worker counts from available RAM (640 MiB reserve, 1,664 MiB per worker), overridable via `ORBIT_CHECK_PROCESSES`.

### Gateway test time limits

`apps/gateway/phpunit.xml` enforces a 5-second default per-test limit, with explicit `#[Large]` attributes for slow tests. `apps/docs/phpunit.xml` sets `enforceTimeLimit="false"`.

### Gateway tests and privilege

`TestDatabaseGuard` and `TestDatabaseEnvironment` refuse to run tests against non-test databases (`ORBIT_TEST_DATABASE`). Never run `bin/test` or `cargo test` as root; privileged tests are marked `@group` / `--ignored` and run explicitly on disposable Linux hosts.

## Generated artifacts

Derived files stay out of Git and are regenerated by `composer docs-build` and `bin/build-api`: `docs/docs.json`, `docs/flows-data.js`, `docs/generated/context.json`, `docs/openapi.json`, `apps/gateway/resources/mcp/tools.json`, and `apps/web/src/api/schema.d.ts`.

## Caches and checkout seeding

### Main caches come only from clean main

### Only main CI publishes

### CI cache refs

### Seed a checkout

`ci.yml` does not publish `refs/orbit/ci-cache/*` refs or `ci-cache-*` artifacts; `bin/ci-cache-ref` remains in the repository only for legacy consumers. Development checkouts and task copies inherit dependencies and build state from their Project's default container seed (`seed-<commit>`) via `bin/bootstrap` fingerprint caching ([Lifecycle](/reference/development-containers#lifecycle)).
