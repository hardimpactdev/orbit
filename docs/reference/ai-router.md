---
title: "AI router"
description: "The optional Orbit extension that collects CLIProxyAPI quota and its model list into shared Valkey, and publishes provider pools at collector.ai-router.orbit."
covers:
  - apps/gateway/extensions/ai-router/**
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/web/src/extensions/ai-router/**
---

# AI router

`ai-router` is an optional Gateway extension. It collects CLIProxyAPI account quota into one snapshot in shared Valkey. The Orbit web app, the CLI, MCP, and CodexBar read provider pools from that snapshot.

## Switch and setup

The extension has one switch and one fleet setup. They are separate.

- `orbit extension:enable ai-router` shows the `ai-router:*` commands, MCP tools, API operations, and the AI router section to every client. It deploys nothing. See [`extension`](/cli/extension).
- `orbit ai-router:setup` deploys the collector Process and publishes `https://collector.ai-router.orbit`.
- `orbit ai-router:teardown` stops the collector and withdraws the hostname.
- `orbit extension:disable ai-router` hides and refuses the extension. It does not tear down the collector.

Certificate publication uses the shared [Node agent](/reference/node-agent#commands) Caddy commands. The extension keeps ownership of the collector hostname, publication intent, and error mapping.

## Valkey placement

No Node role holds a cache. Shared Valkey runs as a [Valkey Database server](/reference/database-servers#a-valkey-server) on a Node with the [`database` role](/reference/database-role). The collector and the Gateway log in through a [Redis Database connection](/reference/database-connections) with its own ACL user.

Setup refuses a cache connection that does not fit:

| Condition | Error |
| --- | --- |
| No Database connection has the slug | `ai_router.cache_missing` |
| The connection driver is not `redis` | `ai_router.cache_invalid` |
| The connection names a Node that is not in the fleet or has no active `database` role | `ai_router.cache_unplaced` |

A connection that names no Node, such as an external Redis host, is accepted.

A Valkey server publishes its port on the Node's WireGuard address. The collector Node and the Gateway both connect to it with the host, port, username, and password of the connection.

## Set up

Create the Valkey server and the collector's user, enable the extension, then set up the collector. This example uses a loopback URL:

```bash
orbit node:role:add db-1 database
orbit database:server:create valkey --node=db-1 --driver=redis
orbit database:create ai-router-cache --server=valkey --pattern='orbit:ai-router:*' --allow=@connection --allow=@string --allow=@keyspace --allow=eval
orbit extension:enable ai-router
orbit ai-router:setup --node=beast --cache-connection=ai-router-cache --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

The collector Node must be an active Linux Node with a WireGuard address. Otherwise setup fails with `ai_router.node_invalid`.

`--cliproxy-url` is the CLIProxyAPI Management API origin, as the collector Node sees it. With task VMs off, use `http://127.0.0.1:8317` when CLIProxyAPI runs on that Node.

Task VMs never call this URL. They call the [task VM guard](#task-vm-guard) on the router's Node, which reaches the router on loopback at the port of this URL. So the URL needs a port, and nothing else ties it to the task VM configuration.

The Gateway stores the management key as a secret setting and passes it to the collector Process. No API response contains it.

Setup can run again. It keeps the read and control tokens, installs the delivered agent pin through the command runner, updates the collector Process in place, and publishes the hostname again. On the same Node, the collector keeps its Process ID rather than being destroyed and created again. The collector runs as `/usr/local/bin/orbit-agent service ai-router-collector`. Its implementation lives in the AI router extension's Rust crate and is compiled into the agent. The [fleet rollout](/reference/node-provisioning#converge-the-orbit-footprint) and `orbit node:converge` deliver changed collector code with that binary.

The Gateway records the delivered agent digest as the collector revision. Before changing that revision, it records pending activation on the Process. It clears that intent only after activation succeeds. An interrupted activation therefore retries even when the binary is already current. A completed unchanged retry does not restart the collector. Agent installation restarts running services that use the agent binary; a stopped collector stays stopped. Collector setup and teardown use fixed extension commands to remove the previous Python source files. The Gateway sends no program source or file paths.

Setup with another `--node` sets up only the new Node and leaves the old Node's collector in place. To move the collector, run `ai-router:teardown` first.

## Follow the cache connection

The Gateway runs no scheduled collector converge. Its Caddy publication used the agent's `caddy.publish`, which [#1901](https://github.com/hardimpactdev/orbit/pull/1901) removed, so the per-minute tick failed every time. Setup and a Database connection update converge the collector. A release leaves it as it is until the AI router's publication runs in Swamp.

The collector depends on the configured Database connection, not a permanent copy of its credentials. Changing that connection's host, port, username, or password reconverges the dependent Process environment. The Gateway rebuilds the collector's Redis environment from the current connection and updates the same Process in place. A running collector restarts to use the new values; a stopped collector stays stopped. Its Process ID, configured cache connection, management key, read token, and control token stay the same. No manual teardown or second setup is needed for a connection update.

The environment is a name-to-value map, including for this Gateway-owned systemd Process. The connection-derived keys are `AI_ROUTER_CACHE_HOST`, `AI_ROUTER_CACHE_PORT`, `AI_ROUTER_CACHE_USERNAME`, and `AI_ROUTER_CACHE_PASSWORD`; the port is a string value too. Rebuilding these keys preserves the collector's other required environment values. Responses, Activity, errors, and logs do not reveal credentials. [Processes and schedules](/reference/processes-and-schedules#environment-contract) owns the shared representation and redaction contract.

The Gateway reconverges only dependent Processes, not every Process on the Node. An invalid cache connection is refused by the same placement and driver checks as setup. Before accepting a connection update, the Gateway validates both the connection fields and these dependent-use constraints. A validation refusal leaves the stored connection and collector unchanged.

### Connection persistence and retry recovery

After validation, the Gateway persists the connection change before reconverging its dependent Processes. The connection record is the source of truth for rebuilding their environment. A failure in remote convergence does not roll back the connection or restore the old credentials. The caller receives failure, and each failed Process records its lifecycle failure rather than claiming that the collector has refreshed. Other dependents may already have converged; a Database connection update is not an atomic transaction across all runtimes.

Every accepted connection update, including an identical retry with no changed connection fields, rebuilds and checks the environment of its dependents. It must not skip this work merely because the stored connection already matches the request. This repairs a crash after the connection was saved but before any dependent was updated, as well as a failure partway through updating several dependents. Each Process follows the [persisted intent and retry recovery](/reference/processes-and-schedules#persisted-intent-and-retry-recovery) contract: stale or incomplete work reconverges in place, while an unchanged, completed runtime is not restarted.

If a response is lost or the Gateway crashes, inspect the saved connection and dependent Process states, then repeat the identical connection update after fixing the cause. Retry can resume from either the old collector environment or an already saved but unapplied environment; both are rebuilt from the current connection. Do not interpret a saved connection as proof of completion, or overwrite a newer connection change by blindly replaying an older request.

Setup on the same Node can also reconverge the collector with the current connection, preserving its ID and tokens. These retries may repeat an unrecorded restart; they do not promise exactly one remote operation.

## What setup deploys

Setup places these pieces on the collector Node and in Gateway settings.

| Piece | Where | Detail |
| --- | --- | --- |
| Process `ai-router-collector` | The collector Node | systemd, listens on `127.0.0.1:8787` |
| Compiled collector | The collector Node | The delivered `/usr/local/bin/orbit-agent` binary |
| Orbit CA leaf and Caddy site | The collector Node | HTTPS on the Node's WireGuard address |
| Private DNS `host-record` | VPN DNS | `collector.ai-router.orbit` to the Node's WireGuard address |
| Management key, read token, and control token | Gateway settings | Stored as secrets |

The Process runs `/usr/local/bin/orbit-agent service ai-router-collector`. systemd does not search `PATH`, so the command names the absolute path. The unit receives the `AI_ROUTER_*` values as `Environment=` directives: the CLIProxyAPI URL and management key (`AI_ROUTER_MANAGEMENT_KEY`), the read and control tokens, the port, and the Valkey host, port, username, and password. The service uses that management key to read auth-file models. It receives no client API key. [Processes and schedules](/reference/processes-and-schedules#environment-of-a-systemd-process) describes that environment.

While setup holds the collector, `process:destroy` refuses to remove the Process with `process.required_by_ai_router`.

While setup uses a Database connection, `database:destroy` refuses to remove it with HTTP 409 `database_connection.required_by_ai_router`. Turning off the extension switch keeps this guard; teardown releases it. Updating that connection validates its proposed driver and placement before saving, then reconverges the collector after the transaction commits. Setup and reconvergence build the same collector environment from the stored settings and current connection credentials; missing cache host or port refuses the build.

`AiRouterProcess::data()` sets both the required `AI_ROUTER_PORT` environment value and the Process port mapping from `AiRouterProcess::PORT` (8787). Caddy upstream and Route takeover checks use that constant too. The collector refuses to start without `AI_ROUTER_PORT`. It has no port setting.

The extension stores the collector Process’s agent digest and pending activation in `ai_router_process_sources`, keyed by Process ID. The core Process record has no collector source fields. A Process without a source row has a null revision; convergence records pending activation before delivering its first compiled collector.

An unchanged collector with no pending activation stays untouched on its next convergence. Deleting a Process also deletes its source record.

`collector.ai-router.orbit` is a reserved platform name, so `route:create` and `route:update` refuse it with `route.domain_conflict`. The suggested management Route `ai-router.orbit` is not reserved. Publish the CLIProxyAPI management UI there as a [custom proxy Route](/reference/routes#custom-proxy-routes) to `http://127.0.0.1:8317`.

Before it changes anything, setup checks for a Route on `collector.ai-router.orbit`. It takes over a custom proxy Route on the collector Node to `http://127.0.0.1:8787` with no Process target: one Caddy reload moves the name to the collector site, and setup then removes the Route. Any other Route on that name fails setup with `ai_router.hostname_taken` (409).

Setup runs the shared [Caddy package step](/reference/node-provisioning#package-sources) before it publishes the Caddy site and updates the collector Process in place. An installed Caddy at or above the floor stays in place. The site retries the loopback collector for up to 5 seconds, so a request that arrives during the restart waits instead of failing.

The `ai-router` provider contributes the collector site to the [Node Caddy build](/reference/caddy-configuration#node-caddy-build) through a [plug-in point](/reference/extensions#plug-in-points). The site renders on the collector Node while setup holds the collector and the Node has the collector certificate.

The provider contributes three more pieces through [plug-in points](/reference/extensions#plug-in-points): the `ai-router` artifact of the [Node footprint](/reference/node-provisioning#converge-the-orbit-footprint), the private DNS record `collector.ai-router.orbit`, and the reservation of that name. The footprint artifact converges the collector on the delivered agent pin. VPN DNS publishes the record while setup holds the collector. The name stays reserved while the extension is off.

## Collection

The collector is the only process that calls CLIProxyAPI for quota. Every minute it takes the Valkey lock `orbit:ai-router:lock` for up to 120 seconds. When another holder has the lock, it skips the round. It lists the CLIProxyAPI auth files, fetches quota for each account that is due through `POST /v0/management/api-call`, and writes `orbit:ai-router:raw` and `orbit:ai-router:snapshot`. It remembers each auth file's models in `orbit:ai-router:model-files`.

| Rule | Value |
| --- | --- |
| Check interval per account | 5 minutes, or 15 minutes for Claude |
| Disabled account | Never checked |
| Failed check | Waits 1 hour, doubling per failure up to 1 day. A longer `Retry-After` wins. |
| Interrupted check | Waits 1 hour |

Each account's next check is stored in Valkey, so a restart or an account toggle does not reset the schedule. `collected_at` is the time of the last snapshot write. `checked_at` and `next_check_at` belong to each account.

The collector reads Claude, Codex, Grok, Kimi, and Antigravity windows from CLIProxyAPI's wrapped response. It names common windows by duration, such as `7d` and `5h`. It sorts by the last character of the label: labels that end in `d` or `w` first, then `h`, `m`, or `s`, then all others, such as `Monthly credits`. A window the provider did not return is absent. The collector never reports a missing window as zero use, and never labels a window Primary or Secondary. The collector's own endpoints leave out a window whose reset time has passed. Gateway reads still show it until the next check.

When Grok returns no percentage, the collector calls Grok's billing RPC through CLIProxyAPI. It accepts zero use only from a complete response with an active weekly or monthly period, as [CodexBar](https://github.com/steipete/CodexBar/pull/3325) does.

## Collector endpoints

The collector serves these paths on `https://collector.ai-router.orbit`. The read token and the control token are bearer tokens in Gateway settings.

Account updates trust only the Orbit CA at `ORBIT_HOME/ca/root.pem`. The Gateway checks that CA in place and sends through Laravel's HTTP client with the system CA directory disabled and a 10-second overall deadline. It does not copy the CA to a temporary file.

| Request | Token | Result |
| --- | --- | --- |
| `GET /health` | None | `{"ok": true}` |
| `GET /v1/quota-stats` | Read | The LLM Proxy quota-stats form of the snapshot, for CodexBar |
| `GET /v1/providers` | Read | Provider pools |
| `GET /api/v1/usage` | Read | The CodexBar plugin snapshot, in camelCase, with Grok as `xai` |
| `GET /api/v1/providers/{provider}` | Read | One provider of that snapshot |
| `PUT /api/v1/providers/{provider}/accounts/{account}` | Control | Sets `disabled` on one account and returns the CodexBar snapshot |
| `PATCH /v1/accounts/{account}` | Control | Sets `disabled` on one account, for the Gateway |

Every read uses the Valkey snapshot and never calls CLIProxyAPI. An account toggle reads `GET /v0/management/auth-files` to find the account's file, sends `PATCH /v0/management/auth-files/status`, then compiles the pools again from the cached snapshot. It fetches no quota. An account without a file returns 404. The CLIProxyAPI management key is not a CodexBar credential.

## Clients

The Gateway reads the snapshot from Valkey through the cache connection. `ai-router:provider:list`, `ai-router:provider:show`, `ai-router:model:list`, and the AI router pages use it. A read or toggle before setup, or after teardown, fails with `ai_router.disabled` (409).

`ai-router:account:enable` and `ai-router:account:disable` checks that the account is in the snapshot, or fails with `resource.not_found`. The Gateway then sends `PATCH https://{node-wireguard-ip}:443/v1/accounts/{account}` with `Host: collector.ai-router.orbit`, Orbit CA verification, and the control token. The account ID must match `[A-Za-z0-9._-]+`. The Gateway then writes the snapshot again with the new state. That write sets `collected_at` to the toggle time and drops each account's `checked_at` and `next_check_at` until the next collection round. The schedule itself is unchanged. When the collector refuses or cannot be reached, the call fails with `ai_router.upstream_failed` (502).

The web app shows the AI router section while the `ai-router` extension is enabled, and reads it every 60 seconds. It shows provider quota when the collector is configured, whether or not the `tasks` extension is enabled. When the collector is not configured, the page names what is missing. A provider page lists the accounts, the remaining quota and reset time of each window, and the controls to enable or disable an account.

`ai-router:status` reports whether the collector is set up, its hostname, Node, cache connection, and `collected_at`.

## Models

`GET /api/v1/ai-router/models` (`ai-router:model:list`) lists the models CLIProxyAPI offers. Each item is `{id, provider}`. The route reads that list from the collector snapshot and does not call CLIProxyAPI.

On each poll, for every auth file from `GET /v0/management/auth-files`, the collector reads `GET /v0/management/auth-files/models?name=` that file's name. It sends the management key as `Authorization: Bearer`. It does not call `GET /v1/models`. That route checks a client API key, and the management key is valid only for `/v0/management`. When that request fails, the collector keeps the models it stored for that auth file on the previous poll. A file that answers replaces only its own models. A file the auth list does not include drops out of the union.

A `models` entry always has `id`. It includes `owned_by`, `display_name`, and `type` only when the registry set them. The collector stores one `{id, provider}` per id. It does not store `display_name` or `type`. A repeated id keeps the provider from the first auth file.

When `owned_by` is present, the collector maps it with the table below. When `owned_by` is absent, it maps the auth file's `provider` with that same table. The management list sets that file's `provider` and `type` to one value. The entry's `type` is not a provider, so the collector does not map it. A model with neither `owned_by` nor an auth-file provider is omitted.

`provider` is the CLIProxyAPI provider that serves the model:

| `owned_by` | `provider` |
| --- | --- |
| `openai` | `codex` |
| `anthropic` | `claude` |
| `xai` | `grok` |
| `moonshot` | `kimi` |
| `google` | `google`. No CLIProxyAPI provider serves it |
| `meta` | `meta`. No CLIProxyAPI provider serves it |
| Any other value | That same value |

`antigravity` keeps its name, and it is an AI router provider. The route refuses with `ai_router.disabled` (409) while the collector is not set up. A snapshot that has no models returns an empty list. A collector deployed before this read has no models until `ai-router:setup` runs again and the next poll stores them.

## Check the collector

After setup, confirm that exactly one collector polls:

| Check | Expected result |
| --- | --- |
| `orbit process:list --node=<node>` | One Process `ai-router-collector` runs. |
| `orbit ai-router:status` | `enabled` is true and `collected_at` has a time. |
| `GET https://collector.ai-router.orbit/v1/quota-stats` with the read token | Quota groups from the snapshot. |
| The CLIProxyAPI access log | Quota `api-call` requests arrive only at the collector's schedule, not on a page refresh or an account toggle. |

## Task VM guard

Task VMs reach the router only through its guard. This follows the rule in [Credentials in task VMs](/reference/tasks#credentials-in-task-vms).

The guard is its own Caddy on the router's Node, `orbit-ai-router-guard.service`. It listens on the Node's WireGuard address at port 8318 and serves this table:

| Request | Answer |
| --- | --- |
| From the task VM range, a `POST` to exactly `/v1/responses`, `/v1/chat/completions` or `/v1/messages`, with no query and with the task key | Passed to CLIProxyAPI on loopback, with the anchor key in place of the task key and no `X-Api-Key` or `X-Goog-Api-Key` header |
| Anything else, the management API included | 403 |

The guard matches the path as the client sent it, so `/v1//responses` or a path with `..` does not pass.

The Gateway keeps both keys as secret settings:

- `ai_router.anchor.<sha256(origin)>` is the anchor key. It also keeps the router's key list from ever being empty.
- `ai_router.task_vm_key` is the task key. CLIProxyAPI never holds it.

On the Node, both keys are root-only files in `/etc/orbit/ai-router-guard`. The unit runs as a dynamic user and loads them with `LoadCredential`, so no other user reads them, the Node's own Caddy included.

Every task VM holds the one task key. A task start writes it, with the guard origin, into the VM's Pi models file. No start and no release calls the router.

Two UFW rules sit above the WireGuard member trust. They admit port 8318 from the task VM range and deny it to every other member. Task VMs cannot reach the router's own port: the hub's task VM filter lets them reach only the guard's address and port.

### Apply or remove the guard

Run `php artisan ai-router:task-guard` on the Gateway after setup, and after a key changes. It admits `ai_router.task_guard`, which applies the guard with Ansible. The run ends with two checks, both from sources outside the range:

- On the router's Node, the task key gets 403.
- From the swamp Node, the router's Node answers on SSH and port 8318 does not answer.

`php artisan ai-router:task-guard --remove` takes the guard, its keys and its rules off the Node, before a teardown. `firewall:list --managed` lists the two rules while the last succeeded run applied the guard.

Rotate the task key only on an incident, never on a schedule. Delete the setting, run the command again, and start new task VMs. A task VM that runs keeps the old key in its Pi models file, and the guard refuses it until its group ends.

## Teardown

Tear down the collector when collection should stop for the whole fleet.

```bash
orbit ai-router:teardown
```

Teardown removes the collector Process and the previous Python source files, withdraws the Caddy site and its certificate, and publishes private DNS without the collector name. It deletes the stored management key and the read and control tokens. A later setup needs the key file again and gives CodexBar a new read token. Valkey data and the Redis connection stay. Teardown does not change the extension switch.

Teardown refuses with HTTP 409 `extension.required` and changes nothing while the `tasks` extension is enabled. Tasks depends on `ai-router`, because Pi on a task VM reaches models only through the task VM guard of this router. `error.details.required_by` is `tasks`. A task VM holds no key of its own that the router must revoke: every task VM holds the same placeholder key, which only the guard accepts, so a teardown strands no key.

`extension:enable tasks` refuses with `ai_router.disabled` (409) while the ai-router is not set up: before the first setup or after a teardown. A task start writes the guard origin and the fixed key into the VM, so Tasks must not start without a guard. A task start checks the same thing and refuses with `ai_router.disabled`.

Task VM code reaches the model router only through the core interface `App\Domain\TaskVms\TaskVmModelRoute`. This extension binds it to `AiRouterTaskVmModelRoute`, which returns the guard origin and the fixed task key, and never the management key or the anchor key. See [Configure task VMs](/reference/compute-drivers#configure-task-vms).

## Errors

These codes come from the Gateway on setup, teardown, reads, and toggles.

| Code | Status | When |
| --- | --- | --- |
| `extension.disabled` | 409 | The `ai-router` extension is disabled. |
| `ai_router.disabled` | 409 | A read, the model list, a toggle, or `extension:enable tasks` runs while the collector is not set up. |
| `validation.failed` | 422 | Invalid setup input. |
| `ai_router.cache_missing` | 422 | No Database connection has the cache slug. |
| `ai_router.cache_invalid` | 422 | The cache connection is not Redis. |
| `ai_router.cache_unplaced` | 422 | The cache connection's Node is not in the fleet or has no active `database` role. |
| `ai_router.node_invalid` | 422 | The collector Node is missing, inactive, not Linux, or has no WireGuard address. |
| `ai_router.source_removal_failed` | 422 | The Node could not remove the previous Python collector source. |
| `ai_router.certificate_publication_failed` | 422 | Setup could not place the Orbit CA leaf on the Node. |
| `ai_router.certificate_read_failed` | 422 | The Gateway could not read the certificate it issued. |
| `ai_router.hostname_taken` | 409 | A Route that setup cannot take over holds `collector.ai-router.orbit`. Setup changes nothing. |
| `ai_router.caddy_publication_failed` | 422 | Setup could not install Caddy or build the Node's Caddy configuration. |
| `ai_router.upstream_failed` | 502 | The collector refused or did not answer an account toggle. |
| `extension.required` | 409 | Teardown runs while the `tasks` extension is enabled. Teardown changes nothing. |
| `ai_router.cutover_pending` | 409 | The release cutover is pending. The next extension tick retries it. Setup and teardown cannot replace its recorded intent. |
| `resource.not_found` | 404 | The provider or account is not in the snapshot. |

## Collector convergence

The release tick and Node footprint use one collector convergence action under the same lifecycle lock. A footprint visit skips a pending rename; the next tick completes it automatically. The action never restarts the router.

`ai-router:status` reports `cutover_pending` until the rename finishes. While it is pending, setup and teardown refuse with `ai_router.cutover_pending`. Quota reads resume when the tick renames the previous snapshot. The window includes up to one minute for dispatch, queue wait, and retirement of the old collector. When no snapshot exists, reads wait for the first successful poll.

The tick replaces the old collector through the normal Process removal and creation actions. Pending intent survives removal of the old Process. Cache keys move individually; retries finish a partial rename and preserve TTLs, backoff deadlines, and account schedules; the old collector lock is deleted. Queue and upstream failures can extend it. Missing quota is unknown, never zero.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Current placement

At build step 3, setup places one collector Process and reuses a registered Redis connection. ADR 0212 step 4 will replace setup with the AI router role and router Process adoption.

### One collector, one snapshot

A refresh of the web page or CodexBar must never start an upstream poll. A second polling loop in the Gateway would call CLIProxyAPI on every refresh. So one collector polls under a Valkey lock, and every client reads the snapshot. `ai-router:model:list` reads the model list from that same snapshot.

### A reserved name, not a Route

If a custom proxy Route served the collector, anyone who changed or destroyed that Route would change the CodexBar endpoint outside the extension lifecycle. So the Gateway reserves `collector.ai-router.orbit` and publishes it itself. The collector name sits under `ai-router.orbit`, the name of the management service it reports on, and the apex stays free for the management Route.

### Missing quota is not zero

A window the provider did not return is unknown. Showing it as zero use would tell the operator that quota is free when it may be exhausted. So a missing window is absent, and the collector names windows by duration, as the CLIProxyAPI management UI does.

## Related

- [`ai-router` commands](/cli/ai-router)
- [`extension`](/cli/extension)
- [Database role](/reference/database-role)
- [Private DNS](/reference/private-dns)

## Cache configuration

The Gateway selects the configured shared cache on its first cache operation. Constructing AI router handlers for the Operation registry reads no Settings or Database connections. The selected cache remains the same for that service instance.
