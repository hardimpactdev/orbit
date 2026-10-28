---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs tasks. It covers the model, the lifecycle, typed deliverables, the task check, agent threads, review, the pull request, fixups, metrics, cleanup, and the outer loop that files recurring problems."
covers:
  - "apps/gateway/{app/{Data/Tasks/**,Domain/{Tasks,Problems}/**,Infrastructure/Tasks/**,Support/Percentile.php},config/swamp.php}"
  - "apps/gateway/app/{Actions/Tasks/**,Jobs/{PublishTaskVmCommit.php,Problems/**}}"
  - "apps/gateway/app/Http/{Requests/Tasks/**,Controllers/Api/{TasksController,TaskGroupsController,TaskInsightsController,TaskSwampRunsController,AgentThreadsController,TaskQuestionsController}.php}"
  - "apps/gateway/app/Console/Commands/{ReleaseTaskWorkspaceLeaseCommand,TickTaskSessionsCommand,CollectT3MetricsCommand,CollectProblemsCommand,FileProblemsCommand,ArchiveTaskThreadsCommand,RenderTaskPromptCommand,JevReportCommand,TaskGitHubReviewsCommand,BackfillTaskMergedAtCommand}.php"
  - "apps/gateway/app/Models/{Task,TaskComment,TaskCheck,TaskQuestion,TaskGitHubReviewConsumption,TaskGitHubReviewObservation,TaskReviewedCommit,TaskWorkspaceLeaseRecord,AgentThread,JevDecision,ProblemFingerprint,ProblemCollectorState}.php"
  - "{apps/gateway/resources/{tasks/**,remote/gateway/task-workspace-owner.py},bin/{task-analyze,task-group-check}}"
  - apps/docs/app/{Documentation/{TaskAnalysis,DocsImpact}.php,Console/Commands/{TaskAnalyzeCommand,DocsImpactCommand}.php}
  - "apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_31_000000_add_task_vm_publish_error_code,2027_01_04_000000_add_task_insight_fields}.php"
---

# Tasks

Swamp is the only executor of task groups. Its groups driver and per-group runs choose provision, agent turns, checks, publication, review parking and cleanup. The Gateway stores the board and evidence and exposes keyed operations; it never claims a group, advances a subtask, retries a turn, appends a fixup or merges a PR on a timer. All groups use `external` orchestration, including groups created without an explicit mode. Existing records move to that mode without changing their status, workspace, assistance or receipts.

Tasks is an optional Gateway extension. It runs planned work with coding agents. A task is one feature or bug fix, delivered as one pull request. Its subtasks run in order in one shared task workspace. A fresh implementer builds each subtask, the Project's task check and CI verify the handoff, and a fresh reviewer approves it. Orbit commits and pushes the work. After the last approval, Orbit opens the pull request and watches it until it merges.

While a subtask is open, Orbit also watches a pull request on `task-{id}`. It stops starting subtasks when that pull request merges or closes.

A Project can opt in to [review and merge](#review-and-merge). Orbit then reviews the whole branch before it pushes anything for review, reviews the pull requests that listed authors open, and merges a reviewed head when CI passes.

The engine is generic. Your agentic development environment (ADE) plans and steers the work. Orbit runs it. Each Project keeps its own task policy in its repository, as an `orbit-tasks` skill under `.agents/skills/` and in its other instructions, and enforces it through its own task check. Agents read that policy from the repository, not from the shared prompts. The Orbit repository keeps its policy in the [orbit-tasks skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) and the [contributor guide](/contributor-guide).

Agents use the Tasks tools of the [MCP server](/reference/mcp). The [`tasks` CLI family](/cli/tasks) runs the same operations from a terminal. There is no web UI to create or change a task. The API, the CLI, and the web app call a child task a subtask.

A [Project removal](/reference/projects#remove-a-project) deletes its task groups and their dependent records only when every group is `cancelled` or `completed`. Any other group status, including `failed`, blocks removal with HTTP 409 and `project.has_task_groups`. Every associated task VM row must also be `destroyed`. A VM in `provisioning`, `ready`, `parked`, `destroying`, or `failed` returns HTTP 409 and `project.task_vm_not_destroyed`, with its name and state in the message. Wait until destruction and model-key revocation finish before retrying. Both refusals leave all records unchanged; only `destroyed` VM rows are deleted with the Project.

Project removal also requires every associated workspace lease to be `released` or `cancelled`, a withdrawn waiter. A `held`, `queued`, or unknown lease status returns HTTP 409 and `project.task_workspace_lease_active`. The message names the Project, lease id, kind, and status. All records, including lease operations, remain unchanged. A missing Instance does not prove release. Project deletion does not release leases and removes only released and cancelled leases and their operations.

## Extension switch and status

Enable and disable the extension with `orbit extension:enable tasks` and `orbit extension:disable tasks`. Both need Gateway access. While the switch is off, the `tasks` commands, MCP tools, and web pages are hidden, except `tasks:status` and the `tasks-status` tool. Every other task operation refuses with HTTP 409 `extension.disabled` and changes nothing. Stored tasks and subtasks stay. [`extension`](/cli/extension) describes the switch.

`tasks:status` is an assistance and status view, not a switch. Its route returns `enabled`, `assistance`, `last_tick_at` (always null), `merges`, and `workspace_leases`. `merges` lists the stored open review-and-merge records. `assistance` lists every task whose `assistance_requested` is true, in ascending task id order. Each entry has `id`, `project_id`, `project`, `project_code`, `title`, `status`, `assistance_kind`, `assistance_question`, and `assistance_reason`. An ended task asks only for `removal_failure` assistance. A task that is not asking is absent, even when it still stores an old reason. A flagged subtask does not add its task unless the task itself is asking. The view remains available while tasks is disabled.

## Model

A task is one row in the `tasks` table. A row with no `parent_id` is a top-level task: the Tasks board shows it, and it is one feature or bug fix delivered as one pull request. A row with `parent_id` is a subtask of that parent. A subtask has no children.

A top-level task holds the task workspace, the branch, the reviewed pull request, the watched pull request, the current reviewer thread, and the settle metrics. A subtask holds its position, its deliverables, its implementer thread, its task checks, and its turn receipts. Both levels store a title, a brief, a status, assistance, comments, and metrics. The Gateway rejects a value in a column that the level does not use.

| Field | Level | Meaning |
| --- | --- | --- |
| `title`, `brief` | both | Short name, and the goal and acceptance |
| `status` | both | Lifecycle state |
| `orchestration` | task | `external` (the default and only value). Create sets it; it never changes |
| `parent_id` | subtask | The top-level task. Null on a top-level task |
| `assistance_requested`, `assistance_reason` | both | Whether the record asks an operator for help, and why. See the [assistance lifecycle](#assistance-and-resolution) for ended tasks and cleanup failures |
| `assistance_kind`, `assistance_question` | both | The typed [assistance cause](#direction-requests) and the one question for a `direction` request. Other kinds have no question |
| `position` | subtask | Order under the parent, gapless from 1 |
| `deliverables` | subtask | The typed items the subtask must deliver |
| `check` | subtask | The latest [task check](#project-check) run |
| `fixup_problem` | subtask | The problem a [Gateway fixup](#fix-a-settling-pull-request) repairs. Null on every other subtask |
| `implementer_agent_thread_id` | subtask | The subtask's implementer thread |
| `reviewer_agent_thread_id` | task | The current reviewer thread. A shared reviewer thread is stored here, and [Review a subtask](#review-a-subtask) points it at the fresh reviewer |
| `taskable_type`, `taskable_id` | task | The task workspace Instance. Null until a provision operation creates it |
| `implementer_model`, `reviewer_model` | task | The models used for the task's threads |
| `pr_url` | task | The reviewed pull request Orbit opened on the last subtask, or the [incoming pull request](#incoming-pull-requests) Orbit reviews |
| `pr_branch` | task | The incoming pull request's head branch, which Orbit fetches and pushes. Null for a task whose branch is `task-{id}` |
| `merge_status`, `merge_reason`, `merged_sha` | task | The [merge gate](#merge-on-green) result: `waiting`, `refused`, or `merged`, its reason, and the merge commit |
| `type` | subtask | `implementation`, or `final_review` for a [final review](#final-review) |
| `watched_pr_url` | task | The pull request on `task-{id}` found by the [branch watch](#watch-the-branch-while-subtasks-are-open). Null until that list finds one. Not `pr_url` |
| `watched_pr_number` | task | The watched pull request's number. Null until the branch watch finds one |
| `watched_pr_state` | task | The last watched state: `open`, `merged`, or `closed`. Null until the branch watch finds one |
| `watched_pr_completion` | task | `merged` or `closed` after `tasks:complete` confirms the end, or `merged` before automatic merge cleanup. Null until then. Resume does not call GitHub |
| `ended_pr_notice_key` | subtask | Stable send key for the one ended-pull-request notice. Null when that subtask has no notice |
| `ended_pr_notice_thread_id` | subtask | The implementer or reviewer thread that notice belongs to |
| `ended_pr_notice_state` | subtask | `pending` or `delivered` |
| `vm_power` | task | [VM power](/reference/compute-drivers#vm-power), separate from task status: `running`, `stopped`, `destroyed`, or `null` |
| `ready_at`, `ended_at`, `merged_at` | task | [Lifecycle times](#lifecycle-times): when the task first entered `todo`, when it ended, and when GitHub merged its pull request |
| `tokens`, `line_diff`, `lines_added`, `lines_deleted`, `duration_ms` | both | [Settle metrics](#settle-metrics). Settle stores task tokens as subtask tokens plus every started reviewer thread, and the task line diff as the whole branch against the default branch |

Clearing the assistance flag can keep the last reason.

Typed comments record the workflow. A stored turn receipt is a comment whose type is its outcome: `ready_for_review`, `blocked`, `changes_requested`, or `approved`. An operator posts `assistance_requested` and `resolution` comments. Each comment keeps its full body, author, time, and attempt. An approval that Orbit committed carries `commit_sha`. The approval of the last subtask also carries `pull_request`: the summary, changes, and breaking changes it proposed.

`GET /api/v1/task-groups/{group}/tasks/{task}/comments` and the MCP tool `tasks-comment-list` accept optional `type` and `limit` query inputs. `type` must be a task comment type, such as `resolution` or `assistance_requested`. `limit` must be an integer from 1 to 100. The endpoint returns comments newest first, filters by type before applying the limit, and preserves each comment's full body and response shape. With neither input, it returns all comments as before. An unknown type or an invalid limit returns HTTP 422 with `validation.failed`. Use `type=resolution` and `limit=1` to read the newest resolution without returning the full comment history; the limit bounds the number of comments, not the byte size of an individual body.

`GET /api/v1/task-groups` and `GET /api/v1/task-groups/{group}`, and their MCP tools `tasks-list` and `tasks-show`, accept an optional boolean `compact` query input. With `compact=true`, each group omits `brief`, `assistance_question`, and `assistance_reason`; each subtask omits those fields plus `completion_summary`, `deliverables`, and `fixup_problem`. The latest check keeps its metadata but omits `output`. Omitted keys are absent, not null. Ids, titles, statuses, positions, assistance flags and kinds, and counters remain available. Without `compact`, or with `compact=false`, the response is unchanged. Invalid boolean input returns HTTP 422 with `validation.failed`. Use compact reads to avoid returning long text in the MCP output; this reduces the response size but does not impose a byte limit or paginate the results.

### External orchestration

Every task group is external. Swamp owns its lifecycle. The Gateway rejects non-empty task topologies with HTTP 422 `tasks.orchestration_unsupported`; the existing Project review-and-merge setting does not block admission. Only explicit keyed operations start work. Group and subtask status updates record board state without starting an agent, check or cleanup.

#### Park an external group for review

`POST /api/v1/task-groups/{group}/park`, CLI `tasks:park GROUP --idempotency-key=KEY`, and MCP `tasks-park` start the keyed `tasks.park` operation. The request is bodyless and requires an idempotency key. It returns HTTP 202 with the operation. It accepts no VM identity or provider command.

Parking requires an open external group with a published, open PR, an existing task VM and workspace, no open child, no preview, no assistance, and no other unfinished operation or workspace owner. It returns HTTP 409 `tasks.park_refused` when review parking is unsafe. Operation admission serializes with other group operations and returns `operation.target_busy` for a busy target. A group execution lock wait is bounded to five seconds; contention returns HTTP 409 `tasks.workspace_waiting` for a later retry.

The operation checks eligibility under the group execution lock and stops its existing VM through the shared parking action. It checks eligibility again after the stop and moves the group to `waiting_for_review` only if that check still passes. A child or assistance change during shutdown refuses the operation and preserves the previous group status. It preserves the checkout and disk. It neither allocates another VM nor merges the PR.

Success records `{group_id, task_vm_id, instance_id, status: "waiting_for_review", power: "stopped"}` only after the provider observes the existing VM stopped. A missing VM is `task_vm.vm_missing`; a VM still running after stop is `task_vm.park_failed`; a provider power state still in transition is `task_vm.not_settled`. A failed or interrupted stop supplies no successful parking evidence.

A failed stop or a refused final eligibility check records the VM error and leaves its power unknown. `vm_power` is `stopped` only after the provider confirms the stop. A parked row alone is not power evidence. Replaying the operation key returns the same operation; recovery of an interrupted step observes provider power before stopping again. A failed operation needs a new key, chosen by Swamp after its backoff. The Gateway does not queue an idle parking job.

A resource observation job checks every parked VM once a minute, including groups asking for assistance and ended groups. It never starts or stops a VM or advances a group. Missing, unsettled, running or unreadable power clears the stop proof. A wake clears the proof before queuing resume.

#### Provision an external group

`tasks:provision <group>` starts the Gateway operation `tasks.provision` through `POST /api/v1/task-groups/{group}/provision`. Its target is `task_group` with the group ID. All task operations share one target hold: another open operation returns HTTP 409 `operation.target_busy`. An ended group returns HTTP 409 `tasks.group_closed`. The Gateway retries no effect for an external group; the orchestrator decides whether to try new work.

Provision requires `todo`. A backlog group returns HTTP 409 `tasks.not_todo`; a claimed group returns HTTP 409 `tasks.already_claimed`.

The operation reserves the group and uses the VM allocator and workspace provisioner. When capacity is full or the VM is still provisioning, the operation stays `waiting` and the group stays `reserved`. Delayed reads continue the claim when the VM is ready. Task VMs that are disabled or misconfigured, or a VM that fails, fail the operation with the task VM error, such as `task_vm.invalid_config`. Any provisioning failure ends the group `failed`, clears its reservation, and leaves VM removal to existing cleanup. A retry needs a new group.

Success leaves the group `running`, its routed workspace `active` with its Route, or its unrouted workspace `source_resolved`. The task branch starts at the Project's default branch. No subtask starts and no check runs. The operation result is `{ instance_id, node_id, starting_commit, analysis_base }`, naming the workspace Instance, its Node, its start commit, and its immutable analysis pin. `starting_commit` is not necessarily the analysis base.

##### Optional Swamp worker

The provision API and generated MCP tool accept an optional `worker` object. Omit it to provision the usual workspace without a worker.

| Field | Contract |
| --- | --- |
| `worker.orchestrator_url` | Required `wss://` URL without user information, query, or fragment; the VM must reach it for WebSocket control and HTTPS data |
| `worker.enrollment_token` | Required secret in Swamp's `name.secret` format; the orchestrator mints a short-lived, single-machine token for this group |
| `worker.server_token` | Optional secret in Swamp's `name.secret` format for token-authenticated serve; mint it for a `worker:` principal, with the enrollment's short lifetime |
| `worker.labels` | Optional object of scheduling labels; use `group=<group id>` for discovery, and target the enrollment-bound worker name for group isolation. Keys and values contain only letters, digits, dots, underscores, and hyphens |

Orbit starts `swamp worker connect` as the VM's `orbit` account only after the workspace and its Route are ready. The worker uses that checkout as its working directory. The [base image](/reference/compute-drivers#swamp-worker) supplies the pinned binary and Orbit CA. The orchestrator owns token minting, expiry, revocation, and step placement. Token-authenticated Swamp serve requires both enrollment and a server token: the server token authenticates the WebSocket upgrade, and enrollment authenticates the worker handshake. Use a per-group `worker:` principal with no control-plane grants. The driver revokes both credentials when the group ends.

The Gateway stages both credentials together, encrypted in its cache outside operation records for at most 30 minutes while startup waits. `cache:clear` drops staged tokens. Invalid staged payloads are treated as missing without reporting their contents; an operation without a server-authentication setting starts with enrollment alone. If the token expires during capacity or setup waits, provision continues without a worker. The driver mints a fresh token and starts the worker with `tasks.worker` afterwards. A dedicated worker operation fails with `tasks.worker_enrollment_expired` without ending the group. Operation input keeps only the public worker settings and an opaque startup reference.

API output, Activity, logs, queue jobs, operation steps, and results never contain either secret. Idempotency fingerprints exclude both secrets. Replays with newly minted credentials return the same operation and do not replace its staged credentials; use a new key for a new worker start.

Systemd loads each secret from a mode-0600 startup file under `/run` into its runtime credential directory before starting the worker. Orbit then removes the startup file and the Gateway's staged copy. The runtime credentials at `%d/enrollment` and, when supplied, `%d/server` stay readable to the `orbit` account, including code running in the VM, while the service runs. Their scope is one group. Swamp uses them for reconnection; systemd removes them when the service stops. No token is written to the image, checkout, unit text, command arguments, or persistent VM disk.

The service reconnects across socket interruptions but does not restart after a process exit or VM reboot. Parking stays allowed and stops the worker with the VM. Use [start a group worker](#start-a-group-worker) with a fresh token after parking or process failure. Successful startup means systemd executed the process; it does not prove enrollment. The orchestrator confirms enrollment through Swamp before dispatching work.

Orbit stops the worker and its children before ending its group or removing its workspace, ahead of head preservation, Project teardown, or VM deletion. If the agent cannot confirm stop and the provider reports the VM running, Orbit powers it off and marks it parked. Cancellation ends that unreachable group and retains its workspace for cleanup. If power-off also fails, group ending returns `tasks.worker_stop_failed`; a deletion attempt still proceeds. VM deletion takes priority over unpublished work when the worker cannot be stopped. Cancelled or failed startup removes its staged credential.

##### Public provision identity

`analysis_base` is null when the pin is unavailable, otherwise exactly `{base, instance_id, repository, branch}`. `base` is a full lowercase 40- or 64-hex Git commit object ID. `instance_id` is a positive integer equal to result `instance_id`; `repository` is the recorded Project repository identity and `branch` is the recorded workspace branch, both nonempty strings.

Validate the repository with the existing Git-origin rules (no HTTPS credentials, SSH password, query, or fragment) and the branch with the existing Git-branch rules. An unsafe identity recorded by provision makes the entire public pin unavailable; never publish credentials or redact a secret into a different supposedly exact identity. These are the identities used by [analysis admission](#fixed-execution-and-complete-report), not new caller inputs. Existing result `node_id` identifies the provisioned Node; it is not a moving location lookup.

Provision records the pin once under its workspace lease, before release. The public object copies that validated record, not the current Project settings, workspace HEAD, `starting_commit`, or remote refs. Pin capture uses the initial merge base described [below](#complete-typed-plan). A divergent starting commit can therefore differ from `analysis_base.base`. Persist the result's identity with the existing provision checkpoints so recovery after release and before terminal result persistence retains that same pin. Reads, recovery, and replay under the same key never recalculate it, fetch, or adopt a supplied base. Later default-branch movement, another child, VM parking, or VM destruction cannot change the terminal result.

A recorded null base, absent pin, invalid object ID, incomplete or invalid identity, or pin for another provisioned Instance yields `analysis_base: null`. Do not return a partial object, fail an otherwise successful provision solely for unavailable analysis, or repair the pin from today's `origin/main`. A terminal result recorded without an `analysis_base` member also means unavailable; reads do not backfill or mutate old results. Analysis still fails closed with `tasks.analysis_unavailable` when no valid matching pin exists; a different supplied base remains `tasks.analysis_stale`. Publication of the pin does not authorize a base update or bypass fresh workspace checks.

For example, this successful result names a starting commit on a divergent workspace and an earlier fixed merge base:

```json
{
  "instance_id": 634,
  "node_id": 91,
  "starting_commit": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
  "analysis_base": {
    "base": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "instance_id": 634,
    "repository": "https://github.com/example/project.git",
    "branch": "task-2074"
  }
}
```

The CLI follows the operation with the shared operation display. It accepts `--idempotency-key=KEY` and `--json`. The PHP SDK request accepts `idempotencyKey`, and the generated MCP tool `tasks-provision` accepts `Idempotency-Key`. A replay returns the same operation before claim validation and claims no second VM; see [Retry a start with a key](/reference/operations#retry-a-start-with-a-key).

The MCP tools `tasks-provision` and `tasks-worker` take the enrollment token as an argument, so it reaches the caller transcript. Gateway redaction does not remove it from that transcript.

To stop a waiting provision, use `tasks:cancel`. Group cancel and complete first cancel the group's open operation. Cancelling provision clears its claim reservation, so cleanup can remove what it created. Cancelling the operation alone does not end the group. Group cancellation keeps the existing rule for an unreachable Node without a possibly running worker: end the group, keep the workspace, and request removal assistance. A worker stop must be confirmed first.

#### Start a group worker

`POST /api/v1/task-groups/{group}/worker` starts keyed operation `tasks.worker`. Send the same `{ worker: { orchestrator_url, enrollment_token, labels } }` object as optional provision enrollment, with a required `Idempotency-Key`. The group must be external and open with a ready workspace and its own task VM.

The operation holds the group target, resumes a parked VM through the normal wake path, then starts or replaces the worker under the workspace lease. It preserves the group, checkout, and task state. A fresh key and token replace the previous worker; replaying a key returns its original operation without restarting anything. An expired staged credential fails before a resume or any new VM allocation. Worker startup never allocates another VM.

Optional provision enrollment remains a shortcut for the initial worker. The driver uses `tasks.worker` whenever it needs a worker again on that same group. Cancelling a waiting worker operation discards its staged enrollment; ending the group also stops any running worker.

#### Check an external group

`tasks:check <group> --subtask=<id> --phase=baseline|handoff` starts `tasks.check` through `POST /api/v1/task-groups/{group}/check` with `{ subtask, phase }`. It uses the [shared task-operation rules](#provision-an-external-group), including the group target hold and no Gateway retries. It accepts `--idempotency-key` and `--json`; the PHP SDK and generated MCP tool expose the same inputs.

A workspace is required (`409 tasks.no_workspace`). A subtask outside the group returns `404`. The current subtask is the open one with the lowest position; another returns `409 tasks.subtask_not_current`. A baseline after an implementer has started anywhere in the group returns `409 tasks.baseline_done`.

A parked VM queues its resume first. The operation waits for readiness and for its own check lease, after any implementer turn releases ownership. It starts no check inside a turn's receipt scope. The detached [Project check](#project-check) runs in the workspace. A baseline runs Project setup first; a handoff verifies deliverables. Delayed reads claim the waiting operation, then reconcile the group's workspace lease to record the reading. A busy lease keeps the operation `waiting`. A lost start reply also keeps the operation `waiting`, with its recorded check and lease held until recovery proves the check ended or confirms its stop.

The request stays exactly `{ subtask: positive integer, phase: baseline|handoff }`, with the existing optional idempotency key; Swamp always supplies a key. There is no caller-selected CI switch, check name, branch, or SHA.

Hosted handoff CI implements the narrow check prerequisite in [ADR 0229](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations#check), not the wider external review-and-merge contract. External review-and-merge create and topology append still return `tasks.orchestration_unsupported`. Tests use isolated external fixtures whose Project requires CI; they do not change production admission or enable a live Project. Ordinary external groups without this policy keep their existing path.

The result keeps `outcome` (`passed`, `failed`, or `changed`), `deliverables`, `check_id`, `commit`, `head`, `tree`, and redacted `output`, and adds `ci`. Each deliverable result holds its `id`, `outcome` (`passed`, `failed`, or `not_checked`), and `failures`. Baselines and review deliverables use `not_checked`. `head` is the pre-commit check snapshot; **`commit` is the candidate HEAD**, not `head`. The human CLI shows the check outcome and check ID, plus the commit when one exists; its exit status follows the operation status, so consumers must inspect `outcome`.

For a handoff whose `Project::handoffCheckName()` is non-null, the existing commit step creates one fresh sanitized commit, even for an unchanged checked tree (`allowEmpty: true`), records it on the check, and verifies its tree against the passed tree. The [handoff policy](#handoff-ci) owns message sanitization. The commit and `refs/orbit/check-operations/{operation}` receipt are written in one Git reference transaction. Recovery reads that receipt before checking the old snapshot, so a lost reply cannot create a second candidate. An uncertain remote result retains the workspace fence.

Same-operation replay/recovery retains that commit, including after an interrupted reply; it creates no second commit. A newly authorized operation with a new key creates a fresh commit even if the previous workflow was cancelled and the checked tree is unchanged. Baseline and Projects without this policy retain their commit behavior and return `ci: null`. The policy follows `reviewsAndMerges()` today, not an independent caller option.

Two persisted steps follow commit: handoff push, then hosted CI read. Operation state records the required check name and `task-{group}-handoff` branch before push. Recovery reads that same plan; later Project edits cannot change which check must pass for the candidate. Push only the operation's recorded commit, without force, to that handoff branch. Never open a pull request on it, push the task branch, or create another commit for CI.

Reconcile the existing VM push record and remote tip before repeating a push after an interrupted reply. The operation reuses the handoff push, shared GitHub check snapshot, classification and output helpers, without lifecycle callbacks.

`ci` is null for baseline/no-policy, or has this shape. The same evidence is persisted in operation state while waiting; it is not a second check-status schema.

| CI field | Contract |
| --- | --- |
| `branch` | The pinned `task-{group}-handoff` branch |
| `commit` | The exact recorded handoff commit, equal to result `commit` |
| `check_name` | The required hosted check name pinned before push |
| `state` | `passed`, `failed`, `pending`, `missing`, or `unreadable` |
| `fault` | `change`, `infrastructure`, `configuration`, or null |
| `pushed_at`, `observed_at` | ISO 8601 times, or null before the corresponding push/read |
| `runs` | Existing `GitHubCheckRun::toArray()` records: `name`, `conclusion`, `url`, `id`, `started_at`, `status`, `head_sha`, `app_slug`, `output_title`, `output_summary`, `check_suite_id` |
| `suites` | Existing `GitHubCheckSuite::toArray()` records: `id`, `status`, `conclusion`, `app_slug` |

CLI `--json`, the PHP SDK's `OperationResponse::result`, and MCP operation reads carry the same result. No new API, CLI verb, SDK request or MCP input is added. Regenerate OpenAPI, recorded SDK fixtures, MCP tools and web types when their emitted contracts change; generic operation-result schemas can leave those generated bytes unchanged. This documentation prerequisite changes no generated runtime contract.

Run and suite text uses the redacted, bounded [check-output rule](#check-output), not raw logs. Pending, missing or unreadable CI keeps the operation `waiting`, with delayed bounded reads, the group target held, and no worker held. A local pass is not a completed usable candidate while required hosted CI waits. The operation result remains terminal evidence; waiting CI evidence lives in operation state. The operation dispatches one VM push and observes its persisted record; a failed push remains waiting with infrastructure evidence without selecting a retry policy. The orchestrator may cancel and investigate it.

| Hosted CI observation | Operation and check result |
| --- | --- |
| Required check passes on the recorded commit | `succeeded`, `outcome: passed` |
| Code/workflow failure under the shared classification | `succeeded`, `outcome: failed`; check `failed_step: ci`, with redacted failure evidence |
| Pending, missing, or unreadable | `waiting`; keep the evidence and read again after a delay |
| Infrastructure/configuration evidence, including no suite after 600 seconds or completed suites classified as configuration because the named job is missing | Report the existing classification while waiting; never silently charge a failed-code budget or start a fixup |
| Post-check workspace movement | `succeeded`, `outcome: changed`; no usable candidate |

The orchestrator owns the wall-clock budget and may cancel observation and park for assistance. The Gateway posts no reminder, assistance or next turn, and never implicitly merges main. The [handoff CI](#handoff-ci) classification and output helpers are shared. A lost local check keeps the group held and the operation `waiting`; cancel the group to end it.

Only a completed successful `tasks.check` with `outcome: passed`, a handoff `TaskCheck`, non-null commit, checked tree and satisfied deliverables supplies a checked candidate. Its source is the operation ID, check ID, owning child, commit and tree, never a caller-supplied SHA. Reviewer and publish admission share one hosted-CI eligibility predicate. When the Project requires CI, the owning handoff operation must have succeeded with passed CI on that check/commit. A locally passed check alone, including one left by cancelled CI observation, is ineligible for both consumers. This is a narrow consumer guard, not final-review publication gating or supported external review-and-merge admission.

Land this prerequisite without changing admission or orchestration ownership. Swamp adoption and the wider review-and-merge contract are separate. Roll back caller adoption first. Before reverting runtime support, cancel or finish affected operations through the existing operation API and reconcile any started push; uncertain local work stays fenced. Preserve recorded check/commit/CI evidence and the already pushed branch until normal group cleanup. A rollback never weakens candidate eligibility, opens admission, changes a group's orchestration, or cancels GitHub workflows.

A direct `operation:cancel` stops a waiting local check as `tasks:check:cancel` does. Orbit records the cancel request first. An unconfirmed stop returns `502 tasks.check_unreachable`, keeps the operation `waiting`, and retries the stop on its next delayed read. Group cancel and complete end the open operation first. When the VM does not answer, they end it `cancelled` without that 502 and follow the existing [unreachable Node rule](#cancel-a-stuck-task).

For hosted handoff CI, cancel stops a queued push under the existing publish stop rules; a started push must be reconciled before ownership ends. Once only hosted CI waits, cancel ends observation, not GitHub workflows. It does not erase the already pushed branch or check evidence. A cancelled observation cannot supply an eligible candidate even if its local `TaskCheck` remains passed.

#### Run a tracked repository script

`POST /api/v1/task-groups/{group}/script` starts `tasks.script`. Send `{ "subtask": 123, "script": "scripts/dependencies", "args": ["routine"] }` with a required `Idempotency-Key`. The group must be external and open, with a provisioned task VM and the current open subtask. The operation holds the group target and the existing workspace lease. It does not create a check, agent turn, commit, or dependency record.

`script` is a relative repository path with no empty, `.` or `..` components. It must name a regular executable tracked as executable at HEAD, with unchanged file content and no symlink in its path. Orbit pins the script digest, HEAD, whole-workspace tree, and literal argument list before dispatch and verifies them again in the owned process. Arguments are strings, not shell source. There is no caller command, environment, working directory, or interpreter field. Do not put secrets in arguments. The script runs from the repository root with the task account's tools and no Gateway credentials. Repository code owns its commands and version policy.

Read the normal operation endpoint until it ends. A completed script returns `{ outcome, exit_code, output, output_truncated, changed, head, tree }`. `outcome` is `passed` for exit zero and `failed` for nonzero; both are successful operation completions. `output` combines stdout and stderr into a redacted tail of at most 16 KiB. `output_truncated` reports discarded output. `changed` compares HEAD and the whole-workspace tree before and after execution. These results are not check evidence or approval; run the existing handoff check before publishing.

The detached runner uses the existing run receipt and cgroup supervision. Lost start replies reconcile the reserved run; they never blindly dispatch it again. A reservation proven not started is tombstoned and fails as `tasks.script_not_started`. An unavailable tracked executable fails as `tasks.script_unavailable`. Unknown process or lease ownership keeps the operation waiting and the workspace fenced. Cancellation must prove the process and descendants terminal before releasing ownership. A script has a fixed one-hour limit. An interrupted run reports failure after terminal proof; it is not an automatic retry.

## Analyze an external group's repository

The fixed read-only analysis operation follows [ADR 0229](/decisions/0229-let-an-orchestrator-drive-task-work-through-gateway-operations#fixed-repository-analysis). Repository analysis and turn evidence do not establish live interruption or docs-workflow proof. They do not open external review-and-merge or topology admission.

`POST /api/v1/task-groups/{group}/analyze` starts `tasks.analyze`, served by the Gateway with target `task_group`. It requires `Idempotency-Key` and accepts exactly `{subtask, plan, plan_sha256, base, head, tree}`. The complete JSON request is at most 48 KiB. Unknown fields refuse. There is no caller command, argument list, environment, executable, working directory, report path, model, or analyzer selector. API, SDK, CLI `tasks:analyze`, and generated MCP metadata share the native request contract; clients do not define competing schemas or aliases.

The usual access, current-open-child, group target hold, parked-VM resume, and workspace rules apply. Tick groups, ended groups, missing workspaces, foreign or noncurrent children, and busy groups keep their existing refusals. The start returns HTTP 202 with `{data: OperationData, meta: {request_id}}`. Follow or cancel it through the existing [operation routes](/reference/operations#read-an-operation). The result is null until terminal. Analysis changes no board or child state, starts no Pi turn, creates no `TaskCheck` candidate, commits or pushes nothing, and advances no workflow stage. Baseline and handoff checks keep their existing inputs and purpose.

##### Complete typed plan

The planning owner asserts the complete cumulative inventory before feature runtime edits. Include source, tests, docs, generated outputs, and new files that do not exist yet. The initial docs child plans the whole feature, not just its own edits. Later revisions retain the group's earlier paths. Shape validation cannot prove completeness of future work; the reviewer compares the inventory with the brief and deliverables.

| Manifest field | Contract |
| --- | --- |
| `id` | Stable identifier, 1–64 characters from `A-Z a-z 0-9 _ -` |
| `revision` | Positive integer; increases for every change |
| `source` | `{brief_id, revision_sha256}` identifying the source brief; the digest hashes its exact bytes before prompt formatting |
| `asserted_by` | Nonempty planning actor identifier, at most 128 characters |
| `complete` | Must be `true`; a partial plan is refused |
| `paths` | Nonempty list of `{change: add\|modify\|delete, path}` or `{change: rename, from, path}`; rename contributes both endpoints to analysis |

Every path names a file relative to the repository root, encoded in UTF-8 and at most 512 bytes. Reject absolute and drive paths, backslashes, empty, `.` or `..` components, control characters, leading or trailing whitespace, globs, directories, `.git` components, and symlink escapes. Do not trim or repair input. Add paths need not exist; delete and rename-from paths must exist in the base or current tracked inventory. Conflicting or duplicate entries refuse the entire request.

Entries arrive sorted by `(path, change, from-or-empty)` in UTF-8 byte order. The manifest has at most 256 entries and at most 32 KiB of RFC 8785 canonical JSON. `plan_sha256` hashes that entire canonical manifest; all SHA-256 values use lowercase hex. Shared JSON/hash fixtures fix encoding across native consumers. Overflow refuses the whole inventory; no path is dropped.

`base`, `head`, and `tree` are full Git object IDs in the repository's object format, resolved in the owned task VM, never branch names. The base is the group's recorded initial merge base with `origin/main`, fixed across children.

Provision calls `analysis-base` in the existing task-check program. It finds the merge base of the Instance's starting commit and local `refs/remotes/origin/main`. A missing starting commit, local ref, or common ancestor leaves the base unavailable; it never falls back to the current HEAD.

Analysis performs no fetch, merge, or base adoption. Use the successful provision operation's [public pin](#public-provision-identity) for `base`, never infer it from `starting_commit` or a later remote ref. This contract exposes no base-update path. Any separately authorized future base-update contract must keep old evidence and require a new plan revision and analysis. After handoff, the candidate HEAD is `check.commit`, not the check result's pre-commit `head`.

Gateway verifies the base object and ancestry and takes fresh HEAD and whole-worktree snapshots under the lease before dispatch and after completion. The existing snapshot includes uncommitted and unignored untracked files without changing the real index; clean HEAD alone is insufficient. Pin repository identity, actual branch, Instance, Node, lease, execution identity, and the fixed entrypoint's tracked content digest. Ignored caches are not candidate evidence. Reports and run metadata live outside candidate paths and must not alter the analyzed tree.

##### Fixed execution and complete report

Persist steps `workspace_ready`, `analysis`, and `report` on the existing operation. Reserve one run identity before dispatch. Existing detached check supervision runs only tracked regular executable `bin/task-analyze`, at the pinned digest, with no arguments and validated JSON on stdin containing the plan, base, candidate, and Gateway-issued execution identity. It runs as the workspace owner without model or GitHub credentials or approval authority. Missing entrypoint or tooling is unavailable; there is no shell, host-checkout, or agent fallback. This narrow read-only profile is the [one fixed analysis exception](/reference/node-agent#fixed-read-only-task-analysis) to the supervision boundary; it adds no Gateway Node script or script-ratchet exception.

Orbit's repository adapter calls the existing `DocsImpact::report(base, paths)` with the complete flattened inventory once. The scanner still unions planned paths, actual tracked changes, and untracked files, retaining deletions and both rename endpoints and inspecting current and base surfaces. Keep its mappings and payload validation in the repository, not Gateway policy. Validate all existing report fields: `base`, `paths`, `impacted_pages` with every reason, `surfaces` with ownership and generator status, `surfaces_handled_by_generator`, `errors`, and `verdict`. Reuse the existing report unchanged, not a second scanner or replacement docs report model. Validate its base and path union against input and actual workspace. Generator checks run in check mode only, never write mode.

Stdout is exactly one strict UTF-8 JSON envelope:

```json
{
  "schema": 1,
  "execution_id": "gateway-reserved-run",
  "plan_sha256": "<lowercase SHA-256>",
  "base": "<full Git object ID>",
  "head": "<full Git object ID>",
  "tree": "<full Git object ID>",
  "status": "ready",
  "diagnostics": [],
  "report": {"schema": "orbit.docs-impact.v1", "data": {"...": "complete existing DocsImpact report"}}
}
```

The placeholder report above illustrates the envelope, not a valid reduced payload. `status` is `ready`, `action_required`, or `blocked`; diagnostics are a list of `{code, message}`. Require every envelope field.

Reject duplicate keys, non-finite numbers, prose prefixes, trailing bytes, malformed or truncated JSON, invalid UTF-8, and wrong echoed identities. The entire envelope is at most 64 KiB and JSON depth 24. Overflow fails; it never yields a shortened successful artifact. Stderr is only a separate bounded, redacted diagnostic tail. If redaction would change structured evidence, refuse it rather than silently changing its meaning. Gateway validates the generic envelope and bounds, cross-checks identities against stored execution, and pins its own snapshots. The repository validates payload semantics. Repository evidence is never privileged instructions.

On a nonzero exit, the adapter can instead emit a bounded failure object on stdout: `{schema: 1, error: {code, required_bytes}}`. The only codes are `tasks.analysis_unavailable` with null `required_bytes`, and `tasks.analysis_report_too_large` with the full envelope's required byte count above 65536. These are failures, never report artifacts.

The existing runner carries the complete bytes unchanged. Gateway validates the closed shape and types, then retains the typed code and size after terminal proof, before release. The [public failure result](#public-analysis-failure-metadata) carries this safe diagnostic without exception text or stderr. The report bound is unchanged.

| Valid repository report | Analysis status |
| --- | --- |
| `no_docs_change`, empty errors, all required generator checks passed | `ready` |
| `docs_required`, no unresolved errors and no missing, stale, or failed generators | `action_required`: provide to the docs author; not permission to start feature runtime edits before docs review |
| Errors, contradictory verdicts, unresolved or unowned surfaces, unavailable or failed generators | `blocked`, even if the scanner process exits 0 |

A valid completion stores `{outcome, artifact, provenance}` in the keyed operation result. `outcome` is `ready`, `action_required`, `blocked`, or `changed`. A valid blocked report may finish with operation status `succeeded`; that status alone never authorizes a turn. Movement of the checkout produces unusable `changed` evidence and preserves any complete report for inspection only. A nonzero exit, invalid or missing report, overflow, or unknown execution outcome cannot produce ready or action-required evidence.

`artifact` is `{sha256, bytes, content}`. Hash and byte length describe the exact UTF-8 stdout bytes; `content` holds those bytes as a JSON string. Verify and parse the complete content, not reserialized JSON or a log tail. Retain the full bounded bytes beside existing operation state, with a response budget large enough for JSON encoding and all bounded fields. Keyed operation and artifact retention survive VM destruction. Existing access checks govern reads; the VM reader addresses only this operation's run record, not arbitrary files. Add no artifact service, durable table, or approval ledger.

Gateway-owned provenance contains `operation_id`, `group`, `subtask`, repository, branch, `plan_id`, `plan_revision`, `plan_sha256`, `base`, `before: {head, tree}`, `after: {head, tree}`, `instance_id`, `node_id`, `lease_id`, `execution_id`, `entrypoint_sha256`, `started_at`, `finished_at`, `duration_ms`, `exit_code`, and `mechanical_llm_calls: 0`. These are typed identities and measurements, not log extraction. Times use ISO 8601 UTC and duration uses a monotonic clock. Record planning, writing, and review calls separately; label unavailable measurements. Claim no savings without a comparable before measurement including checker/repository revision, plan size, elapsed time, calls, and token metrics.

##### Replay, stop, and fencing

Read-only analysis takes the same exclusive group holder and workspace lease as author and check work. No author, check, publish, or write-mode generator may overlap it. An existing lease waits without dispatch. Release the operations worker during waits, not the group target or workspace fence; other targets can still run. Out-of-band edits invalidate the result. Temporary analysis or cache work outside candidate paths must also terminate before release.

After access checks, same key, target, and input replay the stored operation before mutable eligibility checks. Changed input conflicts. Lost start replies, worker restarts, or lost result replies adopt the reserved run and its retained exact report bytes without another scanner invocation. When recovering a missing start, supervision atomically tombstones that reserved identity before concluding that it never ran. The tombstone ends that attempt without dispatch; a new attempt requires a new key.

Once dispatched, a missing retained report fails for inspection only after local terminal proof. Never rerun it under the same key, including after artifact or Gateway persistence loss. Terminal replay stays terminal and starts nothing.

Cancellation before dispatch prevents execution. After dispatch, request stop and reconcile the exact process or unit and its descendants. A timeout, missing PID, elapsed budget, or transport loss is not terminal proof. Unknown or unreachable work remains waiting and fenced until termination is proven, including during cancellation. Malformed transport likewise ends `failed` only after local terminal proof, preserving the failure code and safe diagnostics. Reuse existing stop and journal recovery; add no worker or supervision framework. Cancellation never turns saved evidence into approval or bypasses the existing unreachable-workspace cleanup rule.

Changing plan revision or digest, source brief, base, repository, entrypoint content, HEAD, whole tree, or owning workspace invalidates use for a new turn. Keep prior evidence immutable and authorize a new operation and key. A changed in-flight plan must stop progression and reconcile the existing owner before a new request; do not overwrite its input. Gateway checks the supplied evidence identity, not the caller's latest intentions. The latest authorized plan and actual-versus-planned progression belong to later Swamp adoption, not a new Gateway ledger.

##### Analysis-bound turn evidence

Existing `tasks.turn` gains optional `analysis: {operation, plan_sha256}`: a positive operation ID and lowercase SHA-256. Omission preserves ordinary turns. Resolve a terminal successful `tasks.analyze` for the same group and current child. Verify the reference digest, complete artifact and usable outcome; only `ready` or `action_required` can supply evidence. A blocked or changed report, foreign child, or nonterminal operation cannot.

Immediately before the first Pi send, compare fresh candidate, base, repository, workspace, and entrypoint identity under the existing turn lease. Stale evidence refuses without a send. Reviewer admission still requires its ordinary eligible handoff check, including required hosted CI; the check's commit and tree must also match the fresh analysis. Analysis is not a checked candidate or a substitute for that eligibility rule.

Add the full manifest, validated envelope, and Gateway provenance to the caller's semantic prompt, labelled as repository evidence. Enforce the existing 100,000-character bound on the combined prompt, including fixed instructions and evidence, before sending. Overflow refuses with no send; no summary, shortened tail, truncation, or host-file mount substitutes for the report. A smaller task or plan, or a separately reviewed transport design, is required.

Persist the exact assembled prompt, reference, and hash in turn state before dispatch. Verify that retained reference and complete prompt hash before reuse; a corrupt binding fails without another send and does not release an uncertain owner. After confirmed acceptance, same-key recovery retains the original Pi/Durable send identity and persisted prompt. It recovers the original turn rather than revalidating the intentionally edited checkout and starting a second send.

A local dispatch journal alone does not prove acceptance. If an unconfirmed request now has stale evidence, wait under the original lease without resending; a negative observation does not stop a delayed request from arriving later. A terminal observation for another key cannot release that lease. Uncertain work has no timeout-based release, including after a crash between journaling and transmission. Freshness checks govern first admission and unconfirmed retries, not recovery of a confirmed dispatched turn.

The initial report is input to its author turn. Author edits intentionally invalidate its candidate identity. After the normal handoff commit and check, analyze the current checked candidate again before an evidence-bound reviewer turn. Preserve initial and current operation IDs in caller evidence. Rework or entry to the next child needs fresh evidence when the plan or candidate changes; never rerun baseline for this purpose.

##### Public analysis failure metadata

A terminal `tasks.analyze` failure can carry a non-null operation `result` without becoming successful. Its entire failure result is exactly `{schema: 1, error: {code, required_bytes}}`; no `outcome`, `artifact`, report fragment, or success provenance is included. Operation `status` remains `failed`, and `error.code` must equal the operation's original `error_code`. The existing failed-step identity is preserved. The successful `{outcome, artifact, provenance}` result is unchanged.

| Failure result field | Contract |
| --- | --- |
| `schema` | Integer `1` |
| `error` | Object containing exactly `code` and `required_bytes` |
| `error.code` | Only `tasks.analysis_report_too_large` or `tasks.analysis_unavailable` |
| `error.required_bytes` | For overflow, an exact validated JSON integer from 65537 through 9007199254740991 inclusive, or null if the exact size is unknown; for unavailable tooling, always null |

The integer ceiling is the largest integer exactly representable by all supported JSON consumers. Reject strings, booleans, fractions, non-finite numbers, negative or in-bound overflow sizes, and larger integers; never round or clamp. The adapter's overflow failure object requires a known integer in that range, not null. Null in the public overflow result is reserved for overflow detected by the Gateway when the exact length of the complete envelope is unavailable. The bounded two-code object is the diagnostic; there is no free-text `message` field to redact or parse.

For example, an operation read for a validated adapter overflow includes these fields (other ordinary operation fields and steps are omitted here):

```json
{
  "kind": "tasks.analyze",
  "status": "failed",
  "error_code": "tasks.analysis_report_too_large",
  "result": {
    "schema": 1,
    "error": {
      "code": "tasks.analysis_report_too_large",
      "required_bytes": 216325
    }
  }
}
```

Unavailable tooling uses `error_code: tasks.analysis_unavailable` and `result: {"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null}}`. This does not add metadata to synchronous admission-error responses. Failures before an owned analysis run is reserved, other analysis codes, and non-analysis operations retain their existing result behavior; they cannot acquire this payload merely because an exception or output contains one of these code strings.

Accept an adapter failure only from complete bounded strict UTF-8 stdout of the exact reserved owned run, after a nonzero exit and authoritative local terminal proof. Validate the same closed schema, duplicate-key/depth/number rules, completion identity, and run/workspace/lease binding used by the analysis transport.

The adapter object has no caller-supplied provenance: association comes from the Gateway reservation and supervised run record. Wrong-run, foreign-owner, zero-exit, malformed, unknown-code, extra-field, or forged completion data cannot supply a size or tooling diagnosis. Reject malformed failure objects as `tasks.analysis_report_invalid` with null result, rather than salvaging an embedded number. No arbitrary runner hint, exception message, output tail, stderr, or private operation-state dump is a metadata source.

For Gateway-detected overflow, publish an exact size only when the complete byte count is validated against the owned supervised completion; a tail, prefix, buffer limit, or unverified `report_bytes` value is not the full envelope's size. Otherwise return null for the size while retaining `tasks.analysis_report_too_large`. Never measure the small failure object's length as the required report size. The 65536-byte/depth-24 report bound remains absolute: the 216325-byte full report, its 162390-byte compact form, and a 69452-byte report are all unusable. Do not emit any partial report or raise the bound.

Persist only validated typed failure fields in the existing terminal checkpoint after terminal proof and before releasing the workspace lease. Recovery validates that checkpoint and its original operation, run, workspace, and lease association, releases only that owner, and produces the same failed outcome through the existing operation-result facility. Persist terminal status, original error code, failed step, and result together. A crash before release, after release, or before terminal result persistence cannot discard the exact known size, dispatch again, or turn failure into success. Do not derive metadata from a checkpoint's exception text when typed fields are absent.

Queued, running, or waiting operations expose no terminal result. Unknown termination, unknown descendants, transport loss, and foreign ownership retain fencing and do not publish terminal failure metadata. Cancellation retains the existing stop rules and never fabricates a failed analysis result; a cancelled operation does not expose this payload. A cancel request after terminal failure leaves its recorded result unchanged. Repeated HTTP reads, sequence-filtered reads, same-key replay, and VM cleanup preserve the terminal payload under existing access checks and keyed retention.

##### Metadata compatibility and generated surfaces

These additions use only existing operation reads and `result`; no endpoint, request option, top-level operation field, table, or ledger is added. API get/list reads and same-key start replies, CLI `--json`, PHP SDK `OperationResponse::result`, and MCP operation reads carry the same nested fields. Clients branch on `kind`, `status`, and the documented result shape, not on whether `result` is non-null. The CLI still exits unsuccessfully and displays the original failure code; human display and step output are not machine metadata contracts.

Older records and older Gateways can lack provision `analysis_base` or return null for failed-analysis `result`. Treat those as explicit unavailable base or unknown required size; retain the original typed code and full supported operation. Do not parse error strings, guess values, or reinterpret a missing field as success. This is absence handling in additive public data, not a second execution or compatibility path. No read-time backfill or new request variant is supported.

Implementation must check native OpenAPI, recorded SDK fixtures, generated MCP tools, and web operation types against the same contract, and add API/CLI/SDK/MCP parity coverage. Regenerate emitted artifacts only when their generator inputs change; a generic object-valued `result` can leave generated schemas unchanged, but not excuse missing value-preservation tests. Roll out the producer before caller adoption. On rollback, stop adoption first, reconcile active owners through existing cancellation/recovery, and preserve terminal results and pins without recomputation or extraction from old logs.

Implementation regressions must fail before the change through actual public operation HTTP reads, not only private state assertions. Exercise the real adapter's oversized-report and unavailable-tooling outcomes, repeated reads and same-key replies, crashes around terminal checkpoint/release/result persistence, malformed and forged payloads, unsafe sizes, non-analysis failures, unknown termination, cancellation, and foreign ownership.

A divergent starting-commit fixture must prove the public base equals the immutable pin accepted by analyze, not the start commit; move `origin/main` and replay without changing that pin. Missing or invalid pins remain unavailable. Keep existing report, lease, provision, check, and turn regressions, test limits, and script ratchet intact; reuse valid unchanged proof rather than blindly rerunning full suites.

##### Analysis failures and delivery limits

| Condition | Response or terminal evidence |
| --- | --- |
| Missing key, invalid shape, digest, path, unknown fields, or oversized request | HTTP 422 `validation.failed`; no dispatch |
| Missing or nonancestor base, mismatched HEAD or tree | HTTP 409 `tasks.analysis_stale`; no dispatch |
| Missing entrypoint or tooling | HTTP 422 `tasks.analysis_unavailable` before dispatch, or failed operation with that code when discovered later |
| Invalid, missing, truncated, or wrong-identity report | `tasks.analysis_report_invalid`; unusable |
| Envelope overflow | Failed `tasks.analysis_report_too_large`; [typed size or null](#public-analysis-failure-metadata), no partial artifact |
| Exit 0 with report errors, unresolved surface, or missing/failed/stale generator | Valid `outcome: blocked`, with all diagnostics |
| Nonzero exit or checkout mutation | `tasks.analysis_failed`, or valid `outcome: changed` saved for inspection; no green fallback |
| Lost transport or unknown termination | Waiting with the same owner and run; retain fencing |
| Foreign, nonterminal, or unusable analysis on a turn | HTTP 422 `tasks.analysis_not_usable`; no send |
| Stale evidence at first-send preparation | HTTP 409 `tasks.analysis_stale`; no send |
| Combined prompt overflow | HTTP 422 `tasks.analysis_prompt_too_large`; no send |

An admission defect discovered after admission fails the operation with the same code only once safe to release. Existing access and task errors remain unchanged. Tests use real checker output in isolated Git fixtures, covering new and untracked files, deleted and renamed sources, unresolved ownership, generator failures, invalid plans and complete-report transport, changed identities, competing leases, cancellation and unknown descendants. Fault injection covers reservation, dispatch, terminal artifact persistence, Gateway result persistence, and turn preparation/send. Prove same-run/report and same-send recovery, not just a green mocked verdict. Ordinary baseline/no-policy checks and turns without analysis retain their request, result, and recovery behavior.

Overflow fixtures use fewer, longer valid paths to exceed the report byte limit without hundreds of per-path Git lookups. They still run the actual checker and verify the reported required size. Keep the test time limits unchanged.

At handoff, the existing [docs-impact gate](/contributor-guide#2-write-the-documentation) is authoritative. It checks actual files and retains accepted `exceptions` evidence. Review still judges meaning, ownership, completeness, and branch-local page-specific waivers. Machine no-docs is neither semantic approval nor a waiver; required generator checks remain required. Later Swamp adoption compares actual files, including deletes and both rename endpoints, with the full authorized plan: an omission blocks progression until revised, reviewed, and reanalyzed. This native slice supplies evidence, not that lifecycle gate.

No Swamp client change, write-mode generator lane, generic shell API, second scanner, agent role, or approval ledger is authorized here. A needed write generator parks the future deterministic docs workflow until its mutation lane is separately demonstrated and reviewed; it must not fall back to an unowned host checkout or a mechanical agent turn. External review-and-merge/topology admission, live docs-workflow and interruption proof, incoming-PR orchestration, and tick retirement remain separate work. This slice does not rerun ORB2064/PR1503.

Before rollback, stop new analysis requests and reconcile active analysis and evidence-bound turn owners. Restore a compatible release only after added steps are terminal. Preserve keyed operations, reports, provenance, and original turn identities; an older binary must not replay unknown new step plans. No live mode change, database rollback, or tick ownership transfer is needed.

#### Run an external turn

`tasks:turn <group> --subtask=ID --role=implementer|reviewer|consult --prompt=TEXT --thread=new|ID` starts `tasks.turn` through `POST /api/v1/task-groups/{group}/turn`. Optional `--model` selects a new thread's model. `--check=ID` is required for a reviewer turn. The CLI follows the operation and accepts `--idempotency-key` and `--json`; the PHP SDK and generated MCP tool expose the same inputs.

The shared task-operation rules apply. A missing workspace returns HTTP 409 `tasks.no_workspace`. A subtask outside the group returns 404. Only the first open subtask by position can run; another returns HTTP 409 `tasks.subtask_not_current`. A parked VM queues its resume, and the operation waits before preparing or sending the turn.

| Input | Rule |
| --- | --- |
| `thread` | `new`, or a thread of this group with the same role. A consult uses a reviewer thread. Another thread returns 422 `tasks.thread_role_mismatch` |
| `model` | Only for a new thread; a continued thread returns 422 `tasks.model_fixed`. Claude returns 422 `tasks.model_refused`. A model unavailable on the VM fails the operation with `model.unavailable` |
| `check` | An [eligible checked candidate](#check-an-external-group) for this subtask, including hosted CI when required; otherwise 422 `tasks.commit_not_checked`. HEAD must equal its commit, or 409 `tasks.head_moved` |
| `analysis` | Optional `{operation, plan_sha256}` under the [analysis-bound turn contract](#analysis-bound-turn-evidence); absent preserves ordinary behavior. Complete evidence and fresh first-send identity checks do not replace reviewer check/CI eligibility |

The operation holds the group and sends one turn through Pi. The send key is its `Idempotency-Key`, or `operation-{id}`. The session attaches to the subtask and appears on the board. Orbit supplies only its fixed instructions: no fetch or push by the agent, a read-only reviewer, the turn command, and the workspace MCP configuration. The orchestrator supplies the prompt. Orbit adds no rubric, reminder, or nudge.

When the turn ends, a delayed read reconciles the workspace lease, thread state, and metrics. Orbit stores and clears the receipt without acting on its outcome. A `blocked` receipt starts no consult and creates no question or assistance. No receipt produces `receipt: null`. The result includes the receipt's outcome, summary, deliverable confirmations, question and cause; the thread ID and final state; HEAD and the full working-tree hash before and after the turn; and token metrics.

After a reviewer turn, Orbit records the checkout difference, resets to the check's commit, and removes untracked files. The result includes the removed difference and the state after reset. A consult is read-only but keeps the implementer's uncommitted tree: Orbit records its difference and never resets it.

A Pi restart leaves the turn `working` while Durable resumes it. Durable retries transient provider errors inside that turn; the Gateway retries none. A crash-loop park or an exhausted provider retry budget ends the thread and operation `failed`, with the error in the result. Orbit raises no assistance for the external group. New task VMs need the [Durable artifact pin](/reference/pi-server#roll-out-a-new-binary).

Direct operation cancellation interrupts the held subtask turn and confirms that its lease ended. Subtask cancellation first cancels its turn operation, including receipt collection and the reviewer reset, before recording the subtask as cancelled. A cancel received during send waits for the same confirmed stop. An unconfirmed interrupt returns HTTP 502 `tasks.subtask_interrupt_failed` after recording the cancel request. The operation stays `waiting`, retains its target, and retries the stop on the next delayed read.

#### Publish an external group

`tasks:publish <group> --check=<id> [--title=… --body=…]` starts `tasks.publish` through `POST /api/v1/task-groups/{group}/publish`. The JSON input is `{ check, pull_request?: { title, body } }`. Supply title and body together. It uses the [shared task-operation rules](#provision-an-external-group), requires a workspace (`409 tasks.no_workspace`), and accepts `--idempotency-key` and `--json`. The PHP SDK and generated MCP tool expose the same inputs.

The check must be an [eligible checked candidate](#check-an-external-group) belonging to a subtask of this group, with its recorded commit. Publish shares reviewer admission's hosted-CI predicate: when the Project requires CI, a local pass alone does not count; the owning handoff operation must have succeeded with passed CI on that check/commit. Otherwise admission returns `422 tasks.commit_not_checked`. HEAD must still be that commit (`409 tasks.head_moved`). A parked VM queues its resume first; HEAD is checked after readiness as well as at admission when the VM is ready. This prerequisite adds no final-review publication gate and does not open external review-and-merge admission.

The operation reuses the approval push: `PublishTaskVmCommit` imports the checked commit into the Gateway mirror and pushes it to `task-{id}`. A delayed operation read claims `waiting` as `running`, then reads the job record; it never polls the VM. If a started job is redelivered after losing its outcome, it fetches the remote branch through the Gateway mirror. An identical tip records success without another transfer or push; an ancestor allows the unforced push, and a divergent tip fails with `tasks.not_fast_forward`. A pending cancel resolves after that outcome is recorded.

A divergent remote branch fails with `tasks.not_fast_forward`. An identical remote tip succeeds without a push. A refusal for missing `Workflows: write` fails with `tasks.workflows_permission`. Other push failures use `tasks.push_failed`. The Gateway never forces a push, sends a GitHub token to the VM, or retries a failed effect. A new operation with a new key queues one new attempt after a recorded failure; replaying the old key returns its failure.

Without `pull_request`, the operation only pushes. With it, the operation first looks for an open pull request for the branch, updates its title and body when found, or opens one against the Project's default branch. A resumed step repeats that lookup before opening, so it cannot open a second pull request. It runs no brief coverage and requests no reviewers. It stores `pr_url` and the watched pull request fields, so the existing tick watch follows it. The tick records its merge or close without settling or completing the group.

The result is `{ branch, commit, pull_request_number, pull_request_url }`, with null pull request fields for a push alone. Publication changes no board status or settle metrics.

Cancelling before the publish job starts invalidates its queued record, so a late job does nothing, and ends the operation `cancelled`. After the job starts, cancellation stays requested and the operation stays `waiting`, holding the group until the job records success or failure. It then ends `cancelled` and opens no pull request. A direct cancel returns the waiting record. Group cancel and complete return `409 operation.target_busy` until that push ends; retry them afterward. A cancel cannot undo a push or a pull request step that already ran.

### Task lifecycle

A group starts in `backlog` or `todo`. Moving it to `todo` requires at least one subtask and a deliverable on every subtask. Provision reserves capacity, then records `running` once the workspace is ready. Swamp chooses subsequent status updates: `running`, `reviewing`, `settling` and `waiting_for_review`. Explicit completion, cancellation or failed provisioning records an ended status. Terminal history and assistance remain readable.

One group uses one task VM and one branch. Subtasks share the workspace in order. The keyed operations enforce workspace ownership and persist results; they never select the next subtask.

## Tasks and subtasks

List, show, and `tasks:question:list` accept any authorized peer. Update and the subtask create, update, and destroy operations are served by the Node that holds the task's workspace, or by the Gateway for a task without one. The other operations require Gateway access.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:update` | `PATCH /api/v1/task-groups/{group}` | Workspace Node |
| `tasks:list` | `GET /api/v1/task-groups` | Any peer |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Any peer |
| `tasks:provision` | `POST /api/v1/task-groups/{group}/provision` | Gateway |
| `tasks:check` | `POST /api/v1/task-groups/{group}/check` | Gateway |
| `tasks:turn` | `POST /api/v1/task-groups/{group}/turn` | Gateway |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |
| `tasks:cancel` | `POST /api/v1/task-groups/{group}/cancel` | Gateway |
| `tasks:subtask:create` | `POST /api/v1/task-groups/{group}/tasks` | Workspace Node |
| `tasks:subtask:update` | `PATCH /api/v1/task-groups/{group}/tasks/{task}` | Workspace Node |
| `tasks:subtask:destroy` | `DELETE /api/v1/task-groups/{group}/tasks/{task}` | Workspace Node |
| `tasks:subtask:cancel` | `POST /api/v1/task-groups/{group}/tasks/{task}/cancel` | Gateway |
| `tasks:check:cancel` | `POST /api/v1/task-groups/{group}/tasks/{task}/check/cancel` | Gateway |
| `tasks:comment:create` | `POST /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |
| `tasks:comment:list` | `GET /api/v1/task-groups/{group}/tasks/{task}/comments` | Gateway |
| `tasks:group:comment:create` | `POST /api/v1/task-groups/{group}/comments` | Gateway |
| `tasks:group:comment:list` | `GET /api/v1/task-groups/{group}/comments` | Gateway |
| `tasks:question:list` | `GET /api/v1/task-questions` | Any peer |
| `tasks:question:close` | `POST /api/v1/task-questions/{question}/close` | Gateway |
| `tasks:agents` | `GET /api/v1/task-groups/{group}/agents` | Gateway |
| `tasks:agent:show` | `GET /api/v1/task-groups/{group}/agents/{session}` | Gateway |

Each MCP tool name is the operation name with hyphens, such as `tasks-subtask-create`. The paths keep the `task-groups` segment. `{group}` is the top-level task id, and `{task}` is the subtask id.

Create requires `project_id`, `title` (at most 160 characters), and `brief` (at most 8,000 characters). The Project must read its repository through the GitHub App. A Project with [`source_access: gh_cli`](/reference/projects#source-access) cannot start a task, because Orbit publishes only through the App. It accepts an ordered `tasks` array of at most 50 `{title, brief, deliverables}` objects and a `status` of `backlog` or `todo`. The status defaults to `backlog`. Create with `status: todo` records work for Swamp to admit. Create accepts only `orchestration: external`, which is also the default. The API, PHP SDK, CLI option `tasks:create --orchestration`, and MCP expose this field. Update cannot change it. List accepts `project_id` and `status` filters. Show returns the task and its subtasks in position order.

Update changes a task's `title`, `brief`, or `status`. Title and brief change only in `backlog`. Status updates start no work.

For an external group, `tasks:update` also accepts `status` moves among `running`, `reviewing`, `settling`, and `waiting_for_review`. It still moves between `backlog` and `todo`, with the same deliverable requirements, but starts no work. A status update cannot enter `reserved`, leave `reserved`, cross between preparation and active work, or end the group. Only provision moves `todo` to `reserved`. Use `tasks:cancel` or `tasks:complete` to end a group. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing, including on an ended group. These updates need no idempotency key.

A task moves to `todo`, by create or update, only when it has subtasks and every subtask has at least one deliverable.

### Change subtasks

Subtask create appends a `todo` subtask at the next position. It works while the group has not ended. Outside `backlog`, the new subtask needs at least one deliverable.

Swamp selects the next subtask and records its status through the API.

Subtask update changes `title`, `brief`, `position`, or `deliverables`. A `deliverables` value replaces the whole list. Positions stay gapless from 1.

| Task status | What can change |
| --- | --- |
| `backlog` | Every field of every subtask. Subtask destroy deletes a subtask and closes the gap. |
| `todo`, `running`, `reviewing`, `settling` | Every field of a `todo` subtask. Its deliverables cannot become empty. Its position moves only among the `todo` subtasks after the last started or ended subtask. |
| `reserved` | Nothing. Subtask create still appends |
| `completed`, `failed`, `cancelled` | Nothing |

For an external group, subtask update also accepts `status`. A subtask moves from `todo` to `running`, between `running` and `reviewing`, and from either active status to `completed` or `failed`. `completed` and `failed` are final. Only `tasks:subtask:cancel` sets `cancelled`. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing. An ended group permits no subtask status move. Completing a subtask clears its assistance and reconciles the group request as resolution does. A status update starts no agent or check, advances no other subtask, and does not settle the group. The API, PHP SDK, CLI `--status` option, and MCP expose both status inputs.

A subtask that has started keeps its title, brief, position, and deliverables. A deliverables update on it returns `tasks.deliverables_locked` and leaves the stored list as it is, except for the one correction after `invalid_deliverable` described below.

Appending a subtask records new work. Swamp decides when to resume the group.

### Cancel a subtask

For an external group, `tasks:subtask:cancel` cancels a `todo`, `running`, or `reviewing` subtask in an open group. It uses the existing interrupt path to stop the acting thread and any running check before marking active work `cancelled`. A failed interrupt leaves the status unchanged and returns HTTP 502 `tasks.subtask_interrupt_failed`. Cancellation starts no next subtask and does not settle or change the group's status.

### Errors

The task and subtask operations return these errors.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.orchestration_unsupported` | 422 | A group would declare a topology |
| `tasks.status_move_refused` | 422 | An external group or subtask status update attempts a move outside the allowed transitions |
| `tasks.no_subtasks` | 422 | A move to `todo` for a task without subtasks |
| `tasks.subtask_deliverables_missing` | 422 | A move to `todo` while a subtask has no deliverables, and then `details` names each subtask. Also a subtask without deliverables outside `backlog` |
| `tasks.group_closed` | 409 | Subtask create in an ended task |
| `tasks.not_in_backlog` | 409 | A title or brief update outside `backlog`, or a subtask update or destroy that the table above does not permit |
| `tasks.deliverables_locked` | 409 | A deliverables update on a subtask that has started |
| `tasks.deliverable_base_unavailable` | 422 | The repository base or its complete file tree could not be read for path validation |
| `tasks.already_claimed` | 409 | An attempted move after workspace reservation |
| `tasks.subtask_not_running` | 409 | A subtask cancel that the rules above do not permit |
| `tasks.subtask_interrupt_failed` | 502 | Orbit could not stop the implementer or the check |
| `tasks.agent_driver_unavailable` | 409 | The configured agent driver is unknown. No task is stored |
| `tasks.agent_transcript_unavailable` | 409 | A transcript request for a stored `t3` task thread. The row stays, and no stream opens |
| `tasks.agent_unavailable` | 409 | A transcript request for a thread whose Node is gone, when Orbit saved no transcript |
| `tasks.agent_read_failed` | 502 | A [thread read](#read-a-thread) that the thread's Pi server could not serve, when Orbit saved no transcript |
| `tasks.github_app_required` | 422 | Create for a Project with `source_access: gh_cli`. No task is stored |
| `validation.failed` | 422 | An invalid field, such as a deliverable or a position outside the `todo` subtasks |

## Deliverables

A deliverable is one item that a subtask must deliver, in a form Orbit can check. The ADE or an operator writes the deliverables next to the brief. The implementer confirms each one at handoff. Orbit verifies the mechanical ones before the reviewer starts.

Each deliverable has an `id`, a `type`, a `description`, and the fields of its type.

| Type | Fields | Orbit checks |
| --- | --- | --- |
| `file` | `path`: a path or glob from the workspace root. `change`: `created`, `modified`, or `any` | A matching path in the subtask's diff, added for `created`, modified for `modified`, either for `any` |
| `command` | `command`. `directory`, relative to the workspace root, default `.`. Optional `fails_on_base` and `paths` | The command exits 0 on the working tree. With `fails_on_base`, it also exits nonzero on the start commit |
| `review` | none | The reviewer confirms it in its approval |

```json
[
  {"id": "reference-page", "type": "file", "description": "Document the export", "path": "docs/reference/export.md", "change": "modified"},
  {"id": "export-tests", "type": "command", "description": "The export tests pass", "command": "../../bin/pest-plain vendor/bin/pest tests/Feature/ExportTest.php", "directory": "apps/gateway"},
  {"id": "layout-repro", "type": "command", "description": "The home-screen regression fails before the fix", "command": "../../bin/pest-plain vendor/bin/pest tests/Feature/HomeScreenTest.php", "directory": "apps/gateway", "fails_on_base": true, "paths": ["apps/gateway/tests/Feature/HomeScreenTest.php"]},
  {"id": "web-tests", "type": "command", "description": "The web app tests pass", "command": "bun test", "directory": "apps/web"},
  {"id": "error-copy", "type": "review", "description": "Error messages name the failing subtask"}
]
```

| Rule | Limit |
| --- | --- |
| Number | At most five per subtask. At least one outside `backlog` and for a move to `todo` |
| `id` | A lowercase slug such as `export-tests`, at most 64 characters, unique in the subtask |
| `description` | At most 500 characters |
| `path`, `directory` | Relative paths without `..`, at most 500 characters |
| `command` | At most 1,000 characters |
| `fails_on_base` | The JSON boolean `true` or `false`, on a `command` deliverable only. Omitted means `false`. `true` needs at least one path |
| `paths` | A list of at most 100 canonical repository-relative file paths on a `command` deliverable. Each path is at most 500 characters |

A field of another type is refused with HTTP 422 `validation.failed`. The error names the field path, such as `deliverables.0.path`. The `fails_on_base` and `paths` errors also name the deliverable's `id`. Only a `file` deliverable's `path` accepts a glob: `*` matches in one directory, `**` matches across directories, `?` matches one character, and `{a,b}` is a non-nested alternative, including a single choice such as `{php}`. Alternatives may contain slashes and the same `*`, `**`, and `?` rules. `paths` is not a glob.

A canonical command path has no leading `/` and no `..`, `.`, or empty segment. The handoff check reads `paths` as stored and refuses those segments, so plan time refuses them too. For example, `./tests/FooTest.php` and `tests//FooTest.php` return HTTP 422 `validation.failed`. The error names the canonical form, `tests/FooTest.php`.

Task create, subtask create, and subtask update validate deliverable paths against a selected base commit. A resolved subtask base uses the recorded start commit, the base of a continuation's source subtask, the previous approved commit, or the workspace starting commit, in that order. When no base resolves, validation uses the Project's default-branch HEAD SHA at request time as a provisional base. It does not consult the default branch when a resolved base exists. Existing groups still validate subtask deliverables if the Project later switches to GitHub CLI source access; creating a new group still requires the GitHub App.

Orbit reads the base tree from the task workspace when the group has one. In a [review-and-merge](#review-and-merge) Project, an approval is committed but not pushed, so only the workspace has it. Without a workspace, Orbit reads the commit from the Project repository. It also reads the Project repository when the workspace cannot list the commit. When neither source has the commit, the request fails with `tasks.deliverable_base_unavailable`.

Before each implementer spawn, including retries and the next subtask after approval or cancellation, Orbit rechecks deliverable paths against the resolved review base after recording the subtask's start commit. It never falls back to the default branch at this gate. Missing paths request assistance instead of starting the agent; the reason names each failing deliverable id, path, and base SHA. An unresolved base or an unreadable base tree also requests assistance without starting the agent. This prevents a plan accepted against a provisional default-branch commit from reaching an agent on a release seed or task branch that lacks its paths.

Every reason from this gate starts with `Deliverable path validation`. No implementer exists yet, so recovery does not go through an agent:

1. Fix the cause.
2. For missing paths, replace the subtask's `deliverables` with subtask update.
3. Post a `resolution` comment on the subtask.
4. The next scheduler tick runs the gate again.

A `deliverables` update is accepted while the gate holds the subtask. It must pass the same base-path validation. Task activity records the old and new lists. This update does not use the one correction after `invalid_deliverable`.

A resolution clears the matching assistance and records the answer. Swamp decides whether to request another turn.

A file path must exist on that base, and a file glob must match at least one base file, unless `change` is `created`. File patterns and created-file companion patterns use the same relative-path normalization as handoff, including removal of leading `./`. Errors retain the submitted path.

Every command `paths` entry must exist on the base unless a sibling file deliverable with `change: created` covers it, literally or through a glob. A sibling with `change: modified` or `change: any` is not a new-file marker. With `fails_on_base: true`, each command path must also be a test file: under a `tests/` directory, or ending in `Test.php`, `.test.ts`, `.spec.ts`, or `_test.go`. This prevents a base run from copying the implementation fix. A violation returns HTTP 422 `validation.failed`; its field error names the deliverable id, path, base SHA, and `base_kind=resolved` or `base_kind=provisional`.

There is no `test` deliverable type. A stored continuation uses the source subtask's start commit for its diff and its base run, including when the source subtask has committed its fixes.

A subtask's diff runs from its start commit to the working tree that the check sees, uncommitted and untracked files included. Deleted and ignored files never match. Orbit records the start commit when the subtask starts, before the implementer's first turn. An unreadable base leaves evidence for Swamp to handle before another turn. After that, the start commit stays empty, and the diff uses a fallback base: the previous subtask's approved commit, or the workspace starting commit for the first subtask.

### Prove a command fails on the start commit

A `command` deliverable with `fails_on_base: true` proves that the command fails before the fix. Omitted and `false` are the same: Orbit runs the command once, on the working tree. With `true`, Orbit runs it twice at handoff. The deliverable passes only when the base command exits nonzero and the working-tree command exits 0. A failing base command is the expected evidence.

| Run | Code under test | Passes when |
| --- | --- | --- |
| Base | The start commit, or its fallback base, plus the files in `paths` from the working tree | The command exits nonzero |
| Working tree | The implementer's tree | The command exits 0 |

The base run extracts an archive of the start commit into a directory under the workspace's `$(git rev-parse --git-path orbit)/bases/`. It copies installed `vendor` and `node_modules` directories from the workspace, and no other dependency directory, then copies each file in `paths`, including an uncommitted or untracked file. It runs the command there with `bash -lc`. It does not change the workspace and registers no Git worktree. The check removes the directory when the run ends, and the next check removes a directory that a killed run left behind.

A command that needs another installed tree, such as a Python virtualenv or a Rust `target` directory, can fail on that base tree only because the tree is missing. That nonzero exit satisfies `fails_on_base` and is not evidence that the command reproduced a bug. This copy is retained. A follow-up has to widen it or stop counting a missing dependency as reproduction evidence.

The base run stops after 600 seconds, and a timed-out run counts as failing on the start commit. Exit 126 or 127 means the command did not run, so the deliverable fails. When the base command exits 0, the deliverable fails because the command does not reproduce the failure. The stored exit code and output follow the [shared output policy](#check-output). It records `base_started`, `base_exit_code`, and `base_output`. A timed-out run also records `base_timed_out` and `base_timeout_seconds`. The engine does not read test names or runner output. The Project's command does that. Show and the turn file include `fails_on_base`. An omitted input is stored as `false`.

Task create, subtask create, and subtask update accept `fails_on_base` and `paths` only on a `command` deliverable. `fails_on_base` is the JSON boolean `true` or `false`, and `paths` is a list of strings. Any other value is HTTP 422 `validation.failed`. The error names that deliverable's `id`.

The Orbit Project's [task policy skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) requires repro-first bug work where a command can reproduce it. A bug task's first code subtask carries that command. When a bug cannot be reproduced by a command, the brief says so and that subtask adds a `review` deliverable for the manual check. [`bin/bug-repro`](/reference/delivery-line#binbug-repro) runs that command against current main without filing a task. [`bin/task-group-check`](/reference/delivery-line#bintask-group-check) validates the create payload, including `fails_on_base`, without adding a proof field.

### Confirm deliverables

The Gateway writes the subtask's deliverables into `$(git rev-parse --git-path orbit)/turn.json` before each turn. The agent confirms each one in its [turn receipt](#turn-receipt) with `--deliverable=ID=evidence`, where the evidence says where or how the deliverable is met.

| Turn | Needs |
| --- | --- |
| Implementer `ready_for_review` | A confirmation for every deliverable |
| Reviewer `approved` | A confirmation for every `review` deliverable. Other IDs are allowed |

The turn command refuses a missing confirmation, an unknown ID, an ID given twice, empty evidence, and `--deliverable` with any other outcome. The Gateway stores the confirmations on the receipt's comment.

### Verify deliverables

The keyed check operation verifies file changes and command evidence against the recorded workspace base. It records invalid deliverables as check evidence. A contract correction can update its stored deliverables where the API allows it. It never resumes an agent automatically. Swamp decides when to request another turn or check.

## Prepare a task in Backlog

Prepare a task in Backlog before any agent runs. A Backlog task has no workspace.

1. Create the task. It starts in `backlog`.
2. Optionally, create the branch `task-{id}` from the Project default branch, commit the feature's contract to it, and push it.
3. Add the ordered subtasks with their deliverables, following the Project's task policy.
4. Move the task to `todo` with `tasks:update`.

The Gateway does not check the branch contents. When `origin/task-{id}` exists, the workspace checks it out. Otherwise the workspace starts a new branch from the default branch. The implementer and reviewer prompts are project-neutral. They do not add an ADR contract sentence or Orbit-specific policy such as Incus or lease instructions. The implementer prompt says that Orbit runs the configured Project check at handoff. It names the command only when one is configured.

When the workspace starting commit is 40 or 64 hexadecimal characters, both prompts add `The task started at <sha>.` and `git diff --stat <sha>..HEAD`. The review packet places those lines after its stat and diff commands. The lines name no Project, branch, or policy. Any other value is left out.

## Outer loop

The Gateway files a Backlog task when the same production problem keeps returning. An operator edits that task and moves it to Todo. Swamp does not claim it before that move.

The loop reads Doctor, Activity, the Gateway log, and assistance reasons. A release command also pushes its [release alerts](/reference/gateway-recovery#release-alerts) into the loop. It does not read the `schedules` table. It does not wait for an external alert manager.

Doctor incidents close only after a new successful observation of the resource or proof that its persisted resource is gone. A skipped check, an unreachable Node, and a cleared occurrence episode do not prove recovery. Only untouched generated Backlog groups close automatically; groups that an operator has changed stay open.

### Fingerprints

Each signal updates one row in `problem_fingerprints`. The fingerprint is unique.

| Column | Meaning |
| --- | --- |
| `fingerprint` | Stable key, at most 255 characters |
| `source` | `doctor`, `activity`, `log`, `assist`, or `release` |
| `first_seen`, `last_seen` | Signal time of the first accepted signal, and of the latest |
| `occurrences` | How many 5-minute windows were counted, not how many log lines |
| `evidence` | A small JSON sample |
| `task_group_id` | Top-level task filed for this key, or null |
| `muted_until` | Filing stays off until this time, or null |
| `filed_at` | When this episode was filed, or null. The operator cannot edit it |

A key longer than 255 characters keeps the source prefix, then `#`, then the first 12 hex characters of the SHA-256 of the full key.

The sample holds at most five request ids, five Activity ids, and five Activity paths. It holds one log excerpt of at most 500 characters, the latest Doctor expected and observed values, the latest Doctor summary, the assistance reason before normalization, the newest 20 occurrences, and up to 200 open assistance task ids. A release alert adds its summary as the summary, the release repository and release id, and up to five evidence links.

Each occurrence stores the UTC time of the first signal in its 5-minute window and how many signals fell in that window. A log row also stores its app frame path as `source_path`, including when the fingerprint is shortened. The summary and the assistance reason are cut at 1,000 characters. The excerpt and an Activity error message pass through the Gateway log redactor before they are stored. Expected and observed stay the bounded Doctor values. The sample does not store a raw Doctor report.

| Source | Key |
| --- | --- |
| Doctor | `doctor\|code\|resource_type\|resource_id` |
| Activity | `activity\|command\|error_code` |
| Log | `log\|exception class\|first app frame` |
| Assistance | `assist\|normalized reason` |
| Release alert | `release\|kind\|resource\|attempt\|fault` |

Node reachability checks use `node:{id}`. Node-wide inventory and environment checks use `node:{id}:inventory` and `node:{id}:environments` as their resource ids. Queued reports retain the time when their Node observation started. A delayed job cannot turn that cached observation into fresh recovery evidence. Each check recovers only after its own successful observation; a different job or Node cannot close it. Other null Doctor resource ids use `none`. An Activity row with a nonzero exit code and no error code uses `exit` as the error code segment. The resource id stays out of the Activity key. It lives only in `properties.path`, and that path is evidence.

An Activity row counts only when it is server-class. A row is server-class in any of these cases:

| Test | Example |
| --- | --- |
| The error code is `gateway.unhandled` or `activity.interrupted` | An unhandled Gateway error |
| The error code ends in `_failed` or `.unavailable` | `instance.clone_failed` |
| The error code is `http.` plus a status of 500 or more | `http.500` |
| `exit_code` is nonzero | A command that exited 1 |

`validation.failed`, `http.404`, `http.409`, and `http.422` do not count unless they also match a test above. A code that ends in `_failed` still counts when the HTTP status is below 500.

A log record counts when its level is ERROR or higher, it names an exception class, and the trace has a frame under the Gateway `app/` directory. The frame in the key is that path relative to the Gateway root, a colon, and the function, with no line number. An `HttpExceptionInterface` whose status is below 500 is left out. `ValidationException` is left out. A trace with no app frame is left out. The excerpt keeps the `request_id` from the log context.

An assistance reason is trimmed and lowercased. Each UUID, and each run of digits, becomes `#`. Whitespace collapses to one space. One open request on a task counts once. The same task counts again only after `assistance_requested` has cleared and a new request is stored.

### Occurrence windows

A signal that passes the source tests above increments `occurrences` only when that fingerprint has no counted signal in the same UTC block of 5 minutes. The block index is the signal's Unix time divided by 300, rounded down. A second signal in that block keeps the occurrence time already stored, adds one to that occurrence's signal count, and can still add request ids and the other bounded sample fields. It does not add an occurrence, and it does not raise `occurrences`. It does move `last_seen` to its own time.

The signal time is the time on the signal, not the time the collector reads the source. A log record uses the bracketed timestamp at the start of its header, read in the Gateway application timezone. An Activity row uses its `created_at`. Doctor and assistance use the collector clock when it accepts the signal. A release alert uses the Gateway clock when the alert is raised. The block uses that time in UTC.

Readiness counts these occurrences and their times. It does not count log lines. Many log lines in one block are one occurrence. The sample keeps the newest 20.

### When a fingerprint is ready

The tests below use only the current episode. That episode is the occurrence history stored on the row: the time and the signal count of each 5-minute window.

Doctor is ready after two of those times at least 10 minutes apart. A miss does not delete the row, and it does not reset the episode.

A release alert is ready after one occurrence. A release command raises it once for a deliberate verdict, so there is no noise to wait out.

Activity, the log, and assistance are ready when either test below is true for those same times. The count in both tests is `occurrences`, the number of windows, not the number of log lines.

| Test | Ready when |
| --- | --- |
| Burst | `occurrences` is 10 or more |
| Spread | `occurrences` is 3 or more, and the occurrence times cover two UTC quarter hours or two UTC dates |

A quarter hour is the UTC block of 15 minutes that contains the time. The block index is the Unix time divided by 900, rounded down. Ten log lines in one 5-minute window do not meet the burst test.

Filing a task clears that occurrence history after the brief is built. Hits while that task is still open start another episode. The filer clears that episode in the same write as `muted_until`, when the linked task ends. Only a hit after the task ended can make the key ready once the mute ends. A hit after the merge and before the deploy still counts, and the operator cancels that draft.

### Suppression

The collector and the filer honor two lists in [`apps/gateway/config/orbit.php`](https://github.com/hardimpactdev/orbit/blob/main/apps/gateway/config/orbit.php), in a `problems` array beside `tasks`.

| Key | Match |
| --- | --- |
| `suppressed_fingerprints` | The whole fingerprint, exact and case-sensitive. Ships as an empty list |
| `suppressed_path_prefixes` | The start of a source path. Empty by default |

A log record's source path is the app frame path, the path before the colon in the fingerprint frame. An Activity source path is a path taken from `properties.path`. Doctor and assistance have no source path. A path prefix matches that source path, not the fingerprint string. An empty prefix matches nothing.

The collector copies a log row's source path into the sample as `source_path`. The copy is independent of the fingerprint string. A key longer than 255 characters is shortened to the source prefix, `#`, and 12 hex characters, and that shortened key has no frame path. An accepted log signal writes `source_path` when the sample does not already have one, including a signal that stays in an open 5-minute window and does not increment `occurrences`.

A listed fingerprint, or a source path that starts with a listed prefix, is suppressed. The collector does not count that signal, and it does not create or update a fingerprint row for it. It still advances that source's cursor past the signal.

Each filer run reads the current lists. A row counted before a prefix was configured is still skipped when its stored fingerprint is listed, or when its stored `source_path` or an Activity path starts with a current prefix. For a log row with no `source_path`, the filer reads the frame path only when that path is still in the fingerprint.

A shortened log fingerprint with no `source_path` has no recoverable frame path. The filer does not invent one from the hash. While `suppressed_path_prefixes` is non-empty, the filer skips that row and leaves it unchanged: no task, no mute, and no episode clear. A later accepted signal stores `source_path`, and a later run applies the current lists. An empty prefix list does not block filing. These lists are separate from the mute below, and both apply.

The filer does not open another task for a key while `muted_until` has not passed. It also waits while the linked task has any status in this list: `backlog`, `todo`, `reserved`, `running`, `reviewing`, `settling`.

| Linked task | Deadline written once, from `updated_at` |
| --- | --- |
| `completed` or `failed` | 7 days, only when `muted_until` is empty |
| `cancelled` | 14 days, only when `muted_until` is empty |

A deadline that is already stored stays as it is. A missing linked task uses the 7-day deadline, measured from the run that notices the gap. `failed` uses the same wait as `completed`, because that task never ran and must not take another slot in the same hour.

Filing a new task clears `muted_until` and sets `filed_at`. It sets `occurrences` to 0 and clears `first_seen`, `last_seen`, and the occurrence history. It also clears the request ids, Activity ids, paths, evidence links, the log excerpt, and `source_path`. Open assistance task ids stay, so a request that is still open is not counted again. The brief is built from the episode before that clear.

The first time the filer writes `muted_until` for a `completed`, `failed`, `cancelled`, or missing task, that same write clears the episode again. It sets `occurrences` to 0 and clears `first_seen`, `last_seen`, the occurrence history, the request ids, Activity ids, paths, evidence links, the log excerpt, and `source_path`. Open assistance task ids stay. A crash stores neither the deadline nor the clear.

### What gets filed

`problems:file` runs every hour. It files at most three new tasks per day, using the Gateway application timezone. It takes release alerts first, then the highest `occurrences`. Equal counts use the earlier `first_seen`, then the fingerprint string. Each run loads at most 50 ready rows that are not muted, not tied to an open task, and not skipped by [Suppression](#suppression).

The cap counts fingerprint rows whose `filed_at` falls on today's date in that timezone. An operator edit to the brief does not change the count. A missing Orbit Project files nothing.

The filer inserts the task and its subtasks, then updates the fingerprint, in one database transaction. The update sets `task_group_id` and `filed_at`, clears `muted_until`, and resets the episode as [Suppression](#suppression) describes. A crash rolls every one of those writes back, so the next run does not file a duplicate.

Each task belongs to the Project whose slug is `orbit`, and the task starts in `backlog`. The first line of the brief is `Filed by the outer loop.`

The rest of the brief is eight sections, in this order: Symptom, Fingerprint, First seen, Last seen, Count, Occurrences, Evidence, and Suspected entry point. Times use UTC. Symptom is the Doctor summary, the redacted Activity error message, the redacted log message, the assistance reason before normalization, or the release alert's redacted summary.

Count is `occurrences`, the number of windows. Occurrences lists one line per window, oldest first, at most the newest 20. Each line is the first signal's time, formatted `YYYY-MM-DD HH:MM:SS UTC`, a space, and the signal count in that window, such as `2026-10-01 12:00:01 UTC 129`. Evidence includes a `Request ids:` line when the sample has any, and omits that line when none are known. The suspected entry point is its own section.

| Section | Bound |
| --- | --- |
| Symptom | 1,000 characters, then `...` |
| Fingerprint | 255 characters |
| First seen, Last seen, Count | One line each |
| Occurrences | The newest 20 windows, one line each |
| Evidence | The sample caps. Expected and observed are cut at 200 characters |
| Suspected entry point | 500 characters, then `...` |

The finished brief is at most 8,000 characters. The Evidence and Occurrences headings are always present. When either section has no lines, it says `none`. If the brief is still longer, the filer drops Evidence lines until it fits, and both headings stay. The occurrence lines stay. `tasks:create` refuses a longer brief with `validation.failed`. If create still fails, the filer skips that row and leaves `filed_at` unset. The row does not count toward the cap. The filer continues with the next row. Each subtask brief states that subtask's goal. The symptom appears once, in the group brief.

| Source | Title | Suspected entry point |
| --- | --- | --- |
| Doctor | `Doctor {code} on {type} {id}` | Resource type, id, and code |
| Activity | `{command} failed with {error_code}` | The command name |
| Log | `{exception class} at {frame}` | The app frame, or the stored `source_path` when the key is shortened |
| Assistance | The normalized reason | The open task ids in the sample |
| Release alert | Alert kind, then `for {resource}, attempt {attempt}` | Repository, resource, attempt, and fault |

A title longer than 160 characters is cut to 157 characters plus `...`.

The task has two subtasks. The docs subtask is first. Its deliverable id is `docs`, its type is `review`, and the description says the owning page matches the fix, or that no page changes. The operator can replace that deliverable while the task is in Backlog.

The second subtask reproduces the failure and then fixes it. Its deliverable id is `test` and its type is `review`. There is no `test` deliverable type. The description is `Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.` The deliverable has no `command`, `directory`, `fails_on_base`, or `paths` field. The filer never writes a whole-suite command, including `vendor/bin/pest` with no test file. The operator replaces that review with a `command` deliverable for one scoped test, sets `fails_on_base` to true, and names the test files in `paths`, before moving the task to Todo. [Prepare a task in Backlog](#prepare-a-task-in-backlog) is that edit.

### Collection

`problems:collect` runs every 10 minutes and dispatches short jobs on `problems`, below `tasks` in the same Horizon supervisor. A batch observes each Node, a second checks resource groups selected by each Doctor family, and a final job collects the other signals. Each group has one 12-second command deadline and a 13-second worker timeout. Laravel's `failed()` hook records an unverifiable observation when a job fails. Collection does not file problems; only hourly `problems:file` does that under its lock. [Gateway queue](/reference/gateway-queue#scheduled-work) gives the measured timings and group sizes.

The cached Node observations last at most one hour and are removed after collection; they are not a second problem ledger. Swamp observes the Gateway Horizon Process separately, without a cache or overlap lock, so it can record a stopped Horizon without queue workers. `problems:file` also runs hourly in Swamp, with its 30-minute overlap and command locks. All run only while the Tasks extension is enabled. Daily batch metadata pruning keeps `ORBIT_JOB_BATCH_HOURS` (24 hours by default). See [Scheduled work](/reference/gateway-queue#scheduled-work).

| Source | Bound per run |
| --- | --- |
| Doctor | Typed resource groups; 12-second probe deadline, 13-second worker timeout |
| Activity | 500 rows with `id` above the stored cursor |
| Log | 1 MiB, stopping at the end of a whole record |
| Assistance | 200 open rows |

Doctor runs through `RunDoctorAction` for every Node and every family. A peer access grant does not drop Nodes from that fleet. The collector records only `drift` and `unverifiable` issues. It skips `informational` issues, such as unregistered packages, because Doctor health ignores them too. One `problem_collector_state` row stores the Activity cursor, the log path, the file inode, and the byte offset. Doctor results commit per resource.

The first collector run sets the Activity cursor to the current maximum id, and the log offset to the end of the current file. It does not count those past rows. The log file is `storage/logs/laravel.log` when that path is a regular file. Otherwise it is the newest `storage/logs/laravel-*.log`. The collector finishes unread bytes in a rotated file before it switches.

Each source commits its fingerprint updates and its cursor in one database transaction. For Activity that cursor is the last id. For the log it is the path, inode, and offset. For assistance it is the open task ids. A crash rolls that source back, so the same rows are not counted twice. The collector applies [Suppression](#suppression) and the [occurrence window](#occurrence-windows) before it increments `occurrences`.

A failure in one source does not skip the others. The dispatch command exits successfully once queued; a dispatch failure fails the command. Worker failures appear in Horizon and the Gateway log. An interrupted resource check does not discard another resource's findings.

## Execution ownership

Swamp drives each group through the API and MCP operations documented above. `tasks:tick` and tick orchestration are removed. Creating a todo group records intent; only Swamp starts its work. A resolution clears the matching assistance and records the answer without sending it to an agent. Swamp decides whether to retry or continue. Cancel and complete remain explicit API operations.

The Gateway retains task state, workspace leases, operation recovery, provider jobs and transcripts. A keyed operation performs only the requested primitive and records its result. Operation workers can resume an unfinished primitive; they do not choose the next task phase. Swamp owns admission capacity, lifecycle retries, PR observation and cleanup scheduling.

The group execution lock waits at most five seconds for every waiting caller, including HTTP actions, operation workers and VM jobs. A caller that does not wait refuses immediately. A busy lock retains recorded state and leaves the caller to retry.

## Workspace lease

One durable workspace lease coordinates every Orbit actor that reads a task checkout for a verdict or writes it. The lease belongs to the Instance. Implementer and reviewer turns, candidate and handoff checks, deliverable commands, workspace fast-forwards, publication, settlement, and teardown use the same lease. The group execution lock still serializes admission and completion authorization; it does not replace workspace ownership. Callers in that group refuse promptly while completion authorization is busy. Durable scheduler workflows retry, and a failed synchronous request leaves no unconsumed workspace waiter.

A busy workspace queues the next actor only when its owner has a durable workflow that will retry it. The merge-ready gate uses GitHub state and recorded review evidence, so it does not acquire a workspace lease or join its queue. Waiters retain their order across Gateway restarts. Queued intents retain the subtask phase and attempt they serve. Cancellation or a changed phase or assistance request withdraws an obsolete queued intent; it never discards a held actor.

After release, the oldest eligible waiter is admitted when its durable scheduler workflow retries. A synchronous request that fails before it owns such a workflow withdraws its unconsumed waiter. Task status and Doctor show the holder and waiters, including their thread or check identity and `remote_uncertain` when a remote mutation has no confirmed result. Waiting is not a check failure and consumes no communication attempt.

A handoff check starts inside its implementer's receipt scope and takes over that lease for its detached process. It never queues a separate check lease ahead of the receipt that must admit it. A baseline check starts inside its own admitted check scope.

Agent turns keep ownership until Orbit observes that the turn stopped. Detached checks keep ownership until Orbit records their remote result. A timeout or an unavailable runtime never proves that a holder stopped. After authoritative completion, replaying the same keyed command acknowledges its durable result without opening another turn or lease.

Before admitting workspace work, Orbit reconciles completed checks and stopped turns under the execution lock. A stale or unreachable holder stays visible and prevents another actor from entering the checkout. A missing check supervisor does not prove its child commands stopped, so a lost reading retains ownership.

A stopped Gateway retains its synchronous lease when remote completion is unknown; there is no timeout reset. Synchronous SSH operations record an operation ID before dispatch and a remote completion receipt outside the checkout.

Each operation runs as the managed user in a dedicated systemd service with a retained terminal state. The service receives command arguments unchanged, including literal dollar expressions; only the invoked program interprets them. Recovery verifies the exact operation binding, host boot, terminal service state, and empty cgroup, including descendants that closed inherited descriptors. A changed boot ends an old operation with an unknown exit status; it never establishes a successful command or an attributable resulting tree. An unavailable system manager or passwordless managed sudo refuses the operation; Orbit never falls back to an uncontained mutation.

Recovery queries that exact ID. An absent ID is atomically tombstoned before it can count as not started, so a delayed original request cannot start afterward. A running or unreadable record retains ownership, even when its supervisor is missing. Proven local process death permits recovery only after every dispatched operation has a terminal receipt or tombstone. Gateway process identity includes its host, boot, PID, and process start ticks.

On the Gateway, `php artisan tasks:lease:release LEASE_ID --reason="operator investigation"` requests guarded recovery of one exact held lease. It does not stop a process, discard a waiter, delete a checkout, or publish a verdict. Admission is sealed in the database and on every recorded remote binding before quiescence is checked. Late operation IDs and delayed service starts honor the same durable seal.

Recovery reads the managed-unit inventory and proves terminal state and empty cgroups for this lease’s operation binding and journaled check run ID. A readable, consistently identified unit for another lease or check run does not block recovery.

A listed unit with no unit file does not block recovery only when it is inactive, has no cgroup, and has no pending job. A `not-found` load state alone does not prove that its processes stopped. Every other `not-found` state, a failed state read, any other non-loaded state, or inconsistent unit identity retains the fence. Any separate check or agent turn still needs authoritative completion.

A live or unreadable actor, unmanaged legacy ownership, uncertain provider destruction, or an unverifiable Node remains held. A missing Instance row is not termination evidence. Cross-host synchronous ownership can recover after sealing every recorded endpoint; a local live owner cannot be displaced. The command records its reason, proof and result in Activity. Operator release never credits an unknown tree change to Orbit.

For an owner recorded on another Gateway host, pass `--gateway-node=ID`. Orbit uses that Node’s pinned SSH transport and verifies the exact recorded hostname, boot and process start identity. A live or unreadable owner is refused. On that pinned Node and matching hostname, a changed boot proves the recorded process ended, just as it does for a local Gateway. With the original boot, Orbit requires the exact PID and process start identity to prove death. An unreadable boot or mismatched hostname does not prove death.

Remote operations and separate turns still need their own terminal evidence. A rebooted managed check ends as interrupted and failed; a result from its previous boot cannot pass a gate or establish a causal after-tree.

Before an opening turn, Orbit journals its request key and forwards that same key to the driver, including when Orbit generates it. A dispatched keyed turn completes its lease only when Pi reports `done` or `failed` for that exact key. `idle`, an unreadable prior observation, and terminal state for another key prove no completion. This preserves the fence while a delayed request may still arrive.

A pre-existing turn adopted without a dispatch journal has no request key to match. Orbit retains that owner until it observes a nonempty turn ID in `idle`, `done`, or `failed`.

A permanently lost Pi runtime can leave a dispatched turn without terminal evidence. The lease remains held, and the operator release command refuses it. There is no supported force-release or expiry for that turn; restoring authoritative runtime evidence or a separately reviewed recovery procedure is required. A ghost teardown with no Instance, saved endpoint, or authoritative provider destruction has the same evidence limit.

Remote receipts, operation lock files and binding seals are retained. Removing a tombstone or seal could admit a delayed original; unlinking a lock while an old process holds its inode could create a second lock. Orbit has no automatic pruning procedure yet. Storage must remain available; age alone never permits deleting recovery evidence.

A protocol-level turn refusal retires that exact released scope before cleanup or replacement. Cleanup inspects the existing reservation and never acquires a new lease. A sealed scope or uncertain dispatch still refuses new work.

Orbit records the review HEAD and working tree after it acquires the reviewer lease and prepares the turn, immediately before it sends the review packet. A review that waited therefore reads the current tree. Review outcomes and publication use the same ownership rule.

Every completed Orbit mutation records its before and after HEAD and tree under its lease. A failed after-snapshot leaves a completed holder pending that read; recovery retries the snapshot without repeating the mutation. The delayed snapshot cannot distinguish an intervening outside edit. A Gateway death before finalization leaves the same observation gap. Orbit marks these causal intervals uncertain and does not use it to clear assistance, even when ownership can safely end. A terminal check with its own durable final snapshot retains that exact evidence.

Detached check starts have a durable run ID before dispatch. Their separate retained system service checks the start tombstone before running. A check result is terminal only after the service and all descendants stop; a later tree change fails the gate and records the final snapshot as causally uncertain. Each run stores its result and output by run ID, so an old status request cannot consume or overwrite a newer result. A lost start reply retains its check row; recovery adopts that exact run or atomically fences a start that never arrived.

A durable mutation marker distinguishes executed Orbit commands from read-only verdicts; a changed snapshot under a read-only lease is not ownership proof.

A resolution clears group assistance only when its kind, question, and reason match the resolved subtask request and no sibling still needs assistance. A different or newer request stays open. A running subtask whose direction relay waits for the workspace keeps its assistance request and the answer linked to its question. Swamp reads that answer and decides when to send it.

The causal chain starts after the lease that captured the review baseline, using admission order rather than queue creation order. A matching mutation from an earlier review cannot excuse a later edit. Fetches, pushes, and receipt-file cleanup do not prove a checkout mutation.

An exact ownership chain from the review baseline to the current snapshot permits a new review with delta scope. In a review-and-merge Project, it reopens the handoff instead, as [Handoff CI](#handoff-ci) describes. Orbit does not infer ownership from a commit message, a matching old snapshot, a pre-lease check record, or a different HEAD alone. An agent edit outside its turn, a reviewer edit, or an unexplained gap still asks for assistance. Orbit never asks an agent to revert an Orbit-owned mutation.

Existing assistance stays open until an operator resolves it. A review without a recorded baseline lease cannot inherit proof from merge or check records created before leases existed.

### Why the workspace has one lease

Separate guards for reviewers, checks, and workspace fast-forwards leave other actor pairs free to overlap. One durable lease gives every actor the same ownership rule and preserves detached ownership after a Gateway restart. Process locks alone end before an agent turn or detached check ends. Expiring locks could admit a second writer while the first still runs, so ownership ends only after authoritative reconciliation. Treating every changed HEAD as an Orbit mutation would allow an outside edit to bypass review protection. Exact recorded ownership keeps automatic recovery narrow.

System services retain completion evidence after an SSH client or Gateway process dies. Orbit verifies that the exact service is terminal and its cgroup is empty before it releases ownership. A raw SSH exit status or a missing supervisor PID cannot prove that descendants stopped. Atomic tombstones exclude requests that reach the remote host after recovery. Missing or unreadable evidence keeps the fence; no elapsed-time expiry or blind release command bypasses it.

A garbage-collected unit (`not-found`) with a `running` receipt keeps the lease held until an operator releases it, because Orbit cannot distinguish it from a unit that has not yet been created.

Task work requires systemd 250 or newer, cgroup v2, and the managed account's passwordless sudo prerequisite. Unsupported supervision refuses remote operations. The remote receipt stores operation identity and completion, without commands, credentials, or output. Lease and operation history survives task or Instance removal because an older review can still refer to it. Operations restrict deletion of their lease; a retention policy must preserve referenced baselines before history can be removed.

## Task workspace

The task workspace is one fresh Instance that every subtask of the task shares. Its name and its branch are `task-{id}`. It lives at `<project>/workspace` on the task's own [task VM](/reference/compute-drivers#clone-and-set-up-every-workspace), which holds only this workspace. Its checkout clones from the Gateway mirror and runs the Project's setup steps, using the read-only package cache and CI cache ref bound to the tested SHA.

Before returning the workspace or activating its Route, Orbit inspects its source. An inspection failure leaves the workspace unexposed and the claim fails. Project setup owns dependency copies.

A visitable Laravel task workspace inherits the configured web root; a nested root such as `apps/site/public` uses the shared [application directory](/reference/projects#application-directory) for environment and runtime consumers. Preparation and inspection still verify Git identity, checkout ownership, and task metadata over the whole repository. Unrouted workspaces skip application classification; inspection does not discover a nested app.

The VM's managed user `orbit` owns the checkout and runs the agents, the setup steps, and the checks. So the workspace needs no ACLs and no `safe.directory` entry. [One user for every task agent](/reference/pi-server#one-user-for-every-task-agent) explains the choice.

| Project setting | New workspace |
| --- | --- |
| `task_workspace_routed: false` | Not visitable. The checkout has no Route and stays in the lifecycle state `source_resolved`. |
| `task_workspace_routed: true` | Visitable. The usual development provisioner gives it an inspect subdomain, and it becomes `active`. |

The setting defaults to true and does not depend on the Project slug. Provisioning records the selected mode on the workspace. Changing the Project setting affects future workspaces; it neither creates nor removes Routes on an existing workspace. Doctor uses the recorded mode when it checks that workspace. See [Task workspace routing](/reference/projects#task-workspace-routing).

[Doctor](/cli/doctor) treats `source_resolved` as the healthy state of a workspace that is not visitable, and `active` for a visitable one. An ordinary development Instance, including a monorepo `default` without a Route, settles at `active` instead. It runs the Project's create-time setup list and can supply dependencies to task workspaces without serving an endpoint. See [Provision the application endpoint](/domains/applications#provision-the-application-endpoint).

Orbit writes an untracked `.mcp.json` into the workspace before a reviewer starts, unless the workspace already has one, tracked or not. It points at `{gateway origin}/mcp/search`, which lists only `search_tools` and `execute_tools`. The file is excluded from Git. The [MCP server](/reference/mcp) describes both endpoints.

### Workspace ownership

When provisioning creates the workspace, it records the task as the workspace's owner, before any remote work. An owner is set only when the Instance is created: the database refuses any new owner afterwards. Deleting the task clears it. A task owns at most one Instance.

The owner is the only record of task workspace ownership:

- A task holds only the workspace it owns. Orbit refuses to attach any other Instance to a task.
- Claims, cancel, complete, and the sweep find and remove a task's workspace only through its owner, attached or not.
- A task operation runs only in the workspace the task owns, on the task's ready task VM.
- The name and branch `task-{id}` never make an Instance a task workspace. A task never removes your own Instance named or branched `task-{id}`.

Task operations include agent turns, checks, pushes, commit signing, and `.mcp.json` writes. Orbit refuses them on any other Instance.

Task cleanup never removes an Instance without that task as its recorded owner. To remove another Instance explicitly, run [`instance:destroy`](/cli/instance#orbit-instancedestroy).

A task VM holds only its task's workspace, and destroying the VM waits only for that workspace. Any other Instance row on the VM's Node describes a checkout inside the VM, so it goes with the VM and never keeps the VM alive.

## Swamp run output

The task page reads `GET /api/v1/task-groups/{group}/swamp-runs` through the Gateway. It shows the five newest Swamp runs whose `inputs.group` exactly equals the group id, their steps and states, each step's output, and the run log. It works without an agent thread. Subtasks are not valid group ids. The route requires Gateway access and the Tasks extension. It changes no task or Swamp state.

The browser polls every five seconds while the page is open. The Gateway caches each group snapshot for five seconds and keeps the last successful, redacted snapshot for 24 hours. `availability` is `available`, `stale` when a read fails but a snapshot remains, or `unavailable` without a snapshot. `observed_at` records the successful read time and remains unchanged on failure. An available empty list means no matching runs. An unavailable response has no runs. These states return HTTP 200 so an outage does not break the rest of the task page.

The Gateway connects to `swamp.orbit:443` over verified TLS. Set `ORBIT_SWAMP_READ_TOKEN` in the Gateway environment to a dedicated Swamp token with only `read` grants on workflows and their output data. Never reuse the driver or worker credential. `ORBIT_SWAMP_ADDRESS` selects the server address when the Gateway cannot resolve `swamp.orbit`; the TLS name remains `swamp.orbit`. `ORBIT_SWAMP_CA_PATH` selects the Orbit root CA file. The Gateway omits raw run inputs, run filesystem metadata, and upstream exception messages. It redacts output before returning or caching it. The browser contacts neither Swamp nor the task VM.

### Read limits

Each read uses Swamp's WebSocket `workflow.run.search` with `inputs: {group: "<id>"}` and `limit: 5`, then `workflow.history.get` and `workflow.history.logs` for those exact run ids. Orbit verifies group membership again on the returned details. It never uses a free-text search or a browser-supplied run id. Missing configuration, denied reads, oversized messages, malformed replies, and timeouts all produce stale or unavailable state. The complete upstream read has a five-second budget and a 2 MiB message limit. The response keeps at most 100 steps per run, 8 KiB of text per step, 32 KiB of step output per run, and a 16 KiB run-log tail from at most 200 lines. Explicit truncation flags mark omitted runs, steps, or text.

Orbit applies the Activity secret rule before truncation and before caching. It also removes the configured reader token by exact value and strips terminal control sequences. Structured step outputs are recursively redacted before conversion to text. Output is plain text in the browser.

Worker output can arrive after a step finishes. In the pinned Swamp version, worker console lines travel with the method result; resolved step outputs also appear after the method writes them. The page shows running and pending steps while waiting, and refreshes output as Swamp makes it readable. The [Swamp protocol](https://git.swamp-club.com/swamp-club/swamp/src/commit/983e2929/src/serve/protocol.ts), [run attach authorization](https://git.swamp-club.com/swamp-club/swamp/src/commit/983e2929/src/serve/connection.ts), and [worker console handling](https://git.swamp-club.com/swamp-club/swamp/src/commit/983e2929/src/serve/dispatch_service.ts) define these limits. The [Swamp manual](https://swamp-club.com/manual/explanation/swamp-serve) describes the server and grant boundary.

## Agent threads

An **agent thread** is one persistent conversation with a coding agent. It records the driver, the external conversation id, the Node, the task and subtask, the role, the model, and the effort. The external runtime keeps the transcript. Orbit keeps the link and the metrics.

Each subtask gets a fresh implementer thread. Each subtask's first review starts a fresh reviewer thread. Thread titles are `Orbit task #{task} / subtask #{subtask} · Implementer: {title}` and `Orbit task #{task} · Review: {title}`.

Orbit reserves the thread row before it starts the conversation, so the opening prompt can name the Orbit thread id. The row's external id starts with `pending:` until the conversation starts. A reserved row is not listed, measured, or broadcast. When the start fails, Orbit deletes the row. Explicit workspace cleanup removes completed resources. While the owner is active, a later start reuses it.

| State | Meaning |
| --- | --- |
| `idle` | Ready, with no active turn and no reported outcome |
| `working` | Running a turn |
| `asking_for_input` | Waiting for an answer to a question or an approval |
| `done` | The latest turn completed |
| `failed` | The latest turn failed. The error is stored |

`done` and `failed` stay until a new turn starts. A failed read keeps the last known state and marks it unavailable. It never makes a thread idle or failed.

### Drivers

A driver translates Orbit's thread operations for one agent runtime. Task agents, the implementer and the reviewer, run on the `pi` driver only. `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select the two roles, and both default to `pi`. A new task stores those values. The Gateway registers `pi` and no other task-agent driver. Any other value returns `tasks.agent_driver_unavailable` and stores no task.

A managed task whose recorded driver is not `pi` does not start or resume an agent turn. A caller never supplies a runtime URL. An unsupported operation fails explicitly. [Task agents run on Pi](#task-agents-run-on-pi) explains why.

| Role | Default model | Effort |
| --- | --- | --- |
| Implementer | `gpt-5.6-luna`, or `ORBIT_TASKS_IMPLEMENTER_MODEL` | `high`, or `ORBIT_TASKS_IMPLEMENTER_EFFORT` |
| Reviewer | `gpt-5.6-luna`, or `ORBIT_TASKS_REVIEWER_MODEL` | `high`, or `ORBIT_TASKS_REVIEWER_EFFORT` |

Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env` to choose each role's reasoning effort. Unset or empty keeps `high`. For example, `ORBIT_TASKS_IMPLEMENTER_EFFORT=medium` sets new implementer threads to `medium`.

The Gateway reads effort when it creates a thread, not when it creates the group. A change applies to the next thread of every open group. An existing thread keeps its stored `effort`. The Gateway passes the value to the driver unchanged; the agent runtime validates it.

**Pi.** The `pi` driver runs threads on the [Pi server](/reference/pi-server) of the task's VM. The Gateway chooses the session id. Each send carries a key, and a retry reuses it, so an ambiguous failure never starts a second turn.

The driver maps a model name to Pi's `provider/model` form. Every model uses the VM's one provider, `orbit-task-vm`; see [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The driver refuses a Claude model, including a name that starts with `claude` and a `provider/model` whose provider is `anthropic`, and the turn does not start on another runtime. Pi threads never ask for input, and they report no per-thread line counts.

### Archive finished threads

Task-agent threads are Pi sessions, and their files live on the task VM. Orbit keeps the thread row and its metrics after the work ends. Before Orbit deletes a ready task VM with its workspace, it saves each started thread's transcript on the Gateway, at `storage/app/private/task-transcripts/{task}/{thread}.json`. The file holds the driver's redacted observation: the state, the transcript entries, and the token metrics. A thread that Orbit cannot read is reported and skipped, so saving never stops workspace removal. A VM that died before removal leaves no saved transcript. The Gateway has no `tasks:archive-threads` command, and transcript archival is part of explicit cleanup.

## Session routing

Swamp selects a subtask, role, brief and operation key for each turn. The Gateway performs that turn and records its thread and receipt. It never routes the next turn or interprets a receipt as permission to start other work.

### Fetch before a turn

A keyed turn fetches the default branch before it starts or sends agent work. The operation records its workspace and review baseline so recovery uses the same context.

### Turn receipt

The agent writes a structured receipt to the configured path in the task VM. The turn operation reads and validates it, stores the result and stops. A missing or invalid receipt is evidence for Swamp to handle.

### Questions

Questions and assistance remain durable board records. Swamp decides when to ask a person, send an answer or retry. The Gateway never sends an answer merely because a resolution comment was stored.

### Assistance and resolution

A non-empty resolution clears its matching request and remains in comment history. Independent requests remain open. No turn, check, fixup or baseline starts as a side effect.

#### Group assistance after all children end

An open group with no unfinished child accepts an `assistance_requested` or `resolution` comment through `POST /api/v1/task-groups/{group}/comments`, CLI `tasks:group:comment:create`, or MCP `tasks-group-comment-create`. The body contains `type`, nonblank `body` (at most 100,000 characters) and `author` (at most 255 characters). The response is `201 {data: TaskCommentData, meta: {request_id}}`. An ended group returns `tasks.group_closed`; an unfinished child returns `tasks.assistance_subtask_open`.

#### Direction requests

An assistance request records the question on the group and subtask. A resolution records the answer. Swamp owns the next action.

#### Resolve a request

Use the comment API, CLI or MCP to post a resolution. This changes assistance only. Swamp reads the answer before deciding how to continue.

## Project check

A `tasks.check` operation runs the Project's configured check. A baseline can include setup commands. A handoff records command deliverables, the workspace tree and checked commit. The detached process records bounded, redacted output and its process identity, so operation recovery does not start a second check.

### Check output

The check result contains status, exit code, failed step, changed paths, tree and commit evidence, and bounded redacted output. A failed check is evidence for Swamp, rather than permission to start a repair or retry. A lost supervisor does not prove its children stopped; ownership stays held until stopped work is confirmed.

### Handoff CI

When the requested handoff includes hosted CI, the operation sanitizes the checked commit, pushes without force to the handoff branch and waits for that exact commit's required checks. Its pinned plan and push identity survive a lost reply. Swamp owns budgets, cancellation and any next action. See [Check an external group](#check-an-external-group).

### Baseline check

Swamp requests a baseline before implementation when its workflow calls for one. The operation runs setup and check commands, records evidence and returns. A failure starts no agent.

## Review a subtask

Swamp can request a reviewer turn through `tasks.turn`. The Gateway records the turn's output without approving a subtask, appending findings work, starting another reviewer or publishing a PR.

### Reviewer outcomes

The workflow that requested a reviewer owns review policy. The Gateway records its receipt as evidence. The operator or Swamp changes board status explicitly.

## Pull request and settle metrics

Orbit publishes through the Project's [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request) installation. Agents hold no GitHub token and never fetch or push. [What the App does not cover](/reference/github-app#what-the-app-does-not-cover) states how that is enforced. A task whose Project changes to `source_access: gh_cli` fails to publish and asks for assistance.

After each approval, the Gateway imports the stored commit from the task VM into its Git mirror and pushes from that mirror, never from the agent's checkout. It runs `git push --quiet origin <commit_sha>:refs/heads/task-{id}`, using the stored commit, never `HEAD`. The push is never forced. Then the next subtask starts. In a [review-and-merge](#review-and-merge) task, an approval commits and does not push: only a final review's approval pushes. On the subtask that opens the pull request, the Gateway then opens it against the Project's default branch, or uses an open pull request with that head.

Publication then requests the GitHub logins in `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` as reviewers so the fleet reviewer wakes. It skips the pull request author, because GitHub rejects that request. Unset or empty logins request no one. A failed reviewer request is logged and does not block publication. Orbit stores `pr_url`. During settling, it records `settled_at` and moves the task from `settling` to `waiting_for_review`. The `waiting_for_review` status uses the pull request watch, fixup, and completion rules of [Settling](#settling).

A task's push runs in a [queued job](/reference/compute-drivers#publish-from-the-vm), because the commit comes out of the VM at the VM's pace. While the job waits or runs, the subtask stays in `reviewing` without a failure, and each tick checks the job again. The job's error counts as one failed push.

A failed push or open keeps the subtask in `reviewing` and keeps its commit. It retries after 1 minute, then 2, 5, 10, and 30 minutes, and then every 30 minutes. The fifth failure asks for assistance with a reason that starts with `Approved commit publication failed: `. The reason names Git's error. When GitHub refuses a push that changes `.github/workflows/`, it names the missing `Workflows` permission. A later success clears only that reason.

The task title is the pull request title. The description holds the summary, a Changes list, a Breaking changes list or `None.`, and one line that says each delivered subtask passed the task check and reviewer approval. Cancelled and failed subtasks are not counted.

Before Orbit commits the approval that opens the pull request, Orbit checks the change list against each subtask that is not cancelled or failed. First, a change covers a subtask when the change starts with the subtask's exact title, after both are trimmed.

Case and punctuation count, so `Publish preparatory PR (not CLEAN) with report: ...` covers the subtask `Publish preparatory PR (not CLEAN) with report`. The title must end the change, or be followed by a character that is not a letter or a digit, so `Route` does not cover a change that starts with `Routes`. Then Jev checks the subtasks that no change covers this way. When every subtask is covered by its title, Orbit does not call Jev.

Jev is Orbit's TypeSafe classifier, called through Laravel AI with `TYPESAFE_API_KEY`. Without that key, the call fails with `TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.` For each remaining subtask, it answers whether a listed change delivers that subtask, and it counts a probability of at least one half as "yes". Its input lists only the remaining subtasks. A subtask without a "yes" fails `brief_coverage`, and the reviewer's reminder names it. Jev reads briefs and the change list, not code, so it checks coverage, not correctness. A failed Jev call is a communication failure.

### Watch the branch while subtasks are open

Swamp observes the published PR and records the group status. Swamp owns branch observation and any resulting assistance.

### Settling

Swamp owns PR checks, feedback, retries, fixups and cleanup. Updating a group to `settling` or `waiting_for_review` records board state and starts nothing.

#### Trusted GitHub feedback

The read APIs retain stored review observations and findings. Reading them authorizes no turn, push or merge.

#### Inspect approval observations

`tasks:github-reviews` reports stored observations. It does not grant merge authority or complete a group.

### Settle metrics

The board retains recorded lifecycle times, PR identity, token usage and line changes. Thread and workspace observations refresh recorded metrics. No metric triggers work.

### Lifecycle times

`started_at`, `settled_at`, `completed_at` and `merged_at` describe recorded task history. Swamp records lifecycle transitions through the existing APIs. Task insights read those records.

### Tokens and line diff

Thread observations retain usage and cost. Workspace observations retain line changes. Records remain available after a workspace is removed.

### Thread token metrics

The agent driver reports input, output and cached token usage on each thread. Group metrics aggregate those observations.

## Review and merge

The Gateway retains existing Project settings and review records for the board. Swamp owns incoming PRs, final-review tasks, approval and merge. Swamp owns any workflow that uses those records. A Project's review-and-merge setting does not prevent it from creating an external group.

## Web task board

**Tasks** in the web navigation shows every task on a board with Backlog, Todo, In progress, and Done lanes. The [insights strip](#on-the-board) sits above the lanes. In progress holds `reserved`, `running`, `reviewing`, and `settling` tasks. Done holds `completed`, `failed`, and `cancelled` tasks with their outcome visible.

The task board and the subtasks board hide lanes with no cards. The remaining lanes share the width. An empty task board says "No tasks yet." An empty subtasks board says "No subtasks yet."

Each card shows the Project code and the task id, such as `ORB-13`, its line counts, its status, and its duration. A card for a task that asks for direction says `Needs your direction`. A card for a task that asks because of a failure says `Needs attention`. A task page shows the brief, the metrics, a board of its subtasks, and an Agents section. When the task asks for direction, its page shows the question first. A subtask page shows that subtask's implementer and reviewer. The board is read-only. The [web app](/reference/web-app#live-tasks) keeps it current from task events.

## Task insights

The task board shows an insights strip above its lanes. It answers one question: does a change to the task pipeline make it better or worse? `GET /api/v1/task-insights` returns the numbers, with `period` set to `7d` or `30d` (default `30d`) and an optional `project_id`. Node access is the same as for `tasks:list`. The web app is the only client, so the route has no CLI command.

A period holds the tasks whose `ended_at` falls inside it. The previous period is the same length just before it, so each number has a value to compare with. Tasks without `ended_at` are not counted.

A task **merged** when it has a `merged_at`. Every merge path writes it, so a pull request that Orbit merged and one that a person merged count the same. Every other ended task did not merge: it failed, was cancelled, or ended with its pull request closed.

### Numbers

Durations and token counts are medians. Shares are between 0 and 1. A number is null when nothing in the period has the data it needs.

| Field | Meaning |
| --- | --- |
| `tasks`, `merged` | Ended tasks, and the merged ones among them |
| `tokens` | The `tokens` of every agent thread of every ended task, replaced threads included |
| `tokens_per_merged_pr` | `tokens` divided by `merged`. This is the headline number: it falls when the pipeline delivers more for less |
| `cost_usd`, `cost_per_merged_pr` | The same with the thread `cost_usd`. Null when a thread with tokens has no cost |
| `lead_time_ms` | `ready_at` to `merged_at` of each merged task |
| `queue_ms` | `ready_at` to `started_at` |
| `work_ms` | `started_at` to `settled_at`, the subtasks up to settling |
| `merge_wait_ms` | `settled_at` to `merged_at`: review, fixups, and merge |
| `excluded` | Merged tasks that lack a time that `lead_time_ms` needs |
| `yield` | `merged` divided by `tasks` |
| `pr_first_pass` | Merged tasks with no fixup subtask, from a [final review](#final-review) or from [settling](#fix-a-settling-pull-request), divided by `merged` |
| `wasted_share` | The tokens of tasks that did not merge, plus the tokens of replaced implementer threads, divided by `tokens` |
| `subtask_first_pass` | Reviewed subtasks whose first verdict was `approved`, divided by reviewed subtasks |
| `review_rounds` | The mean count of `approved` and `changes_requested` verdicts per reviewed subtask |
| `handoff_first_try` | Subtasks whose first finished handoff check passed, divided by subtasks with a finished handoff check |
| `questions_per_task`, `questions_by_cause` | The mean count of [questions](#questions) per task, and the count for each cause, zeros included. A question without a cause counts as `unknown` |
| `cache_hit` | `cached_input_tokens` divided by `input_tokens` plus `cached_input_tokens`, over every thread |
| `tokens_per_hundred_lines` | The tokens of merged tasks divided by their added and deleted lines, times 100 |

A replaced implementer thread is one whose subtask has a newer implementer thread. Its tokens bought nothing that shipped.

### Trend and breakdown

The response also holds:

- `weekly`: `tokens_per_merged_pr`, `tasks`, and `merged` for each of the last 12 weeks, whatever the period.
- `daily`: merged and not merged tasks for each day of the period.
- `breakdowns`: `tasks`, `merged`, `tokens_per_merged_pr`, `lead_time_ms`, and `yield` for each implementer model, each Project, and each [Gateway release](/reference/gateway-recovery). A task belongs to the last verified release that began before the task's `started_at`.

### On the board

The strip shows tokens per merged PR with its cost, lead time, yield, first-pass pull requests, and wasted tokens. Each shows its change against the previous period, green when better and red when worse. Fewer tokens, a shorter lead time, and less waste are better. A higher yield and first-pass share are better. The strip has a 7-day and 30-day switch and a Project filter.

**More** opens the rest: the weekly trend, the daily outcomes, the lead time split into queue, work, and merge wait, the other numbers above, and the breakdown table. The browser remembers whether the strip is open. The strip shows on the Tasks board only, not on an Instance's board.

## Agent viewer

The Agents section lists every started thread of the task. `GET /api/v1/task-groups/{group}/agents` returns each thread with its driver, external id, state, observation time, errors, and metrics. `GET /api/v1/task-groups/{group}/agents/{session}` [reads](#read-a-thread) the thread's normalized conversation, as a snapshot or as a delta after a cursor. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams that conversation to the browser. All three need Gateway access and an enabled extension. Runtime credentials stay in the Gateway.

Stored T3 task-thread rows stay in that list, with their metrics. The `t3_*` columns on `agent_threads` stay. A transcript request for a `t3` thread returns HTTP 409 `tasks.agent_transcript_unavailable` and does not open a stream.

A snapshot replaces the browser transcript. Entries merge by id and kind, so an updated entry replaces the earlier one. On reconnect, the browser sends its last cursor. The stream resumes after the cursor and sends only what the viewer missed. When one event becomes several entries, only the last carries the cursor. The viewer writes no thread state. When the thread's Node is gone, the stream sends the [saved transcript](#archive-finished-threads) as one snapshot and ends. Without a saved transcript, it returns HTTP 409 `tasks.agent_unavailable`.

### Read a thread

`GET /api/v1/task-groups/{group}/agents/{session}` reads the thread as one JSON response. The CLI command [`tasks:agent:show`](/cli/tasks#orbit-tasksagentshow) and the MCP tool `tasks-agent-show` call it. An agent polls it to follow a thread without a stream.

A first read returns a transcript snapshot. If it exceeds the Gateway budget, the snapshot keeps the newest entries that fit and starts with an explicit truncation marker. It holds the same redacted content as the first event of the stream, plus `archived`. To poll, pass the previous answer's `cursor` as the `after_sequence` query. When the thread's Pi server can resume from that cursor, the read returns a delta: only the entries added or changed after it, and the current state. Otherwise it returns a snapshot again. `kind` says which one the answer is.

| Field | Meaning |
| --- | --- |
| `thread_id` | The agent thread ID |
| `kind` | `snapshot` when `entries` replaces the transcript, with a truncation marker when older entries were omitted. `delta` when `entries` holds only the entries after `after_sequence` |
| `state` | `idle`, `working`, `asking_for_input`, `done`, or `failed`. Null when unknown or before the thread starts |
| `entries` | Normalized transcript entries, oldest first. Each entry has `id`, `kind` (`message` or `activity`), `label`, `text`, and `at` |
| `input_requests` | Open input requests. A Pi thread has none |
| `tokens`, `input_tokens`, `cached_input_tokens`, `output_tokens`, `model_calls`, `peak_context_tokens` | The [thread token metrics](#thread-token-metrics). Each is null when unknown |
| `lines_added`, `lines_deleted` | The thread's line diff, or null |
| `error`, `provider_error` | The error of a `failed` turn and its provider class |
| `cursor` | The thread's place in its events after this answer. Null for a saved transcript or a thread that has not started |
| `archived` | True when the answer is the saved transcript |

The cursor has the form `{run}.{sequence}`. Pass it back as `after_sequence`, or to the stream as `after_sequence` or `Last-Event-ID`. Either one then sends only what came after it. Replace your transcript with a snapshot. Merge a delta into it by id and kind, so a changed entry replaces the earlier one. A delta can repeat an entry that you already have. A cursor from an earlier run of the Pi server gets a snapshot. The run changes when the Pi server restarts or unloads an idle session. A Pi server binary built before delta reads answers every read with a snapshot.

A thread that has not started yet returns an empty snapshot: no entries, a null `state`, and a null `cursor`. Poll it again until it starts.

When the thread's Node is gone, the read returns the [saved transcript](#archive-finished-threads) with `archived` true and `cursor` null. It does the same when the thread's Pi server fails and Orbit saved a transcript, as after the task VM is deleted. A saved transcript does not change, so a reader can stop polling it.

The read waits at most 5 seconds for the Pi server. The Gateway gives the Pi server a 16 MiB response budget. A larger snapshot keeps only the newest complete entries that fit, with a system message marking the omitted history. An oversized delta becomes a bounded snapshot, so the cursor still names the current state. Orbit uses the same bounded snapshot when it [saves a transcript](#archive-finished-threads), with a 15-second request budget.

The [tick](#session-routing) reads only state, turn identity, errors, and token metrics; it never fetches the transcript. A long transcript does not make a healthy agent unobservable on an updated Pi server. The [Pi rollout requirements](/reference/pi-server#read-after-a-cursor) describe older binaries.

The read writes no thread state. Activity samples it like any other [read](/cli/activity#what-the-gateway-records). When the Pi server is unavailable, refuses the read, does not answer in time, or violates the response budget, and Orbit saved no transcript, the read returns HTTP 502 `tasks.agent_read_failed`. Its message holds no server detail. The other errors match the stream: 404 for a thread of another task, 409 `tasks.agent_transcript_unavailable` for a `t3` thread, and 409 `tasks.agent_unavailable` when the Node is gone and Orbit saved no transcript.

## Cancel a stuck task

`tasks:cancel` ends a task in any status except `completed`, and except `settling` with a `pr_url`. Those return HTTP 409 `tasks.not_cancellable`. Complete a settling task instead. `watched_pr_url` does not make the task published, so a `running` or `reviewing` task stays cancellable.

Cancel waits for exclusive workspace ownership before it pushes approved work or removes the checkout. A busy workspace returns HTTP 409 with error code `tasks.workspace_waiting` and leaves the group and subtask statuses unchanged. When no durable cleanup retry exists, the refused request withdraws its queued intent so later scheduler work can proceed after the holder finishes. It never releases an active or uncertain holder.

Cancel removes the task's workspace, then marks the task and its open subtasks `cancelled`. Subtasks, comments, and thread links stay as history. Cancel does not stop the agent conversations. Cancelling again is safe, and it retries a removal that failed. It also removes the unattached workspace that the task [owns](#workspace-ownership).

Cancel uses forced [Instance removal](/reference/instance-removal), including Project teardown and checkout deletion. A development workspace in `source_resolved` can have no Route or exactly one pending or failed Route targeting only that Instance. The eligible Route and its RouteTarget are removed with the workspace. An active or shared Route still prevents removal, keeps the Instance, and follows the removal-refused rule below.

- **Settling without a pull request.** Cancel first pushes the latest approved commit to `task-{id}`, so you can open a pull request from it. A failed push returns HTTP 502 `tasks.push_failed` and keeps the task.
- **VM task settling without a pull request.** The first cancel queues the push and returns `tasks.push_failed` with `Cancel again in a minute.` A later cancel finds the push done and ends the task.
- **Review and merge.** Cancel pushes only an approved commit that a final review approved. It removes approved work that no final review saw.
- **Node unreachable.** Cancel ends the task and keeps its Instance attached. It requests `removal_failure` assistance with `Workspace removal failed: The Node is unreachable.`
- **Retry after an unreachable Node.** Once the Node returns, direct cancel and the sweep push eligible approved commits before removal. A failed or pending push keeps the workspace.
- **Removal refused.** Cancel returns the error and keeps the task. The task requests `removal_failure` assistance in every status.

Uncommitted changes are never pushed. Git refuses the push when `origin` holds an unrelated `task-{id}` branch, for example after a Gateway rebuild reused the id. Rename that branch on `origin`, then cancel again.

When cancel marks the task `cancelled`, it follows the [ending rule](#assistance-and-resolution): only a removal failure may keep asking for assistance. It clears the open subtasks' requests and keeps their last reasons.

## Complete and cleanup

Swamp requests group completion or cancellation through the API. The Gateway stops admitted work and performs the requested workspace removal. A failed remote stop or removal preserves the owned workspace and error evidence for retry. Swamp schedules retries; there is no PHP cleanup sweep or settling state machine.

`tasks:group:complete` accepts a settling or waiting-for-review group. It can also complete a running or reviewing group when its watched PR has ended. Repeating completion on a completed group retries its attached workspace removal. An explicit cancellation or completion remains available even though automatic orchestration is removed.

Teardown steps run before the workspace and task VM are destroyed. Recorded approved work is preserved through the existing removal push contract. Cleanup does not choose new task work or merge a PR.

## Configuration

These Gateway environment keys configure the extension.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER`, `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | The drivers of new tasks. Both default to `pi`. Any other value is `tasks.agent_driver_unavailable` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL`, `ORBIT_TASKS_REVIEWER_MODEL` | The models of new tasks. Both default to `gpt-5.6-luna`. A Claude model is refused |
| `ORBIT_TASKS_IMPLEMENTER_EFFORT`, `ORBIT_TASKS_REVIEWER_EFFORT` | The effort of new implementer and reviewer threads. Unset or empty keeps `high`. See [Drivers](#drivers) for when changes apply and runtime validation |
| `ORBIT_TASKS_GITHUB_REVIEWERS` | Trusted reviewer account IDs per repository, `owner/repo:id,id;owner/repo:id`. Unset or empty trusts no one. See [Trusted GitHub feedback](#trusted-github-feedback) |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | Comma-separated GitHub logins requested as reviewers when a task pull request is opened or reused. Unset or empty requests no one. The pull request author is skipped |
| `ORBIT_PI_PORT` | The port of the Pi server on each task VM, default `3774`. Each VM has its own token |
| `TYPESAFE_API_KEY` | The key for Jev calls |
| `TYPESAFE_URL`, `TYPESAFE_MODEL` | The TypeSafe endpoint, default `https://api.typesafe.ai/v1`, and the classification model, default `jev-latest` |

Problem suppression is not an environment key. The two lists live in `apps/gateway/config/orbit.php` under `problems`, beside `tasks`. [Suppression](#suppression) defines the match.

## Project-owned task policy

The engine knows the configured check, lifecycle steps, workspace routing, and typed deliverables. It does not select a task check, a fixup, or workspace routing by slug, package manager, manifest, or CI job name. A command may use any toolchain installed on the task Node. The rubric does not require a Composer script or inspect a manifest to judge the Project's check. It does not encode a docs-first workflow, an ADR rule, or a language or package manager, and it does not treat any Project slug as Orbit.

### No planner

Task create accepts no planner. There is no `plan` field, no planner thread, and no stored planner state. An external ADE plans and steers the work. Orbit runs the assigned work.

### Starting from the default checkout

A new task workspace is a linked worktree of the Project's `default` repository on the selected Node. Its task branch starts at the last commit that [deployed](/reference/deployments#development-defaults) in the default's checkout, not a newer fetched default branch. Orbit reads that commit under the Node source lock, records `seed_path` and `seed_commit` on the new Instance before preparing its source, and preserves that selection on retries. An empty selection is recorded too: a later default deployment does not reseed a clone that already started without a seed.

Both explicit Instance setup and asynchronous task baseline setup receive the recorded `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`; the Project copies its own dependency and cache folders from the default checkout with reflinks, then runs its locked installs. A deployment of the default can change those folders during the copy. The seed path names the default's checkout, not a release directory. The workspace never writes back to the seed.

When the Project's default has not deployed on that Node, Orbit creates an independent clone at the fetched default branch tip. Task VMs select caches separately under the [workspace CI baseline rule](/reference/implementation-loop#ci-cache-refs), including on baseline retries. The seed variables are empty and setup must install dependencies from its lock files. There is no automatic root-only dependency copy. External `instance:register` callers read `seed_path` and `seed_commit` from the `default` Instance API, create a branch and linked worktree at that commit, then register it and run setup.

### Routing and cleanup

Swamp owns routing and cleanup policy. The Gateway offers keyed primitives and records their results. It never infers a new lifecycle action from a timer, board update or resolution.

### The base-run limit

The base run is the exception that remains. It copies only installed `vendor` and `node_modules` directories into the start-commit archive, as [Prove a command fails on the start commit](#prove-a-command-fails-on-the-start-commit) describes. A Project whose command needs other installed dependencies cannot treat that base failure as a reproduction.

### No implicit task check

New Projects have no task check until one is configured, regardless of type. Existing stored checks remain unchanged. Shared instructions, reminders, the check runner, and pull request descriptions name only an explicit Project check; none supplies a fallback. Without a check, Orbit still verifies the tree and deliverables and requires review.

## Why it works this way

### Publish uses the checked commit and the approval push

A raw commit would bypass the handoff check. Taking its check ID binds publication to the evidence and the commit recorded together. Reusing the approval push keeps GitHub authority on the Gateway and avoids a second transfer path. The operation holds the group while the queued job runs, so another effect cannot reuse its workspace. An open-pull-request lookup makes a repeated step safe. The orchestrator owns review gates and board states, so publication adds no brief coverage, reviewer request, or settling decision.

A push that started can reach GitHub even after its client stops. Keeping the operation held until its job ends prevents a cancel from claiming that publication stopped. Before start, invalidating the queued record is enough to stop a late job without deleting shared queue data.

These reasons explain the design. Check them before you propose a change.

### External checks own their lease and commit

A turn and its check are separate operations. The check waits for the turn to release the workspace, so a receipt scope cannot lend it the implementer's lease. Detached check supervision gives every requested check one execution and output rule. A failed check is evidence for the orchestrator, so it is a result rather than an operation failure. A passed handoff commits the checked tree so later review and publication can name one recorded commit. A lost check retains ownership because a missing supervisor does not prove its children stopped.

The [hosted-CI prerequisite](#check-an-external-group) needs a fresh sanitized commit per operation because sanitizing a proposed message cannot remove skip directives inherited from an old commit. Recovery must retain that same commit, or a lost reply would create extra commits and pushes. Pinning the CI plan prevents a Project edit from changing what passed. Reviewer and publish admission share one predicate because a local pass cannot prove hosted CI completed; the owning successful operation supplies that evidence without a duplicate check-status schema.

### An external turn records evidence without choosing the next step

The orchestrator owns the prompt, gates, budgets, and assistance. The turn operation only sends, waits, and records evidence. Applying a blocked receipt inside Orbit would create a second lifecycle owner. Durable owns restart resume and provider retries, so the Gateway never starts a replacement turn for them. Reviewers reset to the passed handoff commit to preserve what was checked. Consults keep the tree because it contains the implementer's work.

### Public analysis metadata reuses operation results

A typed code without a typed size forces callers to parse exception text, which is neither stable nor safe. A closed failure result preserves the original failure status and carries only an allowlisted code and validated safe integer. Reusing `OperationOutcome`'s failed-result facility keeps the generic runner free of Tasks exception parsing. Saving typed fields before release lets recovery finish the same failure without keeping raw rejected reports or diagnostics. A new error endpoint, table, generic exception-details bag, or private-state projection would expose more than this consumer needs.

The provisioned starting commit can be ahead of or divergent from the initial merge base. Publishing the already recorded pin gives callers the same identity analysis admits, without trusting caller input or a moving remote. Null is safer than recomputing a pin that provision did not record. Returning the pin does not make a stale workspace usable or create a base-update policy.

### Provision keeps effects separate from lifecycle decisions

An external orchestrator needs the same task VM and workspace as the other keyed operations, without starting a baseline or an agent. Provision shares the workspace provisioner and stops at a usable workspace. One operation hold per group keeps later task effects from overlapping. Capacity waits retain the reservation and release the operation worker; configuration and VM errors end the group instead of starting a Gateway retry policy. Clearing the reservation on cancellation lets existing cleanup own removal.

### An optional, generic extension

Tasks is an extension, so an operator can switch it off without a Gateway downgrade. The engine knows tasks, subtasks, deliverables, one task check, and a lifecycle. Each Project's own policy and task check decide how it plans and verifies work. Inferring policy from a slug, manifest, or CI job name would create a second hidden contract in the Gateway, so those choices belong to the Project. The ADE plans, because planning needs the conversation with you. A web form to create tasks would be a second path beside MCP and the API.

Three alternatives were rejected. Selecting one Project's behavior by its slug would keep a second task policy in the Gateway. Inferring that policy from repository files would hide it in the engine instead of the Project's skill and task check. A compatibility path for a planner thread was rejected, because you plan with an external ADE and the engine keeps no planner state.

Shared prompts stay free of Project policy. They do not name a feature contract or an Orbit lease rule. The repository's instructions and `orbit-tasks` skill carry that policy.

### Every task group runs on its own task VM

Each task group gets its own [task VM](#task-vm-workspace). [One user](/reference/pi-server#one-user-for-every-task-agent) runs its agents and checks in the same environment. The [VM edge](/reference/compute-drivers#why-task-vms-work-this-way) is the boundary. A task waits for its VM; there is no shared-host fallback.

### Backlog before Todo

A task needs an id before its branch `task-{id}` can hold the contract, and it must not run while that contract is written. So a task starts in Backlog and runs only when someone moves it to Todo. A draft flag on a Todo task would give one lifecycle fact two fields.

### A person starts a filed problem

Code can see that a failure came back. It cannot write the test that proves the bug. A model does not choose what to file. The thresholds are code. This serves [agents operate, humans steer](/mission#principles) and [deterministic first](/mission#principles).

The [outer loop](#outer-loop) files a Backlog draft. The reproduce subtask carries a review placeholder, and a person replaces it with a scoped `fails_on_base` command before the task can run. The filer cannot know the right test, so it never writes a whole-suite command such as `vendor/bin/pest`. Filing straight to Todo is rejected, because an agent would start before a person names the test. A whole-suite command is rejected, because a green or red result would not prove this failure.

A page on every server-class row is rejected. One command can fail dozens of times in an hour, and a Doctor finding from one run can be gone on the next. A Doctor fingerprint stays unfiled until two occurrences are at least 10 minutes apart.

Ten occurrences of one Activity, log, or assistance key are enough to file. An occurrence is one 5-minute window, so the many lines of one burst count once and are not enough to file. Ten windows means the same failure came back across separate windows. Three occurrences file only when they fall in two UTC quarter hours or on two UTC dates. Three isolated occurrences do not file.

Known noise stays in Gateway config, beside the Tasks settings, not in a hidden code list. The [suppression lists](#suppression) name exact fingerprints and source-path prefixes. A match is neither counted nor filed. The prefix list is empty by default; configured prefixes exclude matching log frames. Keeping the list only in code is rejected, because an operator has to see it and extend it. The 14-day mute after a cancel stays. The list drops noise before a task exists. The mute waits after a person cancelled a draft.

A shortened log key does not contain the frame path, so the sample keeps that path as `source_path`. Each filer run checks the current lists against the stored path, including a row counted before the prefix was configured. Filing a shortened row that has no stored path is rejected while a prefix is configured, because the filer cannot recover the path. The row stays in place until an accepted signal records it.

The cap of three tasks a day stops a burst from filling the board. `filed_at` holds that count. Counting the cap from the brief is rejected, because the operator edits that brief on the filing day. Cancel is the person's mute, and it lasts 14 days. A completed or failed task waits 7 days. Refiling as soon as a task reaches `failed` is rejected, because that task never ran and the next hourly pass would file the same key again.

The mute write also clears the hits collected while the task was open. Keeping that episode in the ready test is rejected, because the old counts would file the same key when the wait ends, with no new failure.

Clearing the episode only when the task is filed is also rejected, because hits while it sits in Backlog, Todo, or Settling would pass the ready test when the mute ends. When the wait ends, only a hit after the task ended can make the key ready. A hit after the merge and before the deploy can still file a draft, and the operator cancels it.

### The signals the Gateway already has

The loop reads Doctor, Activity, the Gateway log, and assistance reasons. Waiting for schedule rows or an alert manager is rejected. The `schedules` table is empty, and no alert manager is configured.

### A release alert files on its first occurrence

A failed release is a verdict from deterministic checks, not a noisy signal, so waiting for three windows would only delay it. The release command pushes the alert at that moment, and the next hourly run files it.

The release producer supplies the failing resource, attempt, and fault. The Gateway SHA stays in evidence, so a Gateway update does not create another incident for an unchanged failure. The Activity key lacks that incident identity, and a single Activity row would never be ready. Filing release alerts first keeps a burst of recurring noise from taking the daily cap before them. The webhook, not the task, is the prompt signal, so the filer keeps its hourly run.

### One fingerprint per problem

The Activity key is the command and the error code. Putting the resource id in that key is rejected, because the id lives only in `properties.path` and the same failure would split into one task per resource. Merging a log error with its Activity row is also rejected. The keys differ, and a log error with no Activity row would disappear.

### A small sample, and no past rows on the first run

Doctor evidence stores the issue code independently of the fingerprint, including when a long identity is hashed. New groups and refreshed groups use the same fixed instructions. The operator keeps control of groups they edited.

The sample keeps bounded Doctor values and a short redacted log excerpt. Storing raw Doctor reports or full traces is rejected, because that output exposes paths and credentials. [Doctor](/cli/doctor#no-stored-reports) keeps no raw report for the caller. Counting past Activity and log rows on the first run is rejected, because those rows would take the daily cap on the day the loop starts. The first run records its cursors at the end of the current data.

### One table for a task and its subtasks

A task and its subtasks share a lifecycle and most of their fields, so both are rows in `tasks`. A second model would give one concept two names. A subtask has no children, because the workspace, the branch, and the pull request belong to the top-level task.

### Deliverables are checked, not read

Orbit cannot check prose, so a subtask names typed items. The check script runs each command itself. The Gateway verifies against its own run, because the agent controls the workspace and could change a script that verified itself. A `file` path accepts a glob, including `{a,b}` or `{php}` alternation. A command's `paths` list is exact files, because a glob could match a file made to satisfy the base run.

### A command must fail on the start commit

A command that only passes on the fixed code does not prove it covers the bug. So a base run uses the start commit and adds only the files named in `paths`. It also copies installed `vendor` and `node_modules` directories so a Composer or JavaScript command can run, and it copies nothing else. A missing Python or Rust dependency tree is not proof that the bug existed on the start commit.

The base tree is an extracted archive inside `$(git rev-parse --git-path orbit)/bases/`, not a registered worktree, because a killed run would leave a registered worktree that blocks removal of the clone. Exit 126 or 127 is not that proof: the command did not run.

### Assistance is recorded in Activity

Each assistance change has an Activity entry, so an operator can trace the kind, question, and reason without an external notification service. Waiting on another task or pull request is not an assistance kind: a dependency wait that resumes on its own is a separate feature. The operator answers through the CLI, MCP, or API; a web answer box is outside this feature.

### Questions are records, not parsed comments

Each consult and direction request has a record with its answer and cause, while comments keep the conversation. Questions kept only in comment bodies would need free-text parsing before the operator could count them, group them by cause, or trace them to a brief. The records and the `questions` and `escalations` counts show where briefs, contracts, and subtask scopes need attention.

The reviewer chooses a cause from a fixed list when it hands off, because it already holds the contract and the answer. Asking a model to infer the cause later was rejected: the handoff has the evidence, and a fixed list avoids another model call and its cost. A cause stays empty until that reviewer receipt, so an open, escalated, or migrated question does not claim a diagnosis nobody has made.

### Transcripts outlive the task VM

A task VM is deleted when its task ends, and its Pi sessions go with it. Without a copy, nobody can read what an agent did after the task settles, so prompts cannot be audited or replayed against real turns. Orbit saves the redacted observation on the Gateway just before the delete. It reuses the snapshot the agent viewer already streams, so the copy needs no second format and no second redaction path.

### Agents read a snapshot, browsers stream

An MCP tool call returns one answer and holds no request open, so an agent cannot follow the stream. The [thread read](#read-a-thread) gives it the same content as one JSON response. The read and the stream share the checks, the saved-transcript fallback, and the sanitizer, so they never redact differently. The cursor stays the Pi server's, so a reader can move from the read to the stream without a gap. The Gateway keeps no state per reader.

A poll returns a delta, because a transcript only grows: downloading it again on every poll would cost more on each call and pass the MCP output limit for a long thread. The Pi server already keeps the events of its current run for the stream, so it can answer a delta from the same cursor. The 16 MiB limit bounds the memory of one read and stays above real transcripts, so Swamp still observes a long thread. A thread that has not started is a normal state, so its read is an empty snapshot and not an error.

### Insights come from recorded times

The insights are computed on each read from the task rows, their threads, comments, checks, and questions. There is no snapshot table to fill or repair, and a fixed definition applies to old and new tasks alike. The fleet holds a few hundred tasks a month, so a read stays small.

A task stores its own lifecycle times. `updated_at` moves on any write, and the Activity log samples reads and keeps no record of a task whose state changed. Without the times, lead time would be a guess.

`merged_at` is GitHub's merge time, read when Orbit confirms the merge. `merge_status` is set only when Orbit merges, so it misses a pull request that a person merged. Counting merges from GitHub's answer covers both.

A task's release is found by the time each release record was created. The record changes after verification, so `updated_at` would move a task to a later release.

The weekly trend marks no releases. Automatic releases ship many times a day, so marks would cover the line. The release breakdown compares them instead.

Tokens are summed from the threads, not from the task `tokens`. The task total leaves out an implementer thread that a later thread replaced, and those tokens are exactly the waste the insights must show.

### Metrics stay on the thread

The thread spent the tokens, so the split lives there. A total alone does not show whether the prompt grew, the cache missed, or the output grew. Pi reports the split on the session usage object. A missing field stays null, because a partial sum would look complete.

### Task agents run on Pi

T3 task threads run as the operator's Unix user and have that user's full access. Pi task agents run as a dedicated `orbit-agent` account. The operator approved that split on 2026-10-01. T3 Code stays installed as the operator's own tool.

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. Task agents therefore cannot use Claude, and they do not keep a second runtime to reach it. One driver, Pi, owns implementers and reviewers. This serves [one way, one name](/mission#principles) and [no exceptions and no legacy](/mission#principles).

Keeping T3 as a selectable driver would keep two restart rules, two metric paths, and two archive paths. Pi owns restart recovery and reports usage on its sessions. Swamp has no T3 metric collector or thread archive.

Both roles default to `gpt-5.6-luna` at `high` effort. Keeping `claude-opus-5` as the reviewer default would make each new review fail on Pi. Each role keeps its driver setting, with a `pi` default, because deployment selects Pi explicitly. A different configured driver fails before a new task is stored.

Finishing an open T3 task turn would preserve the second runtime, so a managed task that records a driver other than `pi` never starts or resumes an agent turn. The operator cancels or replaces it. Deleting its thread row, metrics, or `t3_*` columns would erase the record of work that already ran, so that history stays. A transcript request returns HTTP 409 `tasks.agent_transcript_unavailable` rather than contacting T3. Pi session files stay on the Node; Orbit keeps their thread rows and metrics too.

### Jev only checks coverage

Code decides every fact that code can check. Jev answers only whether the change list covers each subtask, because the reviewer writes that list and code cannot compare prose. Every call is stored with its input and later labeled by rule from the merged pull request, so the checks can be measured without a second model.

### Swamp output stays a read

The Gateway reads Swamp's own run history rather than recording a second run ledger. This serves the Mission's lean and deterministic-first principles. Exact group inputs already identify maintenance-worker runs, so the driver needs no tagging change. Polling exposes the output that a read-only principal may see. Swamp's `run.attach` requires a `run` grant, so granting execution just to stream a read-only panel is rejected. A worker agent or a direct browser connection would add another credential and network boundary. The snapshot cache bounds repeated reads and preserves useful evidence during an outage without making Orbit a second orchestrator.

## Production smoke ownership

Repository production-smoke browser tests run through GitHub Actions after explicit Swamp baseline admission. The [Gateway smoke API](/reference/deployments#production-smoke) supplies release context and verified execution evidence. It is separate from task workspace checks. Swamp decides what to do when execution evidence is missing or its baseline deadline expires. The Gateway reports pending or unknown for an uncertain send, missing or ambiguous run, or expired artifacts; those states never mean that tests failed.

Only JUnit failures or errors report failed. A clean JUnit report with an unsuccessful GitHub conclusion is unknown; it cannot justify a rollback. No smoke state starts a task, repair, rollback or merge in this slice.

Production smoke recovery uses the [keyed failure block and guarded rollback contract](/reference/deployments#verified-smoke-failure-and-guarded-rollback). It creates no repair Task in this slice. Recovery remains disabled until paired review, the failure-hold integration and an explicitly authorized production proof.
