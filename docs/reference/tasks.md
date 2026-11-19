---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs tasks. It covers the model, the lifecycle, typed deliverables, the task check, agent threads, review, the pull request, fixups, metrics, cleanup, and the outer loop that files recurring problems."
covers:
  - "apps/gateway/{app/{Data/Tasks/**,Domain/{Tasks,Problems}/**,Infrastructure/Tasks/**,Support/Percentile.php},config/{swamp,task_pull_requests}.php}"
  - apps/gateway/app/Actions/Tasks/**
  - "apps/gateway/app/Http/{Requests/Tasks/**,Controllers/Api/{TasksController,TaskGroupsController,TaskPullRequestController,TaskInsightsController,TaskSwampRunsController,AgentThreadsController,TaskQuestionsController}.php}"
  - "apps/gateway/app/Console/Commands/{TaskPullRequestGateCommand,TaskPublishWhenDoneCommand,FileProblemsCommand}.php"
  - "apps/gateway/app/Models/{Task,TaskComment,TaskCheck,TaskQuestion,TaskReviewedCommit,AgentThread,JevDecision,ProblemFingerprint,ProblemCollectorState}.php"
  - "{apps/gateway/resources/tasks/**,swamp/extensions/behaviors/operations/{task-repository/{assets,lifecycle,publication,check}.ts,tasks/publish.ts,dependency-merge/**},swamp/workflows/{workflow-operation-dependency-merge,workflow-operation-task-publish,workflow-operation-task-pr-ready,workflow-operation-task-pr-merge,workflow-operation-task-check,workflow-task-check-ci}.yaml,swamp/extensions/behaviors/_lib/public_output.ts}"
  - apps/docs/app/{Documentation/DocsImpact.php,Console/Commands/DocsImpactCommand.php}
  - "apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_31_000000_add_task_vm_publish_error_code,2027_01_04_000000_add_task_insight_fields,2027_01_18_000000_create_task_review_rounds_table,2027_01_20_000000_keep_review_mode_off_subtasks,2027_01_22_000000_run_review_round_as_one_operation,2027_01_31_000000_clear_copied_subtask_token_totals,2027_02_02_000001_add_task_idle_save,2027_02_19_000000_add_task_pr_modes,2027_03_07_000000_convert_legacy_problem_evidence}.php"
---

# Tasks

Swamp is the only executor of task groups; it runs provision, agent turns, checks, publication, review parking and cleanup as keyed operations. The Gateway stores the board and evidence and exposes keyed operations; it never claims a group, advances a subtask, retries a turn, appends a fixup or merges a PR on a timer. Two ticks are the exceptions: the [automatic publish](#publish-by-itself) of a done group, and the [merge round](#the-merge-round) after a copy publish conflicted. All groups use `external` orchestration, including groups created without an explicit mode. Existing records move to that mode without changing their status, workspace, assistance or receipts.

Tasks is an optional Gateway extension. It runs planned work with coding agents in [task copies](#task-copy) of a Project's default container Instance. The task VM runtime is removed, not disabled. A task is one feature or bug fix, delivered as one pull request. Its subtasks run in order in one shared task workspace. A fresh implementer builds each subtask, the Project's task check verifies the handoff, and a fresh reviewer approves it. A container handoff that requires hosted CI currently refuses with `tasks.instance_hosted_check_unavailable`; [check phases](/reference/task-instance-workspace#check-phases) describes this limit. Publication pushes the checked work and can open a pull request.

While a subtask is open, Orbit also watches a pull request on `task-{id}`. It stops starting subtasks when that pull request merges or closes.

A Project can opt in to [review and merge](#review-and-merge). Orbit then reviews the whole branch before it pushes anything for review, reviews the pull requests that listed authors open, and merges a reviewed head when CI passes.

The engine is generic. Your agentic development environment (ADE) plans and steers the work. Orbit runs it. Each Project keeps its own task policy in its repository, as an `orbit-tasks` skill under `.agents/skills/` and in its other instructions, and enforces it through its own task check. Agents read that policy from the repository, not from the shared prompts. The Orbit repository keeps its policy in the [orbit-tasks skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) and the [contributor guide](/contributor-guide).

Agents use the Tasks tools of the [MCP server](/reference/mcp). The [`task` CLI family](/cli/task) runs the same operations from a terminal. There is no web UI to create or change a task. The API, the CLI, and the web app call a child task a subtask.

A [Project removal](/reference/projects#remove-a-project) deletes its task groups and their dependent records only when every group is `cancelled` or `completed`. Any other group status, including `failed`, blocks removal with HTTP 409 and `project.has_task_groups`. The refusal leaves all records unchanged. Removal reads and deletes no task VM row: the task VM tables are dropped ([old paths](/reference/old-paths#the-task-vm-tables)).


## Extension switch and status

Enable and disable the extension with `orbit extension:enable tasks` and `orbit extension:disable tasks`. Both need Gateway access. While the switch is off, the `tasks` commands, MCP tools, and web pages are hidden, except `task:status` and the `task-status` tool. Every other task operation refuses with HTTP 409 `extension.disabled` and changes nothing. Stored tasks and subtasks stay. [`extension`](/cli/extension) describes the switch.

`task:status` is an assistance and status view, not a switch. Its route returns `enabled`, `assistance`, `last_tick_at` (always null), and `merges`. `merges` lists each open task whose pull request Orbit reviews and merges: a task with a `pr_url` in a review-and-merge Project, or a task that reviews an incoming pull request. An open task without a pull request, such as one in `backlog`, is absent.

`assistance` lists every task whose `assistance_requested` is true, in ascending task id order. Each entry has `id`, `project_id`, `project`, `project_code`, `title`, `status`, `assistance_kind`, `assistance_question`, and `assistance_reason`. An ended task asks only for `removal_failure` assistance. A task that is not asking is absent, even when it still stores an old reason. A subtask's assistance flag does not add its parent task to the list unless the parent also requests assistance. The view remains available while tasks is disabled.

## Model

A verified Gateway release can file a backlog Task in its source Project for an Operation version that has stopped accepting new work and finished its last run. The brief identifies the retained handlers and workflows to review. Repeated releases keep the same Task. See [Operation versions across a Gateway release](/reference/operations#operation-versions-across-a-gateway-release).

A task is one row in the `tasks` table. A row with no `parent_id` is a top-level task: the Tasks board shows it, and it is one feature or bug fix delivered as one pull request. A row with `parent_id` is a subtask of that parent. A subtask has no children.

A top-level task holds the task workspace, the branch, the reviewed pull request, the watched pull request, the current reviewer thread, and the settle metrics. A subtask holds its position, its deliverables, its implementer thread, its task checks, and its turn receipts. Both levels store a title, a brief, a status, assistance, comments, and metrics. The Gateway rejects a value in a column that the level does not use.

| Field | Level | Meaning |
| --- | --- | --- |
| `title`, `brief` | both | Short name, and the goal and acceptance |
| `status` | both | Lifecycle state |
| `orchestration` | task | `external` (the default and only value). Create sets it; it never changes |
| `current_recipe` | task | Stored create-time boolean, default `false`. It selects no VM image. Task starts clone the default container's seed |
| `parent_id` | subtask | The top-level task. Null on a top-level task |
| `assistance_requested`, `assistance_reason` | both | Whether the record asks an operator for help, and why. See the [assistance lifecycle](#assistance-and-resolution) for ended tasks and cleanup failures |
| `assistance_kind`, `assistance_question` | both | The typed [assistance cause](#direction-requests) and the one question for a `direction` request. Other kinds have no question |
| `position` | subtask | Order under the parent, gapless from 1 |
| `deliverables` | subtask | The typed items the subtask must deliver |
| `check` | subtask | The latest [task check](#project-check) run |
| `fixup_problem` | subtask | The problem a [Gateway fixup](#settling) repairs. Null on every other subtask |
| `implementer_agent_thread_id` | subtask | The subtask's implementer thread |
| `reviewer_agent_thread_id` | task | The current reviewer thread. A shared reviewer thread is stored here, and [Review a subtask](#review-a-subtask) points it at the fresh reviewer |
| `taskable_type`, `taskable_id` | task | The task workspace Instance. Null until a provision operation creates it |
| `implementer_agent_provider`, `implementer_agent_model`, `implementer_agent_effort` | task | The [copy turn agent](#copy-turn-agents) the group names for its implementer. Null takes the Project's default, then the Gateway's |
| `reviewer_agent_provider`, `reviewer_agent_model`, `reviewer_agent_effort` | task | The same for the reviewer |
| `implementer_model`, `reviewer_model` | task | Stored model columns. A new task stores each role's resolved copy model here; reads use the copy agent fields above |
| `pr_url` | task | The reviewed pull request Orbit opened on the last subtask, or the [incoming pull request](#ready-and-merge-a-task-pull-request) Orbit reviews |
| `pr_branch` | task | The incoming pull request's head branch, which Orbit fetches and pushes. Null for a task whose branch is `task-{id}` |
| `merge_status`, `merge_reason`, `merged_sha` | task | The [merge step](#merge-step) result: `waiting`, `refused`, or `merged`, its reason, and the merge commit |
| `type` | subtask | `implementation`, or `final_review` for a [final review](#review-a-subtask) |
| `watched_pr_url` | task | The pull request on `task-{id}` found by the [branch watch](#watch-the-branch-while-subtasks-are-open). Null until that list finds one. Not `pr_url` |
| `watched_pr_number` | task | The watched pull request's number. Null until the branch watch finds one |
| `watched_pr_state` | task | The last watched state: `open`, `merged`, or `closed`. Null until the branch watch finds one |
| `watched_pr_completion` | task | `merged` or `closed` after `task:complete` confirms the end, or `merged` before automatic merge cleanup. Null until then. Resume does not call GitHub |
| `ended_pr_notice_key` | subtask | Stable send key for the one ended-pull-request notice. Null when that subtask has no notice |
| `ended_pr_notice_thread_id` | subtask | The implementer or reviewer thread that notice belongs to |
| `ended_pr_notice_state` | subtask | `pending` or `delivered` |
| `vm_power` | task | [VM power](/reference/compute-drivers#vm-power): always `null`, because no task VM exists |
| `ready_at`, `ended_at`, `merged_at` | task | [Lifecycle times](#lifecycle-times): when the task first entered `todo`, when it ended, and when GitHub merged its pull request |
| `tokens`, `line_diff`, `lines_added`, `lines_deleted`, `duration_ms` | both | [Settle metrics](#settle-metrics): distinct-thread tokens, per-subtask usage deltas, recorded whole-branch line counts, and duration |

Clearing the assistance flag can keep the last reason.

Typed comments record the workflow. A stored turn receipt is a comment whose type is its outcome: `ready_for_review`, `blocked`, `changes_requested`, or `approved`. An operator posts `assistance_requested` and `resolution` comments. Each comment keeps its full body, author, time, and attempt. An approval that Orbit committed carries `commit_sha`. The approval of the last subtask also carries `pull_request`: the summary, changes, and breaking changes it proposed.

`GET /api/v1/tasks/{task}/comments` and the MCP tool `task-comment-list` accept optional `type` and `limit` query inputs. `type` must be a task comment type, such as `resolution` or `assistance_requested`. `limit` must be an integer from 1 to 100. The endpoint returns comments newest first, filters by type before applying the limit, and preserves each comment's full body and response shape. With neither input, it returns all comments as before. An unknown type or an invalid limit returns HTTP 422 with `validation.failed`. Use `type=resolution` and `limit=1` to read the newest resolution without returning the full comment history; the limit bounds the number of comments, not the byte size of an individual body.

`GET /api/v1/tasks` and `GET /api/v1/tasks/{task}`, and their MCP tools `task-list` and `task-show`, accept an optional boolean `compact` query input. With `compact=true`, each group omits `brief`, `assistance_question`, and `assistance_reason`; each subtask omits those fields plus `completion_summary`, `deliverables`, and `fixup_problem`. The latest check keeps its metadata but omits `output`. Omitted keys are absent, not null. Ids, titles, statuses, positions, assistance flags and kinds, and counters remain available.

Without `compact`, or with `compact=false`, show returns every field and the list returns its [list rows](#list-tasks). Invalid boolean input returns HTTP 422 with `validation.failed`. Use compact reads to avoid returning long text in the MCP output; this reduces the response size but does not impose a byte limit. [List tasks](#list-tasks) says how the list pages.

### External orchestration

Every task group is external. Swamp owns its lifecycle. The Gateway refuses a subtask's nonempty `topology` list with HTTP 422 `tasks.orchestration_unsupported` and ignores an empty one; the existing Project review-and-merge setting does not block admission. Only explicit keyed operations start work. Group and subtask status updates record board state without starting an agent, check or cleanup.

#### Task runtime

A task runs only in a [task copy](#task-copy): a copy of its Project's default container Instance. Task VMs are removed. Every entry that would admit a task VM answers HTTP 422 `tasks.vm_runtime_removed` before it records anything:

- `task:start` of a task whose Project has no default container Instance and no `nested_fleet` flag;
- a turn of a group that owns no copy;
- a `proof:run` run of a proof Project without a container default.

The message says what to do: `Task VMs are removed. A task runs only in a copy of its Project's default Instance, and that default must be a container. Move a checkout default into a container with instance:migrate, or create a container default with instance:create; then start the task and work in its copy with task:turn, task:check and task:publish.` The CLI prints it, and Conn and the API show it with the code. No admission waits for a task place any more.

The Pi task loop is removed ([old paths](/reference/old-paths#the-pi-task-loop)): `task:prep`, `task:prep-fix`, the build, the review round, the maintenance run, the package install, the repository script, the idle save and the Pi turn. The routes `prep`, `prep-fix`, `package` and `script` are gone, so a call answers HTTP 404, and the MCP tools `task-prep`, `task-prep-fix`, `task-package` and `task-script` are gone from the catalogue. The [gate check](#gate-check) starts neither a build, nor corrections, nor a review round. Their old Operations stay as history.

#### Provision an external group

`task:start <group>` starts the Gateway operation `tasks.provision` through `POST /api/v1/tasks/{task}/start`. Its target is `task_group` with the group ID. Admission locks the group row while it captures the intent. It holds no Gateway execution lease or target hold. An ended group returns HTTP 409 `tasks.group_closed`. The Gateway retries no effect for an external group; the orchestrator decides whether to try new work.

A task of a Project without a default container Instance is refused by name; see [Task runtime](#task-runtime).

A `proof:run` of such a Project runs in [copies](/cli/proof/run#the-run) instead. A nested-fleet Instance container gets its own Swamp Club key at each start, never the proof club key; its Operation's result says what that start did with it ([Club key of the nested Gateway](/reference/development-containers#the-club-key-of-the-nested-gateway)).

Provision requires `todo`. A backlog group returns HTTP 409 `tasks.not_todo`; a claimed group returns HTTP 409 `tasks.already_claimed`.

`tasks.provision` is the request kind of the route, which scopes its `Idempotency-Key`. The start makes the group's [task copy](#task-copy) as an `instance.create` and moves the key to that kind. No `tasks.provision` Operation starts, and its Swamp workflow `operation-task-provision` is deleted with the task VM start (`task-vm-start`) and the hub peer it added ([old paths](/reference/old-paths#the-pi-task-loop)). The registry entry names `operation-instance-create`, the workflow that a start runs through its copy.

The [writes audit](/reference/operations) also checks custom behavior and model methods. Its reviewed native-data methods live in `nativeDataMethods` in `swamp/tests/writes-reviewed.json`.

The group lifecycle after provision stays in separate keyed operations (`tasks.check`, the turn, `tasks.publish` and `tasks.cancel`), each with its own receipt and time budget.

Start uses the default container's seed, not a fresh repository clone or VM image. The [copy create](/reference/development-containers#a-task-copy-starts-clean) removes uncommitted work from that seed. Its app and dependencies come from the default; a baseline does not rerun setup. The [task copy](#task-copy) contract owns placement, create results and failed-create cleanup.

"Node 209" below means the production fleet's Swamp Node. Admission finds it by the `swamp` role, not a fixed id. SSH reaches the container's host Node; the container has no Node identity of its own.

#### Last activity

A group's `last_activity_at` is the newest time any Operation on the group was created, started or ended, or null when it has none.

#### Release an idle task VM

The idle release and its save, `tasks.idle_save`, are removed with the task VMs. A task copy stays until its group ends, so its work needs no save. The columns `idle_saved_commit` and `idle_saved_at` are dropped ([old paths](/reference/old-paths#task-vm-settings)). A save branch `task-<id>-idle` that an old save pushed stays on origin until a person deletes it.

#### Keep command output out of run history

Task checks write command output to guest files. Ansible fetches those files through the Node into a private worker directory and removes the guest and Node copies on success or failure. The result reader redacts a bounded tail and deletes the worker files. [Check phases](/reference/task-instance-workspace#check-phases) owns this transport; raw repository output must not enter native history.

One module holds the rule: `swamp/extensions/behaviors/_lib/public_output.ts`. It reads at most the last 1 MiB of a file and replaces invalid UTF-8 before matching. It replaces known values of eight characters or more in one pass. Then it replaces credential-shaped text: PEM blocks, URL user info, `Authorization` headers, `Bearer` tokens, GitHub tokens, 64-character hex credentials and the values of secret-named assignments.

It splits the redacted capture into lines and keeps the newest whole lines that fit the bound. It drops a first line that may start mid-value when older bytes were discarded, and any line that still holds an 8-byte piece of a known value. A line longer than the room left is replaced by `[a line of N bytes omitted]`, and older lines still fill the bound, so one long line never hides the lines before it. Task commands receive no credentials, except that setup commands can read the token files of the private dependencies, so they pass no known values today.

Checks keep 16 KiB of redacted output.

#### Check an external group

`task:check <task> --phase=baseline|handoff` starts `tasks.check` through `POST /api/v1/tasks/{task}/check` with `{ phase }`. The Gateway admits the open group, current child and owned task copy, records one check row and supplies the public intent to `operation-task-check`. Same-key replay returns that Operation before mutable admission.

A task copy is required: a group without one returns `409 tasks.no_workspace` ("The group requires its task copy."). The run always calls `task-instance-check`, whose [Instance phases](/reference/task-instance-workspace#check-phases) validate deliverable paths, check the workspace and commit the candidate. The root reads the child's typed receipt and checks its execution result. There is no SSH VM alternative. A subtask outside the group returns `404`. Another open subtask returns `409 tasks.subtask_not_current`. A baseline after an implementer has started anywhere in the group returns `409 tasks.baseline_done`. A handoff whose Project requires hosted CI refuses with `tasks.instance_hosted_check_unavailable`.

Ansible reaches the admitted host Node over pinned SSH and runs the Project check and deliverables as `agent` in the container. A baseline runs no setup: the seed already ran it. Handoff first brings in the default branch. The workspace phases own snapshots, start-commit command runs and candidate commits.

The result preserves the admitted subtask base as its start commit, including after a failed check. It uses `original` only when no base was admitted. A retry must not replace its base with the candidate it just checked.

A snapshot runs `git read-tree HEAD`, `git add --all` and `git write-tree` against the run's own index file, and `git rev-parse HEAD`; the real index is untouched. The first snapshot follows the Project check because its formatters run in fix mode: their edits belong to the checked tree, so they do not make the check `changed`.

Commands receive no GitHub or Gateway credentials. Their output stays out of native history ([command output](#keep-command-output-out-of-run-history)); the result keeps a redacted tail of at most 16 KiB.

A command marked `fails_on_base` also runs on its start commit. The [workspace check phase](/reference/task-instance-workspace#check-phases) extracts the base once per checked candidate, then gives each command a private copy with only its installed dependency closure and declared test-path overlays. A missing overlay file or directory refuses the check as `tasks.deliverable_invalid`; a phase execution failure returns its workspace error code.

A changed checked tree returns `changed`; a failed command, an unmet deliverable or a command deliverable that did not run returns `failed`. A failed setup step or Project check keeps its output and runs no command deliverable; it creates no candidate.

A command deliverable stored without `fails_on_base` is admitted with `false`; the subtask keeps it as stored. The check workflows read the value of each command deliverable and never default it.

A passing handoff records the checked candidate under `refs/orbit/check-operations/{operation}` in the copy. It reuses a clean candidate that an earlier check recorded; otherwise it commits the checked tree with the admitted message and hooks off. [Check phases](/reference/task-instance-workspace#check-phases) describes the exact Git evidence.

Hosted CI is refused for task copies at admission. The old scheduled CI observation remains for recorded checks. A native `trigger.schedule` runs about once a minute and performs one bounded status observation per pending check: one `gh api` read of the latest check runs for the exact SHA, at most 100, without paging. TypeScript on 209 keeps only the public run fields.

A queued or running GitHub Actions job on that candidate keeps CI pending while the aggregate Required check has not appeared. Another head or App cannot extend this wait. Without active jobs, a missing Required check fails after ten minutes; active CI keeps the existing 55-minute limit. Only the named Required check can supply passing evidence.

Each observation of a settled result also snapshots the workspace with the same four argv steps (`snapshot-<operation>-index`, `-add`, `-tree`, `-head`); when HEAD or the tree differs from the candidate, the result is `changed`. The observation never waits or retries inside a step.

The collector prints one row per settled check, and a check result with long output exceeds what Swamp inlines in the run history. The Gateway then reads the `output` data artifact of `task-check-ci-data` with `data.get`, at the version the history names. Pending CI remains ineligible for reviewer and publication admission. A pending observation keeps the original Operation running. The family applies recorded cancellation intent on the next delivered valid observation; this does not claim that GitHub or the completed original native run was cancelled. Native pending records settle on a terminal result or 55 minutes after the push.

If SSH preparation fails, native dependencies skip the checks and result steps that need the missing validation and setup outputs. The run keeps its original failure, and the shared terminal reader settles the Operation. Cleanup remains separate native work; no empty output is substituted for a missing predecessor.

The scheduled result identifies the admitted repository, check name and exact SHA; passed CI or a code failure records terminal check evidence through the shared projection. Unreadable or misconfigured CI records an infrastructure failure. Cancellation preserves the pushed candidate and does not cancel GitHub workflows. No webhook or new GitHub access is required.

The native `Check the result` assert fails on an execution error in the Instance check receipt. The Gateway captures that receipt in its terminal history read. Scheduled CI reads take their rows directly from `collect`. Neither workflow prints its result through another shell step.

The result retains `outcome`, `deliverables`, `check_id`, `commit`, `head`, `tree`, redacted `output` and `ci`. Each deliverable has an `id`, an `outcome` and its `failures`, one line each. Its outcome is one of these:

- `passed`: the checked diff holds the file change, or the command ran and exited 0, and a `fails_on_base` command also failed on its start commit.
- `failed`: the diff lacks the file change, or the command ran and exited non-zero. A `fails_on_base` command that passed on its start commit is `failed` too.
- `not_run`: the check holds no run of the command. Its one failure line is `<id>: the command did not run: <reason>`. The reason names the step that failed first (`the step "<name>" failed first`), `the Project check failed first`, or `no evidence was recorded`.
- `not_checked`: a baseline, or a review deliverable.

A `not_run` deliverable never passes: any `failed` or `not_run` deliverable makes the check `failed`. `head` identifies the checked snapshot before commit; `commit` identifies the candidate a reviewer or publisher may use. Only a passed check with the matching commit, tree and required CI supplies a checked candidate. The Gateway records native results without rerunning checks or judging their evidence.

#### Run a tracked repository script

`tasks.script` ran a tracked repository script in a task VM workspace. It is removed with the Pi task loop: the route `POST /api/v1/tasks/{task}/script` answers HTTP 404, and the MCP tool `task-script` is gone. No `orbit` command ever started it.

#### Install a private package

`tasks.package` installed a private package in a task VM workspace. It is removed with the Pi task loop: the route `POST /api/v1/tasks/{task}/package` answers HTTP 404, and the MCP tool `task-package` is gone. No `orbit` command ever started it.

#### Run an external turn

`task:turn <task> --role=implementer|reviewer --prompt=TEXT [--thread=new|ID] [--check=ID]` starts a turn through `POST /api/v1/tasks/{task}/turn`. The turn runs in the group's [task copy](#task-copy) as `tasks.copy_turn` ([chart](/cli/task/turn#in-a-task-copy)). Without `--thread`, it continues the role's thread. `--check=ID` is required for a reviewer turn. The CLI follows the operation and accepts `--idempotency-key` and `--json`; the PHP SDK and generated MCP tool expose the same inputs. A group that owns no copy gets the [refusal](#task-runtime) of the removed task VMs.

The role `consult` and the field `model` were options of the Pi turn on a task VM, which is removed with the Pi task loop. A request with either answers HTTP 422 `validation.failed` on that field and records nothing. The route's Operation kind for an `Idempotency-Key` is `tasks.copy_turn`; a retry keeps the fingerprint it had before.

No `tasks.provision` Operation starts any more: a task start makes a task copy. An old one that is still open holds its group as every open Operation does, so other work returns `operation.target_busy` until the reconcile pass ends it.

A subtask outside the group returns 404. Only the first open subtask by position can run; another returns HTTP 409 `tasks.subtask_not_current`. The admission composes the prompt for every role. First come the group's title and brief, read from the subtask's parent, then the title, brief and deliverables of the subtask. Orbit's copy rules and the turn's clock follow, then the prompt of the caller. It records that text as the admitted prompt. A composed prompt over 100000 characters, the group's brief included, returns 422 `tasks.prompt_too_large`.

The copy rules are one fixed text, the same for the implementer and the reviewer. They name no command of one Project:

- Build and prove the feature end to end in the copy before its pull request. The line also names the nested fleet, the loop and the release rule of [Build a feature in one copy](#build-a-feature-in-one-copy).
- Run every command in the foreground. Leave none running when the turn ends.
- The Project's long gates and full test sets are the work of the task check and CI (`task:check`). Run single test files by path.
- Keep each new or changed test unit within the per-unit time cap of the Project's CI, which the task check prints.
- Commit your work before the turn ends.
- Never print an environment file, a token, a key or a session value.

#### Publish an external group

`task:publish <group> --check=<id> [--title=… --body=…]` starts the native `operation-task-publish` workflow through `POST /api/v1/tasks/{task}/publish`. The JSON input is `{ check, pull_request?: { title, body, draft? } }`. Supply title and body together. `--draft` sets `draft` and opens a new pull request as a draft, as the Orbit App; an open pull request keeps its state. The CLI accepts `--idempotency-key` and `--json`; the PHP SDK and MCP carry the same inputs.

For a group with a task copy, `task:publish <group>` without `--check` publishes the group's done check, as [Release a waiting group](#release-a-waiting-group) describes. The JSON input is then `{}`.

For a group with a task copy and no pull request, `--check` without `pull_request` opens the pull request in the same Operation when the check is the group's [done check](#when-the-work-is-done) and the reviewer sent its [text](#the-reviewers-pull-request-text). The Gateway then publishes exactly what the release publishes: the reviewer's title and body, a ready pull request, and the done check read again under the group lock. So one `task:publish <group> --check=<id>` after the approval opens the pull request of a `review_before_pr` group.

Any other check only pushes: one that is not the done check, such as a newer check the reviewer has not approved, and the done check while the reviewer's newest turn sent no text. The Operation's input and its result then name why in `pull_request_withheld`; the run's intent never carries it.

On a group that has its pull request, `--check` without `pull_request` that names the done check with the reviewer's text updates the open pull request's title and body with that text, as a [republish](#release-a-waiting-group) does. Any other check of such a group only pushes, with no `pull_request_withheld`.

The Gateway admits an open group, its owned workspace and an [eligible checked candidate](#check-an-external-group) belonging to that group. Required hosted CI must have passed for the recorded check and commit. Otherwise admission returns `422 tasks.commit_not_checked`. It pins the repository, default branch, candidate commit, checked tree and SSH target in the intent. Admission serializes publication with the group lock and refuses other active work. It mints no token. The intent names two vault keys, one for the GitHub installation token and one for its Git authorization header.

A step of the run asks the Gateway for the token right before the push (`operation:direct:github-token`, with the run id), so the token is fresh whenever the run reaches that step. The Gateway checks the Operation, the run and the repository. It mints a token for that one repository with `contents: write` and `pull_requests: write`, and writes the two values. The token has no `workflows` permission, so it cannot push a change under `.github/workflows/`. Its Activity row names the repository and these two permissions. The reply holds vault descriptors only.

A copy's [base read](#the-base-of-a-copy-publish) refuses a workflow change of the copy's own before the push, and merges the default head into a copy that is only behind one.

A worker on Node 209 runs the [workspace phases](/reference/task-instance-workspace#contract) `preflight` and `export` through the recorded host Node. They read the checkout as `agent` and fetch the exact candidate bundle to the worker; the Node keeps no copy. Bounded Git commands import it into a disposable bare repository and verify its commit and tree. Only `git push` and `gh api` resolve the execution credential, as an environment value, never in argv or run history. Neither repository commands nor the task copy receive a token.

The chart names every native command and data step. Separate operations stay visible; ordering-only child workflows and result programs are removed.

Swamp pushes the exact candidate to `task-{id}` without force; a task copy pushes its [publish merge](#the-base-of-a-copy-publish) instead when the base read made one. Git pushes nothing for an identical remote tip and refuses a divergent one, so no tip read guards the push. A task copy's base read reads the tip only to decide whether to merge.

A divergent remote tip fails with `tasks.not_fast_forward`; a GitHub workflow-permission refusal fails with `tasks.workflows_permission`. For a task copy, the base read refuses such a push first, so that code is only a fallback for a reason the read did not see. Other transport failures use `tasks.push_failed`. A lost push reply is reconciled by the next push, which finds the identical tip.

Without `pull_request`, publication only pushes. With it, a `gh api` step looks for an open pull request from the admitted branch. TypeScript on 209 plans one update of its title and body when found, or one creation against the admitted default branch, and a second `gh api` step sends it from a private JSON input file. A lost reply fails the operation.

After cleanup removes scratch, start a fresh command. Its push step mints a fresh token, pushes again and repeats the PR lookup before another mutation. Native resume cannot recreate scratch from a completed step, and it cannot reopen a terminal Operation.

Token revocation (`gh api --method DELETE installation/token`) and scratch cleanup are separate native steps. Publication requests no reviewers and runs no brief coverage. A native assert confirms publication and worker cleanup. The Gateway projects the PR step's public metadata into `pr_url` and the watched PR fields. The copy stays until completion or cancellation. Raw Git and GitHub replies stay in private scratch; typed readers retain only public refusal codes.

The Gateway requires the [task copy](#task-copy)'s last completion not to have failed (`tasks.copy_completion_failed`), its Instance to be `active` (`tasks.copy_not_ready`), its container to run (`tasks.instance_not_running`) and no earlier copy turn to remain open (`tasks.copy_turn_unended`). The intent holds the copy's container, start commit and `.env` path from the Instance record.

When the run starts, the Gateway also renders the `.env` from the Instance's stored values and passes it as a vault copy (`execution_secrets.environment`, key `task-publish-environment-<operation>`), never as a run input. The agent cannot change that copy, unlike the file in the copy. It holds the copy's own keys as Orbit stored them; it is not the copy's file byte for byte, because a clone keeps the default's file from the seed and rewrites only its own keys ([the environment of a clone](/reference/development-containers#the-environment-of-a-clone)). When the copy is destroyed, these copies are deleted with the Instance's other execution copies.

After the import, a step on 209 checks every commit from the copy's start to the candidate: each must hold the `.env` path exactly as the start does. A commit that adds or changes it fails with `tasks.publish_secret_path` before the token is minted, also when a later commit removes it again, because the key would stay in the pushed history. The same code answers when the branch history lacks the start commit, for example after a rebase past it.

A second step then checks the content. It takes every object that is new in the range: file contents, file names and commit messages, also of commits that a later commit undoes. It refuses with `tasks.publish_secret_content` when one holds the whole `.env`, or one of its values of 8 bytes or more that the start tree does not already hold. The pull request title and body are checked the same way.

The values come from two references: the copy's own file, fetched from its Node beside the bundle, and the Gateway's render of the Instance's stored values.

A copy in the nested-fleet project also holds the [Club key](/reference/development-containers#the-club-key-of-the-nested-gateway) of its inner Swamp, so its run also reads the key's one vault entry, `nested-fleet-club-api-key`. That key counts as a value even if the start tree holds it; like every value, it must be 8 bytes or more. A hit on it refuses like any other, with no hint which secret matched.

A value is read as Orbit writes it, with its escapes undone, and as a default's file may hold it: after `export`, before an inline comment, or in a commented-out line. The refusal names the commit and the object, and the file only when its path holds no value. Any error of the check refuses with `tasks.publish_secret_unchecked`.

Swamp keeps no stderr of a failed command step. So the check keeps its exit code and writes its one refusal line to the private scratch. The next step, "Read the .env check", fails with that line as the Operation's detail. The token is minted only after the check succeeded.

The content check catches copies and plain lines, not transformed text. It misses:

- a value that is encoded, compressed, escaped or split over lines;
- a value shorter than 8 bytes, unless the whole file is copied;
- a value that the start tree already holds, which is public already;
- an inherited key of the default's file that the agent changed in the copy's file;
- an old value from before an `env:update` during the task;
- the `.env.testing` of a copy with a test database;
- a value in an unpushed start commit, because the start tree counts as public;
- every secret that is not in this `.env` or, for a nested-fleet copy, the Club key: [the copy check](#the-copy-check) covers the copy's other secrets.

A long value of the copy that is no secret is refused too, when the start tree lacks it: its `APP_URL`, a host address or a user name. So is such a value in new commits of the default branch that the task branch merged. Remove it from the branch history and check again.

A Project that tracks `.env` in Git cannot publish from a copy: Orbit's write changes the tracked file, so every check commit holds it. A moved head refuses in the preflight and fails with `tasks.publish_failed`, not `tasks.head_moved`.

##### The copy check

The publish of a copy also checks the copy itself, before any token exists. A workspace phase, `scan`, runs `swamp/ansible/task_copy_scan.py` in the container, where the secrets are. Root reads the secret values and never follows a link to a source. Git runs as `agent` and only lists and reads objects. The program searches them itself, so a value reaches no other process. It checks each object's hash, so a changed object file reads as an error.

The program prints one line of evidence. It names paths, line numbers and secret names, never a value. So no value reaches an Operation result, a log line, an error or a vault execution copy. In every copy `agent` has root through `sudo` ([ADR 0268](/decisions/0268-one-owner-for-the-workspace#amendment-sudo-for-agent-in-every-container)), so these rules guard the check, not the secrets: the agent can read them anyway. A container holds only dev-scoped secrets, never production-grade ones.

| Part | Rule |
| --- | --- |
| Outer secrets | The Club key in `/run/orbit-club` and the operator WireGuard key in `/etc/wireguard` |
| Inner secrets | Of each inner machine: the Gateway `.env` values, the Swamp tokens, the Orbit token files and the WireGuard keys |
| Skipped | A value under 16 bytes, which matches by chance, and a value that the start tree holds. Both are counted per name |
| Range | Every object new from the copy's start to the candidate, and the proposed pull request body |
| Private keys | A `-----BEGIN … PRIVATE KEY-----` block with 100 or more base64 characters. A short test stub passes |
| Same objects | The worker's content check refuses unless its object digest equals the copy check's |

A plain copy has no outer or inner secrets, so it checks only for private keys. The check does not judge workflow changes: a copy's remote branches show the main that its last sync or [handoff check](#the-handoff-check-merges-main-first) brought in, not the default branch head on GitHub at publish. [The base of a copy publish](#the-base-of-a-copy-publish) judges them from the merge base with that head.

A secret refuses with `tasks.publish_secret_value`. The publish result lists the items in `refused`: `path:line secret-name`. `refused_more` counts the rest. An error refuses with `tasks.publish_secret_unchecked`. So does a nested-fleet copy that shows no inner machine. In each case nothing is pushed. A hit in a file name names the folder that holds it, and a path that holds a value is never printed.

On Orbit's own tree the program took 0.25 seconds for 80 values and 196 objects. The phase around it, one more Ansible run on the Node, adds an estimated 5 to 10 seconds to a publish. The program is bounded at 300 seconds.

It catches copies of a value, not transformed text. It misses:

- a value that is encoded, compressed, escaped or split over lines;
- a value under 16 bytes;
- a secret in a file it does not read, such as an inner machine's database files or an inner `base` snapshot with an older token;
- a value in the caller's own pull request title or body, which the content check reads for the `.env` only.

The private-key rule has no waiver, and the range holds the commits that a sync of the default branch brought in. So a real-looking test key that the default branch changed after the copy's start, such as `apps/gateway/tests/Fixtures/gateway-bootstrap-root.key`, refuses too. A person publishes such a change by hand.

##### The base of a copy publish

A copy's publish states the base it publishes against. After the token is minted and before the push, the run fetches the default branch head into its own scratch repository on the worker. That head is the one at publish time. Of the intent, the step reads only `remote`, `base` and `node_id`, which the publish intent already had. The run computes:

| Field | Meaning |
| --- | --- |
| `base_commit` | The checked base: the merge base of the checked commit and the default head |
| `default_commit` | The default branch head at publish time |
| `behind_by` | The commits of the default branch that the checked commit lacks |
| `overlapping_paths` | The paths that both the checked commit and the default branch changed since the base |
| `overlapping_more` | How many overlapping paths lie beyond the listed ones: at most 100 paths in at most 3,000 bytes |
| `merged_default` | The default head that the worker merged at publish, or null when it pushed the checked commit itself |

`git merge-tree --write-tree` then merges the two inside the scratch repository; no workspace is touched. Of the merge, only a real conflict refuses: `tasks.publish_conflict` names the conflicting paths in `conflicting_paths`, at most 100 in at most 3,000 bytes so the refusal fits the step detail Orbit keeps, with `conflicting_more` counting the rest, and nothing is pushed. Such a failure starts a [merge round](#the-merge-round): Orbit brings main in, merges, checks, reviews and publishes again by itself. Being behind, with or without overlapping paths, never refuses.

These fields join the result. With `--title` and `--body`, the pull request body gets a fixed `## Base` section after the caller's text and the [waiver lines](#docs-impact-waivers-in-a-copy) the publish adds. It states the base commit, the default head and how far behind. After a merge at publish it adds the line `- Merged main <sha> at publish`. Then it lists the overlapping paths, or none. An Orbit marker opens the section, so a body that carries an earlier one gets it replaced and a re-publish states it once, with any waiver line that followed it kept before the new section.

The body stays within its 65,536-character limit: when the path list does not fit, the section names only their count, and when the section does not fit at all, the body has none; the result states the base either way.

A copy's handoff check already [merged the head it judged against](#the-handoff-check-merges-main-first). So when the default branch did not move since that check, the publish reads `behind_by` 0 and pushes the checked merge commit itself. A conflict that a busy main brings shows up at the check, before review, not at publish.

The base read also judges workflow changes. It lists the copy's own: the paths under `.github/workflows/` that the checked commit changed since the merge base, `git diff --name-only <base> <commit>`, which is `git diff <default>...<commit>`. A commit that the default branch already holds is never a change of the copy, whether [`task:sync`](#bring-main-into-a-copy) brought it, even from a main newer than the last release, or it was carried in by hand. A workflow change of the copy that a later commit undoes is no change either. A merge that keeps the copy's own version of a workflow that the default branch changed is a change of the copy.

Any listed path refuses with `tasks.publish_workflow_change`, before a conflict refuses. The publish result lists the paths in `refused`, at most 100 in at most 3,000 bytes, and `refused_more` counts the rest. Nothing is pushed. The publish token has no `workflows` permission, so GitHub would refuse such a push too. A task cannot change a workflow from a copy: a change of its own under `.github/workflows/` belongs to a person. The agent says what to change, and a person makes that pull request by hand with their own GitHub account. The pipeline lane does the same.

###### Merge main at publish

GitHub judges the pushed branch's tree against the default branch, not its commits. So a copy that has no workflow change of its own but is behind one of the default branch would still be refused, with `tasks.workflows_permission` (Operation 24460). A workflow change merged on main after the copy's sync does this too.

When the own list is empty, the trees under `.github/workflows/` of the fetched head and the checked commit differ, and the merge is clean, the worker merges at publish. In its scratch repository it writes a merge commit:

- its tree is the `git merge-tree` result;
- its parents are the checked commit, then the fetched head;
- its message is `Merge main <full head sha> at publish`, with the default branch's name;
- its author and committer are `Orbit <orbit@users.noreply.github.com>`.

The push sends that merge instead of the checked commit, so the branch's workflows equal the default branch's. `merged_default` names the head. The publication record keeps `commit`, the checked commit. A copy whose workflows already equal the default branch's pushes the checked commit itself, as before.

The handoff check merges the head of its time first, so this merge stays only for a default branch that moved after the check.

###### A republish

A merge at publish leaves the task branch at a commit that the copy does not hold, so a plain push of a newer checked commit would fail with `tasks.not_fast_forward`. The base read therefore also fetches the task branch's tip from origin, when it exists.

When that tip is not an ancestor of the checked commit, it must be an earlier publish merge. Its message names the default branch and its second parent. Its first parent is an ancestor of the checked commit, and its second parent is an ancestor of the fetched head.

Then the worker always merges, also when no workflow differs: the parents are the checked commit, the fetched head and that tip, and the tree is the merge of the first two. The push is a fast-forward. When the tip's first two parents already are the checked commit and the fetched head, it is this publish's merge: the push sends it again and pushes nothing.

Any other tip that is not an ancestor fails at the push with `tasks.not_fast_forward`, as before.

The result stays `{ branch, commit, pull_request_number, pull_request_url }`, with null PR fields for a push alone, and adds the six base fields for a copy: `base_commit`, `default_commit`, `behind_by`, `overlapping_paths`, `overlapping_more` and `merged_default`. Cancellation prevents a mutation that has not started. A started Git push or GitHub mutation finishes its bounded request before cancellation is reported; cancellation cannot undo an existing branch or PR. Swamp owns cancellation and locking. No Gateway push job, mirror transfer, lease or polling step runs.

###### The merge round

A copy publish that fails with `tasks.publish_conflict` starts a merge round. Orbit syncs the copy and runs one implementer turn with a fixed merge prompt on the subtask of the failed publish's check. It then runs a handoff check and one narrow reviewer turn on that check, and publishes it the same way as the failed publish. A turn that asks or fails, a failed sync or check, a reviewer that does not approve, or a second conflict in the same round stops the round and asks a person (group assistance, with the reason).

The Gateway scheduler runs `task:merge-round` every minute while the Tasks extension is on, beside `task:pr-when-done`. It reads Orbit's own records and admits each step through the same action as a person's command, with the caller `gateway:merge-round`. Swamp runs every step, as it runs any Operation. The driver does nothing on a `tasks.publish_conflict`.

A tick takes each open group whose workspace is a task copy, with or without its pull request, when:

- the group's newest `tasks.publish`, P, failed with `tasks.publish_conflict`;
- the group has no assistance request;
- no Operation of the group runs.

From the group's Operations after P, the tick starts at most one step per group, and only while no copy turn of the group may still run:

| After P | The tick starts |
| --- | --- |
| Nothing | [`task:sync`](#bring-main-into-a-copy) |
| The sync succeeded | An implementer turn on the subtask of P's check, which continues its thread. After a done-check publish, a `completed` subtask is [reopened](#reopen-the-last-subtask) first |
| The turn ended `done` | A handoff check of that subtask: C1 |
| C1 passed | A reviewer turn on C1, which continues the reviewer thread |
| The reviewer turn ended `done` and sent its [pull request text](#the-reviewers-pull-request-text) on C1 | The publish of C1, as P was published |

The turn's prompt names the conflicting paths from P's result and the default head that the sync brought in. It asks the agent to:

- merge `origin/<default branch>` into the task branch;
- resolve each conflict at its cause, for example regenerate a generated file with its own tool, such as `bin/ci-units collect --file`;
- keep the intent of both sides;
- run only the tests that cover the conflicting paths, and commit the merge;
- ask when a conflict needs a decision.

The reviewer turn reviews only the merge's own change. For each merge commit in `git log --merges <C0 commit>..<C1 commit>`, where C0 is P's check, it reads `git show --remerge-diff <merge>`: the difference between the reviewed head merged with main and the resolved tree. It judges that against the original brief and nothing else of the branch. Its approval is the text it sends, as for every reviewer turn: it keeps the earlier text, or updates it when the merge changes what the text says. A reviewer that does not approve sends nothing and lists the defects.

P was a done-check publish when its check was the group's done check as Orbit admitted it. Every copy publish of the done check records that check as `done_check` in its Operation input, once its admission read it as the done check under the group's row lock. A release, the automatic publish and a named done check record it, with the reviewer's text, a person's own text or none; the run's intent leaves the field out.

Only such a P reopens its subtask, and then the tick completes it again and publishes C1 as the done check. The normal [done rule](#when-the-work-is-done) holds: the reviewer turn reviewed exactly C1, and the subtask completed after it. The tick republishes C1 as the release does: with the reviewer's newest text, or as a push only when a group with its pull request has none.

Any other P named a check that was not the done check, with or without a person's text. The round runs on that check's subtask as it is, and the tick pushes C1 with `--check` only. When that subtask is completed, the round does not reopen it: it stops and asks. The round adds no table and no column. Each step is keyed `merge-round:<group>:<P>:<step>`, with the steps `sync`, `turn`, `check`, `review` and `publish`, so a repeat tick starts nothing twice.

The round stops, and the tick requests assistance on the group once, when any of these happens:

- the sync fails;
- the turn fails or asks;
- the check fails;
- the reviewer turn fails, asks or sends no text;
- the subtask is completed, and P was no done-check publish;
- Orbit refuses to start a step for any reason but a busy group;
- a publish of a check that the round made fails with `tasks.publish_conflict` again.

The request is a failure. Its reason names P, the step, the Operation and the conflicting paths. The round asks once for P: Activity keeps the request with its reason. A person who resolves the request without starting an Operation ends the round, and the tick does not ask again for that P.

The round yields to a person. When the group's newest Operation is neither P nor a step of this round, a person or a driver acted, and the round starts nothing more.

Orbit never starts a second round for a group by itself. A conflict of the round's own check is no new P. A later conflict of a check that no round made starts a new round, such as a person's publish after their own new check.

C1 is published once. The round publishes it only while no publish of C1 exists. The `task:pr-when-done` tick never publishes it too:

- an open publish makes the group busy for the other tick;
- a group with its pull request is outside the automatic publish;
- a publish of C1 by the automatic publish is newer than the round's steps, so the round yields.

### Task lifecycle

A group starts in `backlog` or `todo`. Moving it to `todo` requires at least one subtask and a deliverable on every subtask. Provision reserves capacity, then records `running` once the workspace is ready. Swamp chooses subsequent status updates: `running`, `reviewing`, `settling` and `waiting_for_review`. Explicit completion, cancellation or failed provisioning records an ended status. Terminal history and assistance remain readable.

One group uses one task copy and one branch. Subtasks share the workspace in order. The keyed operations enforce workspace ownership and persist results; they never select the next subtask.

Maintenance reads the complete receipt from the native `shape.result_json` producer in its terminal history read. Its parent and each path end with native assertions; no reporting script forwards the receipt. Maintenance admits its next operation only after the terminal projection commits. A rolled-back projection starts no continuation. This keeps GitHub token requests outside the SQLite write transaction; see [write-lock diagnostics](/reference/gateway-recovery#gateway-request-logs).

A declared native Operation with the same `task_group` target and a nonterminal status makes the group busy. A new key returns `operation.target_busy`; the original key still replays its Operation. This lookup also finds the group's operation for cancellation. It uses the recorded target, kind and status, without requiring a writer stamp or `lock_key`. Unrelated targets do not block admission. A timed-out `tasks.cancel` or `tasks.maintenance_finish` Operation still holds `task_group:<id>`: another cleanup admission returns `operation.target_busy` until [native settlement](/reference/operations#native-run-budgets) releases that hold. Other terminal Operations do not block admission.

## Tasks and subtasks

List, show, and `task:question:list` accept any authorized peer. Update and the subtask create, update, and destroy operations are served by the Node that holds the task's workspace, or by the Gateway for a task without one. The other operations require Gateway access.

| Operation | Route | Access |
| --- | --- | --- |
| `task:create` | `POST /api/v1/tasks` | Gateway |
| `task:update` | `PATCH /api/v1/tasks/{task}` | Workspace Node |
| `task:list` | `GET /api/v1/tasks` | Any peer |
| `task:show` | `GET /api/v1/tasks/{task}` | Any peer |
| `task:start` | `POST /api/v1/tasks/{task}/start` | Gateway |
| `task:check` | `POST /api/v1/tasks/{task}/check` | Gateway |
| `task:turn` | `POST /api/v1/tasks/{task}/turn` | Gateway |
| `task:complete` | `POST /api/v1/tasks/{task}/complete` | Gateway |
| `task:cancel` | `POST /api/v1/tasks/{task}/cancel` | Gateway |
| `task:destroy` | `DELETE /api/v1/tasks/{task}` | Workspace Node |
| `task:check:cancel` | `POST /api/v1/tasks/{task}/check/cancel` | Gateway |
| `task:comment:create` | `POST /api/v1/tasks/{task}/comments` | Gateway |
| `task:comment:list` | `GET /api/v1/tasks/{task}/comments` | Gateway |
| `task:question:list` | `GET /api/v1/task-questions` | Any peer |
| `task:question:close` | `POST /api/v1/task-questions/{question}/close` | Gateway |
| `task:agent:list` | `GET /api/v1/tasks/{task}/agents` | Gateway |
| `task:egress` | `GET /api/v1/tasks/{task}/egress` | Gateway |
| `task:agent:show` | `GET /api/v1/tasks/{task}/agents/{session}` | Gateway |

Each MCP tool name is the operation name with hyphens, such as `task-create`. The paths keep the `tasks` segment. `{task}` selects the existing ID of either a parent or a child. Check, turn and check cancellation require a child. Start, publish, complete and agent reads require a parent. Destroy requires a child whose parent is in Backlog. Comments select either level.

Parent creation requires `project_id`, `title` (at most 160 characters), and `brief` (at most 8,000 characters). Child creation uses `parent_id` instead of `project_id` and inherits the Project; a contradictory Project is refused. The Project must read its repository through the GitHub App. A Project with [`source_access: gh_cli`](/reference/projects#source-access) cannot start a task, because Orbit publishes only through the App.

Parent creation accepts an ordered `subtasks` array of at most 50 `{title, brief, deliverables}` objects and a `status` of `backlog` or `todo`. The status defaults to `backlog`. Creating in Todo changes board state only; `task:start` creates the parent's task copy. Parent creation accepts only `orchestration: external`, which is also the default. The API, PHP SDK, CLI option `task:create --orchestration`, and MCP expose this field. Update cannot change it.

Create also accepts `review`, `manual` (default) or `automatic`, the mode of the gate of the group's pull request; update cannot change it, and show returns it. The strict boolean `current_recipe` (CLI `--current-recipe`, default `false`) remains a stored create-time field; it selects no VM image and changes no task-copy start behavior. Update cannot change it. [List tasks](#list-tasks) pages the groups. Show returns the task and its subtasks in position order.

Update changes a task's `title`, `brief`, or `status`. Title and brief change only in `backlog`. Status updates start no work.

For an external group, `task:update` also accepts `status` moves among `running`, `reviewing`, `settling`, and `waiting_for_review`. It still moves between `backlog` and `todo`, with the same deliverable requirements, but starts no work. A status update cannot enter `reserved`, leave `reserved`, cross between preparation and active work, or end the group. Only provision moves `todo` to `reserved`. Use `task:cancel` or `task:complete` to end a group. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing, including on an ended group. These updates need no idempotency key.

A task moves to `todo`, by create or update, only when it has subtasks and every subtask has at least one deliverable.

### List tasks

`GET /api/v1/tasks` returns at most `limit` groups, newest first. `limit` is 1 to 100; the default is 20. `meta.next_cursor` names the next page, or is null on the last page. Send it back as `cursor` with the same filters to read the next page. A cursor is an opaque string; one in another form answers `422` with `validation.failed`. This is the paging of [`operation:list`](/reference/operations#page-the-list). A page is not a snapshot: a group created after the first page appears on no later page. The list orders by id alone, so a page boundary never moves when a group changes status.

`project_id` and `status` filter the groups before the page is cut, so a filtered list pages the same way. With `parent_id`, the list returns that group's subtasks in position order, whole and unpaged; `limit` and `cursor` are refused with it. With `parent_id`, `status` takes a subtask status, and a `project_id` other than the group's Project lists nothing.

A list row has every field of `task:show` except the group's [`publication`](#publication-state) and, on each subtask, `completion_summary`, `fixup_problem` and the latest check's `output`. A summary is stored in full and a check keeps 16 KiB of output, so a page of full rows grows without bound. Publication reads the group's publish Operations and done check, several reads per group. Read one group with `task:show` to get them.

The list reads each row's columns, subtasks, latest checks, reviewed commits, workspace route and runtime, task group mode, thread tokens and activity in a fixed number of queries per page, whatever the number of groups. `compact` still applies on top of a list row.

### Change subtasks

Subtask create appends a `todo` subtask at the next position. It works while the group has not ended. Outside `backlog`, the new subtask needs at least one deliverable.

Swamp selects the next subtask and records its status through the API.

Subtask update changes `title`, `brief`, `position`, or `deliverables`. A `deliverables` value replaces the whole list. `reason` goes only with a [correction after a failed check](#correct-the-deliverables-of-a-started-subtask). Positions stay gapless from 1.

| Task status | What can change |
| --- | --- |
| `backlog` | Every field of every subtask. Subtask destroy deletes a subtask and closes the gap. |
| `todo`, `running`, `reviewing`, `settling` | Every field of a `todo` subtask. Its deliverables cannot become empty. Its position moves only among the `todo` subtasks after the last started or ended subtask. |
| `reserved` | Nothing. Subtask create still appends |
| `completed`, `failed`, `cancelled` | Nothing |

For an external group, subtask update also accepts `status`. A subtask moves from `todo` to `running`, between `running` and `reviewing`, and from either active status to `completed` or `failed`. `failed` is final. `completed` is final too, except for the [reopen of the last subtask in a task copy](#reopen-the-last-subtask). Only `task:cancel` sets `cancelled`. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing.

An ended group permits no subtask status move. Completing a subtask clears its assistance and reconciles the group request as resolution does. A status update starts no agent or check, advances no other subtask, and does not settle the group. The API, PHP SDK, CLI `--status` option, and MCP expose both status inputs.

#### Subtask status in a task copy

In a group whose workspace is a task copy, the admission of a [copy turn](/cli/task/turn#in-a-task-copy) of either role moves the current subtask from `todo` to `running`, under the group's row lock and in the turn's own transaction. A refused turn moves nothing. No turn, check or reviewer pass moves the subtask further. The caller completes it with `task:update <subtask> --status=completed` after the reviewer's turn on the done check ended; that move is the approval that [the done rule](#when-the-work-is-done) reads. Since the turn started it, the subtask then keeps its title, brief, position and deliverables, as the next paragraph says.

A subtask that has started keeps its title, brief, position, and deliverables. A deliverables update on it returns `tasks.deliverables_locked` and leaves the stored list as it is, except for the corrections that the next section lists.

#### Correct the deliverables of a started subtask

One Gateway rule decides whether a started subtask's deliverables may change, and by which correction. It takes the first that applies:

1. The one correction after a failed `invalid_deliverable` step. The subtask asks for assistance. A subtask gets it once, and it takes no reason.
2. The [path gate fix](#deliverables). The deliverable path gate holds the subtask before its implementer starts.
3. A correction after a failed check, in a group whose workspace is a task copy.

A wrong deliverable, such as a command that the copy cannot run or a path that a renumber moved, should not force a code change or a new subtask. In a group whose workspace is a task copy, the caller replaces the list with a reason:

```bash
orbit task:update 5354 --deliverables=deliverables.json --reason="The tools tests need discover -s tools."
```

Orbit accepts this correction when all of these hold:

- The group has not ended.
- The subtask is the current subtask, the first open one, and it has started.
- Its newest handoff check failed.
- No correction answered that check yet, of either kind.

The reason is required here, 1 to 500 characters; without it the update returns HTTP 422 `validation.failed` on `reason`. The list passes the same validation as at create: base paths, at most five, and the fields of each type. One correction answers one failed check: a second correction before a new check returns `tasks.deliverables_locked`. A refused correction stores nothing.

Task activity records `deliverables corrected` with the old list, the new list, the reason and the failed check's id. `task:show` shows the new list, and the next handoff check runs it. Nothing else changes: the [done rule](#when-the-work-is-done) reads the current list, and a reviewer still reviews the next check.

A `reason` without `deliverables`, on a group update, on a `todo` subtask, or with a correction that takes no reason returns HTTP 422 `tasks.reason_unexpected` and changes nothing. A `reason` with `deliverables` for a started subtask that no correction admits returns `tasks.deliverables_locked`, as without one.

#### Reopen the last subtask

In an open group whose workspace is a task copy, the caller can reopen the last subtask with `task:update <subtask> --status=running`, so that a fix after the review or after the pull request runs as more turns in the same subtask and threads. The move from `completed` back to `running` is allowed only when all of these hold:

- The subtask is the group's last subtask by position that is not `cancelled`.
- Every other subtask of the group has ended.
- No copy turn of the group runs. Otherwise the move returns HTTP 409 `tasks.copy_turn_unended`, as a completion does.

The move takes the group's row lock, as a completion does. Any other move from `completed` returns `tasks.status_move_refused`. A `failed` subtask never reopens. Task activity records the reopen.

The reopen adds no state. The existing rules decide what it means:

- The subtask is current again, so implementer and reviewer turns run on it and continue their threads.
- Its deliverables stay locked, as for every started subtask, except for the [corrections](#correct-the-deliverables-of-a-started-subtask).
- The open subtask keeps the group from being [done](#when-the-work-is-done). A `pr_when_done` group does not publish. A `review_before_pr` group leaves `waiting_for_release`.
- A new handoff check, a reviewer turn on it and a new completion make the group done again, on the new check.
- A completion without a new check makes the group done again on its earlier done check, as before the reopen. That check holds no commit made after it.
- A group that has a pull request keeps it. Its next publish updates the same pull request.

Appending a subtask records new work. Swamp decides when to resume the group.

### Cancel a subtask

For an external group, `task:cancel` cancels a `todo`, `running`, or `reviewing` subtask in an open group. It uses the existing interrupt path to stop the acting thread and any running check before marking active work `cancelled`. A failed interrupt leaves the status unchanged and returns HTTP 502 `tasks.subtask_interrupt_failed`. Cancellation starts no next subtask and does not settle or change the group's status.

### Errors

The task and subtask operations return these errors.

| Error | HTTP | When |
| --- | --- | --- |
| `tasks.orchestration_unsupported` | 422 | A subtask would declare a topology |
| `tasks.status_move_refused` | 422 | An external group or subtask status update attempts a move outside the allowed transitions |
| `tasks.no_subtasks` | 422 | A move to `todo` for a task without subtasks |
| `tasks.subtask_deliverables_missing` | 422 | A move to `todo` while a subtask has no deliverables, and then `details` names each subtask. Also a subtask without deliverables outside `backlog` |
| `tasks.group_closed` | 409 | Subtask create in an ended task |
| `tasks.not_in_backlog` | 409 | A title or brief update outside `backlog`, or a subtask update or destroy that the table above does not permit |
| `tasks.deliverables_locked` | 409 | A deliverables update on a subtask that has started, when no [correction](#correct-the-deliverables-of-a-started-subtask) applies |
| `tasks.reason_unexpected` | 422 | A `reason` on an update that is not a [correction after a failed check](#correct-the-deliverables-of-a-started-subtask) |
| `tasks.deliverable_base_unavailable` | 422 | The repository base or its complete file tree could not be read for path validation |
| `tasks.already_claimed` | 409 | An attempted move after workspace reservation |
| `tasks.subtask_not_running` | 409 | A subtask cancel that the rules above do not permit |
| `tasks.subtask_interrupt_failed` | 502 | Orbit could not stop the implementer or the check |
| `tasks.agent_transcript_unavailable` | 409 | A transcript request for a stored `t3` task thread. The row stays, and no stream opens |
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

Orbit reads the base tree from the Project repository, also when the group has a task copy. When the repository lacks the commit, the request fails with `tasks.deliverable_base_unavailable`.

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

### A Pest command gets CI's time limit

A task copy is a shared, loaded host, as CI's runners are. So a `command` deliverable that runs Pest gets the per-test time limit that CI passes to Pest, not the local limit in the project's `phpunit.xml`. The Pest calls are `php artisan test`, `vendor/bin/pest`, `bin/pest-plain … vendor/bin/pest` and `composer test`.

The Project's check command supplies the limit, and the Project opts in through that command. The check asks it only when both hold:

- The check command is one executable path, such as `bin/task-check`.
- That file is a regular file in the workspace and contains the text `--ci-time-limit`.

A check command that does not declare the flag is never run for the limit. Its Project's deliverables run exactly as given.

The lookup works like this:

- The check runs `<check command> --ci-time-limit <directory>`. The directory is where the Pest call runs, relative to the checkout.
- The check command prints exactly one line: `ci-time-limit: <N>`, or `ci-time-limit: none` when CI passes no limit.
- `bin/task-check` reads the limit from `.github/workflows/ci.yml`, as its own Pest part does. For `apps/gateway` that is 5 seconds.
- A project whose CI job passes no limit keeps its `phpunit.xml` limit.

The check appends `--default-time-limit=<N>` to the last simple command of the deliverable. `composer test` gets `--` first, so Composer passes the option on to Pest. The deliverable's output starts with the line `Pest time limit: --default-time-limit=<N>, CI's limit for <directory>`, or with a line that says CI passes none. The start-commit run of a `fails_on_base` command uses the same limit, read from the checked workspace.

These commands run unchanged:

- A command that already passes `--default-time-limit`.
- A command whose Pest call is not its last simple command, such as one with a pipe, a `;` or a redirect.
- Every command of a Project whose check command is not one executable path, or does not contain `--ci-time-limit`.

A check command that declares the flag and then breaks the contract fails the deliverable with exit 2. It breaks it when it fails or prints anything but that one line. It fails the same way when its Pest call runs outside the checkout. Its output then says `Task check: <check command> --ci-time-limit gave no limit`.

### Prove a command fails on the start commit

A `command` deliverable with `fails_on_base: true` proves that the command fails before the fix. Omitted and `false` are the same: Orbit runs the command once, on the working tree. With `true`, Orbit runs it twice at handoff. The deliverable passes only when the base command exits nonzero and the working-tree command exits 0. A failing base command is the expected evidence.

| Run | Code under test | Passes when |
| --- | --- | --- |
| Base | The start commit, or its fallback base, plus the files in `paths` from the working tree | The command exits nonzero |
| Working tree | The implementer's tree | The command exits 0 |

The [workspace check phase](/reference/task-instance-workspace#check-phases) extracts the start tree once into guest scratch and makes a private copy for each base command. It copies only installed `vendor` and `node_modules` directories in that command's dependency closure, then each file in `paths`, including an uncommitted or untracked file. It runs the command there with `bash -lc`. Reflinks, when supported, avoid copying file data while keeping writes private; ordinary copies provide the same isolation elsewhere. No hard links or Git worktrees are used.

Each command starts with the base source and only its own overlays, regardless of earlier commands' writes. The check removes scratch when it ends, and the next check removes scratch that a killed run left behind. The Project check, base replay and deliverables run serially.

A command that needs another installed tree, such as a Python virtualenv or a Rust `target` directory, can fail on that base tree only because the tree is missing. That nonzero exit satisfies `fails_on_base` and is not evidence that the command reproduced a bug. This copy is retained. A follow-up has to widen it or stop counting a missing dependency as reproduction evidence.

The base run stops after 600 seconds, and a timed-out run counts as failing on the start commit. Exit 126 or 127 means the command did not run, so the deliverable fails. When the base command exits 0, the deliverable fails because the command does not reproduce the failure. The stored exit code and output follow the [shared output policy](#check-output). It records `base_started`, `base_exit_code`, and `base_output`. A timed-out run also records `base_timed_out` and `base_timeout_seconds`. The engine does not read test names or runner output. The Project's command does that. Show and the turn file include `fails_on_base`. An omitted input is stored as `false`.

Task create, subtask create, and subtask update accept `fails_on_base` and `paths` only on a `command` deliverable. `fails_on_base` is the JSON boolean `true` or `false`, and `paths` is a list of strings. Any other value is HTTP 422 `validation.failed`. The error names that deliverable's `id`.

The Orbit Project's [task policy skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) requires repro-first bug work where a command can reproduce it. A bug task's first code subtask carries that command. When a bug cannot be reproduced by a command, the brief says so and that subtask adds a `review` deliverable for the manual check.

### Confirm deliverables

The turn prompt names the subtask's deliverables. The agent confirms each one in the `deliverables` map of its [turn receipt](#turn-receipt), `{"ID": "evidence"}`, where the evidence says where or how the deliverable is met.

| Turn | Needs |
| --- | --- |
| Implementer `ready_for_review` | A confirmation for every deliverable |
| Reviewer `approved` | A confirmation for every `review` deliverable. Other IDs are allowed |

The turn checks only that `deliverables` maps each ID to text. It does not compare the IDs with the subtask's deliverables. The Gateway stores the confirmations on the receipt's comment.

### Verify deliverables

The keyed check operation verifies file changes and command evidence against the recorded workspace base. It records invalid deliverables as check evidence. A contract correction can update its stored deliverables where the API allows it. It never resumes an agent automatically. Swamp decides when to request another turn or check.

## Prepare a task in Backlog

Prepare a task in Backlog before any agent runs. A Backlog task has no workspace.

1. Create the task. It starts in `backlog`.
2. Optionally, create the branch `task-{id}` from the Project default branch, commit the feature's contract to it, and push it.
3. Add the ordered subtasks with their deliverables, following the Project's task policy.
4. Move the task to `todo` with `task:update`.

The Gateway does not check the branch contents. When `origin/task-{id}` exists, the workspace checks it out. Otherwise the workspace starts a new branch from the default branch. The implementer and reviewer prompts are project-neutral. They do not add an ADR contract sentence or Orbit-specific policy such as Incus or lease instructions. The implementer prompt says that Orbit runs the configured Project check at handoff. It names the command only when one is configured.

When the workspace starting commit is 40 or 64 hexadecimal characters, both prompts add `The task started at <sha>.` and `git diff --stat <sha>..HEAD`. The review packet places those lines after its stat and diff commands. The lines name no Project, branch, or policy. Any other value is left out.

## Outer loop

The Gateway files a Backlog task when the same production problem keeps returning. An operator edits that task and moves it to Todo. Swamp does not claim it before that move.

The loop reads Doctor, Activity, the Gateway log, and assistance reasons. A release command also pushes its [release alerts](/reference/gateway-recovery#release-alerts) into the loop. It does not read the `schedules` table. It does not wait for an external alert manager.

Doctor incidents resolve only after a new successful observation of the resource or proof that its persisted resource is gone. A `rollout_halted` incident closes after a later fleet rollout visit converges its Node. A skipped check, an unreachable Node, and a cleared occurrence episode do not prove recovery. Only untouched, eligible generated Backlog groups close automatically; groups that an operator has changed stay open.

### Fingerprints

Each signal updates one row in `problem_fingerprints`. The fingerprint is unique.

| Column | Meaning |
| --- | --- |
| `fingerprint` | Stable key, at most 255 characters |
| `source` | `doctor`, `activity`, `log`, `assist`, `release`, or `swamp` |
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

Each row remembers the last 288 windows it counted, in arrival order. A late signal for a window that has already been evicted is counted again and increments `occurrences`.

Readiness counts these occurrences and their times. It does not count log lines. Many log lines in one block are one occurrence. The sample keeps the newest 20.

The database migration converts remaining pre-window evidence once; collectors and filing use only window evidence. It discards old log and Activity occurrence history because those timestamps came from the collector rather than the signal, and clears their first and last seen times. Other sources retain valid timestamps grouped into 5-minute windows. Linked groups, mutes, and unrelated evidence stay unchanged. Rollback keeps the converted evidence; only the pre-release database snapshot can restore discarded history.

### When a fingerprint is ready

The tests below use only the current episode. That episode is the occurrence history stored on the row: the time and the signal count of each 5-minute window.

Doctor rows are never eligible for filing, including existing rows and rows recorded through the observation API. Filing tasks from Doctor findings is a later product decision. The filer leaves any already linked task group unchanged.

A `swamp` problem from the [Swamp datastore watch](/reference/operations#swamp-datastore-watch) is ready after two fires at least 10 minutes apart. The first later fire that does not report it resolves it.

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

`problems:file` runs every hour. It files at most three new tasks per day, using the Gateway application timezone. It takes release alerts first, then the highest `occurrences`. Equal counts use the earlier `first_seen`, then the fingerprint string. Each run loads at most 50 ready rows that are not muted, not tied to an open task, and not skipped by [Suppression](#suppression). It skips a fingerprint whose last occurrence (`last_seen`) is older than 7 days and leaves it unmuted and unresolved, so a new occurrence makes it eligible again. Release alerts are exempt: each is pushed once per incident and stays open until its recovery evidence resolves it.

The cap counts fingerprint rows whose `filed_at` falls on today's date in that timezone. An operator edit to the brief does not change the count. A missing Orbit Project files nothing.

The filer inserts the task and its subtasks, then updates the fingerprint, in one database transaction. The update sets `task_group_id` and `filed_at`, clears `muted_until`, and resets the episode as [Suppression](#suppression) describes. A crash rolls every one of those writes back, so the next run does not file a duplicate.

Each task belongs to the Project whose slug is `orbit`, and the task starts in `backlog`. The first line of the brief is `Filed by the outer loop.`

The rest of the brief is eight sections, in this order: Symptom, Fingerprint, First seen, Last seen, Count, Occurrences, Evidence, and Suspected entry point. Times use UTC. Symptom is the redacted Activity error message, the redacted log message, the assistance reason before normalization, or the release alert's redacted summary.

Count is `occurrences`, the number of windows. Occurrences lists one line per window, oldest first, at most the newest 20. Each line is the first signal's time, formatted `YYYY-MM-DD HH:MM:SS UTC`, a space, and the signal count in that window, such as `2026-10-01 12:00:01 UTC 129`. Evidence includes a `Request ids:` line when the sample has any, and omits that line when none are known. The suspected entry point is its own section.

| Section | Bound |
| --- | --- |
| Symptom | 1,000 characters, then `...` |
| Fingerprint | 255 characters |
| First seen, Last seen, Count | One line each |
| Occurrences | The newest 20 windows, one line each |
| Evidence | The sample caps. Expected and observed are cut at 200 characters |
| Suspected entry point | 500 characters, then `...` |

The finished brief is at most 8,000 characters. The Evidence and Occurrences headings are always present. When either section has no lines, it says `none`. If the brief is still longer, the filer drops Evidence lines until it fits, and both headings stay. The occurrence lines stay. `task:create` refuses a longer brief with `validation.failed`. If create still fails, the filer skips that row and leaves `filed_at` unset. The row does not count toward the cap. The filer continues with the next row. Each subtask brief states that subtask's goal. The symptom appears once, in the group brief.

| Source | Title | Suspected entry point |
| --- | --- | --- |
| Activity | `{command} failed with {error_code}` | The command name |
| Log | `{exception class} at {frame}` | The app frame, or the stored `source_path` when the key is shortened |
| Assistance | The normalized reason | The open task ids in the sample |
| Release alert | Alert kind, then `for {resource}, attempt {attempt}` | Repository, resource, attempt, and fault |

A title longer than 160 characters is cut to 157 characters plus `...`.

The task has two subtasks. The docs subtask is first. Its deliverable id is `docs`, its type is `review`, and the description says the owning page matches the fix, or that no page changes. The operator can replace that deliverable while the task is in Backlog.

The second subtask reproduces the failure and then fixes it. Its deliverable id is `test` and its type is `review`. There is no `test` deliverable type. The description is `Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.` The deliverable has no `command`, `directory`, `fails_on_base`, or `paths` field. The filer never writes a whole-suite command, including `vendor/bin/pest` with no test file. The operator replaces that review with a `command` deliverable for one scoped test, sets `fails_on_base` to true, and names the test files in `paths`, before moving the task to Todo. [Prepare a task in Backlog](#prepare-a-task-in-backlog) is that edit.

### Collection

The Gateway collects Doctor findings for every Node every five minutes from stored agent facts, even while Tasks is disabled. It makes no network call. Findings, recovery, and the last successful full collection time commit in one transaction. The existing `problem_collector_state.observation` record holds that time. [`gateway:status`](/cli/gateway/status) shows it with grouped open findings.

The observation API also runs Doctor on request while Tasks is enabled, then collects the Gateway log, Activity, and assistance signals. Those other signals have no schedule. `problems:file` runs hourly while Tasks is enabled. See [Scheduled work](/reference/gateway-queue#scheduled-work).

| Source | Bound per run |
| --- | --- |
| Doctor | Every Node and family, using stored agent facts |
| Activity | 500 rows with `id` above the stored cursor |
| Log | 1 MiB, stopping at the end of a whole record |
| Assistance | 200 open rows |

Doctor runs through `RunDoctorAction` for every Node and every family. A peer access grant does not drop Nodes from that fleet. The collector records only `drift` and `unverifiable` issues, and skips `informational` issues. One `problem_collector_state` row stores the Activity cursor, the log path, the file inode, and the byte offset. Scheduled Doctor collection leaves those cursors and assistance signals unchanged.

The first collector run sets the Activity cursor to the current maximum id, and the log offset to the end of the current file. It does not count those past rows. The log file is `storage/logs/laravel.log` when that path is a regular file. Otherwise it is the newest `storage/logs/laravel-*.log`. The collector finishes unread bytes in a rotated file before it switches. The Gateway reads its own log right after Doctor's comparison, in the same request; no Swamp step reads it. A single record longer than 8 MiB is skipped, so the cursor never stalls on it.

Each source commits its fingerprint updates and its cursor in one database transaction. For Activity that cursor is the last id. For the log it is the path, inode, and offset. For assistance it is the open task ids. A crash rolls that source back, so the same rows are not counted twice. The collector applies [Suppression](#suppression) and the [occurrence window](#occurrence-windows) before it increments `occurrences`.

A failure in one source does not skip the others. The dispatch command exits successfully once queued; a dispatch failure fails the command. Worker failures appear in Horizon and the Gateway log. An interrupted resource check does not discard another resource's findings.

## Execution ownership

Swamp drives each group through the API and MCP operations documented above. `task:tick` and tick orchestration are removed. Creating a todo group records intent; only Swamp starts its work.

The Gateway retains task state, operation recovery, provider jobs and transcripts. A keyed operation performs only the requested primitive and records its result. Operation workers can resume an unfinished primitive; they do not choose the next task phase.

The group execution lock waits at most five seconds for every waiting caller, including HTTP actions and operation workers. A caller that does not wait refuses immediately. A busy lock retains recorded state and leaves the caller to retry.

A resolution clears group assistance only when its kind, question, and reason match the resolved subtask request and no sibling still needs assistance. A different or newer request stays open.

## Credentials in task copies

Container model calls use a [Node forwarder with no key](/reference/ai-router#container-model-forwarder) and the model guard's placeholder. The real router key stays outside the container. This is not a general secret boundary: `agent` has root in its container, which must hold only development-scoped secrets. Publication runs on a trusted worker and gives the copy no GitHub token ([publish an external group](#publish-an-external-group)).

## Task workspace

Every subtask shares the group's task copy, with name and branch `task-{id}` and checkout at `/srv/workspace`. The copy's [workspace phases](/reference/task-instance-workspace) own checks and publication exports. Proofs use its [nested fleet](/reference/development-containers#nested-fleet), not a separate task VM.

### Task copy

A new task requires an active container default with a ready seed, including for a Project with [`nested_fleet`](/reference/projects#nested-fleet-setting) enabled. Its workspace is a task copy: a clone named and branched `task-{id}`, which the task owns from its create. There is no VM fallback. [`task:start`](/cli/task/start#a-task-copy) makes it and [`task:cancel`](/cli/task/cancel) and [`task:complete`](/cli/task/complete) destroy it. A cancel refuses a copy that holds work origin lacks unless the caller discards it, and its result records what the copy held. A check runs in it through the [check phases](/reference/task-instance-workspace#check-phases).

The copy clones the seed of the Node it is placed on (`TaskStart::seed`). Today that is always the default's Node, so it clones the default's seed. On a CI Node it would clone that Node's [seed holder](/reference/development-containers#the-seed-on-each-ci-node), and a Node whose seed is not the default's current seed refuses with `tasks.copy_source_unavailable`.

The copy's agents follow the Project's own [agent instructions](/reference/development-containers#agent-instructions) from its Documents. `task:start` reads them before its admission transaction, so a copy keeps the version of its start.

A handoff check in a copy makes no commit for a clean head that an earlier check recorded, so checking a published head again leaves nothing that blocks the cancel.

The copy [starts clean](/reference/development-containers#a-task-copy-starts-clean): its tree is its start commit, the seed's commit, plus what Git ignores, so the default's modified and untracked files never reach a check or a pull request. The start's result names what the clean took as `workspace_cleaned`.

[`task:turn`](/cli/task/turn#a-task-that-runs-in-a-copy) runs an implementer or reviewer turn in it as `tasks.copy_turn`, with T3's own CLI as [Swamp drives T3](/reference/development-containers#swamp-drives-t3) describes. Each role keeps one ongoing T3 thread, and a turn without `--thread` continues it: the implementer's thread of the current subtask, recorded on the subtask, or the reviewer's thread, recorded on the group. The first turn of a role, or `--thread=new`, starts the thread with the role's copy model and effort.

A reviewer turn works on the checked candidate, detached, and Orbit checks the task branch out again after it, at the commit it had. A checkout left unclean, off that branch or at another commit holds the group, whatever the turn's run did.

A turn has a bound of 3,600 seconds. A turn that its bound cuts stops its own run in the copy, and then holds nothing, though it still fails with `tasks.turn_timeout`.

Every earlier turn that is over in Orbit but may still run in the copy, because it was cut, cancelled or lost, is settled by the next turn. That turn's phase first stops the earlier run when T3 still shows it active. It settles the earlier turn once T3 shows its run over and a reviewer's checkout is clean on the task branch. A stop that fails leaves the earlier turn unsettled, and the next turn fails with `tasks.copy_turn_unended`. The work in the copy stays. So `operation:cancel <turn>` and then a new turn stop a running turn's run.

A question ends a turn: Orbit stores it and stops the run, and the role's next turn, or a message to the thread from a T3 client, answers it. The thread and its transcript stay on the Gateway after the copy is gone.

Each turn's prompt gives the agent its clock: the time Orbit admitted the turn and that time plus the one-hour bound, in UTC, with the ask to commit before the end. The turn's result names `agent_id`, the agent thread that [`task:agent:show`](/cli/task/agent-show) reads, and `reply`, the transcript's last entry that the agent wrote, bounded at 16,384 characters with a marker that says how much was cut. The transcript is the whole thread's, so a turn in which the agent wrote nothing shows an earlier turn's reply.

A driver reads a turn from its result alone; the whole transcript stays in the archive ([result fields](/cli/task/turn#a-task-that-runs-in-a-copy)).

[`task:sync`](/cli/task/sync#a-task-copy) brings the Project's newest default branch into it, as [bring main into a copy](#bring-main-into-a-copy) describes. [`task:publish`](#publish-an-external-group) pushes its checked commit and opens the pull request from 209. [`orbit proof --group`](/cli/proof/proof) runs one command's proof cases in a nested-fleet copy, as `agent`, against the copy's own nested fleet.

[`proof:run`](/cli/proof/run#the-run) starts its own copies, one per shard and one in each free CI place. Proof groups own them; each group has one subtask row and no agent thread. Proof copies take CI places, not the copy places of task groups, and every end of a run destroys its copy. Consults, a handoff check with hosted CI, prep and build do not run in a copy yet.

The copy's `.env` holds its secrets. Git never takes it: every container create and clone names it in the checkout's own `info/exclude` ([what is built](/reference/development-containers#what-is-built)), so neither the check's commit nor an agent's commit holds it. A copy or container made before that line existed lacks it until its next create or clone: its check can still commit the file. The publish refusal `tasks.publish_secret_path` covers such a copy too: nothing of it is pushed. Cancel it and start the task again.

| Part | Rule |
| --- | --- |
| Source | The active default Instance with a seed, whose container runs or [sleeps](/reference/development-containers#sleep-and-wake). `task:start` reads it once: the power record can be stale. A container down without its asleep marker refuses |
| Cap | A start needs a free [isolated ID range](/reference/development-containers#isolated-id-ranges) for the copy and one for the default's next seed, else `tasks.copy_id_range_full`. `ORBIT_TASKS_COPIES_MAX` (default 1) caps nested-fleet copies per Node: `tasks.copy_capacity_full`. Neither waits |
| Publish | Only for a nested-fleet Project or a Project on `ORBIT_TASKS_COPY_PUBLISH_PROJECTS`. Otherwise `tasks.copy_publish_held` (409), before any Operation. The [mode](#task-group-modes) decides whether Orbit publishes by itself once the work is done |
| Model key | The copy's agent gets the fixed task model key, as every clone does. It is not per task. Per-task model keys are an open decision |
| Ready | The task runs when its copy is `active`: after the copy's [completion](/reference/development-containers#complete-a-clone) succeeded. A create that succeeds leaves the task `reserved` until then |
| Failed create | The task goes back to `todo`, Orbit [removes the copy](#removal-by-orbit) and starts the create once more; see [a failed create](#a-failed-create) |
| Failed completion | The copy stays and the task stays `reserved`, with an assistance request and the retry. See [a failed completion](#a-failed-completion) |
| Kept copy | After an expired run budget, a failed completion, a failed destroy or a copy that Orbit would not remove, the task keeps the copy until `task:cancel`, `task:complete` or `instance:destroy --force` |
| End | When the copy's destroy succeeds, a running task is `cancelled`, a failed task stays `failed` and a completed task stays `completed`. All let go of the copy |

#### A failed completion

A failed, cancelled, interrupted or unadmitted `instance.complete` of a copy keeps the copy, and the task stays `reserved`. Its create succeeded, so its thread was usable and an agent may have worked in it.

The task asks for assistance with kind `failure`, unless a direction request is open. The reason names the completion Operation, its failed step, its error code and the retry `orbit instance:complete <copy-id>`. When a Process of the Project's definitions died, it says to fix that definition before the retry. The request replaces any other open failure, so the held copy always shows. `task:show` and `task:status` show it.

Until a completion succeeds, a turn, a check, a publish and a proof answer `tasks.copy_completion_failed` (409). The details name `operation_id`, `failed_step`, `error_code` and `retry`, and `requires` when the retry needs a changed Process definition first. The copy's Processes and inner fleet did not start, and a retry may run at the same time. While a retry runs, a turn, a check, a publish and a proof answer `tasks.copy_not_ready`. A retry that succeeds runs the task and clears that assistance request, and only that one.

#### A failed create

A failed `instance.create` of a copy does not end its task: a slow baseline read or a full Swamp worker pool on a loaded host must not cost the task. Every end counts but success, an expired run budget and a cancel: a failed run, a failed register or [baseline read](/reference/development-containers#workspace-work-state), a start that Swamp refuses.

The task goes back to `todo` and keeps the copy until Orbit [removes it](#removal-by-orbit). It records the first failure in `copy_create_failure`: the Operation, its failed step and its error code. When the copy's destroy succeeds, Orbit starts the create once more for the same task, through the normal start and as its first caller. Orbit destroys the copy rather than run the create again over it: a task copy is always a new clone, and the destroy keeps any work by its own rules.

When the retry fails too, or its start refuses, the task stays in `todo` and records no further retry. It asks for assistance with kind `failure`; the reason names both Operations, their failed steps and their error codes, and `orbit task:start <task-id>`. `task:show` and `task:status` show it. Orbit removes the second copy the same way. `task:start` of the task works again once the copy is gone; while it is still bound, the start answers `tasks.copy_removing` (409). A later copy that comes up `active` clears that request, and only that one.

`task:cancel` of the task while it holds the failed copy cancels the task when the copy's destroy succeeds, and nothing starts again. A task moved to `backlog` before the copy is gone owes no retry either.

A cancel of the create is a stop that someone asked for: the task is `failed`, and nothing starts again. The copy of a proof belongs to its run, which fails with it, so its create never starts again either.

#### Removal by Orbit

Orbit removes a task copy by itself only after a failed create, through one queued job that admits the copy's `instance:destroy` with force. A failed completion never removes the copy: its create succeeded, so its thread was usable and an agent may have worked in it.

The job destroys the copy at once only when the records prove that no agent could have worked in it: the create did not succeed and no T3 server is registered for the copy. Otherwise it first reads the copy's work, as [`task:cancel`](/cli/task/cancel) does. It destroys a copy that holds no work origin lacks.

A copy with such work, or a read that does not answer, stays. The task then asks for assistance with kind `removal_failure`, the kind that an ended task keeps. The reason names the unpushed commits, the uncommitted files, how many of them are untracked and the stashes, or why the read did not answer. It ends with `orbit task:cancel <task-id>`, with `--discard` if the work is not wanted.

`task:cancel` and `task:complete` are a person's removals and keep their own rules.

A held publish leaves the checked commit in the copy. An operator adds a Project to `ORBIT_TASKS_COPY_PUBLISH_PROJECTS` once the publish guard for `.env` content is live; a copy of any other Project is a plain clone, so the nested-fleet cap does not count it.

#### Two groups on one Node

Copies of several groups can share a Node, and their checks, publishes and syncs run side by side. Swamp locks a behavior for the whole step and lets a second step wait for that lock 60 seconds before it fails. So each step that two groups can run at once on one target, and that can hold its lock near or past that wait, names its behavior by its group as well as its Node, `<family>-<node>-<group>` ([behavior names](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/README.md#behavior-names)):

| Step | Behavior | Runs up to |
| --- | --- | --- |
| The workspace phase of a publish: preflight, export, discard | `task-workspace-<node>-<group>` | 1,800 s |
| The base-merge, check, commit and commit-ref phases of a copy's check | `task-instance-check-<node>-<group>` | 900 s, 10,800 s, 600 s, 600 s |
| The `.env` content check, the import and the push of a publish on 209 | `task-publish-content-<node>-<group>`, `task-publish-import-<node>-<group>`, `task-publish-push-<node>-<group>` | 240 s, 60 s, 60 s |
| The clone and bundle of a sync on 209 | `task-sync-clone-<group>`, `task-sync-bundle-<group>` | 180 s, 60 s |
| The base phase of a copy's sync or handoff check, on its Node | `task-copy-base-<node>-<group>` | 600 s |

One group never runs two of these at once, because the Gateway admits one task Operation of a group at a time. The short input, evidence and cleanup steps keep their names by Node or one shared name. Swamp retains behavior definitions, so only the long container steps get one per group. A publish that still fails on a behavior lock is retried, as [A refused automatic publish](#a-refused-automatic-publish) describes.

### Build a feature in one copy

Build and prove a feature end to end inside one task copy before any pull request opens, draft or ready. Design the proofs before implementation; for a command, the spec (chart, page and cases) is the first commit on the feature branch. When the feature touches the fleet, use the copy's nested fleet. CI confirms the already-passing proof; it is not the first end-to-end test.

1. Work live in the copy. Change it, run it and fix the next layer until the real end result works there.
2. Log each change you make by hand.
3. Codify all of it on the same branch, pass the end-to-end proof, then open one complete pull request. Only a reviewer's request splits it.

The end result is the real one: an app container serves a page, or a create, deploy or wake succeeds.

Never split a feature into pull requests that each wait for a release or a live window to find the next problem. A release or a window is the final proof of a working feature, never the way to find its bugs.

A test that the copy cannot give, such as another idmap or a host setting, gets its own experiment container, never a shared seed.

Nested fleet work once took 18 hours through 3 live windows; a hand build in one experiment container then finished it in about one hour. The [copy rules](#run-an-external-turn) carry it to every turn as one line.

### Bring main into a copy

A copy holds no credential, so it cannot fetch. [`task:sync`](/cli/task/sync) of a group with a task copy makes the default branch's newest head available in the copy as `refs/remotes/origin/<default branch>`. It is one `tasks.sync` Operation, whose run is the copy's own workflow, [`operation-task-sync-copy`](/cli/task/sync#how-it-works):

1. The Gateway mints the sync's read token as the run starts, as a Git header.
2. On the Node, Git fetches the default branch into the copy's own mirror, `/var/lib/orbit/instance-repositories/<instance>.git`.
3. The header is the environment of that one fetch alone. It is never written to a file.
4. A copy without its mirror first gets one from the default's mirror. A copy whose mirror exists needs no default.
5. The Node bundles the default branch without the commits that the copy holds already. A copy that holds the head gets no bundle.
6. Root puts the bundle into a folder of the copy that root owns. As `agent`, Git fetches it into `refs/remotes/origin/<default branch>` and nothing else.
7. The run reads the default head, the branch commit, how far the branch is behind and the copy's start commit.
8. The run's cleanup step removes the bundles. The next sync of the copy, or its destroy, removes any that a cancel left.

The sync never merges or rebases `task-<id>`. HEAD, the working tree, the index and the local branches stay as they are. The result is `{ default_branch, default_commit, branch, branch_commit, behind_by, start }`, where `behind_by` counts the commits of the default branch that `task-<id>` lacks. The agent merges or rebases in the copy, then checks again.

The source is the Project's repository, for every Project. When that is Orbit's own repository, the copy gets the newest commit of `main`. A repository that receives `main` only at a release, such as a trial copy of Orbit, gives the last released main.

| Rule | What happens |
| --- | --- |
| Copy turn | Refused with `tasks.copy_turn_unended` while a copy turn of the group may still run |
| Check or publish | The group lock refuses the sync with `operation.target_busy` while either runs |
| Container | Refused with `tasks.instance_not_running` when the copy's container does not run |
| Credential | Minted per run; it is the environment of the Node's one fetch and is never written, so a killed run leaves none |
| Workflow | The Gateway starts `operation-task-sync-copy`. `operation-task-sync` served the removed task VMs; the Gateway starts it no more. Swamp resolves every step's inputs at run start, so each reads only its intent's keys |

#### The handoff check merges main first

The handoff check of a copy judges the tree that publish will push. Its run first runs the same `base` phase as the sync, through the same child workflow, `task-copy-base`. It has its own read token under the same rules. The Gateway mints it as the run starts, and the Node's one fetch reads it as a Git header through stdin. The copy never holds it. Then, as `agent` in the copy, the run merges with `git merge-tree --write-tree HEAD refs/remotes/origin/<default branch>`:

| Case | What happens |
| --- | --- |
| HEAD holds the head | Nothing. `merged_default` is null |
| Clean merge | Any uncommitted work is committed first, so nothing is lost. Then one merge commit, described below. The check runs on that tree |
| Conflict | The check fails at once with `tasks.check_conflict` and the conflicting paths. The long parts do not run, and the workspace is left exactly as it was |
| Base unavailable | A failed base phase does not block the check. It runs on the tree as it is, and the result says `base: unavailable` |
| Merge refused | The check fails with `tasks.workspace_phase_failed` and changes nothing |

The merge commit has the parents HEAD, then the head. Its message is `Merge main <full sha> before the check`, and its author and committer are `Orbit <orbit@users.noreply.github.com>`, as at publish. A conflict lists at most 100 paths in at most 3,000 bytes in `conflicting_paths`, and `conflicting_more` counts the rest. The implementer merges by hand in the next turn, while the subtask is still open. A base fails when the mirror or GitHub is down; `base_reason` then names the reason.

The run checks every refusal before it changes the copy. It builds the work commit and the merge commit as objects that no ref names. It refuses when the merge would add a file where an untracked or ignored file lies, because Git would overwrite that file. Only then does it update the working tree, the index and the branch. A hand merge that is resolved but not committed reads as the work alone, so it conflicts again: commit the merge, then check.

After the merge step succeeds, HEAD holds the default head. The check then compares file deliverables and `fails_on_base` runs with the subtask's start plus that head, not with the bare start. It does so whether this check merged the head or HEAD held it already, from an earlier check or a sync and a hand merge. So the default branch's own changes never count as the subtask's work. A conflict, or a check without a base, keeps the bare start.

A Project without a GitHub repository or a valid default branch brings in no base, and its result names none of these fields.

The result names `default_commit`, the head the check judged against, and `merged_default`, the head it merged or null, so the driver and the reviewer see the base. `base` is `current` when the head was brought in. Only the copy's own workflow changes refuse at publish: the default branch's workflow changes that this merge brings in are never the copy's ([The base of a copy publish](#the-base-of-a-copy-publish)). A baseline check brings in no base.

### Workspace ownership

When provisioning creates the workspace, it records the task as the workspace's owner, before any remote work. An owner is set only when the Instance is created: the database refuses any new owner afterwards. Deleting the task clears it. A task owns at most one Instance.

The owner is the only record of task workspace ownership:

- A task holds only the workspace it owns. Orbit refuses to attach any other Instance to a task.
- Claims, cancel, complete, and the sweep find and remove a task's workspace only through its owner, attached or not.
- A task operation runs only in the task copy the task owns.
- The name and branch `task-{id}` never make an Instance a task workspace. A task never removes your own Instance named or branched `task-{id}`.

Task operations include agent turns, checks, pushes, commit signing, and `.mcp.json` writes. Orbit refuses them on any other Instance.

Task cleanup never removes an Instance without that task as its recorded owner. To remove another Instance explicitly, run [`instance:destroy`](/cli/instance/destroy).

## Swamp run output

The task page reads `GET /api/v1/tasks/{task}/swamp-runs` through the Gateway. It shows the five newest Swamp runs whose `inputs.group` exactly equals the group id, their steps and states, each step's output, and the run log. It works without an agent thread. Subtasks are not valid group ids. The route requires Gateway access and the Tasks extension. It changes no task or Swamp state.

The browser polls every five seconds while the page is open. The Gateway caches each group snapshot for five seconds, holds the next read for five seconds after a failed one, reads as a view of the [handshake ledger](/reference/swamp-command-steps#handshake-ledger), and keeps the last successful, redacted snapshot for 24 hours. `availability` is `available`, `stale` when a read fails or the ledger keeps the serve window for Operations but a snapshot remains, or `unavailable` without a snapshot. When the ledger held the read back, the response adds `Retry-After` with the seconds until the next read may connect.

`observed_at` records the successful read time and remains unchanged on failure. An available empty list means no matching runs. An unavailable response has no runs. These states return HTTP 200 so an outage does not break the rest of the task page.

The Gateway connects to `swamp.orbit:443` over verified TLS. Set `ORBIT_SWAMP_READ_TOKEN` in the Gateway environment to a dedicated Swamp token with only `read` grants on workflows and their output data. Never reuse the driver or worker credential. `ORBIT_SWAMP_ADDRESS` selects the server address when the Gateway cannot resolve `swamp.orbit`; the TLS name remains `swamp.orbit`. `ORBIT_SWAMP_CA_PATH` selects the Orbit root CA file. The Gateway omits raw run inputs, run filesystem metadata, and upstream exception messages. It redacts output before returning or caching it. The browser contacts neither Swamp nor the task VM.

### Read limits

Each read uses Swamp's WebSocket `workflow.run.search` with `inputs: {group: "<id>"}` and `limit: 5`, then `workflow.history.get` and `workflow.history.logs` for those exact run ids. Orbit verifies group membership again on the returned details. It never uses a free-text search or a browser-supplied run id. Missing configuration, denied reads, oversized messages, malformed replies, and timeouts all produce stale or unavailable state. The complete upstream read has a five-second budget and a 2 MiB message limit. The response keeps at most 100 steps per run, 8 KiB of text per step, 32 KiB of step output per run, and a 16 KiB run-log tail from at most 200 lines. Explicit truncation flags mark omitted runs, steps, or text.

Orbit applies the Activity secret rule before truncation and before caching. It also removes the configured reader token by exact value and strips terminal control sequences. Structured step outputs are recursively redacted before conversion to text. Output is plain text in the browser.

Worker output can arrive after a step finishes. In the pinned Swamp version, worker console lines travel with the method result; resolved step outputs also appear after the method writes them. The page shows running and pending steps while waiting, and refreshes output as Swamp makes it readable. The [Swamp protocol](https://git.swamp-club.com/swamp-club/swamp/src/commit/537e03d1/src/serve/protocol.ts), [run attach authorization](https://git.swamp-club.com/swamp-club/swamp/src/commit/537e03d1/src/serve/connection.ts), and [worker console handling](https://git.swamp-club.com/swamp-club/swamp/src/commit/537e03d1/src/serve/dispatch_service.ts) define these limits. The [Swamp manual](https://swamp-club.com/manual/explanation/swamp-serve) describes the server and grant boundary.

## Agent threads

An **agent thread** is one persistent conversation with a coding agent. It records the driver, the external conversation id, the Node, the task and subtask, the role, the model, and the effort. A copy thread also records how its external id came to be: `admitted` when Orbit set it and T3 has not reported it back, `confirmed` once T3 reported that same id, and `overwritten` when a turn reported another id ([admission](/reference/development-containers#admission)). The external runtime writes the transcript. Orbit keeps recorded snapshots, the link and the metrics.

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

A turn runs in a [task copy](#task-copy) on T3: Swamp runs `t3 thread start` or `t3 thread send` in the copy as user `agent`, with the program of the copy's [recorded container server](/reference/development-containers#container-server), stock T3's `t3` or the Conn Server's `conn-server`. The thread records the driver `t3` with either. When the turn's projection commits, the copy's T3 thread and its title go to the [Conn thread index](/reference/conn#thread-index), so a device lists it at once. A caller never supplies a runtime URL. A thread takes its [copy turn agent](#copy-turn-agents).

The `pi` driver ran turns on task VMs and is removed with the Pi task loop. Threads of old groups keep the driver `pi` and stay readable. A completion or cancel of such a group interrupts nothing, because the task VM and its Pi server are gone. A new task still stores `pi` in its `implementer_agent_driver` and `reviewer_agent_driver` columns, which nothing reads; the Gateway reads none of `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER`, `ORBIT_TASKS_REVIEWER_AGENT_DRIVER`, `ORBIT_TASKS_IMPLEMENTER_EFFORT` or `ORBIT_TASKS_REVIEWER_EFFORT`.

### Copy turn agents

When a copy turn asks a question, Orbit stores it, with the run that owns its request, and stops the run. Only a turn whose run T3 confirmed replaces the stored questions; a turn that was only possibly sent keeps them.

The next turn of that thread answers it: Orbit first resolves exactly the stored question requests in T3, then sends the answer with the questions quoted for context. Stopping the run alone can leave a question pending. Already resolved requests need no action. If resolution cannot be confirmed, the turn fails before launch with `tasks.copy_question_unresolved` and keeps the questions for another attempt. See [turn questions](/cli/task/turn#a-task-that-runs-in-a-copy).

Each role of a copy turn, implementer and reviewer, runs with a provider, a model and an effort. A new copy thread resolves each of the three values on its own:

1. the task's value, set with [`task:create`](/cli/task/create) or [`task:update`](/cli/task/update);
2. else the Project's default, set with [`project:update`](/cli/project/update) and shown by [`project:show`](/cli/project/show);
3. else the Gateway's setting.

| Gateway setting | Shipped default |
| --- | --- |
| `ORBIT_TASKS_COPY_IMPLEMENTER_PROVIDER`, `ORBIT_TASKS_COPY_REVIEWER_PROVIDER` | `claude` |
| `ORBIT_TASKS_COPY_IMPLEMENTER_MODEL`, `ORBIT_TASKS_COPY_REVIEWER_MODEL` | `claude-opus-5-5` |
| `ORBIT_TASKS_COPY_IMPLEMENTER_EFFORT` | `medium` |
| `ORBIT_TASKS_COPY_REVIEWER_EFFORT` | `high` |

The thread stores the resolved model and effort and the T3 provider instance it started on; [`task:agent:show`](/cli/task/agent-show) shows the model and effort. A turn that continues the thread keeps them, whatever the task, the Project or the Gateway say later. [`task:show`](/cli/task/show) prints each role's resolved values and where each comes from: `group`, `project` or `gateway`.

A role's own values on the task change with `task:update` until the role has a thread; after that, `task:update` refuses them with `tasks.agent_settings_locked` (409). The lock covers only the task's own values. The Project's default and the Gateway's settings can still change, and a later new thread of the role, such as the implementer of the next subtask or a turn with `--thread=new`, resolves again and takes them.

Stock T3 0.1.7 takes no effort on its command line. The copy's T3 settings therefore hold one provider instance per provider and effort, such as `claudeMedium` or `codexHigh`, and a new thread starts on the instance of its provider and effort.

A Claude instance sets the effort with `CLAUDE_CODE_EFFORT_LEVEL` in its environment. A Codex thread carries its effort as its `reasoningEffort` model option, because T3 0.1.7 sends Codex no other effort. Orbit therefore creates each new thread through T3's own RPC and runs its first turn with `t3 thread send`. This was verified with Claude Code 2.1.311, Codex 0.167.0 and T3 0.1.7. See [turn model and effort](/reference/development-containers#turn-model-and-effort). A copy made before the Codex instances cannot run a Codex thread: start the task on a new copy.

A new thread's values are checked against what the copy's T3 offers, in two places:

| Value | Where | Against | Refusal |
| --- | --- | --- | --- |
| Provider and effort | At the turn's admission, before anything runs | The provider instances Orbit writes into the copy's T3 settings: `claude` at `low`, `medium`, `high`, `xhigh` or `max`; `codex` at `low`, `medium`, `high`, `xhigh`, `max` or `ultra` | `tasks.copy_turn_unoffered` (422) with the offer in `details.offered`; nothing is recorded |
| Model | First in the turn, before any checkout or start | The slugs and aliases in T3's cached `model-manifest.json` for the driver, or a successful probe of an unlisted Codex model | `tasks.copy_turn_model_unoffered` if the provider rejects the model or the catalog rules it out. `tasks.copy_turn_model_unverified` if Orbit cannot resolve the driver or the Codex probe is inconclusive. Nothing is launched |

Stock T3 0.1.7 lists its models through no command, and `t3 thread start` does not check a model: a model Claude Code does not know would fail only inside the run. T3 fetches its model catalog and caches it in its data folder, so the turn reads that file in the copy.

When the catalog omits a Codex model or cannot be read, the copy sends one bounded, tool-free request through the Codex configuration it uses for turns. The provider must return a usable response for the exact requested model. The probe leaves the cached catalog alone.

For other drivers, without a readable catalog the model check is skipped. The provider can still reject the model in the run with `tasks.turn_failed`. A start that T3 refuses, such as one on a provider instance it does not hold, fails with `tasks.copy_turn_failed`. The error keeps no text of T3, which may quote the prompt.

### Archive finished threads

Swamp records a redacted transcript with each terminal turn result. A copy turn's transcript keeps a user message only by its ID, author and time, so the prompt stays out of the archive. The Gateway stores normalized entries at `storage/app/private/task-transcripts/{task}/{thread}.json`. It keeps the thread row, metrics and transcript after the copy is destroyed. A turn without transcript evidence keeps its previous snapshot. Cleanup makes no transcript request. Transcripts from turns that finished before recorded turn snapshots shipped may be unavailable. This loss is accepted for those test and pipeline tasks; no migration fetches them.

## Session routing

Swamp selects a subtask, role, brief and operation key for each turn. The Gateway performs that turn and records its thread and receipt. It never routes the next turn or interprets a receipt as permission to start other work.

### Fetch before a turn

This section and [Turn receipt](#turn-receipt) describe the Pi turn on a task VM, which is removed with the [Pi task loop](/reference/old-paths#the-pi-task-loop). A copy turn fetches nothing and writes no receipt ([chart](/cli/task/turn#in-a-task-copy)).

### Turn receipt

The Pi file receipt and its validation are removed with that turn path. A copy turn records its Operation result, thread and transcript. It does not ask an agent to write `.git/orbit/receipt.json`.

### Questions

Questions and assistance remain durable board records. Swamp decides when to ask a person, send an answer or retry. The Gateway never sends an answer merely because a resolution comment was stored.

### Assistance and resolution

A non-empty resolution clears its matching request and remains in comment history. Independent requests remain open. No turn, check, fixup or baseline starts as a side effect.

#### Group assistance after all children end

An open group with no unfinished child accepts an `assistance_requested` or `resolution` comment through `POST /api/v1/tasks/{task}/comments`, CLI `task:comment:create`, or MCP `task-comment-create`. The body contains `type`, nonblank `body` (at most 100,000 characters) and `author` (at most 255 characters). The response is `201 {data: TaskCommentData, meta: {request_id}}`. An ended group returns `tasks.group_closed`; an unfinished child returns `tasks.assistance_subtask_open`.

#### Direction requests

An assistance request records the question on the group and subtask. A resolution records the answer. Swamp owns the next action.

#### Resolve a request

Use the comment API, CLI or MCP to post a resolution. This changes assistance only. Swamp reads the answer before deciding how to continue.

### Bring the workspace up to date

`task:sync` starts `tasks.sync` through `POST /api/v1/tasks/{task}/sync` with a required `Idempotency-Key` and no body. It brings the Project's default branch into the group's task copy: [bring main into a copy](#bring-main-into-a-copy). A group without a task copy returns HTTP 409 `tasks.no_workspace`: the uncalled task VM sync workflow is deleted, and the Gateway always starts `operation-task-sync-copy`.

## Project check

A `tasks.check` operation runs the Project's configured check once, before the subtask's deliverables. Command builds add the runner plan as a command deliverable; they do not repeat the configured Project check as another command deliverable. Without a configured command, the build adds a Project-check review deliverable. A baseline can include setup commands. A handoff records command deliverables, the workspace tree and checked commit. The result keeps bounded, redacted output, and native history keeps each finished step, so recovery does not run a finished check step again.

### Check output

The check result contains status, exit code, failed step, changed paths, tree and commit evidence, and bounded redacted output. A failed check is evidence for Swamp, rather than permission to start a repair or retry. A lost supervisor does not prove its children stopped; ownership stays held until stopped work is confirmed.

### Handoff CI

When the requested handoff includes hosted CI, the operation sanitizes the checked commit, pushes without force to the handoff branch and waits for that exact commit's required checks. Its pinned plan and push identity survive a lost reply. Swamp owns budgets, cancellation and any next action. See [Check an external group](#check-an-external-group).

### Baseline check

Swamp requests a baseline before implementation when its workflow calls for one. The operation runs setup and check commands, records evidence and returns. A failure starts no agent. In a task copy the baseline runs no setup steps, because the copied seed already ran them ([check phases](/reference/task-instance-workspace#check-phases)).

### Scoped task check

The Orbit Project whose default Instance is a container uses `bin/task-check` as its check. The candidate gate, `bin/review-check`, checks all four Composer projects and does not fit a handoff in a copy. `bin/task-check` checks what the task changed since its start commit, as CI checks it, and stays under five minutes.

A person sets it on the Project once, after the release that ships the command. `orbit project:update` takes the numeric Project id; the Orbit container Project is 78:

```bash
orbit project:update 78 --task-check=bin/task-check
```

Orbit runs every task check with `ORBIT_CHECK_PROCESSES=1`, whatever the copy's CPU count, so the check runs PHPStan, Pest and the fixture units in one process. Its five-minute budget holds at that value; fixture units it does not reach are deferred to CI and named.

Another Project that develops Orbit sets the same setting on its own id. Its copy must hold what the parts run: the vendor folders of the Composer projects, Deno for the Swamp parts and Node for the flow charts. A seeded copy holds them; the command installs nothing. Project 46 keeps `composer check` as its task check, as [its task policy](/reference/instance-setup#configure-orbits-task-policy) states.

The check runs at the repository root, as every task check does.

#### Start commit

The start commit is HEAD's merge base with `origin/main` when that ref exists, else with `main`. The first line of the output names the start commit and the ref that gave it. `bin/review-check` uses the same rule.

A copy's branch starts at the seed commit, which is `origin/main` in the copy, so a branch at its start is checked against the seed. A copy never fetches on its own. [`task:sync`](#bring-main-into-a-copy) moves its `origin/main` to the Project's newest main and reports `behind_by`; then the agent merges or rebases:

```bash
# In the copy's /srv/workspace, after task:sync:
git merge origin/main        # or: git rebase origin/main
```

The branch then holds that main, so the start commit is that main, and main's own commits are not counted as the task's changes. A sync alone, without a merge or rebase, leaves the merge base and the start commit where they were. If `origin/main` stays at the seed, merged commits count as changed paths. Moving only `main` changes nothing, because `origin/main` comes first.

#### Docs-impact waivers in a copy

The `docs-impact` part reads waivers from the branch's proposed PR body in the copy, `.git/orbit/proposed-pr-bodies/task-<id>.md` under `/srv/workspace`. Nothing else puts that file there: the implementer writes it before the handoff check, with one line for each impacted page that needs no change:

```text
Docs-unaffected: docs/reference/tasks.md: <the specific reason>
```

When a page is neither changed nor waived, the part's output names the file and says whether it is absent or holds no valid line for the page. The reviewer confirms the reason.

[`task:publish`](#publish-an-external-group) carries these lines into the pull request: it appends each `Docs-unaffected:` line of the file that the body does not hold yet, while the body stays within 65,536 characters. The file is data and is never run. The [`.env` content check](#task-copy) reads the final body, waiver lines included, before any token exists.

#### Parts

[The scoped task check](/reference/implementation-loop#the-scoped-task-check) lists its parts, its budget and what it leaves to CI. A failed part fails the check. Fixture budget and capacity checks are unconditional. Expensive checks run when their inputs change: tooling and documentation select docs lint and flow checks, and a file that a policy test reads selects the policy tests. API reference inputs also select docs lint and flow checks. Selected policy checks run before Pest and the fixtures.

The `fixtures` part runs the units of CI's fixture plan that the change touches directly first, then those that fit the budget; it names the rest as deferred to CI. A budget stop or deferral keeps exit zero when nothing failed, but the final result explicitly says coverage is incomplete and CI must finish.

Within a selected Pest job, the check runs the project's changed test files and its pin test files: the Gateway and CLI tests that pin a whole intent, digest or command surface, such as `GatewayRuntimeFilesTest` and `CommandSurfaceTest`. A change far from such a test can break it, so the check runs them whenever CI selects their project's Pest job, and a handoff check finds what CI's full suite finds on them. The `PIN_TESTS` table in `bin/task-check` lists them.

#### Stored result

Orbit stores the last 16 KiB of the check's output, redacted by [the one rule](#keep-command-output-out-of-run-history), as the check's `output`. `task:check`, `task:show` and the API's check `output` return it. `bin/task-check` ends its output with a summary of at most 12 KiB, so the stored output always holds the whole summary:

- `Task check summary`, then one line per part with its outcome, its seconds and its detail.
- For each failed part, `Last lines of <part>:` and the newest lines of its output. The failed parts share the bytes that the other summary lines leave.
- The parts CI alone runs, then `Task check PASSED` or `FAILED`, the start commit and the seconds.

Each line of the summary and of a part's output is at most 300 bytes. A longer line keeps its start and ends with `… (N bytes)`, such as a list of budget-skipped fixture units. The output before the summary, each part as it ends, fills the rest of the 16 KiB.

After the Project check's output, Orbit appends the deliverables and the check's outcome, so the output never ends with a result that the check's outcome contradicts:

- A `Deliverables` block for a subtask with deliverables. Each deliverable gets one line: its id, its outcome and its command's exit code.
- The outcome is `passed`, `failed`, `not_run` or `not_checked`. The exit code appears only when the command ran.
- Each failure follows on its own line.
- A failed command also shows `Last lines of <id>:` and at most 1 KiB of the last lines of its redacted output.
- Then one final line states the outcome: `Task check PASSED`, `Task check FAILED`, or `Task check CHANGED`. `CHANGED` means the workspace moved during the check.
- Deliverables that fail a check whose Project check passed are named on that line, such as `Task check FAILED: deliverable merge-round-tests did not pass`.

The final line is always the last line of `output` and always agrees with `outcome`. A later step that changes the outcome restates it: a moved candidate gives `Task check CHANGED`, and a failed CI run gives `Task check FAILED: CI failed`. The older Project output gives way, so the whole output stays within 16 KiB; the block and the final line are kept. A block too large to fit drops the output tails first. A check that refuses with an `error_code` has no such line.

## Review a subtask

Swamp can request a reviewer turn through `task:turn`. The Gateway records the turn's output without approving a subtask, appending findings work, starting another reviewer or publishing a PR.

### Reviewer outcomes

The workflow that requested a reviewer owns review policy. The Gateway records its receipt as evidence. The operator or Swamp changes board status explicitly. What blocks is set by [the reviewer rule](#the-reviewer-rule).

### The reviewer rule

This is the one rule for what a reviewer judges and what blocks. Every reviewer turn in a task copy gets it after the caller's prompt and the line that names the checked commit, whatever the reviewer's provider. Reviewers outside task copies apply it too. Other pages and prompts link here and do not restate or widen it.

The scope is the brief, its deliverables, the diff of the checked commit against the task's base, and what that diff directly affects. The reviewer judges only that scope. It reports its blocking findings and at most a short list of non-blocking ones.

A finding blocks only if a normal run, or a likely failure, would cause real harm. A likely failure is a crash, a reboot or a network loss at one step. Real harm is one of these:

- It stops or deletes something live.
- It breaks live requests or user-visible behaviour.
- It loses or corrupts data.
- It produces a wrong result that the brief asks for.
- It adds a security hole.

A missed or unproven deliverable, and a broken or missing test the brief asks for, also block. For each blocking finding, the reviewer gives its file, its line and the failing case.

Rare interleavings, races that need several unlikely events, hardening and defence in depth do not block. They are follow-ups for the pull request body. The reviewer asks for no new recovery states, markers or retry paths to cover them. Removing a state beats hardening it.

In code behind a flag that is off by default, a finding that matters only with the flag on goes on a "before the flag goes on" list. It does not block the merge.

A re-review checks only the earlier blocking findings and regressions in their fixes.

The reviewer reports nothing outside the scope. It offers no ideas, refactors or redesigns beyond the task. It asks for no change outside the files the task touches, unless a blocking finding forces one. Then it names that finding.

With no blocking finding, the reviewer approves.

The [pull request text](#the-reviewers-pull-request-text) rule follows it: a reviewer that approves sends the text, one that does not sends nothing. An implementer turn gets neither rule.

## Task group modes

Every group has a mode from its creation on; it acts on a group whose workspace is a [task copy](#task-copy). The mode decides what Orbit does once the group's work is done: it waits for a person, or it opens the pull request by itself. Orbit never merges the pull request of such a group. A person merges it on GitHub.

| Mode | When the work is done |
| --- | --- |
| `review_before_pr` | Nothing happens on GitHub. The group waits until a person runs `task:publish <group>`. This is the default, and it is how every group worked before modes existed |
| `pr_when_done` | Orbit publishes the group by itself and opens a ready pull request |

### Where the mode comes from

The mode is resolved each time Orbit reads it, in this order:

1. The group's own `pr_mode`, set by [`task:create --pr-mode`](/cli/task/create) or [`task:update --pr-mode`](/cli/task/update).
2. The Project's `task_pr_mode`, set by [`project:update --task-pr-mode`](/cli/project/update).
3. The Gateway's `ORBIT_TASKS_PR_MODE`, default `review_before_pr`. Any other value refuses with `tasks.pr_mode_setting_invalid` (503).

[`task:show`](/cli/task/show), `task:create` and `task:update` return the mode in effect as `pr_mode` and where it came from as `pr_mode_source`: `group`, `project` or `gateway`. They return it before the copy exists too. A subtask has no mode. `task:update --pr-mode` is refused with `tasks.pr_mode_locked` (409) once the group has a pull request, while a publish of the group runs, and after the group ended.

When a handoff check passes, Orbit records on the check the mode that was in effect at that moment. Orbit publishes by itself only when both that recorded mode and the mode now in effect are `pr_when_done`. So a later change of the Project or the Gateway setting to `pr_when_done` never publishes a group that was already done: such a group needs a new handoff check, or a person runs `task:publish <group>`.

### When the work is done

Orbit decides from its own records that a group's work is done. All of these must hold:

- The group is open, its workspace is a task copy, and it has no pull request.
- Every subtask has ended, and at least one is `completed`.
- The last completed subtask has a passed handoff check that is an [eligible candidate](#check-an-external-group), and no handoff check of the group is newer. This is the done check.
- The newest turn of the group's reviewer thread reviewed exactly the done check's commit for that subtask and ended `done`.
- No earlier copy turn may still run, and no other Operation of the group runs.

The commit counts, not the check. A re-check of a clean recorded HEAD makes a new check with the same commit, so a reviewer turn on the earlier check of that commit still counts.

A copy reviewer turn records no verdict. The caller's move of the last subtask to `completed`, after a reviewer turn on exactly the done check, is the approval. No clock decides "after": a turn is admitted only on the current subtask, and [`task:update`](/cli/task/update) refuses to complete a task copy's subtask with `tasks.copy_turn_unended` while a copy turn of the group runs. So a reviewer turn on the last subtask always ended before that subtask was completed.

A [reopen](#reopen-the-last-subtask) of the last subtask is refused while a copy turn runs too, and a reopened subtask has not ended, so the group is not done until it is completed again.

For a Project without a task check, a passed handoff check only records the commit: nothing ran on it. The automatic publish still runs. What stands in front of it is the reviewer turn on exactly that commit and the guards of every publish: the [copy publish allow list](#task-copy) and the `.env` content and Club key checks.

### Publish by itself

The Gateway scheduler runs `task:pr-when-done` every minute while the Tasks extension is on. For each group in `pr_when_done` whose work is done, and whose done check recorded `pr_when_done`, it starts the same `tasks.publish` as [`task:publish`](#publish-an-external-group), with the done check, a title and a body. Every guard of a manual publish applies: the group lock, the [copy publish allow list](#task-copy), the candidate check, the `.env` and Club key checks, and the token minted at the push. The [chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/pr-when-done.mmd) shows the tick.

The title and the body are the [reviewer's pull request text](#the-reviewers-pull-request-text). The pull request opens ready, not as a draft. Each start is keyed `pr-when-done:<group>:<check>`, so a repeat tick on the same done check starts nothing. A retry after a [lock timeout](#a-refused-automatic-publish) adds its attempt: `pr-when-done:<group>:<check>:2` and `:3`.

### The reviewer's pull request text

The reviewer in the task copy writes the pull request: its title and its body. Orbit opens the pull request with that text. The copy holds no Git token and never calls GitHub.

The reviewer sends the text during its turn, from the copy, with its [copy key](/reference/development-containers#the-copy-key):

```bash
. ~/.config/orbit/model.env
curl -sS --fail-with-body -H "Authorization: Bearer $ORBIT_COPY_KEY" \
  --form-string 'title=Keep every field when an organisation is created' \
  -F 'body=<pr-body.md' "$ORBIT_COPY_API/pull-request"
```

The reviewer reads the key from the file, not from its environment: Codex can leave variables whose names hold `KEY` out of the shell it runs.

The request also takes JSON, `{"title": "...", "body": "..."}`. It names no group, check or commit: the key names the copy, and the copy names its group. The Gateway accepts it only when all of these hold:

- The copy's group is open: it has not ended. Otherwise `tasks.group_closed` (409). A group that has its pull request accepts the text too.
- A turn of the group's reviewer thread runs now, on a checked commit. Otherwise `tasks.pr_text_not_reviewer_turn` (409).
- That turn's check is still an eligible candidate of the current subtask. Otherwise `tasks.commit_not_checked` (422).
- The Project is released for copy publish, as [Task copy](#task-copy) describes. Otherwise `tasks.copy_publish_held` (409).
- The title has 1 to 256 characters on one line, and the body 1 to 60,000 UTF-16 code units, as the publish step counts them. Otherwise `validation.failed` (422).

Orbit records the text on that check, with the reviewer turn's Operation, and answers 202:

```json
{"data": {"state": "recorded", "check_id": 20977, "operation_id": 21890, "opens": "when the group is done"}}
```

For a group that has its pull request, `opens` reads `updates the pull request on the next publish of the done check`.

A second call in the same turn replaces the text. Orbit opens nothing at this point. A turn cannot publish its own work: while it runs, the group is busy. The reviewer sends the text only when it approves the commit. Its turn prompt says so.

When the group is [done](#when-the-work-is-done), the publish takes the title and the body from the done check. Every publish of the done check carries that text: the first opens the pull request with it, and a [republish](#release-a-waiting-group) updates the open pull request's title and body with it. So a reviewer turn after the pull request opened, such as one after a CI fix, rewrites the pull request without a hand edit.

The text counts only when the reviewer thread's newest turn sent it. A later reviewer turn on the same check that sends nothing leaves the group without text. Every guard of the publish reads this text as it read Orbit's own: the `.env` content check, the [copy check](#the-copy-check) and the waiver lines.

There is no fallback text. A group whose done check has no text waits in `waiting_for_pr_text` until a reviewer turn sends one. A task copy made before the copy key existed cannot send text: a person publishes its group with `task:publish <group> --check=<id> --title=<text> --body=<text>`.

#### Closes the issue

A group can name the GitHub issue it works on: [`task:create --issue=<number>`](/cli/task/create). The issue is in the Project's own repository. Every publish with a pull request text then makes sure that the body closes it, whoever wrote the text: the reviewer or a person with `task:publish --title --body`. When the body holds no `Closes #<number>`, `Fixes #<number>` or `Resolves #<number>`, in any form of these words, Orbit adds `Closes #<number>` at the end. A body that names such a word with that number anywhere, also in a code sample, counts as closing it. GitHub closes the issue when the pull request merges. A group without an issue gets no such line.

### Publication state

`task:show` returns `publication` on a group with a task copy:

| Field | Meaning |
| --- | --- |
| `mode`, `mode_source` | The mode in effect and where it came from |
| `state` | `working`, `waiting_for_pr_text`, `waiting_for_release`, `publishing`, `refused` or `published` |
| `check_id`, `commit` | The done check and its commit, or null while the group is `working` |
| `operation_id` | The `tasks.publish` Operation, when one started |
| `error_code` | The code of a refused publish, or null |
| `pr_text` | The [reviewer's text](#the-reviewers-pull-request-text) on the done check, `{title, body, operation_id}`, or null |

| State | Meaning |
| --- | --- |
| `working` | The work is not done yet |
| `waiting_for_pr_text` | The work is done, but the reviewer thread's newest turn sent no pull request text. A new reviewer turn that sends it moves the group on |
| `waiting_for_release` | The work is done, and Orbit does not publish by itself: the mode is `review_before_pr`, or the done check recorded `review_before_pr` |
| `publishing` | A publish of the done check runs, or the tick starts one within a minute because the group and its done check are in `pr_when_done`, also to retry a [lock timeout](#a-refused-automatic-publish) |
| `refused` | A publish of the done check failed, was cancelled or was refused, by any caller. A [lock timeout](#a-refused-automatic-publish) counts after its last retry, or at once outside `pr_when_done` |
| `published` | The group has its pull request |

[`task:status`](/cli/task/status) lists every group in `waiting_for_pr_text`, `waiting_for_release` or `refused` under `releases`. These states are not board statuses: the group keeps its status.

### Release a waiting group

`task:publish <group>` without `--check`, `--title` and `--body` publishes what the automatic publish would: the done check, the reviewer's title and body, and a ready pull request. It refuses with `tasks.not_done` (409) when the group's work is not done, and with `tasks.pr_text_missing` (409) when the group waits for its text. It also releases a group in `refused` after the cause is fixed. The form with `--check` stays as [Publish an external group](#publish-an-external-group) describes. A group without a task copy has no done check: without `--check`, its publish keeps the validation error (422) on `check`.

On a group that has its pull request, the same call publishes the done check again: a republish. The work must be done by every other rule of [When the work is done](#when-the-work-is-done): the newest handoff check is the last subtask's, and the reviewer approved its commit. The publish pushes that check to the pull request's branch.

When the reviewer thread's newest turn sent its [text](#the-reviewers-pull-request-text) for the done check, the same publish updates the open pull request's title and body with it, with `Closes #<issue>` as on the first publish. Without such text, the publish only pushes, so the open pull request keeps its title and body and shows the new commits. `task:publish <group> --check=<id>` naming the done check publishes the same way. The tick never republishes: it publishes only a group without a pull request.

The admission of any publish of the done check, released or named, reads done again inside its transaction, after it locks the group's row. A subtask added or a turn started since the first read refuses with `tasks.not_done`. For the automatic publish it also reads the mode again there: a group or done check outside `pr_when_done` refuses the same way.

### A refused automatic publish

A refusal at admission, such as `tasks.copy_publish_held`, `tasks.commit_not_checked` or `tasks.instance_not_running` for a stopped task copy, starts no Operation. Orbit stores its code on the group, and `publication` shows `refused` with that code. A publish of the done check that failed, was interrupted or was cancelled, whoever started it, keeps its code on its Operation, such as `tasks.push_failed`, or `operation.cancelled` and `operation.interrupted` when it has none; `publication` shows it the same way. Orbit does not start that done check again by itself, except after a lock timeout, as below. A new handoff check or `task:publish <group>` tries again; start a stopped copy first.

Only `operation.target_busy`, `tasks.copy_turn_unended` and `tasks.not_done` are no refusal. A group in `waiting_for_pr_text` is not admitted at all: the next tick reads the group again and stores nothing. Any other refusal is stored, also a 503 such as `swamp.ssh_identity_unavailable`, because Orbit marks no admission code as passing; release the group once its cause is fixed. A fault that is no refusal is reported, stored as `tasks.publish_failed`, and the tick goes on with the next group. A refusal is never stored over a publish of the group that was admitted in between.

A publish that failed because a Swamp behavior lock timed out is no refusal. Its run waited 60 seconds for a step that another run held, and nothing on the copy or on GitHub was wrong. The step can come after the push, so the branch may be pushed already. A retry is still safe: an identical tip pushes nothing, and the PR plan updates an open pull request instead of opening a second. Swamp reports a failed step whose error reads `Lock "<path>/.lock" held by <holder> (pid <pid>) — timed out after <ms>ms`, or the same without the holder when it let go at the deadline. Orbit records `tasks.publish_lock_timeout`.

The tick then starts the same done check again, one attempt per tick and at most 3 attempts in all. Each retry is a new Operation, keyed with its attempt. While attempts remain, `publication` shows `publishing`. After the third lock timeout it shows `refused` with `tasks.publish_lock_timeout`, and `task:publish <group>` tries again.

These retries never hold a hand `task:publish` of the same check: it is admitted while no Operation of the group runs, as any publish is. Only a group in `pr_when_done` whose done check recorded `pr_when_done` is retried. The lock timeout of any other group shows `refused` at once. Any other failure, such as `tasks.publish_secret_content` or `tasks.push_failed`, stays a refusal and is not retried, also when a later publish of the same check timed out on a lock.

A refused publish changes nothing else. The group keeps its status, and the task copy and its checked commit stay until `task:cancel` or `task:complete`.

### No merge

Orbit never merges the pull request of a group whose workspace is a task copy, also after the group has ended. A pull request counts as such when a `tasks.publish` from a task copy opened it. It also counts when its branch is `task-<id>` of a group that is or was such a group: that covers a publish that ended before it recorded the number. Every merge path refuses it with `tasks.copy_pr_no_merge` (409).

Both records match by the repository, not by the Project. Two Projects can name one repository, as the old Orbit Project and the Orbit Project do. A merge in one of them must still see the pull request that a copy of the other opened. The publish names the repository it pushed to, so a later change of a Project's repository does not move its old pull requests.

`project:pr-merge` also refuses a pull request of a group of another Project on the same repository, with `project.pr_of_other_project` (409). Such a group published it, or its head is the group's `task-<id>` branch. That Project merges it, or a person on GitHub.

| Path | Behavior |
| --- | --- |
| [`task:pr-merge`](/cli/task/pr-merge) | Refused before any GitHub read, also for a watched pull request that a copy of any Project opened. Once read, also for a head on a copy group's branch |
| [`project:pr-merge`](/cli/project/pr-merge) | Refused for such a pull request of any Project on the repository: by its number before any GitHub read, or by its `task-<id>` branch once read |
| [`task:pr-gate`](#gate-check) | Skips the group with `tasks.copy_pr_no_merge`: no ready step and no merge |
| [`task:copy-settle`](#a-copy-group-settles-on-merge) | Reads the pull request's state only, and completes the group after a person merged it |
| [Review and merge](#review-and-merge) | Acts only through `task:pr-gate` and `project:pr-merge`, so it merges no such pull request |
| [Dependency merge](#dependency-batch-squash-admission) | Refused for a batch group whose publish came from a task copy |
| [Automatic deployments](/reference/deployments#automatic-deployments) | Deploy commits already on the default branch, and merge nothing |
| [Modes](#task-group-modes) `review_before_pr` and `pr_when_done` | Publish only: `pr_when_done` opens a ready pull request by itself, and nothing merges it |

So for a copy of a nested-fleet Project, such as the Orbit Project, nothing merges by itself: a person merges its pull request on GitHub.

A test in CI lists every Swamp workflow that calls GitHub's merge API. A new one fails the test until it refuses these pull requests too.

## Pull request and settle metrics

Orbit publishes through the Project's [GitHub App](/reference/github-app#how-orbit-publishes-a-task-pull-request) installation. Agents hold no GitHub token and never fetch or push. [What the App does not cover](/reference/github-app#what-the-app-does-not-cover) states how that is enforced. A task whose Project changes to `source_access: gh_cli` fails to publish and asks for assistance.

Publication exports the checked candidate from the copy and pushes from the trusted worker, never the agent's checkout. [Publish an external group](#publish-an-external-group) owns token handling, exact-commit checks, failure recovery and pull request text. It requests no reviewers and runs no brief-coverage classifier. The removed VM publisher's queued retries and approval tick are not task-copy behavior. The [group mode](#task-group-modes) decides when publication starts; [settling](#settling) observes its pull request.

### Watch the branch while subtasks are open

Swamp observes the published PR and records the group status. Swamp owns branch observation and any resulting assistance.

### Settling

Swamp owns PR checks, feedback, retries, fixups and cleanup. Updating a group to `settling` or `waiting_for_review` records board state and starts nothing.

#### A copy group settles on merge

A group whose workspace is a [task copy](#task-copy) settles itself when its watched pull request merges. Orbit only reads the pull request. It never merges, readies or comments on it ([No merge](#no-merge)).

The Gateway scheduler runs `task:copy-settle` every five minutes while the Tasks extension is on. It runs for every Project with a GitHub repository, whatever its [maintenance](/reference/projects#maintenance) setting. It looks at each running or reviewing copy group with a watched pull request, and reads that pull request with a read-only App token, as [`task:pr-gate`](#gate-check) does:

- **Merged.** The tick records `watched_pr_state`, `watched_pr_completion` `merged` and `merged_at`. Then it completes the group as [`task:complete`](#complete-and-cleanup) does, with no sync.
- **No second pull request read.** The complete reads the copy's workspace instead.
- **Origin's task branch.** The complete also reads it for a group with an approved commit ([Cancel a stuck task](#cancel-a-stuck-task)).
- **The result.** The group ends `completed` with `settled_at` and `merged_at` set, so task insights count it as completed. The copy's destroy is admitted.
- **Closed without a merge.** The tick records the state only. A person completes or cancels the group.
- **Open, or GitHub cannot be read.** Nothing changes. The next tick reads again.

The tick waits for the group to be free and keeps the copy's work:

- **Busy.** The tick skips a group with an open Operation of its own: a turn, a check or a publish. The next tick tries again.
- **A held copy.** The tick also skips a copy that an Operation holds, such as a destroy that runs.
- **Unpublished work.** The copy holds uncommitted, untracked, stashed or other-branch work. The tick records `failure` assistance once and leaves the copy.
- **A stuck copy.** The copy is stopped or asleep, or holds no repository. The tick records the same request.
- **The request.** It names the fix: publish or remove the work, or start the copy. Then a person resolves the request.
- **Until then.** The tick skips the group.

Any other refusal asks nobody, and the next tick tries again. Such a refusal is a copy read that timed out or lost its Node, or a failed GitHub read of origin's task branch.

A copy that the tick could not remove stays with its group until a later tick:

- **A failed destroy.** The group is `completed` and still names the copy. The next tick completes it again, which admits a new destroy.
- **A refused destroy.** A destroy that could not be admitted is retried the same way.
- **Repeats.** A crash between the record and the complete leaves `merged` recorded. The next tick completes the group without reading the pull request again.

#### Trusted GitHub feedback

The read APIs retain stored review observations and findings. Reading them authorizes no turn, push or merge.

#### Inspect approval observations

`task:github-reviews` reports stored observations. It does not grant merge authority or complete a group.

### Settle metrics

The board retains recorded lifecycle times, PR identity, token usage and line changes. Thread and workspace observations refresh recorded metrics. No metric triggers work.

### Lifecycle times

`started_at`, `settled_at`, `completed_at` and `merged_at` describe recorded task history. Swamp records lifecycle transitions through the existing APIs. Task insights read those records. A subtask's `started_at` is set when its first turn in a task copy moves it from `todo` to `running`, and a later turn keeps it; its duration counts from then. Its `settled_at` is set when it first becomes `completed`, `failed` or `cancelled`, by any path, and its duration ends there. A reopen to `running` clears `settled_at`, so its duration runs again until it ends again. Conn reads these metrics in its [Tasks panel](/reference/conn#tasks-panel).

### Tokens and line diff

Task operations record thread usage and nullable cost. Missing metrics keep the last recorded values. Each thread counts once in group totals; subtask tokens are the usage added by its turns. A migration clears legacy subtask totals copied from cumulative thread usage to null (unknown). New turns record deltas from that point; earlier totals are not rebuilt. List and detail reads use the same totals and recorded lifecycle times, including finished groups. Workspace observations retain complete line counts; missing, stale or truncated observations keep the stored counts. Reads never refresh a Node. Records remain available after a workspace is removed.

### Thread token metrics

The agent driver reports input, output and cached token usage on each thread. Group metrics aggregate those observations.

## Ready and merge a task pull request

**Partly built.** Stage 4 of the [command development pipeline](/reference/command-development-pipeline#4-pr-and-ci) ends with two steps on the published pull request of an external group. Each is a keyed, native Swamp operation with a chart. The Gateway admits and names the vault key; each step mints its own token; Swamp calls GitHub. Neither step has an `orbit` command yet. Call the API or the MCP tools `task-pr-ready` and `task-pr-merge`. Both need Gateway access, as the other task operations do.

The Gateway reads both steps' public receipts from the native `command/shell` `result` artifact by its recorded data ID and version when the output is not inline.

Both steps hold the group execution lock and refuse a second task on the group with `operation.target_busy`. A step that fails records an error code and changes no group state. A repeat with a new key starts a new task with a new token. The charts [`tasks/pr-ready`](/cli/task/pr-ready) and [`tasks/pr-merge`](/cli/task/pr-merge) show the steps.

### Ready step

`POST /api/v1/tasks/{task}/pr-ready` takes `{head_sha, mode}` and an `Idempotency-Key`. `mode` is `manual` or `automatic`. Admission reads GitHub with read-only App tokens and requires:

- an open external group whose published pull request is open, with a head that is exactly `head_sha`
- a pass of the Project's merge check, normally `Required checks`, on that head
- in manual mode, a pull request whose author is not the reviewer

The check follows the rule of the [review-and-merge gate](#handoff-ci): a missing, pending or failed run refuses. GitHub refuses a review request from the author of the pull request, so the author check refuses early.

The Gateway then records `tasks.pr_ready` with the full intent and the vault key `pr-ready-write-<uuid>`. It mints no token. The first step of the run asks the Gateway for a token with only `pull_requests: write` (`operation:direct:github-token`, `purpose: pull_request`), so the token is fresh when the step uses it.

Swamp takes the pull request out of draft with the GraphQL `markPullRequestReadyForReview` mutation. In manual mode it also requests the review of `task_pull_requests.reviewer` (`nckrtl`). If the pull request is already out of draft, Swamp skips the first step, so a rerun after a refused review request is safe. The result holds the pull request number, head, draft flag and requested reviewers.

### Merge step

`POST /api/v1/tasks/{task}/pr-merge` takes `{head_sha}` and an `Idempotency-Key`. Admission makes the same reads as the ready step and requires the pull request to be out of draft. It then checks the manual gate.

The gate is the reviewer's latest review that approves, requests changes or is dismissed. It passes only when that review is an approval whose commit is exactly `head_sha`. A comment changes no decision, and an approval of an older head does not count. A failed gate refuses with `tasks_pr.approval_missing`, and `error.details.state` is `waiting`, `changes_requested` or `head_moved`. The Gateway has no webhooks, so a caller that polls the command sees the approval. A refusal starts no task.

After the gate, the Gateway records `tasks.pr_merge` with the vault key `pr-merge-write-<uuid>`. The first step of the run asks for a token with `contents: write` and `pull_requests: write`. An approval can take longer than the hour that a token lives, so the merge never reuses the ready token.

Swamp reads nothing before the merge: admission read the head, and the squash merge carries the expected `sha`, so GitHub refuses a head that moved in the seconds between. If the head moved or the pull request conflicts with the base branch, the merge is refused with `tasks_pr.merge_refused` and the pull request stays open. The author rebases, and the gate starts again. There is no merge queue and no GitHub auto-merge. The result holds the pull request number, head and merge commit.

The ready and merge steps end with the shared child `task-github-cleanup`. It revokes the token when the mint step succeeded, as a best effort, and removes the private GitHub CLI configuration directory of the run.

### Gate check

The Gateway scheduler runs `task:pr-gate` every minute while the Tasks extension is on. The command only reads GitHub and admits steps; it changes nothing on GitHub, and Swamp steps never call back to the Gateway. It looks at each open external group that has a published pull request and belongs to a Project with [review and merge](/reference/projects#review-and-merge) enabled. For each one it reads the pull request with a read-only App token and then:

- A pull request in draft gets the ready step in the review mode of the group. Admission requires a green merge check on the head.
- The ready step also requires a passed handoff check of the group on that commit. A draft without one stays a draft, whatever its reviews say.
- A manual group with a pull request out of draft gets the merge step. Admission requires the reviewer's latest review to approve the exact head, and the merge check to be green.
- An automatic group with a pull request out of draft switches to manual mode, and the ready step runs again and asks the reviewer. The pull request then waits for the approval of the head.

The review rounds of automatic mode, and the build and the corrections of a prep group, ran on a task VM. They are removed with the Pi task loop ([old paths](/reference/old-paths#the-pi-task-loop)), so the gate check starts none of them. An old prep group's draft is a draft like any other.

An old passed round passes no head: the merge of a group that was in automatic mode needs the reviewer's approval of the head. The rows of the old rounds stay in `task_review_rounds` as history, and nothing reads them. A task copy's pull request is skipped before any of this ([no merge](#no-merge)).

A pending or red check, a missing approval, a busy group, a moved head or a failed read is a quiet skip, and the next tick reads again.

Every admission carries the Idempotency-Key `pr-gate:<step>:<group>:<head>`, so a repeat tick on the same head replays the recorded step and starts nothing. A step that failed is not tried again on the same head. A push moves the head and the check starts again, or the operator calls the step by hand with a new key. The gate sets the key with `OperationIdempotency::under`, so the key never outlives its admission.

The command prints one JSON object with a result for each group: `admitted`, `replayed`, or `skipped` with the refusal code. The scheduler log keeps these results.

A group is in manual mode unless `orbit task:create --review=automatic` created it. Automatic mode has no review rounds any more: the gate check switches such a group to manual mode when its pull request leaves draft.

A group whose workspace is a task copy is skipped with `tasks.copy_pr_no_merge`, as [No merge](#no-merge) describes.

### Not built

These parts of stage 4 do not exist yet:

- An automatic gate on copies: a fresh self-review agent instead of the reviewer's approval.
- An `orbit` command for each step, and a proof case for each step.

Until the App opens the pull request, its author is `nckrtl`. GitHub refuses `nckrtl` as its reviewer, and an author cannot approve their own pull request. Manual mode therefore needs another reviewer login, or the App as author.

## Review and merge

The Gateway retains existing Project settings and review records for the board. Swamp owns incoming PRs, final-review tasks, approval and merge. Swamp owns any workflow that uses those records. A Project's review-and-merge setting does not prevent it from creating an external group.

Review and merge never merges the pull request of a group whose workspace is a task copy ([No merge](#no-merge)).

## Web task board

**Tasks** in the web navigation shows every task on a board with Backlog, Todo, In progress, and Done lanes. The [insights strip](#on-the-board) sits above the lanes. In progress holds `reserved`, `running`, `reviewing`, and `settling` tasks. Done holds `completed`, `failed`, and `cancelled` tasks with their outcome visible.

The task board and the subtasks board hide lanes with no cards. The remaining lanes share the width. An empty task board says "No tasks yet." An empty subtasks board says "No subtasks yet."

Each card shows the Project code and the task id, such as `ORB-13`, its line counts, its status, and its duration. A card for a task that asks for direction says `Needs your direction`. A card for a task that asks because of a failure says `Needs attention`. A task page shows the brief, the metrics, a board of its subtasks, and an Agents section. When the task asks for direction, its page shows the question first. A subtask page shows that subtask's implementer and reviewer. The board is read-only. The [web app](/reference/web-app#live-tasks) keeps it current from task events.

## Task insights

The task board shows an insights strip above its lanes. It answers one question: does a change to the task pipeline make it better or worse? `GET /api/v1/task-insights` returns the numbers, with `period` set to `7d` or `30d` (default `30d`) and an optional `project_id`. Node access is the same as for `task:list`. The web app is the only client, so the route has no CLI command.

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
| `pr_first_pass` | Merged tasks with no fixup subtask, from a [final review](#review-a-subtask) or from [settling](#settling), divided by `merged` |
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
- `breakdowns`: `tasks`, `merged`, `tokens_per_merged_pr`, `lead_time_ms`, and `yield` for each model, each Project, and each [Gateway release](/reference/gateway-recovery).
- A task's model is the model of its first implementer thread. A task without one counts as `Unknown`.
- A task's release is the last verified release that began before its `started_at`.

### On the board

The strip shows tokens per merged PR with its cost, lead time, yield, first-pass pull requests, and wasted tokens. Each shows its change against the previous period, green when better and red when worse. Fewer tokens, a shorter lead time, and less waste are better. A higher yield and first-pass share are better. The strip has a 7-day and 30-day switch and a Project filter.

**More** opens the rest: the weekly trend, the daily outcomes, the lead time split into queue, work, and merge wait, the other numbers above, and the breakdown table. The browser remembers whether the strip is open. The strip shows on the Tasks board only, not on an Instance's board.

## Agent viewer

Task state reads answer from recorded Gateway state: `task:show`, `task:list`, `task:agent:list`, `task:agent:show`, insights, and the web board. Reads never contact a task VM or a Node. A parked or deleted VM does not change that rule. The Swamp run view may read recorded runs from Swamp, the trusted control plane.

The Agents section lists started threads and their recorded metrics. Any unfinished Operation that holds a thread makes that thread `working`, even if its last completed turn was `done`. A terminal projection records the final state and clears an old observation error.

### Read a thread

`GET /api/v1/tasks/{task}/agents/{session}` returns one recorded transcript snapshot. The CLI command [`task:agent:show`](/cli/task/agent-show) and MCP tool `task-agent-show` use it. The SSE endpoint sends the same snapshot and ends. Reconnecting reads the latest recorded snapshot. `after_sequence` is accepted but does not request a live Pi read; `cursor` is null and `kind` is `snapshot`.

Swamp records a bounded transcript on turn finalization after success, failure, disconnect or EOF. It keeps user and assistant message text, redacted before any bound applies. Tool entries keep only the name, status and duration when known. Tool arguments, outputs and raw errors are not kept; a failed tool says `tool failed: <class>`, where the class is one of `timeout`, `aborted`, `exit_nonzero`, `permission`, `not_found` or `error`. Assistant diagnostics are omitted. Structural fields are never redacted. Missing terminal usage stays unknown. Native cancellation skips finalization and keeps the previous recorded transcript.

Redaction of message text is best effort. A model can write a secret in any shape, so no redactor is complete. Tool arguments and outputs are never stored. Message text loses known secret values and credential shapes: PEM blocks, JWTs, bearer and GitHub tokens, and URL credentials. It also loses the value of a secret-named field in common JSON, `key: value`, `key=value` and quoted forms. HTTP `Authorization` and `Cookie` lines lose their whole value. A field name is secret by the Gateway's secret-word rule, which includes session identifiers. Swamp mirrors that rule in `swamp/extensions/behaviors/_lib/public_output.ts`.

Swamp classifies each distinct field name once per text capture. Repeated names reuse that classification; each value still follows its own quoting, header and numeric-metric rules. The classification is discarded after the capture.

These forms are known to pass unredacted:

- JSON with a line break between a key and its value.
- YAML block scalars, such as `password: |`.
- TOML and Python triple-quoted strings.
- Field names inside an escaped container quote.
- Unicode-escaped or percent-encoded field names.
- `.env` values `true`, `false` and `null`, such as `PASSWORD=true`.


Each child turn records its transcript when it ends, while its parent operation can keep running. During a running turn, readers see the last recorded transcript and current recorded activity state. There is no live transcript viewer. Before the first transcript arrives, readers get an empty snapshot. The response keeps the normalized `entries`, state, errors, and token metrics. `archived` means a stored transcript exists; it does not mean a turn cannot run again.

A stored T3 thread keeps its metrics. Its transcript returns HTTP 409 `tasks.agent_transcript_unavailable`. A thread of another task returns 404. Neither error contacts the original Node.

## Cancel a stuck task

`task:cancel` ends a task in any status except `completed`, and except `settling` with a `pr_url`. Those return HTTP 409 `tasks.not_cancellable`. Complete a settling task instead. `watched_pr_url` does not make the task published, so a `running` or `reviewing` task stays cancellable.

Cancel is admitted under the group execution lock. A busy group returns HTTP 409 with error code `tasks.workspace_waiting` and leaves the group and subtask statuses unchanged. The caller retries.

An admitted cancel answers HTTP 202 with its `tasks.cancel` Operation. A task with a [task copy](#task-copy) ends through the copy's destroy, as [Task copy cleanup](#task-copy-cleanup) describes. Subtasks, comments, and thread links stay as history. Cancel does not stop the agent conversations.

A task without a task copy holds nothing to release: the task VMs and their release are removed. Admission marks it and its open subtasks `cancelled` and records the Operation as succeeded at once, with no run (R1). So the 202 already carries a succeeded Operation, and `task:show` reads `cancelled` from then on. The same holds for `tasks.maintenance_finish`. A task that still holds a workspace that is no task copy (a task VM workspace) refuses with HTTP 409 `tasks.workspace_ownership_refused`; complete it instead.

Cancel asks a running group Operation to stop. If it is still running, the call returns HTTP 409 `operation.target_busy` with its id and confirms the stop request. Run `task:cancel` again once that Operation has ended. A timed-out Operation whose run nothing has proven ended still holds the group, for up to an hour; the cancel then returns the same 409 before it admits the destroy of the task's copy.

- **Work only a task copy holds.** A cancel refuses a [task copy](#task-copy) with work that is not published. It returns HTTP 409 `tasks.copy_unpublished_work` with the counts and the content verdict `work`.
- **Published work.** [Published work of a copy](#published-work-of-a-copy) defines it. A succeeded `task:publish` put its commit and that commit's history on origin; they do not count either.
- **Squash-merged or hand-carried work.** Its content is on the default branch, so it does not count, whatever its commit SHAs.
- **Unread task copy.** A cancel refuses a copy it cannot read: HTTP 409 `tasks.copy_workspace_unknown`, with the reason.
- **No content read of the head.** The verdict is `work_unread`. Run `task:sync`, then cancel again.
- **Discard.** `discard: true` (CLI `--discard`) lets the cancel destroy that work; `--yes` never does. Complete needs no discard, because origin has the approved work.
- **Approved work not on origin.** Cancel and complete refuse with HTTP 409 `tasks.approved_work_unpublished` and change nothing. Run `task:publish` first.
- **Refused admission.** `tasks.not_cancellable` and `tasks.workspace_ownership_refused` change nothing.

Cancel and complete keep approved work by a precondition, never by a push of their own. The precondition applies while the task holds a workspace or has an approval. Origin's task branch must reach the latest approved commit. For a task copy, the copy's latest read may stand in: it is of the copy's current head, that head holds the approved commit, and the read found every committed path on the default branch. That commit is the latest commit Orbit reviewed (ADR 0203), or else the commit of the latest approval.

Only when GitHub answers that the branch does not exist and the task's pull request merged does that pull request's head stand in for the branch. Commits and refs are read with a `contents: read` token. An earlier approved commit that merged does not cover a later one. The refusal names the commit and the branch. Every other outcome refuses the same way, such as a failed GitHub read or a branch that is gone without a merged pull request.

Every refusal comes before the cancel stops running work.

When cancel marks the task `cancelled`, it follows the [ending rule](#assistance-and-resolution): only a removal failure may keep asking for assistance. It clears the open subtasks' requests and keeps their last reasons.

## Complete and cleanup

Swamp requests group completion or cancellation through the API. The Gateway stops admitted work and performs the requested workspace removal. A failed remote stop or removal preserves the owned workspace and error evidence for retry. Swamp schedules retries.

`task:complete` accepts a settling or waiting-for-review group. It can also complete a running or reviewing group when its watched PR has ended. A running or reviewing group with a task copy also completes when its work is already on the default branch and is not empty, such as work carried there by hand; [Published work of a copy](#published-work-of-a-copy) decides. An empty copy group needs a cancel. A refused complete answers `tasks.not_settling` with a `reason`: `status`, `pull_request_not_ended`, `work_unread`, `work_not_on_default` or `work_empty`.

Completion never destroys a copy that holds uncommitted, untracked, stashed or other-branch work. It reads the copy first, as a cancel does, and refuses with `tasks.copy_unpublished_work` and the read's counts, or `tasks.copy_workspace_unknown` when the copy cannot be read. Publish or remove that work, then complete again. Repeating completion on a completed group retries its attached workspace removal without a new read. A copy group whose watched PR merged completes by itself ([A copy group settles on merge](#a-copy-group-settles-on-merge)). An explicit cancellation or completion remains available even though automatic orchestration is removed.

Origin must already have the approved work, as [Cancel a stuck task](#cancel-a-stuck-task) describes. Cleanup does not choose new task work or merge a PR.

### Task copy cleanup

A task whose workspace is a [task copy](#task-copy) has no task VM. `task:complete` reads the copy as `task:cancel` does and refuses one that holds work that is not published ([Complete and cleanup](#complete-and-cleanup)). It then admits the copy's normal `instance.destroy` with force, as `task:cancel` does, marks the task `completed` and answers HTTP 200 with the task. An open destroy of the copy is reused. When the destroy succeeds, the task lets go of the copy and stays `completed`. When it fails, the task keeps the copy, and completing again admits a new destroy.

A cancel of a copy records the `tasks.cancel` Operation under the group's lock key and admits the destroy in one transaction, record first. An Operation that holds or takes the group lock refuses the record with `operation.target_busy`, and the transaction admits no destroy.

### Published work of a copy

One rule decides whether a task copy holds work that would be lost with it. It lives in one place: the content read at the end of the copy's `base` phase, in `swamp/ansible/tasks/task-instance-workspace-phase.yml`. [`task:sync`](/cli/task/sync) runs that phase with its minted read token, so the copy holds the current default head as `origin/<default>` when it reads. The sync records the read as `work` in its result.

- **Changed paths.** The read takes every path that HEAD changed since its merge base with the default head.
- **Kinds of change.** Adds, edits, deletes and mode changes count.
- **Renames.** A rename is a delete and an add.
- **Committed work.** The default head must hold the same content at each changed path. A commit that a succeeded `task:publish` of the group pushed counts too.
- **Commit SHAs do not matter.** Content is compared, so a squash-merged or hand-carried branch is published.
- **Empty commits.** A check's empty handoff commit adds nothing. So does a commit whose tree repeats published content.
- **Never published.** Uncommitted and untracked files, stashes, and commits that only another local branch holds always count.
- **Empty.** A copy whose HEAD changed no path since the merge base is `empty`: complete refuses it, and cancel ends it.

The read returns `work`: `state` (`clean` or `dirty`), `head`, `empty`, the count of differing `paths` with up to five names, the `uncommitted`, `untracked` and `stashes` counts, and `approved_held`. Cancel and complete use the latest sync's read only while it is of the copy's current head, which their own bounded read sees. A commit made after the sync needs a new sync. The copy never holds a GitHub credential: the base phase fetches on the Node, and the copy receives only a bundle.


A copy turn runs on the `t3` driver, which the driver registry does not hold. Its turn is its `tasks.copy_turn` Operation, which complete stops as it stops every running group Operation, so the subtask stop interrupts no `t3` thread.

### A task VM workspace

The task VMs and their release (`task_vm.slot_release`) are removed ([old paths](/reference/old-paths)). A task of that era that still holds its workspace Instance has no task copy. `task:complete` marks it `completed`, answers HTTP 200 and asks for `removal_failure` assistance: "This workspace is no task copy. Task VMs and their release are removed, so Orbit cannot remove it." The Instance record stays attached. `task:cancel` refuses such a task with `tasks.workspace_ownership_refused`.

## Configuration

These Gateway environment keys configure the extension.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_TASKS_COPY_IMPLEMENTER_PROVIDER`, `ORBIT_TASKS_COPY_IMPLEMENTER_MODEL`, `ORBIT_TASKS_COPY_IMPLEMENTER_EFFORT`, and the same three for `REVIEWER` | The Gateway's [copy turn agent](#copy-turn-agents) of each role, the last fallback after the task and the Project. Defaults: `claude`, `claude-opus-5-5`, and `medium` for the implementer or `high` for the reviewer |
| `ORBIT_TASKS_GITHUB_REVIEWERS` | Trusted reviewer account IDs per repository, `owner/repo:id,id;owner/repo:id`. Unset or empty trusts no one. See [Trusted GitHub feedback](#trusted-github-feedback) |
| `ORBIT_TASKS_REVIEW_REQUEST_LOGINS` | Comma-separated GitHub logins requested as reviewers when a task pull request is opened or reused. Unset or empty requests no one. The pull request author is skipped |
| `ORBIT_TASKS_PR_MODE` | The [mode](#task-group-modes) of a group whose group and Project set none: `review_before_pr` (default) or `pr_when_done`. Any other value is `tasks.pr_mode_setting_invalid` |
| `TYPESAFE_API_KEY` | The key for Jev calls |
| `TYPESAFE_URL`, `TYPESAFE_MODEL` | The TypeSafe endpoint, default `https://api.typesafe.ai/v1`, and the classification model, default `jev-latest` |

Problem suppression is not an environment key. The two lists live in `apps/gateway/config/orbit.php` under `problems`, beside `tasks`. [Suppression](#suppression) defines the match.

## Project-owned task policy

The engine knows the configured check, lifecycle steps, workspace routing, and typed deliverables. It does not select a task check, a fixup, or workspace routing by slug, package manager, manifest, or CI job name. A command may use any toolchain installed on the task Node. The rubric does not require a Composer script or inspect a manifest to judge the Project's check. It does not encode a docs-first workflow, an ADR rule, or a language or package manager, and it does not treat any Project slug as Orbit.

### No planner

Task create accepts no planner. There is no `plan` field, no planner thread, and no stored planner state. An external ADE plans and steers the work. Orbit runs the assigned work.

### Starting from the default checkout

A task starts from the Project's default container seed, with source, runtime and installed dependencies already present. It is not a linked host worktree and does not run checkout setup or select a VM dependency cache. [A task copy starts clean](/reference/development-containers#a-task-copy-starts-clean) owns branch selection and removal of uncommitted seed work.

### Routing and cleanup

Swamp owns routing and cleanup policy. The Gateway offers keyed primitives and records their results. It never infers a new lifecycle action from a timer, board update or resolution.

### The base-run limit

The base run is the exception that remains. It copies only installed `vendor` and `node_modules` directories into the start-commit archive, as [Prove a command fails on the start commit](#prove-a-command-fails-on-the-start-commit) describes. A Project whose command needs other installed dependencies cannot treat that base failure as a reproduction.

### No implicit task check

New Projects have no task check until one is configured, regardless of type. Existing stored checks remain unchanged. Shared instructions, reminders, the check runner, and pull request descriptions name only an explicit Project check; none supplies a fallback. Without a check, Orbit still verifies the tree and deliverables and requires review.

## Why it works this way

### Publish uses the checked commit and trusted Git

A raw commit would bypass the handoff check. Taking its check ID binds publication to the evidence and the commit recorded together. The workspace phases export that candidate to a trusted bare repository on 209 without lending the copy a GitHub token or loading its Git configuration. The import verifies that the bundle holds the admitted commit and tree.

An open-pull-request lookup lets a new admission reconcile a lost create reply. The orchestrator owns review gates and board states, so publication adds no brief coverage, reviewer request, or settling decision.

A push that started can reach GitHub even after its client stops. Native cancellation therefore does not prove that the remote branch stayed unchanged. A fresh admission pushes again without force: Git pushes nothing for an identical tip and refuses a divergent one. The recorded copy remains the group's workspace; publication adds no new ownership mechanism.

These reasons explain the design. Check them before you propose a change.

### External checks are their own operation and own their commit

A turn and its check are separate operations. The group's Operation lock makes the check wait for the turn to end. Detached check supervision gives every requested check one execution and output rule. A failed check is evidence for the orchestrator, so it is a result rather than an operation failure. A passed handoff commits the checked tree so later review and publication can name one recorded commit.

The one exception is a task copy whose clean `HEAD` an earlier check already recorded: that commit is the recorded commit, and the check makes no empty one ([check phases](/reference/task-instance-workspace#check-phases)).

The [hosted-CI prerequisite](#check-an-external-group) needs a fresh sanitized commit per operation because sanitizing a proposed message cannot remove skip directives inherited from an old commit. Recovery must retain that same commit, or a lost reply would create extra commits and pushes. Pinning the CI plan prevents a Project edit from changing what passed. Reviewer and publish admission share one predicate because a local pass cannot prove hosted CI completed; the owning successful operation supplies that evidence without a duplicate check-status schema.

### An external turn records evidence without choosing the next step

The orchestrator owns the prompt, gates, budgets, and assistance. The turn operation only sends, waits, and records evidence. Applying a blocked receipt inside Orbit would create a second lifecycle owner. Durable owns restart resume and provider retries, so the Gateway never starts a replacement turn for them. Reviewers reset to the passed handoff commit to preserve what was checked. Consults keep the tree because it contains the implementer's work.

### Provision keeps effects separate from lifecycle decisions

An external orchestrator needs a usable task copy without starting a baseline or an agent. Provision shares the copy start path. One operation hold per group keeps later task effects from overlapping; it does not choose the next phase. Missing or stale seeds refuse rather than selecting an older runtime.

### An optional, generic extension

Tasks is an extension, so an operator can switch it off without a Gateway downgrade. The engine knows tasks, subtasks, deliverables, one task check, and a lifecycle. Each Project's own policy and task check decide how it plans and verifies work. Inferring policy from a slug, manifest, or CI job name would create a second hidden contract in the Gateway, so those choices belong to the Project. The ADE plans, because planning needs the conversation with you. A web form to create tasks would be a second path beside MCP and the API.

Three alternatives were rejected. Selecting one Project's behavior by its slug would keep a second task policy in the Gateway. Inferring that policy from repository files would hide it in the engine instead of the Project's skill and task check. A compatibility path for a planner thread was rejected, because you plan with an external ADE and the engine keeps no planner state.

Shared prompts stay free of Project policy. They do not name a feature contract or an Orbit lease rule. The repository's instructions and `orbit-tasks` skill carry that policy.

### Every task group owns its task copy

Each task group gets one [task copy](#task-copy). Agents and checks use that checkout; proofs use its nested fleet. Containers share their host kernel, so this is not a dedicated-kernel boundary. The copy has only the development credentials and resources its work needs.

### Backlog before Todo

A task needs an id before its branch `task-{id}` can hold the contract, and it must not run while that contract is written. So a task starts in Backlog and runs only when someone moves it to Todo. A draft flag on a Todo task would give one lifecycle fact two fields.

### A person starts a filed problem

Code can see that a failure came back. It cannot write the test that proves the bug. A model does not choose what to file. The thresholds are code. This serves [agents operate, humans steer](/mission#principles) and [deterministic first](/mission#principles).

The [outer loop](#outer-loop) files a Backlog draft. The reproduce subtask carries a review placeholder, and a person replaces it with a scoped `fails_on_base` command before the task can run. The filer cannot know the right test, so it never writes a whole-suite command such as `vendor/bin/pest`. Filing straight to Todo is rejected, because an agent would start before a person names the test. A whole-suite command is rejected, because a green or red result would not prove this failure.

One command can fail dozens of times in an hour. Counting each signal as a separate problem would fill the board.

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

Orbit cannot check prose, so a subtask names typed items. The check runs each command itself. The Gateway verifies against its own run, because the agent controls the workspace and could change a script that verified itself. A `file` path accepts a glob, including `{a,b}` or `{php}` alternation. A command's `paths` list is exact files, because a glob could match a file made to satisfy the base run.

### A command must fail on the start commit

A command that only passes on the fixed code does not prove it covers the bug. So a base run uses the start commit and adds only the files named in `paths`. It also copies installed `vendor` and `node_modules` directories so a Composer or JavaScript command can run, and it copies nothing else. A missing Python or Rust dependency tree is not proof that the bug existed on the start commit.

The base tree is an extracted archive in the check's container scratch, not a registered worktree that a killed run could leave attached. The [check phases](/reference/task-instance-workspace#check-phases) own its lifecycle. Exit 126 or 127 is not proof of a regression: the command did not run.

### Assistance is recorded in Activity

Each assistance change has an Activity entry, so an operator can trace the kind, question, and reason without an external notification service. Waiting on another task or pull request is not an assistance kind: a dependency wait that resumes on its own is a separate feature. The operator answers through the CLI, MCP, or API; a web answer box is outside this feature.

### Questions are records, not parsed comments

Each consult and direction request has a record with its answer and cause, while comments keep the conversation. Questions kept only in comment bodies would need free-text parsing before the operator could count them, group them by cause, or trace them to a brief. The records and the `questions` and `escalations` counts show where briefs, contracts, and subtask scopes need attention.

The reviewer chooses a cause from a fixed list when it hands off, because it already holds the contract and the answer. Asking a model to infer the cause later was rejected: the handoff has the evidence, and a fixed list avoids another model call and its cost. A cause stays empty until that reviewer receipt, so an open, escalated, or migrated question does not claim a diagnosis nobody has made.

### Transcripts outlive the task copy

A task copy is destroyed when its group ends, and its server sessions go with it. Swamp records redacted transcript evidence when each turn ends; the Gateway keeps it after destruction. Cleanup needs no live transcript read. Stored Pi transcripts remain readable without the old runtime.

### Recorded snapshots for all readers

JSON reads and the browser stream return the same stored snapshot and use the same sanitizer. Readers keep no connection to Pi. During a turn, activity comes from the recorded Operation; transcript updates arrive with the terminal result.

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

This section is history. Task VMs and the Pi task loop are removed, and every turn runs in a task copy on T3 ([Drivers](#drivers)).

The [old-paths ledger](/reference/old-paths#the-pi-task-loop) records the retirement. Current implementer and reviewer turns use the [copy turn agents](#copy-turn-agents); there is no Pi restart or publication path to operate.

### Jev only checks coverage

Code decides every fact that code can check. Jev answers only whether the change list covers each subtask, because the reviewer writes that list and code cannot compare prose. Every call is stored with its input and later labeled by rule from the merged pull request, so the checks can be measured without a second model.

### Swamp output stays a read

The Gateway reads Swamp's own run history rather than recording a second run ledger. This serves the Mission's lean and deterministic-first principles. Exact group inputs already identify maintenance-worker runs, so the driver needs no tagging change. Polling exposes the output that a read-only principal may see. Swamp's `run.attach` requires a `run` grant, so granting execution just to stream a read-only panel is rejected. A worker agent or a direct browser connection would add another credential and network boundary. The snapshot cache bounds repeated reads and preserves useful evidence during an outage without making Orbit a second orchestrator.


## Dependency batch squash admission

Dependency batches may use `POST /api/v1/tasks/{task}/dependency-merge` with a required Idempotency-Key and `{publish_operation_id, head_sha}`. Only the configured Services peer may admit it. The Gateway records a direct `tasks.dependency_merge` Operation on the Swamp configuration Node (209). Swamp owns the GitHub checks, dependency content and version policy, then runs the expected-head squash request. The Gateway records its bounded public result.

Admission holds the group execution lock and names two vault keys. It mints no token. The first step of the run asks the Gateway for two repository-scoped tokens (`purpose: dependency_merge`): Contents and Pull requests write, and Contents, Pull requests, Checks and Actions read. The Gateway writes them to the named keys. Native `gh api` commands read tokens from the environment. No other execution step reads credentials or reports results through a Gateway callback.

All Projects are disabled: `dependency_merge.projects` is empty and the private project extension's `dependencyAutoMerge` defaults to false. Enabling is a separate change after the live acceptance proof. Package and unclassified monorepo Projects are ineligible. Admission verifies the hosted production Instance, external batch group created by the authenticated Services Node, package deliverable, passed handoff check and exact successful publish receipt. Agent-repaired dependency batches use these same gates. Feature groups and unrelated repair groups have no authority through this endpoint.

Fresh GitHub reads must show an open non-draft same-repository `task-{group}` PR into the current Project and repository default branch. Require GitHub Actions' `Required checks` on the exact head. Every enabled policy must name `smoke_workflow`, an exact `.github/workflows/*.yml` or `.yaml` path. Its active workflow and `Production smoke / smoke` are mandatory: the raw `smoke` job must belong to that exact workflow's latest PR suite. Removing or renaming the workflow refuses the merge.

Missing, pending, skipped, failed, unreadable or superseded checks refuse the native merge. Admission freezes local lineage and policy. Fresh PR evidence checks the admitted head and base; the expected-head PUT refuses a head that moves after those reads. The GitHub write uses squash with the expected head SHA and a repository token with only Contents write and Pull requests write, never Workflows write.

Before the native write, complete paged PR-file evidence must match `changed_files` and contain only root `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`, `yarn.lock`, `bun.lock`. Both paths of a rename must qualify. Missing, truncated, duplicate or GitHub-capped (3,000 files) evidence refuses the merge. Workflow and code changes go through ordinary PR handling. Binary `bun.lockb` is excluded.

For each changed manifest, Swamp fetches base and head by commit SHA and compares decoded JSON. Only values of existing Composer `require`/`require-dev` or JavaScript `dependencies`/`devDependencies`/`optionalDependencies`/`peerDependencies` may differ. Dependency names must match the base map. Constraints must be registry semver ranges or npm dist-tags; URL, alias, local, Git and Composer dev/hash specifications refuse the merge. All other keys, including scripts, repositories, config, autoload, extra, overrides and resolutions, must match. Invalid JSON, duplicate keys, deleted manifests and unreadable content refuse the merge.

Swamp checks lock sources and package identities before execution. Composer package, dist and source entries accept only known keys, with no mirrors or transport options and only Packagist's notification URL. Every package must match its version's fresh Packagist P2 metadata for dist, source, type, autoload, extra and bin. Unavailable Packagist evidence refuses the merge. Composer archives use HTTPS on `repo.packagist.org`, `api.github.com` or `codeload.github.com`; optional Git source metadata uses HTTPS on `github.com`.

JavaScript artifacts use HTTPS on `registry.npmjs.org`, including implicit npm-registry sources in pnpm and text Bun locks. Local, link, Git-protocol, credential-bearing and unknown-host sources refuse the merge. npm tarballs must match the installed name and version exactly and include integrity. pnpm tarball overrides are forbidden. Bun requires implicit registry sources and a name matching its package key. Supported locks are Composer JSON, npm v2/v3 JSON, pnpm v9 YAML and Bun v1/v2 text JSONC. Unsupported formats, including Yarn, require the ordinary PR path.

Every locked package must be reachable from the root manifest. Composer walks its Packagist-attested require, replace and provide maps. JavaScript uses the existing strict graph readers; npm, pnpm and Bun additionally refuse package names absent from the base lock. Composer may resolve a virtual requirement only through a provider present in the base lock; a real base package cannot become a virtual requirement in the head. pnpm snapshot edges must use plain versions, never package aliases. Composer lock root keys are closed; aliases and platform overrides are refused.

A range-to-dist-tag change is refused. Archive names are not dist-tags. A constraint that crosses or adds a breaking semver boundary, or a locked top-level package that crosses one, refuses with `dependency_merge.major_bump`. The boundary is the leftmost non-zero component: major for 1.x and above, minor for 0.x, and patch for 0.0.x. Its PR stays open and its development Instance stays active for hand-testing. Agent repair does not waive this rule.

Native reads for repository settings, changed files, workflow definitions, exact-head workflow runs and check runs run in parallel after the initial PR read. Native `forEach` steps fetch exact contents and fresh Packagist P2 records. TypeScript only parses these private replies and applies dependency policy. GitHub replies and decoded repository contents stay in a private scratch directory; native data retains public request plans and the merge receipt.

Both tokens are revoked on success and failure by native cleanup steps. Revocation is best effort because tokens expire within one hour. Cleanup removes worker scratch, and a native assert confirms the merge result and cleanup. The Gateway's shared terminal reader projects the exact head and merge SHA, or a bounded refusal code, from one native history read.

A lost merge reply fails the operation. A fresh admission for the same PR and head mints new tokens and reads the PR again. If the PR was merged at the admitted head, the read returns its merge SHA and skips further reads and the PUT. Start a new request after cleanup; do not resume a run whose private replies and credentials were removed. No retry selects a new head or a new PR. The ordinary task factory observes the merge and owns completion and cleanup. This path neither deploys nor changes task, access or production controls.

## Native Pi turns

Pi turns on task VMs (`tasks.turn`) are removed with the Pi task loop, with their workflow. A turn runs in a task copy as [`tasks.copy_turn`](/cli/task/turn#in-a-task-copy).

### Internal container workspace phases

[Task Instance workspace phases](/reference/task-instance-workspace) define the internal evidence and export contract. `tasks.check` uses their [check phases](/reference/task-instance-workspace#check-phases) when a group owns a container Instance workspace: a [task copy](#task-copy).
