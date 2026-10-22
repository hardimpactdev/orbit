---
title: "Contributing to Orbit"
sidebarTitle: "Contributor guide"
description: "Prepare architecture and documentation, build a feature, and submit a complete pull request."
covers:
  - .agents/skills/reviewing-pull-requests/references/test-*.md
  - "{composer.json,vet.json,.gitattributes}"
  - bin/{bootstrap,gateway-app-key,test,pest-plain,review-check,bug-repro,task-group-check,pr-head-check,deploy-verify,docs-merge-check,generated-merge,dependency-audit}
  - "{apps/*,packages/php-sdk}/{composer,vet}.json"
  - "{apps/*,packages/php-sdk}/phpstan.neon"
  - apps/gateway/{database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php,tests/{Support/{LinuxHost,TestToolchain,LocalShellSshExecutor,WithCommittedSqliteDatabase}.php,Feature/Database/SchemaBaselineTest.php}}
  - apps/docs/**
  - .github/{dependabot,workflows/ci,workflows/dependency-audit}.yml
---

# Contributing to Orbit

Submit a complete feature: its implementation, tests, documentation, and any architecture decisions. Orbit reproduces the feature on Incus during independent review, and the maintainer approves each merge.

New to Orbit? [Your first contribution](/first-contribution) is the short path for a person who works by hand. It also lists the parts of this guide that Orbit's maintainers and agents handle.

The order is always the same: architecture, documentation, implementation, a complete pull request, review, maintainer approval, and merge.

From the repository root, install the project dependencies:

```bash
bin/bootstrap
```

`bin/bootstrap` installs the five Composer projects and writes the Gateway's application key to `~/.orbit/gateway.app-key`, or to `$ORBIT_HOME/gateway.app-key` when `ORBIT_HOME` is set. It keeps a key that already exists. The web app, the Pi server, the Node agent, and the desktop app use Bun or Cargo; add `--with-bun` or `--with-rust` to install their locked dependencies too. [Bun and Rust projects](/reference/bun-and-rust-projects) shows how to install, run, and check them.

## 1. Check the architecture

Read the [mission](/mission), the [architecture](/architecture), and the [concepts](/concepts). Then read the documentation, code, and tests of the parts your feature changes. The documentation is the current truth about Orbit, and each page explains its design in a "Why it works this way" section. [Architecture decisions](/decisions/overview) holds the decisions that are not built yet.

Find the pages for a component with `composer docs-context -- --component=apps/cli`. `--concept=Cluster` selects pages by concept. The command returns an ordered reading list. It does not decide what the feature must do.

When the feature makes a significant architecture decision, draft an architecture decision record (ADR) on the same branch. A decision is significant when it sets a contract between components, an architecture boundary, a security or ownership model, or a choice that is costly to reverse. Explain the alternatives and the consequences, and name the mission principle it serves. Implementation details stay in code and tests.

Mark the draft `In progress.` Reviewers assess it with the implementation and the documentation. The pull request that completes the decision absorbs it into the owning page and retires the record, so the documentation stays the single source of truth. The [ADR guide](https://github.com/hardimpactdev/orbit/blob/main/docs/decisions/README.md) covers the steps, numbering, and format.

## 2. Write the documentation

Update the pages under `docs/` before you write code. Describe what a user can do, the limits, and what happens when an operation fails. Write in the present tense, and check that the pages and the proposed ADRs agree. The documentation ships in the same pull request as the behavior it describes.

Update the documentation when the feature changes behavior, terms, architecture, a public or operational contract, or knowledge that another contributor needs. A fix that restores documented behavior, or a refactor that changes no behavior, can leave the documentation alone. Do not write prose only to produce a documentation change.

When an ADR, a page, the code, or a test disagree, stop and report the conflict. Do not resolve it by quietly changing one of them.

Run the deterministic impact check for the group's start commit and every planned path, including paths that do not exist yet. [Docs impact stays in the repository](#docs-impact-stays-in-the-repository) explains why Orbit owns this check:

```bash
bin/docs-impact --base <start-commit> --paths <planned-path>
```

The start commit is the merge base with `origin/main`; repeat `--paths` for every planned path in the brief, including paths that do not exist yet. Every Orbit group starts with this docs subtask, as required by the [Orbit Tasks policy](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md). A `docs_required` report means the subtask updates each impacted page or runs its named generator. A `no_docs_change` report is the fast path: hand off the complete JSON report as evidence. The reviewer confirms the planned paths and report are complete before implementation starts. The report, not an agent's opinion, is the evidence.

A person who contributes outside Orbit Tasks has no docs subtask. That person can still run the check to find the affected pages, as [What you can skip](/first-contribution#what-you-can-skip) explains.

The impact checker maps each CLI family to `docs/cli/<family>.mdx`. Register a new family in `DocsImpact::CLI_FAMILIES` and add its reference page in the same change.

At every subtask handoff, Orbit's own task check runs the impact check against the candidate diff from the group's start commit. An impacted page missing from that diff fails with the list of pages.

A reviewer-confirmed waiver is a `page: reason` line in `.git/orbit/docs-unaffected/<branch>.txt`, named after the checked-out branch. Linked worktrees use the primary checkout's `.git`. The gate reads only the current branch's file, and a detached HEAD has none. The file is never committed, so a waiver never reaches `main` or another branch. The gate's log lists the waivers it accepted under `exceptions`, and the reviewer confirms each one. Required generator checks still apply. This policy runs through Orbit's `composer check` and `bin/review-check`; the generic Gateway task engine does not know about docs-first or documentation conventions. Jev is out of scope for this version.

Run from the repository root:

```bash
composer docs-build
composer docs-lint
```

Commit `docs/generated/context.json` when `docs-build` changes it. A new page also needs a place in the `docs/docs.json` navigation; `composer docs-nav` fails for a page that exists only on disk. For Mintlify page or navigation changes, also run `npx mint validate` and `npx mint broken-links` from `docs/`.

### Merging generated files

Keep `docs/generated/context.json`, `docs/docs.json`, `docs/decisions/overview.mdx`, `docs/openapi.json`, `apps/web/src/api/schema.d.ts`, and `apps/gateway/resources/mcp/tools.json` committed. The Gateway and web app need their runtime contracts, and the documentation checks need reproducible snapshots. Do not resolve their conflicts by taking one branch or using a text union: that can drop another PR's API or produce invalid JSON.

Run `bin/generated-merge --install` once in each clone before merging branches. `bin/bootstrap` also installs this local Git driver. It needs the normal Docs and Gateway Composer dependencies and the web app's Bun dependencies. Git does not distribute driver configuration with attributes; a fresh clone and a hosted merge service do not run it automatically. Prepare the merge locally with the driver, then publish the checked merge through the normal review workflow. No global Git configuration is changed.

The Docs CI job installs the Gateway's locked runtime Composer dependencies with `--no-dev` and the web app's locked Bun dependencies before running tests. The merge proof needs Gateway route discovery and `openapi-typescript`, not Gateway test or analysis tools. The Docs project's own development dependencies remain installed.

For Orbit task workspaces whose Project setup list uses separate dependency steps instead of `bin/bootstrap`, the operator must add `orbit instance:setup-step:create generated-merge --project=PROJECT_ID --command='bin/generated-merge --install' --timeout=30` after this change merges. Replace `PROJECT_ID` with the Orbit Project ID. The baseline setup installs the driver before agent turns. Apply this live Project change only after merge; repository changes do not update the live setup list. The implementer uses the driver when a conflict fixup merges main.

The driver merges the maintained parts of `docs/docs.json` by navigation identity and ADR overview rows by decision number. Conflicting edits to the same value still fail. It builds a private merge preview of the source files, regenerates the context index, OpenAPI document, web types, and MCP manifest there, and returns the requested artifact to Git. Source conflicts or missing build tools leave the merge unresolved. It does not fetch, change the source checkout, or run Git hooks.

Regeneration is cached once per ordered pair of commit SHAs, driver version, and installed dependency locks in the checkout's private Git directory. Before building, it refuses changes to either branch's Docs or Gateway `composer.lock` or web `bun.lock` since their common ancestor, since installed packages may predate those changes.

Before sharing installed dependencies, it also compares the merged tree's locks with the dependency checkout. A lock change or mismatch fails clearly: install the merged locked dependencies and run the generators explicitly instead of using old vendor packages. Source conflicts and dependency failures are not cached. Cache hits reuse the completed validation of those immutable commits.

The private source merge uses the maintained-index driver only for navigation and ADR rows. Git's no-op driver handles derived snapshots, which the generators replace before publishing the cache. The generators run in sequence on the calling thread: Markdown context index, OpenAPI document, MCP tools, then web types. Web types use the web app's installed OpenAPI TypeScript CLI on Bun, the web app's runtime. Every generator must succeed before the complete output is published.

`bin/docs-merge-check` previews GitHub's ordinary text merge without custom drivers; it reports the local driver result separately on a conflict, never turning that conflict into a successful GitHub preview.

This keeps the existing file layout and deployment contracts. Dropping build outputs would need deployment changes and would not solve the committed runtime artifacts; splitting indexes would still leave the API contracts. Regeneration is smaller and avoids combining stale snapshots. All existing read-only freshness and `--check` gates remain required after a merge; the driver does not replace them. Use a normal two-parent merge with locally available commit refs. For a rebase, squash application, or a merge with conflicting source edits, resolve the sources first and run the generators explicitly.

## 3. Implement and verify

Build the feature and its tests against the documented behavior. Keep the in-progress ADRs and the documentation in line with what the implementation delivers. Explain any change of direction in the pull request.

Run the tests that cover your change with stock Pest: `vendor/bin/pest --compact` with a test file path or `--filter`. `composer test` runs the project's full suite. Orbit runs no test impact analysis. Every project sets Composer's `process-timeout` to `0`, so Composer never stops a long test or check run.

Follow the [Feature delivery merge gate](/reference/implementation-loop#full-suites-gate-merges) for PR checks.

Every CI run on `main`, a push, the nightly run and a manual dispatch, runs every project's full suite as a pull request does, with the Gateway suite in stock Pest shards. CI selects no tests.

The [CI reference](/reference/implementation-loop#ci) lists the rules. Orbit's task gate, `bin/review-check`, runs no tests. CI runs the full suites of each task handoff on the handoff commit. The [feature delivery reference](/reference/implementation-loop#the-candidate-gate) lists the gate's steps.

The `test` script in each PHP project, and root `bin/test`, pass `--colors=never` to Pest. `bin/pest-plain` strips any ANSI control sequences that remain. The output has no ANSI escape codes and still ends with the `Tests:` summary. The scripts do not pass `--no-progress`, because parallel Pest then omits that summary. Keep the flag on the scripts.

The Gateway's package-cache integration tests run real npm and Bun installs. Keep Node/npm and Bun available locally. CI supplies Bun 1.4.2 in the Gateway project and shard jobs.

Local SSH fixtures give task-runner login shells a disposable `HOME`, not the contributor's shell profiles. Guest-script fixtures redirect host paths, including `/usr/local/bin`, into their temporary root. Keep these boundaries when adding tests: a check must not depend on personal profile output or write host tool launchers.

Build expensive read-only Git fixtures once per test file, then copy them into each case's disposable directory before changing them. Use `WithSharedGitFixtureCleanup` for the shared release and Instance-transfer seeds; its PHPUnit `AfterClass` hook clears them at each file boundary. Stock Pest clears scoped `uses()->afterAll()` hooks between cases. Cleanup must restore owner read, write, and traversal access on fixture directories without following symlinks or changing hard-linked file modes.

Use blocking process completion for fixture commands rather than a polling runner. For bundled Python programs, a test host can share imports and compiled code, but each invocation runs in a fresh child with its own globals, environment, working directory, and process group. Share no mutable program state between cases.

Keep real subprocess coverage for remote programs that manage Git state or process groups. Capture and status tests can run the real program synchronously; detached admission, cancellation, and managed-run tests keep their real launch path. Use explicit readiness signals before advancing a program's timeout clock. Keep a real-clock case where enforcing the timeout is the behavior under test. Start a readiness-timeout fixture with its normal startup budget, then set its short deadline after `Process::start()` returns; process launch must not expire before the readiness helper runs. Scheduler tests advance the framework clock instead of sleeping.

In an Orbit task, the implementer runs only the tests that cover the change in each changed Composer project. The Gateway runs the Project check at handoff, and CI runs the full suites on the handoff commit. Outside an Orbit task, run these commands in each changed project, such as `apps/cli`:

```bash
composer test
composer check
```

The Gateway runs only on Linux. You can run its tests on macOS, as the next two paragraphs describe, but to run a Gateway, use a Linux machine and follow the [Quickstart](/quickstart).

A change to a [remote program](/reference/remote-programs) also needs shellcheck and ruff 0.17.0 for `bin/remote-programs-check`, for example `brew install shellcheck` and `uv tool install ruff@0.17.0`. Install the pinned pytest tools from `apps/gateway/tests/Programs/requirements.txt` in a virtual environment and put its `bin` directory on `PATH`.

Gateway tests run the shell programs that Orbit installs on Ubuntu Nodes. On macOS, install the Linux tools they need with `brew install bash coreutils gnu-sed findutils caddy`. The test bootstrap puts these tools first on `PATH`, supplies `setsid` and `flock`, and stops with the missing package names when a tool is absent. The test application reads the tracked `.env.example`, not your `.env`. Child processes that boot the Gateway, such as `artisan` calls, still read `.env` when it exists.

Some tests run Node programs that use Linux kernel interfaces, such as `/proc/net/tcp` or `os.O_PATH`. On Linux, such as in CI, these tests run directly. On another system, such as macOS, they run on the Linux SSH host that `ORBIT_LINUX_TEST_HOST` names, such as an Ubuntu machine like the Nodes. The host needs the tools used by the delegated case and must accept `ssh` without a prompt.

Ordinary cases need no sudo. Only deliberately selected `privileged` cases need a disposable host with sudo and the `caddy` account; the default test selection excludes them. See [Gateway tests and privilege](/reference/implementation-loop#gateway-tests-and-privilege). When `ORBIT_LINUX_TEST_HOST` is not set, these tests are skipped, and the skip message names the variable. CI runs them on Linux, so a skip on your machine does not hide them from review.

Each test process copies `apps/gateway` to the host with rsync once and reuses the copy. The copy holds only the files that Git would track, as `git ls-files --cached --others --exclude-standard` lists them, and `vendor/`. So ignored files such as keys, logs, caches, and databases stay local, and no `.env` file except `.env.example` leaves your machine. The copy lives in a mode 700 directory under `/tmp/orbit-gateway-linux-tests-<uid>`. The process removes its copy when it ends, also on Ctrl-C or `SIGTERM`. The host stops a test that runs longer than five minutes, or `ORBIT_LINUX_TEST_TIMEOUT` seconds. A later run removes the copies that a killed process left, after six hours.

`bin/pest-plain` creates a fresh directory under the system temporary directory before starting Pest. It respects an inherited `TMPDIR`, except Orbit's private agent and check directories: those runs use `/tmp` so cross-user fixtures can traverse every parent. It sets `TMPDIR`, `TMP`, and `TEMP` for the run, so ParaTest worker files, generated PHP files, and browser profiles share the same lifetime as test fixtures.

The wrapper stops its remaining child processes and waits for them before removing its directory, including on Ctrl-C or `SIGTERM`. On Linux it also owns orphaned descendants that start a new session, reaps exited orphans while the command runs, and stops the run when its launcher parent dies. It preserves the directory and reports its path if a process cannot stop. It never scavenges another run's files.

The first interrupt is forwarded to the test process group. The launcher gives the group and its adopted children up to 75 seconds to finish cleanup, even when the Pest leader exits before its workers. This exceeds the Linux host cleanup's 60-second SSH timeout. A second interrupt forces termination. After an ordinary exit, remaining children stop immediately. `SIGKILL` of the launcher can leave a run directory for manual inspection. Use `bin/pest-plain vendor/bin/pest ...` for direct Pest runs too.

Gateway bootstrap reuses the run directory. Without the wrapper it creates a process-local `orbit-gateway-tests-<pid>-<random>` directory. E2E tests keep their fixtures in an `orbit-e2e-tests-<random>` child directory. Create fixtures under `sys_get_temp_dir()`, not a fixed `/tmp`, unless the test needs a short Unix socket path or a path that a Node program fixes. On `app-dev` Nodes, the [tmpfiles rule](/reference/node-provisioning#converge-the-orbit-footprint) empties `/tmp/orbit-*` and `/dev/shm/orbit-*` directories a day after their contents last changed.

Every Gateway Feature test gets `Storage::fake('local')`. Its Laravel storage path points to a temporary directory for that test, including direct-file staging. An after-test guard compares the original `storage/app` tree and fails if files changed there. Use the fake disk or a per-test temporary path for archives; never write transcript fixtures to the real archive root.

Gateway tests that need real commits or a database visible to subprocesses use `Tests\Support\WithCommittedSqliteDatabase`. It migrates a pristine SQLite template once per test file and copies it into each case's private directory. The ordinary `RefreshDatabase` connection stays inside its transaction; the committed fixture uses a separate connection. Do not roll back or purge the ordinary connection to replace it, because that invalidates Laravel's migration cache and reloads the database for every case.

Before replacing this helper with a stock reset trait, compare complete Documents workloads, including setup and teardown, not repeated migration calls alone. Both setups must preserve real commits, subprocess visibility, reference defaults, and per-case isolation. `DatabaseTruncation` permits real commits; its seeder must restore reference rows and reset application ID counters left by empty tables that Laravel skips truncating.

The Gateway's fresh-install schema is one squashed Laravel migration, followed by a migration that seeds required reference rows. The baseline keeps the last applied migration's name, `2026_12_03_000000_allow_cold_recipe_image_rebuilds`, so an existing Gateway that completed the recorded migration chain skips it. Fresh installations create the same tables, indexes, foreign keys, checks, and triggers and record the squashed history. Trigger diagnostics use the current Project and Instance names. Finish the recorded chain before deploying a squash. The baseline cannot be rolled back.

Keep tables and columns in `Schema::create` calls so Larastan reads them through its normal migration scanner and fingerprints the PHP source. SQLite triggers and partial indexes stay SQL data in the migration. Do not add a separate schema dump: the [Node script ratchet](/decisions/0225-make-node-changes-through-agent-commands#the-ratchet-starts-at-this-merge) counts new non-PHP files, including SQL. `SchemaBaselineTest` compares the table names, column names, and nullability Larastan reads with the loaded SQLite schema. It checks the fixed squashed-history boundary and the exact current migration files after it, so adding a migration does not require changing a hard-coded total.

Separate named test connections use the stock migrate command, with no schema-path override or SQLite CLI dependency. Point `queue.batching.database` at the same connection before migrating; Horizon's batch-metadata migration uses that setting. When adding a migration after the squash, test its upgrade and rollback against the preceding schema; delete those history tests and their unused helpers when the migration is next squashed. Required fresh-install reference rows belong in the reference-row migration.

Keep non-Gateway suites fast with each ecosystem's stock test tools. PHP tests use controllable clocks and Laravel's `Sleep::fake()` for timeout budgets, and in-process Artisan calls with transport fakes for command behavior. Give in-process nonterminal commands an explicit nonterminal input stream; never inherit the test runner's stdin. For raw output and channel assertions, call the real console kernel with separate in-memory stdout and stderr streams rather than mocking its output style. SDK-backed CLI commands use Saloon's transport fake, not Laravel's HTTP fake.

Keep one real binary smoke test for local profile output, and real PTY cases for raw input, echo, terminal-state restoration, and proving that explicit consent skips a terminal prompt.

Cover the SDK retry's default clock with one short test that uses real time. Inject a clock for the other timeout tests. Console lifecycle tests use short work and renderer intervals for process ownership and cleanup checks. Keep cold nested-panel startup and settlement inside the outer callback.

The composition proof uses the real renderer at 50 ms with at least seven outer ticks, three inner phases, off-tick admission and resume, and proportionally scaled cadence bounds. A separate fast test pins the production interval to 300 ms. Terminal recordings keep the production interval. The proof for printing lines still observes five frames at 300 ms in each frame order.

CLI and Docs tests have a stock PHPUnit limit of two seconds per test, for every test size and without exceptions. Unsized Gateway tests have a two-second local limit and a five-second CI limit. The Gateway shard and privileged steps pass PHPUnit's standard `--default-time-limit=5` through Pest because shared CI hosts can be CPU-saturated. An over-limit test fails the suite. Investigate failures at the applicable limit rather than adding retries, sleeps, skips, or per-test budgets. There is no whole-suite or CI-step duration gate.

Gateway permits a reviewed exception only when real elapsed time is the behaviour under test and a controllable clock or transport would lose that proof. Put only the approved cases in a dedicated native PHPUnit class with class-level `#[Large]` and a one-line justification beside the attribute. Use stock `timeoutForLargeTests`, not custom timeout or sizing machinery; keep unrelated tests unsized. The [Gateway test time-limit reference](/reference/implementation-loop#gateway-test-time-limits) lists the approved exceptions and enforcement settings.

CLI feature tests build fresh applications, configuration, containers, and command objects. Isolation regressions check that mutations do not survive a refresh and compare the complete command registry, including aliases, visibility and input definitions, with an independent subprocess. Use real subprocesses for proofs that depend on a cold boot or the process environment. CI does not add a private bytecode cache.

Consent matrices register only their command in the real application kernel. An affirmative case for each family retains full command discovery. Tests that validate prompt input deliver Backspace keys in one buffered read instead of rendering hundreds of unrelated frames. The prompt still processes every key. The real PTY driver polls readiness every 2 ms and drains queued output without waiting once the child has exited. Neither interval is a production-cadence assertion.

When a test must observe native cURL options, inject its option-setting callable and use a stock mock that delegates to `curl_setopt`; checking a private timeout selector does not prove the transfer uses it.

Add regression tests for behavior changes and their important failure modes. A bug fix adds a test that fails before the fix and passes after it. Confirm that the tests that exercise the new behavior ran.

Docs tests build a fresh Git repository for each isolated case. Fixture indexing and command assertions run in-process unless process or environment isolation is the behavior under test. Temporary Git repositories disable automatic garbage collection and maintenance before the first commit, so background Git processes cannot write pack files during fixture cleanup.

The generated-merge end-to-end proof runs outside Pest: `cd apps/docs && composer merge:check`. Docs includes it in `composer check`, so CI runs it in the project quality step, not the test step. It invokes the real Git, Composer, PHP, and Bun tools; it is not a PHP test and has no two-second per-test limit. No part of this proof runs in a Pest setup hook.

The check prepares one source-only Git snapshot from the current source files, including independent branch commits and expected combined trees. It creates private runtime-only Composer autoloaders, then gives each case a complete private copy with separate Git metadata and loaders. The snapshot must stay clean and have no generated-merge cache or generated bootstrap cache files. Preparation copies recorded output fixtures; each merge and freshness case still starts real generator processes cold. Both merge callbacks and independent freshness cases use the original installed, locked web CLI through Bun, with no bundling or minification for the check.

The disposable route corpus keeps real core controllers, Laravel route discovery, and the explicitly registered Conn extension provider, but only the endpoints and form requests needed by the merge proofs and the generator's request-schema checks. Disable unrelated third-party provider discovery in the private applications; those providers are not inputs to these proofs. Register the real Docs application provider and Librarian provider explicitly for context generation and independent linting. This avoids copying unrelated application history and evaluating unused request constants. Keep every Python case in the external quality check.

Tests for generated merges write distinct markers to derived artifacts instead of generating branch snapshots. Maintained navigation entries and ADR rows remain real inputs. Each merge order runs independently through real Git and generators, and must equal the same complete expected tree and artifact bytes. Preview commands also run separately in both orders.

Recorded fixtures contain real generator output for the base, combined ADR additions, and combined endpoint changes. Separate read-only freshness cases rebuild each fixture's outputs, including a fresh web type comparison. Every check must pass and leave all artifacts unchanged. Regenerate fixture bytes with the real generators when their inputs change; never update them just to silence a mismatch. Policy tests use small, valid corpora that exercise the rule under test; `composer docs-lint` checks the full corpus.

Run the merge generators in sequence. Give each independent freshness gate its own Python case for each recorded fixture: OpenAPI, MCP tools, Docs lint, navigation, and web types. Each gate checks that all recorded artifacts remain unchanged. The quality check runs these cases sequentially, without a nested worker pool. Another case rejects generators that run on a different thread. The real merge and freshness cases remain separate.

Use the [delivery-line commands](/reference/delivery-line) to prove a bug on current main, to validate a task-group payload, to check the current pull-request head, and to verify post-merge live state. They print one JSON object, exit nonzero on failure, and do not file, merge, deploy, or roll back.

GitHub CI runs quality checks and the full test suites of the five Composer projects, including documentation lint. It also checks and tests the web app, the Pi server, and the Rust agent, lints the desktop app, and lints and tests the Gateway's [remote programs](/reference/remote-programs), as [CI](/reference/implementation-loop#ci) lists.

Root `composer check` runs `bin/review-check`. It runs `composer validate --strict` and `composer check` in each of the five Composer projects, several projects at once. It runs no test suite. It checks the working tree as it is, uncommitted changes included, and writes a report under `<git-common-dir>/orbit-checks/<HEAD>/`. For changed paths it also checks generated OpenAPI and MCP artifacts, Rust formatting, the web and Pi server profiles, and a PHP finding pack. Artifact failures name the regeneration command. A missing tool fails its check. Orbit's Project task check runs this gate at every task handoff, and CI runs the handoff's full suites. [The candidate gate](/reference/implementation-loop#the-candidate-gate) lists every check.

The five projects share one tooling baseline: the same Composer script names and commands, the same `composer check` steps, and PHPStan level 9. `bin/tooling-baseline` fails when a project drifts from it, or when Pint skips a folder that Rector or PHPStan checks. CI and `bin/review-check` run it. To change a project's tooling on purpose, change the baseline in the same pull request and give the reason for any difference.

`composer rector` clears Rector's cache before every run, so changes in a parent class cannot hide findings in an unchanged child. The regression checks in its test body that the Composer command includes an exact `--clear-cache` argument. The shared tooling baseline keeps that command identical across all five projects. The test does not start Rector or move any part of its proof into a class-level hook.

CI and a plain `composer check` reanalyse all configured Rector paths. The review gate runs Pint on the changed files. It runs Rector on the changed files and the files that depend on them. The [feature delivery reference](/reference/implementation-loop#changed-file-quality-checks) lists the rules. Pest, PHPStan, Rector, and Pint run in parallel. `bin/check-processes` derives their [process count](/reference/implementation-loop#parallel-processes) from available CPU and memory.

## Audit tests and unused code

Routine test review uses each project's testing guidance and the existing reviewer skill. For a requested suite cleanup or unused-code sweep, use the reviewer's [optional audit procedure](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/reviewing-pull-requests/references/test-audit.md). It requires evidence for removals, named retained coverage, and checks that repaired assertions catch the intended defect. It does not add a mandatory PR gate. A script that checks test quality remains a proposed follow-up.

## Static analysis

PHPStan checks each PHP project during `composer check`, at level 9. The projects are `apps/gateway`, `apps/cli`, `packages/php-sdk`, `apps/e2e`, and `apps/docs`. Laravel projects use Larastan. The SDK uses PHPStan directly.

Fix the code that PHPStan reports, so that the declared type and the runtime value agree. Do not clear a finding with any of these:

- an ignore, including `@phpstan-ignore`, or a baseline
- `assert()` or an inline `@var` that overrides the inferred type
- a cast that only silences the finding
- a wider type that hides the finding

The finding pack also rejects a call to `strtotime()`, so use Carbon parsing. In a test, chain `preventStrayClassifications()` to every `Classification::fake()` call.

The CLI and E2E projects each keep one counted `ignoreErrors` entry for a Larastan finding on an inherited command helper. Each entry names the commands, the option, the file, and the count. Do not add an entry or raise a count.

## Dependencies

Manual updates take only releases that are at least three days old:

- Composer: the `laravel/vet` plugin skips releases younger than `minimum-release-age` days, as each project's `vet.json` sets.
- Bun: `bunfig.toml` sets `install.minimumReleaseAge` to 259200 seconds in `apps/web`, `apps/pi-server`, and `apps/desktop`.

The wait applies only when Composer or Bun resolves a version. `composer install` and `bun install --frozen-lockfile` install the locked versions. Vet keeps no trust entries, so it prints a notice on each install.

Update only the packages that you need, for example `composer update vendor/package --with-dependencies` or `bun update package`. Review the lockfile diff and run the checks.

When updating Vite+ in web or Pi server, keep the `vite` alias on the same Vite+ core version. Match the `vitest` override and web's Playwright browser provider to the Vitest version in `vite-plus/dist/versions.js`. Remove overrides for Vitest packages absent from the new runner. Pi server also pins transitive `pi-ai` and `pi-agent-core` to its direct versions so runtime objects and branded types come from one copy.

Dependabot is the automatic updater. It covers Composer in CLI, Gateway, Docs, E2E and the PHP SDK; Bun in web, Pi server and desktop; and GitHub Actions' full-SHA pins. Every entry in `.github/dependabot.yml` joins the daily multi-ecosystem group and sets `cooldown.default-days: 7`, so one daily pull request proposes releases that are at least seven days old. Security updates through Dependabot are not delayed. Enabling Dependabot security updates and GitHub's SHA-pinning policy is a separate operator step; repository files do not enable those settings.

When a published advisory has a fix, link the CVE or GHSA advisory in the pull request, update the package, run `composer audit:dependencies` and the checks, and get a review. If the fixed release is younger than three days, add its exact package name to `minimum-release-age-exclude` in `vet.json`, or to `install.minimumReleaseAgeExcludes` in `bunfig.toml`. Remove the exclusion when the release is three days old.

If an advisory has no fix, do not hide it. Record it in the pull request with the advisory link and the reason that the risk is acceptable.

From the repository root, `composer audit:dependencies` runs `composer audit --locked` for each Composer lockfile and `bun audit` for each Bun lockfile. The Dependency audit workflow runs it every night and on manual dispatch.

## 4. Submit a complete pull request

Explain the problem, the resulting behavior, the architecture decisions, the documentation changes, the verification results, and the remaining limits. Link an issue when one exists. A bug fix closes its issue with `Fixes #N` and names its regression test.

Request maintainer review when the feature is complete. You can use a draft pull request while you work. Mark it ready when the implementation is complete.

## Review and merge

Orbit's independent reviewer checks the code, the documentation, and the ADRs, and reproduces the feature on an [Incus topology](/reference/incus-topologies). The reviewer records the commit, the environment, the actions, the results, and the limits. The [feature delivery reference](/reference/implementation-loop) lists that evidence.

Address the review findings. Reviewers check the fixes and repeat the affected checks on the updated pull request. A merge needs passing CI, a successful independent code and Incus review, resolved findings, and the maintainer's approval.

For Orbit task pull requests whose final review and merge the maintainer delegates, a Tasks engine subtask approval is not the final review of the whole pull request. The DevOps reviewer submits a formal GitHub `APPROVED` review for the exact head and merges that commit through the maintainer's GitHub CLI profile.

The review body records the full head SHA, the checks and results, limitations, evidence links, and the verdict. A plain comment alone does not satisfy the gate. Follow the [final review workflow](/reference/implementation-loop#final-review-of-an-orbit-task-pull-request) for the delegated consent, the reviewer's identity and `commit_id`, successful `Required checks` on that head, the immediate merge with `--match-head-commit`, and a new formal approval after the head changes.

## Use an agent

The skills in the repository guide an agent through the work.

| Task | Skill |
| --- | --- |
| Shape the feature and prepare its ADRs and documentation | [grill-with-docs](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/grill-with-docs/SKILL.md) |
| Prepare, implement, or review work tracked by Orbit Tasks | [orbit-tasks](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) |
| Implement, verify, and submit the feature | [developing-features](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/developing-features/SKILL.md) |
| Review a proposal or a completed pull request | [reviewing-pull-requests](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/reviewing-pull-requests/SKILL.md) |
| Merge an approved pull request and clean up | [merging-pull-requests](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/merging-pull-requests/SKILL.md) |

For focused work, use [writing-documentation](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/writing-documentation/SKILL.md), [verifying-cli-output](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/verifying-cli-output/SKILL.md), [verifying-web-ui](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/verifying-web-ui/SKILL.md), or [using-incus-topologies](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md). CLI work follows the [CLI design standard](/reference/cli-ux) and the [command vocabulary](/reference/cli-command-vocabulary). A web UI change follows [web verification](/reference/web-verification).

An independent reviewer can review the proposed ADRs and documentation before coding, on request.

## Report a problem

Use [GitHub issues](https://github.com/hardimpactdev/orbit/issues) for bugs and questions. The bug report form adds the `bug` label, so the [open bugs](https://github.com/hardimpactdev/orbit/issues?q=is%3Aissue+is%3Aopen+label%3Abug) list counts every reported bug. A bug found inside an Orbit task still gets an issue when the task does not fix it. Include the source commit, the Orbit version, the operating system, the exact command, the expected result, and the observed result. Remove credentials, environment values, private keys, tokens, and personal data from shared logs. Include a request ID and a stable error code when you have them.

## Why it works this way

These reasons explain the process. Check them before you propose a change.

### Decisions ship with their feature

A pull request carries the ADR, the implementation, and the documentation together, and absorbs the ADR when it completes the decision. The maintainer then reviews a decision with the code that shows its consequences. Merging ADRs before their implementation is a rejected alternative, because it separates the decision from the evidence. A required plan in every pull request is also rejected, because the implementation, the documentation, and the decisions already describe the feature.

### Review reproduces on a topology

Orbit's reviewer reproduces the feature on the [topology](/reference/incus-topologies) that the task group declares, nested in its task VM, and records the evidence. Contributors do not have to run Incus before they submit, because they may not have the environment and Orbit owns the machine review. External contributors and internal automation meet the same review and merge standard.

A separate proof run for each candidate is a rejected alternative. That flow kept a proof plan and fixtures beside each branch, captured immutable evidence on a proof topology, kept those machines through review, compared later commits by recorded inputs, and promoted the proof topology into the snapshot at closeout. It doubled the delivery steps and held machines through review. The ownership and cleanup rules of that flow still apply to every topology.

### One documentation corpus

All maintained documentation lives under the root `docs/` directory, for humans and agents alike. `apps/docs` holds only the tooling: the lint rules, the context index builder, and their tests. A second content tree would drift from the first.

### Docs impact stays in the repository

The Gateway Tasks engine stores work, deliverables, and lifecycle state. Each Project supplies its own task check and repository policy. Orbit keeps `bin/docs-impact` and its extraction logic in the repository, and enforces the result through `composer check` and `bin/review-check`. Putting docs-first rules in the Gateway is rejected, because it would couple a generic engine to Orbit's documentation, ADRs, and tooling.

A deterministic report makes the docs-first decision repeatable. It combines changed paths with planned paths, maps public and operational surfaces to owning pages or existing generators, and reports impacted pages, reasons, generator status, and errors as stable JSON. Unknown or unowned current surfaces are errors, not guesses. Relying only on agent or reviewer judgment is rejected, because it makes the no-change path slow and inconsistent. Reviewers still confirm the report and any page-specific waiver; the task check repeats the analysis at every handoff so later work cannot silently leave a page unchanged.

Deleting a migration without an owner does not require a new ownership binding for the removed file. Removing an owned migration still routes the change to its page, and new or changed migrations still need owners.

A waiver lives in the Git directory, not in a tracked file, because it matters only to one branch and nothing reads it after the merge. A shared tracked file is rejected: concurrent branches appended to the same lines, so most merges of `main` into a task branch conflicted.

Page ownership belongs beside the documentation, in `covers:` frontmatter and the extractor's surface mappings. Listing owners in every task brief is rejected, because duplicate lists go stale and miss new surfaces. Running only existing generators is also rejected: generated API, CLI, and MCP contracts do not find every hand-written page affected by configuration, errors, migrations, or schedules. Current generator output needs no duplicate prose, but stale or missing output still requires an update.

Coverage declarations are optional, but docs-lint checks that their globs are safe and match tracked paths. A committed ratchet prevents already-covered pages from dropping their declarations. Requiring coverage on every page at once is rejected, because a monotonic ratchet lets documentation consolidation add ownership incrementally without weakening existing coverage.

### Checks that need no network

`composer docs-lint` checks structure, links, ADR format, blocked wording, and the freshness of the committed context index. It also enforces the ADR lifecycle. A number in the retirement table on the [decisions overview](/decisions/overview) must have no matching file in `docs/decisions`. Matching uses the full slug recorded in `apps/docs/config/adr-retired-slugs.php`, so the Tasks 0114 slug clash is allowed. Every row in that table must have a redirect from that exact ADR path.

Every ADR numbered 0180 or higher must say `In progress.` in its Status section and include a `Principle:` line. A lower number follows the same two rules only when the retirement table does not list its number. The open gaps are 0007 and 0020. Older ADRs still in Records with other statuses are listed in a committed allowlist that can only shrink.

Check a proposed head and its merge result with the current Docs tooling:

```bash
bin/docs-merge-check --base origin/main --head HEAD --head-only
bin/docs-merge-check --base origin/main --head HEAD
```

`--head` defaults to `HEAD`. `--head-only` checks that committed tree alone; otherwise `--base` is required and Git builds the merge result in a temporary local clone. Both modes run strict docs lint, including the ADR lifecycle rules, without changing the working tree. The merge preview retains both parents for history checks and binds the coverage ratchet baseline to the resolved `--base` commit, not the checkout's local `main`. Head-only mode preserves the source checkout's `origin/main` baseline when present; without it, lint uses the head's committed ratchet. Neither mode substitutes local `main` for that baseline.

The command isolates Laravel's configuration-cache path in its private scratch directory so a workspace or inherited cache cannot select a different documentation tree; existing caches are left untouched.

An ADR number closed on the base can pass at an older branch's head but fail in the merge result: the merge result is what lands on main.

CI's Docs job in the project matrix checks out the immutable event SHA (`github.sha`) and runs `composer check` and the full Docs suite once. On pull requests, that SHA is the event's merge commit, not the moving `refs/pull/N/merge` ref or the PR head. `Required checks` requires this job on every event; there is no second Docs merge-ref job.

The command never fetches. Make both refs available locally before running it. `--help` lists the flags and exit codes: 0 means lint passed, 1 means lint failed, and 2 means the preview could not be checked. Missing refs report `base_unavailable` or `head_unavailable`; conflicts report `merge_conflict` and never fall back to checking the head.

The lint command reads the repository only, with no network, external service, or Incus topology. Live behavior is proved on Incus, separately. A lint rule earns its place only when it protects a current invariant and has tests for a valid and an invalid case.

### Audits stay outside the gate

Advisories appear upstream at any time. An audit in `composer check` or in `Required checks` would block unrelated work for a change that the branch did not make. The nightly audit reports new advisories, and an advisory fix pull request ships the fix.

Vet runs without a trust baseline. A baseline makes each routine update a manual approval, and the release age already holds back a compromised release while it is found and pulled.

### A committed context index

`docs/generated/context.json` maps pages to concepts and components. Agents use it to load the right pages without reading all the code. Generation is an explicit write, and lint only checks that the committed index is current.
