---
title: "Feature delivery"
description: "How a change reaches main: review evidence, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/{AdrLifecycle,DocsImpact}.php
  - bin/{review-check,test,ci-build-plan,ci-cache-ref,worktree-create,worktree-remove,check-processes,php-build,swamp-shape-gate}
  - "{swamp/bin/{check-grant-patterns,validate-swamp-workflows,check-swamp-base-policy}.py,swamp/bin/check-swamp-shape.ts,swamp/config/swamp-shape-baseline.json,tools/check-processes.php}"
  - tools/check_budget.py
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/{setup.sh,javascript.py},agent_command.py}
  - .github/{workflows/*.yml,actions/{php,ci-scope}/action.yml}
  - apps/gateway/{phpunit.xml,database/migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php,tests/Support/TestDatabaseEnvironment.php,tests/Support/TestDatabaseGuard.php,tests/Support/PythonPrograms.php,tests/Support/FixtureProcessRunner.php,tests/Support/GatewayReleaseFixture.php,tests/Support/TaskSchedulerTickFixtures.php,tests/Feature/Domain/TaskWorkspaceTmpdirTest.php,tests/Feature/Infrastructure/Instances/DevelopmentSourceRemovalCommandTest.php}
---

# Feature delivery

A feature reaches main as one complete pull request with its code, tests, documentation, and any in-progress ADRs. The [contributor guide](/contributor-guide) describes the steps. Orbit builds its own features as [tasks](/reference/tasks): the first subtask writes the documentation, each subtask handoff runs the Project task check, and the task ends in a pull request. This page covers review evidence, CI, the local checks, and the main caches that make those checks fast. The merge is stage 4 of the [command development pipeline](/reference/command-development-pipeline#4-pr-and-ci).



## Review on Incus

Orbit's independent reviewer checks the complete change and reproduces the feature on an [Incus topology](/reference/incus-topologies) before merge. Use the machines allocated to the review, and verify which source commit runs there. Exercise the feature and its important failure cases, then inspect the resulting state changes.

The review records enough evidence for another maintainer to assess the result.

| Evidence | Content |
| --- | --- |
| Revision | The exact pull request head and the source revision that ran on the machines |
| Environment | Node roles, operating system, configuration, and resource identity |
| Acceptance | Actions, expected and observed outcomes, exit codes, and state changes |
| CI | A passing `Required checks` job for the reviewed change |
| Limitations | Unverified behavior, failed checks, and open findings |
| Verdict | Whether the complete feature is ready for maintainer approval |

Store detailed logs in CI artifacts or in shared evidence outside the checkout, and link them from the review. Share only sanitized records. When no machines are available, the reviewer returns code findings and marks the Incus review as pending. For documentation and tooling changes, verify the behavior that the change affects.

CI compares proof case definitions at the merge base and head before starting the proof job. The runner expands both files once; CI publishes its selection for the Gateway to consume. Missing or invalid results select whole files. [Choose the cases](/reference/feature-flow#choose-the-cases) defines those rules.

## After review

Address the blocking findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. The final review must cover the head that the gate reads. The gate and the merge are [stage 4](/reference/command-development-pipeline#4-pr-and-ci). After the merge, keep the review evidence and release the resources allocated to the feature. For a local worktree, run `bin/worktree-remove ISSUE`.

## CI

Orbit CI runs builds and static checks. Swamp changes also run the Operation Deno tests. Pull requests, pushes to `main`, task handoff pushes and manual runs use the same gate.

| Check | Work |
| --- | --- |
| PHP syntax and Composer | Check frozen agent command references, validate every tracked Composer manifest and run `php -l` on changed PHP files, with at most four workers |
| Gateway static analysis | Run the complete Gateway PHPStan configuration and collect all Gateway tests whenever a PR changes any file under `apps/gateway/`, including tests |
| CLI build | Build the existing Linux and macOS CLI binaries through the reusable Orbit CLI Binary workflow |
| CLI static analysis, PHP SDK static analysis, E2E static analysis | Run PHPStan for `apps/cli`, `packages/php-sdk` or `apps/e2e` when a PR changes any file of that project, `composer.lock` included. The SDK also selects the CLI analysis |
| Docs build | Generate the documentation index, check its committed freshness and run strict documentation lint; run `bin/check-flows` on the [flow charts](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md) |
| Generated contracts | Check Operation kinds, their charts and workflow intent; generate OpenAPI, MCP tools and the web schema; check the recorded fixtures |
| Web build | Build the web app and require `dist/index.html` and `dist/version.json` |
| Gateway release inputs | Pass on every main commit, because a Gateway release also loads Swamp; on a pull request, skip Swamp-only changes |
| Swamp | `bin/swamp-shape-gate`: validate every workflow when the change selects Swamp and every night, run the Operation Deno tests, enforce the Swamp-shape gate and reject new per-run or invalid [behavior names](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md#behavior-names) |
| Proof harness | Only for changes under `swamp/proof/` or shared CI inputs: run the case runner unit tests and lint the proof programs with `ruff` |
| CI proof | Prove a pull request head with `orbit proof:run` when a changed path selects a command. Required checks requires its success, including the outside proof for a fork; see [the proof run job](#the-proof-run-job) |
| Required checks | Require every selected build, static check and PR proof to succeed; only an explicitly untouched job may skip |

The Swamp gate rejects a new behavior name that carries a run id. A task workflow names its local and data steps by the address of the task VM, as [Behavior names](/reference/tasks#behavior-names) says.

The Swamp Deno test job uses `[self-hosted, orbit-shard]` when `ORBIT_SABRE_RUNNER=true` on main and same-repository pull requests. Fork pull requests and a false variable use `ubuntu-26.04`. Docs, web, contracts and workflow validation stay hosted. `tools/test_ci_runner_policy.py` pins this routing and the fork guard. Flip `ORBIT_SABRE_RUNNER` to `false` to roll back.

Runner containers are unprivileged. Install or repair their toolchain with `tools/ci-runners/setup.sh container NAME` while idle; jobs verify the managed PHP build and use fake privileged boundaries. Ordinary Deno tests need no sudo or Docker daemon; real Docker proofs run only on disposable proof VMs.

### The proof run job

`proof-run` in `.github/workflows/ci.yml` is part of `Required checks` when a pull request's changed paths select commands. It passes only the full head SHA and an idempotency key to Orbit. A new push cancels the previous proof. A fork needs the trusted base-branch workflow, a maintainer's approval of the head and the `run-proofs` label; fork code never runs on the managed runner. That workflow publishes `Orbit outside proof` on the exact head. Required checks reads its latest trusted status and fails if it is missing, pending or failed. After the outside proof succeeds, rerun the failed Required job.

CI proofs join one FIFO admission queue. Its front takes every free usable CI place up to its number of batches. Orbit balances whole batches by measured case time, runs them on fresh VMs, merges the verdicts and deletes the VMs. Capacity waits within a two-hour admission budget and then fails clearly. See [proof:run](/cli/proof/run).

A clean CLI exit is insufficient: the job requires a passing result with case verdicts for every selected command. A failed case, an unrun case, a missing result or a selected command with no verdict fails. A result with no selected command passes. A capacity message with no cases always fails; it has no successful skip classification.

The job summary lists case verdicts and uncovered paths. The `proof-receipt` artifact records the commit, operation, selected commands, outcome and idempotency key. It excludes execution input and host settings. Reports use full Swamp output artifacts because native history summaries keep only 4 KiB.

The job holds no secret. The runner's CLI acts as its enrolled Node from its WireGuard address, with an access grant to the Gateway. Only an active authenticated `ci-runner` Node gets the CI pool. The default labels are `self-hosted, hardimpact, orbit-shard`; `ORBIT_PROOF_RUNNER` can supply a JSON list of labels. The 195-minute job timeout covers two hours of admission, execution and cancellation.

`bin/ci-build-plan` owns build input selection. Gateway or SDK changes run generated contracts and Web; CLI or SDK changes build CLI binaries. Documentation changes run Docs. PHP changes run syntax and Composer validation. A change under `apps/gateway/resources/agent/` also selects that job: it downloads the pinned Linux agent, verifies its checksum, and checks that its `contract` output exactly matches the frozen command file. Shared workflow, script and tooling changes select all builds.

Swamp source lives under `swamp/`. Swamp-only changes select the Swamp checks and skip PHP and Gateway builds. Changing `swamp/config/gateway-workflow-grants.json` also selects Generated contracts. A run whose token the Gateway writes to a vault key, such as `task:sync` with `task-sync-token-<uuid>`, adds its key pattern there. That job checks the intended policy against the loaded Gateway declarations. Every main commit builds and uploads Web and becomes a Gateway release, Swamp-only ones too, because the release [loads Swamp](/reference/gateway-recovery#load-swamp). The CI run on `main` selects builds the same way.

The Swamp shape job runs `bin/swamp-shape-gate --only shape`. The script runs the gate, Python analyzer, frozen-base policy and validation CI regression tests through Deno. It also runs pinned `ansible-lint` against the Node enrollment, role, development create, Route and task VM playbooks and checks the private Ansible input builder, the enrollment playbook contract and the waits of a nested fleet against a stub `incus`. The firewall regression test evaluates changed shapes, rule order and unchanged state through the pinned Ansible engine. The artifact tests cover candidate order, transport-only fallback, checksum rejection, cache reuse and staging cleanup.

Baseline counts cannot grow. Stale evidence labels can be corrected only to labels found in the immutable base source. The check against frozen base rules always scans committed source, including gate scripts. It accepts a repair only when the baseline changes evidence labels alone, counts stay equal, base and head have identical findings, and all errors refer to the repaired labels. Added calls, changed endpoints and debt hidden by checker edits still fail.

The Deno test for worker renewal runs the installed Ansible playbook against fake CLI, journal and systemd boundaries. It holds drain and enrollment barriers to check renewal order, survivor capacity, queued follow-on work, failed enrollment and actual bootstrap/remint worker names.

Ansible test doubles for commands that accept standard input consume the full input before exiting. The task VM host fixture checks a launch config larger than a pipe buffer to catch broken-pipe races.

Tests live beside the checked scripts in `swamp/bin/` and run one file at a time. Python helpers run as subprocesses with assertions in Deno. These regression tests use disposable fixtures and do not run the Swamp CLI.

Swamp checks validate every workflow with the runtime pinned in `swamp/.swamp.yaml` and enforce the Swamp-shape baseline. The downloaded runtime is cached under a key of the node-worker pin file, `swamp/extensions/behaviors/node-worker/config.ts`, so a pin change downloads the new runtime once. The validation job uses a disposable hosted runner, where the pinned PHP setup action can install PHP without changing a restricted self-hosted runner. It also runs that pinned binary's `serve check-config` against disposable grant files rendered by the Gateway's production `SwampGrantRenderer` through `apps/gateway/bin/render-swamp-grants` (PHP 8.5, no Laravel boot or deployment credentials) from every `swamp/config/*grants*.json` input.

After native validation resolves the pinned extensions, the job runs `swamp/tests/node_add_bootstrap_test.ts`. It checks the first-contact SSH options and the bootstrap key guard against the checked-in workflow.

The gate requires `passed`, `wouldStart` and the full grant-file count; a refusal or missing account fails Required checks. Changes to that renderer, its typed input helper or its CLI also select Swamp validation. This checks complete CEL conditions and regex safety without starting serve or changing live grants.

Swamp check selection follows their inputs. Shape policy changes select the shape gate; baseline and analyser inputs also select the Operation tests that read them. Ansible changes also select Operation tests that read the playbooks. Workflows, grants, extension sources and shared runtime pins select shape, native validation and Operation tests. Swamp prose and skills select Docs only; shared CI or unclassified tooling changes keep the broad check set. New application, package or top-level source roots fail planning until their checks are declared.

Inside the shape job, pinned tool and collection setup runs first. Independent Ansible lint, Python fixtures and Deno checker tests then share at most four command slots, with one Deno worker per command and separate temporary files. Ansible lint and the Deno fixtures receive the same pinned collection path, so a role include does not depend on collections installed in the worker's home. Baseline and ratchet checks stay serial, and every failure remains in the final result.


Unit tests run in one job with four Deno workers. Keeping one job avoids five separate waits for the shared runner pool.

Workflow validation runs only when the change selects its workflow, grant, extension or runtime inputs, and validates every workflow on the nightly schedule. It needs the Swamp account secrets. GitHub gives Dependabot pull requests no Actions secrets, so a dependency update outside `swamp/` does not wait on them.

Required checks target five minutes. Project checks are removed from CI for now; Nick decides which checks return. Their local commands remain available. Orbit keeps fast TypeScript unit tests and no native workflow suite or custom runtime harness. Workflow validation and pinned `serve check-config` remain required. No nightly workflow replaces removed checks.

Tests of bin scripts live beside their script. The shape job names the liveness and Node 209 source suites explicitly. It also runs every unit test in `swamp/tests` and in the automatic-deploy, Composer and JS maintenance and Project extension folders. Two files in `swamp/tests` stay out: `metrics_exporter_firewall_test.ts` until it passes, and `native_host_method_test.ts`, which needs systemd and passwordless sudo, so it runs on a host. Test fixtures serve unit tests; they do not start Swamp or pull extensions.

Each main SHA has its own concurrency group in both CI and the reusable Swamp workflow. Main runs complete independently. A shared group would replace an older pending run even with `cancel-in-progress: false`; that option protects only the running job. Pull requests share a group and cancel superseded runs.

Two checks guard the flow charts. Generated contracts requires a chart or a `docs/cli/flows-pending.txt` line for every kind. Docs build runs `bin/check-flows`. Changing `swamp/workflows/` also selects Docs, and changing a chart or `docs/cli/flows-pending.txt` also selects Generated contracts. So both checks see every change they depend on.

`Gateway release inputs` records whether the whole push includes Orbit inputs; both the Gateway and CLI publisher read that check instead of recomputing the last commit. The initial import ratchets against its retained Swamp source commit, recorded in `swamp/import-source.json`; once the comparison base contains `swamp/`, every run uses its actual Orbit base.

Swamp workflow validation uses the dedicated `orbit-monorepo-ci` collective token with only `extensions:read`. Validation keeps its read-only key and signed sign-in proof on pull requests and pushes to main. Shape and ordinary Operation test jobs receive no secret environment.

CI uses `pull_request_target` only in the label workflow `proof-outside.yml`, which checks out one script from the base branch and passes only a commit SHA to Orbit ([the proof CI](/reference/feature-flow#the-proof-ci)). The runtime and local extension tree remain pinned; validation, shape, placement and Operation tests are required, including when authentication is absent or refused. No personal key or initial-import validation exception is used.

Build jobs, including Swamp validation, shape and unit tests, start in parallel and check their input scope before installing toolchains or dependencies. They do not wait for build input selection. Each uses the same `bin/ci-build-plan` policy.

The three Swamp subjobs read their own selections; their aggregate remains the one Swamp dependency of `Required checks`. Known documentation, contract-generation and proof tools select only their consumers. The planner fixture job always runs. Scope detection and PHP syntax checks fetch only the event commit and its comparison base. The Swamp shape job keeps full history and resolves the initial import's retained comparison source before running both current and frozen rules. Gateway releases and desired fleet state use the pinned agent manifest in `apps/gateway/resources/agent/frozen-manifest.json`, independently of Gateway or agent source changes.

On agent source, Cargo or test changes, CI runs `cargo check --locked --all-targets` and `scripts/check-crates test --locked`, including composed extension crates, within a 15-minute job budget. On Pi source changes, CI runs its check, tests and build within an eight-minute job budget. Shared CI changes select both jobs too. Gateway releases and desired fleet state use the pinned agent manifest in `apps/gateway/resources/agent/frozen-manifest.json`, independently of Gateway or agent source changes.


CI wall time is measured from workflow creation to completion of `Required checks`, including queueing and the final gate.

Installed Gateway runtime and CLI build dependencies use lock-based caches. Composer install and its current lifecycle scripts always rerun, including contract generation and autoload discovery. Download caches restore across lockfile changes. Source, generated contracts and release binaries are never restored as dependency caches.

Rust CI caches the Cargo registry, Git dependencies and `apps/agent/target`. An exact manifest and lockfile key is preferred. After those inputs change, CI can restore the newest cache for the same operating system, architecture and Rust compiler; Cargo checks its fingerprints and rebuilds changed inputs. Locked checks and tests always run.

Run `bin/check-operation-kinds` to repeat the registry check locally. It uses the real Gateway container, loads every family declaration and resolves its PHP executor or Swamp completion and execution handlers plus its named transports. Any loading or resolution failure exits nonzero. CI supplies a public fixture encryption key and requires no Gateway database. Constructor dependencies defer resource reads until their first use. The check runs no Operation, reads no resource records and contacts no Node. Families register a kind only after the Gateway enforces its required contracts.

The proof CI is part of the one CI result; [the proof CI](/reference/feature-flow#the-proof-ci) describes it. The required check name stays exactly `Required checks`. The branch ruleset and automatic Gateway release resolver depend on it. Its dependencies include the selected Pi and Rust checks. The complete CI run must also finish successfully before the CLI release workflow publishes.

Every checkout uses the immutable event commit, `github.sha`. On a pull request, this is the event's merge commit. A new push cancels the older run of that pull request or handoff branch. Each main commit has its own concurrency group, so an earlier main run cannot queue or cancel a newer release candidate.

On `main`, Web uploads `web-dist-<full commit SHA>` for 14 days. The commit has its build as soon as `Required checks` passes. It includes hidden files and keeps `index.html` and `version.json` at its root. Automatic Gateway releases install this commit's build. Pull requests publish no web build.

### Action pins and token permissions

Actions use pinned commits. Build and check jobs have read-only tokens. The CLI release publisher alone has the content write and attestation permissions needed to publish signed releases. It publishes only CLI binaries, checksums and provenance. It downloads no agent delivery.

### One PHP build in CI

Generated API contracts, web types, documentation navigation, and context are built in CI and stay out of Git. The Gateway Composer install builds its runtime MCP catalogue before generated-contract and web checks.

Managed Linux Nodes, recipe artifacts, and PHP builds support amd64 only. CI builds no agent delivery. It reuses the frozen authenticated published delivery.

The shared PHP action installs PHP 8.5 and Composer 2.10.3 through the pinned PHP setup action. It does not compile the Rust agent or install the Node software recipe. Nodes and images continue to use the [machine software recipe](/reference/compute-drivers). Composer download caches stay separate from generated files and tool result caches.

### Gateway shards

Gateway test shards and the privileged, E2E, SDK, CLI, remote-program and web test jobs, including the native package boundary checks, do not run in CI. Their test files and local commands remain available. CI does not use the former project selection tool.

### Runner policy during the Swamp migration

During the Swamp migration, Orbit CI runs builds and static checks, plus the selected Pi and Rust tests. Gateway Pest and web unit tests stay out until the full pipeline runs end to end. Agents run the affected test suites locally before publishing a change and report their counts in the pull request. The test files remain the behavior specification.

Sabre and Shark run CI runners in unprivileged Incus containers. With Nick's approval, both hosts also run temporary Swamp migration workers. They may share capacity during the migration; restore their CI capacity when that work ends. Task VMs run only on Beast and Shark through Incus.

Public repositories use GitHub-hosted runners, with self-hosted runners available when configured. Private repositories use self-hosted runners.

The operator registers runners. The Orbit GitHub App keeps `administration: read`; runner registration adds no App permission or Gateway-held registration credential. Linux runner jobs run without host sudo inside their containers. Increase capacity when measured queueing requires it.

### Self-hosted shard runners

The managed setup preloads every pinned action from the repository’s workflows and local composite actions into `/opt/actionarchivecache`. It uses Git fetch and Git archive, so provisioning and jobs avoid codeload downloads for those pins. The runner uses GitHub’s standard `ACTIONS_RUNNER_ACTION_ARCHIVE_CACHE`, as in the [hosted image installer](https://github.com/actions/runner-images/blob/main/images/ubuntu/scripts/build/install-actions-cache.sh); no checkout replacement is needed.

Run `tools/ci-runners/setup.sh actions NAME` after action pins change, then restart that container’s runner service once it is idle. The command is idempotent, preserves other runner environment settings, and does not restart or register a runner. `container NAME` also preloads the cache. The cache is readable by the runner and owned by root; each archive uses its pinned commit as the key.

Every shard runner also runs `tools/ci-runners/job-started-hook.sh` before each job, through the runner's standard `ACTIONS_RUNNER_HOOK_JOB_STARTED` setting. The hook resets each reused git checkout under the work directory with `git sparse-checkout disable`, `git reset --hard` and `git clean -ffdx`. It empties a checkout that is in sparse mode (`core.sparseCheckout`, or skip-worktree entries in the index) or that git cannot reset: `reset --hard` leaves skip-worktree files on disk, and the next job's `git sparse-checkout disable` then fails on them.

A job that leaves tracked files modified therefore cannot fail the next `actions/checkout`. `container NAME` installs the hook; `tools/ci-runners/setup.sh hook NAME` installs it on a running runner without a restart. Restart the runner service once it is idle to load it. The host-level `orbit-proof` runners on Beast and Shark take the same hook with `tools/ci-runners/setup.sh proof_hook` on their host.

### Agent delivery

CI has no agent build, selection, combine or upload jobs. The command guard downloads the pinned binary only to verify its read-only command inventory; it publishes nothing. Gateway preparation verifies the pinned published manifest and writes that same pin into each prepared release. Desired fleet state reads that pin. No Gateway commit waits for its own agent or CLI release.

The CLI release workflow publishes each green main commit with its own CLI version, checksums and provenance. When the agent source commit (`apps/agent/scripts/source-commit`) differs from the previous release's, the same release also builds and carries the agent. A change that got no release of its own therefore ships in the next one. A release's tag appears only when it publishes, about 20 minutes after an agent build starts. Releases that start in that window do not see it yet and build the same agent again. The build is reproducible, so they carry the same bytes, and the pin names one of them.

Two read-only jobs build the Linux and macOS agents from the release commit with the pinned `dtolnay/rust-toolchain`, and the manifest names the agent source commit. The publish job combines them with `tools/combine_agent_delivery.py`, which refuses a mixed source or altered bytes, and `bin/orbit-cli-release-assets` adds each agent's release URL to `manifest.json`. A failed agent build stops the release. The [pin](/reference/node-agent#releases) moves only in a reviewed commit.

The Docs site workflow also follows each green main commit. It builds the docs and force-pushes the `docs-site` branch, which Mintlify deploys; see [Write the documentation](/contributor-guide#2-write-the-documentation).

## Local checks

### Gateway tests and privilege

Ordinary local Gateway cases need no sudo. Privileged tests remain in their existing group for deliberate runs on a disposable host. CI runs neither group. Keep the existing no-sudo and temporary-directory boundaries when using these tests locally.

In an Orbit task, run the tests that cover the change in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Use stock Pest with a test file path or `--filter`. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff, and CI runs builds and static checks on the handoff commit. Outside a task, run the full suite and the checks below. Each project keeps its own dependencies and test configuration.

| Command | Result |
| --- | --- |
| `vendor/bin/pest --compact PATH` or `--filter=NAME` | Runs the tests that cover a change |
| `composer check` | Runs PHPStan, Rector, and the Pint check. It does not run the full Pest suite. |
| `composer test` | Runs the project's full suite in parallel processes |
| `composer format` | Applies Pint's Laravel preset |
| `composer analyse` | Runs PHPStan. The applications use Larastan. |

Confirm that the tests of the feature ran. A filter that matches no test is not acceptance evidence.

Each project requires stock `pestphp/pest` from its lock file. Stock Pest includes test impact analysis, which turns on with `--tia` or `--locally`, a `PEST_TIA` environment variable, or a `tia()` configuration. Orbit uses none of them, and a contract test checks the project scripts, the PHPUnit configuration, the workflows, the CI actions, and the `bin` scripts. To adopt a new release, update the dependency and the lock file in every project, then run the project checks.

Root `bin/test` queues the five project suites through the same weighted lane allocator as the candidate gate. The total of concurrent Pest workers stays within the [available process budget](#parallel-processes), including when the budget is smaller than the number of suites. Each finished suite frees its share for the remaining suites.

The E2E tests for gate selection build one Git repository per file, with two commits. Each test gets an independent copy. Cases that need a different history change only their copy. The gate-selection fixtures make Pest fail if the local gate tries to run a test suite; handoff suites run in CI.

Gateway feature tests use Laravel's `LazilyRefreshDatabase`: the first query or transaction triggers the same real migrations and transaction setup. A test that inspects its ambient transaction level first issues a query to establish the test transaction. Guidance rendering does not use a database, so it pays no migration cost.

The Gateway schema baseline includes database private-endpoint ownership, target networks and connection locale. It records the squashed migration names and batches without choosing their row IDs, including the October 21 and 22 database migrations. Earlier vendor migrations can occupy IDs first. The migration that seeds reference rows preserves existing configuration; its rollback leaves those rows in place. Documentation `covers` names the baseline file, not the whole migrations directory. New migrations belong to the pages that own their behaviour.

The E2E checks for unusable caches and inherited databases run the Gateway's real renderer for strict guidance. They do not repeat its unrelated guidance tests. The inherited-database check also runs the real feature database test: its first query triggers migrations, and the caller's sentinel database must remain unchanged.

The worker-budget test keeps a locked count of active shares, including overlap between waves. It lets the first lane acquire its share before later lanes register, so later allocations must respect shares already held. Signals report when each active lane has either acquired a share or entered the allocator's real condition wait. Workers stay on sockets until all granted workers in that wave have connected; no observation sleep determines completion.

### Parallel processes

Pest, PHPStan, Rector, and Pint use one process rule in `bin/check-processes`. At check start it reserves 640 MiB for gate overhead, then divides the remaining available memory by the measured allocation of 1664 MiB per worker, takes the smaller of that count and the available CPU count, and allows at least one worker.

On Linux it reads CPU affinity and cgroup v2 CPU quotas. Available memory is the smaller of `/proc/meminfo`'s `MemAvailable` and each bounded cgroup's limit minus its working set, including ancestor bounds. The working set is `memory.current` minus `memory.stat`'s reclaimable `inactive_file` pages. On macOS it reads the CPU count and the free, inactive, and speculative pages from `vm_stat`. Unreadable resources fail the check.

The floor of one can overcommit memory and OOM when less than the reserve plus one worker is available. The rule assumes one gate per task VM and does not reserve resources between concurrent gates on a shared host. CPU is schedulable capacity, not idle capacity: topology processes share it and can slow the gate.

| Tool | How it reads the process count |
| --- | --- |
| Pest | `--processes=$(../../bin/check-processes)` in the `test` script |
| Pint | `--parallel --max-processes=$(../../bin/check-processes)` in the `format` script |
| PHPStan | `tools/phpstan/parallel.php`, which every `phpstan.neon` includes |
| Rector | `->withParallel()` in `rector.php`, with the value from `tools/check-processes.php` |

Rector keeps its cache in `vendor/rector/cache` of each project.

Gateway's `RunScheduledCommand` keeps an explicit default for its command arguments so jobs queued before that property existed can still deserialize. Its Rector configuration excludes that class from constructor promotion, which would lose the property default on those payloads. A queue regression test covers this deployment boundary.

The PHP helper `tools/check-processes.php` invokes `bin/check-processes`; it contains no allocation rule. The candidate gate and `bin/test` use `tools/check_budget.py` to split the resource-derived budget between their lanes and suites with the internal `ORBIT_CHECK_PROCESSES` variable. A positive integer lane share is used verbatim, and an invalid share fails the check. Do not set it on a Project or in an operator shell. CI test commands bypass this local gate budget. Stock ParaTest detects all runner CPUs, as [Gateway shards](#gateway-shards) describes. CI quality tools still use this helper.

### The candidate gate

Root `composer check` builds OpenAPI, navigation, flow data and the documentation context index, then runs `bin/review-check`, the candidate gate. This refreshes ignored outputs from the current checkout before the impact and freshness checks read them.

The Orbit Project uses it as its task check, so it runs in the task VM at every subtask handoff. CI's Project check job runs the same gate on every commit, so a green `main` is green for tasks too. The gate runs only fast checks and no test suite. The full suite of each handoff runs in CI on the exact handoff commit, as [Tasks: Handoff CI](/reference/tasks#handoff-ci) describes. A merge still needs the full suite on the final head.

The gate checks the working tree as it is, including uncommitted and untracked files. It runs these checks.

API ownership matching removes the shared `api/v1` prefix, so a Form Request used by a grouped route maps to its versioned OpenAPI operation. The command map of `bin/docs-impact` names the commands that exist; it has no entry for `orbit:gateway-web`. It has no entry for `task:lease:release` either: the command left with the workspace lease.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. The fallback is the merge base with local `main`. Without a merge base, this check fails.
2. It checks the five Composer projects, as the next paragraph describes. [Projects run at the same time](#projects-run-at-the-same-time) explains how steps 2 to 5 share the machine.
3. When the candidate changes `apps/web`, `docs/openapi.json`, or `apps/pi-server`, it adds the matching checks, as [Web and Pi server checks](#web-and-pi-server-checks) describes.
4. It checks generated API artifacts when their inputs change, as [Generated artifacts](#generated-artifacts) describes.
5. When the candidate changes the Rust agent or the desktop app, it runs `cargo fmt --all -- --check` in the matching Rust project.

Test inputs need no page: step 1 ignores files under `tests/` and `swamp/proof/`, and test files beside their source (`*_test.ts`, `*_test.py`, `test_*.py`). A page's `covers:` glob over a source folder does not claim the test files in it. Nor does a change to the machine recipe that only bumps pinned values. A deleted CLI command needs no page either: step 1 does not ask for the page of a command whose source file is gone.

When the candidate selects the Swamp checks by the rule of `bin/ci-build-plan`, the gate runs `bin/swamp-shape-gate` as its own group: the shape steps against the merge base, then the workflow validation of CI. [Run the Swamp shape gate before you push](#run-the-swamp-shape-gate-before-you-push) describes the script. Without a Swamp credential, the gate reports the validation as skipped, not failed, because CI validates.

#### Run the Swamp shape gate before you push

The `Swamp shape gate` and `Swamp workflow validate` jobs of CI call one script, `bin/swamp-shape-gate`. The candidate gate calls it too, so a local run checks what CI checks. Commit your change first: the checkers compare committed `HEAD` with the base.

```bash
bin/swamp-shape-gate                # shape and validation, against the merge base with origin/main
bin/swamp-shape-gate --base REF     # compare with another base
bin/swamp-shape-gate --only shape   # or --only validate
```

The script installs the linters into a virtual environment under `~/.cache/orbit-shape-gate`, keyed by their pins, and reuses it. `swamp/ansible/shape-gate-requirements.txt` is the only place that names the pins; the workflow names none. It sets umask 022, as CI has, then runs the checks in this order: the Ansible phase checks, the shape regression Deno tests, `check-swamp-shape.ts`, `check-swamp-base-policy.py`, `lint-behavior-names.ts` and the frozen agent command check. It runs every step, prints a summary, and exits 1 when any step failed. It retries nothing. A run takes about one minute once the environment exists. The JSON reports go to `--reports DIR`, by default a per-user directory in the temporary directory.

A task VM holds the same environment in `/opt/orbit-shape-gate`. The task VM role playbook builds it for the pins of its release. The script uses it when its name matches the pins, so the check runs offline.

Workflow validation needs a Swamp account and reads only `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`. Without them, the script skips the workflow validation, the Node enrollment trust test (it imports the extensions that validation pulls) and the grant pattern check (it also needs PHP). It names each skipped step and still exits 0. `--only validate` exits 78 then, which the candidate gate reads as skipped. CI passes `--strict`: a skipped step is a failure there.

CI gives the credentials to this one step on pull requests, on main pushes, on the nightly schedule and on the push of a `task-*-handoff` branch by the Orbit App (`orbit-nckrtl[bot]`), so a task handoff that changes `swamp/` can pass hosted CI. It never gives them on `pull_request_target` or to a fork. Decided by the orchestrator, for Nick's review: the push of a handoff branch by the App.

For each Composer project, the gate runs `composer check`. CI runs `composer validate --strict`. Its `composer check` runs Rector and Pint in fix mode, as [Formatters run in fix mode](#formatters-run-in-fix-mode) describes.

#### Projects run at the same time

The gate groups its project checks by project and runs the groups at the same time. Each group runs its own commands in order. `bin/check-processes` supplies the budget of the whole gate, and the tools of all groups together stay within it. The gate shares the budget in three ways:

1. It runs one more group than half the budget at once. So the largest group can keep at least half of the budget.
2. It gives each command a share by the size of its group's projects, counted in test files. The share compares the group with the groups that still run.
3. It holds back a command until a process is free. Each command frees its processes at its end.

The largest group starts first. Its later commands get the processes that finished groups freed.

A budget of 2 or less runs one group at a time. The budget counts workers, not each tool's main process.

With a budget of 4, the Gateway's checks run with two processes while two other groups run with one each. A budget of 1 checks one project at a time. The gate prints each result when the command ends. The receipt lists the checks in the gate's own order.

The web checks and the repository checks run in the Gateway's group, after the Gateway's checks. The contract checks boot the Gateway application. The other groups share no files that a check writes.

#### Formatters run in fix mode

Each project's `composer check` runs Rector and Pint in fix mode first, then PHPStan on the fixed code. All three check every file in their paths; their caches keep a repeat run fast. A formatter edit becomes part of the candidate: the receipt names the tree the checks leave, and the gate prints `Formatters changed files; commit them with your change.`

The gate fails when HEAD moves during the run. It also fails on formatter drift: when the check starts on a clean commit and the formatters change files, the commit is not formatter-clean, and the gate prints `Formatters changed files of a clean commit: the commit is not formatter-clean. Run composer check and commit the result.` The receipt names it as `drift`. CI checks committed trees, so main stays formatter-clean, and `composer check` in a task rewrites only the files the task touched.

#### Generated artifacts

When `bin/ci-build-plan` selects CI's Generated contracts job, the gate runs the same checks: `bin/check-operation-kinds`, then `bin/docs-openapi` and `bin/mcp-tools`, then `bin/api-fixtures --check`. The generators write ignored build outputs and fail when the sources disagree with the API contract. The fixture check validates the SDK and web fixtures against the fresh OpenAPI document.

The gate checks the local candidate tree. CI runs the builds and static checks listed above on the PR merge tree. Test suites remain available for focused local verification.

#### Web and Pi server checks

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The build also requires nonempty `dist/index.html` and `dist/version.json`, as CI does. The generated-types check runs `openapi-typescript` from the web app’s `tools/api-types` workspace and writes its output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. On failure it prints a unified diff and says to run `cd apps/web && bun run types`. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, and `bun run build` there. Its test files remain available locally. Rust formatting runs for changed Rust projects; the remaining Rust checks run only in CI.

A command whose program is missing fails with `<program>: required tool not found`. The generated-types check runs in a shell, so a tool that is missing there fails with exit code 127. The gate never skips a selected check.

#### Finding checks

Two checks reject review findings that code can detect. `composer check` runs them in every PHP project through the shared rules in `tools/phpstan/`.

| Check | Rejects |
| --- | --- |
| `phpstan-disallowed-calls` | A call to `strtotime()`. Use Carbon parsing. |
| `orbit.inlineVarOverride` | An inline `@var`, `@phpstan-var`, or `@psalm-var` inside a method body. Fix the type at its source. |

Each failure names the file and line.

#### Receipt

The gate writes a receipt, `result.json`, and one log per command in a new `review-*` directory under `orbit-checks/<HEAD>/` in the Git common directory. Each command also prints its duration in seconds, so stored task output shows slow steps without opening the receipt. The receipt passes only when at least one command ran, every command passed, and the commit and the working tree did not change during the run.

Before it writes, the gate removes the `review-*` directories its own Unix user wrote more than a day ago, and `<HEAD>` directories that stayed empty for a day. It cannot remove another user's reports; each user cleans up its own on its next run.

### Gateway test databases

The Gateway test bootstrap keeps tests away from real databases. Before Laravel loads its configuration, it sets `APP_ENV=testing`, the SQLite driver, and the test database in the process environment and in the PHP environment and server variables. After Laravel loads its configuration, a guard checks the effective connection, including a connection URL and cached configuration.

| Input | Test behavior |
| --- | --- |
| No `ORBIT_TEST_DATABASE` | In-memory SQLite |
| Inherited `DB_DATABASE`, `DB_URL`, or `DB_CONNECTION` | The test value replaces it |
| `ORBIT_TEST_DATABASE` set to an absolute path of an `orbit-gateway-test-*` file in the system temporary directory | That disposable SQLite file |
| `ORBIT_TEST_DATABASE` set to any other path, or to a symbolic link | The test bootstrap stops with a message that names the rule |
| Any other driver, database, or application environment | The run stops before migrations with a database safety refusal |

Allocate a new temporary file for each run that needs a file-backed database. Never point `ORBIT_TEST_DATABASE` at a Gateway runtime database, another application's database, or a backup.

An unexpected refusal usually means that Laravel loaded a stale cached configuration. In `apps/gateway`, run `unset APP_CONFIG_CACHE` and `php artisan config:clear`, then run the same command again. Do not disable the guard.

## Worktrees

`bin/worktree-create ISSUE` creates a linked worktree for a local feature. For `ORB-217`, it uses branch `orb-217` and the directory `/fast/worktrees/orbit/orb-217`. It fetches `origin` first and does not change the primary checkout. Set another absolute base directory with `git config orbit.worktreeRoot PATH`. The base must be outside the primary checkout.

The command reuses what already exists.

| State | Result |
| --- | --- |
| The branch has a clean registered worktree | The command reuses that worktree |
| That worktree is dirty or on another branch | The command refuses |
| Only the local branch exists | The command adds a worktree for it |
| Only `origin/orb-217` exists | The command creates a local branch that tracks it |
| No branch exists | The command creates the branch from `origin/main` |

It refuses a path that exists but is not a registered worktree. Then it runs `bin/bootstrap` in the worktree.

`bin/bootstrap` installs all five Composer projects. Then it runs `composer test`, the project's full suite, and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the installs.

`bin/bootstrap --dependencies-only` installs dependencies, including any requested Bun and Rust dependencies, and the [pre-push docs gate](/contributor-guide). It prepares no application state and runs no checks.

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It removes the worktree. Then it deletes the merged branch. It releases no topology: a topology runs only in a task VM and goes with it.

## Main caches

Main caches let a new checkout start with warm Pint and PHPStan result caches from `main`. Task VM workspaces import them from [CI cache refs](#ci-cache-refs). A local checkout starts cold.

### CI cache refs

The CI workflow publishes no new quality-cache artifacts or refs. Existing `refs/orbit/ci-cache/<sha>` snapshots remain readable by task workspaces. These parentless Git commits contain cache payloads, not source history; the suffix names the code commit they came from.

Each retained project snapshot has a schema-3 manifest and optional Pint and PHPStan caches. The manifest records the code SHA, project, CI run and attempt, each cache checksum, each configuration checksum and the PHP minor version. Consumers check these facts before importing; a ref name alone is not evidence that a payload fits. PHPStan caches contain executable PHP.

A new task VM workspace starts at the `origin/<default branch>` tip. For a Project that declares `@orbit/ci-caches`, the Gateway independently selects the newest commit on that tip's first-parent history with a published `refs/orbit/ci-cache/<sha>` ref, records it in `refs/worktree/orbit/ci-baseline`, and imports its caches. It logs the baseline SHA and its distance from the tip, including zero for a cached tip.

If no ancestor has a cache ref, the workspace starts cold with no recorded cache baseline. If the optional ref fetch fails, the Gateway discards the mirror's cache refs and logs the fetch failure as the cause of the cold start. Neither case fails the workspace claim. It does not wait or poll for publication, or try another baseline if the selected cache is invalid. A baseline retry moves the untouched workspace to the current default branch tip and selects its cache baseline by the same rule.

Existing task branches, seeded worktrees, and incoming pull requests keep their selected source commit. The ref for the remote default branch still tracks its latest head. The PR must pass `Required checks` before merging.

The Gateway fetches the `refs/orbit/ci-cache/*` namespace into its mirror alongside source refs. Cache fetching is optional: a cache-ref miss must not fail the required source fetch. The Gateway sends only the ref for the selected code baseline to a workspace over SSH. [Instance setup](/reference/instance-setup#import-ci-main-caches) owns the compatibility checks and import. A missing or incompatible payload leaves checks cold at the selected code commit. A cache ref for another SHA, even a newer one, is never substituted. The workspace imports Git objects, not an Actions download or a base64 command payload.

### Cache ref retention

The retained `bin/ci-cache-ref publish` command prunes `refs/orbit/ci-cache/*` to one ref: the ref of the newest tested main commit. Main commit ancestry, not job completion order, identifies that commit. So origin holds only the newest publication, and a workspace never selects an older cache baseline. Pruning never touches source branches, tags, or other refs.

The Gateway removes upstream-deleted refs only from its mirror's cache namespace. It does not delete an active workspace's local cache objects or private tool caches. A workspace whose baseline ref was pruned starts cold. Git garbage collection can reclaim unreferenced cache objects later.

### CI artifacts

The CI workflow uploads no `ci-cache-<index>-<commit>` artifacts. Older runs retained these staging exports for 14 days. They are not a workspace transport: the Gateway reads Git refs for `@orbit/ci-caches`, without downloading artifacts or putting their contents in a setup command.

| File | Content |
| --- | --- |
| `manifest.json` | Schema version 3, project path, tested commit, CI run and exporting attempt, PHP minor version, and the checksum and configuration checksum of each quality cache |
| `quality/pint`, `quality/phpstan` | The Pint and PHPStan result caches that `composer check` wrote in the same job, when it wrote them |

### Seed a checkout

A task VM workspace [imports a CI cache ref](/reference/instance-setup#import-ci-main-caches) instead. That import also checks the runtime facts that each tool compares. A Project setup step can reflink each dependency tree and private tool cache from `ORBIT_SEED_PATH` first.

### Failures on main

A failure on main is a `Required checks` check run on a `main` commit that did not succeed. Read it for the current tip with `gh api 'repos/hardimpactdev/orbit/commits/main/check-runs?check_name=Required%20checks'`. Diagnose it on the failed commit. Fix it, or revert the change that caused it, through a reviewed pull request. The next `main` commit whose `Required checks` passes clears it.

## Why it works this way

### CI runs the task gate

CI and the task check once disagreed on green: CI skipped the e2e guidance suite and the fixture check, so `main` went red for tasks while CI stayed green. The Project check job removes that gap: it runs the same `bin/review-check`. Its three shards split the gate's lanes by weight with `--shard I/N`, and shard 1 also runs the repository checks. `--base` compares with the event's base: a pull request's base, or a main push's previous head. Keeping two lists of checks in step is a rejected alternative, because they drift.

### Every project passes the gate at each handoff

The gate checks all five Composer projects whatever the candidate changes. Checking the changed project alone is a rejected alternative, because the other projects would then have no quality check before review. The gate runs at each handoff, before review. Running it only in the independent review is also rejected, because each deterministic failure would then cost a reviewer turn and a correction round before it reached the implementer.

### Web and Pi server checks join the gate, Rust stays in CI

A change to the web app or the Pi server could pass the gate and then fail a required CI job. So the gate runs the same fast checks as those CI jobs when their paths change. Their test files remain available locally. Running every CI job for every subtask is a rejected alternative, because path selection keeps unrelated work fast. Rust cross-compilation is too slow for each handoff, so the Rust agent stays a CI check.

### A missing tool fails the gate

A green gate must mean that every selected check ran. Skipping a check when its tool is absent would pass a workspace that happens to lack `bun` or `git`.

### Repeated findings become checks

Reviewers report `strtotime()`, inline type overrides, and unguarded classification fakes again and again. Each one is deterministic, so a check rejects it before review. Asking reviewers to remember them is a rejected alternative, because it spends a review round on a finding that code can detect.

### Only the newest waiting main commit runs

Pushes to `main` share one concurrency group. A running run always finishes, and a newer push replaces the waiting one. An automatic Gateway release ships only a commit with its own successful `Required checks` run, so a replaced commit never ships by itself. It ships inside the next green commit, which descends from it and whose builds and static checks cover its tree.

A group per `main` commit is a rejected alternative. It gave every commit a result, but during a burst of merges every commit queued a full run on the runners on Sabre, and the newest commit, the one a release ships, ran last. On 2026-10-08 about a dozen merges in 15 minutes delayed the release by 30 to 40 minutes.

The cost is a smaller fallback when the newest run fails. The release resolver examines at most 20 commits, and a replaced commit has no result to fall back to. After a burst of more than 20 merges whose newest run fails, auto-release waits for a fix to merge and pass. A manual re-run of an older `main` run also joins the shared group, so it replaces a waiting run.

### Main caches come only from clean main

A feature checkout can hold unmerged code or working edits, so its tool caches cannot stand for main. The CI workflow publishes no new quality-cache refs. Each checkout writes to its own private copy. One writable cache shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results.

### Only main CI publishes

The CI workflow produces no quality caches. A deployment never runs a test suite.

Running the checks on a development machine to fill a cache is a rejected alternative. It repeated the work of CI, competed with other agents for the processors, and inside a deployment it exceeded the deployment's deadline. Its results also depended on the host: a test that passes in CI failed on a host with other file ACLs, and that failure held merges as a correctness failure on main. Publishing the caches of a clean bootstrap on `main` is rejected for the same reason, and because two producers would race.

### Main runs record full suites

Nick replaced full-suite CI with builds and static checks on 2026-10-28. Keeping test files preserves the intended behavior as a local reference while command families move to Swamp in parallel.

### Full suites gate merges

The check name remains `Required checks` so branch protection, task handoff CI and Gateway auto-release continue to resolve it. The name now represents the builds and static checks in [CI](#ci). A green result does not claim that any test suite ran. No ruleset change is needed for Orbit.

### Gateway test time limits

Gateway uses stock PHPUnit per-test limits: `enforceTimeLimit="true"`, `defaultTimeLimit="2"`, `failOnRisky="true"`, and `stopOnRisky="true"` in `apps/gateway/phpunit.xml`. The default is two seconds locally. Tests remain unsized except for reviewed cases whose real deadline or kernel age is the behaviour under test.

CI invokes no Pest tests. The retained local test tooling supports stock per-test limits.

An exception uses a dedicated native PHPUnit class with class-level `#[Large]` and a one-line justification beside the attribute. Each case needs review; the class contains only the approved real-deadline cases, never unrelated tests. The approved classes are `DocumentTransportDeadlineTest`, with the native Guzzle connection and total-request deadlines, `LinuxHostCtimeTest`, with real kernel-ctime aging through GNU `find`, and `TaskCheckDeadlineTest`, with the retained Tasks checker’s real setup deadlines, TERM grace, and output-drain bound. They use stock PHPUnit `timeoutForLargeTests`, without an override. Installed PHPUnit supports sizes only on classes, and installed Pest cannot declare sizes. Do not substitute size-named groups or add custom timeout or sizing machinery.

Fix slow cases at their cause: control clocks and transports, share expensive read-only setup, and keep a real subprocess or deadline only when it is the proof. Preserve every behaviour assertion. The WebSocket pong-write deadline proof keeps the real TLS connection and zero-byte writes. Its controlled application clock checks the deadline boundary and expiry without waiting five seconds. Finite local fixture commands may use `Tests\Support\FixtureProcessRunner`, which waits on pipes instead of polling for production cancellation. It rejects streaming and cancellation callbacks; those proofs use the native runner.

Native PHPUnit Feature tests extend `Tests\FeatureTestCase` with the same database and cache traits as Pest's Feature cases. Both use `FeatureTestEnvironment` for storage and process isolation, transport guards, and cleanup. Extension-specific setup remains with its tests.

Read-only fixtures are prepared once per file or worker, then copied into private writable directories. Gateway release fixtures share the initial Git origin and adopted release; each case still owns its working repositories, database, runtime and cleanup. Adoption tests fake the agent receipts and keep the two-phase workflow, recovery records, and retries in PHP. Rust tests own adoption’s filesystem safety and atomic exchanges. Cleanup restores directory access in the PHP process before descending, without following symlinks or changing regular-file modes, then removes the private tree. It does not start a `chmod` process for every directory.

The fixture that snapshots a live WAL shares only its initial database, not the writer, snapshot, transfer or import. It keeps all 200 rows and the 64 MiB BLOB. The real writer runs without a delay.

Registration tests in the Gateway fake agent receipts and verify source facts, relocation checkpoints, lost-response recovery, and error mapping. Rust tests own checkout safety, cross-filesystem copies, worktree repair, and Laravel rollback files. Each filesystem recovery checkpoint runs against real private repositories, including a separate filesystem for copy recovery.

Development source tests keep branch selection and removal recovery decisions in PHP with fake agent responses.

The Documents scheduler proves startup and recurring cleanup, explicit pause/resume, dead-worker fencing, and daemon restart in separate cases. Each case owns its database, HTTP server and workers, with real `schedule:work` dispatch and `schedule:run` children. A restart case may prepare earlier deletions through the real cleanup action; the old worker's child dispatch has its own proof.

The Rust Caddy ACL race test covers five real walks, each with a private checkout. It releases each walk at the find/setfacl boundaries and checks removals, replacements, late-file inheritance, and source denial as Caddy. The Gateway tests select the sites and fake the command. See [Rust checks](/reference/bun-and-rust-projects#node-agent) for the privileged test command.

Valkey PHP fixtures copy an immutable ACL seed at first boot, before executing the production image's entrypoint and server command. They fake the Node writer by copying its requested ACL into the private container. Restarts never restore the seed, so repairing a missing file still exercises Gateway intent and the real Predis protocol. Native Rust tests own exact file contents, private permissions, replacement, and refusal before mutation. Fixtures acknowledge each boot directly from the attached Docker process and do not persist unrelated Docker logs. Mutable servers and volumes remain private to each case.

Workspace TMPDIR proofs acknowledge readiness and hold checks open through private FIFOs and shell builtins, not marker polling or `sleep` children. Cancellation still signals the real check process group. The fixture does not add a disposable sleeper whose unreaped process can hold that group open during cancellation. Setup, checks and deliverables still run in two independent workspaces with real permissions and ACLs. Each phase records its TMPDIR. Capture the final permission evidence once per workspace rather than overwriting it in each phase.

Copy the initial committed Git repository into each private checkout. The analysis stand-in uses shell builtins for its temporary-file text writes and reads. Status and snapshot probes have separate private cases, each retaining the TMPDIR identity, mode and child ACL checks before real cancellation.

A scheduler proof with fixed collaborators reuses its real scheduler graph within one case, including successive handoffs, CI-state reads and failure-budget ticks. Resolve a fresh graph after rebinding a collaborator. The fixture shares the immutable subtask runner only while building one graph and preserves caller-owned bindings. It does not cache the scheduler across cases. Freeze the clock before starting a handoff age-boundary proof so elapsed test execution cannot cross the boundary before an explicit time advance.

Scheduler signer doubles accept the optional commit receipt even though native tick does not use it. Prove receipt recovery separately with real Git and isolated repositories.

Task VM source fixtures use the same local executor for finite Bash programs. Each case owns its repositories, cache publications and imports. Proofs of removal, cancellation and streamed output still run the actual processes.

Production deployment fixtures copy committed read-only Git seeds into private writable repositories instead of rebuilding the same history for each retained release. Each case still runs the real release programs, ACL changes and filesystem checks. Buffer finite Bash programs before execution so the shell does not read each source line from a process pipe.

Source the production deployment fake `sudo` fixture in a Bash function instead of starting `env` and another Bash for every command. The fixture only shifts its own arguments and invokes the command, so Bash builtins such as `test` run without another process. Real Git, filesystem and ACL programs still run, and the function preserves command arguments and exit status. Other scripted command fixtures keep their isolated subshell when they change variables or shell options.

The private-SSH-home permission proof keeps real `sudo` and the Caddy identity. A busy release worker acknowledges its working directory through its output pipe and waits on shell input; readiness does not poll `/proc` or depend on a sleeper.

Private DNS publication fixtures source their scripted service, socket, sleep and PID-matching commands as shell functions. The PID matcher consumes the scripted socket output without starting `grep` on each retry; it handles the numeric PID query used by the publication program and delegates other queries, including release-retention file checks, to real `grep`. The restore proof still performs and checks all 100 failed socket probes and the real fragment rollback.

The shared Git cleanup regression runs stock Pest against a native fixture with real frozen release seeds and the class-cleanup trait. It does not boot a nested Laravel application merely to test class cleanup before the run directory is removed. The parent waits through PHP's `exec`, with captured output and an asserted exit code. It does not leave a Symfony pipe error handler installed while waiting for the child.

Python deadline fixtures block until the timeout kills them; they do not race a short sleep against a nearby deadline. A following immediate command proves that the shared host remains usable.

Fixtures for Python programs that use only the standard library can use `StandardLibraryPython` to omit host site-package startup. Arguments, binary stdin, environment, exit status and execution in a fresh process remain real. `LocalShellSshExecutor` buffers finite `bash -seu` stdin programs in private temporary files; other command arguments remain data. Protected inputs keep their caller-owned lifetime.

Selected authentication, SSR and repeated-check cases can inspect actual local check artifacts after the real check exits. Dedicated status and output transport cases still use the remote reader. Do not use local artifact helpers for streaming or cancellation proofs.

Timeouts fail the run. `stopOnRisky` prevents later cases in the same worker from consuming state left by interrupted cleanup; it does not guarantee cleanup completes. The Gateway PHP recipe requires `pcntl`, and the shared CI PHP action verifies the recipe before running suites. Keep that requirement in the recipe, not individual jobs.

Give datasets containing invalid UTF-8 readable names. This keeps JUnit test names valid without changing the input bytes that the test validates.

Process fixtures acknowledge readiness from their final executable before a case acts on them; a PID alone does not prove startup finished. Tests of leftover cleanup after `SIGKILL` first observe that the check is lost, then cancel it. Sending a signal does not mean the process has exited.

### CI caches stay separate

Hosted CI keeps its caches in GitHub Actions cache, keyed by branch and inputs. Each run starts from a fresh runner. On `main` it uploads the quality exports that the [CI cache refs](#ci-cache-refs) carry. Pint and PHPStan key their caches on paths relative to the project, so their CI caches work in any checkout.

### Style is fixed, not reported

A formatter finding has one correct answer, so the gate applies it instead of reporting it. Agents never spend a turn on style, and no CI job checks style. Rector and Pint keep their default caches in each project's `vendor/`. On Beast, a cold Gateway Rector run takes about 12 seconds and a warm one about 1.4 seconds.

CI builds the pull request merge result, checks generated contracts and validates changed PHP syntax. Its `Required checks` result decides the merge. The gate gives fast feedback at each handoff.

All four tools run in parallel processes. The old limits of two PHPStan and Pest processes had no recorded reason. Rector ran in one process. With 16 processes on a 16-core machine, a full Rector run in the Gateway takes about 32 seconds with the same findings, and a cold Pint run about 37 seconds.

Memory and CPU quotas bound the process count. The gate shares the resource-derived budget between the projects it runs at once. The Gateway is the largest project, so it gets the largest share. Each lane passes its share to every tool through `ORBIT_CHECK_PROCESSES`.

A complete normal-VM gate on Beast on 2026-10-19 UTC exercised the Gateway workers. The gate still ran each project's selected tests then. Pi 0.87.1 stayed resident in idle RPC mode, with no model calls. The real diff against `353ef196c` selected all 847 Gateway test files from 104 changed files. The Gateway tests passed 13,954 tests with 21 skips and 108,537 assertions in 2614.290 seconds. Its heaviest worker reached 1506.1 MiB `VmHWM`. The final allocation is 1664 MiB, about 10.5% above that measured peak.

| VM and selection | Workers | Gate peak summed RSS | Gate kernel memory peak | Minimum VM available memory | Gate controller peak PSS | Gate seconds |
| --- | --- | --- | --- | --- | --- | --- |
| Normal: 2 CPUs, 4 GiB, resident Pi, Gateway and E2E tests | 1 | 3.864 GiB | 2.094 GiB | 1.185 GiB | 538.0 MiB | 3160.337 |

That root `composer check` passed with zero OOM events. The nominal 200 ms sampler's longest interval was 1.214 seconds. Forked test children share pages, so summed RSS counts those mappings repeatedly; the continuous kernel peak records the gate's charged memory. The measured gate used one worker. The final rule also selects one worker from its recorded 3.054 GiB available at start, so its lane shares are unchanged.

The final 640 MiB reserve gives about 19% headroom above the measured 538.0 MiB controller PSS. These counts use the final rule at the recorded starting memory:

| VM | Topology | Available memory | Workers |
| --- | --- | --- | --- |
| Normal: 2 CPUs, 4 GiB, resident Pi | None supported | 3.054 GiB | 1 |
| Medium: 4 CPUs, 8 GiB, resident Pi | None | 7.056 GiB | 3 |
| Medium: 4 CPUs, 8 GiB | Gateway, operator, app-dev | 2.835 GiB | 1 |
| Large: 6 CPUs, 12 GiB | Gateway, operator, app-dev, app-prod | 5.722 GiB | 3 |

Size alone does not determine the count. The topology rows replay the final allocation over captured resources; they do not measure another complete gate under that allocation. Four workers need at least 7.125 GiB available.

### Findings are fixed, not silenced

A PHPStan ignore, a baseline, or a silencing cast keeps the wrong type in the code, and new code can repeat it. Fixing the value at its source lets CI catch these errors before review, so a reviewer spends the review on behavior. The [contributor guide](/contributor-guide#static-analysis) lists the rules.

### Shared-worker feedback

The task check runs no Gateway tests, so it needs neither host sudo nor another Unix identity. Local disposable Gateway privileged checks can run the `privileged` group; see [Gateway tests and privilege](#gateway-tests-and-privilege) for the rule. Check report directories permit group reads so the worker can inspect failures.

The shared installer inspects runner-writable JavaScript executables as `runner`, including the setup smoke check, which runs from `/` and compares the result with the recipe pin. Inspection passes `VP_HOME` after sudo in argv so sudo environment filtering cannot create another Vite+ store. All Node-consuming CI jobs, including the dependency audit, read `.node-version`.

Native Vault CLI fixtures serialize each read and write of their shared backend state. A failed command must preserve another command's completed writes and deletes.
