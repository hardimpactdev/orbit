---
title: "Web app"
description: "How the Gateway serves the Orbit web app at https://gateway.orbit, how live views update, how open pages move to new releases, and how web builds deploy."
covers:
  - apps/web/**
  - bin/web-deploy
  - apps/gateway/app/Infrastructure/Gateway/GatewayCaddyConfigRenderer.php
---

# Web app

The web app is Orbit's live browser view of the fleet. It is a static single-page app that reads the Gateway API and follows [realtime events](/reference/events).

Its TypeScript API schema is generated from `docs/openapi.json` via `bun run types` in `apps/web`. Regenerate and commit the schema whenever OpenAPI changes. To install, run, and check the app, see [Bun and Rust projects](/reference/bun-and-rust-projects#web-app).

## Open the app

Open `https://gateway.orbit` from any machine on the Orbit WireGuard network whose browser trusts the Orbit root CA (installed via `orbit gateway:trust`). The Gateway identifies the browser by its WireGuard IP, so there is no login. Other browser origins are refused under [Browser access to the Gateway](/reference/browser-access).

## How the Gateway site routes requests

On a container Gateway, host Caddy imports `/etc/caddy/orbit-gateway-handlers.caddy` to forward application traffic to the serving FrankenPHP container while handling `/grafana/*` on the host. Across both container and host deployments, the Gateway site routes paths as follows:

| Path | Destination |
| --- | --- |
| `/api/*`, `/mcp`, `/mcp/*`, `/up`, `/.well-known/*`, `/horizon`, `/horizon/*` | Laravel (including the [queue dashboard](/reference/gateway-queue#dashboard)) |
| `/vendor/horizon-new-dawn/*` | Compiled Horizon dashboard assets in the current Gateway release |
| `/grafana/*` | Proxied to `metrics.orbit` after `forward_auth` against `/api/v1/metrics/grafana/authorize` |
| `/assets/*` | Hashed static assets (`public, max-age=31536000, immutable`; missing files return 404 with `no-cache`) |
| Everything else | Current web release with `index.html` fallback and `Cache-Control: no-cache` |

Extension navigation follows the Gateway's enabled set: `tasks`, `ai-router`, and `documents` links and routes appear only while their extension is enabled (`vault` and `conn` have no pages). The Gateway API remains authoritative (`extension.disabled`).

## Fleet views

The app organizes fleet data across dedicated views:

- **Dashboard and Nodes:** Groups Nodes into **Tracked nodes** (`desired: true` and `actual: active`) and **Other nodes**.
- **Node detail tabs:** Exposes **Overview**, **Tools** (registered Tools plus unmanaged packages observed by the agent via `GET /api/v1/tool-inventory`, with adopt/update/remove flows), and **Firewall** on Linux (`GET /api/v1/firewall-rules?node=<id>&managed=1&live=1`).
- **CI runner view (`/runners`):** Reads `GET /api/v1/ci/runners` to display per-Node places bars, runner states, active jobs, and 48-hour statistics ([CI runner view](/reference/ci-runner-view)).
- **New Node enrollment:** Requires an approved SSH host-key fingerprint, follows the returned Operation, and loads the Node on completion.

## Live Node and Process state

The app subscribes to `orbit` and to `presence-node.{id}` for every active Node via Reverb (`GET /api/v1/realtime`).

| Agent state | Node indicator | Process `runtime_status` |
| --- | --- | --- |
| Online (`agent.{id}` present, event within 15 s) | `online` | Agent's latest reported state |
| Lost (left channel or silent for 15 s) | `offline` | Process list value |
| Not seen since subscribing | Prometheus `up` | Process list value |

While a Gateway release runs on the Gateway Node, its [`updating`](/reference/gateway-recovery#nodes-being-updated) field overrides the indicator with a pulsing blue `updating — Gateway release` state. Per-Process CPU and memory arrive via [`process.usage`](/reference/events#process-usage) events.

## Live tasks

When `tasks` is enabled, the task board (`taskGroupsQuery`, paging at 100 groups per page) and task detail pages follow [task events](/reference/events#tasks) with a 100 ms debounce:

| Event | Refetched queries |
| --- | --- |
| `task_group.created`, `task_group.updated` | Task list and that task |
| `task_comment.created` | That subtask's comments |
| `agent_thread.updated` | That task's agent threads and the task |
| `tasks.updated` | Stores the updated `enabled` flag |

Task detail pages also show a Swamp run panel polling the Gateway every 5 seconds ([Swamp run output](/reference/tasks#swamp-run-output)), deliverables for tasks without subtasks, direction/attention badges (`Needs your direction` / `Needs attention`), and the collapsible [insights strip](/reference/tasks#task-insights) (refreshed every 5 minutes, expanded state stored in `orbit.tasks.insights.expanded`).

## Live Activity

The Activity log (`/activity`) loads 50 rows at a time via `before_id` cursor pagination with URL-persisted filters for status, command, caller Node, and target Node. While realtime is live, `activity.created` prepends matching rows (with a `N new` jump control when scrolled down) and `activity.updated` patches loaded rows in place.

## Polling

While realtime is live, fleet lists and deployment history do not poll. When realtime disconnects, the footer shows `live updates paused` and views poll with exponential backoff from 30 seconds up to 5 minutes (pausing while the tab is hidden).

| View | Live realtime | Realtime down |
| --- | --- | --- |
| Task board, agents, comments, extension status | Every 5 minutes | Every 30 seconds |
| Activity log and open Activity | Every 5 minutes | Every 30 seconds |
| Process list (CPU and memory) | After 60 s without `process.usage` | Every 15 seconds |

Views without realtime events poll while visible: database users, live UFW rules, and CI runner view every 15 seconds; Process/Instance logs, queue, analytics, and Grafana metrics every 10 seconds; AI router quota every 60 seconds.

## Live logs

Instance and Process log panes follow [live log streams](/reference/live-logs) while realtime is live (initial batch of 500 lines for Instances, 100 for Processes), renewing leases every 20 seconds and retrying transient refusals every 30 seconds.

## Installed app on iPhone and iPad

`index.html` sets `viewport-fit=cover` and `apple-mobile-web-app-status-bar-style="black"` with a fixed opaque app shell. In standalone mode, the shell applies `env(safe-area-inset-*)` padding (keeping phone footers hidden unless a status message or paused-updates banner appears).

## Updates to open pages

Every build writes `{"build": "<commit-sha>"}` to `/version.json` (served with `Cache-Control: no-cache`). Open pages check `/version.json` at most once per minute (after the first minute) on client-side navigation, tab visibility return, or back-forward cache restore.

When a new build is detected, navigation triggers a full page load and visibility return reloads the page. Reloads wait while a protected draft (such as an unsaved Project Document) or focused text field is active. A 10-minute `sessionStorage` guard prevents reload loops if an old `index.html` is served, and `vite:preloadError` triggers one reload per build.

## Web directory and releases

Gateway releases install CI-built `web-dist-<sha>` artifacts into `/home/orbit/web/releases/<12-char-sha>` (group `caddy`, dirs `0750`, files `0640`) and switch `/home/orbit/web/current` atomically alongside Gateway code. Pruning removes builds not referenced by `current` or a retained Gateway release.

For manual deploys of commits without a CI artifact, run `bin/web-deploy` from a clean checkout (or `bin/web-deploy --switch <12-char-sha>` to switch to a retained build). It builds in a clean temporary worktree, keeps the newest three builds plus any retained Gateway release builds, and supports these environment overrides:

| Variable | Default | Purpose |
| --- | --- | --- |
| `ORBIT_WEB_DEPLOY_HOST` | `orbit@gateway` | SSH destination of the Gateway host |
| `ORBIT_WEB_DEPLOY_SSH` | `ssh` | SSH command and options |
| `ORBIT_WEB_DIR` | `/home/orbit/web` | Web directory on the Gateway host |
| `ORBIT_WEB_GROUP` | `caddy` | Group granted read access |
| `ORBIT_GATEWAY_RELEASES` | `/home/orbit/releases` | Gateway releases directory protected from pruning |

## Browser tests

`bun run test` in `apps/web` runs Vitest browser tests against the demo Gateway without modifying tracked files. Use a [task copy's nested fleet](/reference/tasks#build-a-feature-in-one-copy) for live UI changes that mutate fleet state, and [web verification](/reference/web-verification) for demo-mode layout checks.

## Annotation overlay

The web app mounts `@nckrtl/annotator` for standalone local annotation servers (Orbit provides no built-in annotation server or preset). `vp dev` proxies `/__annotate/local/{port}/annotations` to `127.0.0.1:{port}` and `/__annotate/speech` to `ANNOTATION_TRANSCRIPTION_TARGET`, configured via `VITE_ANNOTATION_*` variables in `apps/web/.env.local`.

## Why it works this way

Serving the SPA from the Gateway origin keeps API requests same-origin and preserves WireGuard peer identity without adding a browser login. Installing web releases built by CI outside the Gateway checkout keeps Node and Bun off the control plane and enables instant symlink rollbacks, while `/version.json` moves long-lived home-screen sessions onto new builds without a service worker.
