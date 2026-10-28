---
title: "Feature delivery"
description: "How a change reaches main: review evidence, merge, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/{AdrLifecycle,DocsImpact}.php
  - bin/{review-check,project-vocabulary,bug-repro,task-group-check,pr-head-check,deploy-verify,test,ci-cache-ref,check-pest-shards,worktree-cache,worktree-create,worktree-remove,check-classification-fakes,check-processes,quality-scope,php-build}
  - tools/{check-processes,rector-cache}.php
  - tools/check_budget.py
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/{setup.sh,javascript.py},agent_command.py}
  - .github/{workflows/*.yml,actions/{php,no-sudo}/action.yml}
  - apps/gateway/{phpunit.xml,database/migrations/2026_12_04_000000_seed_schema_baseline_reference_rows.php,tests/Support/TestDatabaseEnvironment.php,tests/Support/TestDatabaseGuard.php,tests/Support/PythonPrograms.php,tests/Support/FixtureProcessRunner.php,tests/Support/GatewayReleaseFixture.php,tests/Support/TaskSchedulerTickFixtures.php,tests/Feature/Domain/TaskWorkspaceTmpdirTest.php,tests/Feature/Domain/ScheduleValidationTest.php,tests/Feature/Infrastructure/Instances/DevelopmentSourceRemovalCommandTest.php}
---

# Feature delivery

A feature reaches main as one complete pull request with its code, tests, documentation, and any in-progress ADRs. The [contributor guide](/contributor-guide) describes the steps. Orbit builds its own features as [tasks](/reference/tasks): the first subtask writes the documentation, each subtask handoff runs the Project task check, and the task ends in a pull request. This page covers review evidence, merge, CI, the local checks, and the main caches that make those checks fast.

The docs-first amendment for [fixed repository analysis](/reference/tasks#analyze-an-external-groups-repository) supplies complete candidate-bound evidence to existing turns; it is not another baseline or handoff lane. Its native implementation follows independent docs review. Run Orbit's existing checker with the complete cumulative plan, preserve all diagnostics and check-mode generator outcomes, and keep the docs gate that checks actual files at handoff.

Edits invalidate the author evidence; analyze the current checked candidate for review rather than rerunning baseline. Unknown execution or descendants stay fenced until terminal proof, and a lost reply never authorizes a duplicate run or send. Record measured duration and zero mechanical LLM calls without claiming savings or live interruption proof from documentation checks.

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

## Merge and cleanup

Address the blocking findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. The final review must cover the commit proposed for merge.

A merge needs a complete feature, passing CI, a successful independent code and Incus review, resolved blocking findings, and maintainer approval. Merge the approved commit and verify the result on GitHub. A known correctness failure on main holds unrelated merges until a reviewed fix is verified on main.

### Final review of an Orbit task pull request

A Project with [review and merge](/reference/tasks#review-and-merge) on does not use this workflow. Orbit's final review, merge check, and App merge replace it. The workflow below applies to every other Project.

When the maintainer delegates final review and merge of a named Orbit task pull request, that delegation is the consent for that work only. The DevOps reviewer uses the maintainer's GitHub CLI profile. The reviewer checks the whole pull request, confirms the independent code and Incus review evidence, and submits a formal GitHub review for the exact head.

The review body names the full head commit SHA, the checks and their results, any remaining limitations, links to the review evidence, and the verdict. A limitation that leaves required behavior unverified prevents approval. A Tasks engine subtask approval does not replace this final review of the whole pull request.

The merge gate requires a formal GitHub `APPROVED` review from the designated final reviewer for the current head. Submit `APPROVE` through the GitHub reviews API from the maintainer profile, with `commit_id` set to the reviewed SHA. A plain comment, including a ready-to-merge verdict, does not satisfy the gate. The pull request author cannot approve their own pull request. The delegation does not authorize an unrelated merge.

Use [`bin/pr-head-check`](/reference/delivery-line#binpr-head-check) to check for an `APPROVED` review and successful `Required checks` on the exact current head, and for named leftovers in the diff. A `COMMENTED` review on that head does not pass. The command does not verify the designated reviewer's identity or merge the pull request.

Before the immediate merge, read GitHub's review records and verify the final reviewer's identity, the `APPROVED` state, and that `commit_id` matches the reviewed SHA. A dismissed or stale approval, an approval for another head, outstanding requested changes from the final reviewer, the wrong identity, or unreadable review data prevents the merge.

The reviewer also confirms that `Required checks` succeeded on that same head, that blocking findings are resolved, that required verification is complete, and that the pull request head still matches the reviewed SHA. The reviewer then runs `gh pr merge <pr-url> --merge --match-head-commit <reviewed-sha>` from the maintainer profile. If the head changes, stop. Review the new commit, repeat the affected checks, and submit a new formal approval before trying again. A failed, pending, missing, or unreadable required check prevents the merge.

This workflow merges immediately after those checks pass. It does not enable GitHub auto-merge. After the merge, verify the merged state and record the merge commit. The Tasks scheduler observes the merged pull request and completes the task on its next tick.

The maintainer profile is an admin profile. It bypasses GitHub enforcement of the `Required checks` status rule, including on `gh pr merge`. GitHub does not require this approval and does not enforce it for that account, so the reviewer checks the formal approval and the successful `Required checks` result before invoking the merge. This workflow keeps that bypass and does not change the ruleset. It adds no `tasks:merge` command, Gateway merge endpoint, SDK contract, MCP contract, or API contract, and it changes no App permission.

Final DevOps review and its designated reviewer stay outside Orbit. The Tasks scheduler also consumes [trusted GitHub requested changes](/reference/tasks#trusted-github-feedback) as bounded fixup input. The Gateway operator's repository-scoped account allowlist is repair authority only; it does not designate the final reviewer or enforce this merge workflow. `COMMENTED` reviews remain informational, and an observed `APPROVED` review neither completes the task nor authorizes a Gateway merge.

Operators can [inspect durable approval evidence](/reference/tasks#inspect-approval-observations) through the read-only Gateway console report. Its reviewer/review/commit provenance and `current`, `historical`, or `unverified` status describe the stored scan only. It cannot replace this workflow's fresh checks or establish the designated final reviewer's identity or delegated consent.

For blocking findings, submit a formal `REQUEST_CHANGES` review through GitHub's reviews API with `commit_id` set to the full reviewed head SHA. Put the bounded findings in its body and inline review comments, not only in an issue comment or linked evidence. An eligible trusted request creates at most one automatic fixup for that review ID, within the shared [fixup caps](/reference/tasks#execution-ownership). Editing or dismissing a consumed review does not rewrite or cancel that work.

If the request exceeds the retrieval limits or needs a product decision, scope it with the operator instead of assuming Orbit will follow links or implement every instruction in the prose.

A fixup's fresh internal reviewer checks its snapshotted findings and the Project checks. Its push updates the same pull request. It does not submit a GitHub decision, comment, dismissal, or re-review request. The external reviewer must review the new head and submit a new formal decision. An old request or approval is stale, even if the fixup seems small.

Read all effective decisions from the designated final reviewer in submission order, with review ID breaking ties: a later `COMMENTED` review does not erase an approval or requested changes, and dismissal does not revive an older decision. The final reviewer still checks the complete PR, independent evidence, resolved findings, and successful `Required checks` on the exact head before the authorized immediate merge. Orbit observes the merge and completes the task afterward.

After the merge, keep the review evidence and release the resources allocated to the feature. For a local worktree, run `bin/worktree-remove ISSUE`.

[Delivery-line proofs](/reference/delivery-line) are the read-only commands for reproduction on current main, task-group shape, the current pull-request head, and post-merge live state. They do not file a task, merge, deploy, or roll back. Use `bin/pr-head-check` to confirm the diff adds no named leftover, including `object-storage-host` and `linear-reference`; the [leftover list](/reference/delivery-line#binpr-head-check) defines their generic, public-safe forms.

## CI

CI runs builds and static checks only. It keeps every test file, but runs no test suites. Pull requests, pushes to `main`, task handoff pushes and manual runs use the same fast gate. There is no nightly test run.

| Check | Work |
| --- | --- |
| PHP syntax and Composer | Validate every tracked Composer manifest and run `php -l` on added or changed PHP files, with at most four workers |
| CLI build | Build the existing Linux and macOS CLI binaries through the reusable Orbit CLI Binary workflow |
| Docs build | Generate the documentation index, check its committed freshness and run strict documentation lint |
| Generated contracts | Boot the Gateway and load all Operation kinds, executors and completion contracts; check OpenAPI, MCP tools and `apps/web/src/api/schema.d.ts` against their generators |
| Web build | Build the web app and require `dist/index.html` and `dist/version.json` |
| Required checks | Require all five build and static-check jobs to succeed; failures, cancellations and skips fail it |

Run `bin/check-operation-kinds` to repeat the registry check locally. It uses the real Gateway container, loads every family declaration and resolves its PHP executor or Swamp completion and execution handlers plus its named transports. Any loading or resolution failure exits nonzero. It runs no Operation, reads no resource records and contacts no Node. Families register a kind only after the Gateway enforces its required contracts.

The required check name stays exactly `Required checks`. The branch ruleset and automatic Gateway release resolver depend on it. Its dependencies run in minutes and do not wait for agent compilation. The complete CI run must also finish successfully before the CLI release workflow publishes.

Every checkout uses the immutable event commit, `github.sha`. On a pull request, this is the event's merge commit. A new push cancels the older run of that pull request or handoff branch. Main keeps its running run; a newer push replaces only the waiting run.

On `main`, Web uploads `web-dist-<full commit SHA>` for 14 days. It includes hidden files and keeps `index.html` and `version.json` at its root. Automatic Gateway releases install this commit's build. Pull requests publish no web build.

### Action pins and token permissions

Actions use pinned commits. Build and check jobs have read-only tokens. The CLI release publisher alone has the content write and attestation permissions needed to publish signed releases. It downloads CI's verified `agent-delivery` artifact without compiling or executing the agent.

### One PHP build in CI

Generated API contracts, web types, documentation navigation, and context are built in CI and stay out of Git. The Gateway Composer install builds its runtime MCP catalogue before generated-contract and web checks.

The shared PHP action installs PHP 8.5 and Composer 2.10.3 through the pinned PHP setup action. It does not compile the Rust agent or install the Node software recipe. Nodes and images continue to use the [machine software recipe](/reference/compute-drivers). Composer download caches stay separate from generated files and tool result caches.

### Gateway shards

Gateway test shards and the privileged, E2E, SDK, CLI, remote-program, Pi server, web and agent test jobs do not run in CI. Their test files and local commands remain available. CI does not use the former project selection tool.

### Self-hosted Gateway runner

The fast CI gate uses hosted runners. It needs no Gateway test system packages, privileged test setup or local Swamp credentials.

### Self-hosted shard runners

Existing runner provisioning remains available to other workflows. This CI workflow schedules no test shards or publishes quality-cache refs.

### Agent delivery

`Select agent delivery` finds the newest published CLI release whose agent source is an ancestor of this checkout. It compares that delivery's source with `apps/agent` in the current checkout. This comparison also detects an agent change whose earlier main CI run was cancelled. A changed tree builds Linux and macOS deliveries with at most four Cargo workers. An unchanged tree skips both builds.

`Combine agent delivery` runs after that selection even when both build jobs skip. For an unchanged agent, it downloads the published binaries, manifest, checksums and provenance. It verifies the manifest and checksums against the release workflow's attestation on `main`, then verifies every binary digest and uploads the same `agent-delivery` layout as a new build. No published delivery means the first build must create it.

The CLI release workflow still publishes every green main commit with its own CLI version, agent binaries, manifest, checksums and provenance. It rewrites the reused agent manifest's URLs to that commit's release and preserves the agent source commit and digests. [Gateway preparation](/reference/gateway-recovery#prepare-a-release) and [desired fleet state](/reference/self-update#desired-fleet-state) therefore use the same release and pin contract on a docs-only commit.

## Local checks

### Gateway tests and privilege

Ordinary local Gateway cases need no sudo. Privileged tests remain in their existing group for deliberate runs on a disposable host. CI runs neither group. Keep the existing no-sudo and temporary-directory boundaries when using these tests locally.

In an Orbit task, run the tests that cover the change in each changed project directory, such as `apps/gateway` or `packages/php-sdk`. Use stock Pest with a test file path or `--filter`. Do not run `composer check` or `bin/review-check`: the Gateway runs the Project check at handoff, and CI runs builds and static checks on the handoff commit. Outside a task, run the full suite and the checks below. Each project keeps its own dependencies and test configuration.

| Command | Result |
| --- | --- |
| `vendor/bin/pest --compact PATH` or `--filter=NAME` | Runs the tests that cover a change |
| `composer check` | Runs `guidance:check`, PHPStan, Rector, and the Pint check. It does not run the full Pest suite. |
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

Gateway smoke cases use a fake Gateway and real subprocess and HTTP deadlines. Each Python unittest case runs separately in Pest. Polling tests set a 0.25-second interval in the test process; the production poll interval and 20-second command limit stay unchanged. The run's total budget also caps each command. Timeout cases still expire real deadlines, including the total run limit. The no-tick and total-timeout tests keep their four- and three-second total limits so concurrent scheduler, agent-view, and CLI checks have time to finish under load.

The slow-Orbit case runs the real smoke result aggregation and tick check with a one-second total budget and real CLI subprocesses. Unrelated read checks use immediate successful doubles, each asserted to run once; their real behavior has separate coverage. The first tick read returns a stale tick immediately, and the second stalls past the total limit. The test keeps the JSON, exit-status, read-count, and last-seen-tick assertions without requiring unrelated subprocesses to finish within that second. A shorter poll interval does not reserve time for the next read.

The worker-budget test keeps a locked count of active shares, including overlap between waves. It lets the first lane acquire its share before later lanes register, so later allocations must respect shares already held. Signals report when each active lane has either acquired a share or entered the allocator's real condition wait. Workers stay on sockets until all granted workers in that wave have connected; no observation sleep determines completion.

### Parallel processes

Pest, PHPStan, Rector, and Pint use one process rule in `bin/check-processes`. At check start it reserves 640 MiB for gate overhead, then divides the remaining available memory by the measured allocation of 1664 MiB per worker, takes the smaller of that count and the available CPU count, and allows at least one worker.

On Linux it reads CPU affinity and cgroup v2 CPU quotas. Available memory is the smaller of `/proc/meminfo`'s `MemAvailable` and each bounded cgroup's limit minus its working set, including ancestor bounds. The working set is `memory.current` minus `memory.stat`'s reclaimable `inactive_file` pages. On macOS it reads the CPU count and the free, inactive, and speculative pages from `vm_stat`. Unreadable resources fail the check.

The floor of one can overcommit memory and OOM when less than the reserve plus one worker is available. The rule assumes one gate per task VM and does not reserve resources between concurrent gates on a shared host. CPU is schedulable capacity, not idle capacity: topology processes share it and can slow the gate.

| Tool | How it reads the process count |
| --- | --- |
| Pest | `--processes=$(../../bin/check-processes)` in the `test` script |
| Pint | `--parallel --max-processes=$(../../bin/check-processes)` in the `format:check` script |
| PHPStan | `tools/phpstan/parallel.php`, which every `phpstan.neon` includes |
| Rector | `->withParallel()` in `rector.php`, with the value from `tools/check-processes.php` |

Each parallel Rector worker keeps its cache in its own directory under `vendor/rector/cache/workers/`. `tools/rector-cache.php` names the directory and removes it when the worker exits. The next run's `--clear-cache` removes a directory that a killed worker left. Rector writes a cache file with a plain copy, and every worker rewrites the same configuration entry when it starts. Workers that share a directory can read a half-written file and fail with a syntax error.

Gateway's `RunScheduledCommand` keeps an explicit default for its command arguments so jobs queued before that property existed can still deserialize. Its Rector configuration excludes that class from constructor promotion, which would lose the property default on those payloads. A queue regression test covers this deployment boundary.

The PHP helper `tools/check-processes.php` invokes `bin/check-processes`; it contains no allocation rule. The candidate gate and `bin/test` use `tools/check_budget.py` to split the resource-derived budget between their lanes and suites with the internal `ORBIT_CHECK_PROCESSES` variable. A positive integer lane share is used verbatim, and an invalid share fails the check. Do not set it on a Project or in an operator shell. CI test commands bypass this local gate budget. Stock ParaTest detects all runner CPUs, as [Gateway shards](#gateway-shards) describes. CI quality tools still use this helper.

### The candidate gate

Root `composer check` runs `bin/review-check`, the candidate gate. The Orbit Project uses it as its task check, so it runs in the task VM at every subtask handoff. The gate runs only fast checks and no test suite. The full suite of each handoff runs in CI on the exact handoff commit, as [Tasks: Handoff CI](/reference/tasks#handoff-ci) describes. A merge still needs the full suite on the final head.

The gate checks the working tree as it is, including uncommitted and untracked files. It runs these checks.

1. It runs `bin/docs-impact --gate` against the merge base with `origin/main`. The fallback is the merge base with local `main`. Without a merge base, this check fails.
2. It runs `bin/project-vocabulary`, `bin/env-docs`, `bin/tooling-baseline`, and `bin/docs-nav`.
3. It seeds absent quality caches with `bin/worktree-cache`.
4. It checks the five Composer projects, as the next paragraph describes. [Projects run at the same time](#projects-run-at-the-same-time) explains how steps 4 to 8 share the machine.
5. When the candidate changes `apps/web`, `docs/openapi.json`, or `apps/pi-server`, it adds the matching checks, as [Web and Pi server checks](#web-and-pi-server-checks) describes.
6. It checks generated API artifacts when their inputs change, as [Generated artifacts](#generated-artifacts) describes.
7. When the candidate changes the Rust agent, the desktop app, or `.github/workflows/ci.yml`, it runs `cargo fmt --all -- --check` in the matching Rust project.
8. It runs `bin/check-classification-fakes` on changed test sources. A change to the checker itself checks every project’s tests.

When the candidate changes `apps/gateway/resources`, `apps/gateway/tests/Programs`, `apps/gateway/ruff.toml`, or `bin/remote-programs-check`, the gate also runs `bin/remote-programs-check`. See [Remote programs](/reference/remote-programs#lint-and-direct-tests).

For each Composer project, the gate runs `composer validate --strict` and `composer check`. Its `composer check` runs Pint and Rector only on the files that the candidate affects. [Changed-file quality checks](#changed-file-quality-checks) lists them.

#### Projects run at the same time

The gate groups its project checks by project and runs the groups at the same time. Each group runs its own commands in order. `bin/check-processes` supplies the budget of the whole gate, and the tools of all groups together stay within it. The gate shares the budget in three ways:

1. It runs one more group than half the budget at once. So the largest group can keep at least half of the budget.
2. It gives each command a share by the size of its group's projects, counted in test files. The share compares the group with the groups that still run.
3. It holds back a command until a process is free. Each command frees its processes at its end.

The largest group starts first. Its later commands get the processes that finished groups freed.

A budget of 2 or less runs one group at a time. The budget counts workers, not each tool's main process.

With a budget of 4, the Gateway's checks run with two processes while two other groups run with one each. A budget of 1 checks one project at a time. The gate prints each result when the command ends. The receipt lists the checks in the gate's own order.

The web checks and the repository checks run in the Gateway's group, after the Gateway's checks. `bin/docs-openapi --check` boots the Gateway application. The other groups share no files that a check writes.

#### Changed-file quality checks

The gate's `composer check` sets `ORBIT_CHECK_SCOPE=changed` and `ORBIT_CHECK_BASE` to the merge base with `origin/main`. `bin/quality-scope` reads them and narrows Pint and Rector. PHPStan keeps its result cache and analyses the whole project, so `composer check` runs it before Rector. The changed files are the PHP files that differ from the merge base: committed on the branch, staged, unstaged, or untracked.

| Tool | Files it checks in the gate |
| --- | --- |
| Pint | The changed PHP files that `pint.json` formats. With none, it logs `Pint: no changed PHP files to check` and passes. |
| Rector | The changed files in its paths, the files that PHPStan records as depending on a changed file, and the files in its paths that PHPStan does not analyse |

Rector reads the dependents from PHPStan's result cache, `vendor/phpstan/cache/resultCache.php`. It still runs with `--clear-cache`. The tool runs on all its files, and logs why, in these cases:

- The result cache is missing or cannot be read.
- Another PHPStan than the installed one wrote the result cache, or its format is not one that `bin/quality-scope` reads. The script lists the formats it knows.
- The result cache does not describe the files as they are now: a file it records has another hash, or a file in PHPStan's paths is not in it.
- The candidate deletes a PHP file that PHPStan analyses or records. After PHPStan runs again, nothing records the files that used it.
- The candidate changes the tool's configuration, `composer.json`, or `composer.lock`.
- The gate has no merge base.

CI, a plain `composer check`, and a run with `CI` or `GITHUB_ACTIONS` set check every file. Only the gate sets the scope. To make the gate check every file, run it with `ORBIT_CHECK_SCOPE=full`.

#### Generated artifacts

Changes under `apps/gateway`, `apps/cli`, or `packages/php-sdk`, to the gate or either API generator, to `docs/openapi.json` or `docs/docs.json`, or to the generated MCP outputs run `bin/docs-openapi --check` and `bin/mcp-tools --check`. These checks generate the expected bytes in memory and print a unified diff when files are stale. They leave the candidate files unchanged. A stale-artifact failure names the exact regeneration command: `bin/docs-openapi` or `bin/mcp-tools`. Regenerate OpenAPI first when both are stale, then run `cd apps/web && bun run types` to update the web schema.

Changes to `docs/openapi.json`, `packages/php-sdk/fixtures`, `apps/web/fixtures`, the fixture checker, or the gate also run `bin/api-fixtures --check` explicitly. Docs’ `composer check` continues to validate the SDK and web fixtures on every run.

The gate checks the local candidate tree. CI runs the builds and static checks listed above on the PR merge tree. Test suites remain available for focused local verification.

#### Web and Pi server checks

A change under `apps/web` runs `bun install --frozen-lockfile` in `apps/web`, then `bun run check`, `bun run build`, and a generated-types check in `apps/web`. The build also requires nonempty `dist/index.html` and `dist/version.json`, as CI does. The generated-types check runs `openapi-typescript` from the web app’s `tools/api-types` workspace and writes its output for `docs/openapi.json` to a temporary file and compares it with `src/api/schema.d.ts`. It does not change the working tree. On failure it prints a unified diff and says to run `cd apps/web && bun run types`. A change to `docs/openapi.json` alone runs the install and the generated-types check. A change under `apps/pi-server` runs `bun install --frozen-lockfile`, `bun run check`, and `bun run build` there. Its test files remain available locally. Rust formatting runs for changed Rust projects; the remaining Rust checks run only in CI.

A command whose program is missing fails with `<program>: required tool not found`. The generated-types check runs in a shell, so a tool that is missing there fails with exit code 127. The gate never skips a selected check.

#### Finding checks

Three checks reject review findings that code can detect. `composer check` runs the first two in every PHP project through the shared rules in `tools/phpstan/`.

| Check | Rejects |
| --- | --- |
| `phpstan-disallowed-calls` | A call to `strtotime()`. Use Carbon parsing. |
| `orbit.inlineVarOverride` | An inline `@var`, `@phpstan-var`, or `@psalm-var` inside a method body. Fix the type at its source. |
| `bin/check-classification-fakes` | A changed test that calls `Classification::fake()` without chaining `preventStrayClassifications()`. |

Each failure names the file and line.

#### Receipt

The gate writes a receipt, `result.json`, and one log per command in a new `review-*` directory under `orbit-checks/<HEAD>/` in the Git common directory. Each command also prints its duration in seconds, so stored task output shows slow steps without opening the receipt. Cache seeding prints its duration too. The receipt passes only when at least one command ran, every command passed, and the commit and the working tree did not change during the run.

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

`bin/bootstrap` installs all five Composer projects, [seeds the quality caches](#seed-a-checkout), and validates each project's guidance. Then it runs `composer test`, the project's full suite, and `composer check` in each project. Any failed check fails bootstrap. `bin/bootstrap --skip-checks` stops after the guidance validation. `bin/bootstrap --dependencies-only` stops after dependency installation, including any requested Bun and Rust dependencies; it prepares no development state and runs no checks.

`bin/worktree-remove ISSUE` refuses a dirty worktree, a branch that `origin/main` does not contain, and a path that exists but is not a registered worktree. It removes the worktree. Then it deletes the merged branch. It releases no topology: a topology runs only in a task VM and goes with it.

## Main caches

Main caches let a new checkout start with warm Pint and PHPStan result caches from `main`. Task VM workspaces import them from [CI cache refs](#ci-cache-refs). A local checkout copies them from the primary checkout, as [Seed a checkout](#seed-a-checkout) describes.

### CI cache refs

The fast CI workflow publishes no new quality-cache artifacts or refs. Existing `refs/orbit/ci-cache/<sha>` snapshots remain readable by task workspaces. These parentless Git commits contain cache payloads, not source history; the suffix names the code commit they came from.

Each retained project snapshot has a schema-3 manifest and optional Pint and PHPStan caches. The manifest records the code SHA, project, CI run and attempt, each cache checksum, each configuration checksum and the PHP minor version. Consumers check these facts before importing; a ref name alone is not evidence that a payload fits. PHPStan caches contain executable PHP.

A new task VM workspace starts at the `origin/<default branch>` tip. For a Project that declares `@orbit/ci-caches`, the Gateway independently selects the newest commit on that tip's first-parent history with a published `refs/orbit/ci-cache/<sha>` ref, records it in `refs/worktree/orbit/ci-baseline`, and imports its caches. It logs the baseline SHA and its distance from the tip, including zero for a cached tip.

If no ancestor has a cache ref, the workspace starts cold with no recorded cache baseline. If the optional ref fetch fails, the Gateway discards the mirror's cache refs and logs the fetch failure as the cause of the cold start. Neither case fails the workspace claim. It does not wait or poll for publication, or try another baseline if the selected cache is invalid. A baseline retry moves the untouched workspace to the current default branch tip and selects its cache baseline by the same rule.

Existing task branches, seeded worktrees, and incoming pull requests keep their selected source commit. The ref for the remote default branch still tracks its latest head. The PR must pass `Required checks` before merging.

The Gateway fetches the `refs/orbit/ci-cache/*` namespace into its mirror alongside source refs. Cache fetching is optional: a cache-ref miss must not fail the required source fetch. The Gateway sends only the ref for the selected code baseline to a workspace over SSH. [Instance setup](/reference/instance-setup#import-ci-main-caches) owns the compatibility checks and import. A missing or incompatible payload leaves checks cold at the selected code commit. A cache ref for another SHA, even a newer one, is never substituted. The workspace imports Git objects, not an Actions download or a base64 command payload.

### Cache ref retention

The retained `bin/ci-cache-ref publish` command prunes `refs/orbit/ci-cache/*` to one ref: the ref of the newest tested main commit. Main commit ancestry, not job completion order, identifies that commit. So origin holds only the newest publication, and a workspace never selects an older cache baseline. Pruning never touches source branches, tags, or other refs.

The Gateway removes upstream-deleted refs only from its mirror's cache namespace. It does not delete an active workspace's local cache objects or private tool caches. A workspace whose baseline ref was pruned starts cold. Git garbage collection can reclaim unreferenced cache objects later.

### CI artifacts

The fast CI workflow uploads no `ci-cache-<index>-<commit>` artifacts. Older runs retained these staging exports for 14 days. They are not a workspace transport: the Gateway reads Git refs for `@orbit/ci-caches`, without downloading artifacts or putting their contents in a setup command.

| File | Content |
| --- | --- |
| `manifest.json` | Schema version 3, project path, tested commit, CI run and exporting attempt, PHP minor version, and the checksum and configuration checksum of each quality cache |
| `quality/pint`, `quality/phpstan` | The Pint and PHPStan result caches that `composer check` wrote in the same job, when it wrote them |

### Seed a checkout

`bin/worktree-cache` copies Pint and PHPStan caches into a checkout's absent private caches and never replaces an existing one. Bootstrap and the candidate gate run it. Its only source is the primary checkout, and only while that checkout is clean on `main` and has the same `composer.lock` and tool configuration as the project. Feature worktrees are never a source. A missing or incompatible cache leaves that project cold, and seeding does not fail.

A task VM workspace [imports a CI cache ref](/reference/instance-setup#import-ci-main-caches) instead. That import also checks the runtime facts that each tool compares. A Project setup step can reflink each dependency tree and private tool cache from `ORBIT_SEED_PATH` first.

### Failures on main

A failure on main is a `Required checks` check run on a `main` commit that did not succeed. Read it for the current tip with `gh api 'repos/hardimpactdev/orbit/commits/main/check-runs?check_name=Required%20checks'`. Diagnose it on the failed commit. Fix it, or revert the change that caused it, through a reviewed pull request. The next `main` commit whose `Required checks` passes clears it.

## Why it works this way

### The maintainer approves every merge

The maintainer decides whether a feature belongs in Orbit. Turning on [review and merge](/reference/tasks#review-and-merge) for a Project is that decision for every task and listed pull request of the Project.

For named Orbit task work, the maintainer can delegate final review and merge. That delegation is the consent to submit the formal GitHub approval and to merge the reviewed commit. The review records the reviewed head, the evidence, and the verdict in GitHub's review state. A plain comment is not that approval, because its prose does not distinguish approval from requested changes. A Tasks engine subtask approval, or any other agent review, does not replace the final review of the whole pull request and does not authorize unrelated work.

The merge uses the maintainer's GitHub CLI profile. That admin profile bypasses GitHub enforcement of `Required checks`, and GitHub does not require an approving review, so the DevOps reviewer checks the formal approval and green CI before merging the reviewed commit. A ruleset change that requires an approving review is deferred: it needs new credentials, API behavior, and deployment work. The App merge of [review and merge](/reference/tasks#execution-ownership) has no admin bypass, so the ruleset binds it. It runs only for Projects that opt in.

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

A feature checkout can hold unmerged code or working edits, so its tool caches cannot stand for main. The fast CI workflow publishes no new quality-cache refs; and `bin/worktree-cache` copies only from a clean primary checkout on `main`. Each checkout writes to its own private copy. One writable cache shared across worktrees is a rejected alternative, because concurrent features would overwrite each other's results.

### Only main CI publishes

The fast CI workflow produces no quality caches. A deployment never runs a test suite.

Running the checks on a development machine to fill a cache is a rejected alternative. It repeated the work of CI, competed with other agents for the processors, and inside a deployment it exceeded the deployment's deadline. Its results also depended on the host: a test that passes in CI failed on a host with other file ACLs, and that failure held merges as a correctness failure on main. Publishing the caches of a clean bootstrap on `main` is rejected for the same reason, and because two producers would race.

### Main runs record full suites

Nick replaced full-suite CI with builds and static checks on 2026-10-28. Keeping test files preserves the intended behavior as a local reference while command families move to Swamp in parallel.

### Full suites gate merges

The check name remains `Required checks` so branch protection, task handoff CI and Gateway auto-release continue to resolve it. The name now represents the builds and static checks in [CI](#ci). A green result does not claim that any test suite ran. No ruleset change is needed for Orbit.

### Gateway test time limits

Gateway uses stock PHPUnit per-test limits: `enforceTimeLimit="true"`, `defaultTimeLimit="2"`, `failOnRisky="true"`, and `stopOnRisky="true"` in `apps/gateway/phpunit.xml`. The default is two seconds locally. Tests remain unsized except for reviewed cases whose real deadline or kernel age is the behaviour under test.

CI invokes no Pest tests. The retained local test tooling supports stock per-test limits. `composer check` also runs Pest through `guidance:check`. Its shared launcher adds the same stock flag when `CI=true`; local guidance runs keep their existing arguments and configuration. Unsized tests therefore get five seconds in CI without changing local limits.

CI permits five seconds because CPU contention slows work on shared hosts. Aborts at two seconds across changing tests, including pure renderers, are not evidence that those tests need rewriting. Investigate any failure that remains at five seconds as a real bug. There is no suite-time target, CI-step timing gate, or headroom requirement. Do not add per-test CI budgets or timing machinery.

An exception uses a dedicated native PHPUnit class with class-level `#[Large]` and a one-line justification beside the attribute. Each case needs review; the class contains only the approved real-deadline cases, never unrelated tests. The approved classes are `DocumentTransportDeadlineTest`, with the native Guzzle connection and total-request deadlines, `LinuxHostCtimeTest`, with real kernel-ctime aging through GNU `find`, and `TaskCheckDeadlineTest`, with the retained Tasks checker’s real setup deadlines, TERM grace, and output-drain bound. They use stock PHPUnit `timeoutForLargeTests`, without an override. Installed PHPUnit supports sizes only on classes, and installed Pest cannot declare sizes. Do not substitute size-named groups or add custom timeout or sizing machinery.

Fix slow cases at their cause: control clocks and transports, share expensive read-only setup, and keep a real subprocess or deadline only when it is the proof. Preserve every behaviour assertion. The WebSocket pong-write deadline proof keeps the real TLS connection and zero-byte writes. Its controlled application clock checks the deadline boundary and expiry without waiting five seconds. Finite local fixture commands may use `Tests\Support\FixtureProcessRunner`, which waits on pipes instead of polling for production cancellation. It rejects streaming and cancellation callbacks; those proofs use the native runner.

Native PHPUnit Feature tests extend `Tests\FeatureTestCase` with the same database and cache traits as Pest's Feature cases. Both use `FeatureTestEnvironment` for storage and process isolation, transport guards, and cleanup. Extension-specific setup remains with its tests.

Read-only fixtures are prepared once per file or worker, then copied into private writable directories. Gateway release fixtures share the initial Git origin and adopted release; each case still owns its working repositories, database, runtime and cleanup. Adoption tests fake the agent receipts and keep the two-phase workflow, recovery records, and retries in PHP. Rust tests own adoption’s filesystem safety and atomic exchanges. Cleanup restores directory access in the PHP process before descending, without following symlinks or changing regular-file modes, then removes the private tree. It does not start a `chmod` process for every directory.

The fixture that snapshots a live WAL shares only its initial database, not the writer, snapshot, transfer or import. It keeps all 200 rows and the 64 MiB BLOB. The real writer runs without a delay.

Registration tests in the Gateway fake agent receipts and verify source facts, relocation checkpoints, lost-response recovery, and error mapping. Rust tests own checkout safety, cross-filesystem copies, worktree repair, and Laravel rollback files. Each filesystem recovery checkpoint runs against real private repositories, including a separate filesystem for copy recovery.

Development source tests keep branch selection and removal recovery decisions in PHP with fake agent responses. Native Rust tests verify Git execution, preparation receipts, quarantine identity, and cleanup after partial Git deletion. Tests of linked worktrees compare the files of siblings and shared Git metadata before and after removal. They cover siblings that move or disappear during an accepted removal.

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

CI does not restore or save Rector's cache. Every project's `composer rector` uses `--clear-cache`, including runs through `composer check` in CI and the local review gate. Rector's unchanged-file cache does not track all class dependencies: a parent-only property change can leave an unchanged child's missing `#[Override]` undetected. Clearing the cache trades warm-run speed for a complete check of the configured Rector paths; Pint, PHPStan, and Pest keep their existing caches. The candidate gate narrows the files instead of keeping a cache, as [The gate checks changed files](#the-gate-checks-changed-files) explains.

The regression in `apps/docs/tests/Unit/ToolingBaselineRectorCacheTest.php` reads the project's Composer script in the test body and requires an exact `--clear-cache` argument. Removing the argument makes the test fail. `bin/tooling-baseline` keeps the Rector command identical in every project, and its drift test rejects removal of the argument from another project too.

Running Rector twice from cold adds unrelated work. The direct command assertion checks the setting Orbit owns instead of retesting Rector's cache implementation. No Rector work or assertions run in a class-level hook.

Gateway timings were measured in the task workspace on Linux x86_64, with four CPUs, PHP 8.5.11, and Rector 2.6.7. They were 126.24 seconds for an uncached run and 0.65 seconds for a warm run before the fix. After adding `--clear-cache`, `composer rector` took 154.77 seconds on the first run and 118.92 seconds on the repeat. These are wall-clock samples, not a performance guarantee: checks now pay the full analysis cost on every run.

### The gate checks changed files

A new Orbit Instance or task VM starts at a `main` commit that CI already checked. Before this change, `composer check` there took about 10 minutes, almost all of it in the quality tools. Rector ran in one process without a cache, and Pint ran in one process. In the Gateway, Rector took about 155 seconds and a cold Pint about 288 seconds.

The gate now checks with Pint only the files the candidate changes, because Pint judges each file on its own. Rector's findings in a file can depend on other files, such as a parent class. So Rector also checks every file that PHPStan records as depending on a changed file. PHPStan records these dependents for its own result cache, and the gate runs PHPStan first, so the record describes the files as they are. A record that does not match the files makes Rector check everything. The gate never checks less than it can prove covers the change.

CI builds the pull request merge result, checks generated contracts and validates changed PHP syntax. Its `Required checks` result decides the merge. The gate gives fast feedback at each handoff.

All four tools run in parallel processes. The old limits of two PHPStan and Pest processes had no recorded reason. Rector ran in one process, and parallel workers that share its cache directory can fail on a half-written cache file, so each worker now has its own directory. With 16 processes on a 16-core machine, a full Rector run in the Gateway takes about 32 seconds with the same findings, and a cold Pint run about 37 seconds.

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

The shared installer inspects runner-writable JavaScript executables as `runner`, including the setup smoke check, which runs from `/` and compares the result with the recipe pin. Inspection passes `VP_HOME` after sudo in argv so sudo environment filtering cannot create another Vite+ store. All Node-consuming CI jobs, including the dependency audit, read `.node-version`. Recipe convergence owns Node.js on fleet Nodes and retires duplicate APT ownership after verifying the replacement and simulating dependency-safe removal.
