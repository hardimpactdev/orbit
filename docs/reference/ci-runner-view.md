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

The runner view (`/runners` in the [web app](/reference/web-app)) shows live capacity and 48-hour job history for every Node with an active [`ci-runner` role](/reference/node-provisioning#ci-runner-role). It is strictly read-only: it never starts an Operation, connects over SSH, or mutates a Node or GitHub.

## What the view shows

Each CI Node renders one frame displaying:

- A **places bar** showing free places, active runner jobs, and live proof copies. Configure capacity with [`ci.places`](/reference/node-settings#ci-places); idle runners consume no place.
- Host CPU pressure, memory, and `/` disk usage.
- Each runner container's Incus state, systemd service state, busy flag, version, and current job.

Below the frames, the view lists running and queued self-hosted jobs. It also shows 48-hour per-runner statistics: job count, failures, median queue wait, median run time, and total busy seconds.

## Where each value comes from

The view combines four read-only sources:

| Value | Source | Freshness |
| --- | --- | --- |
| Runner state, service, busy flag, version | `ci-runner` [state timer](/reference/node-provisioning#runner-state-for-the-runner-view) -> node exporter -> Prometheus -> Gateway | Timer writes every 15s, Prometheus scrapes every 10s, Gateway caches 10s (stale >60s) |
| Proof copies | Gateway Instance records owned by active [proof](/cli/proof/run) task groups | Live per request |
| CPU pressure, memory, disk | Latest [Node agent](/reference/node-agent#facts) metrics snapshot | Reported every 60s (`possibly_outdated` when offline) |
| Self-hosted Actions jobs | GitHub Actions API via the [GitHub App](/reference/github-app) (`actions: read`, `exclude_pull_requests=true`) | Cached 60s across all viewers |

Each runner's container name in Incus project `orbit-ci` matches its GitHub `runner_name`.

### Workflow filter, paging, and time budget

To stay within GitHub's 5,000 requests/hour limit, the Gateway inspects workflow files on each repository's default branch once per hour using a `contents: read` token. It skips workflows whose jobs never request `self-hosted`. Workflows with expressions, reusable `uses:` calls, unparseable files, dynamic GitHub workflows, or edits within the last 49 hours are kept.

For kept workflows, the Gateway pages runs (100 per page, splitting 48-hour windows over 1,000 runs in half) and caches completed runs' jobs until they age out. Each refresh budgets at most 20 seconds for uncached workflow files and job lists. If time runs out, unfinished repositories are marked `truncated: true` and resumed first on the next refresh.

## Read the view

`GET /api/v1/ci/runners` (`Cache-Control: no-store`, with `meta.request_id`) returns the full snapshot for callers with [Gateway authority](/cli/node#authority) (`node_access.required`, 403, otherwise). There is no CLI or PHP SDK counterpart. Partial source failures return HTTP 200 with inline error codes (`ci_runner.state_unavailable`, `github.app_missing`, or per-repository `github.unavailable`).

| Top-level `data` field | Contents |
| --- | --- |
| `generated_at`, `window_hours` | Response timestamp and `48`. |
| `nodes` | Per-Node `node_id`, `node_name`, `places` (`total`, `proof_only`, `runner_jobs`, `proof_copies`, `free`), `proof_copies`, `resources`, `runner_state` (`observed_at`, `stale`, `error_code`), and `runners`. |
| `repositories` | Per-repo `repository`, `error_code`, and `truncated`. |
| `github_error_code` | `null` or global GitHub error code. |
| `jobs` | Self-hosted jobs in the 48-hour window, newest first (`job_id`, `run_id`, `repository`, `workflow`, `job`, `runner_name`, `status`, `conclusion`, timestamps, `queue_wait_seconds`, `run_seconds`, `url`). |

Each runner's `stats` summarizes completed jobs in the window. It includes `jobs`, `failed` (`failure`, `timed_out`, `startup_failure`), `queue_wait_seconds_median`, `run_seconds_median` (integer floor division, `null` when `jobs` is `0`), and `busy_seconds`.

## Why it works this way

Reusing the node exporter's textfile collector and Prometheus lets one query serve every CI Node without per-pageview SSH or Operations. Reading job lists with `actions: read` avoids granting runner administration permissions to the GitHub App.
