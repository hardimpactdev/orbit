---
title: "ADR 0228: Serve several Reverb apps on the websocket role"
sidebarTitle: "0228 Reverb apps"
description: "In progress. The websocket role's Reverb server serves one Reverb app per record. Each Project gets its own app on wss://reverb.orbit, and the Gateway's secret stays on the Gateway."
---

# ADR 0228: Serve several Reverb apps on the websocket role

The `websocket` role's Reverb server serves several Reverb apps. The Gateway's own app is one of them. Each Project, starting with Hauzer, gets its own app on `wss://reverb.orbit`, and the Gateway's secret never leaves the Gateway.

## Status

In progress.

Principle: [One way, one name](/mission#principles). An app reaches its credentials the way it reaches a database: one Gateway record, attached to an Instance's stored environment. No principle exception.

This record stays until two changes are merged. First, [orbit-reverb](https://github.com/nckrtl/orbit-reverb) reads `REVERB_APPS`. Then the Gateway renders it.

## Context

- The role runs the plain Laravel app [orbit-reverb](https://github.com/nckrtl/orbit-reverb) as `orbit-websocket.service` at `/opt/orbit/websocket`. Its `config/reverb.php` builds one app from `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET`.
- The Gateway stores that one app as three settings of the role's Node, beside the server's Laravel `APP_KEY`. A move copies them. A role removal deletes them, so a new role gets new keys.
- A Project that wants realtime events, such as Hauzer, has two choices today. It can share the Gateway's secret, which lets it publish on the Gateway's channels. Or it can run its own Reverb server.
- Reverb keeps each app's channels apart. It checks every publish request against the secret of the app in the request path, and a private channel's auth signature against that app's secret.
- Reverb reads its apps once, when `reverb:start` starts. The role's converge writes the `.env` and runs `systemctl enable --now`, which does not restart a running server.

## Decision

The Gateway owns a record for each Reverb app, renders every app into the Reverb server's environment, and writes an app into an Instance's stored environment on request.

### Records

A `websocket_apps` row is one Reverb app: a unique `name`, an `app_id`, a `key`, and a `secret`. The `encrypted` cast encrypts the secret at rest, and no response holds it. The Gateway generates the three values. The app named `gateway` is the Gateway's own app. The role's first converge creates it when it is missing. The apps outlive the role: role removal, `--offline` removal, and relocate leave them alone. The server's Laravel `APP_KEY` stays a setting of the role's Node, because it belongs to the server, not to an app.

A migration moves the three settings of the role's Node into the `gateway` row once and deletes the settings from every Node. The settings hold the same ciphertext that the cast reads, so the secret moves as it is.

### Rendering

The converge renders every app, ordered by name, into one value of the server's `.env`:

```text
REVERB_APPS='[{"app_id":"…","key":"…","secret":"…"},…]'
```

orbit-reverb's `config/reverb.php` maps each entry to an app of Reverb's stock `config` provider, with the shared options it already has. The values are random letters and digits. Single quotes keep the JSON literal for phpdotenv. Each converge restarts `orbit-websocket`, so Reverb loads the current code and every app. A restart closes every connection, and clients reconnect.

### Commands

| Command | Route | Result |
| --- | --- | --- |
| `websocket:app:create <name>` | `POST /api/v1/websocket-apps` | Creates an app and converges the active role. |
| `websocket:app:list` | `GET /api/v1/websocket-apps` | Lists the apps by name. |
| `websocket:app:show <name>` | `GET /api/v1/websocket-apps/{name}` | Shows one app. |
| `websocket:app:destroy <name>` | `DELETE /api/v1/websocket-apps/{name}` | Deletes an app and converges the active role. |
| `instance:websocket:add <app> --instance=<instance>` | `PUT /api/v1/instances/{instance}/websocket-apps/{name}` | Writes the app into the Instance's stored environment. |
| `instance:websocket:remove <app> --instance=<instance>` | `DELETE /api/v1/instances/{instance}/websocket-apps/{name}` | Removes the keys and the attachment. |

The responses carry `id`, `name`, `app_id`, `key`, and the IDs of the Instances that hold the app. They never carry the secret. Without an active role, create and destroy only change the record, and the role's next converge renders it. A failed converge keeps the record change, and `node:role:add <node> websocket --converge-existing` applies it after the fix. Create and destroy run inside the request, as `analytics:update` does. [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) lists them with the other commands that converge a role.

Add writes seven keys, the names Laravel's own `reverb` broadcast connection reads: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=reverb.orbit`, `REVERB_PORT=443`, and `REVERB_SCHEME=https`. A `websocket_app_targets` row records the attachment. An Instance holds at most one app, so a second add replaces the first. Add and remove change stored configuration only; `env:sync` writes the file. This is how a secret reaches an app without passing through chat.

### Refusals

- `websocket.app_reserved` (409): destroying the `gateway` app, or adding it to an Instance.
- `validation.failed` (422): creating an app named `gateway`, a taken name, or a name that is not a lowercase slug of at most 63 characters.
- `websocket.app_in_use` (409): destroying an app that an Instance still holds.
- `websocket.attachment_missing` (404): removing an app that the Instance does not hold.

### Clones

A production [clone](/reference/instance-cloning) does not copy the seven keys of a candidate that holds an app. The production Instance gets its own app with `instance:websocket:add`, so its channels stay apart from the candidate's.

## Rejected alternatives

- Render `config/reverb.php` into the checkout: the converge resets the checkout to the pinned ref, so Orbit would overwrite a tracked file on every converge, and the Gateway would own Reverb's server options.
- Numbered keys such as `REVERB_APP_1_ID`: the app's config still has to loop over them, and the number of apps would set the number of keys.
- A custom Reverb `ApplicationProvider` that asks the Gateway: Orbit code in the Reverb app, and a Gateway outage would stop new connections.
- One Reverb server per Project: more Processes, ports, and certificates for the same isolation that Reverb already gives each app.
- Share the Gateway's app: a Project that holds the Gateway's secret can publish on the Gateway's channels.

## Consequences

- Each Project gets its own channels on `wss://reverb.orbit`. Its credentials cannot publish on another app's channels.
- The Gateway's secret stays on the Gateway. A role move or removal no longer changes any app's keys.
- Each converge restarts Reverb, so it loads the current code and every app, and clients reconnect.
- The migration deletes the old Node settings. A Gateway code rollback does not run migrations back, so the old code finds no Reverb app and the Gateway stops broadcasting. Restore the pre-release database snapshot or roll forward to recover.
- The orbit-reverb change must merge before this one. A Gateway that renders `REVERB_APPS` for an orbit-reverb that does not read it starts Reverb without a usable app.
- The endpoint stays private to WireGuard. A public endpoint for production apps needs its own decision.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, apps/web, packages/php-sdk
- ADRs: [0218](/decisions/0218-take-long-commands-off-the-request-path)
- Detail: [Reverb apps](/reference/websocket-apps), [Realtime events with Reverb](/solutions/realtime-reverb)
- Verify: the [orbit-reverb](https://github.com/nckrtl/orbit-reverb) change, which builds the apps from `REVERB_APPS`; Gateway `tests/Feature/Api/WebSocketAppsTest.php`, `tests/Feature/Database/WebSocketAppsMigrationTest.php`, the websocket unit and feature tests, the clone test, and orbit-reverb `tests/Feature/ReverbAppsTest.php`, which starts `reverb:start` and refuses one app's credentials on another app's ID
