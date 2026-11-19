---
title: "Merge through a GitHub merge queue"
sidebarTitle: "0272 Merge queue"
description: "A GitHub merge queue tests each pull request on the pull requests ahead of it, and main delivers the commit that the queue tested."
---

# ADR 0272: Merge through a GitHub merge queue

A pull request reaches `main` through a merge queue. The queue's CI run tests the exact commit that `main` receives.

## Status

In progress.

Decided by the owner on 2026-11-19.

Principle: [One way, one name](/mission#principles): one CI run decides a merge, and it runs on the tree that lands.

## Context

Main delivery released a commit only when its tree equalled the tree that the pull request's CI run had checked out. `Required checks` did not require an up-to-date branch. So a pull request that merged after `main` moved landed a tree that no run had tested, and delivery refused it. The workaround was manual: update the branch, wait for CI again, and merge before `main` moved once more. Each round cost a full CI run, and a busy `main` needed several rounds.

## Decision

1. **A merge queue owns the merge.** The ruleset for `main` requires the queue, with squash merges. The author adds a pull request with `gh pr merge <n> -R hardimpactdev/orbit --squash --auto`. GitHub queues it when `Required checks` is green on its head.
2. **The queue run is the one run on the merged tree.** For each queued pull request GitHub builds a commit on top of the pull requests ahead of it and starts CI with a `merge_group` event. That run plans its checks against the newest verified commit below it and runs every selected check, fixture shards included. A green run fast-forwards `main` to the tested commit.
3. **Main delivers the tested commit.** `Main delivery checks` finds the queue run on the exact `main` commit, requires its `Required checks`, `Select build inputs` and `Web build` jobs to be green, and promotes its web build. It compares no trees: the commit is the same. A commit that reached `main` past the queue has no such run, and delivery refuses it.
4. **A pull request run is the pre-check.** It runs the selected builds, static checks, Pest suites, Docs gate and proof, on hosted runners. It plans the fixture shards and starts none. It no longer needs a delivered base, a web build for delivery or a receipt.
5. **Every group in a batch must be green.** The queue tests two groups at a time and merges one pull request at a time. A pull request whose group fails leaves the queue; GitHub rebuilds the groups behind it.

The ruleset settings are in [Merge queue](/reference/implementation-loop#merge-queue).

## Rejected alternatives

- **Require an up-to-date branch.** It is the manual workaround as a rule: every move of `main` reruns CI on every open pull request.
- **Keep fixture shards on pull requests as well.** One run can fill the shared runner pool. A second run of the same shards for each merge makes both runs wait for runners.
- **Reuse the pull request's result in the queue when the trees match.** It keeps the tree comparison that this decision removes, for a case that a busy `main` rarely meets.
- **Let the last group stand for the batch.** A failing pull request then merges when the pull request behind it is green.
- **Test more groups at a time.** The runner pool, not the queue, limits the fixture shards. More groups would add waiting shards and lost work when a group ahead fails.

## Consequences

- A queued pull request waits for one CI run, on the tree that merges. No branch update or second run follows a move of `main`.
- A fixture failure shows in the queue run, not on the pull request. The pull request leaves the queue; the author fixes it and queues it again.
- A fork's code reaches Orbit's runners only after a maintainer queues its pull request.
- The Gateway commands [`task:pr-merge`](/cli/task/pr-merge) and [`project:pr-merge`](/cli/project/pr-merge) merge directly. They do not serve a repository whose ruleset requires a queue.
- This replaces the 2026-11-05 choice to merge without a queue, and the rejected alternative "GitHub merge queue" of [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge).

## Affects

- Components: apps/docs
- ADRs: [ADR 0223](/decisions/0223-run-one-gate-from-handoff-to-merge), [ADR 0262](/decisions/0262-prove-changed-operations-on-the-pull-request)
- Detail: [Merge queue](/reference/implementation-loop#merge-queue), [main delivery](/reference/implementation-loop#deliver-the-queues-commit-on-main), stage 4 of the [command development pipeline](/reference/command-development-pipeline), and the CI workflow with `bin/ci-tree-receipt`, `bin/ci-build-plan` and `bin/ci-delivery`
- Verify: `tools/test_ci_tree_receipt.py`, `tools/test_ci_policy.py`, `tools/test_ci_units.py` and `tools/test_ci_delivery.py`; two pull requests queued back to back merge with one queue run each, and main delivery passes on both commits
