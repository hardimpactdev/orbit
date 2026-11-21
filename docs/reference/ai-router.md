---
title: "AI router"
description: "The optional Orbit extension that collects CLIProxyAPI quota and model lists into shared Valkey, publishes collector.ai-router.orbit, and guards model access from containers."
covers:
  - apps/gateway/extensions/ai-router/**
  - swamp/ansible/ai-router-model-forwarder.yml
  - apps/web/src/extensions/ai-router/**
---

# AI router

`ai-router` is an optional Gateway extension that collects CLIProxyAPI account quota and model lists into a shared Valkey snapshot. The Orbit web app, CLI, MCP, and CodexBar read provider pools from that snapshot without polling upstream providers.

## Switch and setup

The extension switch and fleet deployment are independent:

- `orbit extension:enable ai-router` exposes `ai-router:*` commands, API routes, MCP tools, and web pages without deploying anything.
- `orbit ai-router:setup` deploys the collector Process and publishes `https://collector.ai-router.orbit`.
- `orbit ai-router:destroy` removes the collector and withdraws the hostname.
- `orbit extension:disable ai-router` hides and refuses extension routes (`extension.disabled`) without tearing down the collector.

### Valkey placement and setup

Shared Valkey runs as a [Valkey Database server](/reference/database-servers#a-valkey-server) on an active `database` Node (or an external Redis host with no Node). Setup validates the cache connection (`ai_router.cache_missing`, `ai_router.cache_invalid`, `ai_router.cache_unplaced`) and target Node (`ai_router.node_invalid`, active Linux Node with WireGuard IP), refusing any existing Process attached as a Node service target (`process.node_service_ownership_conflict`):

```bash
orbit node:role:add db-1 database
orbit database:server:create valkey --node=db-1 --driver=redis
orbit database:create ai-router-cache --server=valkey --pattern='orbit:ai-router:*' --allow=@connection --allow=@string --allow=@keyspace --allow=eval
orbit extension:enable ai-router
orbit ai-router:setup --node=<collector-node> --cache-connection=ai-router-cache --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

Setup deploys `/usr/local/bin/orbit-agent service ai-router-collector` as systemd Process `ai-router-collector` listening on `127.0.0.1:8787`, stores the management key and generated read/control tokens as Gateway secrets, publishes the Orbit CA leaf and Caddy site for reserved hostname `collector.ai-router.orbit` (`route.domain_conflict` on manual Route creation), and publishes its private DNS record. Re-running setup on the same Node updates the Process in place while keeping its ID and tokens.

While setup is active, `process:destroy` refuses (`process.required_by_ai_router`) and `database:destroy` refuses (`database_connection.required_by_ai_router`, HTTP 409) even when the extension switch is off.

## Follow the cache connection

The collector reads its Valkey credentials from the configured Database connection (`AI_ROUTER_CACHE_HOST`, `AI_ROUTER_CACHE_PORT`, `AI_ROUTER_CACHE_USERNAME`, `AI_ROUTER_CACHE_PASSWORD`). Updating that connection validates driver and placement first, saves the updated connection, and reconverges the collector Process in place (restarting only if running). Identical retries also reconverge to recover interrupted updates.

## Collection

Every minute, the collector takes Valkey lock `orbit:ai-router:lock` (120-second TTL), reads CLIProxyAPI auth files, fetches due account quota via `POST /v0/management/api-call`, and updates `orbit:ai-router:raw`, `orbit:ai-router:snapshot`, and `orbit:ai-router:model-files`.

| Rule | Value |
| --- | --- |
| Poll interval per account | 5 minutes (15 minutes for Claude) |
| Disabled account | Never polled |
| Failed check | 1-hour backoff, doubling up to 24 hours (respects longer `Retry-After`) |
| Interrupted check | 1-hour backoff |

Each account's schedule persists in Valkey across restarts. The collector reports Claude, Codex, Grok, Kimi, and Antigravity windows by duration label (`d`/`w`, then `h`/`m`/`s`, then other labels), omitting absent windows rather than reporting zero.

### Model aliases

Before each collection round, the collector checks `~/.config/cliproxyapi/config.yaml` and converges the `antigravity` list under `oauth.model-alias` (or root `oauth-model-alias`) with `fork: true`:

| Name | Aliases |
| --- | --- |
| `gemini-4-argon-high` | `gemini-4-argon`, `gemini-3.1-pro`, `gemini-3.1-pro-preview` |
| `gemini-3.8-flash-high` | `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.1-flash-lite-preview` |
| `gemini-4-carbon-high` | `gemini-4-carbon` |
| `gemini-4-hydrogen-xhigh` | `gemini-4-hydrogen` |
| `gemini-4-neon-xhigh` | `gemini-4-neon` |

Edits rewrite the file in place (preserving owner, mode, and inode so CLIProxyAPI's file watch reloads automatically) using `.config.yaml.orbit-previous` and `.config.yaml.orbit-next` (`0600`) for crash recovery. Complex YAML forms that cannot be edited safely are skipped and logged.

### Collector endpoints

The collector serves these routes on `https://collector.ai-router.orbit` (all reads serve from Valkey without calling CLIProxyAPI):

| Request | Auth | Result |
| --- | --- | --- |
| `GET /health` | None | `{"ok": true}` |
| `GET /v1/quota-stats`, `GET /quota-stats` | Read token | Quota-stats snapshot for CodexBar |
| `GET /v1/providers` | Read token | Provider pools |
| `GET /api/v1/usage`, `GET /api/v1/providers/{provider}` | Read token | CamelCase CodexBar plugin snapshot (`xai` for Grok) |
| `PUT /v1/providers/{provider}/accounts/{account}` | Control token | Sets `disabled` via CLIProxyAPI and returns updated snapshot |
| `PATCH /v1/accounts/{account}` | Control token | Sets `disabled` for the Gateway |

## Models

`GET /api/v1/ai-router/models` (`ai-router:model:list`) returns `{id, provider}` pairs from the Valkey snapshot. During collection, the collector queries `GET /v0/management/auth-files/models?name=<file>` using the management key and normalizes `owned_by` (falling back to auth-file `provider`): `openai` -> `codex`, `anthropic` -> `claude`, `xai` -> `grok`, `moonshot` -> `kimi`, keeping `antigravity`, `google`, `meta`, and other names as-is.

## Task VM guard

Containers and task workloads never hold CLIProxyAPI's real key or reach its management API. `orbit-ai-router-guard.service` runs a dedicated Caddy on the router Node's WireGuard IP at port `8318`, loading credentials owned by root through systemd `LoadCredential` from `/etc/orbit/ai-router-guard`:

- **OpenAI and Anthropic routes:** Admitted sources may `POST` to `/v1/responses`, `/v1/chat/completions`, `/v1/messages`, or `/v1/messages/count_tokens` with the task key in `Authorization: Bearer`. Queries must be empty, except `beta=true` on messages routes.
- **Google routes:** Admitted sources may `POST` to `/v1beta/models/<model>:streamGenerateContent?alt=sse`, `:generateContent`, or `:countTokens` with the task key in `X-Goog-Api-Key`.
- **Key rewriting:** The guard swaps the task key for the anchor key (`ai_router.anchor.<hash>`), strips `X-Api-Key` and `X-Goog-Api-Key`, and proxies to loopback CLIProxyAPI.
- **Denied requests:** Any other route (including management routes) returns `401` from admitted sources and `403` from all other sources.

Apply or remove the guard with `orbit ai-router:task-guard [--remove]` (`POST /api/v1/ai-router/task-guard`, Operation `ai_router.task_guard`, locked by `ai_router_guard:<router-node>`).

### The guard follows its config

Each guard or forwarder run records a config digest (Caddyfile, UFW rules, state, and playbooks; no secrets). After a Gateway release's fleet phase settles, its `ai_router_guard` [follow-up phase](/reference/gateway-recovery#follow-up-phases) compares the rendered digest and automatically re-applies the guard if it changed (reporting `current`, `pending`, `absent`, `disabled`, or `failed` with `release_follow_up_failed` on error).

## Container model forwarder

Agent Nodes expose model access to containers through `orbit-ai-router-forwarder.service`, a dedicated Caddy listening on the container bridge IP at port `8319` under system user `orbit-ai-router-forwarder` ([ADR 0252](/decisions/0252-reach-the-model-router-from-containers-through-a-node-forwarder)).

`orbit-ai-router-forwarder-table.service` loads nftables table `inet orbit_ai_router_forwarder` at priority `filter - 10` (ahead of Docker and UFW):

- **Input chain:** Resets any connection to the forwarder address not arriving on the container bridge.
- **Forward chain:** Drops all forwarded packets directly to the guard address and port.
- **Output chain:** Resets all host-originated connections to the guard unless owned by `orbit-ai-router-forwarder`.

Apply or remove a forwarder from the Gateway:

```bash
php artisan ai-router:model-forwarder <node> --bridge=<bridge> --address=<bridge-ip>
php artisan ai-router:model-forwarder <node> --remove
```

Containers point `ANTHROPIC_BASE_URL` to `http://<bridge-ip>:8319` and Codex `base_url` to `http://<bridge-ip>:8319/v1` using the task key.

### Orbit routes for task copies

The forwarder also proxies three exact paths from task copies to the Gateway API over HTTPS (verifying `/etc/orbit/agent/ca.pem` and requiring the copy's [copy key](/reference/development-containers#the-copy-key)):

| Container path | Gateway path |
| --- | --- |
| `GET /orbit/v1/copy/whoami` | `/api/v1/copy/whoami` |
| `POST /orbit/v1/copy/pull-request` | `/api/v1/copy/pull-request` |
| `POST /orbit/v1/copy/handoff` | `/api/v1/copy/handoff` |

All other paths forward to the guard on port `8318`. Re-apply the forwarder on each agent Node if the Gateway's WireGuard IP changes.

## Teardown

Run `orbit ai-router:destroy` to stop and remove the collector Process, Caddy site, certificate, private DNS record, and stored tokens/keys (Valkey data and the Redis connection remain). Teardown refuses with HTTP `409 extension.required` while `tasks` is enabled.

## Errors

AI router commands and endpoints return these error codes:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `ai_router.disabled` | 409 | Collector is not set up |
| `ai_router.cache_missing` / `cache_invalid` / `cache_unplaced` | 404 / 422 | Cache connection missing, not Redis, or not on an active `database` Node |
| `ai_router.node_invalid` | 422 | Collector or forwarder Node is missing, inactive, not Linux, or lacks WireGuard IP |
| `ai_router.hostname_taken` | 409 | Another Route already occupies `collector.ai-router.orbit` |
| `ai_router.guard_absent` | 409 | Forwarder apply attempted while the guard is not installed |
| `ai_router.forwarder_refused` | 409 | Node is the router Node or holds `swamp` or `vpn` |
| `ai_router.gateway_unaddressed` | 409 | Active Gateway has no WireGuard IPv4 address for copy routes |
| `ai_router.upstream_failed` | 502 | Collector refused or could not be reached for an account toggle |
| `extension.required` | 409 | Teardown attempted while `tasks` extension is enabled |
| `operation.target_busy` | 409 | Another guard or forwarder Operation holds the guard lock |
