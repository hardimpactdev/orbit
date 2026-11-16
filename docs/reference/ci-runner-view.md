---
title: "CI runner view"
description: "What the web app's runner view shows about each CI Node and runner, where each value comes from, how fresh it is, and the read endpoint GET /api/v1/ci/runners behind it."
covers:
  - apps/gateway/app/Actions/Ci/ShowCiRunnersAction.php
  - apps/gateway/app/Data/Ci
  - apps/gateway/app/Http/Controllers/Api/CiRunnersController.php
  - apps/gateway/routes/api/ci.php
  - apps/gateway/app/Infrastructure/GitHub/GitHubActionsJobs.php
  - apps/gateway/app/Infrastructure/Metrics/PrometheusCiRunnerStates.php
  - apps/gateway/app/Domain/Nodes/CiRunnerStateFiles.php
  - apps/gateway/resources/ci-runner/state.py
---

# CI runner view

The runner view shows what each CI place is doing. Open it at `/runners` in the [web app](/reference/web-app). A CI Node is a Node with an active [`ci-runner` role](/reference/node-provisioning#ci-runner-role). The view only reads. It starts, stops and re-runs nothing. [ADR 0260](/decisions/0260-share-ci-places-between-runners-and-proof-copies#orbit-shows-what-each-place-is-doing) decides what it shows.

## What the view shows

The view uses Orbit's terminal style. It shows one frame for each CI Node:

- A places bar. Each place is free, runs a runner job, or holds a proof copy. A proof-only Node is marked "proof copies only".
- The Node's CPU pressure, memory and disk.
- Each runner: running or stopped, idle or busy, its runner version, and its current job and repository.

Below the frames, the view lists the running and queued jobs. Then, for each runner, it shows its jobs of the last 48 hours: the count, the failures, the queue wait and the run time, and its recent jobs.

A place counts as used while a runner is busy or a proof copy lives on the Node. An idle runner holds no place. The count of places is the Node's [`ci.places`](/reference/node-settings#ci-places) setting. When a Node has no `ci` setting, the bar shows its used places and no free count. On a Node with `ci.proof_only`, runner jobs never use a place, so only proof copies fill its bar. A busy runner on that Node still shows as busy in its row.

## Where each value comes from

The view joins four sources. Each has its own age, and the response gives the observation time where a source has one.

| Value | Source | Freshness |
| --- | --- | --- |
| Runner state, service, busy flag and version | The `ci-runner` role's [state timer](/reference/node-provisioning#runner-state-for-the-runner-view) writes them on the Node. The Metrics role's Prometheus scrapes them through the node exporter, and the Gateway reads Prometheus. | The timer writes every 15 seconds, and Prometheus scrapes every 10 seconds. The Gateway keeps one answer for 10 seconds. A reading older than 60 seconds is stale. |
| Proof copies | The Gateway's own Instance records: each live Instance on the Node that a [proof](/cli/proof/run) run's task group owns. | At the request. |
| CPU pressure, memory and disk | The latest metrics snapshot of the [Node agent](/reference/node-agent#facts), the same one that [`node:metrics`](/cli/node/metrics) reads. | The agent reports every 60 seconds. `possibly_outdated` marks a Node whose agent is not online. |
| Jobs, queue wait, run time and failures | The Actions job lists of the repositories, read with the [GitHub App](/reference/github-app)'s existing `Actions: read`. Each job names its runner in `runner_name`. | The Gateway keeps one answer for 60 seconds for every viewer. |

A runner's name is its container name in the Incus project `orbit-ci`. `setup.sh` registers the runner with the same name, so a job's `runner_name` matches it.

The repositories are the same set that the [fleet reboot](/reference/gateway-recovery#ci-jobs-lost-at-the-freeze) reads: each repository of a Project read through the GitHub App, when the App is installed on it. The Gateway leaves out skipped runs and every job that did not ask for a `self-hosted` runner.

### The workflow filter

A busy repository has many more runs than self-hosted jobs. On 16 November 2026, `hardimpactdev/orbit` had 1,592 runs in 48 hours, while 11 runs of that day held its 137 self-hosted jobs. So the Gateway reads the runs of a workflow only when the workflow can ask for a self-hosted runner.

For each repository, the Gateway lists the workflows and reads each workflow file on the default branch, with a token that holds only `contents: read` from the App's existing `Contents: write`. A workflow is left out only when its file shows that every job asks for labels without `self-hosted`. The Gateway keeps a workflow, and reads its runs, when:

- a job's `runs-on` names `self-hosted`, also inside `labels` of a runner group;
- a job's `runs-on` is an expression, such as `${{ matrix.runner }}` or `${{ fromJSON(vars.ORBIT_PROOF_RUNNER) }}`;
- a job calls another workflow with `uses`;
- the file does not parse;
- GitHub gives no content for the file.

GitHub gives no content when the default branch has no such file or the file is over 1 MB. It gives none for its own workflows either, such as `dynamic/dependabot/dependabot-updates`, so the Gateway reads their runs too.

The Gateway keeps this list, and the verdict on each file, for an hour. A workflow that starts to ask for a self-hosted runner shows its jobs within the hour; the window still holds its older runs. A workflow that could ask for one stays in the list for 49 hours after its file stops asking, so its self-hosted runs stay in the window. The file on the default branch decides: a branch that makes a GitHub-hosted workflow ask for a self-hosted runner is not seen until the change reaches the default branch.

### Paging

For each kept workflow, the Gateway lists the runs created in the last 48 hours and the latest attempt's jobs of each run. It reads each list in pages of 100 until it holds GitHub's `total_count`, so a run with 301 jobs gives all 301. GitHub gives at most 1,000 runs for one run list query, so a window with more runs of one workflow is read in halves until each half fits.

The jobs of a completed run do not change, so the Gateway keeps them until they leave the window and reads again only the runs that have not completed. A workflow that runs only on GitHub-hosted runners costs no run list and no job list.

### Time budget and `truncated`

One answer reads workflow files and the jobs of runs that it did not keep for at most 20 seconds. The run lists and the kept jobs are always read, so a repository keeps its known jobs when the time ran out. When the time runs out, the Gateway reads no more files or job lists, and each repository with a run or a file left unread is `truncated`.

The next answer starts with the first repository that the last answer did not finish, then takes the others in order. When the repository that started the last answer is still unfinished, the next unfinished one starts instead, so one busy repository cannot keep the others unread. Each answer goes on from the runs and files that the Gateway kept. `truncated` means only that the time ran out.

### Requests to GitHub

The installations of the App share GitHub's rate limit of 5,000 requests an hour with every other reader. The Gateway builds at most one answer a minute, whatever the number of viewers. For each repository, one answer makes these requests:

| Case | Requests |
| --- | --- |
| No workflow can ask for a self-hosted runner, list kept | None. |
| Steady state | 2 App requests for the `actions: read` token, and the run and job lists below the table. |
| Once an hour, when the workflow list is read again | 2 more App requests for the `contents: read` token, one workflow list page per 100 workflows, and one read of each workflow file. |
| A run that completed since the last answer | One job list page per 100 jobs, once. |

In steady state, each kept workflow costs one run list page per 100 runs of the window, at least one. Each run that has not completed costs one job list page.

A window with more than 1,000 runs of one workflow costs one more page at each split. The Gateway reads the first page of the whole window, sees the count, drops the page, and reads the halves.

Take `hardimpactdev/orbit` with about 1,000 runs of its kept workflows in the window and five runs in progress. Its steady state is 2 token requests, about 10 run list pages and 5 job list pages. That is about 17 requests an answer, and about 1,000 an hour while someone watches the view. A repository without self-hosted workflows adds no run list or job list read to an answer. It still costs the hourly token, workflow list and file reads of the table.

The worst case is a start without kept runs, for example after the cache was cleared: each run of a kept workflow needs one job list read. The run lists are read in full, and the 20-second budget bounds the file and job list reads; at GitHub's usual 0.2 to 0.5 seconds a request, that is 40 to 100 requests an answer. The answers of the next minutes go on from the kept runs, until they cover the window.

## What the view never does

The view stays a cheap read. It keeps these limits:

- It never reads a runner list from GitHub, for the organization or a repository.
- It asks GitHub for no new permission. Every token holds one read permission for one repository: `actions: read`, or `contents: read` for the workflow files.
- It starts no Operation and no Swamp workflow for a page view.
- The Gateway does not connect to a Node over SSH for a request, and sends no Node agent command.
- It changes nothing on a Node or on GitHub.

## Read the view

`GET /api/v1/ci/runners` returns the whole view in one response. The web app's runner view reads it. The CLI and the PHP SDK have no counterpart.

The caller needs Gateway authority under the [shared Node access rule](/cli/node#authority): the active Gateway Node, the host that serves the Gateway, or a caller with a grant to the active Gateway Node. The view covers every CI Node and the jobs of every repository, so a direct grant to one CI Node is not enough.

A source that cannot answer does not fail the read. The response marks that part with an error code and still returns the rest.

### Response

`data` holds these fields.

| Field | Meaning |
| --- | --- |
| `generated_at` | When the Gateway built the response. |
| `window_hours` | The job window, `48`. |
| `nodes` | One entry for each CI Node, ordered by name. See below. |
| `repositories` | One entry for each repository read: `repository` as `owner/name`, `error_code`, and `truncated`. See below the table. |
| `github_error_code` | Null, or the reason that no repository was read. |
| `jobs` | Every job of the window that asked for a self-hosted runner, newest first. See below. |

In `repositories`, `error_code` is null when the read succeeded. `truncated` is true when the answer's 20 seconds ran out before the Gateway read every run and workflow file of the repository. The next answer starts with that repository. See [Time budget and `truncated`](#time-budget-and-truncated).

Each entry of `nodes`:

| Field | Meaning |
| --- | --- |
| `node_id`, `node_name` | The Node's ID and name. |
| `places` | `total`, `proof_only`, `runner_jobs`, `proof_copies` and `free`. See below the table. |
| `proof_copies` | Each proof copy on the Node: `instance_id`, `instance_name` and `task_group_id`. |
| `resources` | Null when the agent reported no metrics snapshot yet. Else `observed_at`, `possibly_outdated`, `cpu_pressure` and `memory_pressure` (the `some` `avg10` values), `memory` and `disk` (each `used_bytes` and `total_bytes`; `disk` is the `/` mount). |
| `runner_state` | `observed_at`, the time the Node's timer read its runners; `stale`, true when that is more than 60 seconds ago; and `error_code`, null when Prometheus answered with a reading for the Node. |
| `runners` | Each runner container, ordered by name. Empty when `runner_state.error_code` is set. See below. |

In `places`:

- `total` is the Node's `ci.places` setting. It is null when the Node has no `ci` setting.
- `proof_only` is the Node's `ci.proof_only`. It is false when the Node has no `ci` setting.
- `runner_jobs` counts the busy runners. It is 0 on a proof-only Node.
- `free` is `total` minus `runner_jobs` and `proof_copies`, never below 0. It is null when `total` is null.
- `free` is 0 on a Node that takes no proof copies, such as a Node that was not converged after a release changed the [hold script](/reference/node-provisioning#proof-places-on-a-ci-node). A proof takes no place there.

Each entry of `runners`:

| Field | Meaning |
| --- | --- |
| `name` | The container name, which is also the runner's name on GitHub. |
| `state` | The container state from Incus: `running`, `stopped`, `frozen` or `error`. |
| `service` | The state of the runner service in the container: `active`, `activating`, `inactive` or `failed`. `unknown` when the container does not run or has no runner service. |
| `busy` | True while a `Runner.Worker` process runs in the container. |
| `version` | The runner version, or null when it cannot be read. |
| `current_job` | The newest `in_progress` job whose `runner_name` is this runner: `job_id`, `repository`, `workflow`, `job`, `started_at` and `url`. Null when there is none. A busy runner with a null `current_job` runs a job that the Gateway did not read. |
| `stats` | The completed jobs of this runner in the window: `jobs`, `failed`, `queue_wait_seconds_median`, `run_seconds_median` and `busy_seconds`. A job failed when its conclusion is `failure`, `timed_out` or `startup_failure`. The medians are null when `jobs` is 0. |

A runner that a proof stopped while the proof holds places on the Node reads `service: inactive` in a running container ([Proof places on a CI Node](/reference/node-provisioning#proof-places-on-a-ci-node)).

Each entry of `jobs`:

| Field | Meaning |
| --- | --- |
| `job_id`, `run_id` | GitHub's IDs of the job and its workflow run. |
| `repository` | `owner/name`. |
| `workflow`, `job` | The workflow name and the job name. |
| `runner_name` | The runner that took the job, or null while the job is queued. It can name a runner outside the CI places, such as the host-level proof runner. |
| `status`, `conclusion` | GitHub's job status and conclusion. `conclusion` is null until the job completes. |
| `created_at`, `started_at`, `completed_at` | GitHub's times. Null until they happen. |
| `queue_wait_seconds` | `started_at` minus `created_at`. Null until the job starts. |
| `run_seconds` | `completed_at` minus `started_at`. Null until the job completes. |
| `url` | The job's page on GitHub. |

### Errors

Only a refused caller fails the read. The other codes appear inside a successful response.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `node_access.required` | 403 | The caller has no Gateway authority. |
| `ci_runner.state_unavailable` | 200, in `runner_state.error_code` | No runner state for the Node. There is no Metrics role, Prometheus did not answer or has no reading, or the timer could not read Incus. |
| `github.app_missing` | 200, in `github_error_code` | The Gateway has no GitHub App, so it read no jobs. |
| `github.unavailable` | 200, in a repository's `error_code` | GitHub refused or did not answer for that repository. |

## Why it works this way

The Node agent is frozen ([ADR 0253](/decisions/0253-patch-nodes-and-reboot-them-drained)), so it gets no command or fact for runners. A read per page view must not start an Operation, because Swamp serve is bound by CPU. The Gateway does not connect to a Node over SSH for a request.

The Metrics role already carries Node state to the Gateway without any of these. The node exporter runs on each selected Node, and Prometheus runs on the Metrics Node. The Gateway's Prometheus read already answers the [Process status](/reference/metrics#process-runtime-status) for the whole fleet in one query. A `ci-runner` Node selects the exporter by default. So the role adds only a small timer script that writes the runner state as a textfile for that exporter. One query then answers for every CI Node, and its cost does not grow with the number of viewers.

The CPU pressure, memory and disk come from the agent's snapshot, because `node:metrics` already reads them there. Proof copies come from the Gateway's records, because the Gateway creates them and knows which run owns each one.

GitHub's organization runner list would also give runner state, but it needs another organization permission. The job lists give the job, the repository and the times, and each job names its runner, so the view does without the runner list.
