---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs planned coding work in container task copies: the model, deliverables, checks, agent turns, handoffs, review, pull requests, insights, and the outer loop."
covers:
  - "apps/gateway/{app/{Data/Tasks/**,Domain/{Tasks,Problems}/**,Infrastructure/Tasks/**,Support/Percentile.php},config/{swamp,task_pull_requests}.php}"
  - "{apps/gateway/app/Actions/Tasks/**,.claude/skills,.agents/swamp-upstream/**}"
  - "apps/gateway/app/Http/{Requests/Tasks/**,Controllers/Api/{TasksController,TaskGroupsController,TaskPullRequestController,TaskInsightsController,TaskSwampRunsController,AgentThreadsController,TaskQuestionsController}.php}"
  - "apps/gateway/app/Console/Commands/{TaskPullRequestGateCommand,TaskPublishWhenDoneCommand,FileProblemsCommand,TaskInsightsBackfillCommand,TaskInsightsRefreshCommand}.php"
  - "apps/gateway/app/Models/{Task,TaskComment,TaskCheck,TaskQuestion,TaskReviewedCommit,AgentThread,JevDecision,ProblemFingerprint,ProblemCollectorState}.php"
  - "{apps/gateway/resources/tasks/**,swamp/extensions/behaviors/operations/{task-repository/{assets,lifecycle,publication,check}.ts,tasks/publish.ts,dependency-merge/**},swamp/workflows/{workflow-operation-dependency-merge,workflow-operation-task-publish,workflow-operation-task-pr-ready,workflow-operation-task-pr-merge,workflow-operation-task-check,workflow-task-check-ci}.yaml,swamp/extensions/behaviors/_lib/public_output.ts}"
  - apps/docs/app/{Documentation/DocsImpact.php,Console/Commands/DocsImpactCommand.php}
  - "apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_31_000000_add_task_vm_publish_error_code,2027_01_04_000000_add_task_insight_fields,2027_01_18_000000_create_task_review_rounds_table,2027_01_20_000000_keep_review_mode_off_subtasks,2027_01_22_000000_run_review_round_as_one_operation,2027_01_31_000000_clear_copied_subtask_token_totals,2027_02_02_000001_add_task_idle_save,2027_02_19_000000_add_task_pr_modes,2027_03_07_000000_convert_legacy_problem_evidence,2027_03_12_000000_create_task_insight_records_and_totals,2027_03_13_000000_repair_watched_pr_state_of_completed_groups,2027_03_15_000000_rerecord_task_insights_with_unknown_tokens,2027_03_18_000000_rerecord_task_insights_with_handoffs,2027_03_20_000000_rerecord_task_insights_with_finding_categories}.php"
---

# Tasks

Tasks is an optional Gateway extension (`orbit extension:enable tasks`) that runs planned coding work in container [task copies](#task-copy) cloned from a Project's default container Instance. Swamp executes every task Operation (provision, turns, checks, sync, publish, and cleanup), while the Gateway stores the board, validates state transitions, and enforces handoff gates.

Enable or disable the extension with `orbit extension:enable tasks` and `orbit extension:disable tasks` (requires `ai-router` to be set up first). While disabled, every task endpoint except `task:status` (`GET /api/v1/tasks/status`) returns HTTP `409 extension.disabled`. `task:status` returns `enabled`, `assistance`, `last_tick_at`, `merges`, and `releases`.

## Model

A task is a row in `tasks`. Rows with `parent_id = null` are top-level task groups (one feature or fix delivered as one pull request); rows with `parent_id` set are child subtasks.

### Tasks and subtasks

Top-level task groups use `TaskGroupStatus`: `backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`, `waiting_for_review`, `completed`, `failed`, `cancelled`. Subtasks use `TaskStatus`: `todo`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`, and `type` (`implementation`, `final_review`, or `work`). The web board "In progress" lane displays `TaskGroupStatus::active()` (`reserved`, `running`, `reviewing`, `settling`, `waiting_for_review`).

A Project can be deleted only when all of its task groups are `completed` or `cancelled` (`409 project.has_task_groups` otherwise).

### A task with no subtask

A single-step task can omit child subtasks and attach deliverables directly to the parent, which creates an internal `TaskType::Work` row (`422 tasks.one_subtask` refuses creating a group with exactly one explicit subtask). The web page shows its `Work` status and `work_review` (`In review`, `Approved`, `Not approved`) in place of a subtask board.

### Task group modes

All task groups run in `external` mode (`external` orchestration).

### External orchestration

External orchestrators (such as Swamp workflows or operator ADEs) drive task progression through keyed Gateway Operations; the Gateway never claims groups, starts turns, or appends fixups on a timer, except for [`publish-by-itself`](#publish-by-itself) and [`the-merge-round`](#the-merge-round).

### List tasks

`GET /api/v1/tasks` supports cursor pagination (`limit` `1`–`100`, default `20`), `compact=true` for lightweight board rows, and filtering by status, project, or PR.

### Find the task of a pull request

Filter `GET /api/v1/tasks` with `repository` and `pull_request` to locate the task group associated with a GitHub pull request.

### Deliverables

Each subtask (or single-work task) declares up to 5 typed deliverables: `file`, `command`, or `review`. Command deliverables may set `fails_on_base: true` with explicit `paths` so the check proves the test fails on the start commit and passes on the candidate.

### Correct the deliverables of a started subtask

An operator or orchestrator can update deliverables on an active subtask via `task:update` when the original check specification needs correction, preserving audit history.

### Reopen the last subtask

To run another implementer pass on a completed subtask before publication, reopen it via `task:update`; the Gateway enforces valid status transitions (`tasks.status_move_refused`).

## Task runtime

### Task copy

`task:start` (`tasks.provision`) creates a container Instance clone (`task-{id}`) from the Project's default container Instance, mounting `/srv/workspace`. Projects without a container default refuse with `422 tasks.vm_runtime_removed`.

### Workspace ownership

The task group owns its container Instance until `task:complete` or `task:cancel` destroys it.

### Two groups on one Node

Two task copies on the same `app-dev` Node run concurrently without blocking one another because each group gets its own Swamp behavior names.

### Behavior names

Swamp names workspace and check behaviors per Node and group (`task-workspace-<node>-<group>`, `task-instance-check-<node>-<group>`) so locks stay isolated per group.

### A failed create

If `instance.create` fails for a task copy, the task group transitions to `failed` and queues cleanup of any partial container.

### A failed completion

If `instance.complete` fails after clone creation, the copy stays retained and the task group stays `reserved` with an assistance request so you can inspect or retry `orbit instance:complete <id>`.

### Build a feature in one copy

For interactive development on a Project with a nested fleet, create a development clone or task copy to test Gateway, agent, and web changes against an isolated inner fleet.

### Every task group runs on its own task VM

Task VMs have been removed; every task group now runs in its own container [task copy](#task-copy).

### A task VM workspace

Container task copies store their checkout at `/srv/workspace` inside the container; no task VM workspace is created.

### Release an idle task VM

Task VM idle release is removed; container task copies use container [sleep and wake](/reference/development-containers#sleep-and-wake).

### Credentials in task VMs

Task copies never receive GitHub tokens or CLIProxyAPI real keys. Model calls go through the [container model forwarder](/reference/ai-router#container-model-forwarder) and Git syncs/pushes run from the host mirror or Swamp worker with short-lived tokens.

## Execution ownership

Swamp workflows own turn execution, check execution, retries, and publication; the Gateway validates admission, records receipts, and projects terminal results.

### Copy turn agents

Turn agent selection resolves `task` -> `project` -> `gateway` settings (`ORBIT_TASKS_COPY_*`, defaulting to `claude` and `claude-opus-5-5`). Supported providers and efforts are:

| Provider | Models | Efforts |
| --- | --- | --- |
| `claude` | T3 catalog models (such as `claude-opus-5-5`) | `low`, `medium`, `high`, `xhigh`, `max` |
| `codex` | T3 catalog or probed Codex models (such as `gpt-6.1-sol`) | `low`, `medium`, `high`, `xhigh`, `max`, `ultra` |
| `antigravity` | `gemini-4-neon`, `gemini-4-hydrogen` (`low`–`xhigh`); `gemini-4-carbon`, `gemini-4-argon`, `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash` (`low`–`high`); `gemini-3.1-pro` (`low`, `high`) | Per model (Conn Server copies only) |

Admission validates provider and effort (`422 tasks.copy_turn_unoffered`), and turn start verifies the model against T3's `model-manifest.json` or Codex probe (`tasks.copy_turn_model_unoffered`, `tasks.copy_turn_model_unverified`).

### Drivers

Container task copies are driven either interactively via Conn or autonomously via Swamp turns (`tasks.copy_turn`, 3,600-second turn bound, 100,000-character prompt cap).

### Run an external turn

`POST /api/v1/tasks/{task}/turn` (`tasks.copy_turn`) runs an implementer or reviewer turn inside the task copy via T3, returning the turn receipt, thread state, workspace evidence, and token metrics.

### Fetch before a turn

Before starting a turn, bring the default branch into the copy with `task:sync` (`tasks.sync`); no separate VM fetch step exists.

### Turn receipt

Each completed turn records a `TurnReceipt` comment and updates thread token metrics on the subtask.

### Read a thread

`GET /api/v1/tasks/{task}/agents/{session}` (`task-agent-show`) returns recorded thread snapshots and deltas (`after_sequence`), and `GET /api/v1/tasks/{task}/agents/{session}/stream` streams updates.

### Swamp run output

`GET /api/v1/tasks/{task}/swamp-runs` (`task-swamp-runs`) returns the latest five Swamp runs for a task group, with step states, bounded output, and availability metadata.

### Subtask status in a task copy

Subtasks move through `todo` -> `running` -> `reviewing` -> `completed` (or `failed`/`cancelled`) as implementer turns, checks, and reviewer turns succeed.

### Bring main into a copy

`POST /api/v1/tasks/{task}/sync` (`tasks.sync`) runs `operation-task-sync-copy` to fetch the default branch into the Node mirror and import objects into `refs/remotes/origin/<default>` without touching working files. When the copy's Node reports the sync primitives, the same steps run on the [Gateway engine](/reference/gateway-engine#taskssync).

## Checks and deliverables

### Start commit

`task:start` records the copy's `starting_commit` so diffs, commit counts, and `fails_on_base` deliverable runs compare against a fixed baseline.

### Baseline check

A baseline check (`phase: baseline`) verifies the clean starting commit passes the Project check before implementation begins.

### Check an external group

`POST /api/v1/tasks/{task}/check` (`tasks.check`) runs `task-instance-check` in the container ([Check phases](/reference/task-instance-workspace#check-phases)), returning `{ outcome, deliverables, check_id, commit, head, tree, output, ci }`.

### Project check

Each Project defines its own verification command (`project_check`), executed inside `/srv/workspace` as `agent` with no credentials.

### Scoped task check

Subtasks can scope checks to their declared command deliverables alongside the Project check.

### A Pest command gets CI's time limit

Pest commands executed inside a task check inherit CI's bounded execution timeout so hung tests fail cleanly.

### The handoff check merges main first

Before running a handoff check (`phase: handoff`), `task-instance-check` runs `task-copy-base` and merges `refs/remotes/origin/<default>` if clean; merge conflicts fail the check with conflicting paths.

### Handoff CI

Projects requiring hosted CI on handoff checks record CI evidence in `result.ci`; container handoffs requiring hosted CI currently refuse with `tasks.instance_hosted_check_unavailable`.

### Gate check

Gate checks verify that a subtask has a passing check on the exact candidate `HEAD` before review or publication.

### Stored result

Each `TaskCheck` stores its `outcome` (`passed`/`failed`), candidate `commit`, `head`, `tree`, deliverable verdicts, and bounded redacted output.

### The copy check

During publication, `task_copy_scan.py` scans the diff against the copy's `.env` and secret patterns to block accidental secret leaks (`tasks.publish_secret_unchecked` on scan error).

### Docs-impact waivers in a copy

Publication reads `.git/orbit/proposed-pr-bodies/task-<id>.md` (up to 64 KiB) to verify any `docs-impact` waiver lines against `orbit:docs-impact`.

## Handoffs and review

### Handoffs

`task_handoffs` stores structured receipts exchanged between implementers, reviewers, and operators. Missing required handoffs refuse progression with `409 tasks.handoff_required`.

### The delivery receipt

Implementers submit a delivery receipt summarizing changes, check evidence, and candidate commit before review begins.

### The delivery gate

The delivery gate verifies a passing check on the delivered candidate commit before admitting a reviewer turn.

### The review receipt

Reviewers submit a structured review receipt (`approved` or `changes_requested`) bound to the reviewed commit, recording blocking findings by category (`regression`, `destructive`, `failure_state`, `race`, `contract`, `validation`, `outcome_missing`, `docs`, `other`).

### The review gate

The review gate verifies an approved review receipt on the current candidate commit before publication.

### The reviewer rule

This is the single rule for what a reviewer judges and what blocks a merge. Every task-copy reviewer turn receives it in `<review_rules>` in a thread separate from the implementer. Review rounds count per subtask, while the reviewer thread belongs to the group. A fresh reviewer thread starts only when the group has none in the copy or the caller passes `--thread=new`; otherwise a turn continues the group's reviewer thread across re-reviews and subsequent subtasks. Independent reviewers outside task copies apply the same rule, which wins over the implementer quality bar in `AGENTS.md`.

Scope covers only the briefs, deliverables, the diff of the checked commit against the base, and what that diff directly affects. Reviewers report no out-of-scope ideas, refactors, or redesigns, and ask for changes outside the touched files only when a blocking finding forces it (naming that finding).

A finding blocks only if one of these holds:

- A normal run or a likely failure (a crash, reboot, or network loss at one step) would cause real harm.
- A deliverable is missed or not proven, or a test requested by the briefs is missing or broken.

Real harm means one of these outcomes:

- It stops or deletes something live.
- It breaks live requests or user-visible behavior.
- It loses or corrupts data.
- It produces a wrong result requested by the briefs.
- It adds a security hole.

These do not block and belong in short follow-ups for the pull request body:

- Limits accepted by the briefs.
- Rare interleavings, multi-fault races, hardening, and defense in depth.
- Findings behind a flag that is off by default, affecting behavior only with the flag on (put on a "before the flag goes on" list).

Reviewers ask for no new recovery state, marker, or retry path for rare races; removing a state beats hardening it.

The first review is the one full review. It covers every owned outcome, brief requirement, deliverable, and direct effect of the diff, tests at least one way the result could be wrong while checks pass, and checks sibling cases of any blocker before reporting.

A re-review checks only three things:

- Earlier blocking findings (closed or still open, and why).
- Changes since the last reviewed commit and their direct effects, excluding base merges.
- New evidence or owner decisions in the turn.

Re-reviews do not re-audit unchanged code, except to report real harm of the first kind spotted in unchanged code (marked "missed in the first review").

Each blocking finding gives its [category](#the-review-receipt), file and line, failing case and consequence, the rule a fix must satisfy, and every case found. The report ends with one exact line: `VERDICT: APPROVE` or `VERDICT: CHANGES`. With no blocking finding, the reviewer approves.

### The reviewer's pull request text

The reviewer's approved summary populates the pull request body when Orbit publishes the task branch.

## Publication, PRs, and merge

### Publish an external group

`POST /api/v1/tasks/{task}/publish` (`tasks.publish`) exports the verified candidate bundle from the task copy, scans for secrets, pushes `task-{id}` to GitHub via a short-lived App token, and opens or updates the pull request.

### Publication state

Top-level tasks track `pr_url`, `pr_number`, `published_commit`, and `published_at` alongside `watched_pr_url`, `watched_pr_number`, and `watched_pr_state`.

### The base of a copy publish

Publication targets the Project's default branch and verifies the candidate descends from the synced base.

### Published work of a copy

`task-copy-base` reports whether the copy's commits are already included in the updated default branch.

### Publish by itself

The `task:pr-when-done` scheduler command automatically publishes completed task groups for Projects configured with `pr_when_done` mode (default mode is `review_before_pr`).

### Watch the branch while subtasks are open

Orbit watches for an existing pull request on `task-{id}` while subtasks are open (`watched_pr_*`) and stops starting new subtasks if that PR merges or closes.

### A copy group settles on merge

`task:copy-settle` watches open task copy PRs and transitions the group to `settling` / `completed` once the PR merges.

### Closes the issue

When a task is linked to a GitHub issue, the generated pull request body includes `Closes #<number>` so merging closes the issue.

### Review and merge

Projects that opt into automated review and merge allow Orbit to review incoming pull requests from configured authors (`ORBIT_TASKS_PULL_REQUEST_AUTHORS`) and merge approved heads once CI passes (`task:pr-gate`).

### Ready and merge a task pull request

`POST /api/v1/tasks/{task}/pr-ready` (`tasks.pr_ready`), `POST /api/v1/tasks/{task}/pr-merge` (`tasks.pr_merge`), and `POST /api/v1/projects/{project}/pr-merge` (`project.pr_merge`) mark PRs ready and execute App-authenticated merges.

### No merge

Task copy pull requests do not auto-merge through the legacy merge endpoint (`409 tasks.copy_pr_no_merge`); merge through GitHub or the configured review-and-merge workflow.

### The merge round

`task:merge-round` resolves rebase/merge conflicts when a copy publish conflicts with a newer default branch.

### Trusted GitHub feedback

`task:pr-gate` reads GitHub reviews and comments from allowlisted reviewers and records fixup subtasks (`fixup_problem`) without granting merge authority to untrusted comments.

## Assistance, questions, and cleanup

### Direction requests

Tasks can request operator input via `assistance_kind` (`direction` for design choices, or failure attention), surfaced in `task:status` and on the web board.

### Assistance and resolution

Record subtask or group assistance and resolution comments via `POST /api/v1/tasks/{task}/comments` (`type: assistance_requested` or `resolution`).

### Group assistance after all children end

Assistance comments at the parent group level stay on the task group independently of child subtask comment streams.

### Questions

`GET /api/v1/task-questions` lists open direction questions; `POST /api/v1/task-questions/{question}/close` closes a stale question as `answered` or `superseded`.

### Complete and cleanup

`POST /api/v1/tasks/{task}/complete` (`task:complete`) completes a `settling` group (or a group whose watched PR merged/closed), interrupts open subtasks, and destroys the task copy workspace.

### Cancel a stuck task

`POST /api/v1/tasks/{task}/cancel` (`task:cancel`) stops running Operations, cancels open subtasks, and destroys the task copy (removing pending/failed exclusive Routes on `source_resolved` workspaces while refusing active shared Routes).

The cancel of a task that owns a copy stays `running` until the copy's `instance.destroy` ends, and takes its outcome. It succeeds once the copy is gone. The task is then `cancelled`, a `failed` task included; a task that a completion completed meanwhile stays `completed`. It fails with the destroy's error code when the destroy fails or its budget expires, and the task keeps the copy and runs on. A later receipt that corrects the destroy's end corrects the cancel too.

The cancel holds no lock and blocks no other admission of the task while it waits. It has no budget of its own; the destroy's budget bounds it. A [signed clone removal](/reference/gateway-engine#instancedestroy) on the Gateway engine stays fenced past its budget until its Node returns the result, and the cancel waits with it. The reconciler ends a waiting cancel whose destroy ended without it.

Its result names the destroy as `destroy_operation_id`. A stop request on the waiting cancel fails with HTTP 409 `tasks.cancel_waits_for_destroy` and names the destroy. See [`task:cancel`](/cli/task/cancel).

## Metrics and insights

### Task insights

`GET /api/v1/task-insights` and Prometheus scrape `GET /api/v1/metrics/task-insights` (refreshed by `task:insights-refresh`) report throughput, cycle times, handoff counts, `findings_per_first_review`, `findings_by_category`, `unresolved_tasks`, and token usage.

### Lifecycle times

Tasks record `created_at`, `started_at`, `finished_at`, and phase durations for cycle-time reporting.

### Last activity

`last_activity_at` tracks the most recent turn, check, comment, or state transition on a group.

### Settle metrics

Settled groups store final commit counts, review rounds, and merge latency.

### Deploy times

Verified Gateway releases stamp `deployed_at` on merged tasks carried by that release commit.

### Thread token metrics

Each `AgentThread` records input, output, cache read/write tokens, and cost per turn.

### Tokens and line diff

Task groups aggregate total token counts and `lines_added` / `lines_deleted` from workspace diffs.

## Outer loop

The outer loop turns recurring fleet, Doctor, log, Activity, and release failures into backlog Tasks in the `orbit` Project.

### Fingerprints

Each problem source produces a deterministic fingerprint (`source|key...`, such as `release|{kind}|{resource}|{attempt}|{fault}`).

### When a fingerprint is ready

Release alerts are ready on first occurrence; recurring operational signals require repeated observations within their window before `problems:file` (hourly) files a backlog Task, subject to daily caps.

### Suppression

Operators can mute or suppress noisy fingerprints so `problems:file` skips them until unmuted.

## Configuration

Configure Tasks in the Gateway environment (`config/orbit.php` `tasks.*`):

| Variable | Default | Purpose |
| --- | --- | --- |
| `ORBIT_TASKS_COPIES_MAX` | `1` | Maximum concurrent container task copies |
| `ORBIT_TASKS_COPY_PUBLISH_PROJECTS` | `[]` | JSON array of Project IDs enabled for copy publishing |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | empty | GitHub logins requested as PR reviewers |
| `ORBIT_TASKS_PULL_REQUEST_AUTHORS` | empty | Allowlisted GitHub authors for incoming PR review |
| `ORBIT_TASKS_RESERVED_TIMEOUT_SECONDS` | `3600` | Timeout for a reserved group before recovery |

## API routes

Key routes under `/api/v1` (all require `tasks` enabled except `GET /tasks/status`):

| Route | Purpose |
| --- | --- |
| `GET /tasks/status` | Extension status, assistance list, merges, and releases |
| `GET/POST /tasks`, `GET/PATCH/DELETE /tasks/{task}` | Task group and subtask CRUD |
| `POST /tasks/{task}/{start,sync,check,turn,publish,complete,cancel}` | Task lifecycle Operations |
| `POST /tasks/{task}/{pr-ready,pr-merge,dependency-merge}`, `POST /projects/{project}/pr-merge` | PR ready and merge Operations |
| `GET/POST /tasks/{task}/comments`, `GET /task-questions`, `POST /task-questions/{question}/close` | Comments and direction questions |
| `GET /tasks/{task}/agents/{session}`, `GET /tasks/{task}/agents/{session}/stream`, `GET /tasks/{task}/swamp-runs` | Thread transcripts and Swamp run snapshots |
| `GET /task-insights`, `GET /metrics/task-insights` | Task insights API and Prometheus scrape |
