---
title: "ADR 0223: Run one gate from handoff to merge"
sidebarTitle: "0223 One gate"
description: "In progress. One local gate per handoff and CI on the merge ref are built. One definition of the checks, check results and evidence, and the close step remain open."
---

# ADR 0223: Run one gate from handoff to merge

Between "the implementer says done" and "the pull request merged", a task change used to pass up to five gates that repeat the same work. This ADR keeps two: one local gate per handoff, which the Gateway runs, and CI `Required checks` on the pull request merge ref. The implementer runs only the affected tests of the projects it changed. The local gate runs the fast checks only; the handoff's full suite runs in CI on the handoff commit.

## Status

In progress.

Parts 1, 2, 3, 5, 6 and 7 are built. Parts 4 and 8 and the close step are open.

Principle: [One way, one name](/mission#principles). One local gate, one integration gate, one definition of the checks, one cache transport, and one rule for the process count. It also serves [Lean](/mission#principles). No principle exception is proposed.

The decision was amended twice on 2026-10-20:

- A handoff's full test suite runs in sharded GitHub Actions CI on the pushed handoff commit, not in the task VM. [Tasks: Handoff CI](/reference/tasks#handoff-ci) owns the rule.
- Orbit runs no test impact analysis (TIA). An implementer runs the tests of the files it touches with stock Pest filters, and CI runs the full suites.

## Context

Data from the Gateway `task_checks` table, 321 checks from 2026-10-13 to 2026-10-18, showed the problem:

- The checks used 85.8 hours of task VM time in five days: handoff 58.5 h, the local main-merge gate (`base_merge`) 16.8 h, and baseline 10.5 h.
- Gateway `test:affected` was the cost driver, with a median of 812 s. PR CI took a median of 6 minutes.
- So almost all of the wait was the local gates. The implementer ran the full gate, the Gateway ran it again at handoff, Orbit ran it twice more on main merges, and CI ran it on the same merged tree.
- TIA, once its selection was correct, selected a median of the whole suite. Recording coverage added about 19% to test time.

## Decision

The decision has eight parts. Six are built, and two are open.

### Built

- **1. One local gate per handoff.** The Gateway handoff check is the only local run of the Project check in a task. A failed handoff check returns to the implementer with the end of the output and has its own budget of three failures in a row. The `project-check` deliverable is gone.
- **2. CI on the merge ref is the only integration gate.** Orbit runs no Project check on a main merge and never merges main itself in a review-and-merge group. Only the implementer merges main, and only to resolve a conflict. Review and merge refuses a base branch that requires an up-to-date branch, and asks for assistance once when GitHub refuses a merge because the branch is behind. See [Review and merge](/reference/projects#review-and-merge).
- **3. Each handoff's tests run in CI on the handoff commit.** The local gate runs the fast checks only. Orbit pushes the checked tree once to `task-{id}-handoff`, and the Project's `merge_check` on that commit is the handoff's test result. [Tasks: Handoff CI](/reference/tasks#handoff-ci) owns the rule.
- **6. The process count comes from cores and memory.** `bin/check-processes` is the one rule, and `ORBIT_CHECK_PROCESSES` is only the share that `bin/review-check` and `bin/test` pass to each lane.
- **7. No Project gets a test base.** The `slug === 'orbit'` case and `ORBIT_TASK_CHECK_BASE` are gone.
- **5. One cache transport.** CI cache refs, `refs/orbit/ci-cache/<sha>`, are the only transport. A checkout imports the nearest ancestor ref with `bin/ci-cache-ref`. The artifact worker is gone.

### 4. One definition of the checks (open)

- One file, `tools/checks.json`, lists each check: its project directory, its commands and their `budget_seconds`, its architecture tests, the paths that select it, and where it runs. It also holds the stage budgets in `stage_budgets`: baseline 600 seconds, handoff 1200 seconds, review 900 seconds.
- A check runs locally, in CI, or in both. The Rust agent runs only in CI.
- For a PHP project, the file holds `composer validate --strict` and `composer check`, which both gates run. Tests, architecture tests included, run only in CI, and the test step stays in `ci.yml`.
- `bin/review-check` reads the file. CI runs a check's commands with `bin/review-check run CHECK`, and the architecture-test step runs `bin/review-check architecture PROJECT`.
- Path triggers select only the checks outside the PHP projects' commands. They never drop the commands of a PHP project, and the baseline check uses none.
- Runner choice, Gateway shards, and process counts stay in `ci.yml`.
- The E2E configuration test checks that every check job in `ci.yml` runs its commands through `bin/review-check`. It holds no copy of its own.
- Delete `ARCHITECTURE_TESTS`, `ARCHITECTURE_TRIGGERS`, `WEB_CHECK_COMMANDS`, `PI_SERVER_COMMANDS`, and the other check and trigger constants in `bin/review-check`, and the copied commands and path conditions in `ci.yml`.

### 8. Check results and evidence (open)

- Every check start has a run ID. `RemoteTaskCheckRunner::start` requires it, and the path without a run ID goes.
- The check supervisor writes its own cancelled or failed result. It handles SIGTERM, SIGINT, and SIGHUP, stops its children, and writes `check.json` before it exits. `status()` only reads results.
- A managed run that dies without a result reads as a terminal `lost` once systemd proves it ended: the exact unit is inactive or failed, and its cgroup is empty. The workspace lease releases its holder and marks the interval causality uncertain. A reading without that proof keeps the holder.
- The check exports `ORBIT_CHECK_RECEIPT`, a file path. A Project check may write a JSON list to it: for each command, its name, directory, seconds, exit code, `budget_seconds`, a free `detail` text, and `selected_test_count` for test commands. Orbit records the same for each Project setup step in the baseline receipt, and stores the list in a new JSON column of the check row.
- The Gateway stores each review turn's elapsed seconds on the review thread row.
- Time budgets report overruns and never block or stop work. An overrun writes at most one Activity row per run with code `tasks.<stage>_over_budget`, where `<stage>` is `baseline`, `handoff`, `review` or `command`. `ProblemCollector` treats these codes as server-class, so existing fingerprints and the daily filing cap bound repeats.
- Setup `timeout_seconds` stay hard limits. The 16 KiB output tail stays.

### Close

Once no converted `base_merge` reviewed commit belongs to an open group, delete `inherited_approval_sha`, `TaskReviewedCommit::$inherited_approval_sha`, and the inherited-approval branch in `TaskPublication::checkReturningPullRequest`. Absorb this ADR into the pages below and retire it.

## Rejected alternatives

- **A declaration-data selector.** Runtime coverage cannot reliably link enum cases, constants, defaults, and attribute arguments. Widening those changes would affect about 86% of Gateway app changes.
- **Keep the main-merge gate, with a test base.** It repeats CI on a slower machine, after CI already tests the same merged tree.
- **Keep the implementer's full gate and skip Orbit's.** Orbit cannot trust an agent's report of its own run.
- **Keep the artifact worker for local stores.** It was a second transport and a second failure record for caches that Git refs already carry.
- **Require an up-to-date branch.** It would bring back the mechanical main merges that part 2 deletes.
- **GitHub merge queue.** GitHub offers it only for organization-owned repositories, and `hardimpactdev/orbit` belongs to a user.
- **A process count per Project.** A Project setting does not know the machine.

## Consequences

- The 16.8 hours of `base_merge` gates and the implementer's run of the full gate before each handoff are gone.
- A typical subtask costs one fast handoff gate, one CI run on the handoff commit, and one review.
- A formatting or static-analysis failure reaches the implementer after the handoff check, not before. That can cost one more correction turn, inside the failed-check budget.
- A semantic conflict between two pull requests that both passed CI can reach main. The `push` run of CI on main finds it.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, apps/web
- ADRs: [0213](/decisions/0213-run-ci-runners-through-a-ci-runner-role): CI keeps its process counts per runner type; 0223 needs only `Administration: read`, while runner registration needs its own decision
- Detail: [Tasks](/reference/tasks), [Projects: Review and merge](/reference/projects#review-and-merge), [GitHub App](/reference/github-app), [Feature delivery](/reference/implementation-loop), [Instance setup: Import CI main caches](/reference/instance-setup#import-ci-main-caches), [Environment variables](/reference/environment-variables)
- Verify: in apps/e2e, the `review-check` and CI configuration tests. On the live Gateway, the check row of each handoff holds its command list, and a time budget overrun leaves the check status unchanged and writes exactly one `_over_budget` Activity row.
