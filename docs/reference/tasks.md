---
title: "Tasks"
description: "How the optional Gateway Tasks extension runs tasks. It covers the model, the lifecycle, typed deliverables, the task check, agent threads, review, the pull request, fixups, metrics, cleanup, and the outer loop that files recurring problems."
covers:
  - "apps/gateway/{app/{Data/Tasks/**,Domain/{Tasks,Problems}/**,Infrastructure/Tasks/**,Support/Percentile.php},config/{swamp,task_pull_requests}.php}"
  - "apps/gateway/app/{Actions/Tasks/**,Jobs/{PublishTaskVmCommit.php,Problems/**}}"
  - "apps/gateway/app/Http/{Requests/Tasks/**,Controllers/Api/{TasksController,TaskGroupsController,TaskPullRequestController,TaskInsightsController,TaskSwampRunsController,AgentThreadsController,TaskQuestionsController}.php}"
  - "apps/gateway/app/Console/Commands/{TaskPullRequestGateCommand,CollectT3MetricsCommand,CollectProblemsCommand,FileProblemsCommand,ArchiveTaskThreadsCommand}.php"
  - "apps/gateway/app/Models/{Task,TaskComment,TaskCheck,TaskQuestion,TaskGitHubReviewConsumption,TaskGitHubReviewObservation,TaskReviewedCommit,TaskReviewRound,AgentThread,JevDecision,ProblemFingerprint,ProblemCollectorState}.php"
  - "{apps/gateway/resources/tasks/**,swamp/extensions/behaviors/operations/{task-repository/{program,provision,assets,lifecycle,publication,check}.ts,tasks/{publish,transcript}.ts,dependency-merge/**},swamp/workflows/{workflow-operation-dependency-merge,workflow-operation-task-provision,workflow-operation-task-publish,workflow-operation-task-idle-save,workflow-operation-task-pr-ready,workflow-operation-task-pr-merge,workflow-operation-task-review-round,workflow-operation-task-check,workflow-task-check-ci}.yaml,swamp/extensions/behaviors/_lib/public_output.ts}"
  - apps/docs/app/{Documentation/DocsImpact.php,Console/Commands/DocsImpactCommand.php}
  - "apps/gateway/database/migrations/{2026_12_03_000000_allow_cold_recipe_image_rebuilds,2026_12_31_000000_add_task_vm_publish_error_code,2027_01_04_000000_add_task_insight_fields,2027_01_18_000000_create_task_review_rounds_table,2027_01_20_000000_keep_review_mode_off_subtasks,2027_01_22_000000_run_review_round_as_one_operation,2027_01_31_000000_clear_copied_subtask_token_totals,2027_02_02_000001_add_task_idle_save}.php"
---

# Tasks

Swamp is the only executor of task groups; it runs provision, agent turns, checks, publication, review parking and cleanup as keyed operations. The Gateway stores the board and evidence and exposes keyed operations; it never claims a group, advances a subtask, retries a turn, appends a fixup or merges a PR on a timer. All groups use `external` orchestration, including groups created without an explicit mode. Existing records move to that mode without changing their status, workspace, assistance or receipts.

Tasks is an optional Gateway extension. It runs planned work with coding agents. Task VMs are [off in the live fleet](/reference/old-paths#off-in-the-live-fleet): no task VM starts today. Tasks move to copies of a container Instance. A task is one feature or bug fix, delivered as one pull request. Its subtasks run in order in one shared task workspace. A fresh implementer builds each subtask, the Project's task check and CI verify the handoff, and a fresh reviewer approves it. Orbit commits and pushes the work. After the last approval, Orbit opens the pull request and watches it until it merges.

While a subtask is open, Orbit also watches a pull request on `task-{id}`. It stops starting subtasks when that pull request merges or closes.

A Project can opt in to [review and merge](#review-and-merge). Orbit then reviews the whole branch before it pushes anything for review, reviews the pull requests that listed authors open, and merges a reviewed head when CI passes.

The engine is generic. Your agentic development environment (ADE) plans and steers the work. Orbit runs it. Each Project keeps its own task policy in its repository, as an `orbit-tasks` skill under `.agents/skills/` and in its other instructions, and enforces it through its own task check. Agents read that policy from the repository, not from the shared prompts. The Orbit repository keeps its policy in the [orbit-tasks skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/orbit-tasks/SKILL.md) and the [contributor guide](/contributor-guide).

Agents use the Tasks tools of the [MCP server](/reference/mcp). The [`task` CLI family](/cli/task) runs the same operations from a terminal. There is no web UI to create or change a task. The API, the CLI, and the web app call a child task a subtask.

A [Project removal](/reference/projects#remove-a-project) deletes its task groups and their dependent records only when every group is `cancelled` or `completed`. Any other group status, including `failed`, blocks removal with HTTP 409 and `project.has_task_groups`. Every associated task VM row must also be `destroyed`. A VM in `provisioning`, `ready`, `parked`, `destroying`, or `failed` returns HTTP 409 and `project.task_vm_not_destroyed`, with its name and state in the message. Wait until destruction finishes before retrying. Both refusals leave all records unchanged; only `destroyed` VM rows are deleted with the Project.


## Extension switch and status

Enable and disable the extension with `orbit extension:enable tasks` and `orbit extension:disable tasks`. Both need Gateway access. While the switch is off, the `tasks` commands, MCP tools, and web pages are hidden, except `task:status` and the `task-status` tool. Every other task operation refuses with HTTP 409 `extension.disabled` and changes nothing. Stored tasks and subtasks stay. [`extension`](/cli/extension) describes the switch.

`task:status` is an assistance and status view, not a switch. Its route returns `enabled`, `assistance`, `last_tick_at` (always null), `merges`, and `capacity`. `merges` lists each open task whose pull request Orbit reviews and merges: a task with a `pr_url` in a review-and-merge Project, or a task that reviews an incoming pull request. An open task without a pull request, such as one in `backlog`, is absent. `capacity` is described in [Task places](#task-places).

`assistance` lists every task whose `assistance_requested` is true, in ascending task id order. Each entry has `id`, `project_id`, `project`, `project_code`, `title`, `status`, `assistance_kind`, `assistance_question`, and `assistance_reason`. An ended task asks only for `removal_failure` assistance. A task that is not asking is absent, even when it still stores an old reason. A subtask's assistance flag does not add its parent task to the list unless the parent also requests assistance. The view remains available while tasks is disabled.

## Model

A task is one row in the `tasks` table. A row with no `parent_id` is a top-level task: the Tasks board shows it, and it is one feature or bug fix delivered as one pull request. A row with `parent_id` is a subtask of that parent. A subtask has no children.

A top-level task holds the task workspace, the branch, the reviewed pull request, the watched pull request, the current reviewer thread, and the settle metrics. A subtask holds its position, its deliverables, its implementer thread, its task checks, and its turn receipts. Both levels store a title, a brief, a status, assistance, comments, and metrics. The Gateway rejects a value in a column that the level does not use.

| Field | Level | Meaning |
| --- | --- | --- |
| `title`, `brief` | both | Short name, and the goal and acceptance |
| `status` | both | Lifecycle state |
| `orchestration` | task | `external` (the default and only value). Create sets it; it never changes |
| `current_recipe` | task | Whether the task needs the current machine recipe. Create sets it, default `false`. When `true`, provision never starts its VM from a [fallback image](/reference/compute-drivers#image-fallback) and waits for the current recipe image |
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
| `implementer_model`, `reviewer_model` | task | The models used for the task's threads |
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
| `vm_power` | task | [VM power](/reference/compute-drivers#vm-power), separate from task status: `running`, `stopped`, `destroyed`, or `null` |
| `ready_at`, `ended_at`, `merged_at` | task | [Lifecycle times](#lifecycle-times): when the task first entered `todo`, when it ended, and when GitHub merged its pull request |
| `tokens`, `line_diff`, `lines_added`, `lines_deleted`, `duration_ms` | both | [Settle metrics](#settle-metrics): distinct-thread tokens, per-subtask usage deltas, recorded whole-branch line counts, and duration |

Clearing the assistance flag can keep the last reason.

Typed comments record the workflow. A stored turn receipt is a comment whose type is its outcome: `ready_for_review`, `blocked`, `changes_requested`, or `approved`. An operator posts `assistance_requested` and `resolution` comments. Each comment keeps its full body, author, time, and attempt. An approval that Orbit committed carries `commit_sha`. The approval of the last subtask also carries `pull_request`: the summary, changes, and breaking changes it proposed.

`GET /api/v1/tasks/{task}/comments` and the MCP tool `task-comment-list` accept optional `type` and `limit` query inputs. `type` must be a task comment type, such as `resolution` or `assistance_requested`. `limit` must be an integer from 1 to 100. The endpoint returns comments newest first, filters by type before applying the limit, and preserves each comment's full body and response shape. With neither input, it returns all comments as before. An unknown type or an invalid limit returns HTTP 422 with `validation.failed`. Use `type=resolution` and `limit=1` to read the newest resolution without returning the full comment history; the limit bounds the number of comments, not the byte size of an individual body.

`GET /api/v1/tasks` and `GET /api/v1/tasks/{task}`, and their MCP tools `task-list` and `task-show`, accept an optional boolean `compact` query input. With `compact=true`, each group omits `brief`, `assistance_question`, and `assistance_reason`; each subtask omits those fields plus `completion_summary`, `deliverables`, and `fixup_problem`. The latest check keeps its metadata but omits `output`. Omitted keys are absent, not null. Ids, titles, statuses, positions, assistance flags and kinds, and counters remain available. Without `compact`, or with `compact=false`, the response is unchanged. Invalid boolean input returns HTTP 422 with `validation.failed`. Use compact reads to avoid returning long text in the MCP output; this reduces the response size but does not impose a byte limit or paginate the results.

### External orchestration

Every task group is external. Swamp owns its lifecycle. The Gateway rejects non-empty task topologies with HTTP 422 `tasks.orchestration_unsupported`; the existing Project review-and-merge setting does not block admission. Only explicit keyed operations start work. Group and subtask status updates record board state without starting an agent, check or cleanup.

#### Release a task VM for review

Publishing a task PR releases its VM, workspace, Node identity, keys and VPN peer. The group waits for review without holding a place. An [idle](#release-an-idle-task-vm) VM goes too. A build, fix turn or review round claims a task place again and restores the task branch on a fresh copy of the base. Full pools wait for up to two hours. See the [capacity model](/reference/command-development-pipeline#capacity-ideal-state).

#### Provision an external group

`task:start <group>` starts the Gateway operation `tasks.provision` through `POST /api/v1/tasks/{task}/start`. Its target is `task_group` with the group ID. Admission locks the group row while it captures the intent. It holds no Gateway execution lease or target hold. An ended group returns HTTP 409 `tasks.group_closed`. The Gateway retries no effect for an external group; the orchestrator decides whether to try new work.

Task work and operator proofs claim task places. Authenticated CI proofs claim CI places. Proofs of a refreshed base use that pool too. Each VM holds one place until deletion is confirmed. Full pools record a waiting Operation without a workspace or identity.

A task VM serves only the proof Project: the base VM carries only its checkout. Work that needs a new VM for a group of another Project, such as provision, prep or a build, returns HTTP 409 `task_vm.project_unsupported` at once and records no Operation. A waiting Operation that reaches that check fails with the same code. Freed places cannot change that, so such a group never joins the wait queue, where it would hold up every later admission.

A group of a Project with [`nested_fleet`](/reference/projects#nested-fleet-setting) on is refused the same way, with HTTP 503 `operation.kind_unavailable`, by the task-workspace entry of the [placement rule](/reference/projects#nested-fleet-setting). This covers every admission that needs a new workspace: provision, prep, build, turn, prep fix, review round and operator or CI proofs. Orbit records no place, identity, workspace, wait or Operation. A waiting Operation that reaches its claim later fails with the same code. A nested-fleet Instance container gets its own Swamp Club key at each start, never the proof club key; its Operation's result says what that start did with it ([Club key of the nested Gateway](/reference/development-containers#the-club-key-of-the-nested-gateway)).

Task provision, prep, build and review leave each host’s [CI/refresh reserve](/reference/compute-drivers#place-task-vms) free. Operator proofs do too. Only a command proof from an authenticated active Node with an active `ci-runner` role, or a refresh candidate proof, can use it. Orbit records CI eligibility before queueing admission; report-only mode does not grant access. Another proof cannot preempt CI.

Provision requires `todo`. A backlog group returns HTTP 409 `tasks.not_todo`; a claimed group returns HTTP 409 `tasks.already_claimed`.

A run that provisions its own VM as one step can pin the VM to one task VM host. The [refresh of a host's base snapshot](/reference/compute-drivers#refresh-the-base-snapshot) does: its proof start goes to that host only and copies the base snapshot the host promoted last. It takes a VM CI place, and a rebuild from stock withdraws only the host's VM base. Task container places stay out of task and proof placement until the [switch](/reference/compute-drivers#place-task-vms).

Admission pins the commit the workspace starts from, so a group never starts on a red commit. Before its transaction, the Gateway walks the first-parent history of the Project's default branch from its head through the [GitHub App](/reference/github-app). It pins the newest commit whose Project `merge_check` passed on exactly that commit, by the same rule as the [review-and-merge gate](#handoff-ci): every run of that name comes from GitHub Actions and completed with `success`. It examines at most 20 commits, the bound of [automatic deploy source reads](/reference/deployments).

A Project without a `merge_check` has no gate, so admission pins the branch head, as an automatic deploy does. Admission never falls back to the head of a gated Project:

| Error code | HTTP | When |
| --- | --- | --- |
| `tasks.no_green_commit` | 409 | No first-parent commit passed the `merge_check` in the walk. The walk stops after 20 commits or where the branch listing ends. Provision again after a commit turns green. |
| `tasks.source_unreadable` | 409 | The Project has a `merge_check` but does not read a github.com repository through the GitHub App. |
| `tasks.source_unavailable` | 502 | GitHub, the App installation, or the branch head could not be read. |

A refusal leaves the group unchanged and records no VM, workspace or Operation. The intent carries the commit as `workspace.start_commit`.

The Gateway records one `tasks.provision` intent and starts one native Swamp run. Its admission transaction captures VM placement, the exact guest Node, enrollment and workspace metadata. It starts no child VM operation. Swamp composes the canonical Task VM provision child for provider creation, bootstrap, WireGuard publication and Pi runtime preparation. Plain Task VMs have no Swamp worker. No PHP executor or waiting-read loop runs these effects.

A task with a topology also carries a `converge` input, outside the intent. The step `Converge once` runs it on the outer VM before the nested fleet starts. The VM provider takes the intent whole in one argument, so the large converge intent stays out of it. A task without a topology has no `converge` input. The two steps stay skipped, and their inputs take an empty object (written `{} }`, with a space, because `}}` ends the expression), because Swamp evaluates the inputs of a skipped step.

The [writes audit](/reference/operations) also checks custom behavior and model methods. Its reviewed native-data methods live in `nativeDataMethods` in `swamp/tests/writes-reviewed.json`.

The run's main steps are `Create and prepare the VM`, `Set up the workspace`, `Accept the source`, `Check the workspace` and `Accept the workspace`. A failed step of the checkout fails the run with its own message, stderr included, and nothing after it starts. The checkout steps are the connection, the control folder, the clone credential, the clone, the bundle and its copy, the import, the origin, the task branch, the identity, the Route's access rows, the inspection and the composer read. The clone, the import, the task branch and the Project setup declare `writes: true`, so Swamp never runs one of them twice after a worker loses it.

The profile, the dependency files, the Project setup, the copy of its output and the proof club report in the receipt as `error_code` and `failed_step`. `Accept the source` fails the run on that receipt before the converge, the fleet and the Route start. A failed step keeps no stdout, so the Gateway reads the same receipt from the workspace step, and the failed Operation keeps the receipt's error code, such as `app-dev.php_version_unsupported`. When the workspace step itself fails, `Check the workspace` names `source` as the failed step if it has no receipt.

When the workspace has a Route or Process definitions, three steps run between setup and the check. `Bind the workspace keys` puts the guest's pinned host key into the captured children; `Route the workspace` runs the `operation-route-create` child. It runs between `Hold the Route Nodes` and `Release the Route Nodes`, which hold the Route-serving Nodes for that step only ([Coordinate publication](/reference/routes#coordinate-publication)). A hold that stays busy for two minutes fails the receipt with `failed_step` `route`. `Create, start Processes` runs one `operation-process-create` child per definition. The check merges their outcomes into the receipt; a failed child sets `tasks.provision_failed` with `failed_step` `bind`, `route` or `processes`. `Accept the workspace` fails the run on any receipt error code.

The group lifecycle after provision stays in separate keyed operations (`tasks.check`, `tasks.turn`, `tasks.publish` and `tasks.cancel`), each with its own receipt and time budget.

A step on Node 209 first asks the Gateway for the clone credential (`operation:direct:github-token` with `purpose: clone`). The Gateway writes a read-only Git authorization header to the vault key that admission named, or an empty value for a repository that needs none. Admission mints nothing, because the VM step before the clone can wait for capacity for longer than the hour that an installation token lives.

Separate steps then clone the source on Node 209, with full history, and copy a Git bundle through official SSH. The VM never holds the credential: `origin` is the plain repository URL, and `.git/config`, a credential helper and the environment files hold none.

Each read and command on the VM is one official `@swamp/ssh exec` argument list; no step copies a script to the VM. Two reads take the checkout's HEAD and branch, and a `stat` of `composer.json` and the entry point. When the profile needs a regular `composer.json`, one official `@swamp/ssh copy` downloads it into the private worker directory. TypeScript on Node 209 turns them into the source profile; it reads and deletes the downloaded file.

"Node 209" here is the Swamp Node of the production fleet: the Node that holds the `swamp` role. The Gateway finds it by that role and never by an id, so a Gateway that bootstraps its own machine has the same Swamp Node ([Gateway bootstrap prerequisites](/reference/node-provisioning#gateway-bootstrap-prerequisites)). Such a Gateway records finished runs through its own queue: its bootstrap starts the scheduler and Horizon units, and its `gateway` role keeps them.

Early in the workspace run, beside the clone credential, a step asks the Gateway for a read token (`purpose: dependencies`). Two parallel steps write the Composer and npm configuration of the task account from it, right before the first setup command. After the last setup command, on success or failure, two parallel steps remove both files and revoke the token. The revoke step reports the outcome in the receipt as `dependency_token`. A revoke that could not run says so: `The revoke step could not run.` Any other failed revoke says `The revoke request did not complete.`

Each captured Project setup command runs in order as `env -C <checkout> … /bin/bash -lc <command>`, as the VM's managed user, with its own timeout. Setup receives empty `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT`, `COMPOSER_NO_INTERACTION=1`, `ORBIT_CHECK_PROCESSES=1` and `COMPOSER_CACHE_DIR`. The checkout's `.env` is not exported into its environment; tools read it themselves. The command's output never reaches native history; the source run keeps a redacted tail of at most 4 KiB per command ([command output](#keep-command-output-out-of-run-history)). For the proof Project, the [proof club credential](/reference/gateway-environment) reaches the VM on SSH stdin of one more exec; no script runs there either.

Each shared task VM host keeps one Large VM place for authenticated CI proofs, shared with its refresh candidate proof. CI-only hosts admit only authenticated CI proofs and their own refresh proofs. Task starts, prep, builds and review rounds never take it, even when it is idle. See [Place task VMs](/reference/compute-drivers#place-task-vms) for the budget units and place numbers.

Full capacity, or no usable promoted image, records the `tasks.provision` Operation as `waiting` in the shared task queue. It holds no VM, workspace or identity, and the group stays `todo`. Admission retries every 20 seconds in queue order and fails the Operation with `tasks.capacity_timeout` after two hours. The waiting Operation and the group show the queue position and the groups that hold the places; see [Task places](#task-places). A host without a recorded base snapshot cannot serve a new VM. See [Image fallback](/reference/compute-drivers#image-fallback).

Admission captures the workspace's runtime with the same actions as `instance:create`. A Project that serves a web root with `task_workspace_routed` on gets a private Route on the development Cluster. Development Process definitions become Instance Processes. Schedule definitions are production-only ([ADR 0215](/decisions/0215-start-development-processes-from-project-definitions)), so a workspace gets none. The guest's host key is unknown at admission, so the Gateway captures the guest's SSH targets with `host_keys: null`.

`Bind the workspace keys` fills those keys from the VM step's pinned key and refuses any other Node. The run uses the child-observed SSH host key and the captured Node 209 identity; it issues no guest worker credentials. Success activates the Route and completes the Processes; a failure marks them failed. The projection reads the admitted Process members only when the receipt reports Process outcomes.

Task VMs reuse WireGuard addresses. Official `@swamp/ssh` pins the observed host key per step, but the official cfgmgmt behaviors check keys against the 209 worker account's `known_hosts`. So enrollment first replaces any entry for the guest's address with its observed key, and destroy removes the address again.

Success leaves the group `running` and its workspace `source_resolved`. The task branch starts at the pinned start commit. The workspace step refuses a checkout on any other commit. No subtask or check starts. The operation result includes `{ instance_id, node_id, starting_commit, image }`, naming the workspace Instance, guest Node, start commit and the image the VM got. The operation stays running until both VM enrollment and workspace setup finish. A failed native run ends the group `failed`; cancellation ends it `cancelled`. Neither result claims that the VM was destroyed. Cleanup remains a separate native operation.

`image` is `{ recipe_identity, fingerprint, build_id, fallback }`, copied from the VM row. `fallback` is `true` when the VM started from the host's last promoted image instead of the current recipe's; `recipe_identity` then names that older recipe.

#### Task places

Task work waits for a task place in one first-come, first-served queue: the `tasks.provision`, `tasks.prep`, `tasks.build`, `tasks.prep_fix`, `tasks.turn`, `tasks.review_round` and operator `proof.command` admissions that found no free place. The queue is every `waiting` Operation on a group, in Operation ID order.

`task:status` returns `capacity` with two lists:

| Field | Contract |
| --- | --- |
| `hosts` | Each active task VM host, in configuration order: `node_id`, `node`, `task_places`, `task_used`, `task_free`, `ci_places`, `ci_used`, `ci_free`, and `holders` |
| `queue` | Each waiting admission, front first: `position` (from 1), `operation_id`, `kind`, `group_id`, `title`, and `waiting_since` |

A holder is one live task VM: `vm`, `pool` (`task` or `ci`), and the group it serves as `group_id`, `title`, `status`, `last_activity_at` and `active_operation_id`. The group fields are null for a VM that serves no group, such as a CI proof VM. Each live VM holds its place until its deletion is confirmed, so a failed or parked VM counts.

A group's `last_activity_at` is the newest time any Operation on the group was created, started or ended, or null when it has none. `active_operation_id` is the Operation on the group that has not ended, or null. A holder with no active Operation is idle.

A waiting Operation on a group, and `task:show` of its group, return `capacity_wait`: `operation_id`, `position`, and `holders`, the holders of the task pool on every host. `capacity_wait` is null when the group or Operation is not waiting for a task place.

#### Release an idle task VM

This section owns the rule. A task VM holds its place only while its group works. Between two steps, such as a build and the driver's check, the VM stays for the next step. A group whose task-pool VM is `ready`, that has no active Operation, and whose last Operation other than an idle save ended more than 30 minutes ago gives its place back. An idle release never loses work: it saves the workspace to origin first. Every five minutes the Gateway job `ReleaseIdleTaskVms` finds such a group and admits `tasks.idle_save` ([chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/task/idle-save.mmd)). The group keeps its status, subtasks and threads.

Thirty minutes is longer than the pause of a driver that is working, and short enough that a stalled driver does not starve the task queue. Group 4118 held a place for hours after its build while preps waited.

The save runs only when the next claim can use it:

- Origin must have the task branch, because the next claim starts from it or from the save. A group that never pushed keeps its VM.
- Origin's task branch must reach the group's latest approved commit, as for [cancel](#cancel-a-stuck-task). Until `task:publish` pushes that commit, the group keeps its VM.
- An open Operation on the VM itself, such as a resume, keeps the VM.
- A `parked` VM gets its resume admitted. A later pass saves it once it runs.

The save is one Ansible phase on the VM. It commits the workspace changes that Git tracks or would track as `Save the idle workspace`, by Orbit, checks that the workspace is clean, and bundles the head. Node 209 pushes that commit to the save branch `task-<id>-idle`, which holds only the latest save. A failed read, commit or bundle fails the whole save, so nothing is pushed.

The push token is minted when the push runs and never reaches the VM. It has `contents: write` only. GitHub runs the `push` workflows that a pushed commit defines, so a save that adds or changes a file under `.github/workflows/` is refused with `tasks.workflows_permission` rather than pushed with a broader token. The workflows on `main` and the repository rulesets do not cover the save branch.

The save holds what the next claim restores: the committed workspace. It does not hold files that `.gitignore` excludes, the content of a nested Git repository (a clean one is saved as a gitlink, a dirty one fails the save), stashes, other local branches, or the Pi session of the VM. The save commit stays in the workspace, also when the push or the release does not follow, and the next turn continues on top of it.

The Gateway records the saved commit on the group, then admits the [release](/reference/compute-drivers#release-a-task-vm) of the VM, as publication does. A failed save keeps the VM. It is no activity of the group, so the next pass saves again. After three saves in a row that fail, are cancelled or are interrupted, the idle release stops for that group.

A save refused for changing a workflow stops the idle release at once, because a retry changes nothing; a turn and a publish carry that work. The group then asks for `failure` assistance, and the VM keeps its place. The driver's next Operation starts the count again.

The next claim compares the save with the head it would start from: the task branch head for a turn or a prep fix, the named head for a review round or a build.

| The save | Turn or prep fix | Review round or build |
| --- | --- | --- |
| Holds work beyond the head | Starts from the save | Refuses with `tasks.idle_save_pending` (409) |
| Is in the head, or is the head | Starts from the head | Starts from the head |
| Has moved apart from the head | Refuses with `tasks.idle_save_diverged` (409) | Refuses with `tasks.idle_save_diverged` (409) |
| Cannot be compared | Refuses with `tasks.idle_save_unreadable` (503) | Refuses with `tasks.idle_save_unreadable` (503) |
| Its branch is gone and the head holds it | Starts from the head | Starts from the head |
| Its branch is gone and the head lacks it | Refuses with `tasks.idle_save_missing` (409) | Refuses with `tasks.idle_save_missing` (409) |

A turn that starts from the save continues where the group stopped. A pending save asks for a turn and a publish before the review round. A diverged save stays on `task-<id>-idle`, and the next save cannot overwrite it. Merge that branch into the task branch, with `-s ours` to drop the save, then try again. An unreadable comparison starts nothing; try again. For a missing save, push the commit that `details.saved_commit` names to `task-<id>-idle` again, or merge it into the task branch, and try again. When that commit is lost, cancel the group. Delete a save branch only after its group ended.

A check or a publish needs the fresh workspace first and returns `tasks.no_workspace` until a turn claims it. The save branch stays on origin after the group ends, and a person deletes it then.

#### Keep command output out of run history

Official `@swamp/ssh` records the stdout and stderr of each exec verbatim in native run history on Node 209. So every repository command on a task VM writes its output to a file instead: provision setup, check setup, the Project check, command deliverables, scripts, and teardown. The file lives in a private (`0700`) run directory on the VM. The exec step captures no output; history keeps its exit code.

One official `@swamp/ssh copy` downloads the files into a private worker directory on Node 209. The TypeScript step that shapes the result reads each file, redacts it, keeps a bounded tail and deletes the file. Best-effort steps remove both run directories.

One module holds the rule: `swamp/extensions/behaviors/_lib/public_output.ts`. It reads at most the last 1 MiB of a file and replaces invalid UTF-8 before matching. It replaces known values of eight characters or more in one pass. Then it replaces credential-shaped text: PEM blocks, URL user info, `Authorization` headers, `Bearer` tokens, GitHub tokens, 64-character hex credentials and the values of secret-named assignments.

It splits the redacted capture into lines and keeps the newest whole lines that fit the bound. It drops a first line that may start mid-value when older bytes were discarded, and any line that still holds an 8-byte piece of a known value. A line longer than the room left is replaced by `[a line of N bytes omitted]`, and older lines still fill the bound, so one long line never hides the lines before it. Task commands receive no credentials, except that setup commands can read the token files of the private dependencies, so they pass no known values today.

Repository files follow the same rule. Provision downloads `composer.json` instead of reading it through an exec. The profile step keeps only its facts, the PHP version and whether the source is Laravel, and deletes the file.

Scripts and checks keep 16 KiB of output. Provision setup and teardown keep 4 KiB per command in their step output.

#### Check an external group

`task:check <task> --phase=baseline|handoff` starts `tasks.check` through `POST /api/v1/tasks/{task}/check` with `{ phase }`. The Gateway admits the open group, current child and owned VM, records one check row and supplies the full public intent and named vault descriptors to `operation-task-check`. Same-key replay returns that Operation before mutable admission.

The run follows its chart: `Build every argv`, `Run setup in order`, `Run the Project check`, `Snapshot the workspace`, `Run each deliverable`, `Snapshot the checked tree`, `Commit the candidate` and `Push the candidate` are child workflows. `Read the result` exposes the complete receipt as a direct behavior output, and `Check the result` fails the run on its execution error code. Swamp propagates only one level of child behavior outputs, so a review round reads the direct receipt rather than reaching through the push child.

A failed step stops the chain, but `Push the candidate` still runs: it shapes the result, which is then `tasks.repository_execution_unknown`, and removes the scratch files. A failed step keeps no stdout, so on a failed run the Gateway reads the result from the push step and keeps its error code. A pending handoff record is kept under one name per group, so the scheduled CI observation replaces it when it settles.

A workspace is required (`409 tasks.no_workspace`). A group that owns a container Instance workspace takes the [container check path](/reference/task-instance-workspace#check-phases) instead of the steps below. A parked VM is woken by the admission, which answers `409 tasks.vm_resuming`; repeat the call once the VM runs. A subtask outside the group returns `404`. Another open subtask returns `409 tasks.subtask_not_current`. A baseline after an implementer has started anywhere in the group returns `409 tasks.baseline_done`. Every step runs on Node 209. Official SSH reaches the admitted VM over WireGuard with the existing identity and stored host key. Plain task VMs need no Swamp binary or worker.

Every step on the VM is one official `@swamp/ssh exec` argument list; no step copies a script to the VM. TypeScript on Node 209 builds each argv from the intent and shapes every output. It refuses a deliverable directory that is absolute or has a `..` segment, an overlay path that is not a canonical repository-relative file, and a `fails_on_base` command without a base commit, as `tasks.deliverable_invalid`, before any command runs.

The steps record the start HEAD (`original`), run baseline setup in order (`setup-0`, `setup-1`, …), run the Project check (`project`), snapshot the workspace (`snapshot-before-*`), read the subtask diff from its base with `git diff-tree -r --no-renames --name-status -z` (`diff`), run each command deliverable (`deliverable-0`, …), and snapshot the checked tree again (`snapshot-after-*`, `changed`).

The result preserves the admitted subtask base as its start commit, including after a failed check. It uses `original` only when no base was admitted. A retry must not replace its base with the candidate it just checked.

A snapshot runs `git read-tree HEAD`, `git add --all` and `git write-tree` against the run's own index file, and `git rev-parse HEAD`; the real index is untouched. The first snapshot follows the Project check because its formatters run in fix mode: their edits belong to the checked tree, so they do not make the check `changed`.

Each command runs as `env -C <directory> COMPOSER_NO_INTERACTION=1 ORBIT_CHECK_PROCESSES=1 COMPOSER_CACHE_DIR=… [INERTIA_SSR_PORT=… INERTIA_SSR_URL=…] /bin/bash -lc <command>` in the SSH account's environment. A setup step named `@orbit/ci-caches` expands to the same shared program that provision runs. Commands receive no GitHub or Gateway credentials. Their output stays out of native history ([command output](#keep-command-output-out-of-run-history)); the result keeps a redacted tail of at most 16 KiB.

A command marked `fails_on_base` also runs on its start commit, in separate steps: `git archive` of the base commit, `tar -x` into the run's scratch directory, `find … -exec cp -a --parents` of the installed `vendor` and `node_modules` directories, `cp --parents` of only the declared test paths, and `test -d` of its directory. A missing overlay file or directory refuses the check as `tasks.deliverable_invalid`; any other failed step refuses it as `tasks.repository_execution_unknown`.

A changed checked tree returns `changed`; failed commands or unmet deliverables return `failed`. A failed Project check keeps its output and leaves skipped command deliverables unmet; it creates no candidate.

A command deliverable stored without `fails_on_base` is admitted with `false`; the subtask keeps it as stored. The check workflows read the value of each command deliverable and never default it.

A passing handoff creates its candidate commit in the VM with `git add --all` and one `git commit` as `Orbit <orbit@users.noreply.github.com>`, with hooks off and the admitted message. TypeScript on Node 209 reads `HEAD`, its tree and its parent back: they must be the checked tree on the checked HEAD, or the check is `changed` and records no candidate. `git update-ref` then records the candidate under `refs/orbit/check-operations/{operation}`. Native history owns successful step reuse. The workspace path is run intent; checks add no lease, ownership token or cross-step lock. Cancellation uses native Swamp cancel and official SSH session teardown. Leftover remote descendants are accepted until normal group cleanup or VM destruction.

For Projects with a handoff check name, separate export, copy, import and push steps publish that exact candidate without force to `task-{group}-handoff`, as [publish](#publish-an-external-group) does. After the candidate is ready, a placed mint step posts to the existing scoped Gateway token primitive with `curl`.

The Gateway captures no token at check admission. It mints the existing repository credentials and writes them through the shared execution-copy helper, with the push token also written in Git extra-header form; the response contains vault descriptors only. The push reads that header whole from the vault, as publish does, so the token never appears in a command. Only trusted Git/GitHub actions on 209 resolve those vault copies. The same Gateway class, `TaskGitHubTokens`, mints these credentials as it mints the clone, publish, pull request and dependency merge tokens: a `tasks.check` handoff asks with no purpose.

The VM and repository commands receive none. The push token is revoked after use. The mint steps (the installation token of every clone, dependency read and push) declare `writes: true`, so a lost dispatch fails the step and does not mint a second token. The revokes stay re-runnable: a second `DELETE installation/token` answers 401, and a revoke that a lost worker never finished is better run again. The Checks token is revoked when CI settles or admission fails, with `gh api --method DELETE installation/token`. No pull request opens on the handoff branch.

The check run ends after local execution and CI admission. A native `trigger.schedule` runs about once a minute and performs one bounded status observation per pending check: one `gh api` read of the latest check runs for the exact SHA, at most 100, without paging. TypeScript on 209 keeps only the public run fields.

A queued or running GitHub Actions job on that candidate keeps CI pending while the aggregate Required check has not appeared. Another head or App cannot extend this wait. Without active jobs, a missing Required check fails after ten minutes; active CI keeps the existing 55-minute limit. Only the named Required check can supply passing evidence.

Each observation of a settled result also snapshots the workspace with the same four argv steps (`snapshot-<operation>-index`, `-add`, `-tree`, `-head`); when HEAD or the tree differs from the candidate, the result is `changed`. The observation never waits or retries inside a step.

The collector prints one row per settled check, and a check result with long output exceeds what Swamp inlines in the run history. The Gateway then reads the `output` data artifact of `task-check-ci-data` with `data.get`, at the version the history names. Pending CI remains ineligible for reviewer and publication admission. A pending observation keeps the original Operation running. The family applies recorded cancellation intent on the next delivered valid observation; this does not claim that GitHub or the completed original native run was cancelled. Native pending records settle on a terminal result or 55 minutes after the push.

If SSH preparation fails, native dependencies skip the checks and result steps that need the missing validation and setup outputs. The run keeps its original failure, and the shared terminal reader settles the Operation. Cleanup remains separate native work; no empty output is substituted for a missing predecessor.

The scheduled result identifies the admitted repository, check name and exact SHA; passed CI or a code failure records terminal check evidence through the shared projection. Unreadable or misconfigured CI records an infrastructure failure. Cancellation preserves the pushed candidate and does not cancel GitHub workflows. No webhook or new GitHub access is required.

The native `Check the result` assert fails on an execution error in the push producer receipt. The Gateway captures that receipt in its terminal history read. Scheduled CI reads take their rows directly from `collect`. Neither workflow prints its result through another shell step.

The result retains `outcome`, `deliverables`, `check_id`, `commit`, `head`, `tree`, redacted `output` and `ci`. Baselines and review-only deliverables are `not_checked`. `head` identifies the checked snapshot before commit; `commit` identifies the candidate a reviewer or publisher may use. Only a passed check with the matching commit, tree and required CI supplies a checked candidate. The Gateway records native results without rerunning checks or judging their evidence.

#### Run a tracked repository script

`POST /api/v1/tasks/{task}/script` starts `tasks.script`. Send `{ "subtask": 123, "script": "scripts/dependencies", "args": ["routine"] }` with a required `Idempotency-Key`. The group must be external and open, with a provisioned task VM and the current open subtask. The Gateway records the owned VM, checkout and literal arguments, then starts `operation-task-script`. Swamp owns placement and execution. The script does not create a check, agent turn, commit, or dependency record.

A script captures its run budget at admission: the history of the kind (see [Native run budgets](/reference/operations#native-run-budgets)). Every SSH step of the workflow ends at that budget. When the Gateway times the script out, it asks Swamp to cancel the run. Ending the group asks Swamp again for any run of the group that outlived its timeout.

`script` is a relative repository path with no empty, `.` or `..` components. HEAD must track it as an executable file (mode `100755`); `git ls-tree` reads that mode before anything runs. Swamp then snapshots the whole workspace and runs the script with the admitted literal arguments. It does not inherit Gateway or worker dispatch credentials.

Arguments are strings, not shell source. There is no caller command, environment, working directory, or interpreter field. Do not put secrets in arguments. The script runs from the repository root with the task account's tools and no Gateway credentials. Repository code owns its commands and version policy.

Read the normal operation endpoint until it ends. A completed script returns `{ outcome, exit_code, output, output_truncated, changed, head, tree }`. `outcome` is `passed` for exit zero and `failed` for nonzero; both are successful operation completions. `output` is the combined stdout and stderr, as a redacted tail of whole lines, at most 16 KiB. `output_truncated` reports discarded output. `changed` compares HEAD and the whole-workspace tree before and after execution. These results are not check evidence or approval; run the existing handoff check before publishing.

Each task group has its own VM and workspace. The run intent carries the workspace path, and every step uses that path. Steps add no ownership tokens, leases or cross-step locks. Native history owns step completion and recovery.

Script runs receive their complete public intent from the Gateway. A worker on Node 209 uses official `@swamp/ssh` to reach the VM's admitted WireGuard address and stored SSH host key. Plain task VMs need no Swamp binary or worker. Every step on the VM is one official `@swamp/ssh exec` argument list; no step copies a script to the VM.

The steps read the entrypoint mode, take the workspace snapshots and run the program as `env -C <checkout> COMPOSER_NO_INTERACTION=1 ORBIT_CHECK_PROCESSES=1 [INERTIA_SSR_PORT=… INERTIA_SSR_URL=…] ./<entrypoint> [args…]` in the SSH account's environment.

A snapshot runs `git read-tree HEAD`, `git add --all` and `git write-tree` against a temporary index at `/tmp/orbit-task-index-<run>`, and `git rev-parse HEAD`; the real index is untouched, and a best-effort step removes the temporary index. TypeScript on Node 209 refuses an unavailable entrypoint before the program runs, and shapes the public result.

The program's output stays out of native history ([command output](#keep-command-output-out-of-run-history)). The Gateway captures the public producer output through the shared subscriber in its terminal history read. Native `assert` checks execution availability; a nonzero script exit keeps its successful Operation completion. No shell step prints the result again, and execution steps make no Gateway requests.

Cancellation uses native Swamp cancel and official SSH session teardown. It does not track or confirm remote descendant termination. A remaining process is removed by normal group cleanup or VM destruction. Native resume does not repeat a successful execution step when later evidence collection resumes. An unavailable tracked executable fails as `tasks.script_unavailable`. Execution keeps its existing limit of one hour; native SSH owns its timeout and connection handling. Repository programs receive no operational credentials.

#### Install a private package

`POST /api/v1/tasks/{task}/package` starts `tasks.package` with `{ manager, package }` and an `Idempotency-Key`. `manager` is `composer` or `npm`. `package` is a name with an optional version constraint, such as `acme/ui:^1.2` or `@acme/ui@2.1.0`; it starts with a letter, a digit or `@`, so it is never an option. The MCP tool is `task-package`. There is no `orbit` command.

An agent that cannot resolve a private package ends its turn `blocked` with the package as its question. That is assistance, and nothing else happens. The orchestrator decides, starts this install, and resolves the assistance.

Admission needs an open external group with its owned task VM workspace and refuses other active work on the group. It records the request and names the vault keys of the dependency configuration. It mints no token. The run asks the Gateway for a read token (`purpose: dependencies`), writes the Composer and npm configuration of the task account from it, runs one `composer require --no-scripts` or `npm install --ignore-scripts` in the checkout, and removes both files. The package is `$0` of a fixed command, so no shell parses it.

The package manager changes the manifest and the lock file in the workspace; nothing is committed. A failed install, or a failed removal, fails the run with `tasks.package_failed`. The removal step also revokes the token, so a command that echoed it holds a dead one. The result names the outcome in `dependency_token`: `revoked`, `none` (GitHub answered 401) or `unrevoked` with the reason. An `unrevoked` token does not fail the run. A cancelled run keeps the files until the token expires or `task:cancel` removes the VM.

#### Run an external turn

`task:turn <task> --role=implementer|reviewer|consult --prompt=TEXT [--thread=new|ID]` starts `tasks.turn` through `POST /api/v1/tasks/{task}/turn`. Without `--thread`, a task VM turn starts a new thread and a [task copy](#task-copy) turn continues the role's thread. Optional `--model` selects a new thread's model. `--check=ID` is required for a reviewer turn. The CLI follows the operation and accepts `--idempotency-key` and `--json`; the PHP SDK and generated MCP tool expose the same inputs.

While a group's `tasks.provision` Operation waits for a place or runs, the group is not provisioned. Every other admission on it then returns HTTP 409 `tasks.not_provisioned`. That covers a turn, a check, a publish, a script, a package install, a sync, a build, a review round, a prep fix, a maintenance run and a proof on the group's VM. `details.operation_id` names that Operation; follow it and repeat the call once it succeeds. Any other open Operation on the group still returns `operation.target_busy`.

A parked VM is woken by the admission of a turn, a check, a publish or a script, which returns HTTP 409 `tasks.vm_resuming`; repeat the call once the VM runs. While a [fleet reboot](/reference/gateway-recovery#fleet-reboot) pass drains the VM's host, the same admissions return HTTP 409 `tasks.host_rebooting` and start nothing; repeat the call after the pass. The resume is its own Operation and does not take the Idempotency-Key of the call, so a keyed call can be repeated with the same key.

The Gateway admits the request and starts the native `operation-task-turn` workflow. A missing workspace returns HTTP 409 `tasks.no_workspace`. A subtask outside the group returns 404. Only the first open subtask by position can run; another returns HTTP 409 `tasks.subtask_not_current`. A worker on Node 209 runs the turn. The full intent supplies the group's VM workspace path and admitted SSH target; official SSH performs each workspace action. Pi API actions run on Node 209 with the existing vault-backed VM token. Swamp owns cancellation and the Pi session without adding a cross-step lock.

Pi HTTP actions run as bounded native curl commands on the Swamp worker. curl reads the Pi token from its own environment, never from argv, and redirects fail. The worker needs only `sh`, `curl` and `awk`: `awk` keeps at most 1 MiB of recent stream events in the run's private worker directory. The terminal reader returns available usage, nullable cost, and at most 256 KiB of recent redacted transcript entries. A marker names omitted history.

Receipt and diff files are downloaded to the same directory, and TypeScript readers redact them with the repository redaction rule before they enter native history. Parser errors can quote private input, so a failed step reports only one fixed code, such as `tasks.pi_event_invalid` or `tasks.difference_invalid`.

The final `outcome` step uses a native assert over the typed turn result after required cleanup. The Gateway captures the complete public output of `shape_result` in the same terminal history read, without a shell step that prints it again. A failed Pi turn retains its public evidence and refusal code. A later cleanup failure keeps the Operation failed while retaining the completed Pi observation on the thread.

| Input | Rule |
| --- | --- |
| `thread` | `new`, or a thread of this group with the same role. A consult uses a reviewer thread. Another thread returns 422 `tasks.thread_role_mismatch` |
| `model` | Only for a new thread; a continued thread returns 422 `tasks.model_fixed`. Claude returns 422 `tasks.model_refused`. A model unavailable on the VM fails the operation with `model.unavailable` |
| `check` | An [eligible checked candidate](#check-an-external-group) for this subtask, including hosted CI when required; otherwise 422 `tasks.commit_not_checked`. HEAD must equal its commit, or 409 `tasks.head_moved` |

Separate native steps capture workspace evidence, create `.git/orbit` and remove an earlier `receipt.json` with pinned `@swamp/ssh exec` argv, create or continue the Pi session, send the turn with key `operation-{id}`, and observe one Pi event stream. The turn copies no file to the task VM. The session attaches to the subtask and appears on the board. Orbit supplies only its fixed instructions: no fetch or push by the agent, a read-only reviewer, the [turn receipt](#turn-receipt) schema, and the workspace MCP configuration. Orbit adds no rubric, reminder, or nudge.

The admission puts the title, brief and deliverables of the subtask in front of the prompt of the caller, for every role, and records that text as the admitted prompt. A composed prompt over 100000 characters returns 422 `tasks.prompt_too_large`.

When the turn ends, Swamp returns its receipt and measurements. The Gateway projects thread state and metrics without acting on the receipt outcome. A `blocked` receipt starts no consult and creates no question or assistance. No receipt produces `receipt: null` and `receipt_error: null`. A receipt that breaks the [schema](#turn-receipt) produces `receipt: null` and one fixed `receipt_error`; the turn itself still succeeds. The result includes the receipt's outcome, summary, deliverable confirmations, question and cause; the thread ID and final state; HEAD and the full working-tree hash before and after the turn; token metrics, nullable cost, and the recorded transcript.

After a reviewer turn, Orbit records the checkout difference, resets to the check's commit, and removes untracked files. The result includes the removed difference and the state after reset. A consult is read-only but keeps the implementer's uncommitted tree: Orbit records its difference and never resets it.

A Pi restart leaves the turn `working` while Durable resumes it. Durable retries transient provider errors inside that turn; the Gateway retries none. A crash loop at Durable's restart limit or an exhausted provider retry budget ends the thread and operation `failed`, with the error in the result. Orbit raises no assistance for the external group. New task VMs need the [Durable artifact pin](/reference/pi-server#roll-out-a-new-binary).

Direct turn cancellation uses native Swamp cancellation and official SSH session teardown. It does not prove that the Pi session or remote descendants stopped. Normal group cleanup or VM destruction removes remaining work. A failed or disconnected Pi stream takes the separate interrupt action once before collecting a failed result. Native resume reuses completed steps, including the keyed message send; it adds no Gateway poller or ownership machinery.

#### Publish an external group

`task:publish <group> --check=<id> [--title=… --body=…]` starts the native `operation-task-publish` workflow through `POST /api/v1/tasks/{task}/publish`. The JSON input is `{ check, pull_request?: { title, body, draft? } }`. Supply title and body together. `--draft` sets `draft` and opens a new pull request as a draft, as the Orbit App; an open pull request keeps its state. The CLI accepts `--idempotency-key` and `--json`; the PHP SDK and MCP carry the same inputs.

The Gateway admits an open group, its owned workspace and an [eligible checked candidate](#check-an-external-group) belonging to that group. Required hosted CI must have passed for the recorded check and commit. Otherwise admission returns `422 tasks.commit_not_checked`. It pins the repository, default branch, candidate commit, checked tree and SSH target in the intent. Admission serializes publication with the group lock and refuses other active work. It mints no token. The intent names two vault keys, one for the GitHub installation token and one for its Git authorization header.

A step of the run asks the Gateway for the token right before the push (`operation:direct:github-token`, with the run id), so the token is fresh whenever the run reaches that step. The Gateway checks the Operation, the run and the repository. It mints a token for that one repository with `contents: write`, `pull_requests: write` and `workflows: write`, and writes the two values. The reply holds vault descriptors only.

A worker on Node 209 uses official SSH to verify HEAD and the whole working tree against the checked candidate. Each VM read and command is one official `@swamp/ssh exec` argument list; no step copies a script to the VM. Four reads take `git read-tree HEAD`, `git add --all` and `git write-tree` on a per-run index file, and `git rev-parse HEAD`. A moved candidate fails with `tasks.head_moved` before a push.

`git bundle create` exports HEAD on the VM, official SSH copies the bundle to 209, and separate bounded Git commands initialize a disposable bare repository, import the bundle and read its commit and tree. A bundle whose HEAD or tree is not the candidate fails with `tasks.head_moved`. Only the `git push` and `gh api` steps on 209 resolve their needed execution copy, as an environment value; it never enters argv or run history. Repository commands and the VM receive no token. The workspace path stays in intent, without a new lease or cross-step lock.

The chart names every native command and data step. Separate operations stay visible; ordering-only child workflows and result programs are removed.

Swamp pushes the exact candidate to `task-{id}` without force. Git pushes nothing for an identical remote tip and refuses a divergent one, so no tip read runs first. A divergent remote tip fails with `tasks.not_fast_forward`; a GitHub workflow-permission refusal fails with `tasks.workflows_permission`. Other transport failures use `tasks.push_failed`. A lost push reply is reconciled by the next push, which finds the identical tip.

Without `pull_request`, publication only pushes. With it, a `gh api` step looks for an open pull request from the admitted branch. TypeScript on 209 plans one update of its title and body when found, or one creation against the admitted default branch, and a second `gh api` step sends it from a private JSON input file. A lost reply fails the operation.

After cleanup removes scratch, start a fresh command. Its push step mints a fresh token, pushes again and repeats the PR lookup before another mutation. Native resume cannot recreate scratch from a completed step, and it cannot reopen a terminal Operation.

Token revocation (`gh api --method DELETE installation/token`) and scratch cleanup on the VM and on 209 are separate native steps. Publication requests no reviewers and runs no brief coverage. A native assert confirms publication and worker cleanup. The Gateway projects the PR step’s public metadata into `pr_url` and the watched PR fields and releases the VM after successful publication. Raw Git and GitHub replies stay in the private scratch directory. Typed data readers preserve public refusal codes without retaining those replies in run history.

A group whose workspace is a [task copy](#task-copy) publishes the same way, with the same result. The Gateway admits it without a task VM, while its container runs (`tasks.instance_not_running`) and no earlier copy turn may still run (`tasks.copy_turn_unended`). The intent adds the copy's container, its start commit and the path of its `.env`, from the Instance's record.

When the run starts, the Gateway also renders the `.env` from the Instance's stored values and passes it as a vault copy (`execution_secrets.environment`, key `task-publish-environment-<operation>`), never as a run input. The agent cannot change that copy, unlike the file in the copy. It holds the copy's own keys as Orbit stored them; it is not the copy's file byte for byte, because a clone keeps the default's file from the seed and rewrites only its own keys ([the environment of a clone](/reference/development-containers#the-environment-of-a-clone)). When the copy is destroyed, these copies are deleted with the Instance's other execution copies.

Instead of the VM reads, the [workspace phases](/reference/task-instance-workspace#contract) `preflight` and `export` read the checkout as `agent` and fetch the branch bundle to 209; the Node keeps no copy, and the copy receives no token.

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
- every secret that is not in this `.env` or, for a nested-fleet copy, the Club key.

A long value of the copy that is no secret is refused too, when the start tree lacks it: its `APP_URL`, a host address or a user name. So is such a value in new commits of the default branch that the task branch merged. Remove it from the branch history and check again.

A Project that tracks `.env` in Git cannot publish from a copy: Orbit's write changes the tracked file, so every check commit holds it. No task VM is released: the copy stays until `task:cancel`, and the group keeps its status. A moved head refuses in the preflight and fails with `tasks.publish_failed`, not `tasks.head_moved`.

The result stays `{ branch, commit, pull_request_number, pull_request_url }`, with null PR fields for a push alone. Cancellation prevents a mutation that has not started. A started Git push or GitHub mutation finishes its bounded request before cancellation is reported; cancellation cannot undo an existing branch or PR. Swamp owns cancellation and locking. No Gateway push job, mirror transfer, lease or polling step runs.

#### Prepare a command

`task:prep` starts `tasks.prep` through `POST /api/v1/tasks/{task}/prep` with `{ command, brief?, reference? }`, where `command` is `family:command` or a nested `family:part:command`. A nested command's files join its parts after the family with dashes: `node:role:list` is `docs/cli/node/role-list.mdx`.

It runs [stage 1 of the command development pipeline](/reference/command-development-pipeline#1-prep) for one command. An author agent drafts the chart, the docs page and the cases in a task VM, a separate review agent checks them, and Swamp opens the draft pull request as the Orbit App with the spec as its first commit. The route requires an `Idempotency-Key`. It has no CLI command and no SDK request, like `task:pr-ready`; the MCP tool `task-prep` is the caller. Any other form, or upper case, returns `422 tasks.prep_command_invalid`.

The optional `brief` (8,000 characters) goes into every prompt of the run. The optional `reference` (80,000 characters) goes only into the draft prompt; use it for text the workspace does not hold, such as the approved files of a sibling that is still in an open PR.

The group is an external `todo` group, made with `task:create`, of a Project that the GitHub App reads. It has no VM yet. The Gateway admits one run. In one transaction it captures the placement, VM, workspace and credentials the way [provision](#provision-an-external-group) does, and it captures the rest of the intent:

- the author thread, and one reviewer thread for each review round, with model, effort, session and Pi address;
- the prompts of the draft, review and fix turns;
- the title and body of the draft pull request;
- the repository, the base and the branch `task-{group}`; and
- the review mode of the group, `manual` or `automatic`.

It records the author thread on the subtask with a query update, so no model guard of the subtask runs. It mints no GitHub token. A prep run takes longer than the hour that an installation token lives, so admission only names the vault keys of the push token and its Git authorization header. The publish step asks the Gateway for the token with the run id of the prep run, right before the push. Admission adds no child Operation.

Two steps of the run mint a GitHub credential at the Gateway: the clone step of the provision child and the publish step.

The run is the same in both modes. Successful publication releases the VM and moves the group to `waiting_for_review`. A later build or correction claims a fresh task place.

The native `operation-task-prep` workflow is one parent run. Each step runs an existing workflow as a child, with its input taken from the parent's inputs.

| Step | Child workflow | Role and thread |
| --- | --- | --- |
| Create the VM | `operation-task-provision` | |
| Draft the spec | `operation-task-turn` | implementer, new thread |
| Review the spec | `operation-task-turn` | reviewer, new thread for each round |
| Fix the findings | `operation-task-turn` | implementer, the draft's thread |
| Open a draft PR | `operation-task-publish` with `draft` | |

The guest's host key is unknown at admission, so the intents of the later children carry `host_keys: null` for the VM. After the provision child, a `Pin the VM` step fills the key from the provision receipt, the way provision binds its own Route and Process targets.

The child workflows gain only inputs that the parent needs. `operation-task-turn` takes three optional inputs:

- `turn_key` names the Pi message, because a draft turn and a fix turn on one thread must send two different keys;
- `candidate` is the commit a reviewer restores, which is the commit the author just made;
- `prompt_append` adds the findings of a review to the prompt of a fix turn.

`operation-task-publish` accepts a `null` check, because no check recorded the commit. The Gateway cannot know these values at admission, so they pass from step to step in the run. The `after` snapshot that a turn reports becomes the next reviewer's candidate and the publish commit and tree.

The author checks the draft with the flow check, the docs build and lint, and `runner.py plan --file` on the cases file, which must exit 0.

The author also runs the runner unit tests; a failure caused by the content of the cases file goes into the summary as a blocker for the owner, and no test is changed. The author keeps every case that runs on the default branch, and the reviewer checks that none moved to `uncovered`. The reference text of a run is input, not truth: it is a draft sibling without approval, and a defect in it is not a precedent. The author commits the three files as one commit.

The run also checks the plan itself. A step `Plan the cases` runs the plan in the task VM as one `@swamp/ssh exec` with literal arguments, after the draft and after each fix. The Gateway captured the path of the cases file at admission. The runner exits 3 on a refusal and the step accepts that exit.

The refusal becomes the first finding of the round: the review cannot approve past it, and the fix turn gets it.

Any other exit fails the run with `tasks.prep_plan_failed`. The run has no handoff check, so a command deliverable on the subtask would not be verified. A draft that leaves HEAD where it was ends the run with `tasks.prep_not_committed` before any review. The review runs for at most three rounds. A reviewer receipt with outcome `approved` ends the loop. With `changes_requested`, the reviewer's summary goes into the prompt of a fix turn on the author thread, and a new review follows.

Each review starts a new thread on the reviewer model of the group, which differs from the author's, at high effort. It also reads the findings of the one before, so it checks them instead of finding them again. A reviewer reports at most five findings and approves with notes when only matters of taste remain.

A fix that leaves HEAD where it was ends the run with `tasks.prep_not_committed`, because the next review would restore a commit that holds none of the fix. A finding left after the last round ends the run with `tasks.prep_review_unresolved`. Nothing is published, the result keeps the findings of the last review, and the VM stays for the orchestrator, who cancels the group when done.

The Gateway projects the terminal event once. A succeeded run records the workspace and VM as provision does, the metrics of each thread, and the pull request on the group as publish does: `pr_url`, `watched_pr_url`, `watched_pr_number` and `watched_pr_state`. It also marks the spec subtask completed and releases the VM while the spec waits for review. A failed run leaves the subtask open. The result is `{ branch, commit, pull_request_number, pull_request_url, rounds }`. The group stays running; stage 4 of the pipeline takes the draft from there. A failed run records the failed step and its code, and keeps the workspace.

#### Apply the corrections

`task:prep-fix` starts `tasks.prep_fix` through `POST /api/v1/tasks/{task}/prep-fix` with `{ corrections }`. It is the "Apply the corrections" step of [stage 1](/reference/command-development-pipeline#1-prep): the owner reviewed the spec in its draft PR and asked for changes. A group without a VM claims a task place and restores its published branch. A ready VM runs the corrections directly. `corrections` is the review as text, up to 40,000 characters, with any decision made since. The route requires an `Idempotency-Key`. It has no CLI command and no SDK request; the MCP tool `task-prep-fix` is the caller, and so is the gate check of stage 4.

Every minute the gate reads the reviews of a draft pull request whose group has a succeeded `tasks.prep` run, no active work and a build that has not started, and the owner has not approved the head. When the latest decision of the reviewer is a request for changes on exactly that head, the gate admits `task:prep-fix` with the review text and the inline comments of the reviewer as `corrections`.

The admission is keyed by group and review, so a repeat tick starts nothing and each new review starts one run. A run that failed is not started again by the next tick: the gate reports `tasks_pr.prep_fix_failed` for that review when the group is idle. A failed run leaves the VM running, so until then the gate reads nothing for the group. A new review of the owner starts a new run. A comment, an approval, a dismissed review or a review of an older head starts nothing.

The group must be a prep group: a succeeded `tasks.prep` run, a published branch and no active work, an open pull request, and no running operation. Otherwise admission returns `409 tasks.prep_fix_refused`. The Gateway captures the whole intent in one transaction. It holds:

- the task VM identity and a fresh claim when the previous VM was released;
- the author turn, with the corrections as its prompt;
- two new review threads and the fix turn;
- the path of the cases file;
- the vault key names of the Pi token and the push token.

The native `operation-task-prep-fix` workflow is one parent run, with existing workflows as children. A ready VM skips resume and starts the author turn directly:

- `Claim a task place` creates a fresh VM from the base at the published task head. An existing VM skips this step.
- `Apply the corrections` runs the author turn on the author thread of the prep run. The author changes only the three files of the spec, in one new commit. It never amends a pushed commit.
- `Plan the cases` and the review follow as in the prep run, for at most two rounds.
  - The reviewer also checks that each correction is applied or has a reason.
  - Runner tests that pin the old cases file are a note, not a finding. The owner's review must defer them.
  - The notes of the last review stay in the result.
- `Push the corrections` runs `operation-task-publish` without a pull request body, so the same draft PR gets the commit.

A branch that moved since the park, such as a correction pushed by hand, is refused with `tasks.not_fast_forward`; nothing is overwritten. A correction that needs another file or a decision of the owner is left out, and the author's summary says so.

The Gateway projects the terminal event once. A succeeded run records the thread metrics and `{ branch, commit, rounds }`, then releases the VM. A failed run records the failed step and keeps its workspace for inspection; a failed fresh claim releases its partial allocation.

The gate check also admits this run when the owner requests changes, and it detects the approval, which [starts the build](#start-the-build).

#### Start the build

`tasks.build` is the step after the owner approves the spec of a prep group. The gate check admits it. It has no command, route or MCP tool. Before it existed, a person moved the group to `running` and added the build subtask by hand.

The gate check acts on a group with a succeeded `tasks.prep` run, an open draft pull request and no other subtask than its completed spec subtask. The group is `waiting_for_review` after publication in either mode.

In manual mode it acts when the latest review of the reviewer approves the exact head of the pull request. In automatic mode it acts without a review, because the clean review of the prep run is the approval. A group that has started its build, a head that is not the approved one and a busy group are quiet skips. The Idempotency-Key is `pr-gate:build:<group>:<head>`.

In one transaction the Gateway records the approval (the head, the mode and the reviewer) in the intent, appends the build subtask, moves the group to `running` and admits one run.

The subtask has a fixed brief that names the approved chart, page and cases of the command and the approved head. Its deliverables are that the runner plan of the cases file passes and that the Project check passes, as the check command of the Project or, without one, a review deliverable. The intent holds the VM identity and its fresh claim, the sync and the first implementer turn. It also names the vault keys of the Pi token and the read token.

The native `operation-task-build` workflow is one parent run, with existing workflows as children. A ready VM skips resume and starts the sync directly:

- `Claim a task place` creates a fresh VM from the base at the published task head. An existing VM skips this step.
- `Sync the refs` runs `operation-task-sync`, so the workspace holds the approved head in `refs/remotes/origin`.
- `Run the first turn` runs `operation-task-turn`. Its prompt is the title, brief and deliverables of the subtask, then the instruction to build the command.

The Gateway projects the terminal event once: the task VM as ready, the metrics of the thread and `{ branch, head }`. The group stays `running` for its driver, and the VM stays for the driver's next step unless the [idle release](#release-an-idle-task-vm) takes it. A failed run records the failed step with `task_vm.resume_failed`, `tasks.sync_failed` or `tasks.turn_failed`, and the group stays `running` for the orchestrator.

### Task lifecycle

A group starts in `backlog` or `todo`. Moving it to `todo` requires at least one subtask and a deliverable on every subtask. Provision reserves capacity, then records `running` once the workspace is ready. Swamp chooses subsequent status updates: `running`, `reviewing`, `settling` and `waiting_for_review`. Explicit completion, cancellation or failed provisioning records an ended status. Terminal history and assistance remain readable.

One group uses one task VM and one branch. Subtasks share the workspace in order. The keyed operations enforce workspace ownership and persist results; they never select the next subtask.

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

Parent creation accepts an ordered `subtasks` array of at most 50 `{title, brief, deliverables}` objects and a `status` of `backlog` or `todo`. The status defaults to `backlog`. Creating in Todo changes board state only; `task:start` starts the parent's VM. Parent creation accepts only `orchestration: external`, which is also the default. The API, PHP SDK, CLI option `task:create --orchestration`, and MCP expose this field. Update cannot change it.

Create also accepts `review`, `manual` (default) or `automatic`, the mode of the gate of the group's pull request; update cannot change it, and show returns it. Create also accepts a strict boolean `current_recipe` (CLI `--current-recipe`, default `false`) for a task that needs the current machine recipe; update cannot change it. List accepts `project_id` and `status` filters. Show returns the task and its subtasks in position order.

Update changes a task's `title`, `brief`, or `status`. Title and brief change only in `backlog`. Status updates start no work.

For an external group, `task:update` also accepts `status` moves among `running`, `reviewing`, `settling`, and `waiting_for_review`. It still moves between `backlog` and `todo`, with the same deliverable requirements, but starts no work. A status update cannot enter `reserved`, leave `reserved`, cross between preparation and active work, or end the group. Only provision moves `todo` to `reserved`. Use `task:cancel` or `task:complete` to end a group. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing, including on an ended group. These updates need no idempotency key.

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

For an external group, subtask update also accepts `status`. A subtask moves from `todo` to `running`, between `running` and `reviewing`, and from either active status to `completed` or `failed`. `completed` and `failed` are final. Only `task:cancel` sets `cancelled`. Any other move returns HTTP 422 `tasks.status_move_refused`. Repeating the current status changes nothing. An ended group permits no subtask status move. Completing a subtask clears its assistance and reconciles the group request as resolution does. A status update starts no agent or check, advances no other subtask, and does not settle the group. The API, PHP SDK, CLI `--status` option, and MCP expose both status inputs.

A subtask that has started keeps its title, brief, position, and deliverables. A deliverables update on it returns `tasks.deliverables_locked` and leaves the stored list as it is, except for the one correction after `invalid_deliverable` described below.

Appending a subtask records new work. Swamp decides when to resume the group.

### Cancel a subtask

For an external group, `task:cancel` cancels a `todo`, `running`, or `reviewing` subtask in an open group. It uses the existing interrupt path to stop the acting thread and any running check before marking active work `cancelled`. A failed interrupt leaves the status unchanged and returns HTTP 502 `tasks.subtask_interrupt_failed`. Cancellation starts no next subtask and does not settle or change the group's status.

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
| `tasks.not_provisioned` | 409 | Other work on a group while its `tasks.provision` Operation waits or runs. `details.operation_id` names it |
| `tasks.subtask_not_running` | 409 | A subtask cancel that the rules above do not permit |
| `tasks.subtask_interrupt_failed` | 502 | Orbit could not stop the implementer or the check |
| `tasks.agent_driver_unavailable` | 409 | The configured agent driver is unknown. No task is stored |
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

`problems:file` runs every hour. It files at most three new tasks per day, using the Gateway application timezone. It takes release alerts first, then the highest `occurrences`. Equal counts use the earlier `first_seen`, then the fingerprint string. Each run loads at most 50 ready rows that are not muted, not tied to an open task, and not skipped by [Suppression](#suppression).

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

The group execution lock waits at most five seconds for every waiting caller, including HTTP actions, operation workers and VM jobs. A caller that does not wait refuses immediately. A busy lock retains recorded state and leaves the caller to retry.

A resolution clears group assistance only when its kind, question, and reason match the resolved subtask request and no sibling still needs assistance. A different or newer request stays open.

### Behavior names

The local and data steps of `tasks.prep`, `tasks.prep_fix`, `tasks.script` and `tasks.check` are named by the task VM: the name ends in the address of the VM (its WireGuard address). Addresses come from a bounded pool and are reused, so the set of names stays fixed. Swamp keeps one data lock per behavior name. A fixed name would make runs on different VMs wait for each other, and a long step such as the `observe` step of a turn would hold the lock for the whole stream.

The SSH steps keep their node id. An SSH definition holds the host keys and the identity of one VM, and the address returns to the pool for the next VM, so a name on the address would rewrite that definition. The Node id of a task VM is never reused.

Other steps keep fixed names. The steps of `tasks.pr_ready`, `tasks.pr_merge` and the comment steps of `tasks.review_round` only make short GitHub calls, and Swamp never deletes a behavior instance, so a name per group would grow without bound. A few short check steps (`task-check-prepare`, `task-check-candidate` and the other `task-check-` preparation steps) are short too, and the `collect` step of the scheduled check observation is one step for every VM.

The VM provision child (`tp-clone`, `tp-bundle` and the other `tp-` names) and the per-group `task-check-pending` record still use their earlier names.

## Credentials in task VMs

A task VM holds only placeholder keys. Nick set this as the rule for every key a task VM needs (2026-11-07).

A guard proxy runs outside the VM, on the Node that holds the real key. It answers as this table shows:

| Request | Answer |
| --- | --- |
| From an admitted source, on a path the VM needs, with the placeholder | Passed upstream with the real key in place of the placeholder |
| Anything else from an admitted source | 401 |
| Anything from any other source | 403 |

The admitted sources are the task VM WireGuard network (`task_vms.wireguard_range`) and each agent Node that runs a [container model forwarder](/reference/ai-router#container-model-forwarder), where only containers on its bridge reach the guard. So the real key never leaves its Node and the Gateway, and a leaked placeholder is useless outside those sources. Each further key is one more placeholder-to-real pair in a guard.

The one exception is a short-lived minted token that never stays on the VM, such as a GitHub App token that a step mints for one push.

The first use is the model router guard on `services`, at `10.44.0.3:8318`.

All task VMs share one placeholder model key. The Gateway keeps it as a secret setting and changes it only on an incident, with no scheduled refresh. A task start writes it, with the guard origin, into the VM's Pi models file inside the claim; no start and no release calls the model router. The guard passes only a `POST` to the inference routes, and swaps in the router's anchor key. [Task VM guard](/reference/ai-router#task-vm-guard) describes it. Containers on agent Nodes use the same guard and placeholder through a [forwarder with no key](/reference/ai-router#container-model-forwarder) on their Node.

## Task workspace

The task workspace is one fresh Instance that every subtask of the task shares. Its name and its branch are `task-{id}`. It lives at `<project>/workspace` on the task's own [task VM](/reference/compute-drivers#clone-and-set-up-every-workspace), which holds only this workspace. Its checkout clones from the Gateway mirror and runs the Project's setup steps, using the CI cache ref bound to the tested SHA.

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

### Task copy

A new task of a Project whose default Instance is a container, or that has [`nested_fleet`](/reference/projects#nested-fleet-setting) on, gets no task VM. Its workspace is a task copy: a clone of the Project's default Instance, named and branched `task-{id}`, which the task owns from its create. [`task:start`](/cli/task/start#a-task-copy) makes it and [`task:cancel`](/cli/task/cancel) and [`task:complete`](/cli/task/complete) destroy it. A cancel refuses a copy that holds work origin lacks unless the caller discards it, and its result records what the copy held. A check runs in it through the [check phases](/reference/task-instance-workspace#check-phases).

A handoff check in a copy commits only a changed tree, so a check of committed work leaves nothing that blocks the cancel.

The copy [starts clean](/reference/development-containers#a-task-copy-starts-clean): its tree is its start commit, the seed's commit, plus what Git ignores, so the default's modified and untracked files never reach a check or a pull request. The start's result names what the clean took as `workspace_cleaned`.

[`task:turn`](/cli/task/turn#a-task-that-runs-in-a-copy) runs an implementer or reviewer turn in it as `tasks.copy_turn`, with T3's own CLI as [Swamp drives T3](/reference/development-containers#swamp-drives-t3) describes. Each role keeps one ongoing T3 thread, and a turn without `--thread` continues it: the implementer's thread of the current subtask, recorded on the subtask, or the reviewer's thread, recorded on the group. The first turn of a role, or `--thread=new`, starts the thread with the role's copy model and effort.

A reviewer turn works on the checked candidate, detached, and Orbit checks the task branch out again after it, at the commit it had. A checkout left unclean, off that branch or at another commit holds the group until `task:cancel`, whatever the turn's run did. A turn that ended asking for input does not hold the next turn of its own thread, so the answer can be sent. The thread and its transcript stay on the Gateway after the copy is gone.

[`task:publish`](#publish-an-external-group) pushes its checked commit and opens the pull request from 209. [`orbit proof --group`](/cli/proof/proof) runs one command's proof cases in a nested-fleet copy, as `agent`, against the copy's own nested fleet. Consults, a handoff check with hosted CI, prep and build do not run in a copy yet.

The copy's `.env` holds its secrets. Git never takes it: every container create and clone names it in the checkout's own `info/exclude` ([what is built](/reference/development-containers#what-is-built)), so neither the check's commit nor an agent's commit holds it. A copy or container made before that line existed lacks it until its next create or clone: its check can still commit the file. The publish refusal `tasks.publish_secret_path` covers such a copy too: nothing of it is pushed. Cancel it and start the task again.

| Part | Rule |
| --- | --- |
| Source | The Project's default Instance, active with a seed, whose container runs: `task:start` reads it once before it admits the copy, because the power record can be stale |
| Cap | A start needs a free [isolated ID range](/reference/development-containers#isolated-id-ranges) for the copy and one for the default's next seed, else `tasks.copy_id_range_full`. `ORBIT_TASKS_COPIES_MAX` (default 1) caps nested-fleet copies per Node: `tasks.copy_capacity_full`. Neither waits |
| Publish | Only for a nested-fleet trial Project or a Project on `ORBIT_TASKS_COPY_PUBLISH_PROJECTS`. Otherwise `tasks.copy_publish_held` (409), before any Operation |
| Model key | The copy's agent gets the fixed task model key, as every clone does. It is not per task. Per-task model keys are an open decision |
| Ready | The task runs when its copy is `active`: after the copy's [completion](/reference/development-containers#complete-a-clone) succeeded. A create that succeeds leaves the task `reserved` until then |
| Failed create | The task is `failed`, and Orbit destroys the copy with force. A refused start or a cancel before the run counts too, and so does a failed or cancelled completion |
| Kept copy | After an expired run budget or a failed destroy, the task keeps the copy until `task:cancel`, `task:complete` or `instance:destroy --force` |
| End | When the copy's destroy succeeds, a running task is `cancelled`, a failed task stays `failed` and a completed task stays `completed`. All let go of the copy |

A held publish leaves the checked commit in the copy. An operator adds a Project to `ORBIT_TASKS_COPY_PUBLISH_PROJECTS` once the publish guard for `.env` content is live; a copy of any other Project is a plain clone, so the nested-fleet cap does not count it.

### Workspace ownership

When provisioning creates the workspace, it records the task as the workspace's owner, before any remote work. An owner is set only when the Instance is created: the database refuses any new owner afterwards. Deleting the task clears it. A task owns at most one Instance.

The owner is the only record of task workspace ownership:

- A task holds only the workspace it owns. Orbit refuses to attach any other Instance to a task.
- Claims, cancel, complete, and the sweep find and remove a task's workspace only through its owner, attached or not.
- A task operation runs only in the workspace the task owns, on the task's ready task VM or in its task copy.
- The name and branch `task-{id}` never make an Instance a task workspace. A task never removes your own Instance named or branched `task-{id}`.

Task operations include agent turns, checks, pushes, commit signing, and `.mcp.json` writes. Orbit refuses them on any other Instance.

Task cleanup never removes an Instance without that task as its recorded owner. To remove another Instance explicitly, run [`instance:destroy`](/cli/instance/destroy).

A task VM holds only its task's workspace, and destroying the VM waits only for that workspace. Any other Instance row on the VM's Node describes a checkout inside the VM, so it goes with the VM and never keeps the VM alive.

## Swamp run output

The task page reads `GET /api/v1/tasks/{task}/swamp-runs` through the Gateway. It shows the five newest Swamp runs whose `inputs.group` exactly equals the group id, their steps and states, each step's output, and the run log. It works without an agent thread. Subtasks are not valid group ids. The route requires Gateway access and the Tasks extension. It changes no task or Swamp state.

The browser polls every five seconds while the page is open. The Gateway caches each group snapshot for five seconds, reads within the Gateway-wide [Swamp read budget](/reference/swamp-command-steps), and keeps the last successful, redacted snapshot for 24 hours. `availability` is `available`, `stale` when a read fails or the budget refuses it but a snapshot remains, or `unavailable` without a snapshot. `observed_at` records the successful read time and remains unchanged on failure. An available empty list means no matching runs. An unavailable response has no runs. These states return HTTP 200 so an outage does not break the rest of the task page.

The Gateway connects to `swamp.orbit:443` over verified TLS. Set `ORBIT_SWAMP_READ_TOKEN` in the Gateway environment to a dedicated Swamp token with only `read` grants on workflows and their output data. Never reuse the driver or worker credential. `ORBIT_SWAMP_ADDRESS` selects the server address when the Gateway cannot resolve `swamp.orbit`; the TLS name remains `swamp.orbit`. `ORBIT_SWAMP_CA_PATH` selects the Orbit root CA file. The Gateway omits raw run inputs, run filesystem metadata, and upstream exception messages. It redacts output before returning or caching it. The browser contacts neither Swamp nor the task VM.

### Read limits

Each read uses Swamp's WebSocket `workflow.run.search` with `inputs: {group: "<id>"}` and `limit: 5`, then `workflow.history.get` and `workflow.history.logs` for those exact run ids. Orbit verifies group membership again on the returned details. It never uses a free-text search or a browser-supplied run id. Missing configuration, denied reads, oversized messages, malformed replies, and timeouts all produce stale or unavailable state. The complete upstream read has a five-second budget and a 2 MiB message limit. The response keeps at most 100 steps per run, 8 KiB of text per step, 32 KiB of step output per run, and a 16 KiB run-log tail from at most 200 lines. Explicit truncation flags mark omitted runs, steps, or text.

Orbit applies the Activity secret rule before truncation and before caching. It also removes the configured reader token by exact value and strips terminal control sequences. Structured step outputs are recursively redacted before conversion to text. Output is plain text in the browser.

Worker output can arrive after a step finishes. In the pinned Swamp version, worker console lines travel with the method result; resolved step outputs also appear after the method writes them. The page shows running and pending steps while waiting, and refreshes output as Swamp makes it readable. The [Swamp protocol](https://git.swamp-club.com/swamp-club/swamp/src/commit/db3f6373/src/serve/protocol.ts), [run attach authorization](https://git.swamp-club.com/swamp-club/swamp/src/commit/db3f6373/src/serve/connection.ts), and [worker console handling](https://git.swamp-club.com/swamp-club/swamp/src/commit/db3f6373/src/serve/dispatch_service.ts) define these limits. The [Swamp manual](https://swamp-club.com/manual/explanation/swamp-serve) describes the server and grant boundary.

## Agent threads

An **agent thread** is one persistent conversation with a coding agent. It records the driver, the external conversation id, the Node, the task and subtask, the role, the model, and the effort. The external runtime writes the transcript. Orbit keeps recorded snapshots, the link and the metrics.

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

A driver translates Orbit's thread operations for one agent runtime. A turn in a task VM runs on the `pi` driver. A turn in a [task copy](#task-copy) runs on T3 instead: Swamp runs stock `t3 thread start` or `t3 thread send` in the copy as user `agent`, and the thread records the driver `t3`. When the turn's projection commits, the copy's T3 thread and its title go to the [Conn thread index](/reference/conn#thread-index), so a device lists it at once. The Project's [`nested_fleet`](/reference/projects#nested-fleet-setting) setting chooses between the two.

`ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER` and `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` select the driver of each role on a task VM, and both default to `pi`. Every new task stores those values, a task copy's too. The Gateway registers `pi` and no other task-agent driver, and a copy turn does not use the registry. Any other value, `t3` included, returns `tasks.agent_driver_unavailable` and stores no task.

A managed task whose stored driver is not `pi` does not start or resume an agent turn. A task copy stores `pi`, and its `t3` threads do not use the driver registry: a copy turn starts or continues them with T3's own CLI. A caller never supplies a runtime URL. An unsupported operation fails explicitly. [Task agents run on Pi](#task-agents-run-on-pi) explains why.

| Role | Default model | Effort |
| --- | --- | --- |
| Implementer | `gpt-5.6-luna`, or `ORBIT_TASKS_IMPLEMENTER_MODEL` | `medium`, or `ORBIT_TASKS_IMPLEMENTER_EFFORT` |
| Reviewer | `gpt-5.6-luna`, or `ORBIT_TASKS_REVIEWER_MODEL` | `high`, or `ORBIT_TASKS_REVIEWER_EFFORT` |

Set `ORBIT_TASKS_IMPLEMENTER_EFFORT` and `ORBIT_TASKS_REVIEWER_EFFORT` in the Gateway's `.env` to choose each role's reasoning effort, on a task VM and in a task copy alike. Unset or empty keeps `medium` for the implementer and `high` for the reviewer. For example, `ORBIT_TASKS_IMPLEMENTER_EFFORT=high` sets new implementer threads to `high`.

The Gateway reads effort when it creates a thread, not when it creates the group. A change applies to the next thread of every open group. An existing thread keeps its stored `effort`. The Gateway passes the value to the driver unchanged; the agent runtime validates it.

The model settings name Pi models and apply to task VM threads. A thread in a [task copy](#task-copy) takes its model from `ORBIT_TASKS_COPY_IMPLEMENTER_MODEL` or `ORBIT_TASKS_COPY_REVIEWER_MODEL` and its effort from the role's effort setting, which must be `low`, `medium`, `high`, `xhigh` or `max`. Stock T3's CLI takes no effort, so the copy's T3 settings hold one Claude provider instance per effort level, `claudeLow` to `claudeMax`, each with `CLAUDE_CODE_EFFORT_LEVEL` in its environment, and a new thread starts on the instance of its effort. Claude Code takes that variable over the `--effort` T3 passes: verified with Claude Code 2.1.311 and T3 0.1.7. See [Swamp drives T3](/reference/development-containers#swamp-drives-t3).

**Pi.** The `pi` driver runs threads on the [Pi server](/reference/pi-server) of the task's VM. The Gateway chooses the session id. Each send carries a key, and a retry reuses it, so an ambiguous failure never starts a second turn.

The driver maps a model name to Pi's `provider/model` form. Every model uses the VM's one provider, `orbit-task-vm`; see [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The driver refuses a Claude model, including a name that starts with `claude` and a `provider/model` whose provider is `anthropic`, and the turn does not start on another runtime. Pi threads never ask for input, and they report no per-thread line counts.

### Archive finished threads

Swamp records a redacted transcript with each terminal turn result. The Gateway stores normalized entries at `storage/app/private/task-transcripts/{task}/{thread}.json`. It keeps the thread row, metrics and transcript after the VM is deleted. A turn without transcript evidence keeps its previous snapshot. Cleanup makes no transcript request. Transcripts from turns that finished before recorded turn snapshots shipped may be unavailable. This loss is accepted for those test and pipeline tasks; no migration fetches them.

## Session routing

Swamp selects a subtask, role, brief and operation key for each turn. The Gateway performs that turn and records its thread and receipt. It never routes the next turn or interprets a receipt as permission to start other work.

### Fetch before a turn

A keyed turn fetches the default branch before it starts or sends agent work. The operation records its workspace and review baseline so recovery uses the same context.

### Turn receipt

The agent ends its turn by writing `.git/orbit/receipt.json` in the workspace with its own file tools. No helper command exists. The turn prompt states the schema, the turn's thread and the outcomes its role may use:

```json
{"thread": 14, "outcome": "ready_for_review", "summary": "The export command works and tests/Feature/ExportTest.php covers it.", "deliverables": {"export-test": "tests/Feature/ExportTest.php"}}
```

| Field | Rule |
| --- | --- |
| `thread` | Required. The thread of this turn |
| `outcome` | Required. One of the outcomes the role may use, listed below |
| `summary` | Required, not blank |
| `question` | Required when `outcome` is `blocked` |
| `deliverables` | Optional map of deliverable ID to evidence text |
| `cause`, `pull_request` | Optional, when the prompt asks for them |

| Role | Outcomes |
| --- | --- |
| Implementer | `ready_for_review`, `blocked` |
| Reviewer | `approved`, `changes_requested`, `blocked` |
| Consult | `answered`, `blocked` |

Before Pi starts, the turn creates `.git/orbit` and removes an earlier receipt. After the turn, it downloads the file and checks it in TypeScript. A receipt that breaks a rule is kept out of the result with one fixed code in `receipt_error`:

| Code | Cause |
| --- | --- |
| `tasks.receipt_invalid` | Not a regular file, larger than 64 KiB, not JSON, or a field of the wrong type |
| `tasks.receipt_thread_mismatch` | `thread` is missing or names another thread |
| `tasks.receipt_outcome_invalid` | `outcome` is unknown or not allowed for the role |
| `tasks.receipt_summary_missing` | `summary` is missing or blank |
| `tasks.receipt_question_missing` | A `blocked` receipt has no question |

A missing or invalid receipt is evidence for Swamp to handle.

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

`task:sync` starts `tasks.sync` through `POST /api/v1/tasks/{task}/sync` with a required `Idempotency-Key` and no body. A task VM holds no GitHub credential, so the workspace cannot fetch. When origin moved, because the default branch was merged into the pull request branch or the owner pushed a correction, the push of the author would fail with `tasks.not_fast_forward`. The sync brings the commits in; the author merges or rebases them.

The admission checks an open external group with its own task VM workspace and wakes a parked VM, as the other work admissions do. It captures the repository, the default branch, the branch `task-<group>`, the workspace path and the SSH target, and names the vault key of a read token. As it starts the run, the Gateway mints that token with `contents: read` and writes it to the key. No step of the run calls the Gateway.

On Node 209 the run makes a bare clone of the default branch, fetches the task branch and writes one bundle of both. It copies the bundle to a private directory on the VM with official SSH, runs `git fetch` of the bundle into `refs/remotes/origin/<default branch>` and `refs/remotes/origin/task-<group>`, and reads the two commits. HEAD, the working tree and the local branches stay as they are. A clean up step removes both scratch directories on success and on failure. The result holds the two commits.

## Project check

A `tasks.check` operation runs the Project's configured check once, before the subtask's deliverables. Command builds add the runner plan as a command deliverable; they do not repeat the configured Project check as another command deliverable. Without a configured command, the build adds a Project-check review deliverable. A baseline can include setup commands. A handoff records command deliverables, the workspace tree and checked commit. The result keeps bounded, redacted output, and native history keeps each finished step, so recovery does not run a finished check step again.

### Check output

The check result contains status, exit code, failed step, changed paths, tree and commit evidence, and bounded redacted output. A failed check is evidence for Swamp, rather than permission to start a repair or retry. A lost supervisor does not prove its children stopped; ownership stays held until stopped work is confirmed.

### Handoff CI

When the requested handoff includes hosted CI, the operation sanitizes the checked commit, pushes without force to the handoff branch and waits for that exact commit's required checks. Its pinned plan and push identity survive a lost reply. Swamp owns budgets, cancellation and any next action. See [Check an external group](#check-an-external-group).

### Baseline check

Swamp requests a baseline before implementation when its workflow calls for one. The operation runs setup and check commands, records evidence and returns. A failure starts no agent. In a task copy the baseline runs no setup steps, because the copied seed already ran them ([check phases](/reference/task-instance-workspace#check-phases)).

### Scoped task check

The Orbit Project whose default Instance is a container uses `bin/task-check` as its check. The candidate gate, `bin/review-check`, checks all five Composer projects and does not fit a handoff in a copy. `bin/task-check` checks only what the task changed since its start commit, and stays under five minutes.

A person sets it on the Project once, after the release that ships the command. `orbit project:update` takes the numeric Project id; the Orbit container Project is 78:

```bash
orbit project:update 78 --task-check=bin/task-check
```

Orbit runs every task check with `ORBIT_CHECK_PROCESSES=1`, whatever the copy's CPU count, so the check runs PHPStan and Pest in one process. Its five-minute budget holds at that value.

Another Project that develops Orbit sets the same setting on its own id. Its copy must hold what the parts run: the vendor folders of the Composer projects, Deno for the Swamp parts and Node for the flow charts. A seeded copy holds them; the command installs nothing. Project 46 keeps `composer check` as its task check, as [its task policy](/reference/instance-setup#configure-orbits-task-policy) states.

The check runs at the repository root, as every task check does.

#### Start commit

The start commit is HEAD's merge base with `origin/main` when that ref exists, else with `main`. The first line of the output names the start commit and the ref that gave it. `bin/review-check` uses the same rule.

A copy's branch starts at the seed commit, which is `origin/main` in the copy, so a branch at its start is checked against the seed. A copy never fetches, so its `origin/main` is the driver's to move. A driver that brings a newer main into the branch first moves `origin/main` to it, from a bundle made from the real `main`, then merges or rebases:

```bash
git fetch /tmp/main.bundle +main:refs/remotes/origin/main
git merge origin/main        # or: git rebase origin/main
```

The branch then holds that main, so the start commit is that main, and main's own commits are not counted as the task's changes. If `origin/main` stays at the seed, the merged commits count as changed paths. Moving only `main` changes nothing, because `origin/main` comes first.

#### Docs-impact waivers in a copy

The `docs-impact` part reads waivers from the branch's proposed PR body in the copy, `.git/orbit/proposed-pr-bodies/task-<id>.md` under `/srv/workspace`. Nothing else puts that file there: the implementer writes it before the handoff check, with one line for each impacted page that needs no change:

```text
Docs-unaffected: docs/reference/tasks.md: <the specific reason>
```

When a page is neither changed nor waived, the part's output names the file and says whether it is absent or holds no valid line for the page. The reviewer confirms the reason.

#### Parts

[The scoped task check](/reference/implementation-loop#the-scoped-task-check) lists its parts, its budget and what it leaves to CI. A failed part fails the check.

#### Stored result

Orbit stores the last 16 KiB of the check's output, redacted by [the one rule](#keep-command-output-out-of-run-history), as the check's `output`. `task:check`, `task:show` and the API's check `output` return it. `bin/task-check` ends its output with a summary of at most 12 KiB, so the stored output always holds the whole summary:

- `Task check summary`, then one line per part with its outcome, its seconds and its detail.
- For each failed part, `Last lines of <part>:` and the newest lines of its output. The failed parts share the bytes that the other summary lines leave.
- The parts CI alone runs, then `Task check PASSED` or `FAILED`, the start commit and the seconds.

Each line of the summary and of a part's output is at most 300 bytes. A longer line keeps its start and ends with `… (N bytes)`, such as a list of budget-skipped fixture units. The output before the summary, each part as it ends, fills the rest of the 16 KiB.

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

`task:github-reviews` reports stored observations. It does not grant merge authority or complete a group.

### Settle metrics

The board retains recorded lifecycle times, PR identity, token usage and line changes. Thread and workspace observations refresh recorded metrics. No metric triggers work.

### Lifecycle times

`started_at`, `settled_at`, `completed_at` and `merged_at` describe recorded task history. Swamp records lifecycle transitions through the existing APIs. Task insights read those records.

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

The ready and merge steps, and the comment of a review round, end with the shared child `task-github-cleanup`. It revokes the token when the mint step succeeded, as a best effort, and removes the private GitHub CLI configuration directory of the run.

### Gate check

The Gateway scheduler runs `task:pr-gate` every minute while the Tasks extension is on. The command only reads GitHub and admits steps; it changes nothing on GitHub, and Swamp steps never call back to the Gateway. It looks at each open external group that has a published pull request and belongs to a Project with [review and merge](/reference/projects#review-and-merge) enabled. For each one it reads the pull request with a read-only App token and then:

- A pull request in draft gets the ready step in the review mode of the group. Admission requires a green merge check on the head.
- The ready step also requires a passed handoff check of the group on that commit.
- A spec-only pull request, such as the one `task:prep` opens, has no such check and stays a draft.
- The owner's approval of its head [starts the build](#start-the-build) instead.
- A manual group with a pull request out of draft gets the merge step. Admission requires the reviewer's latest review to approve the exact head, and the merge check to be green.
- An automatic group with a pull request out of draft gets one review round per head.

A round is one Swamp run, `tasks.review_round`, with the workflow `operation-task-review-round`. Its chart is `docs/cli/task/review-round.mmd`. The gate check admits it once for each head, with the whole intent. The intent holds the task VM identity and a fresh reviewer turn in a new thread on the head. A group without a VM claims a fresh task place at the reviewed head; a running VM is used directly. In rounds 1 and 2 it also holds the fix: the author turn, the local check and the push.

Admission requires a green merge check on the head. The head must be a passed handoff candidate of the current subtask, or the commit that an earlier round pushed.

Admission captures the passed handoff check result (including the local check of an earlier pushed fix) and the hosted Required-check conclusions read with the Gateway App token. Only check identities and result fields reach the reviewer, never logs or GitHub credentials. The evidence names the reviewed head and checked tree. Before sending the reviewer prompt, Swamp rejects evidence for another candidate head or workspace tree.

The run resumes the VM and lets the reviewer judge the head. It posts the verdict and the findings as a comment of the Orbit App, so the owner can read them. The comment mints its own token when it runs. A comment that GitHub refuses does not fail the run.

When the reviewer ends with `approved`, the Gateway records a pass for that head from the end of the run. The next tick admits the merge step. In automatic mode the step requires that pass on the exact head, not an approving review. A run that ends in error never records a pass.

When the reviewer ends with `changes_requested` in round 1 or 2, the same run goes on. The author turn runs in the thread of the author, with the findings as its prompt. Then the handoff check of the subtask runs without hosted CI. Then the run pushes the checked candidate to the branch of the pull request.

The pull request runs its own CI on the pushed head, so the push has no hosted handoff CI. The row of the local check stays on the board. No `tasks.check` operation backs it, so it is no candidate for a review turn or a manual publish. The Gateway records the pushed commit on the round, and the next admission accepts that commit through the round row.

The new head gets a new CI run. The next tick admits the next round on it once the merge check is green. A step that fails, or ends with a turn that is not ready or a check that did not pass, leaves findings without a pushed commit. The gate check then sends the group back to manual mode.

A failed round ends with one of these codes, and the round records the verdict it reached:

| Code | Meaning |
| --- | --- |
| `task_vm.resume_failed` | The VM did not resume. |
| `tasks.review_fix_not_ready` | The author turn did not end `ready_for_review`. |
| `tasks.review_fix_not_passed` | The local check of the fix failed, changed the tree, or left no result. |
| `tasks.review_round_failed` | The reviewer left no verdict, the token clean-up failed, or findings were not pushed. |
| `tasks.review_round_refused` | At the start, the task VM or its Pi credential was gone. |
| `tasks_pr.vm_unavailable` | At admission, the VM was neither parked nor running. |
| `tasks_pr.no_author_thread` | At admission, the current subtask had no author thread to fix with. |

A failed push ends with its own code, as for `task:publish`, for example `tasks.not_fast_forward`.

A group switches to manual mode after round 3 with findings. It also switches after a round that failed or ended `blocked`, and when a new head finds the three rounds used. After the switch the ready step runs again and asks the reviewer. The pull request then waits for the approval of the head.

A pending or red check, a missing approval, a busy group, a moved head or a failed read is a quiet skip, and the next tick reads again.

Every admission carries the Idempotency-Key `pr-gate:<step>:<group>:<head>`, so a repeat tick on the same head replays the recorded step and starts nothing. A step that failed is not tried again on the same head. A push moves the head and the check starts again, or the operator calls the step by hand with a new key. The gate sets the key with `OperationIdempotency::under`, so the key never outlives its admission.

The command prints one JSON object with a result for each group: `admitted`, `replayed`, or `skipped` with the refusal code. The scheduler log keeps these results.

The Gateway keeps one row for each round, with the head, the round number, the operation of the run, the verdict, the commit that the run pushed and the check row of the fix. A group has at most 3 rounds.

A group is put in automatic mode with `orbit task:create --review=automatic`; without the option it is in manual mode.

### Not built

These parts of stage 4 do not exist yet:

- The automatic gate: a fresh self-review agent with at most 3 review rounds.
- An `orbit` command for each step, and a proof case for each step.
- The Swamp prep flow that opens the spec pull request as a draft with `task:publish --draft`.

Until the App opens the pull request, its author is `nckrtl`. GitHub refuses `nckrtl` as its reviewer, and an author cannot approve their own pull request. Manual mode therefore needs another reviewer login, or the App as author.

## Review and merge

The Gateway retains existing Project settings and review records for the board. Swamp owns incoming PRs, final-review tasks, approval and merge. Swamp owns any workflow that uses those records. A Project's review-and-merge setting does not prevent it from creating an external group.

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
- `breakdowns`: `tasks`, `merged`, `tokens_per_merged_pr`, `lead_time_ms`, and `yield` for each implementer model, each Project, and each [Gateway release](/reference/gateway-recovery). A task belongs to the last verified release that began before the task's `started_at`.

### On the board

The strip shows tokens per merged PR with its cost, lead time, yield, first-pass pull requests, and wasted tokens. Each shows its change against the previous period, green when better and red when worse. Fewer tokens, a shorter lead time, and less waste are better. A higher yield and first-pass share are better. The strip has a 7-day and 30-day switch and a Project filter.

**More** opens the rest: the weekly trend, the daily outcomes, the lead time split into queue, work, and merge wait, the other numbers above, and the breakdown table. The browser remembers whether the strip is open. The strip shows on the Tasks board only, not on an Instance's board.

## Agent viewer

Task state reads answer from recorded Gateway state: `task:show`, `task:list`, `task:agent:list`, `task:agent:show`, insights, and the web board. Reads never contact a task VM, Pi server, or Node. A parked or deleted VM does not change that rule. The Swamp run view may read recorded runs from Swamp, the trusted control plane.

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

An admitted cancel answers HTTP 202 with its `tasks.cancel` Operation. The run releases the task's VM and its place (`task_vm.slot_release`): it deletes the VM, its seed clone and its identity volume, and removes the hub peer. When the run passes, the Gateway deletes the workspace Instance and the Node, and marks the task and its open subtasks `cancelled`. Cancel runs no Project teardown: the workspace lives on the VM's disk and goes with it. Subtasks, comments, and thread links stay as history. Cancel does not stop the agent conversations.

A task that holds no VM and no workspace, such as one in `todo` or one that waits for a place, has nothing to release. Admission marks it and its open subtasks `cancelled` and records the Operation as succeeded at once, with no run (R1). So the 202 already carries a succeeded Operation, and `task:show` reads `cancelled` from then on. The same holds for `tasks.maintenance_finish` on a task whose VM publication already released.

Cancel asks a running group Operation to stop. If it is still running, the call returns HTTP 409 `operation.target_busy` with its id and confirms the stop request. Run `task:cancel` again once that Operation has ended. A timed-out Operation whose run nothing has proven ended still holds the group, for up to an hour; the cancel then returns the same 409 before it admits the destroy of the task's copy.

- **Work only a task copy holds.** A cancel refuses a [task copy](#task-copy) with work origin lacks. It returns HTTP 409 `tasks.copy_unpublished_work` with the counts.
- **Published commits.** A succeeded `task:publish` put its commit and that commit's history on origin. They do not count.
- **Unread task copy.** A cancel refuses a copy it cannot read: HTTP 409 `tasks.copy_workspace_unknown`, with the reason.
- **Discard.** `discard: true` (CLI `--discard`) lets the cancel destroy that work; `--yes` never does. Complete needs no discard, because origin has the approved work.
- **Approved work not on origin.** Cancel and complete refuse with HTTP 409 `tasks.approved_work_unpublished` and change nothing. Run `task:publish` first; it resumes a parked VM.
- **Parked VM.** Neither cancel nor complete starts a parked VM. The release deletes it as it is.
- **Refused admission.** `tasks.not_cancellable`, `task_vm.release_refused` and a busy VM Operation change nothing.
- **Failed run.** A cancel run that fails, or whose start Swamp refuses, keeps the task and marks its VM `failed`. Cancel again to release it.

Cancel and complete keep approved work by a precondition, never by a push of their own. The precondition applies while the task holds a workspace or a task VM. Origin's task branch must reach the latest approved commit. That commit is the latest commit Orbit reviewed (ADR 0203), or else the commit of the latest approval.

Only when GitHub answers that the branch does not exist and the task's pull request merged does that pull request's head stand in for the branch. Commits and refs are read with a `contents: read` token. An earlier approved commit that merged does not cover a later one. The refusal names the commit and the branch. Every other outcome refuses the same way, such as a failed GitHub read or a branch that is gone without a merged pull request.

A cancel also refuses while an Operation is open on the task VM, such as a resume. Every refusal comes before the cancel stops running work. An admitted cancel marks the VM `releasing`, so no later resume starts it. When Swamp refuses the cancel's start, the cancel fails and the VM is marked `failed`, so another cancel can reserve it again. Uncommitted changes and work that no review approved go with the VM.

When cancel marks the task `cancelled`, it follows the [ending rule](#assistance-and-resolution): only a removal failure may keep asking for assistance. It clears the open subtasks' requests and keeps their last reasons.

## Complete and cleanup

Swamp requests group completion or cancellation through the API. The Gateway stops admitted work and performs the requested workspace removal. A failed remote stop or removal preserves the owned workspace and error evidence for retry. Swamp schedules retries. The host cleanup job releases failed, unclaimed Incus task starts through `task_vm.slot_release`; it does not orchestrate task groups.

`task:complete` accepts a settling or waiting-for-review group. It can also complete a running or reviewing group when its watched PR has ended. Repeating completion on a completed group retries its attached workspace removal. An explicit cancellation or completion remains available even though automatic orchestration is removed.

Teardown steps run before the workspace and task VM are destroyed, and only while the provider reports the VM running. Origin must already have the approved work, as [Cancel a stuck task](#cancel-a-stuck-task) describes. Cleanup does not choose new task work or merge a PR.

### Task copy cleanup

A task whose workspace is a [task copy](#task-copy) has no task VM. `task:complete` admits the copy's normal `instance.destroy` with force, as `task:cancel` does, marks the task `completed` and answers HTTP 200 with the task. An open destroy of the copy is reused. When the destroy succeeds, the task lets go of the copy and stays `completed`. When it fails, the task keeps the copy, and completing again admits a new destroy.

A cancel of a copy records the `tasks.cancel` Operation under the group's lock key and admits the destroy in one transaction, record first. An Operation that holds or takes the group lock refuses the record with `operation.target_busy`, and the transaction admits no destroy.

A copy turn runs on the `t3` driver, which the driver registry does not hold. Its turn is its `tasks.copy_turn` Operation, which complete stops as it stops every running group Operation, so the subtask stop interrupts no `t3` thread.

### Task VM cleanup

Task VM cleanup is the release `task_vm.slot_release`. `task:cancel` runs it as the last step of its own run. `task:complete` runs the Project teardown while the VM is `ready` and retains recorded agent transcripts, admits the release, marks the task `completed`, and answers HTTP 200 with the task. The release then deletes the VM, the Node and the workspace Instance in its own run.

Release admission locks and re-reads the VM. It reuses an open release, and any other open Operation on the VM, such as a resume, refuses it with `operation.target_busy`. A refused release still completes the task, requests `removal_failure` assistance and keeps the Instance attached; completing again retries the release. A release that passes ends that request when it deletes the workspace. A VM of the old pool, without a place number, is released the same way, by its name ([release a task VM](/reference/compute-drivers#release-a-task-vm)).

## Configuration

These Gateway environment keys configure the extension.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER`, `ORBIT_TASKS_REVIEWER_AGENT_DRIVER` | The drivers of new tasks. Both default to `pi`. Any other value is `tasks.agent_driver_unavailable` |
| `ORBIT_TASKS_IMPLEMENTER_MODEL`, `ORBIT_TASKS_REVIEWER_MODEL` | The models of new tasks. Both default to `gpt-5.6-luna`. A Claude model is refused |
| `ORBIT_TASKS_IMPLEMENTER_EFFORT`, `ORBIT_TASKS_REVIEWER_EFFORT` | The effort of new implementer and reviewer threads, on a task VM and in a task copy. Defaults: `medium` and `high`. See [Drivers](#drivers) for when changes apply |
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

### Publish uses the checked commit and trusted Git

A raw commit would bypass the handoff check. Taking its check ID binds publication to the evidence and the commit recorded together. Exporting that commit through official SSH lets a trusted bare repository on 209 push it without lending the VM a GitHub token or loading its Git configuration. One observation checks the workspace before export; the import checks that the bundle holds the same commit and tree, so no second observation is needed.

An open-pull-request lookup lets a new admission reconcile a lost create reply. The orchestrator owns review gates and board states, so publication adds no brief coverage, reviewer request, or settling decision.

A push that started can reach GitHub even after its client stops. Native cancellation therefore does not prove that the remote branch stayed unchanged. A fresh admission pushes again without force: Git pushes nothing for an identical tip and refuses a divergent one. The group's VM workspace path remains part of intent, with no new ownership mechanism.

These reasons explain the design. Check them before you propose a change.

### External checks are their own operation and own their commit

A turn and its check are separate operations. The group's Operation lock makes the check wait for the turn to end. Detached check supervision gives every requested check one execution and output rule. A failed check is evidence for the orchestrator, so it is a result rather than an operation failure. A passed handoff commits the checked tree so later review and publication can name one recorded commit.

In a task copy, a clean checked tree is already a commit, the implementer's `HEAD`, so it is the recorded commit and the check makes no empty one ([check phases](/reference/task-instance-workspace#check-phases)).

The [hosted-CI prerequisite](#check-an-external-group) needs a fresh sanitized commit per operation because sanitizing a proposed message cannot remove skip directives inherited from an old commit. Recovery must retain that same commit, or a lost reply would create extra commits and pushes. Pinning the CI plan prevents a Project edit from changing what passed. Reviewer and publish admission share one predicate because a local pass cannot prove hosted CI completed; the owning successful operation supplies that evidence without a duplicate check-status schema.

A copy runs no handoff with hosted CI, so its clean-tree candidate keeps the implementer's message. That is an accepted risk: a skip directive in that message, such as `[skip ci]`, reaches the draft pull request's push, and GitHub then skips its CI. The skip-directive rule has one home, the Gateway's handoff message, and the Gateway never reads the copy's commit messages. The reviewer reads the commits before approval.

### An external turn records evidence without choosing the next step

The orchestrator owns the prompt, gates, budgets, and assistance. The turn operation only sends, waits, and records evidence. Applying a blocked receipt inside Orbit would create a second lifecycle owner. Durable owns restart resume and provider retries, so the Gateway never starts a replacement turn for them. Reviewers reset to the passed handoff commit to preserve what was checked. Consults keep the tree because it contains the implementer's work.

### Provision keeps effects separate from lifecycle decisions

An external orchestrator needs the same task VM and workspace as the other keyed operations, without starting a baseline or an agent. Provision shares the workspace provisioner and stops at a usable workspace. One operation hold per group keeps later task effects from overlapping. Capacity waits retain the reservation and release the operation worker; configuration and VM errors end the group instead of starting a Gateway retry policy. Clearing the reservation on cancellation lets existing cleanup own removal.

That VM is always large: the admission of the refresh passes the size to `TaskProvisionIntent`, so no Project size list decides it. The [compute drivers](/reference/compute-drivers#apply-and-check-the-nested-fleet) page has the steps.

### An optional, generic extension

Tasks is an extension, so an operator can switch it off without a Gateway downgrade. The engine knows tasks, subtasks, deliverables, one task check, and a lifecycle. Each Project's own policy and task check decide how it plans and verifies work. Inferring policy from a slug, manifest, or CI job name would create a second hidden contract in the Gateway, so those choices belong to the Project. The ADE plans, because planning needs the conversation with you. A web form to create tasks would be a second path beside MCP and the API.

Three alternatives were rejected. Selecting one Project's behavior by its slug would keep a second task policy in the Gateway. Inferring that policy from repository files would hide it in the engine instead of the Project's skill and task check. A compatibility path for a planner thread was rejected, because you plan with an external ADE and the engine keeps no planner state.

Shared prompts stay free of Project policy. They do not name a feature contract or an Orbit lease rule. The repository's instructions and `orbit-tasks` skill carry that policy.

### Every task group runs on its own task VM

Each task group gets its own [task VM](#task-workspace). [One user](/reference/pi-server#one-user-for-every-task-agent) runs its agents and checks in the same environment. The [VM edge](/reference/compute-drivers#why-task-vms-work-this-way) is the boundary. A task waits for its VM; there is no shared-host fallback.

Operators can inspect the task Node's last reported resource use with [`node:metrics`](/cli/node/metrics). The read does not contact or wake the VM. An offline VM retains its last snapshot with the original observation time and a `possibly outdated` label; this is resource evidence, not proof that an agent turn is still running.

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

Task VM image build incidents recover after a verified build or a superseding recipe generation.

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

The base tree is an extracted archive in the run's scratch directory on the VM, `/tmp/orbit-task-check-<run>/base-<n>`, not a registered worktree, because a killed run would leave a registered worktree that blocks removal of the clone. The run removes the directory at its end. Exit 126 or 127 is not that proof: the command did not run.

### Assistance is recorded in Activity

Each assistance change has an Activity entry, so an operator can trace the kind, question, and reason without an external notification service. Waiting on another task or pull request is not an assistance kind: a dependency wait that resumes on its own is a separate feature. The operator answers through the CLI, MCP, or API; a web answer box is outside this feature.

### Questions are records, not parsed comments

Each consult and direction request has a record with its answer and cause, while comments keep the conversation. Questions kept only in comment bodies would need free-text parsing before the operator could count them, group them by cause, or trace them to a brief. The records and the `questions` and `escalations` counts show where briefs, contracts, and subtask scopes need attention.

The reviewer chooses a cause from a fixed list when it hands off, because it already holds the contract and the answer. Asking a model to infer the cause later was rejected: the handoff has the evidence, and a fixed list avoids another model call and its cost. A cause stays empty until that reviewer receipt, so an open, escalated, or migrated question does not claim a diagnosis nobody has made.

### Transcripts outlive the task VM

A task VM is deleted when its task ends, and its Pi sessions go with it. Without a copy, nobody can read what an agent did after the task settles, so prompts cannot be audited or replayed against real turns. Swamp records redacted transcript evidence when each turn ends. The Gateway keeps it after the VM is deleted. Cleanup needs no live transcript read.

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

Host T3 task threads ran as the operator's Unix user and had that user's full access. Pi task agents run as a dedicated `orbit-agent` account. The operator approved that split on 2026-10-01. T3 Code stays installed as the operator's own tool.

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. Task agents therefore cannot use Claude, and they do not keep a second runtime to reach it. One driver, Pi, owns implementers and reviewers on a task VM. A task copy runs its implementer and reviewer turns with T3's own CLI as `agent`, as [Swamp drives T3](/reference/development-containers#swamp-drives-t3) describes. This serves [one way, one name](/mission#principles) and [no exceptions and no legacy](/mission#principles).

Keeping T3 as a selectable driver would keep two restart rules, two metric paths, and two archive paths. Pi owns restart recovery and reports usage on its sessions. Swamp has no T3 metric collector or thread archive.

Both roles default to `gpt-5.6-luna` at `high` effort. Keeping `claude-opus-5` as the reviewer default would make each new review fail on Pi. Each role keeps its driver setting, with a `pi` default, because deployment selects Pi explicitly. A different configured driver fails before a new task is stored.

Finishing an open T3 task turn would preserve the second runtime, so a managed task that records a driver other than `pi` never starts or resumes an agent turn. The operator cancels or replaces it. Deleting its thread row, metrics, or `t3_*` columns would erase the record of work that already ran, so that history stays. A transcript request returns HTTP 409 `tasks.agent_transcript_unavailable` rather than contacting T3. Pi session files stay on the Node; Orbit keeps their thread rows and metrics too.

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

Swamp runs task turns on the Swamp role worker and reaches the task VM over SSH. The Gateway admits the group, current subtask, role, model and session, then starts `operation-task-turn`. It records the returned thread state, workspace identities and token metrics. It does not send Pi messages, wait for turns or acquire workspace execution leases. Swamp's native workflow owns placement, locking, cancellation and recovery.

Pi writes and reviews code inside the VM. Swamp clears the old receipt, then sends the prompt and receipt schema with a stable Pi submission key and consumes the Pi event stream until the turn ends. A resumed step uses the same session and submission key. Git snapshots use a temporary index, include untracked files and leave the real index unchanged. The turn trusts the admitted workspace. Reviewer changes are captured and the candidate is restored with Git reset and clean before the result returns. VM provision, readiness and enrollment belong to the task VM workflows.

### Internal container workspace phases

[Task Instance workspace phases](/reference/task-instance-workspace) define the internal evidence and export contract. `tasks.check` uses their [check phases](/reference/task-instance-workspace#check-phases) when a group owns a container Instance workspace: a [task copy](#task-copy).
