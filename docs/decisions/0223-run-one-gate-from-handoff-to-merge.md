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
| Baseline, median | 1058 s | 345 s | 300 s | 120 s | 62 s | 74 s |
| Handoff, median | 175 s | 1669 s | 902 s | 871 s | 191 s | 1466 s |
| Main-merge gate (`base_merge`), median | – | – | 1135 s | 803 s | 2311 s | – |

- The checks used 85.8 hours of task VM time in five days: handoff 58.5 h, `base_merge` 16.8 h in 69 runs, and baseline 10.5 h.
- The `base_merge` total excludes two rows, 1773 and 1790, of 5.6 h and 3.5 h. Their gates passed in about 20 minutes, and the rows closed the next morning, so that time was a Gateway stall.
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

- After #1335, rework 1874 took 550 s. Gateway `test:affected` took 439 s of it, on one process.
- That is no faster than the first-handoff median of 480 s.

### Copies, transports, and settings

- **Three copies of the check list.** The architecture tests are listed in `bin/review-check:25-45`, in `.github/workflows/ci.yml:321-324`, and in `apps/e2e/tests/Fixtures/tia-cache-tests.py:1458-1470`. The test copies the implementation instead of checking it against CI. #1234 exists because two copies drifted.
- **Two cache transports.** Workspaces import `refs/orbit/ci-cache/<sha>` (#1226). Local stores still use the `gh` artifact worker in `bin/tia-cache` (1020 lines): `refresh`, `drain`, the queue and worker locks, the registry, and a ledger of failures on main that repeats GitHub's CI status. On task VMs, the gate's `tia-cache seed` step does nothing.
- **Exact-commit caches only.** `apps/gateway/resources/instances/ci-caches.sh` imports only the ref of the exact baseline commit. When CI has not yet published that commit, the baseline records a fresh graph: 1770, 1771, 1775, 1821, and 1822 took 1253 to 1677 s against about 70 s warm. Pest accepts any ancestor graph.
- **A process override.** Since 2026-10-17 the Orbit Project's task check is `ORBIT_CHECK_PROCESSES=1 composer check`, after an OOM. Every check now runs one lane and one Pest process, and Gateway affected runs take about twice as long. The default in `bin/check-processes` and `tools/check-processes.php` allows four workers of up to 1 GB, and its comment cites 2 GB VMs. A normal task VM on beast and shark has 2 CPUs and 4 GiB. Medium has 4 CPUs and 8 GiB. Large has 6 CPUs and 12 GiB.
- **An alias.** `bin/test` still reads `ORBIT_TEST_PROCESSES`.
- **A Project special case.** `TaskSubtaskRunner::handoffCheck` sends `test_base` only when `$group->project->slug === 'orbit'`. The engine must stay generic.
- **A result written on the read side.** `check` raises `SystemExit` on SIGTERM, and `run()` catches only `Exception`, so a cancelled supervisor writes no result. `status()` then builds a failed `causality_uncertain` result from systemd and cgroup state. The start and status path without a run ID is still present, although every caller passes a run ID.
- **Lost evidence.** The Gateway keeps a 16 KiB output tail. In 123 of 321 checks the line of the slow step is cut. The receipt `result.json` holds the seconds and selection of each command, but it lives only on the VM.

## Decision

The decision has eight parts. They change the Gateway, the root `bin` and `tools` scripts, `.github/workflows/ci.yml`, and the Pest fork `nckrtl/pestphp-monorepo`.

### 1. One local gate per handoff

- The Gateway handoff check is the only local run of the Project check.
- `TaskTurnInstructions::implementer` no longer says "and `<check>` passes". It says that Orbit runs the Project check when the implementer hands off.
- The engine stays generic. The Orbit repository's task guidance, in the `orbit-tasks` skill, tells an implementer to run `composer test:affected` in each project it changed, and not to run `composer check` or `bin/review-check`.
- A failed handoff check already returns to the implementer as a reminder with the end of the output. That does not change.
- The `project-check` deliverable goes. A final-review fixup and a settling fixup keep their `review` deliverable. Their handoff check runs the Project check, as every handoff does.

### 2. CI on the merge ref is the only integration gate

- Orbit runs no Project check on a main merge. `TaskCheckKind::BaseMerge` goes.
- Orbit does not merge main before a final review. The first final review still starts at the merge base of `HEAD` and `origin/{default branch}`, so it reviews the whole branch.
- Orbit does not merge main before a push.
- Orbit never merges main itself. Main is merged into the workspace only to resolve a conflict.
- When GitHub reports that the pull request conflicts with its base, review-and-merge groups use the settling fixup `Merge origin/{base}` (`TaskSettlingFixup::plans`) that other Projects already use. The implementer merges and resolves. Its approval waits for a final review, as every approval in such a group does.
- A failed `Required checks` already creates a `check:*` fixup. That does not change.
- Merge on green requires final-review evidence on the head. A reviewed commit with source `base_merge` no longer exists, so a head inherits no review.
- Review and merge supports only base branches that do not require an up-to-date branch. Orbit's `main` does not (`strict_required_status_checks_policy: false`). When GitHub refuses a merge because the branch is behind, Orbit records `refused` with that reason. It merges nothing itself.

When main moves after CI ran on a merge ref, the App can merge a head whose merged tree CI did not test. Today the same gap exists after the last local merge gate. The `push` run of CI on `main` tests the merge commit, and [Failures on main](/reference/implementation-loop#failures-on-main) and the red-main [baseline retry](/reference/tasks#baseline-check) handle a red result.

### 3. Test each handoff from the subtask start commit

Every handoff of a subtask, first or rework, tests from the start commit of that subtask. Orbit uses no handoff tree as a test base.

- `bin/review-check` passes the commit to Pest with a documented option of the Pest fork that Orbit owns, `--tia-base=COMMIT`.
- The option selects the tests that the graph links to files that changed since `COMMIT`, including uncommitted and untracked files. It replays no stored result. The fork already computes this set for a commit in `ChangedFiles::since`; the option only names the commit.
- `bin/review-check` no longer writes Pest's graph.

The data decides it:

- A rework then selects the same set as its first handoff. The first-handoff median is 480 s (n=68), on four processes or fewer.
- The only rework measured with a tree base after #1335 took 550 s. A tree base saved nothing measurable.
- Reworks are 32 of 100 handoffs. Item 6 restores parallel processes, which about halves the Gateway affected run.
- The tree base needs content-hash snapshots of a tree inside the fork, more fork code, and more fork tests. The commit base needs one option on code that exists.

### 4. One definition of the checks

- One file, `tools/checks.json`, lists each check: its project directory, its commands, its architecture tests, the paths that select it, and whether only CI runs it, such as the Rust agent.
- `bin/review-check` reads it. The local gate runs a check when a changed path matches its triggers.
- CI reads the same file through `bin/review-check`: the architecture-test step runs `bin/review-check architecture PROJECT`. CI runs every check in the file, as it does today.
- Runner choice, Gateway shards, and the TIA plan stay in `ci.yml`, because only CI has runners.
- The E2E configuration test checks that every job in `ci.yml` that runs a check names a check in the file. It no longer holds its own copy.

### 5. One cache transport: Git refs

- CI cache refs, `refs/orbit/ci-cache/<sha>`, are the only cache transport.
- A workspace imports the ref of the nearest first-parent ancestor of its baseline commit that has a ref. It never imports a newer commit. Pest selects the changes since that commit, so a baseline is never cold while any ancestor ref is retained.
- The Gateway sends that ref to the workspace, as it sends the exact ref today.
- A local main cache store imports the same refs from `origin` with `bin/ci-cache-ref import`, which also takes over the checks of `bin/tia-cache import-ci`.
- The artifact worker, its queue, its registry, and its failure ledger go. GitHub's CI status is the only record of failures on main.
- CI keeps its artifacts only to stage the exports for `publish-ci-cache` in the same run.

### 6. Derive the process count from cores and memory

- `bin/check-processes` prints the smaller of the core count and one process per GiB of memory above a reserve of 2 GiB, and at least 1. It reads the cgroup memory limit when that is lower than the machine's memory.
- A normal task VM then gets 2 processes, medium 4, and large 6.
- `tools/check-processes.php` runs `bin/check-processes` instead of its own copy of the rule. CI runs it too, instead of fixed `--processes` values.
- `ORBIT_CHECK_PROCESSES` stays only as the share that `bin/review-check` and `bin/test` pass to each lane or suite. No Project, operator, or CI job sets it.
- The Orbit Project's task check goes back to `composer check`.
- `ORBIT_TEST_PROCESSES` goes.

### 7. Every Project gets the test base

`TaskSubtaskRunner::handoffCheck` exports `ORBIT_TASK_CHECK_BASE`, the subtask start commit, to the handoff check of every Project. The `slug === 'orbit'` case goes. A Project that does not read the variable is unaffected.

### 8. Check results and evidence

- The check supervisor writes its own cancelled or failed result. It handles SIGTERM, SIGINT, and SIGHUP, stops its children, and writes `check.json` before it exits. A run that dies without a result, such as on SIGKILL or a VM loss, stays `lost`, as today.
- `status()` only reads results. The read-side synthesis of a terminal result goes.
- Every check start has a run ID. The path without a run ID goes, and `RemoteTaskCheckRunner::start` requires the run ID.
- The check row stores the receipt's command list: for each command, its project, its command, its seconds, its exit code, and its selected test count. The 16 KiB output tail stays.

### Build order

Each step is one small pull request. Docs change with the code in the same pull request.

1. **One local gate.** Change the implementer instruction and the `orbit-tasks` skill.
   - Delete the `project-check` deliverable in `TaskFinalReview` and `TaskSettlingFixup`.
   - Delete `matches_task_check` and the `task_check` reuse in `verify_deliverables` of `check`.
   - Delete the reuse note in `TaskCheckRunner` and the `project-check` paragraph of [Project check](/reference/tasks#project-check).
2. **Process count.** Change `bin/check-processes` and `tools/check-processes.php`, and make CI call it. Then set the Orbit Project's task check to `composer check`.
   - Delete `DEFAULT_LIMIT` and its 2 GB comment.
   - Delete the PHP copy of the rule.
   - Delete `ORBIT_TEST_PROCESSES` from `bin/test`, `implementation-loop.md`, and `environment-variables.md`.
   - Delete the fixed `--processes` values in `ci.yml`.
3. **No local main-merge gate.**
   - Delete `TaskCheckKind::BaseMerge` and `TaskReviewedCommitSource::BaseMerge`.
   - Delete `TaskPublication::appendBaseMerge`, `prepareBaseMerge`, `queueBaseMergeRetry`, `appendAgentBaseMerge`, `appendDeltaReview`, and `conflictOutput`, with the repair, retry, and lost states of the gate.
   - Delete the main merge before the final review and before the push, and its retry caller in `StoreTaskCommentAction`.
   - Delete `TaskBaseMerge` and `TaskBaseBranchFetcher::mergeDefault`, which only the gate uses.
   - Route a conflicting review-and-merge pull request to `TaskSettlingFixup::plans`, which today serves only Projects without review and merge.
   - Delete the delta-only final review (`TaskFinalReview::BaseMergeReview`) and its cap.
   - Delete `classify_merge_gate`, the `base_merge` mode, and `base_merge_passed_tree` in `check`.
   - Delete the `behind` branch of `mergeWhenReady`.
   - Delete the docs of the main-merge gate in [Final review](/reference/tasks#final-review), [Every push is reviewed](/reference/tasks#every-push-is-reviewed), and [Records and status](/reference/tasks#records-and-status).
4. **Start-commit test base.** First a release of the Pest fork with `--tia-base=COMMIT` and its test. Then pass the subtask start commit to every Project.
   - Delete the tree case of `task_test_base`, `scope_tia_graph`, `scope_tree_baseline`, `comparison_tree_snapshot`, the `TREE_BASELINE_*` messages, and the embedded PHP program in `bin/review-check`.
   - Delete `keep_main_baseline` and the one-slot handoff cache, `handoff_cache` and its receipt write.
   - Delete the passed-handoff-tree rule of `TaskReviewBase::testBase` and the `slug === 'orbit'` case.
   - Delete the "A handoff tree is not a Pest commit" and "The gate selects against main" sections of [Feature delivery](/reference/implementation-loop#why-it-works-this-way).
5. **One check definition.** Add `tools/checks.json` and the `architecture` command.
   - Delete `ARCHITECTURE_TESTS`, `ARCHITECTURE_TRIGGERS`, and the other trigger constants in `bin/review-check`.
   - Delete the `case` list in the architecture-test step of `ci.yml`.
   - Delete the copied list in `tia-cache-tests.py`.
6. **One cache transport.** Import the nearest ancestor ref in workspaces and local stores.
   - Delete `bin/tia-cache`, with `refresh`, `drain`, `seed`, `register`, `status`, the queue and its locks, the frozen runners, the registry links, and the failure ledger.
   - Delete the `tia-cache seed` step of the gate and the exact-SHA rule of `ci-caches.sh`.
   - Delete [Refresh from CI](/reference/implementation-loop#refresh-from-ci) and [Failures on main](/reference/implementation-loop#failures-on-main) as worker docs, with the text on legacy failure records. Failures on main keeps one line: GitHub's CI status is the record.
7. **Check results.** The supervisor writes its result, and the check row stores the command list.
   - Delete the terminal-result synthesis in `status()`.
   - Delete the start and status path without a run ID and the "Legacy check process identity" match in `managed_run`.
   - Delete the optional `$runId` of `RemoteTaskCheckRunner::start`.
8. **Close.** Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **Keep the main-merge gate, with a test base.** It still repeats CI on a slower machine, after CI already tests the same merged tree on four runners. The parent classification and the repair and retry states stay.
- **Keep the implementer's full gate and skip Orbit's.** Orbit cannot trust an agent's report of its own run. The deterministic check must run where Orbit records it.
- **A base-tree option in the Pest fork.** A rework would select fewer tests. The only measured rework with a tree base, 550 s, was no faster than a first handoff, 480 s. It needs content-hash snapshots of a tree in the fork.
- **Keep the tree base with the graph writes of #1335.** It edits the fork's private format from outside the fork, and that layer caused the 40-minute checks.
- **Key the handoff cache by tree.** A start-commit base needs no handoff cache.
- **CI runs `bin/review-check` as one job.** CI would lose its runner choice, its Gateway shards, and its parallel jobs, which keep a PR at a median of 6 minutes.
- **Keep the artifact worker for local stores.** It is a second transport, a second failure record, and 1020 lines for caches that the Git refs already carry.
- **Exact-commit caches only.** Five baselines ran cold for 1253 to 1677 s in five days.
- **Require an up-to-date branch.** Every merge to main would rerun CI on every open pull request, and Orbit would push mechanical main merges again.
- **A process count per Project.** A Project setting does not know the machine. One rule from cores and memory fits every machine.

## Consequences

- In the measured week, this removes the 16.8 hours of `base_merge` gates and the implementer's run of the full gate before each handoff.
- A typical subtask costs one handoff gate and one review. CI adds 6 to 15 minutes once for each pushed head.
- A rework costs about one first handoff.
- A formatting or static-analysis failure now reaches the implementer as a reminder after the handoff check, not before. That can cost one more correction turn.
- A semantic conflict between two pull requests that both passed CI can reach main. The `push` run of CI on main finds it.
- Review and merge refuses a base branch that requires an up-to-date branch.
- Orbit depends on one documented option of its Pest fork. The fork's tests cover it.

## Affects

- Components: apps/docs, apps/e2e, apps/gateway
- ADRs: none
- Detail: [Tasks](/reference/tasks): Deliverables, Project check, Baseline check, Final review, Every push is reviewed, Merge on green, and Records and status. [Feature delivery](/reference/implementation-loop): Local checks, Parallel processes, The candidate gate, Main caches, and Why it works this way. [Instance setup: Import CI main caches](/reference/instance-setup#import-ci-main-caches). [Environment variables](/reference/environment-variables)
- Verify: the tests and live reads below.
  - In apps/gateway, `php artisan test --compact --filter='TaskCheck|TaskPublication|TaskFinalReview|TaskSettling|ReviewAndMerge|TaskTurnInstructions'`.
  - In apps/e2e, the `review-check`, `ci-cache-ref`, and CI configuration tests.
  - In the Pest fork, the `--tia-base` tests.
  - On the live Gateway, after the build: no new `base_merge` check rows; the median rework handoff stays within 1.2 times the median first handoff; no baseline records a fresh graph while an ancestor cache ref exists; and the check row of each handoff holds its command list.
