---
title: "CI runner view"
description: "What the web app's runner view shows about each CI Node and runner, where each value comes from, how fresh it is, and the read endpoint GET /api/v1/ci/runners behind it."
---

# CI runner view

The runner view shows what each CI place is doing. Open it at `/runners` in the [web app](/reference/web-app). A CI Node is a Node with an active [`ci-runner` role](/reference/node-provisioning#ci-runner-role). The view only reads. It starts, stops and re-runs nothing.

## What the view shows

The view uses Orbit's terminal style. It shows one frame for each CI Node:

- A places bar. Each place is free, runs a runner job, or holds a proof copy.
- The Node's CPU pressure, memory and disk.
- Each runner: running or stopped, idle or busy, its runner version, and its current job and repository.

Below the frames, the view lists the running and queued jobs. Then, for each runner, it shows its jobs of the last 48 hours: the count, the failures, the queue wait and the run time, and its recent jobs.

A place counts as used while a runner is busy or a proof copy lives on the Node. An idle runner holds no place. The count of places is the Node's `ci_places`.

## Where each value comes from

The view joins four sources. Each has its own age, and the response gives the observation time where a source has one.

| Value | Source | Freshness |
| --- | --- | --- |
| Runner state, service, busy flag and version | The `ci-runner` role's [state timer](/reference/node-provisioning#runner-state-for-the-runner-view) writes them on the Node. The Metrics role's Prometheus scrapes them through the node exporter, and the Gateway reads Prometheus. | The timer writes every 15 seconds, and Prometheus scrapes every 10 seconds. The Gateway keeps one answer for 10 seconds. A reading older than 60 seconds is stale. |
| Proof copies | The Gateway's own Instance records: each live Instance on the Node that a [proof](/cli/proof/run) run's task group owns. | At the request. |
| CPU pressure, memory and disk | The latest metrics snapshot of the [Node agent](/reference/node-agent#facts), the same one that [`node:metrics`](/cli/node/metrics) reads. | The agent reports every 60 seconds. `possibly_outdated` marks a Node whose agent is not online. |
| Jobs, queue wait, run time and failures | The Actions job lists of the repositories, read with the [GitHub App](/reference/github-app)'s existing `Actions: read`. Each job names its runner in `runner_name`. | The Gateway keeps one answer for 60 seconds for every viewer. |

A runner's name is its container name in the Incus project `orbit-ci`. `setup.sh` registers the runner with the same name, so a job's `runner_name` matches it.

The repositories are the same set that the [fleet reboot](/reference/gateway-recovery#ci-jobs-lost-at-the-freeze) reads: each repository of a Project read through the GitHub App, when the App is installed on it. For each repository, the Gateway lists the workflow runs created in the last 48 hours, at most 5 pages of 100, and the latest attempt's jobs of each run. It leaves out skipped runs and every job that did not ask for a `self-hosted` runner. The jobs of a completed run do not change, so the Gateway keeps them until they leave the window and reads again only the runs that have not completed. One answer reads job lists for at most 20 seconds, newest runs first. When that time runs out, the repository is marked `truncated`, and the next answer goes on from the runs that the Gateway kept.

## What the view never does

The view stays a cheap read. It keeps these limits:

- It never reads a runner list from GitHub, for the organization or a repository.
- It asks GitHub for no new permission. Every token holds only `actions: read` for one repository.
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
| `repositories` | One entry for each repository read: `repository` as `owner/name`, `error_code`, and `truncated`. `error_code` is null when the read succeeded. `truncated` is true when the Gateway did not read every run of the window: the repository had more runs than the Gateway reads, or the answer ran out of time to read their jobs. |
| `github_error_code` | Null, or the reason that no repository was read. |
| `jobs` | Every job of the window that asked for a self-hosted runner, newest first. See below. |

Each entry of `nodes`:

| Field | Meaning |
| --- | --- |
| `node_id`, `node_name` | The Node's ID and name. |
| `places` | `total`, `runner_jobs`, `proof_copies` and `free`. `total` is the Node's `ci_places`, or null when it has none. `free` is `total` minus the used places, never below 0, and null when `total` is null. |
| `proof_copies` | Each proof copy on the Node: `instance_id`, `instance_name` and `task_group_id`. |
| `resources` | Null when the agent reported no metrics snapshot yet. Else `observed_at`, `possibly_outdated`, `cpu_pressure` and `memory_pressure` (the `some` `avg10` values), `memory` and `disk` (each `used_bytes` and `total_bytes`; `disk` is the `/` mount). |
| `runner_state` | `observed_at`, the time the Node's timer read its runners; `stale`, true when that is more than 60 seconds ago; and `error_code`, null when Prometheus answered with a reading for the Node. |
| `runners` | Each runner container, ordered by name. Empty when `runner_state.error_code` is set. See below. |

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
