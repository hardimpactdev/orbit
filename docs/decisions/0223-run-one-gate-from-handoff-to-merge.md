---
title: "ADR 0223: Run one gate from handoff to merge"
sidebarTitle: "0223 One gate"
description: "In progress. The Gateway runs one local gate per handoff, from the subtask start commit. The implementer runs only the affected tests. CI on the pull request merge ref is the only integration gate, so the local main-merge gate goes. One definition of the checks, one cache transport, and one process rule serve CI and the local gate."
---

# ADR 0223: Run one gate from handoff to merge

Between "the implementer says done" and "the pull request merged", a task change passes up to five gates that repeat the same work. This ADR keeps two: one local gate per handoff, which the Gateway runs, and CI `Required checks` on the pull request merge ref. The implementer runs only the affected tests of the projects it changed. The handoff tests from the subtask start commit. The local main-merge gate, the `project-check` deliverable, the artifact cache worker, and the copied check lists go.

## Status

In progress.

Principle: [One way, one name](/mission#principles). One local gate, one integration gate, one definition of the checks, one cache transport, and one rule for the process count. It also serves [Lean](/mission#principles): about 17 hours of main-merge gates in five days, and the implementer's duplicate run, are deleted. No principle exception is proposed.

Nick approved this decision on 2026-10-18. He left one choice open in item 3, between two test bases. This ADR chooses the subtask start commit, from the data below.

## Context

The data comes from the Gateway `task_checks` table: 321 checks from 2026-10-13 to 2026-10-18. A read-only review of the code merged in that week measured it on 2026-10-18.

| Stage | 10-13 | 10-14 | 10-15 | 10-16 | 10-17 | 10-18 |
| --- | --- | --- | --- | --- | --- | --- |
| Baseline, median | 1058 s | 345 s | 300 s (p90 1675 s) | 120 s (cold: 1253 s) | 62 s | 74 s |
| Handoff, median | 175 s (n=15) | 1669 s | 902 s | 871 s | 191 s (n=3) | 1466 s |
| Main-merge gate (`base_merge`), median | – | – | 1135 s | 803 s | 2311 s (n=1 real) | no-op |

- The checks used 85.8 hours of task VM time in five days: handoff 58.5 h, `base_merge` 16.8 h in 69 runs, and baseline 10.5 h.
- The `base_merge` total excludes two rows, 1773 and 1790, of 5.6 h and 3.5 h. Their gates passed in about 20 minutes, and the rows closed the next morning, so that time was a Gateway stall. The task-lifecycle review counted 22.4 h of `base_merge` with those rows in part; this ADR uses the check-time figure.
- Gateway `composer test:affected` is the cost driver. Its median over all checks is 812 s.
- E2E `test:affected` has a median of 161 s. Gateway `composer check` has a median of 24 s.
- The agents are fast. A subtask reviewer approves in a median of 2.5 minutes and requests changes in 4.6 minutes. A final review takes 7 minutes.
- PR CI takes a median of 6 minutes, with a p90 of 15 minutes. A task pull request goes from open to merged in a median of 11 minutes.
- So almost all of the wait is the local gates.

### The same work runs several times

A handoff in a review-and-merge Project passes these steps today:

1. The implementer runs the full gate. `TaskTurnInstructions::implementer` tells it to hand off when "`<check>` passes".
2. The Gateway runs the same gate on the same tree at handoff.
3. The subtask reviewer reviews. It does not run the gate.
4. Before the final review, Orbit merges main and runs the full gate on the merge (`base_merge`).
5. When that gate fails, `classify_merge_gate` runs the gate again on each merge parent, in a fresh worktree.
6. Before the push, Orbit merges main again and runs `base_merge` again.
7. CI `Required checks` runs on `refs/pull/N/merge`, the same merged tree.

Measured cases:

- Tree `8cbe69bd`: the implementer's own gate ran Gateway `test:affected` for 2228 s. Handoff 1853 then ran the same command on the same tree for 2888 s.
- Tree `3bbd50f0`: 67 s by the implementer, then 85 s in handoff 1870.
- The `base_merge` gate passes no test base. So `bin/review-check` selects every change since the graph that the baseline imported: all main commits merged in, plus the whole branch.
- The parent runs copy only `vendor` and `node_modules`. `.orbit-tia/` is ignored by Git, so each parent run records a fresh graph with the full suite. Check 1842 took 2697 s, and check 1786 took 1808 s.
- The implementer's concurrent full-gate runs caused the OOM on 2026-10-17.
- #1216 added the `project-check` deliverable reuse (`matches_task_check`, `TaskFinalReview.php:114`, `TaskSettlingFixup.php:122`) only so that this deliverable does not run the gate a third time.

### The rework test base

#1235 (2026-10-15) made a rework handoff test from the tree of the last passed handoff. Pest walks only commits, so `bin/review-check` wrote the tree into Pest's graph, and Pest rebuilt the graph with the full suite.

- After #1235, rework handoffs had a median of 1427 s (n=32). First handoffs had a median of 480 s (n=68).
- The same subtask shows it: 1797 ran 167 s and its rework 1799 ran 1107 s. 1780 ran 145 s and 1793 ran 1152 s.
- Handoffs 1863 and 1864 changed eight documentation paths and no Gateway file. Gateway `test:affected` still ran 1919 s and 1926 s.
- About 9 hours of VM time were lost in 3.5 days.

#1335 fixed it by writing a content-hash snapshot of the tree into Pest's graph, with a PHP program in a Python string that calls `\Pest\Plugins\Tia\ContentHash`. It works, but it hand-writes the private format of the Pest fork that Orbit owns. The bug came from exactly that layer.

- After #1335, rework 1874 took 550 s. Gateway `test:affected` took 439 s of it, on one process. Earlier handoffs of the same subtask took 2332 to 2491 s.
- That is one sample. Its selection size was not read, because its receipt was gone with the VM.

### Copies, transports, and settings

- **Three copies of the check list.** The architecture tests are listed in `bin/review-check:25-45`, in `.github/workflows/ci.yml:321-324`, and in `apps/e2e/tests/Fixtures/tia-cache-tests.py:1458-1470`. The test copies the implementation instead of checking it against CI. #1234 exists because two copies drifted.
- **Two cache transports.**
  - Workspaces import `refs/orbit/ci-cache/<sha>` (#1226).
  - Local stores still use the `gh` artifact worker in `bin/tia-cache` (1020 lines): `refresh`, `drain`, the queue and worker locks, and the registry.
  - The worker keeps a ledger of failures on main that repeats GitHub's CI status.
  - On task VMs, the gate's `tia-cache seed` step does nothing.
- **Exact-commit caches only.** `apps/gateway/resources/instances/ci-caches.sh` imports only the ref of the exact baseline commit. When CI has not yet published that commit, the baseline records a fresh graph: 1770, 1771, 1775, 1821, and 1822 took 1253 to 1677 s against about 70 s warm. Pest accepts any ancestor graph.
- **A process override.**
  - Since 2026-10-17 the Orbit Project's task check is `ORBIT_CHECK_PROCESSES=1 composer check`, after an OOM.
  - Every check now runs one lane and one Pest process. Gateway affected runs take about twice as long.
  - The default in `bin/check-processes` and `tools/check-processes.php` allows four workers of up to 1 GB. Its comment cites 2 GB VMs.
  - A normal task VM on beast and shark has 2 CPUs and 4 GiB.
  - A medium task VM has 4 CPUs and 8 GiB.
  - A large task VM has 6 CPUs and 12 GiB.
  - A medium VM's topology takes about 2.7 GiB of that memory.
- **An alias.** `bin/test` still reads `ORBIT_TEST_PROCESSES`.
- **A Project special case.** `TaskSubtaskRunner::handoffCheck` sends `test_base` only when `$group->project->slug === 'orbit'`. The engine must stay generic.
- **A result written on the read side.**
  - `check` raises `SystemExit` on SIGTERM, and `run()` catches only `Exception`. So a cancelled supervisor writes no result.
  - `status()` then builds a failed `causality_uncertain` result from systemd and cgroup state. That result is what releases the workspace lease after the supervisor dies.
  - The start and status path without a run ID is still present, although every caller passes a run ID.
- **Lost evidence.**
  - The Gateway keeps a 16 KiB output tail. In 123 of 321 checks the line of the slow step is cut.
  - The receipt `result.json` holds the seconds and selection of each command, but it lives only on the VM.

## Decision

The decision has eight parts. They change the Gateway, the root `bin` and `tools` scripts, `.github/workflows/ci.yml`, the agent guidance, and the Pest fork `nckrtl/pestphp-monorepo`.

### 1. One local gate per handoff

- The Gateway handoff check is the only local run of the Project check in a task.
- `TaskTurnInstructions::implementer` no longer says "and `<check>` passes". It says that Orbit runs the Project check when the implementer hands off.
- The engine stays generic. The Orbit repository's guidance tells a task implementer to run `composer test:affected` in each project it changed, and not to run `composer check` or `bin/review-check`. Work outside a task keeps the full checks, because no Gateway gate runs for it.
- A failed handoff check returns to the implementer as a reminder with the end of the output, as today.
- A failed handoff check gets its own budget: three failed handoff checks in a row for one subtask. The fourth asks for assistance. A passing check resets the count. Today one reminder per `completion_attempt` is allowed, so without this budget two failed handoffs in a row would stop the group.
- The `project-check` deliverable goes. Every settling fixup gets the `review` deliverable `fixup-review`, also when the Project has a task check. A final-review fixup and a GitHub-review fixup keep their `review` deliverable. Their handoff check runs the Project check, as every handoff does.

### 2. CI on the merge ref is the only integration gate

- Orbit runs no Project check on a main merge. `TaskCheckKind::BaseMerge` goes.
- Orbit does not merge main before a final review. The first final review still starts at the merge base of `HEAD` and `origin/{default branch}`, so it reviews the whole branch.
- Orbit does not merge main before a push.
- In a review-and-merge group, Orbit never merges main itself. Only the implementer merges main, and only to resolve a conflict.
- When GitHub reports that the pull request conflicts with its base, the group gets the settling fixup `Merge origin/{base}` from `TaskSettlingFixup::plans`. `healOpenPullRequest` already plans it for every group. The implementer merges and resolves. Its approval waits for a final review, as every approval in such a group does.
- A failed `Required checks` already creates a `check:*` fixup. Its "merge the base first" variant (`mergingBaseFirst`) goes. CI tested the merge ref, so main at that time was already in the tested tree.
- Merge on green requires final-review evidence on the head. A reviewed commit with source `base_merge` no longer exists, so a head inherits no review.
- Review and merge supports only base branches that do not require an up-to-date branch. Orbit's `main` does not: its ruleset has `strict_required_status_checks_policy: false`.
- `project:update --review-and-merge=true` reads both sources of branch rules, because GitHub applies rulesets and classic branch protection together:
  - the rulesets, with `GET /repos/{owner}/{repo}/rules/branches/{branch}`, which needs `Metadata: read`;
  - the classic branch protection, with `GET /repos/{owner}/{repo}/branches/{branch}/protection`, which needs `Administration: read`. A 404 there means that the branch has no classic protection.
- It refuses when either source requires an up-to-date branch: `strict_required_status_checks_policy` in a ruleset, or `required_status_checks.strict` in classic protection.
- It also refuses when it cannot read either source, because then the policy is unknown.
- The protection read needs `Administration: read`. [ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role) plans `Administration: write` on the same App, for runner registration. Write covers read, so both ADRs share one permission change:
  - Whichever ADR's step builds first adds `administration: write` to the App manifest, once.
  - Nick accepts that change on the installation, once.
  - The other ADR reuses the same grant. It adds no read-level permission and never lowers the grant to read.
  - The token for the protection read is minted with only `administration: read`. Runner tokens keep only `administration: write`.
- An installation that has not accepted the grant cannot turn review and merge on until it does. Projects that already have it on are not read again.
- Rules can change after that. When GitHub refuses a merge because the branch is behind, Orbit records `refused`, merges nothing, and asks for assistance once, with that reason. So the pull request never waits without a signal.
- Projects without review and merge keep GitHub's branch update, as today.

When main moves after CI ran on a merge ref, the App can merge a head whose merged tree CI did not test. Today the same gap exists after the last local merge gate. The `push` run of CI on `main` tests the merge commit, and [Failures on main](/reference/implementation-loop#failures-on-main) and the red-main [baseline retry](/reference/tasks#baseline-check) handle a red result.

### 3. Test each handoff from the subtask start commit

Every handoff of a subtask, first or rework, tests from the start commit of that subtask. Orbit uses no handoff tree as a test base.

The Pest fork that Orbit owns gets one documented option, `--tia-base`, with two forms:

- `--tia-base=graph` selects the tests that the graph's edges link to files changed since the commit at which the graph was recorded.
- `--tia-base=COMMIT` selects the tests that the graph's edges link to files changed since `COMMIT`.
- Both forms include uncommitted and untracked files.
- Both forms read only the graph's edges. They ignore every stored branch baseline, its `lastRunTree`, and its results. So the implementer's own `composer test:affected`, which writes a branch baseline into the graph, cannot narrow the gate.
- The fork already computes the changed files of a commit in `ChangedFiles::since`. The option names the commit and skips the baseline replay.

`--tia-base=COMMIT` is valid only when the graph's edges are current at `COMMIT`. A graph recorded at an older commit has stale edges for the files changed between that commit and `COMMIT`. So `bin/review-check` chooses the form:

- It passes `--tia-base=COMMIT` only when its graph comes from a passed run on the tree of `COMMIT`. That graph is the handoff cache entry for that tree.
- In every other case it passes `--tia-base=graph`. That covers the baseline check, a handoff without a matching cache entry, and a local `composer check`.
- So the baseline check selects every change since the imported graph's commit. Its run refreshes the edges for those changes.
- When the imported graph is older than the start commit, the baseline runs the tests of the changes between them. A red main shows there.
- When the imported graph is at the start commit, the delta is empty and the baseline runs no tests. That is correct: CI publishes a cache ref only after `Required checks` passed on that exact commit.
- A red main commit gets no cache ref, so its baseline imports an older ancestor and tests the changes since then.
- When no graph fits, the fork records a fresh graph with the full suite, as today.
- `bin/review-check` no longer writes Pest's graph.

The handoff cache stays: one slot, matched by tree, holding the graph after a passed run.

- A passed baseline fills it for the start commit's tree, so the first handoff can test from the start commit.
- A passed handoff fills it for its tree. When subtask N+1 starts at the tree of subtask N's last passed handoff, the cache gives it current edges for the code the group added.
- After item 1, only the Gateway's checks write it, because the implementer no longer runs `bin/review-check`.

The data does not show what a tree base saves. The only rework measured after #1335 is one sample, on one process, with an unread selection. So the choice rests on simplicity and a bounded cost:

- A rework selects the set of its first handoff plus the files that the rework changed.
- The first-handoff median is 480 s (n=68). Most of those ran before the override, with 2 processes on a normal VM.
- At that median, the 32 reworks of the measured 3.5 days cost about 4.3 h in total. A correct tree base saves at most part of that 4.3 h. No data shows how much.
- The commit base needs one option on code that exists in the fork. A tree base needs content-hash snapshots of a tree inside the fork, more fork code, and more fork tests.

### 4. One definition of the checks

- One file, `tools/checks.json`, lists each check: its project directory, its commands, its architecture tests, the paths that select it, and where it runs.
- A check runs locally, in CI, or in both. The Rust agent runs only in CI.
- For a PHP project, the file holds `composer validate --strict`, `composer check`, and the architecture tests, which both gates run. It also holds `composer test:affected`, which only the local gate runs.
- The test step of a PHP project in CI stays in `ci.yml`, because CI runs sharded Pest with its own TIA plan.
- `bin/review-check` reads it.
- Path triggers select only the checks that TIA cannot narrow: the web app, the Pi server, the generated artifacts, the fixtures, and the extra architecture triggers.
- Path triggers never drop the commands of a PHP project. Every local gate, the baseline included, runs the commands of all five PHP projects, as `selected_projects` does today. TIA narrows their tests.
- A handoff and a local `composer check` run a triggered check when a changed path matches its triggers.
- The baseline check uses no path triggers. Its branch diff is empty, so triggers would select nothing.
- CI reads the same file through `bin/review-check`.
- A CI job runs a check's commands with `bin/review-check run CHECK`.
- The architecture-test step runs `bin/review-check architecture PROJECT`.
- CI path conditions read the triggers from the file.
- CI runs every check that the file marks for CI.
- Runner choice, Gateway shards, process counts, and the TIA plan stay in `ci.yml`, because only CI has runners.
- The E2E configuration test checks that every check job in `ci.yml` runs its commands through `bin/review-check`. It no longer holds its own copy.

### 5. One cache transport: Git refs

- CI cache refs, `refs/orbit/ci-cache/<sha>`, are the only cache transport.
- A checkout imports the ref of the nearest first-parent ancestor of its baseline commit that has a ref. It never imports a newer commit.
- Pest selects the changes since that commit. So a baseline is not cold for lack of a ref while any ancestor ref is retained.
- A changed TIA fingerprint, such as a new `composer.lock` or Pest configuration between the ref and the baseline, still forces a full run, as it does today.
- The Gateway sends that ref to a workspace, as it sends the exact ref today.
- A local worktree fetches the refs from `origin` and imports its own nearest ancestor ref with `bin/ci-cache-ref import`. `bin/bootstrap` and `bin/worktree-cache` call it for the graph and the Pint and PHPStan caches.
- The shared main cache store under `orbit-tia/v1` goes, with its seed, its registration, and its refresh.
- `bin/ci-cache-ref` takes over the code it loads from `bin/tia-cache` today: `import_ci`, the graph validation, the checksums, the artifact layout, and the ancestor check.
- The artifact worker, its queue, its registry, and its failure ledger go. GitHub's CI status on `main` is the only record of failures on main. The merge skill reads that status instead of `bin/tia-cache status --remote`.
- CI keeps its artifacts only to stage the exports for `publish-ci-cache` in the same run.

### 6. Derive the process count from cores and memory

- `bin/check-processes` prints the smaller of two numbers, and at least 1: the core count, and the available memory divided by the memory of one worker.
- It reads the available memory when the check starts: the cgroup's `memory.max` minus `memory.current` when the cgroup has a limit, or `MemAvailable` otherwise. It reads the cgroup CPU limit the same way.
- Available memory already leaves out what the agent, the system, and a running topology use. So one rule fits every VM size, with or without a topology.
- Step 2 measures the peak memory of one worker and sets the per-worker value from that measurement.
- On today's VM sizes without a topology, the core count decides: 2 on a normal VM and 4 on a medium VM. The OOM on 2026-10-17 came from the implementer's concurrent full-gate runs, which item 1 removes. The memory term matters where memory is short for the cores.
- When `ORBIT_CHECK_PROCESSES` is set, `bin/check-processes` prints it, because it is the lane share below.
- `tools/check-processes.php` runs `bin/check-processes` instead of its own copy of the rule.
- `ORBIT_CHECK_PROCESSES` stays only as the share that `bin/review-check` and `bin/test` pass to each lane or suite. No Project or operator sets it.
- CI keeps its counts per runner type in `ci.yml`. Shard runners share a host on purpose and see its cores ([ADR 0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role)).
- The Orbit Project's task check goes back to `composer check`.
- `ORBIT_TEST_PROCESSES` goes.

### 7. Every Project gets the test base

`TaskSubtaskRunner::handoffCheck` exports `ORBIT_TASK_CHECK_BASE`, the subtask start commit, to the handoff check of every Project. The `slug === 'orbit'` case goes. A Project that does not read the variable is unaffected.

### 8. Check results and evidence

- The check supervisor writes its own cancelled or failed result. It handles SIGTERM, SIGINT, and SIGHUP, stops its children, and writes `check.json` before it exits.
- The supervisor also checks the tree after its last descendant stops. Today `status()` does that check.
- A managed run that dies without a result, such as on SIGKILL, reads as a terminal `lost` once systemd proves it ended: the exact unit is inactive or failed, and its cgroup is empty.
- The workspace lease releases its holder on a terminal `lost` and marks the interval causality uncertain. Today only a `finished` reading releases it, so this replaces the result that `status()` writes today.
- Then the retry in `TaskSubtaskRunner` starts a new check, as it does for `lost` today. A second `lost` run asks for assistance, as today.
- A `lost` reading without that proof keeps the holder, as today.
- A reboot still reads as `interrupted`. An unreachable VM gives no reading, and the holder stays.
- `status()` only reads results.
- Every check start has a run ID. The path without a run ID goes, and `RemoteTaskCheckRunner::start` requires the run ID.
- The check exports `ORBIT_CHECK_RECEIPT`, a file path.
- A Project check may write a JSON list to that file: for each command, its name, its seconds, its exit code, and a free `detail` text.
- Orbit stores the list in a new JSON column of the check row.
- Orbit's `bin/review-check` writes the list from `result.json`, with the selected test count as `detail`.
- The 16 KiB output tail stays.

### Build order

Each step is one small pull request. Docs change with the code in the same pull request.

1. **One local gate.** Change the implementer instruction. Change the guidance in `AGENTS.md`, `apps/cli/AGENTS.md`, `apps/docs/AGENTS.md`, and the `developing-features` and `orbit-tasks` skills. Add the failed-check budget.
   - Delete the `project-check` deliverable in `TaskFinalReview.php:114`, `TaskSettlingFixup::deliverables`, and `TaskSettlingFixup::reviewPlan`, with its fixture in `gateway-response-fixtures.md`.
   - Delete `matches_task_check` and the `task_check` reuse in `verify_deliverables` of `check`.
   - Delete the reuse note in `TaskCheckRunner` and the `project-check` paragraph of [Project check](/reference/tasks#project-check).
2. **Process count.** Change `bin/check-processes` and `tools/check-processes.php`, with the measured per-worker memory. Then set the Orbit Project's task check to `composer check`.
   - Delete `DEFAULT_LIMIT` and its 2 GB comment, and the 2 GB reason in `implementation-loop.md:737`.
   - Delete the PHP copy of the rule.
   - Delete `ORBIT_TEST_PROCESSES` from `bin/test`, `implementation-loop.md`, and `environment-variables.md`, and the operator text for `ORBIT_CHECK_PROCESSES` in `implementation-loop.md` and `environment-variables.md`.
3. **No local main-merge gate.** The enum casts on `TaskCheck::kind` and `TaskReviewedCommit::source` refuse an old value, so a migration removes every `base_merge` value:
   - It deletes every `base_merge` check row, of open and ended groups.
   - It converts every `base_merge` reviewed commit to `orbit_push`. The head of an open group may be such a commit, and it inherited its review, so `mergeWhenReady` keeps accepting it.
   - Deploy it when no `base_merge` check is running.
   - Add the strict-base refusal to `project:update`, with both rule reads. When ADR 0213's registration through the GitHub App has not added `administration: write` to `GitHubAppManifestPage` yet, this step adds it, and the [GitHub App](/reference/github-app) page names both uses.
   - Add the assistance on a behind refusal to `mergeWhenReady`.
   - Delete `TaskCheckKind::BaseMerge` and `TaskReviewedCommitSource::BaseMerge`.
   - Delete `TaskPublication::appendBaseMerge`, `prepareBaseMerge`, `queueBaseMergeRetry`, `appendAgentBaseMerge`, `appendDeltaReview`, and `conflictOutput`, with the repair, retry, and lost states of the gate.
   - Delete the main merge before the final review and before the push, and their callers: `StoreTaskCommentAction`, `TaskReviewOutcome` (`:303`, `:473`, `:489-493`), `TaskSettling` (`:110-121`), `TaskSubtaskRunner` (`:269`), `TaskScheduler` (`:231-249`), `TaskWorkspaceLease` (`:521`), and `TaskReviewBase` (`:58-73`), with their test fakes.
   - Delete `TaskBaseMerge` and `TaskBaseBranchFetcher::mergeDefault`, which only the gate uses.
   - Delete `TaskBaseBranchFetcher::overlappingPaths`, whose callers are gate paths (`TaskPublication.php:148`, `:409`), and `TaskPullRequestMerger::baseTipGreenAhead`, whose only caller is `TaskSettling.php:715`. Delete their implementations and fakes (`TaskSchedulerTickFixtures.php:632-637`, `ReviewAndMergeTest.php:264-269`).
   - Delete the `base_merge` keys in the docblocks of `RemoteTaskCheckRunner.php:57` and `TaskCheckRunner.php:30`.
   - Delete the `base_merge` values in `docs/openapi.json` and `apps/web/src/api/schema.d.ts`. Regenerate them with `bin/docs-openapi`, `bin/mcp-tools`, and the web types.
   - Delete `TaskSettlingFixup::mergingBaseFirst` and its caller in `TaskSettling`.
   - Delete the delta-only final review (`TaskFinalReview::BaseMergeReview`) and its cap.
   - Delete `classify_merge_gate`, the `base_merge` mode, and `base_merge_passed_tree` in `check`.
   - Delete the `behind` branch of `mergeWhenReady`.
   - Rename the `main_merge` lease kind of `prepareResumedWorkspace`, which then only fast-forwards.
   - Delete the docs of the main-merge gate in these sections of [Tasks](/reference/tasks): Final review, Every push is reviewed, Records and status, Fix a settling pull request, Review fixup lifecycle, the opening rules of Review and merge, item 1 of Merge on green, and "Orbit merges only what it reviewed".
4. **Start-commit test base.** First a release of the Pest fork with `--tia-base=COMMIT` and its tests, including one with a dirty branch baseline. Then bump the fork in each `composer.lock`, and pass the subtask start commit to every Project.
   - Delete the tree case of `task_test_base`, `scope_tia_graph`, `scope_tree_baseline`, `comparison_tree_snapshot`, the `TREE_BASELINE_*` messages, and the embedded PHP program in `bin/review-check`.
   - Delete `keep_main_baseline`, because both forms of `--tia-base` ignore branch baselines.
   - Fill the handoff cache after a passed baseline too.
   - Delete the passed-handoff-tree rule of `TaskReviewBase::testBase` and the `slug === 'orbit'` case.
   - Delete the "A handoff tree is not a Pest commit" and "The gate selects against main" sections of [Feature delivery](/reference/implementation-loop#why-it-works-this-way).
5. **One check definition.** Add `tools/checks.json` and the `run` and `architecture` commands.
   - Delete `ARCHITECTURE_TESTS`, `ARCHITECTURE_TRIGGERS`, `WEB_CHECK_COMMANDS`, `PI_SERVER_COMMANDS`, and the other check and trigger constants in `bin/review-check`.
   - Delete the `case` list in the architecture-test step and the copied commands and path conditions of the check jobs in `ci.yml`.
   - Delete the copied list in `tia-cache-tests.py`.
6. **One cache transport.** Move the shared code into `bin/ci-cache-ref`. Import the nearest ancestor ref in workspaces and local worktrees. Switch the merge skill to GitHub's CI status.
   - Delete `bin/tia-cache`, with `refresh`, `drain`, `seed`, `register`, `status`, the queue and its locks, the frozen runners, the registry links, and the failure ledger.
   - Delete its callers: the seed in `bin/bootstrap`, the refresh in `bin/worktree-remove`, the store lookup in `bin/worktree-cache`, the tool check in `ci-caches.sh`, and the `status --remote` step of the `merging-pull-requests` skill.
   - Delete the `tia-cache seed` step of the gate and the exact-SHA rule of `ci-caches.sh`.
   - Delete the `bin/tia-cache` tests: `apps/e2e/tests/Unit/E2E/TiaCacheTest.php` and the worker tests in `tests/Fixtures/tia-cache-tests.py`. That fixture also holds the review-check gate tests, so move them to a fixture of their own.
   - Change the tests that call `bin/tia-cache`: `WorktreeLifecycleTest.php:30`, `TestDatabaseGateTest.php:36`, `BuilderGateSelectionTest.php:105`, `ComposerConfigurationTest.php:371`, and the Gateway tests `CiCacheSetupTest.php` and `TiaBaselineSetupTest.php`.
   - Delete the `bin/tia-cache` comment in `bin/tooling-baseline:18`.
   - Delete [Refresh from CI](/reference/implementation-loop#refresh-from-ci), "Only main CI publishes", "Maintenance runs in the background", "Clones find the store through a registration", and the worker text of [Failures on main](/reference/implementation-loop#failures-on-main), with the text on legacy failure records. Failures on main keeps one line: GitHub's CI status is the record.
7. **Check results.** The supervisor writes its result and checks the final tree. `status()` reads a proven-terminated run as a terminal `lost`, and `TaskWorkspaceLease` releases on it. The check row stores the receipt list.
   - Delete the terminal-result synthesis and the after-result tree check in `status()`.
   - Delete the start and status path without a run ID and the "Legacy check process identity" match in `managed_run`.
   - Delete the optional `$runId` of `RemoteTaskCheckRunner::start`, of the `TaskCheckRunner` interface (`TaskCheckRunner.php:35`), and of `FakeTaskCheckRunner`.
8. **Close.** Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **Keep the main-merge gate, with a test base.** It still repeats CI on a slower machine, after CI already tests the same merged tree on four runners. The parent classification and the repair and retry states stay.
- **Keep the implementer's full gate and skip Orbit's.** Orbit cannot trust an agent's report of its own run. The deterministic check must run where Orbit records it.
- **A base-tree option in the Pest fork.** A rework would select fewer tests. No data shows how many fewer. It needs content-hash snapshots of a tree in the fork.
- **Keep the tree base with the graph writes of #1335.** It edits the fork's private format from outside the fork, and that layer caused the 40-minute checks.
- **CI runs `bin/review-check` as one job.** CI would lose its runner choice, its Gateway shards, and its parallel jobs, which keep a PR at a median of 6 minutes.
- **Keep the artifact worker for local stores.** It is a second transport, a second failure record, and 1020 lines for caches that the Git refs already carry.
- **Exact-commit caches only.** Five baselines ran cold for 1253 to 1677 s in five days.
- **Require an up-to-date branch.** Each merge that follows a move of main would need one more main merge, pushed by Orbit, and one more CI run. That brings back the mechanical main merges that item 2 deletes.
- **GitHub merge queue.** It tests merged trees in order and needs no up-to-date branch, so it would close the semantic-conflict gap below. GitHub offers merge queues only for repositories that an organization owns, and `nckrtl/orbit` belongs to a user. If the repository moves to an organization, a merge queue can replace the merge on green as its own decision.
- **A process count per Project.** A Project setting does not know the machine. One rule from cores and memory fits every task VM and worktree.
- **One reminder per attempt for a failed handoff check.** Without the implementer's own gate, two failed checks in a row would stop the group.

## Consequences

- In the measured week, this removes the 16.8 hours of `base_merge` gates and the implementer's run of the full gate before each handoff.
- It removes the last main merge that Orbit makes in a workspace. The lease and #1297 to #1314 already fixed the incident in which groups 1632 and 1692 lost about 19 hours, so this saves no more of those hours.
- A typical subtask costs one handoff gate and one review. CI adds 6 to 15 minutes once for each pushed head.
- A rework costs about one first handoff.
- A formatting or static-analysis failure now reaches the implementer as a reminder after the handoff check, not before. That can cost one more correction turn, inside the new budget.
- A conflict fixup selects every main change since its start commit.
- A semantic conflict between two pull requests that both passed CI can reach main. The `push` run of CI on main finds it.
- Review and merge refuses to merge into a base branch that requires an up-to-date branch.
- Orbit depends on one documented option of its Pest fork. The fork's tests cover it.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, apps/web
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role): CI keeps its process counts per runner type; its `Administration: write` grant also covers the protection read, and whichever ADR builds first adds it once
- Detail: [Tasks](/reference/tasks): Deliverables, Project check, Baseline check, Rubric and reminders, Fix a settling pull request, Review fixup lifecycle, Review and merge, Final review, Every push is reviewed, Merge on green, Records and status, and "Orbit merges only what it reviewed". [Projects: Review and merge](/reference/projects#review-and-merge). [GitHub App](/reference/github-app). [Feature delivery](/reference/implementation-loop): Local checks, Parallel processes, The candidate gate, Main caches, and Why it works this way. [Instance setup: Import CI main caches](/reference/instance-setup#import-ci-main-caches): the setup step imports the nearest ancestor ref and no longer names `bin/tia-cache import-ci`. [Environment variables](/reference/environment-variables)
- Verify: the tests and live reads below.
  - In apps/gateway, `php artisan test --compact --filter='TaskCheck|TaskPublication|TaskFinalReview|TaskSettling|ReviewAndMerge|TaskTurnInstructions|TaskConsultation'`.
  - In apps/e2e, the `review-check`, `ci-cache-ref`, and CI configuration tests.
  - In the Pest fork, the `--tia-base` tests, including a dirty branch baseline.
  - In apps/e2e, baseline cases of `bin/review-check` for an older graph (the changes since it run), a graph at the start commit (no tests run), and a missing graph (the full suite runs).
  - In apps/gateway, the `project:update` preflight cases: a strict ruleset, strict classic protection, no protection, and an unreadable source.
  - On the live Gateway, after the build, no new `base_merge` check row appears.
  - The median rework handoff stays within 1.2 times the median first handoff.
  - No baseline records a fresh graph while an ancestor cache ref with the same TIA fingerprint exists.
  - The check row of each handoff holds its command list.
