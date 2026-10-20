---
title: "Reverb apps"
description: "How the websocket role's Reverb server serves one Reverb app per record, and how an Instance gets an app's credentials without the Gateway's secret."
covers:
  - apps/cli/app/Commands/{WebSocket/**,Instances/{Add,Remove}InstanceWebSocketCommand.php}
  - packages/php-sdk/src/{Requests,Responses}/WebSocket/**
  - apps/gateway/app/{Actions,Data}/WebSocket/**
  - apps/gateway/app/Domain/WebSocket/{WebSocketAppIssuer,WebSocketAppEnvProjection,WebSocketServerCredentials}.php
  - apps/gateway/app/Http/Controllers/Api/{WebSocketAppsController,WebSocketAppAttachmentsController}.php
  - apps/gateway/app/Http/Requests/WebSocket/**
  - apps/gateway/app/Models/{WebSocketApp,WebSocketAppTarget}.php
  - apps/gateway/database/migrations/2026_12_07_000000_create_websocket_apps.php
---

# Reverb apps

The [`websocket` role](/solutions/realtime-reverb) runs one Reverb server at `wss://reverb.orbit`. That server serves several Reverb apps. The Gateway publishes its own events with the app named `gateway`. Every other Project gets an app of its own. [ADR 0228](/decisions/0228-serve-several-reverb-apps-on-the-websocket-role) records the decision while it is built.

## An app

An app has a unique `name`, an `app_id`, a `key`, and a `secret`. The Gateway generates the three values. It stores the secret encrypted and never returns it.

`app_id` is Reverb's own identifier. It does not name an Orbit Project or Instance.

Reverb keeps each app's channels apart. A client connects with an app's key and sees only that app's channels. Reverb checks each publish request against the secret of the app in the request path. So an app's credentials cannot publish on another app's channels.

The role's first converge creates the `gateway` app. That app's secret never leaves the Gateway: you cannot add it to an Instance or destroy it. Apps outlive the role. Removing or moving the role keeps every app and its keys.

## Create an app

Create an app for each Project that publishes realtime events.

```bash
orbit websocket:app:create hauzer
```

The name is a lowercase slug of at most 63 characters. `gateway` and a taken name are refused with `validation.failed`. After it stores the app, the Gateway converges the active `websocket` role, so Reverb serves the new app. Without an active role, the role's next converge serves it.

`websocket:app:list` lists the apps by name. `websocket:app:show <name>` shows one app. Both return `id`, `name`, `app_id`, `key`, and `instance_ids`, the Instances that hold the app. Neither returns the secret.

## Destroy an app

Destroy an app when no Instance uses it any more.

```bash
orbit websocket:app:destroy hauzer
```

The Gateway deletes the app and converges the active role, so Reverb stops serving it. It refuses the `gateway` app with `websocket.app_reserved` (409). It refuses an app that an Instance still holds with `websocket.app_in_use` (409). Remove the app from each Instance first.

The CLI asks for confirmation, with No selected. Use `--force` to confirm in JSON or non-interactive mode. See [`websocket:app:destroy`](/cli/websocket#orbit-websocketappdestroy).

When the converge fails, the record change stays. Fix the cause, then run `orbit node:role:add <node> websocket --converge-existing`.

## Add an app on an Instance

Add an app to give an Instance its credentials, then synchronize the Instance's `.env`.

```bash
orbit instance:websocket:add hauzer --instance=<instance>
orbit env:sync --instance=<instance>
```

`PUT /api/v1/instances/{instance}/websocket-apps/{name}` writes the app into the Instance's [stored environment](/reference/environment-variables). `{instance}` is an Instance ID or an exact Route domain. The route needs an access grant to the Instance's Node. The keys are the names Laravel's own `reverb` broadcast connection reads:

| Key | Value |
| --- | --- |
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID` | The app's `app_id` |
| `REVERB_APP_KEY` | The app's `key` |
| `REVERB_APP_SECRET` | The app's `secret` |
| `REVERB_HOST` | `reverb.orbit` |
| `REVERB_PORT` | `443` |
| `REVERB_SCHEME` | `https` |

An Instance holds at most one app. A second add replaces the first app's values. The result names the Instance, the app, the keys, whether stored configuration changed, and the key count. It never holds a value. So the secret reaches the app without passing through a terminal or a chat.

`DELETE /api/v1/instances/{instance}/websocket-apps/{name}` removes the attachment and the seven keys. Other keys stay. An app that the Instance does not hold returns `websocket.attachment_missing` (404).

The CLI command is `instance:websocket:remove <app> --instance=<instance>`. It asks for confirmation, with No selected. Use `--force` to confirm in JSON or non-interactive mode.

Add and remove change stored configuration only. Run [`env:sync`](/reference/environment-variables#synchronize) to write the Instance's `.env`. A production [clone](/reference/environment-variables#laravel-mode) does not copy the seven keys of a candidate that holds an app. Add an app to the production Instance.

`reverb.orbit` is a private name. Only machines on the WireGuard network reach it.

## The Reverb server

The role's converge writes every app, ordered by name, into the server's `.env` as one JSON list:

```text
REVERB_APPS='[{"app_id":"…","key":"…","secret":"…"},…]'
```

[orbit-reverb](https://github.com/nckrtl/orbit-reverb)'s `config/reverb.php` makes one app of Reverb's `config` provider from each entry. Every app gets the same options: `allowed_origins` `*`, and client events from channel members.

Each converge restarts `orbit-websocket`, so Reverb loads the current code and every app. A restart closes every connection, and clients reconnect.

`ORBIT_WEBSOCKET_REF` must name an orbit-reverb commit that reads `REVERB_APPS`. With an older commit, Reverb starts without a usable app.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One server, one app per Project

Reverb already keeps apps apart by key and secret. One server per Project would add Processes, ports, and certificates for the same isolation. Sharing the Gateway's app was rejected, because a Project could then publish on the Gateway's channels.

### A JSON list in the server's environment

The `.env` is the one file the converge owns on the Node. A JSON list keeps orbit-reverb a plain Laravel app with Reverb's stock `config` provider. Rendering `config/reverb.php` into the checkout was rejected: the converge resets the checkout to its ref, so Orbit would overwrite a tracked file on every converge. Numbered keys such as `REVERB_APP_1_ID` were rejected, because the config would still loop over them. A custom app provider that asks the Gateway was rejected, because it puts Orbit code in the Reverb app.

### An app is an attachment, like a database

An Instance gets an app the way it gets a [Database connection](/reference/database-connections#add-a-connection-on-an-instance): a record on the Gateway, written into stored configuration, synchronized by `env:sync`. The attachment record lets destroy refuse an app that an Instance still holds.
