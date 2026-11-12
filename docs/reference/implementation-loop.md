---
title: "Feature delivery"
description: "How a change reaches main: review evidence, CI, local checks, worktrees, and the shared main caches."
covers:
  - apps/docs/app/Documentation/{AdrLifecycle,DocsImpact}.php
  - bin/{review-check,test,ci-build-plan,ci-units,ci-delivery,ci-proof-eligibility,ci-proof-run,ci-cache-ref,ci-runner-prerequisites,worktree-create,worktree-remove,check-processes,php-build,swamp-shape-gate}
  - "{swamp/bin/{check-grant-patterns,validate-swamp-workflows,check-swamp-base-policy,swamp-shape-python,swamp_shape_host_tools}.py,swamp/bin/{check-swamp-shape,gate-test-support}.ts,swamp/config/swamp-shape-baseline.json,tools/check-processes.php}"
  - "{tools/check_budget.py,swamp/tests/_support/slice.ts}"
  - "{apps/*,packages/php-sdk}/rector.php"
  - tools/{phpstan/**,ci-runners/{setup.sh,javascript.py},agent_command.py}
  - .github/{ci/checks.toml,workflows/*.yml,actions/php/action.yml}
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

Orbit CI runs builds, static checks and the full Gateway, CLI and PHP SDK Pest suites when their inputs change. Swamp changes also run the Operation Deno tests. Pull requests, pushes to `main`, task handoff pushes and manual runs use the same fast checks.

Pull requests and main pushes run the fast Swamp control checks. Selected shape fixtures run as units in hosted shards. Route access fixtures share the admitted argv with the Gateway unit test, so hosted Ansible checks need no Gateway Composer installation. The fixture for destroy without a Route evaluates guards with the pinned CEL compiler. It runs source removal against real temporary Git trees.

The migration environment fixtures cover dependency-file isolation and explicit application, Process and Schedule file selection. Clone runtime fixtures cover inherited-store reuse, empty-clone preparation followed by a later pinned Process, and same-commit seed refresh after conversion.

The retained guard fixture pins only the source-removal step and the absence of workflow dependency checks, so unrelated Route phases can change. The withdrawal fixture runs two local Ansible applications to verify cleanup after a partial publication and after an already absent site. [Main full set](/reference/main-full-set) runs every shape fixture after merge, outside the merge and deploy path. Static PHP compilation is off on pull requests, which keep its pin validation; main builds and publishes after the merge.

| Check | Work |
| --- | --- |
| PHP syntax and Composer | Check frozen agent command references, validate every tracked Composer manifest and run `php -l` on changed PHP files, with at most four workers |
| Gateway static analysis | Run the complete Gateway PHPStan configuration and collect all Gateway tests |
| Gateway Pest suite | Run the complete default Gateway suite on `ubuntu-26.04`: four workers, 1 GiB per PHP process and a five-second default per-test limit |
| CLI Pest suite | Run the complete CLI suite on `ubuntu-26.04` in one process with 1 GiB of PHP memory |
| PHP SDK Pest suite | Run the complete SDK suite on `ubuntu-26.04` in one process; fail on an empty suite and always upload JUnit results |
| CLI build | Build the existing Linux and macOS CLI binaries through the reusable Orbit CLI Binary workflow |
| CLI static analysis, PHP SDK static analysis, E2E static analysis | Run PHPStan for `apps/cli`, `packages/php-sdk` or `apps/e2e` |
| Docs build | Generate API contracts and the documentation index, run strict documentation lint and the complete Docs Pest suite, including its Python contracts; run `bin/check-flows` on the [flow charts](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md) |
| Generated contracts | Check Operation kinds, their charts and workflow intent; generate OpenAPI, MCP tools and the web schema; check the recorded fixtures |
| Web build | Build the web app and require `dist/index.html` and `dist/version.json` |
| Gateway release inputs | Pass on every main commit, because a Gateway release also loads Swamp; on a pull request, skip Swamp-only and docs-only changes |
| Swamp | `bin/swamp-shape-gate`: validate every workflow when the change selects Swamp and on the three-hour schedule, run the Operation Deno tests, enforce static Swamp-shape controls and reject new per-run or invalid [behavior names](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md#behavior-names) |
| Proof harness | Run the case runner unit tests and lint the proof programs with `ruff` |
| CI proof | Prove a pull request head with `orbit proof:run` when a changed path selects a command. The blocking switches decide whether it gates Required checks; see [the proof run job](#the-proof-run-job) |
| Required checks | On PRs, require selected builds, static checks, Pest suites and fixture shards, including capacity. Proof follows the blocking switches. Untouched jobs and metadata-ineligible proofs may skip |
| Main delivery checks | On main, require the same selected fast jobs, independently of duplicate fixture shards and Main full set |
| Main fixture report | After selected main fixtures finish, report failure, cancellation or capacity to the CI summary and the causal merged PR; never gate delivery |

`Main delivery checks` depends exactly on `php`, `gateway-static`, `gateway-tests`, `cli`, `cli-static`, `cli-tests`, `sdk-static`, `sdk-tests`, `e2e-static`, `docs`, `api-reference`, `web`, `pi`, `agent`, `proof`, `gateway-release`, `changes` and `swamp`. These are the existing builds, Pest suites, static checks, contracts and other fast checks. It adds no test or build. `Start the CLI release` depends on this result.

The release resolver verifies a successful delivery job on the exact SHA in this repository's `ci.yml` main push or manual run; it accepts neither a PR check with the same name nor a commit status. The run may still be running or finish red because fixtures failed. Old commits without this result cannot be backfilled.

Gateway automatic releases retain the `Required checks` name: on main, `main-required` verifies the exact SHA and main CI run before forwarding a successful `Main delivery checks` result. Its summary links the source CI and fixture report. It has no fixture dependency. Skipped gates have distinct names in each event, so a skipped PR gate cannot invalidate the main result. The Gateway also requires `Gateway release inputs`, preserving its current reader and rollout guards.

`Resolve release` gates `Build CLI`, `Build Linux agent` and `Build macOS agent`; `Publish release` requires the resolver and those builds. Version, main ancestry, tags, provenance and publication guards still apply. Neither fixture shards nor the independent Main full set receiver belongs to these dependencies.

`Main fixture report` runs only for main pushes and manual main runs, after `changes` and `fixtures`. The job has two minutes for API reads and comment writes. It reports the exact tested SHA, source run, fixture job URLs, matrix outcome and capacity errors. Only merged PRs into this repository's main whose merge SHA equals the tested SHA receive a comment. A main push containing several merges reports only the PR for its tested head; a direct push has no causal PR comment. The comment is updated on reruns.

Missing attribution is explicit in the CI summary; API errors fail the report job. A failed fixture or capacity result also leaves the report job red after writing its evidence. The release summary links the source CI and this report, including pending fixtures, without waiting for either. Main full set is unchanged.

The Gateway Pest job installs Gateway and CLI dev Composer dependencies and regenerates both autoloaders with `composer install --no-scripts`. It then runs `bin/build-api` so tests read the current OpenAPI and MCP contracts; these generated outputs are not cached. NodeMetrics runs the CLI's PHP consent fixture. Separate vendor caches use each project's manifest and lockfile, PHP 8.5, the runner OS and SDK Composer metadata; they never restore the runtime-only Gateway cache.

The job downloads the recipe's Deno 2.9.8 artifact and checks its SHA-256 before extraction. It caches the archive and JSR/npm downloads and warms the firewall test imports before Pest. It downloads the recipe's Caddy 2.11.7 artifact, checks its SHA-512 before extraction and requires the executable and version before running tests. Missing Caddy fails setup. The real Caddy fixtures wait for every listener in the chain before sending their assertions; the echo server uses an ephemeral loopback admin port. SQLite stays in memory; no database or Redis service is started. The two privileged kernel cases remain in Gateway static analysis.

Gateway static analysis checks `nft --version` before its two real-kernel cases. Pi checks `setfacl --version` and `getfacl --version` before its ACL tests and verifies passwordless access to `nobody`. When the tools work, both jobs skip apt. Missing tools use `bin/ci-runner-prerequisites`: a signed Ubuntu 26.04 main index from Azure, then `mirrors.edge.kernel.org` if needed. The fallback uses a separate DNS domain, so a Canonical DNS outage does not affect both attempts. Both indexes require signatures from the Ubuntu archive keyring.

Each attempt uses private source and index files, a three-second connection timeout and no retries. An incomplete index update fails the attempt. Update and install share ten seconds for Azure and twenty seconds for the fallback. At the deadline, the helper kills the attempt's process group. It verifies the installed commands and fails if neither attempt supplies them. The job limits remain two minutes.

The CI policy tests run actionlint on the complete CI workflow to check Actions syntax, types and context availability. The checker downloads pinned actionlint 1.7.12 and verifies its archive's SHA-256 before extraction, including cache hits. Its runner-label catalog predates `ubuntu-26.04`, so the actionlint configuration adds that label explicitly.

Gateway bootstrap tests supply a fixed RSA key at the process boundary. The key has 4096 bits and is only for tests. Random prime search cannot consume the test deadline before the database's lazy migrations. Certificate signing, SSH-key generation and migration execution remain real; the tests check the requested key size and that a second bootstrap generates no new CA key.

The [checks table](https://github.com/hardimpactdev/orbit/blob/main/.github/ci/checks.toml) defines the inputs of Gateway Pest and every other build check. `bin/ci-build-plan` interprets it. An input selects a check when it matches an include and no exclusion. `**` crosses directories; `*` and `?` stay within one name.

Registry edits compare parsed entries against the merge base: each changed `check` entry selects its own job, while fixture-only edits and formatting select no extra fast jobs. Shared sections (including budgets, defaults, roots and selection rules) select all fast jobs. Changes to other files still select their usual checks. Fixture-only registry edits do not select Gateway release inputs on a PR; main keeps its release inputs.

The job depends on input selection; only an explicit false selection skips it. Required checks has a static dependency on the job and validates the exact selection keys and boolean values before accepting the results. A selected failure, cancellation or unexpected skip fails the gate independently of `ORBIT_SLOW_CHECKS_BLOCKING`.

The CLI Pest job installs `apps/cli` dev Composer dependencies with the shared PHP 8.5 action and coverage disabled. It runs the complete suite in one process with a 1 GiB memory limit and uploads JUnit results even after a failure.

The checks table also defines CLI Pest inputs. Required checks requires the job whenever selected; only an explicit false selection permits a skip.

The PHP SDK Pest job installs `packages/php-sdk` dev Composer dependencies with scripts disabled, using the shared pinned PHP 8.5 action with coverage disabled. Its vendor cache is separate from runtime dependencies. SDK code, tests, fixtures and configuration select it; shared PHP action changes select all checks. Unrelated docs and app changes skip it. Both Required checks and Main delivery checks require selected SDK tests to pass. Missing or non-boolean selections, failures, cancellation and unexpected skips fail the gate.

The initial SDK measurement at `1b46059657` found 1,250 cases: 1,236 passed, two failed and twelve errored, in 0.581 seconds of test time and 0.77 seconds wall time. The failures were stale expectations for Operations, malformed role assignments and the Node reboot hold. After repair, all 1,255 cases passed with 4,690 assertions in 0.608 seconds of test time and 0.83 seconds wall time. The job keeps a 120-second ceiling and a conservative 60-second table cost for PHP setup, dev-package installation, Pest and artifact upload. Cold downloads and the time from a push to Required checks remain unmeasured on hosted runners; verify the first hosted run before claiming the budget fits.

The Swamp gate production-lints the observer preparation play. Its fixture tests install the candidate pin on all three nested Nodes and reject checksum and download failures. Gateway tests require new presence versions and host facts after a controlled restart. Runner tests require preparation before every batch and direct case, including old workflow calls without setup, and give preparation and batch setup separate bounded budgets.

The mail phase runs as one fast, unsliced Python fixture in the Required PR path. It calls the shipped converger through unprivileged host and guest tool boundaries, rather than launching a playbook for every case. It exercises two IPv4 socket proxies, exact own-ACL admission, the real additive Docker DNAT resolver and refresh, foreign-resource skips, and unchanged repeats with new admission timestamps. It also retries after a failed unit write, after one successful service rewrite, and after all writes before reload. Its registry regression checks both Required selection and the runner command.

The fixture for the mailer rule reproduces the Incus error when deletion finds no file: exit 1, even with `--force`. Its real replacement shell proves distinct source ACL and user-xattr preservation after rename; three Ansible launches also check safe failure text.

It runs without root; fixture paths and ownership stay with the fixture user, while assertions preserve the production root/0644 contract. It preserves a workspace canary and permits no guest PHP or application Process restart.

The gate also runs the real Ansible readiness tasks against sockets, checking that the Instance proxy and model forwarder observe their exact listeners without opening host TCP connections. Private dpkg databases prove that installed Incus packages stay untouched with or without a hold, while removed packages are installed.

The Swamp gate rejects a new behavior name that carries a run id. A task workflow names its local and data steps by the address of the task VM, as [Behavior names](/reference/tasks#behavior-names) says.

The Swamp Deno test job runs on `ubuntu-26.04`. It uses the same hosted pool as the other pull request checks.

Runner containers are unprivileged. Install or repair their toolchain with `tools/ci-runners/setup.sh container NAME` while idle; jobs verify the managed PHP build and use fake privileged boundaries. Ordinary Deno tests need no sudo or Docker daemon; real Docker proofs run only on disposable proof VMs.

### CI budget

Pull request CI takes at most five minutes from push to result, including queues and dependencies. Proof CI is the only exception. Run only checks that apply to the changed files, using `bin/ci-build-plan`. Switch an over-budget check off until it meets the budget; no merge, deploy or review waits on it. Whoever adds or restores a job measures its complete path first.

Measure CI wall time from workflow creation to completion of `Required checks`. Include queueing, input selection and the final gate. Independent jobs overlap, so use the longest required path rather than adding every job's duration. The full workflow can continue after Required checks passes.

The repository variables below show today's observed values. Their meaning and defaults are owned by [Capacity: ideal state](/reference/command-development-pipeline#capacity-ideal-state).

| Switch or job | Observed today |
| --- | --- |
| `ORBIT_SLOW_CHECKS_BLOCKING` | `false` |
| `ORBIT_PROOF_BLOCKING` | `false` |
| `Start the CLI release` | Runs on main; [CLI binaries](/reference/cli-binaries) owns its condition |

The plan job reads the checks table once. Selected jobs start after it; unselected jobs are skipped before a runner starts. A docs-only change starts three jobs: Select build inputs, Docs build and Required checks. Reusable workflows receive the plan's selection instead of calculating it again.

Build, fixture and proof selection compare the candidate with its merge base against the supplied base commit. Selection requires both commits and their common ancestry; the plan job checks out full history. Commits added only to main after a pull request branches do not select checks for that pull request. For an ordinary main push, the previous main commit is the merge base, so selection covers the pushed changes.

Every executable check job has a `timeout-minutes` equal to its table `ceiling`, rounded up to minutes. Plan, Gateway release inputs and CLI release dispatch have two-minute limits. Required checks has two minutes unless a selected pull-request proof is blocking; then it retains the proof's 195-minute wait budget. CI proof keeps its own 195-minute exception. [Main full set](/reference/main-full-set) keeps its separate limits and installs its planner's Python prerequisites before checking its workflow policy.

`CiBudgetTest` in `tools/test_ci_table.py` follows the reusable workflows from `ci.yml`. It rejects a pull request job without a timeout or table entry, a timeout above four minutes or different from the ceiling, and a dependency other than the plan. It names the non-check jobs explicitly. Only Required checks, CLI release dispatch and CI proof may have other dependencies; proof is the only long-running exception on the pull request path.

Required checks has static dependencies on the selected checks, including Swamp. It accepts success or an unselected skip and checks that the plan's boolean selection keys exactly match its dependencies. Its API wait covers only blocking proof. The two proof switches retain their meaning.

The fixture table in `.github/ci/checks.toml` maps changed inputs to files and declares their costs. Editing fixture entries selects only those fixtures, using the same merge base as path selection. Formatting and edits to check definitions select no extra fixture bodies. Changes to shared fixture budgets, defaults or fixture order, or to the runner and slice preload, select all fixtures within the existing budget.

`bin/ci-units` turns each selected file into a unit; Deno files above 60 seconds run in slices that register every n-th case. Units fill four lanes per shard, each with at most 150 declared seconds, with at most six shards per pull request. Each shard runs up to four units at once. A file can declare a larger slice count when its cases need finer slices.

Measured container and fleet files declare costs by exact case name, with run IDs for completed measurements and explicit estimates for unmeasured cases. Their file cost equals the retained case total. Packing includes six seconds of startup per slice. Slice counts never exceed the retained case count.

Container host preparation uses one case per unit. Each declared body cost uses its largest completed observation, rounded up plus one second; paired cases must not receive an equal share of the file total. Deadline cuts are censored observations, not completed measurements.

Direct input matches take priority when work exceeds that limit, then fixture table order decides admission. A file runs with all its slices or is deferred whole; the plan names deferred units, their cases and unmapped Swamp paths in its summary. A file that cannot fit even an otherwise empty six-shard plan, or contains a unit above the lane budget, makes Required checks fail; admitted files still run. Unmapped paths select the table’s default checks.

Migration remains after-merge-only: its declared work needs twelve shards, above the six-shard limit. The host ACL file also exceeds a lane, and the host build-list file needs an isolated measurement before admission. The FrankenPHP proxy fixture needs the pinned binary through `ORBIT_FRANKENPHP`, which hosted fixture setup does not install. Their table entries explain these coverage gaps. The measured container file remains eligible.

The native migration fixture also forces advisory mail failure and malformed receipts for defaults and clones, proving that Route cutover and retirement succeed without rollback. Its account gate uses Swamp's upstream [throwaway credential fixture](https://github.com/swamp-club/swamp/blob/db3f6373/integration/auth_gate_fixture.ts), with no real credential or fleet call.

The task VM host egress Python file is also after-merge-only: it repeatedly reached the 180-second PR ceiling, including run 43102509039. Python files run as whole units; the full-set receiver retains every case until measured case splitting makes PR admission possible. These explicit deferrals do not fail Required checks.

Each fixture shard has a four-minute job limit. The runner stops the shard at 190 seconds, names running units as `OVER BUDGET` and queued units as `NOT RUN`, and fails the shard.

Each unit has a 180-second ceiling; a unit that exceeds it fails with its name, time and an instruction to raise its table cost for another slice or make it faster. Units that cannot be sliced and cost more than 150 seconds run after merge only, with a reason in the table.

The case inventory in `tools/tests/ci-fixture-cases.json` records measured registrations. Inventory edits select only the fixtures whose entries differ between the merge base and PR head, including added or removed entries for fixtures that still exist. Edits that only change JSON formatting select no fixture bodies.

Source hashes are evidence, not a gate on unrelated changes. Selected Deno units compare current registrations with their recorded population before running a body, including loop-generated cases. Changed populations, zero registrations and stale excluded names fail by name. A successful process must also provide a receipt proving that every selected case passed; ignored cases, unrun cases and missing receipts fail. New files use the default cost and the same runtime deadlines. Use `bin/ci-units collect --file PATH` to collect names without executing bodies, then review costs when updating a measured population. When a case name changes, update its inventory and cost key together.

The from-stock fleet case runs in PR fixtures with a deterministic fixture key pair, without installed WireGuard tools. It checks the stored private key and both public-key consumers, then stops before real WireGuard or systemd activation. Its table cost remains an estimate until a hosted run measures the restored case.

Two more cases run only after merge. Restarting the worker needs a running user systemd manager. Rebooting a persistently unreachable ordinary Node hit the shard deadline at 72.3 seconds. Their exact names and evidence are recorded in `checks.toml`. Other cases in those files remain eligible. Strict receipts and deadlines still apply, including in the full-set receiver. Deferral is a coverage gap until that receiver executes the case successfully.

Shards publish source hashes, declared costs, unit and startup times, exact case names and completed case times as artifacts. Running cases at a cutoff are censored lower bounds, not completed measurements; queued cases remain `NOT RUN`. A failed Python file reports unknown case outcomes and retains unittest output. Startup time covers launch and module load before the first case.

Cases above 150 seconds with completed or single-case cutoff evidence can be excluded by exact name in the table; the preload removes them before counting slices. The full after-merge run includes those cases. Scheduled and manual full runs still report failures outside the merge path. The fast control job keeps the shape baseline, frozen-base policy, behavior names and frozen agent contract checks.

The CLI release starts after Required checks on main. Measure that chain separately through release publication, including dispatch queues. The Linux and macOS agent jobs use [release build caches](/reference/cli-binaries#agent-build-cache). Measure cold and warm agent deliveries separately; a cache does not by itself prove that publication fits five minutes.

### The proof run job

`proof-run` in `.github/workflows/ci.yml` runs when changed paths select commands in a pull request from this repository and a hosted metadata preflight finds the exact commit eligible. Eligibility follows the Gateway trust rule: a current repository branch head, or the head of an open pull request with `run-proofs` and a writer's latest decisive approval of that exact commit. Comments do not revoke approval; change requests and dismissals do. The preflight reads GitHub metadata within 20 seconds. It skips the managed-runner job when ineligible. API errors fail the preflight. Unselected proofs do no eligibility work. The preflight adds no dependency to Required checks.

Proof gates `Required checks` only when both [blocking switches](#ci-budget) permit it. While blocking, Required checks accepts a skipped proof only with a successful preflight's explicit ineligible marker; missing evidence, API failure and unexpected skips fail closed. It does not wait for intentionally skipped proofs to run.

A new push cancels the previous proof. The runner rechecks eligibility immediately before calling Orbit. Lost eligibility produces a `skipped` outcome, never a passing proof. An explicit `proof.commit_untrusted` admission refusal with a failed result and an empty cases list also produces `skipped`. Executed cases, interrupted execution and all other failures retain their existing failure semantics. GitHub cannot change a running job to skipped: these runtime skips exit successfully but say `skipped` in the step output, receipt and summary. Blocking Required checks permits this explicit skip policy too.

A fork keeps its separate trusted base-branch workflow, a maintainer's approval of the head and the `run-proofs` label; fork code never runs on the managed runner. That workflow publishes `Orbit outside proof` on the exact head. When proof is blocking, Required checks reads its latest trusted status and fails if it is missing, pending or failed. After the outside proof succeeds, rerun the failed Required job.

CI proofs join one FIFO admission queue. Its front takes every free usable CI place up to its number of batches. Orbit balances whole batches by measured case time, runs them on fresh VMs, merges the verdicts and deletes the VMs. Capacity waits within a two-hour admission budget and then fails clearly. See [proof:run](/cli/proof/run).

A clean CLI exit is insufficient: the job requires a passing result with case verdicts for every selected command. A failed case, an unrun case, a missing result or a selected command with no verdict fails. A result with no selected command passes. A capacity message with no cases always fails; it has no successful skip classification.

The job summary lists case verdicts and uncovered paths. The `proof-receipt` artifact records the commit, operation, selected commands, outcome and idempotency key. An `interrupted` outcome means inconclusive coverage and exits nonzero; [proof outcomes](/cli/proof/run#verdicts) define failure precedence. It excludes execution input and host settings. Reports use full Swamp output artifacts because native history summaries keep only 4 KiB.

The job holds no secret. The runner's CLI acts as its enrolled Node from its WireGuard address, with an access grant to the Gateway. Only an active authenticated `ci-runner` Node gets the CI pool. The default labels are `self-hosted, hardimpact, orbit-shard`; `ORBIT_PROOF_RUNNER` can supply a JSON list of labels. The 195-minute job timeout covers two hours of admission, execution and cancellation.

The checks table owns build input selection, known source roots, each root's default checks, and measured costs and ceilings. `ci.yml`, shared action definitions, the table and the planner select every check once. Other workflow files select their declared consumers.

A path inside a known root that no check claims selects that root's default, the fast static check of its project, and appears in the plan summary. The tooling roots (`.github/`, `bin/`, `tools/`, `scripts/` and top-level files) have no static check, so their default is empty: no job runs for a file that no check reads. A path outside all declared roots fails planning and names the table entry to add.

Swamp source lives under `swamp/`. Its check inputs are declared in the same table. A run whose token the Gateway writes to a vault key, such as `task:sync` with `task-sync-token-<uuid>`, adds its key pattern to `swamp/config/gateway-workflow-grants.json`. Generated contracts checks the intended policy against the loaded Gateway declarations. Every main commit builds and uploads Web and becomes a Gateway release, Swamp-only ones too, because the release [loads Swamp](/reference/gateway-recovery#load-swamp). The CI run on `main` selects other builds the same way.

The Swamp shape job runs `bin/swamp-shape-gate --only control`. Its serial controls include all seven cases in the read-only `swamp/tests/writes_declared_test.ts` audit. Workflow, model and `writes-reviewed.json` changes select this job through the checks table. A failed audit fails the job. The audit also runs once in `--only shape`; fixture discovery, the fixture registry and its case inventory exclude it.

The full shape group runs the gate, Python analyzer, frozen-base policy and validation CI regression tests through Deno. It also runs pinned `ansible-lint` against the Node enrollment, role, development create, Route and task VM playbooks and checks the private Ansible input builder, the enrollment playbook contract and the waits of a nested fleet against a stub `incus`. The firewall regression test evaluates changed shapes, rule order and unchanged state through the pinned Ansible engine. The artifact tests cover candidate order, transport-only fallback, checksum rejection, cache reuse and staging cleanup.

The bridge writer fixture executes the shipped Ansible task against a temporary ACL boundary. It checks immediate busy refusal, partial withdrawal followed by refresh, failure health and successful recovery. Its fixture input map includes the shipped task and fake curl boundary. It performs no live Incus operation.

The task VM Incus tests require the SSH user's client config before parallel fleet work and preserve it on a second converge with zero changes.

The gate also validates Instance task arguments against the real pinned Ansible module specifications, because fixture modules cannot reject arguments on the real modules' behalf.

Baseline counts cannot grow. Stale evidence labels can be corrected only to labels found in the immutable base source. The check against frozen base rules always scans committed source, including gate scripts. It accepts a repair only when the baseline changes evidence labels alone, counts stay equal, base and head have identical findings, and all errors refer to the repaired labels. Added calls, changed endpoints and debt hidden by checker edits still fail.

The Python policy proves the bridge writer’s nonblocking host-tool transaction as a complete structure, including its busy refusal, descriptor custody and failure health write. Tests run both the current checker and the checker frozen in the comparison commit. Both reject a missing lock, early release, mutation on busy, wait loops, destructive calls outside the exact state helper (including loop-carried and match rebindings), helper aliases or altered destinations, and package imports that bypass the locked entry point. The [bridge transaction contract](https://github.com/hardimpactdev/orbit/blob/main/swamp/docs/swamp-shape.md#bridge-writer-transaction-contract) owns the requirements; it adds no pathname or baseline allowance.

The Deno test for worker renewal runs the installed Ansible playbook against fake CLI, journal and systemd boundaries. It holds drain and enrollment barriers to check renewal order, survivor capacity, queued follow-on work, failed enrollment and actual bootstrap/remint worker names.

Ansible test doubles for commands that accept standard input consume the full input before exiting. The task VM host fixture checks a launch config larger than a pipe buffer to catch broken-pipe races.

Tests live beside the checked scripts in `swamp/bin/` and run one file at a time. Python helpers run as subprocesses with assertions in Deno. These regression tests use disposable fixtures and do not run the Swamp CLI.

Swamp checks validate every workflow with the runtime pinned in `swamp/.swamp.yaml` and enforce the Swamp-shape baseline. The downloaded runtime is cached under a key of the node-worker pin file, `swamp/extensions/behaviors/node-worker/config.ts`, so a pin change downloads the new runtime once. The validation job uses a disposable hosted runner, where the pinned PHP setup action can install PHP without changing a restricted self-hosted runner. It also runs that pinned binary's `serve check-config` against disposable grant files rendered by the Gateway's production `SwampGrantRenderer` through `apps/gateway/bin/render-swamp-grants` (PHP 8.5, no Laravel boot or deployment credentials) from every `swamp/config/*grants*.json` input.

After native validation resolves the pinned extensions, the job runs `swamp/tests/node_add_bootstrap_test.ts`. It checks the first-contact SSH options and the bootstrap key guard against the checked-in workflow.

The gate requires `passed`, `wouldStart` and the full grant-file count; a refusal or missing account fails Required checks. The checks table declares the renderer and its validation inputs. This checks complete CEL conditions and regex safety without starting serve or changing live grants.

The checks table defines the inputs of shape control, native validation and Operation tests. It also declares prose inputs and known source roots; no unclassified tooling path selects every check by default.

Inside the scheduled or manual full-fixture job, pinned tool and collection setup runs first. Independent Ansible lint, Python fixtures and Deno checker tests then share at most four command slots, with one Deno worker per command and separate temporary files. Ansible lint and the Deno fixtures receive the same pinned collection path, so a role include does not depend on collections installed in the worker's home. Baseline and ratchet checks stay serial, and every failure remains in the final result.


Unit tests run in one hosted job with four Deno workers.

Workflow validation runs only when the change selects its workflow, grant, extension or runtime inputs, and validates every workflow on the three-hour schedule. It needs the Swamp account secrets. GitHub gives Dependabot pull requests no Actions secrets, so a dependency update outside `swamp/` does not wait on them.

The [CI budget](#ci-budget) applies before any fixture returns to pull requests. Project checks are removed from CI for now. Their local commands remain available. Orbit keeps fast TypeScript unit tests and no native workflow suite or custom runtime harness. Workflow validation and pinned `serve check-config` remain required. Full shape fixtures run every three hours and by manual dispatch.

Tests of bin scripts live beside their script. The shape job names the liveness and Node 209 source suites explicitly. It also runs every unit test in `swamp/tests` and in the automatic-deploy, Composer and JS maintenance and Project extension folders. Each file in `swamp/tests` is its own step, so the workers share the slow Ansible files. Three files stay out: `node_add_bootstrap_test.ts` runs in the validation group, `metrics_exporter_firewall_test.ts` stays out until it passes, and `native_host_method_test.ts` needs systemd and passwordless sudo, so it runs on a host. Test fixtures serve unit tests; they do not start Swamp or pull extensions.

Each main SHA has its own concurrency group in both CI and the reusable Swamp workflow. Main runs complete independently. A shared group would replace an older pending run even with `cancel-in-progress: false`; that option protects only the running job. Pull requests share a group and cancel superseded runs.

Docs build installs the locked Docs dev dependencies and Gateway runtime dependencies. It uses the existing PHP action and Bun 1.4.2 pin, installs only the API-types workspace, and generates OpenAPI, MCP tools and web types before building the Docs index and running Pest. Python 3, Git and Node come from `ubuntu-26.04`; no service or fleet operation is needed. Every `apps/docs/**`, `bin/check-*` and docs chart change selects this job. The complete Unit and Feature suites run with two workers and fail on an empty suite; the Python wrapper discovers every `*_test.py` file and rejects an empty collection. No Docs case is deferred to Main full set.

The first local measurement at `6faf66c1da` took 13.34 seconds for 228 Pest tests with fourteen workers on Ubuntu 26.04, PHP 8.5.12 and Python 3.14.4. It exposed stale navigation fixtures and unprepared generated contracts. After preparing the generated inputs and repairing the navigation fixtures, all 228 tests passed with 768 assertions in 17.52 seconds of Pest time and 17.90 seconds wall time with two workers. The wrapper reached all 11 Python tests across three files. These are warm local measurements.

A local install into empty vendor directories took 3.45 seconds for Docs dev packages and 3.18 seconds for Gateway runtime packages, with scripts disabled and warm Composer downloads. Contract generation took 1.61 seconds; web types took 0.82 seconds. Hosted PHP and Bun setup and cold downloads remain unmeasured.

The job keeps its 120-second ceiling. Its table cost is a 70-second estimate: the previous 40 seconds plus about 28 seconds of measured local tests and setup, rounded up. Hosted evidence must replace this estimate. Budget PHP setup, dev-package extraction, Gateway runtime installation, the filtered Bun install and contract generation as well as Pest; download-cache misses add network time. Check the first hosted run against the two-minute job limit and the five-minute push-to-Required budget before claiming it fits. Check Linux process-adoption fixtures on slower runners; each unsized test has a two-second limit.

Two checks guard the flow charts. Generated contracts requires a chart or a `docs/cli/flows-pending.txt` line for every kind. Docs build runs `bin/check-flows`. Their inputs are declared in the checks table.

`Gateway release inputs` records whether the whole push includes Orbit inputs; both the Gateway and CLI publisher read that check instead of recomputing the last commit. The initial import ratchets against its retained Swamp source commit, recorded in `swamp/import-source.json`; once the comparison base contains `swamp/`, every run uses its actual Orbit base.

Swamp workflow validation uses the dedicated `orbit-monorepo-ci` collective token with only `extensions:read`. Validation keeps its read-only key and signed sign-in proof on pull requests and pushes to main. Shape and ordinary Operation test jobs receive no secret environment.

CI uses `pull_request_target` only in the label workflow `proof-outside.yml`, which checks out one script from the base branch and passes only a commit SHA to Orbit ([the proof CI](/reference/feature-flow#the-proof-ci)). The runtime and local extension tree remain pinned. Validation, placement and Operation tests remain required, including when authentication is absent or refused. Full shape fixtures never gate Required checks; the existing switches apply to proof. No personal key or initial-import validation exception is used.

The plan passes three booleans and the resolved comparison base to the Swamp workflow. Each selected subjob starts directly, and Required checks requires the reusable call's result as a static dependency.

On a push to `main`, the `Start the CLI release` job runs after `Required checks` passes and dispatches `Orbit CLI Release` for the commit, regardless of `ORBIT_SLOW_CHECKS_BLOCKING`. The release does not wait for the rest of the run. A later release run from CI completion changes nothing when the release already exists with the expected commit and assets. See [published releases](/reference/cli-binaries#published-releases).

Known documentation, contract-generation and proof tools select only their consumers. The plan job always runs. The plan, PHP syntax, Docs and Swamp shape jobs keep full history for their comparisons. The plan resolves the initial import's retained comparison source before shape checks run current and frozen rules. Gateway releases and desired fleet state use the pinned agent manifest in `apps/gateway/resources/agent/frozen-manifest.json`, independently of Gateway or agent source changes.

When the table selects the Rust check, CI runs `cargo check --locked --all-targets` and `scripts/check-crates test --locked`, including composed extension crates, within its four-minute table ceiling. When it selects Pi, CI runs its check, tests and build within its two-minute table ceiling. Gateway releases and desired fleet state use the pinned agent manifest in `apps/gateway/resources/agent/frozen-manifest.json`, independently of Gateway or agent source changes.


Installed Gateway runtime and CLI build dependencies use lock-based caches. Composer install and its current lifecycle scripts always rerun, including contract generation and autoload discovery. Download caches restore across lockfile changes. Source, generated contracts and release binaries are never restored as dependency caches.

Rust CI caches the Cargo registry, Git dependencies and `apps/agent/target`. An exact manifest and lockfile key is preferred. After those inputs change, CI can restore the newest cache for the same operating system, architecture and Rust compiler; Cargo checks its fingerprints and rebuilds changed inputs. Locked checks and tests always run.

Run `bin/check-operation-kinds` to repeat the registry check locally. It uses the real Gateway container, loads every family declaration and resolves its PHP executor or Swamp completion and execution handlers plus its named transports. Any loading or resolution failure exits nonzero. CI supplies a public fixture encryption key and requires no Gateway database. Constructor dependencies defer resource reads until their first use. The check runs no Operation, reads no resource records and contacts no Node. Families register a kind only after the Gateway enforces its required contracts.

The proof CI reports within the same CI run; [the proof CI](/reference/feature-flow#the-proof-ci) describes its execution. The required check name stays exactly `Required checks`. The branch ruleset and automatic Gateway release resolver depend on it. Its dependencies include the selected Pi and Rust checks.

Every checkout uses the immutable event commit, `github.sha`. On a pull request, this is the event's merge commit. A new push cancels the older run of that pull request or handoff branch. Each main commit has its own concurrency group, so an earlier main run cannot queue or cancel a newer release candidate.

On `main`, Web uploads `web-dist-<full commit SHA>` for 14 days. The commit has its build as soon as `Required checks` passes. It includes hidden files and keeps `index.html` and `version.json` at its root. Automatic Gateway releases install this commit's build. Pull requests publish no web build.

### Action pins and token permissions

Actions use pinned commits. Build and check jobs have read-only tokens. The CLI release publisher alone has the content write and attestation permissions needed to publish signed releases. It publishes only CLI binaries, checksums and provenance. It downloads no agent delivery.

### One PHP build in CI

Generated API contracts, web types, documentation navigation, and context are built in CI and stay out of Git. The Gateway Composer install builds its runtime MCP catalogue before generated-contract and web checks.

Managed Linux Nodes, recipe artifacts, and PHP builds support amd64 only. CI builds no agent delivery. It reuses the frozen authenticated published delivery.

The shared PHP action installs PHP 8.5 and Composer 2.10.3 through the pinned PHP setup action. It does not compile the Rust agent or install the Node software recipe. Nodes and images continue to use the [machine software recipe](/reference/compute-drivers). Composer download caches stay separate from generated files and tool result caches.

### Gateway shards

Gateway Pest runs in one hosted job with four workers and no shards. Gateway static analysis also runs the two privileged kernel cases. CLI Pest and SDK Pest each run in a separate hosted job in one process. E2E, remote-program and web unit test jobs remain local checks; their test files and commands remain available.

### Runner policy during the Swamp migration

During the Swamp migration, Orbit CI runs builds and static checks, plus selected Gateway, CLI and SDK Pest, Pi and Rust tests. Web unit tests stay local. Agents run the affected test suites locally before publishing a change and report their counts in the pull request. The test files remain the behavior specification.

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

Ordinary local Gateway cases need no sudo. Privileged tests remain in their existing group for deliberate runs on a disposable host. CI runs the two real-kernel cases in the hosted Gateway static analysis job and the default suite in the Gateway Pest job. Keep the existing no-sudo and temporary-directory boundaries when using these tests locally.

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

The E2E tests for gate selection build one Git repository per file, with two commits. Each test gets an independent copy. Cases that need a different history change only their copy. The gate-selection fixtures make Pest fail if the local gate tries to run a test suite; selected Gateway Pest runs in CI.

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

The PHP helper `tools/check-processes.php` invokes `bin/check-processes`; it contains no allocation rule. The candidate gate and `bin/test` use `tools/check_budget.py` to split the resource-derived budget between their lanes and suites with the internal `ORBIT_CHECK_PROCESSES` variable. A positive integer lane share is used verbatim, and an invalid share fails the check. Do not set it on a Project or in an operator shell. CI test commands bypass this local gate budget. The hosted Gateway Pest job explicitly uses four workers, as [Gateway shards](#gateway-shards) describes. CI quality tools still use this helper.

### The candidate gate

Root `composer check` builds OpenAPI, navigation, flow data and the documentation context index, then runs `bin/review-check`, the candidate gate. This refreshes ignored outputs from the current checkout before the impact and freshness checks read them.

The Orbit Project uses it as its task check, so it runs in the task VM at every subtask handoff. The gate runs only fast checks and no test suite. CI omits the Project check and runs the selected jobs in [CI](#ci), including Gateway, CLI and SDK Pest when their inputs change. Required checks must pass on the final head before merge.

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

The `Swamp shape gate` and `Swamp workflow validate` jobs of CI call one script, `bin/swamp-shape-gate`. CI selects `--only control` for static shape rules. The candidate gate uses the full shape group. [Main full set](/reference/main-full-set) runs the fixtures after merge. Commit your change first: the checkers compare committed `HEAD` with the base.

```bash
bin/swamp-shape-gate                # shape and validation, against the merge base with origin/main
bin/swamp-shape-gate --base REF     # compare with another base
bin/swamp-shape-gate --only shape   # or --only validate
```

The script installs the linters into a virtual environment under `~/.cache/orbit-shape-gate`, keyed by their pins, and reuses it. `swamp/ansible/shape-gate-requirements.txt` is the only place that names the pins; the workflow names none. It sets umask 022, as CI has, then runs the checks in this order: the Ansible phase checks, the shape regression Deno tests, `check-swamp-shape.ts`, `check-swamp-base-policy.py`, `lint-behavior-names.ts` and the frozen agent command check.

Independent commands share up to four workers, with one worker inside each command. The Instance host fixture files run in two serial groups at the front of the queue: updates and ACL cases in one; host, image and runtime cases in the other. At most two Instance host playbooks run at once, leaving workers for the other checks. The updates and ACL cases measured 356 and 257 seconds on ci-shark-2, or 613 seconds together. The image/runtime group must not also carry those ACL cases.

Each playbook has a four-minute hang guard. Before launching a fixture command, the gate sets `ORBIT_INSTANCE_HOST_DEADLINE_MS` to the current epoch time in milliseconds plus twelve minutes. Every test file reads that same deadline; a late playbook gets only the time left. A file run outside the gate defaults to twelve minutes from its helper import. The updates and ACL sample leaves 107 seconds in its group budget; this margin depends on runner load. Cleanup and output collection take at most five more seconds.

An earlier ci-shark-3 calibration measured 503.7 seconds for the slower group in a 897-second job. Setup and the other checks added `ceil(897 - 503.7) = 394` seconds. Its stall estimate is `720 + 5 + 394 = 1119` seconds (18m39s), leaving 1m21s below the twenty-minute target and 11m21s below the unchanged thirty-minute workflow limit. This estimate depends on measured runner overhead; it is not a hard bound on unrelated setup or checks.

A Python child subreaper owns each playbook from launch. Detached workers and orphans reparent to it. On deadline it sends TERM to its children, waits one second, then repeatedly kills and reaps children, including those adopted during cleanup. Signals use pidfds. Output collection also has a deadline. A timeout fails the case with the captured output, including its last task.

The gate prints each command's start and reports its result as soon as it finishes. It runs every step, prints a summary, and exits 1 when any step failed. It retries nothing. The JSON reports go to `--reports DIR`, by default a per-user directory in the temporary directory.

The shape checker resolves a constant quoted CEL child-workflow name and scans that child just as it scans a literal target.

A task VM holds the same environment in `/opt/orbit-shape-gate`. The task VM role playbook builds it for the pins of its release. The script uses it when its name matches the pins, so the check runs offline.

Workflow validation needs a Swamp account and reads only `SWAMP_API_KEY` and `SWAMP_SIGNIN_TOKEN`. Without them, the script skips the workflow validation, the Node enrollment trust test (it imports the extensions that validation pulls) and the grant pattern check (it also needs PHP). It names each skipped step and still exits 0. `--only validate` exits 78 then, which the candidate gate reads as skipped. CI passes `--strict`: a skipped step is a failure there.

CI gives the credentials to this one step on pull requests, on main pushes, on the three-hour schedule and on the push of a `task-*-handoff` branch by the Orbit App (`orbit-nckrtl[bot]`), so a task handoff that changes `swamp/` can pass hosted CI. It never gives them on `pull_request_target` or to a fork. Decided by the orchestrator, for Nick's review: the push of a handoff branch by the App.

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

The task gate and CI serve different scopes. The task gate runs `bin/review-check`; CI omits that Project check and runs the selected builds, static checks and Gateway, CLI and SDK Pest. A passing Required checks result therefore does not certify every local Project check.

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

### Gateway Pest gates selected changes

The Gateway default suite runs in one hosted job. Four workers keep setup costs low; there are no shards or quarantined files. The CLI suite has its own hosted job. Other PHP suites remain local checks.

### Full suites gate merges

The check name remains `Required checks` so branch protection, task handoff CI and Gateway auto-release continue to resolve it. The name represents the selected builds, static checks and Gateway, CLI and SDK Pest suites in [CI](#ci). A green result requires every selected Pest job to pass. No ruleset change is needed for Orbit.

### Gateway test time limits

Gateway uses stock PHPUnit per-test limits: `enforceTimeLimit="true"`, `defaultTimeLimit="2"`, `failOnRisky="true"`, and `stopOnRisky="true"` in `apps/gateway/phpunit.xml`. The default is two seconds locally. Tests remain unsized except for reviewed cases whose real deadline or kernel age is the behaviour under test.

CI passes `--default-time-limit=5` to the Gateway suite and its privileged cases. Local Gateway runs keep the two-second default. Both retain failure and stop-on-risky behavior. Fixtures for large row limits insert batches that fit SQLite's parameter limit while still storing every row up to the production limit.

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

The development mail-line fixture runs unprivileged. It executes the shipped byte rule against temporary files through a fake Incus boundary. Its inventory and named case costs change together, using `bin/ci-units collect --file` after each fixture edit.

The migration mail fixture also checks the phase order: copy first, bounded mail attempt second, application starts last. A failed attempt retains the copied file and produces a warning receipt.

The destroy dependency fixture uses real Git in private temporary directories, runs unprivileged and launches no Ansible playbooks. It covers confirmed dependents, moved and prunable worktrees, unreadable candidates, malformed rows and named target failures without scanning checkout contents. Gateway tests bind a deterministic remote-program fake by default and prove an admission refusal leaves serving records untouched. Inventory and named cost keys are collected together.

Clone branch fixtures use unprivileged local Git repositories and run without an Ansible playbook. Their registered case names and conservative costs change together in `tools/tests/ci-fixture-cases.json` and `.github/ci/checks.toml`; collect names with `bin/ci-units collect --file swamp/tests/instance_clone_test.py`.

When a fixture gains cases, collect its registrations with `bin/ci-units collect --file PATH` and update its inventory and cost keys together. Include the production inputs the new cases exercise. The Ansible structure test also checks parsed command argument types before module validation can coerce them.

The migration source fixture runs one unprivileged Ansible play, fakes Git and timeout without network calls or sleeps, and checks credential removal on success and refusal. Migration fixtures check that untracked files survive copying and retirement, counting only files Git does not ignore. Tracked changes, unpushed commits in protected refs, owner stashes and owner dependencies keep their refusal codes.

A linked checkout can move before its owner; a registered descendant still refuses. Linked fixtures verify file retention, independent Git recovery and exact unregister. Scope fixtures run the shipped retirement tasks on real Git repositories: linked checkouts protect their own HEAD while owners protect every branch and stash. An interrupted unregister keeps an incomplete-retirement failure on repeat. Batch phase plays in one Ansible invocation to keep each fixture within three playbook launches; update registered case names and costs together.

The mail fixture executes the production prefix whitelist, the reviewed bare-CR and comment-grouping regressions, and a realistic thirty-line file. It preserves `lines` beside `guard_line` and uses three Ansible launches. Gateway tests compare every admitted shape with installed phpdotenv; Swamp fixtures need no PHP or Composer installation.

The Route site fixture runs unprivileged. It checks the shipped file converger and real OpenSSL CSR action, and runs one real Ansible production phase for workspace access, `APP_URL`, environment links and PHP-FPM publication. Other cases exercise validation failure and retry without repeating playbook launches.

The destroy guard is admission only. Tests pin the workflow to its pre-guard main bytes and prove retry/dispatch does not recheck Git after dismantling begins. Candidate pointer failures retain named warnings; target failures and confirmed dependents refuse before record or trigger changes.

The container Schedule fixture checks timer installation and advisory activation with three Ansible launches. It executes the journal module directly for byte and transport limits. Its declared cost includes startup; inventory and case costs change together.

Private Node-runtime fixtures use the shipped conversion and fetch helper with a fake Vite+ command; network downloads are tested separately in an owned fixture Instance.

The Caddy candidate fixtures execute each changed writer with invalid and valid candidates. They keep every path and stand-in under a private temporary directory, run Ansible without facts or privilege, preserve relative-import directories and prove invalid input leaves live bytes unchanged with no reload.

Node service endpoint fixtures run unprivileged in the existing migrate environment unit. They use temporary guest files and bounded stand-ins for Incus/systemd; one pinned-Ansible run proves the dedicated tag accepts minimal public intent without workspace fields. The helper is measured separately on fixture containers for real networking, source address and idle connection survival.

The `instance:restore` proof owns a disposable PostgreSQL server and runs alone. Its active logical replication slot exists before destroy and remains active through restoration.
