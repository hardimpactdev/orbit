---
title: "Delivery-line proofs"
description: "Two repository commands that prove pull-request head review and post-merge live state, without merging, deploying, or rolling back."
covers:
  - bin/{pr-head-check,deploy-verify}
  - apps/e2e/tests/Unit/E2E/DeliveryLineCommandsTest.php
  - apps/e2e/tests/Fixtures/delivery-line/**
  - .agents/skills/merging-pull-requests/SKILL.md
---

# Delivery-line proofs

Orbit's delivery line uses two repository commands. Each command prints one JSON object on stdout, has `--help`, and exits nonzero on failure. The error text says what to do next. Commands that can run a side effect support `--dry-run` and default to read-only work.

The style matches [`bin/review-check`](/reference/implementation-loop#the-candidate-gate), the Project [task check](/reference/tasks#prove-a-command-fails-on-the-start-commit), and [`doctor`](/cli/doctor): one structured result, no repair, and a next step on failure.

| Command | Result | Side effects |
| --- | --- | --- |
| [`bin/pr-head-check`](#binpr-head-check) | The current head has a matching review and Required checks that ran with the current main tip, and no named leftover | None. Does not merge. |
| [`bin/deploy-verify`](#bindeploy-verify) | Live `APP_VERSION` matches the merged SHA, `/up` is up, and gateway status is `ok` | None. Does not deploy or roll back. |

Run every command from the repository root.

## Shared result

Success prints one JSON object and exits `0`. Failure prints one JSON object on stdout, writes the next step on stderr, and exits `1`. Wrong flags exit `2`. `--help` prints the command contract and exits `0`.

| Field | When |
| --- | --- |
| `passed` | Present on a verdict. `true` or `false`. |
| `dry_run` | Present when `--dry-run` skipped the side effect or the live calls. |
| `error` | Present on failure. A stable token. |
| `message` | Present on failure. One sentence. |
| `next` | Present on failure. What to do next. |

Machine output contains no heading, progress line, or ANSI sequence.

## bin/pr-head-check

Read the pull request, its reviews, its `Required checks` run, and its file diff. Pass only when those records match the current head and the diff has no leftover the [merge skill](https://github.com/hardimpactdev/orbit/blob/main/.agents/skills/merging-pull-requests/SKILL.md) already names. Do not merge.

```bash
bin/pr-head-check --pr URL
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--pr` | required | A GitHub pull request URL or `owner/repo#number`. |
| `--pull-file`, `--reviews-file`, `--checks-file`, `--files-file`, `--compare-file` | GitHub through `gh api` | Recorded JSON for tests. |

The result includes `merged`, copied from the pull request record, alongside the existing `passed` and mismatch fields. Before any review or merge attempt, read this field even when the command exits nonzero. When the JSON has `merged:true`, stop: the pull request is already merged and the merge flow is a terminal no-op. Do not review it again or run `gh pr merge`. This applies even when `passed` is `false` or the result names a missing review or another mismatch. When `merged` is `false`, all review and merge requirements still apply. A missing or unreadable `merged` field is not evidence of a merge; resolve the read before proceeding.

The command keeps a review only when its `commit_id` equals the current full head SHA and its state is `APPROVED`. `COMMENTED`, `CHANGES_REQUESTED`, `DISMISSED`, and `PENDING` do not count. GitHub refuses `APPROVE` from the pull request author. An empty successful review list is missing. A failed, partial, or unparsable read is unreadable and is not treated as empty.

On that same full head SHA, the GitHub Actions check run named `Required checks` must have `status` `completed` and `conclusion` `success`. That run's `head_sha` must equal the pull request head. Newer `gh` wraps `gh api --paginate --slurp` check-runs in a one-element array of the check-runs object. Older `gh` has no `--slurp` and returns the object. Both shapes flatten to the `check_runs` list. A check that is not completed is pending. A completed check whose conclusion is not `success` is failed. No run with that name is missing. The admin bypass is not a successful check.

Those checks must also have run with the current tip of the pull request's base branch, which is main for Orbit pull requests. The command compares the base branch with the head (`gh api repos/{owner}/{repo}/compare/main...<head-sha>`). The checks ran with the tip when the head contains it (`behind_by` is 0), or when the pull request's recorded `base.sha` equals it, because the `pull_request` run merged the head into that base.

Otherwise the result is `base_behind`: green on an older base does not prove the head still works on current main. The JSON `base` object names the base ref, the recorded base SHA, the current tip, and `behind_by`. A merged pull request skips this comparison.

The diff fails when it adds a leftover the merge skill already names: GitHub auto-merge, `--auto`, `--admin`, an `orbit tasks:merge` command, a Gateway merge endpoint, a merge SDK, MCP, or API contract, a ruleset change, an App permission change, an `object-storage-host`, or a `linear-reference`. Markdown sentences that explicitly prohibit those leftovers are exempt. A negation in a URL, another sentence, or product code does not exempt the match. The scan also skips the detector (`bin/pr-head-check`), the delivery-line test that names the leftover refusal, and recorded delivery-line fixtures. Those files hold the forbidden names so the checker can refuse them. A leftover added in product code still fails.

Do not add hostnames under the generic `upcloudobjects.com` provider suffix (`object-storage-host`), `linear.app` references, Linear issue IDs, or Linear issue, ticket, project, product, task, integration, or workspace wording (`linear-reference`). A trailing sentence period or DNS root dot still matches the provider host; a domain that extends the suffix does not. Patterns and fixtures contain no real storage bucket, endpoint, region, or account names.

This repository does not wake ReviewBot when a GitHub `pull_request_review` event arrives without a new push; wiring that wake is a follow-up. A review event never bypasses `bin/pr-head-check`: an `APPROVED` review and successful `Required checks` must still match the exact current head.

On any mismatch the JSON names the mismatch. For an unmerged pull request, the next step is to review the new head. For `merged:true`, stop instead, regardless of the mismatch. The command never runs `gh pr merge`.

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--pr` with the pull request URL. |
| `review_missing` | Obtain an `APPROVED` review of the current head. Do not merge. |
| `review_unreadable` | Re-read the reviews until the list is complete. Do not merge. |
| `check_missing`, `check_pending`, `check_failed` | Wait for `Required checks` on this head, or review the new head. Do not merge. |
| `head_mismatch` | Review the new head. Do not merge. |
| `base_behind` | Update the branch with main, wait for `Required checks` to pass on the new head, and re-review the new head. Do not merge. |
| `leftover` | Remove the named leftover. Do not merge. |

## bin/deploy-verify

Read the live tip version, `/up`, and gateway status. Pass when `APP_VERSION` equals the given merged SHA, `/up` is up, and gateway status is `ok`. Those are the checks recorded in ops verified-merges. Do not deploy and do not roll back.

Each [Gateway release](/reference/gateway-recovery#deploy-a-release) runs the same checks as its verify step. The command stays a read-only check from outside the Gateway.

```bash
bin/deploy-verify --sha SHA [--up-url URL] [--status-url URL] [--dry-run] [--fixture FILE]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--sha` | required | The merged commit to expect as live `APP_VERSION`. A prefix match is enough. |
| `--up-url` | `ORBIT_DEPLOY_UP_URL` or `https://gateway.orbit/up` | The health URL. |
| `--status-url` | `ORBIT_DEPLOY_STATUS_URL` or `https://gateway.orbit/api/v1/gateway/status` | The gateway status URL. Its `data.version` is the tip `APP_VERSION`. |
| `--dry-run` | off | Print the URLs and the expected SHA. Do not call them. |
| `--fixture` | off | A recorded response set. Used when production is unreachable. |

`--dry-run` exits `0` and does not claim a live pass. `--fixture` evaluates the recorded `/up` body, gateway status, and `APP_VERSION` `2f214816deae` marker shape from 2026-10-01. A fixture pass is not a live pass. The JSON says `source` is `fixture` or `live`.

Python HTTPS calls need `SSL_CERT_FILE` set to Orbit's root CA, or they fail certificate verification. That file is the Gateway profile `ca_path` after [`orbit gateway:trust`](/cli/gateway/trust). An unreachable result from this machine is not a reason to change the live checks.

`instance:rollback` is not part of this command. A rollback selects one retained production release and does not undo deploy steps, environment writes, or database files. See [Roll back](/reference/deployments#roll-back).

| `error` | Next step |
| --- | --- |
| `usage` | Pass `--sha` with the merged commit. |
| `unreachable` | Use `--dry-run` or `--fixture`. For a certificate problem, set `SSL_CERT_FILE`. |
| `version_mismatch` | The live `APP_VERSION` is not this SHA. Do not treat the deploy as verified. |
| `up_failed` | `/up` is not up. Do not treat the deploy as verified. |
| `status_failed` | Gateway status is not `ok`. Do not treat the deploy as verified. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Repository commands stay outside the engine

The Gateway task engine is generic. Orbit's policy lives in the repository skill and these checks. Putting the proofs in `bin/` beside `review-check` keeps Project policy out of the engine, the CLI product, and the SDK.

### Doctor's verify-only boundary applies to deploy

`bin/deploy-verify` reads the same three signals ops already records after a merge: tip version, `/up`, and gateway status. It does not deploy, and it does not roll back. Wiring `instance:rollback` into the verify command would hide that a rollback does not restore data or undo steps.

### A stale head is named, not merged

`bin/pr-head-check` stops when the review or `Required checks` belong to another SHA. The next step is to review the new head. It also stops when those checks never ran with the current main tip. A branch that was green before main moved can still break main without a textual conflict. The next step is to update the branch, wait for green, and review the new head. Auto-merge, `--admin`, and a `tasks:merge` command remain the leftovers the merge skill already forbids.
