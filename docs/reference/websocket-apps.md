---
title: "Reverb apps"
description: "How the websocket role's Reverb server serves one Reverb app per record, and how an Instance gets an app's credentials without the Gateway's secret."
covers:
  - apps/cli/app/Commands/{WebSocket/**,Instances/{Add,Remove}InstanceWebSocketCommand.php}
  - packages/php-sdk/src/{Requests,Responses}/WebSocket/**
  - apps/gateway/app/{Actions,Data}/WebSocket/**
  - apps/gateway/app/Domain/WebSocket/{WebSocketAppIssuer,WebSocketAppEnvProjection,WebSocketServerCredentials}.php
  - apps/gateway/app/Http/Controllers/Api/{WebSocketAppsController,WebSocketAppAttachmentsController}.php
  - apps/gateway/app/{Http/Requests/WebSocket/**,Infrastructure/WebSocket/NativeWebSocketRouteInspector.php}
  - apps/gateway/app/Models/{WebSocketApp,WebSocketAppTarget}.php
  - apps/gateway/database/migrations/2026_12_07_000000_create_websocket_apps.php
---

# Reverb apps

The [`websocket` role](/solutions/realtime-reverb) runs one Reverb server at `wss://reverb.orbit` that serves multiple Reverb apps. The Gateway publishes its own realtime events through the reserved `gateway` app, and every other Project gets an app of its own.

## An app

An app has a unique `name`, an `app_id`, a `key`, and a `secret`. The Gateway generates those three values, stores the secret encrypted, and never returns it. `app_id` is Reverb's own identifier and does not match an Orbit Project or Instance ID.

Reverb isolates each app's channels. Clients connect with an app's public key and see only that app's channels, and Reverb verifies every publish request against the secret of the app in the request path.

The role's first converge creates the `gateway` app. Its secret never leaves the Gateway, and you cannot attach it to an Instance or destroy it. Apps outlive the role: removing or moving the `websocket` role keeps every app and its credentials intact.

## Create an app

Create an app for each Project that publishes realtime events:

```bash
orbit websocket:app:create hauzer
```

The name is a lowercase slug of at most 63 characters. `gateway` and existing names return `validation.failed`. The registry allows up to 10,000 apps (`websocket.app_limit`, 409).

`websocket:app:list` lists apps by name, and `websocket:app:show <name>` shows one app. Both return `id`, `name`, `app_id`, `key`, and `instance_ids` without the secret.

App creation saves a new registry revision and returns an [Operation](/reference/operations) with HTTP 202. It does not converge the role, restart Reverb, or change private DNS. Each Reverb process fetches the new snapshot on its 30-second reconciliation loop and activates the app without restarting. Repeating the request with the same `--idempotency-key` returns the same app and Operation; reusing a key with different input returns `websocket.idempotency_conflict` (409).

## Destroy an app

Destroy an app when no Instance uses it:

```bash
orbit websocket:app:destroy hauzer
```

The Gateway deletes the app, saves a new registry revision, and returns an Operation (202). Each Reverb process stops accepting the app and closes only that app's sockets with code `4001`.

The `gateway` app returns `websocket.app_reserved` (409). An app still attached to an Instance returns `websocket.app_in_use` (409); remove it from every Instance first. The CLI prompts for confirmation unless `--force` is passed; see [`websocket:app:destroy`](/cli/websocket/app-destroy).

## Add an app on an Instance

Attach an app to write its credentials into the Instance's [stored environment](/reference/environment-variables), then synchronize `.env`:

```bash
orbit instance:websocket:add hauzer --instance=<instance>
orbit env:sync --instance=<instance>
```

`PUT /api/v1/instances/{instance}/websocket-apps/{name}` accepts an Instance ID or exact Route domain and requires an access grant to the Instance's Node. It writes the keys Laravel's `reverb` broadcast connection reads:

| Key | Value |
| --- | --- |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID` | The app's `app_id` |
| `REVERB_APP_KEY` | The app's `key` |
| `REVERB_APP_SECRET` | The app's `secret` |
| `REVERB_HOST` | `reverb.orbit` (or the public Route domain with `--public`) |
| `REVERB_PORT` | `8443` for private attachments, or `443` for public attachments |
| `REVERB_SCHEME` | `https` |

An Instance holds at most one app; a second add replaces the first app's keys. The response reports the Instance, app, key names, whether configuration changed, and the key count, never the secret value.

`DELETE /api/v1/instances/{instance}/websocket-apps/{name}` (`instance:websocket:remove <app> --instance=<instance>`) removes the attachment and all seven keys, leaving other keys untouched (`websocket.attachment_missing`, 404, if not attached).

Add and remove update stored configuration only; run [`env:sync`](/reference/environment-variables#synchronize) to write `.env`. Public attachments also rebuild the Route's Caddy configuration. A production [clone](/reference/environment-variables#laravel-mode) does not copy the seven keys from a candidate that holds an app; attach an app to the production Instance directly. `reverb.orbit` is a private name reachable only over WireGuard.

## Reach from a container

A [container Instance](/reference/development-containers) with a private Reverb app reaches only `reverb.orbit` on port `8443` and nothing else on the websocket Node.

### Two listeners

The websocket Node serves `reverb.orbit` on two listeners:

- Port `443` is shared with other private sites on the Node (such as `analytics.orbit`) for the Gateway, task VMs, and browsers.
- Port `8443` binds only `reverb.orbit`, so requests with another Host or SNI find no site. `/internal` stays Gateway-only on both listeners, and `orbit:wireguard-members` already admits port `8443` across WireGuard.

### Keys and path

Private attachments write `REVERB_PORT=8443` for both checkout and container Instances. An existing checkout still using `443` keeps working, and reattaching the same app switches it to `8443`.

The container's [access plan](/reference/development-containers#private-names-of-the-access-plan) adds the NIC ACL rule, firewall rule, and hosts entry for `reverb.orbit:8443` and keeps Orbit CA verification enabled. Containers on the websocket Node itself cannot use this path because the listener refuses traffic from the local bridge address. Running `env:sync` reconciles the path; after removing the app, the next `env:sync` withdraws the ACL, firewall rule, and hosts line.

## Publish on the existing public app Route

To expose an app's WebSocket endpoint on its existing public app Route, pass `--public` and synchronize `.env`:

```bash
orbit instance:websocket:add myapp-prod --instance=<instance> --public
orbit env:sync --instance=<instance>
```

This creates no new Route, DNS record, certificate, or firewall rule. Caddy proxies only the attached app's Pusher paths to `https://reverb.orbit:443` over WireGuard with Orbit CA verification, while all other traffic stays on the application handler.

The attachment records the public app Route ID and sets `REVERB_HOST` to that Route's domain with `REVERB_PORT=443` and `REVERB_SCHEME=https`. Reattaching the same app without `--public` or rotating its secret preserves the public choice. Pass `{"public": false}` in the API, or detach and reattach without `--public`, to return to the private endpoint.

When multiple Routes target the Instance, Orbit selects the lowest-ID app Route without a web root and verifies public eligibility. A shared Route cannot serve two different Reverb apps, and attachment refuses an unfinished Route transition. Remove selecting attachments before changing a Route's domain, publication, or targets.

Caddy admits only `GET /app/<attached-key>` HTTP/1.1 WebSocket upgrades and the attached app ID's Pusher API paths with a single matching `auth_key` query parameter. Path matching is case-sensitive on the decoded, uncleaned path, so dot segments, repeated slashes, or case variants cannot select another app. Other `/app`, `/app/*`, `/apps`, and `/apps/*` requests return 404, and `/internal/*` stays on the application handler.

Before publishing, Orbit inspects the Instance's production Laravel route list (with a 60-second timeout; failure returns `websocket.route_inspection_failed`, 409) to check for `/app` or `/apps` collisions, including case variants and first-segment parameters. The root Laravel fallback is allowed because Caddy reserves Pusher prefixes ahead of it. Doctor repeats this check to catch collisions added by later deployments.

Attachment and detachment commit stored intent before rebuilding Caddy. If Caddy fails to build during attachment, retry the command; if withdrawal fails to build, run `orbit node:converge <node> --full` on each affected serving Node.

### Website build and protection

Configure frontend builds with the public Route domain and public key (never `REVERB_APP_SECRET`):

```dotenv
VITE_REVERB_HOST=example.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_REVERB_APP_KEY=<app-public-key>
```

`env:sync` does not rebuild JavaScript; rebuild and deploy assets with these values, allow `wss://<domain>` in CSP `connect-src`, and update native clients. Orbit's Caddy configuration adds no WebSocket rate limiter; Reverb defaults to 10,000-byte request and message limits. Channel authorization remains on your application's `/broadcasting/auth` endpoint.

Verify external delivery from outside WireGuard by opening `wss://<domain>/app/<public-key>?protocol=7&client=js&version=8.4.0&flash=false`, confirming trusted TLS and a `pusher:connection_established` frame, verifying another app's key returns 404, and broadcasting an authenticated event.

## The Reverb server

[orbit-reverb](https://github.com/hardimpactdev/orbit-reverb) integrates Reverb with Orbit's Gateway registry using stock Pusher protocol handling and no static app list. Each Gateway release pins an immutable `orbit-reverb` commit at `orbit.websocket.ref` in `apps/gateway/config/orbit.php` (no environment override).

Each Reverb process registers a fresh boot identity before loading the registry snapshot from the Gateway, then polls every 30 seconds and reports the revision it applied. A running process keeps serving its last applied snapshot during a Gateway outage so active apps stay online, while a new process waits for a fresh snapshot before opening its listener.

### Remove a dead websocket Node

Use `orbit node:remove <node> --offline --force` or `orbit node:role:remove <node> websocket --offline --force` only after confirming the machine is dead or isolated from clients. Orbit checks reachability first; for an unreachable Node, offline abandonment withdraws its runtimes from apply confirmation and revokes their registry access. Do not bring an abandoned machine back into service without reconverging it, as it may still hold old secrets.

### Rotate an app secret

`websocket:app:rotate` keeps the app key, generates a new secret, increments the credential generation, and updates attached Instances' stored environments in the same transaction. Each Reverb process closes that app's existing sockets with code `4201` when it applies the revision while other apps stay connected. Run `env:sync` on attached Instances to apply the new secret to `.env`.

### Server upgrades

Code changes to `orbit-reverb` require a process restart; plan a short reconnect window for server upgrades. Routine app additions, removals, and rotations do not restart Reverb.

During convergence, the agent stages a new release, activates it, waits up to 30 seconds for the local listener to open after loading a fresh snapshot, and prunes releases older than the active release and its immediate predecessor. A timeout fails with `websocket.runtime_convergence_failed` and leaves staged releases intact.

`orbit-websocket.service` starts after `network-online.target`, `wg-quick@orbit.service`, and `orbit-gateway-dns.service`, and its `ExecStartPre` retries `https://gateway.orbit/up` up to 30 times (one second apart) so Reverb loads its initial registry immediately after fleet boots instead of waiting 30 seconds for the next reconcile.

## Registry confirmation

`websocket.sync`, `websocket.app_create`, `websocket.app_destroy`, and `websocket.app_rotate` save a registry revision, record an Operation, and return HTTP 202 without restarting Reverb. Each serving Reverb process pulls the snapshot on its 30-second reconciliation loop and reports its applied revision and boot identity.

- A task succeeds once every required Reverb boot serves its revision or a later one (within a 15-second report freshness window).
- A later change that supersedes the app identity or credential generation before confirmation fails the Operation with `websocket.registry_superseded`.
- A task still pending after five minutes fails with `websocket.registry_pending`; the saved registry stays intact, so run `websocket:sync` after fixing the process.
- Cancelling a waiting Operation ends tracking immediately while keeping the committed registry intent.
- After 60 seconds without a serving report, the Gateway excludes that endpoint from realtime config, auth, and broadcasting until a fresh report arrives.

`websocket:sync` observes the current revision independently and never blocks or supersedes app mutations.

## Deploy and recover

Run `orbit node:role:add <node> websocket --converge` only for server or role upgrades, inside an announced reconnect window, and confirm a fresh process report and Gateway realtime before closing the window. After rolling back between pinned Gateway releases, converge the websocket role again so the running Reverb server matches the Gateway's pinned commit.

After restoring the Gateway database, restart every Reverb process so it registers a new boot and loads a fresh snapshot. A changed registry epoch, lower revision, or mismatched digest forces a running process to exit so systemd restarts it, but an unchanged revision can return HTTP 304, so always restart manually after a restore.

## Errors

Reverb app endpoints and Operations return these error codes:

| Code | HTTP | Meaning |
| --- | --- | --- |
| `websocket.app_limit` | 409 | The registry has reached its 10,000-app limit. |
| `websocket.app_reserved` | 409 | Attempted to attach or destroy the reserved `gateway` app. |
| `websocket.app_in_use` | 409 | An Instance still holds the app. |
| `websocket.attachment_missing` | 404 | The Instance does not hold the named app. |
| `websocket.attachments_changed` | 409 | Instance attachments changed concurrently; retry. |
| `websocket.idempotency_conflict` | 409 | The idempotency key was reused with different parameters. |
| `websocket.gateway_missing` | 409 | The Gateway Node record is missing. |
| `websocket.route_inspection_failed` | 409 | Laravel route collision inspection failed or timed out after 60 seconds. |
| `websocket.registry_superseded` | Operation | A later registry revision replaced this change before confirmation. |
| `websocket.registry_pending` | Operation | Required Reverb processes did not confirm the revision within five minutes. |
| `websocket.runtime_convergence_failed` | Operation | Reverb did not open its local listener within 30 seconds during role convergence. |

## Why it works this way

### One server, one app per Project

Reverb isolates channels by key and secret. Running one server avoids extra Processes, ports, and certificates while keeping Projects separate from the Gateway's own channels.

### One durable registry with stock protocol handling

Making the Gateway the single credential authority and having Reverb pull full snapshots keeps cold starts and live updates on one code path, without per-Node secret files, a second registry store, or vendor patches to Reverb.

### Availability during an outage

Letting running processes serve their last applied snapshot keeps active apps online if the Gateway restarts, while requiring a fresh snapshot on cold start prevents deleted apps or revoked secrets from returning.

### An app is an attachment, like a database

Storing attachments on the Gateway and projecting keys through `env:sync` keeps secrets off terminals and lets `websocket:app:destroy` refuse apps that an Instance still uses.

### Reverb shares the application domain

Reusing the existing public app Route avoids extra hostnames, DNS records, and TLS certificates. Because Reverb accepts any configured app key regardless of `Host`, Caddy enforces the exact attached key and app ID before proxying, and route inspection prevents collisions with Laravel routes.
