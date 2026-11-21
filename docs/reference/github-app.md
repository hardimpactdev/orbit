---
title: "GitHub App"
description: "How a Gateway registers its own GitHub App and installs it on a GitHub account. How Orbit reads private repositories through the App or the Gateway's GitHub CLI, publishes task pull requests, reviews and merges pull requests, and finds the newest green commit of a branch."
covers:
  - apps/gateway/app/Domain/GitHub/**
  - apps/gateway/app/Infrastructure/GitHub/**
  - apps/gateway/app/Http/Controllers/Api/GitHubAppController.php
  - apps/gateway/app/Actions/GitHub/**
---

# GitHub App

Each Gateway reads private `github.com` repositories through its own GitHub App, and [Tasks](/reference/tasks) publishes, reviews, and merges pull requests through it. Public repositories need no App, and Projects whose owner does not install the App can use [the Gateway's GitHub CLI](#read-through-the-github-cli) (`source_access: gh_cli`) instead.

## What the App is

A Gateway owns at most one public GitHub App with a private key stored encrypted in Gateway settings (never exposed in responses, Activity, or Doctor). The manifest requests seven permissions:

- `actions: read`
- `administration: read`
- `checks: read`
- `contents: write`
- `metadata: read`
- `pull_requests: write`
- `workflows: write`

Every operation mints a short-lived (1-hour) installation token narrowed to only the permissions and single repository it needs.

### No webhooks

The App registers no webhooks and cannot modify repository settings. Orbit polls GitHub APIs on demand or on bounded schedules, so the Gateway never needs a public webhook endpoint.

## Register and install

Run [`orbit github:app:install`](/cli/github/app-install) from a machine whose browser can reach the Gateway over WireGuard.

- **First run:** serves the GitHub App manifest registration page and handles `GET /api/v1/github/app/callback` synchronously to store credentials.
- **Subsequent runs:** opens GitHub's installation page to add accounts or repositories.
- **Sync installations:** `POST /api/v1/github/app/sync` (`orbit github:app:sync`) lists installations synchronously and refreshes stored metadata.
- **Remove:** [`orbit github:app:destroy`](/cli/github/app-destroy) deletes local App credentials and prints the GitHub deletion URL.

Neither registration nor sync creates an Operation. Callback exchanges expire after 1 hour (`github.registration_invalid`, 422, or `github.registration_failed`, 502). Sync signs a 9-minute App JWT, lists up to 10 pages of 100 installations, and updates `installations_status` (`unknown`, `current`, or `stale` after 5 minutes; `github.app_missing`, 404, or `github.unavailable`, 503).

## Instance source credentials

Deploy, automatic deploy, development create, production clone, and source-reading removals mint a repository-scoped `contents: read` token before fetching (missing installations fall back to anonymous access only when GitHub confirms the repository is public).

| Code | HTTP / Scope | Meaning |
| --- | --- | --- |
| `source.github_app_denied` | 403 | App is not installed on or lacks access to the repository. |
| `source.github_unavailable` | 502 | GitHub failed or timed out while resolving credentials. |
| `deployment.source_access_unsupported` | 409 | Development deployment requires `github_app` (migration still accepts `gh_cli`). |
| `source.read_timed_out`, `source.head_unavailable` | Operation | Migration could not reach source (30s timeout) or read the default branch. |

## Services dependency credentials

When `ORBIT_DEPENDENCY_DETECTION_SERVICES_NODE_ID` is set, that Services Node can call `POST /api/v1/projects/{project}/dependency-read-token` with `{}` over WireGuard. It receives a 1-hour `contents: read` and `pull_requests: read` token (`Cache-Control: no-store`, 5-second timeout, no CLI fallback). Error codes are `dependency_token.forbidden` (403), `project.not_found` (404), `dependency_token.repository_unavailable` (422), `dependency_token.refused` (403), and `dependency_token.unavailable` (503).

## How Orbit reads a repository

For `source_access: github_app` (the default), Orbit mints a 1-hour `contents: read` token for covered `github.com` repositories and passes it via `GIT_CONFIG_*` environment variables for that single `git` command (never in origin URLs, argv, `.git/config`, or files on disk). SSH-form `github.com` URLs are fetched over HTTPS while preserving the stored origin.

### The checkout cannot inherit the token

Every token-bearing `git` process runs with `core.hooksPath=/dev/null`, empty `credential.helper`, `core.fsmonitor=false`, `core.alternateRefsCommand`, `core.sshCommand`, `core.askPass`, `core.gitProxy`, and `uploadpack.packObjectsHook` cleared, `http.followRedirects=false`, and `protocol.ext.allow=never`. Clones use `--no-checkout` so untrusted checkout files or hooks never execute while the token is in the environment.

Other specialized read paths reuse the same App permissions:

- **CI artifacts & failed job logs:** `actions: read` (used by Gateway [web builds](/reference/gateway-recovery#web-build), check fixups, and the [CI runner view](/reference/ci-runner-view)).
- **Branch protection & rulesets:** `administration: read` and `metadata: read`.
- **Conn PR projection:** `pull_requests: read` (10-second budget per read).

## Read through the GitHub CLI

For private `github.com` repositories where the App cannot be installed, set `orbit project:update <id> --source-access=gh_cli` and log in once as `orbit` on the Gateway host (`sudo -u orbit -H gh auth login --hostname github.com`, ideally with a fine-grained `Contents: read` token via `--with-token`). Nodes need no `gh` binary or login.

For each read, the Gateway runs `gh auth token --hostname github.com` and injects the token with the same `GIT_CONFIG_*` isolation as App tokens without storing it. Missing logins return `github.cli_unauthenticated` without falling back. Tasks cannot publish on `gh_cli` Projects (`tasks.github_app_required`).

## How Orbit publishes a task pull request

Task copies hold no GitHub credentials. When publishing, Orbit mints a scoped `contents: write` and `pull_requests: write` token (adding `workflows: write` where permitted). It pushes `<commit_sha>:refs/heads/task-{id}` (or `task-{id}-handoff` with `--force-with-lease`), opens or updates the PR, requests configured reviewers from `ORBIT_TASKS_REVIEW_REQUEST_LOGINS`, and revokes the token when the run ends.

Settling watches poll PR status, check runs (`checks: read`, up to 1,000 runs), workflow suites (`actions: read`), and trusted reviews (`pull_requests: read`, up to 10 review pages and 5 comment pages, 64 KiB cap).

## How Orbit reviews and merges a pull request

For review-and-merge Projects, ready and merge steps first read PR state, checks (`checks: read`), and workflow suites (`actions: read`). They then mint a short-lived `pull_requests: write` (ready) or `contents: write` plus `pull_requests: write` (merge) token and revoke it afterward. Merging as the App triggers `push` workflows on the default branch, unlike `GITHUB_TOKEN`, and remains bound by repository rulesets.

## Find the newest green commit

Automatic Gateway releases and production automatic deployments walk up to 20 first-parent commits on the target branch (stopping at the currently deployed commit) and pick the newest commit where:

- Every required check run (`Required checks` plus `Gateway release inputs` for the Gateway, or the Project's `merge_check`) exists with matching `head_sha`, `conclusion: success`, and `app.slug: github-actions`.
- GitHub's compare API reports the commit strictly `ahead` of the deployed commit (preventing downgrades or diverged history).
- The commit has not failed a release.

Reads fail closed on missing Apps, refused tokens, incomplete pagination, or malformed responses.

## What the App does not cover

Manual `git` commands in development checkouts use your own credentials. Task workspaces never hold a GitHub token; the Gateway fetches in its own mirror before each turn and transfers Git objects without credentials.

## Errors

GitHub App endpoints return these error codes:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `github.app_missing` | 404 | No GitHub App is registered on this Gateway. |
| `github.registration_invalid` | 422 | Callback state is invalid or registration expired (1 hour). |
| `github.registration_failed` | 502 | GitHub refused the manifest code exchange. |
| `github.unavailable` | 503 | GitHub API unreachable or App credential rejected. |
| `github.installation_pending` | CLI | Timed out waiting for installation confirmation. |
| `github.cli_unauthenticated` | 422 | `gh` missing or not logged in as `orbit` for `gh_cli` Project. |

## Why it works this way

One App per Gateway avoids a central multi-tenant token service and needs only a single browser confirmation. Minting 1-hour, single-repository, minimum-permission tokens on demand keeps standing secrets off workload Nodes and bounds blast radius, while using the App rather than a personal token for merges keeps merges subject to branch rulesets and triggers downstream `push` workflows.
