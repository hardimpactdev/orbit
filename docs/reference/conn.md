---
title: "Conn layer"
description: "How the Gateway authenticates Conn devices as Nodes, shares settings through profiles, manages T3 server admin sessions and pairings, creates clones, and tracks durable launches."
covers:
  - apps/gateway/extensions/conn/**
  - swamp/extensions/behaviors/operations/conn-*/**
  - swamp/workflows/workflow-operation-conn-*.yaml
  - swamp/ansible/conn-*.yml
  - swamp/{ansible/t3_copy_rpc.mjs,tests/t3_copy_rpc_test.ts}
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/{2027_03_22_000001_let_a_reserved_clone_take_its_name,2027_03_23_000000_add_primary_agent_to_nodes_table}.php
  - apps/gateway/database/migrations/*_conn_*.php
---

# Conn layer

Conn is Orbit's fork of T3 Code. The optional `conn` [extension](/reference/extensions) (`apps/gateway/extensions/conn`) provides one central control plane on the Gateway so devices sign in by WireGuard IP, discover registered T3 servers, share profile settings, pair with servers, and create or launch container clones. T3 traffic flows directly between devices and T3 servers over WireGuard, never through the Gateway.

While `conn` is disabled, every Conn endpoint returns HTTP `409 extension.disabled` and MCP hides Conn tools. Stored profiles, servers, and pairings remain intact, and `ActivityInputRedaction` keeps admin sessions and settings documents out of Activity logs even while the switch is off.

## Access

Every caller is authenticated by its active WireGuard Node IP (`403 peer.identity_unknown` for unknown peers). Conn reuses standard [node access](/cli/node/access-add) edges (`403 node_access.required` when missing):

| Endpoints | Required access |
| --- | --- |
| Server list, pairing endpoints, held session, thread record (`POST /environments/{id}/threads`), attention (`POST /environments/{id}/attention`) | Node access to the Node that registered the server |
| `GET /me`, `PUT /me/profile`, `PUT/GET/DELETE /me/push-device`, profiles/settings, Conn Operations, `GET /projects`, `GET /threads` | Node access to at least one Node (results filter to accessible Nodes) |
| `POST /projects/{id}/instances`, `GET /projects/{id}/branches`, `GET /projects/{id}/instances/check` | Node access to the Node hosting the Project's default Instance |
| `POST /launches` | Active peer; `expected_node_id`, `expected_profile_id`, and target Node access enforced inside the admission transaction (`withoutMiddleware(RequireNodeAccess::class)`) |
| `GET /launches`, `GET /launches/{id}`, cancel, retry | Active peer bound to the recorded profile with access to the target Node |
| `POST /environments` (registration) | Active WireGuard peer (no node access edge required) |

Gateway Nodes and peers with Gateway access reach every server.

## Profiles

Devices bind to a shared profile (`GET /me`, `PUT /me/profile`) storing settings JSON up to 4 MB across top-level sections (`workspaces`, `appearance`, `devices.<type>`, `attachedThreads`, `sidebarHiddenThreads`) at `GET/PUT/PATCH /api/v1/conn/profiles/{profile}/settings`. `PATCH` updates individual sections with per-section optimistic versioning (`409 conn.settings_version_conflict` on stale versions). `PUT` checks the profile `version` and replaces only the included `workspaces`, `appearance`, and `devices` sections while preserving omitted sections; sending `attachedThreads` or `sidebarHiddenThreads` to `PUT` returns HTTP `422` (`validation.failed`). Profiles have no `DELETE` endpoint.

### Attached threads

`attachedThreads` tracks threads pinned to the user's profile across devices so switching devices preserves open thread tabs.

### Sidebar hidden threads

`sidebarHiddenThreads` stores thread IDs hidden from the Conn sidebar across devices in the same profile.

### Profile events

When a profile write commits, the Gateway broadcasts `conn.profile.updated` (`{ profile_id, version }`, without the standard Realtime envelope) on `private-conn-profile.{key}`. Keys live for 15 minutes and renew every 5 minutes on `GET /me`. Clients discover Reverb via `GET /api/v1/conn/realtime` and authorize channel subscriptions via `POST /api/v1/conn/broadcasting/auth`.

## Registration

A T3 server calls `POST /api/v1/conn/environments` with its environment ID and admin session. The calling Node owns that registration; another Node attempting to claim the same environment ID receives HTTP `409 conn.environment_owned`. If the owning Node is removed, only a peer with Gateway access can re-register the environment.

Every entry in `GET /api/v1/conn/environments` also carries two additive fields from its registering Node: `roles` (the registering Node's active role names in canonical order, or `[]` when the Node was removed) and `is_primary_agent` (boolean). At most one active `agent`-role Node is the fleet's primary agent: the Node explicitly designated with `primary_agent: true` (`PATCH /api/v1/nodes/{node}` / `orbit node:update --primary-agent=true`, which takes Gateway access), or, when none is explicitly designated, the sole active `agent`-role Node. With zero or multiple undesignated `agent`-role Nodes, none is primary. `is_primary_agent` is `true` only on a host (non-Instance) server registered by that primary agent Node; Instance servers always return `is_primary_agent: false`.

### Unregister a host server

Remove a stale host T3 server entry from the Gateway registry with `DELETE /api/v1/conn/environments/{environment_id}` ([`orbit conn:environment:unregister`](/cli/conn/environment-unregister), destructive MCP tool `conn-environment-unregister`) so it stops appearing in `conn:environment:list`.

Access is owner-scoped and requires Conn enabled plus node access to the registering Node (or Gateway access after Node removal), returning `node_access.required` (403) otherwise. Servers bound to a container Instance (`instance_id` set) refuse with `conn.environment_is_instance` (409); destroy the Instance instead. A running `conn.*` Operation on the server refuses with `operation.target_busy` (409).

Removal is synchronous, local, and idempotent without contacting the T3 server. In one transaction, the Gateway deletes the environment row (`admin_session`), pairings, held sessions, cached threads, and managed target records. After commit, it drops attached thread references from profiles and deletes execution secret vault copies. It returns HTTP `200` with `{"environment_id": "...", "removed": true|false}`, and a still-running host T3 server that registers again reappears.

T3 and the Conn Server refuse a session that revokes itself, so the Gateway's admin session stays valid on the server after the row goes. Unregister keeps it in a hidden `conn_owed_gateway_revokes` row that no server list reads. The owed-work reconcile revokes it only on a server whose `GET /health` names `conn-server` 0.5.0 or later. It calls `POST /api/auth/logout` with that session's own bearer and no body. A 401 means an earlier logout already revoked it, so the row goes either way. Against an older server the row stays owed.

A host server that registers again with the same bearer uses that session again: the registration deletes the owed row for its environment or URL, and the reconcile drops without logout any owed row whose session a registered server holds. The reconcile holds no transaction or lock across a network call. After the `/health` gate, it reads the owed row again and stops if a registration deleted it, drops it without logout if a registered server holds its session, and otherwise logs out at once and then deletes the row.

### Session lifetimes

Orbit asks for sessions that last until revoked. The Conn Server stores `until-revoked` as its about 10-year cap and still answers a date, which the Gateway stores.

The Gateway session of an Instance's Conn Server is issued with `--ttl 3650d`. Every Conn Server from 0.2.0 on takes it. The TypeScript T3 server keeps its 30-day session.

Each pairing-token request sends `sessionTtl: "until-revoked"`. That covers held sessions and the pairing links that devices and peer links spend. The code itself still lasts 5 minutes. A server older than 0.5.0 ignores the field and grants 30 days.

At a host server's registration and at an Instance's wake, the Gateway reads `GET /health`. On a `conn-server` of 0.5.0 or later, it calls `POST /api/auth/clients/extend` for the Gateway session and each held session that ends within a year, each with its own bearer.

Only `GET /health` tells an old server, which gets no `extend`. A 409 `operation_forbidden` keeps a T3-carried session dated and renewed. A 404 means the session is gone. A gone held session is settled, and the Node's next read holds a new one. A gone Gateway session is recorded as ended, so the renewal issues a new one. A 400 `invalid_session_ttl` is logged as an error and keeps the session dated.

Orbit does nothing for peer links: Conn owns them. The Gateway never extends a peer-link session, because the holder keeps the end it stored when it linked. A new link made with an until-revoked code lasts about 10 years on both sides. From Conn 0.5.1, the holder extends an existing dated link itself, with its own MCP bearer through `POST /api/auth/clients/extend`.

The hourly renewal still runs. It renews only sessions within 7 days of their end, so it never renews a long-lived one. Revocation is unchanged: a revoke by session id ends a long-lived session at once.

### Instance servers

Container Instances automatically register their T3 server under their host `app-dev` Node during clone creation (`clone-register`), exposing the container's T3 port over the host's WireGuard address. On Instance destroy, terminal projection removes the environment and pairings before core deletes the Instance, scanning only Conn Operation kinds to keep the write lock short. After commit, Orbit deletes vault execution copies (logging `conn.destroy_secret_delete_failed` without failing destroy) and drops attached thread references.

### Renewal

The Gateway stores each server's encrypted admin session to mint and revoke pairings without operator intervention.

### Renewal of an Instance server

The hourly `conn:environments:renew` command renews expiring admin sessions on container Instance T3 servers within a 7-day margin (up to 5 renewals per tick, at most 1 per Node) using `conn/environment-renew.mmd`.

### Restart for the machine name

When a container Instance's presented machine name changes, `POST /api/v1/conn/instances/{instance}/t3-restart` and `POST /api/v1/conn/nodes/{node}/t3-restart` restart T3 cleanly once no active turn is running so the new machine label takes effect.

## Pairing and revocation

Devices pair with non-container T3 servers by requesting a pairing link through `POST /api/v1/conn/environments/{environment}/pairings` (minted via the server's stored admin session) and revoke pairings via `POST /api/v1/conn/environments/{environment}/pairings/revoke` (passing `pairing_id` in the body for one pairing, and optional `node_id` to target another Node instead of the caller).

### Held sessions

On container Instance T3 servers, devices never pair directly. On first `GET /api/v1/conn/environments/{environment}/session`, the Gateway admits `conn.session_hold` (deduplicating concurrent reads without `Idempotency-Key`), pairs itself for that Node, and stores and returns the held session. Set `ORBIT_CONN_HELD_SESSION_PREMINT=true` (default `false`) to pre-mint held sessions.

### Session cleanup

`POST /api/v1/conn/environments/{environment}/sessions/cleanup` revokes stale sessions on a T3 server. Removing a Node access grant (`NodeAccessRemovalHook`) or removing a Node (`NodeRemovalHook`) automatically rotates profile event keys and cleans up held sessions.

### Owed work

A container T3 server's owed work lives in its rows, so it survives a Gateway restart: each withdrawn held session owes a revoke by its pairing id, and a `cleanup_version` above `cleanup_completed_version` owes the cleanup of earlier Gateway sessions.

The end of a Conn Operation that holds the server (a hold, a revoke, a cleanup) and the Instance's wake admit the next owed revoke, then the cleanup, without the hourly tick. An interrupted revoke or cleanup is admitted again at once. A sleeping or removing Instance starts nothing until its wake. The Gateway stops serving a withdrawn session at once.

The `conn:owed-work:advance` command runs every five minutes while Conn is on. It admits what no such event will. A revoke or cleanup that failed, as an unreachable host leaves it, is admitted again once 15 minutes have passed. A revoke that waited while the Gateway admin session had ended starts once a session is recorded. Work whose after-commit admission a crash lost starts at the next run. A withdrawn session past its recorded end owes nothing, because T3 refuses it already. One whose end is unknown owes its revoke until a revoke by its pairing id covers it, however old.

### Managed grant revocation

When `ORBIT_CONN_MANAGED_TARGETS=true` (default `false`; returns `404 conn.managed_disabled` while off), Conn enforces revision-fenced revocation of target grants using `conn_svc_` and `conn_mt_` credentials enrolled via `php artisan conn:managed-target:enrol`. Targets poll or receive grant revisions, acknowledge revocation leases, and reject stale tokens (`conn.managed_*` error codes).

## Thread index

`GET /api/v1/conn/threads` lists threads from registered host and container servers that the caller can access. `POST /api/v1/conn/environments/{environment}/threads` records a thread before the next poll; its body is `{thread_id, title}`, and it answers `201` with the row, or `200` when the row existed. A host thread has no `instance_id`; its `project_id` is its Conn filing, or `null` for General. A Project filter matches the host thread's filing or the container's Project. An Instance filter excludes host threads.

The Gateway stores host rows separately so enabling host indexing does not rewrite the existing container index.

While Conn and the Gateway queue are enabled, the scheduler queues a host poll every five seconds. It is the fallback for a push that never came: it adds the threads of a host whose Conn Server does not push. It also keeps each host row's activity and Conn filing current, including a manual move or unfiling in Conn. `ORBIT_CONN_THREAD_INDEX_POLL=true` also enables the separate container poll: `conn:threads:read` runs every minute on the `problems` queue. A failed read keeps that server's last rows and reports the failure in `meta.environments[].read_error`.

### The thread librarian

The librarian files a new top-level General thread on a registered host Conn Server (0.5.2 or later) into an Orbit Project. Its trigger is the push. When a thread gets its first user message, the host Conn Server calls `POST /api/v1/conn/environments/{environment}/threads` with `{thread_id, title}`, authenticated as its Node by its WireGuard address. The Gateway records the row, queues `DiscoverConnThreadJob`, and answers; it does not read the Conn Server inside the request. A call for a container server queues nothing. Once a POST is accepted, a failure to queue the job never changes its response.

The host poll is the fallback: it finds a thread whose push never came once the shell lists a user-authored message. Push and poll apply one rule, and an eligible thread gets one `ClassifyConnThreadJob`, unique per thread:

- it was created at or after the host's cutoff;
- it is top-level, not delegated or archived, and has no Orbit filing;
- its Conn project is the scratch project;
- it has no completed decision.

Only the host's first librarian poll sets its permanent cutoff, the time that poll began. Threads created before it are out of scope. A push that arrives before that poll records its row and gets the same response, but it sets no cutoff and queues no classification.

A thread outside the scratch project is refused once and not read again. A completed decision is never revisited, so a push and a poll, or two pushes, classify a thread at most once. A missing model setting, an unreachable Conn Server, or Conn disabled after the POST makes the push's job do nothing; the poll still finds the thread later.

A push can arrive before its thread or first message is readable. The job then reads the thread again, every 2 seconds, four reads in all, and judges each read on that read's thread. The wait is not a failure: when the message stays unreadable, the thread stays undecided, and a later push or poll classifies it, using its first user message even after more turns.

The model gets only the title (255 characters), the first user-authored message (4,000 characters), and the Orbit Projects visible to the host's Node. Each Project includes its ID, slug, name, and a short description based on its repository or Node.

Set `ANTHROPIC_BASE_URL` to a model endpoint on [Orbit's AI router](/reference/ai-router) reachable from the Gateway, and `ORBIT_MODEL_KEY` to the credential for that endpoint. The job calls `claude-haiku-5-5` at medium effort through the router's Claude subscription pool. It needs no Anthropic API key. Without the model settings, host indexing continues without classification. The Gateway's session on the Conn Server needs `orchestration:read` and `orchestration:operate`.

The thread index exposes the librarian's `classification`: `existing` with a Project ID and a short reason, `new` with a suggested name and slug, or `none`. Only an `existing` answer can change Conn. Already-filed threads and threads manually returned to General are skipped. The Gateway retains the answer across archiving and never creates a Project from a suggestion. It doesn't store the message.

A model outage or invalid answer leaves the thread unfiled. The job has at most three attempts, with retries after 15 seconds and then 60 seconds. On exhaustion the index shows `given_up` with the last `error_code`, and later pushes and polls do not restart the job.

## Tasks panel

When the `tasks` extension is enabled, Conn reads tasks and detail snapshots via `GET /api/v1/conn/tasks` and `GET /api/v1/conn/tasks/{task}` and stays current through [task realtime events](/reference/events#tasks).

## Threads in an Orbit Project

A General thread is tagged to a Project from the [Project summary](/reference/projects#project-summary) of `GET /api/v1/projects`, which also lists the [generic Projects](/reference/projects#generic-projects) about a reachable Node; Conn keeps no Project registry of its own. Conn lists cloneable Projects via `GET /api/v1/conn/projects` (pass `include_unavailable=1` to see readiness failures across 14 ordered checks), lists branches via `GET /api/v1/conn/projects/{project}/branches`, checks clone feasibility via `GET /api/v1/conn/projects/{project}/instances/check`, and creates a container clone via `POST /api/v1/conn/projects/{project}/instances`.

Clone creation runs stable step names (`clone-container`, `clone-t3`, `clone-session-copy`, `clone-register`) and hands off to `instance.complete`. Inspect workspace git state via `GET /api/v1/conn/instances/{instance}/work`.

### Retry a completion

If `instance.complete` fails after clone creation, `POST /api/v1/conn/instances/{instance}/complete` retries completion on the retained `source_resolved` clone.

### Cancel a create or a completion

`GET /api/v1/conn/operations/{operation}` returns `instance.create`, `instance.complete`, Conn-started `instance.destroy`, and (for the admitting Node) a reservation's `instance.finalize`. `POST /api/v1/conn/operations/{operation}/cancel` requests cancellation of an active clone create or completion Operation (returning HTTP `404` for destroy and finalize).

### Remove an Instance

`POST /api/v1/conn/instances/{instance}/destroy` destroys a user's development clone after verifying no unpushed work would be lost unless forced.

### Remove a clone whose create runs

If a user deletes a clone while `instance.create` is still running, Conn cancels the create and schedules destroy once the create settles.

## Reserve a clone

A Conn device can pre-warm a clone while the user types a new thread ([finalize chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/finalize.mmd)). `POST /projects/{id}/reservations` with `{}` admits `instance.create` under a `draft-<8 hex>` name and returns HTTP 202 (or 200 for the caller's existing open reservation).

| Field | Meaning |
| --- | --- |
| `id`, `project_id`, `instance_id` | Reservation UUID, Project ID, and reserved clone ID. |
| `state`, `expires_at`, `error_code` | `preparing`, `ready`, `finalizing`, `finalized`, `failed`, `expired`, or `released`; expiry timestamp; failure code. |
| `create_operation_id`, `finalize_operation_id`, `destroy_operation_id` | Associated lifecycle Operation IDs. |
| `environment_id`, `pairing` | Clone T3 server ID from `ready` on, and one-time `IssuedConnPairingData` (`Cache-Control: no-store`, issued by the create or minted after `ready`, read once on `GET /reservations/{id}`). |

Rules:

- One open reservation per device, profile, and Project (max three per device; a fourth releases and destroys the oldest).
- Open reservations expire 10 minutes after creation or last read, destroying the clone and cancelling any open [fleet wait](/reference/development-containers#a-clone-starts-its-fleet).
- Open reservations keep the clone awake (`conn_reservation`). Submitting the thread finalizes the clone's name and branch via `instance.names` and live-reloads Conn Server (`SIGHUP`).

When the Node's agent reports `instance.t3_pairing` in `features`, the reserved clone's engine create issues the reserving device's pairing link in its `t3` step beside the Gateway's sessions so `ready` arrives with it. If omitted or refused, a background job admits `conn.pairing_create` after `ready`.

### Remove a clone whose completion runs

If a user deletes a clone while `instance.complete` is running, Conn admits `instance.destroy` with `after_operation_id` pointing at the completion Operation so destroy waits cleanly and starts immediately when completion ends.

## Durable launches v1

Durable launches (`POST /api/v1/conn/launches`, `GET /api/v1/conn/launches`, `GET /api/v1/conn/launches/{launch}`, `cancel`, `retry`) coordinate waking or creating a container Instance, preparing its T3 server, and delivering the first user message (with `model_selection` capped at 16,384 bytes) under one durable Gateway record.

Launches pin child Operation versions at admission, never lock the parent synchronously from inside a child's terminal transaction, and recover missed settlements inside `ReconcileSwampOperations`. Conflicting scope edits return `409 conn.launch_scope_changed`; retrying or cancelling after handoff commits returns `409 conn.launch_handoff_committed`.

## Push notifications

iOS push notifications for Conn threads that need attention sit behind `orbit.conn.push` (`ORBIT_CONN_PUSH`, off by default). While the switch is off, every push route returns `conn.push_disabled` (404) and `SendConnPushJob` sends nothing.

### Device registration and attention events

An iOS device registers, reads, and removes its token through `PUT /api/v1/conn/me/push-device`, `GET /api/v1/conn/me/push-device?device_id=<uuid>`, and `DELETE /api/v1/conn/me/push-device/{device_id}`. These routes use the same WireGuard peer, `conn` extension, and collection node-access checks as `GET /api/v1/conn/me`. Registrations upsert on `(node_id, device_id)` in `conn_push_devices`. The APNs device token is stored encrypted and never appears in API responses or Activity.

Native Conn Servers report events to `POST /api/v1/conn/environments/{environment}/attention` behind `RequireNodeAccess` and `ConnServerAccess::assert`, with `thread_title` redacted from Activity. Managed (container) Conn Servers report events to `POST /api/v1/conn/managed/attention` behind `RequireConnManagedTargets`, `RequireConnPush`, and `AuthenticateConnManagedTarget`, and record no Activity.

Both attention routes verify that `environment_id` matches the authenticated server and that `thread_id` does not belong to another environment. Each server is limited to 120 events per minute (`conn.attention_rate_limited`, 429). Events are deduplicated by unique ULID `event_id`: a repeat returns 200 as a no-op, and a new event returns 202, stores a row in `conn_attention_events` (pruned after 7 days by `conn:push:prune-events`), and queues `SendConnPushJob`.

### Audience, delivery, and cleanup

`SendConnPushJob` runs on the `tasks` queue ([Gateway queue](/reference/gateway-queue#queues)). At delivery time, `ConnPushDeliverer` drops events older than 1 hour and resolves recipients through `ConnServerAccess` (`NodeAccessAuthorizer`, as `conn:thread:list`), matching profile bindings, per-kind preferences (`approval`, `input`, `turn_finished`, `turn_failed`), and `hide_titles`. It enforces alert rate limits of one alert per thread per 30 seconds per device, and at most 30 alerts per device per hour followed by one summary alert. Background `attention_cleared` pushes bypass alert rate limits.

`HttpApnsClient` sends HTTP/2 requests with ES256 provider JWTs (renewed every 40 minutes) to `api.sandbox.push.apple.com` or `api.push.apple.com` according to each device's `apns_environment`. One `.p8` key serves both hosts. It sends `apns-push-type` (`alert` or `background`), `apns-topic` (`bundle_id`), `apns-priority` (`10` for `approval`/`input`, `5` otherwise), `apns-expiration` (`now + 1h`), and `apns-collapse-id` (SHA-256 of `environment_id:thread_id`), with only `environment_id`, `thread_id`, and `kind` in `userInfo`. Transient `429`/`500`/`503` failures retry with backoff up to 3 tries; `403 ExpiredProviderToken` renews the JWT once; `410 Unregistered`, `400 BadDeviceToken`, and `400 DeviceTokenNotForTopic` delete the device row and record the reason in `conn_push_sends`. Every delivery is recorded in `conn_push_sends` (`event_id`, device row id, APNs status, `apns-id`, and deletion reason) without tokens, JWTs, or titles, and pruned after 7 days.

Device rows are deleted when a Node is removed (`ConnNodeRemoval`), loses its last access edge (`ConnNodeAccessRemoval`), or has its last live pairing revoked (`ConnOperationCompletion`; routine held-session renewals leave a live pairing in place, so devices stay).

### APNs credentials

Store the Apple APNs `.p8` key in Orbit's encrypted Gateway settings with [`orbit conn:push-credentials:set`](/cli/conn/push-credentials-set) (or `php artisan conn:push-credentials:set --key-id <id> --team-id <id>` on the Gateway; key read from stdin) or `PUT /api/v1/conn/push-credentials` (Gateway access, `private_key` redacted from Activity). [`orbit conn:push-credentials:show`](/cli/conn/push-credentials-show), `php artisan conn:push-credentials:show`, and `GET /api/v1/conn/push-credentials` return only `configured`, `key_id`, and `team_id`.

## API

All Conn endpoints live under `/api/v1/conn` and are summarized below:

| Route | Purpose |
| --- | --- |
| `GET /me`, `PUT /me/profile` | Caller identity, profile binding, and profile event key |
| `PUT/GET /me/push-device`, `DELETE /me/push-device/{device}` | iOS push device registration, lookup, and removal (behind `ORBIT_CONN_PUSH`) |
| `GET /realtime`, `POST /broadcasting/auth` | Reverb discovery and `private-conn-profile.{key}` authorization |
| `GET/POST /profiles`, `GET/PUT/PATCH /profiles/{profile}/settings` | Profile creation, listing, and settings document management |
| `GET/POST /environments`, `DELETE /environments/{env}` | T3 server listing, registration, and idempotent host-server unregistration (`conn.environment_is_instance` 409 for container servers) |
| `GET/POST /environments/{env}/pairings`, `GET /environments/{env}/pairings/{pairing}`, `POST /environments/{env}/pairings/revoke` | Device pairing creation, lookup, and revocation |
| `GET /environments/{env}/session`, `POST /environments/{env}/sessions/cleanup` | Held session retrieval and stale session cleanup |
| `GET /threads`, `POST /environments/{env}/threads` | Thread index reads and recording |
| `POST /environments/{env}/attention`, `POST /managed/attention` | Native and managed Conn Server attention event ingestion (behind `ORBIT_CONN_PUSH`) |
| `GET /projects`, `GET /projects/{project}/branches`, `GET /projects/{project}/instances/check`, `POST /projects/{project}/instances` | Project discovery, branch listing, and clone creation |
| `GET /instances/{instance}/work`, `POST /instances/{instance}/{complete,destroy,t3-restart}`, `POST /nodes/{node}/t3-restart` | Clone workspace inspection, completion, removal, and T3 restarts |
| `GET /operations/{operation}`, `POST /operations/{operation}/cancel` | Conn Operation reads and cancellation |
| `GET/POST /launches`, `GET /launches/{launch}`, `POST /launches/{launch}/{cancel,retry}` | Durable launch lifecycle |
| `GET /tasks`, `GET /tasks/{task}` | Tasks panel reads |
