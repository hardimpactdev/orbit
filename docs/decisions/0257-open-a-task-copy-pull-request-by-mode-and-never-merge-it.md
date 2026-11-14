---
title: "ADR 0257: Open a task copy's pull request by mode, and never merge it"
sidebarTitle: "0257 Task group modes"
description: "In progress. A task group in a task copy has a mode, review_before_pr or pr_when_done, resolved from the group, the Project and the Gateway. Orbit decides from its own records when the work is done; in pr_when_done it publishes by itself behind every guard of a manual publish, in review_before_pr the group waits for a person's task:publish. Orbit never merges the pull request of such a group."
---

# ADR 0257: Open a task copy's pull request by mode, and never merge it

A task group whose workspace is a task copy has a mode. In `review_before_pr` the group waits, visibly, until a person runs `task:publish <group>`. In `pr_when_done` Orbit opens a ready pull request by itself once the work is done. In both modes Orbit never merges the pull request: a person merges it on GitHub.

## Status

In progress.

The owner decided the open points: `review_before_pr` puts nothing on GitHub until the release; the mode is resolved when read; the caller's completion of the last subtask after a reviewer turn on exactly the done check is the approval; the automatic pull request opens ready.

Principle: [Agents operate, humans steer](/mission#principles) and [Deterministic first](/mission#principles).

## Context

Today a task copy's group publishes only when a caller runs `task:publish` with a check, a title and a body ([Publish an external group](/reference/tasks#publish-an-external-group)). Nothing tells a person that the work is ready, and an orchestrator that wants the pull request opened must make the call itself.

Several paths can merge a pull request: `task:pr-merge`, `project:pr-merge`, the `task:pr-gate` tick of a review-and-merge Project, and the dependency merge. `project:pr-merge` refuses a pull request only while an open group watches it, so the pull request of a group that ended can be merged through it. Work from a task copy has had no merge by Orbit yet, and its review is a turn without a verdict record.

A copy reviewer turn records no verdict ([Task copy](/reference/tasks#task-copy)). The records Orbit has are the subtask statuses that the caller sets, the passed handoff checks with their eligibility, and the copy turns with the commit each reviewer turn reviewed.

## Decision

- **Mode.** `review_before_pr` or `pr_when_done`. The group's own `pr_mode`, else the Project's `task_pr_mode`, else the Gateway's `ORBIT_TASKS_PR_MODE` (default `review_before_pr`, which is today's behavior). `task:show` returns the mode and its source. A group's mode changes only until it has a pull request.
- **Done.** Orbit decides from its own records. Every subtask has ended and one is completed. The last completed subtask has an eligible passed handoff check, with no newer one. The reviewer thread's newest turn reviewed exactly that commit and ended done. No clock orders the review and the completion: a turn is admitted only on the current subtask, and completing a task copy's subtask is refused while a copy turn of the group runs. No turn or Operation of the group runs. The caller's completion of the last subtask after that review is the approval. For a Project without a task check, a passed handoff check only records the commit; the reviewer turn on that commit and the publish guards (the allow list, the `.env` content and Club key checks) are then all that stand in front of the automatic publish.
- **Recorded mode.** A passed handoff check records the mode in effect when it passed. Orbit publishes by itself only when that recorded mode and the current mode are both `pr_when_done`. A change of the Project or the Gateway setting after the check passed therefore never publishes that work by itself.
- **Publish by itself.** The scheduled `task:pr-when-done` admits the existing `tasks.publish` for each such group, keyed by group and check, with the group title, a body that Orbit writes, and a ready pull request. Every guard of a manual publish applies.
- **Waiting and release.** `task:show` returns `publication` with a state: `working`, `waiting_for_release`, `publishing`, `refused` or `published`. `task:status` lists waiting and refused groups under `releases`. `task:publish <group>` without `--check` releases the done check the same way. No board status is added.
- **Refused.** An admission refusal, a stopped copy included, is stored on the group. A failed, interrupted or cancelled publish of the done check, by any caller, keeps its code on its Operation. Both show as `refused` with the code, and Orbit does not start that check again by itself. Only a busy group, a turn that may still run, or a group whose done answer changed under the row lock is a quiet skip. A fault that is no refusal is stored as `tasks.publish_failed`. The group and its task copy stay as they are.
- **No merge.** One rule, `TaskCopyPullRequest`: a group that has a task copy or published from one, and a pull request that such a publish opened or that sits on such a group's `task-<id>` branch. Every merge path refuses it with `tasks.copy_pr_no_merge`, and a CI test holds the list of workflows that call GitHub's merge API.

## Rejected alternatives

- Open a draft pull request in `review_before_pr` and release it with `task:pr-ready`: something would publish by itself in both modes, and the default would no longer equal today's behavior.
- Fix the mode at `task:create`: a backfill would be needed, and a group that the old release creates between migrate and switch would lack a value that the new code requires.
- Timestamps on the Project setting and the Gateway setting to tell earlier done work apart: the Gateway setting is an environment value with no change record, so no exact time exists. The mode recorded on the check is exact and costs one column.
- Start the publish from the end of the handoff check: the work becomes done when the caller completes the last subtask, which ends no Operation, so the rule would need several triggers.
- Count a passed handoff check alone as done: an unreviewed commit would open a pull request by itself.
- Add a board status for the waiting state: board moves are guarded and the lanes read them; a field says the same without new moves.

## Consequences

- A person sees in `task:show` and `task:status` that a group waits for release, and releases it with one call.
- A `pr_when_done` Project gets its pull requests without an orchestrator call, behind the same guards as a manual publish.
- No Orbit path can merge a task copy's pull request, also after its group ended. A person merges on GitHub.
- A group whose mode changed after its done check needs a new handoff check or a release call.
- A copy reviewer turn still records no verdict. If copy turns get a receipt with a verdict, the done rule adds `approved` and nothing else changes.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: none
- Detail: [Task group modes](/reference/tasks#task-group-modes)
- Verify: Gateway Pest tests for the mode, the done rule, the `task:pr-when-done` tick, the release call and each merge path; the CI test of the merge workflows; `bin/check-flows`
