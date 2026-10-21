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

The [`websocket` role](/solutions/realtime-reverb) runs one Reverb server at `wss://reverb.orbit`. That server serves several Reverb apps. The Gateway publishes its own events with the app named `gateway`. Every other Project gets an app of its own.

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

The name is a lowercase slug of at most 63 characters. `gateway` and a taken name are refused with `validation.failed`. The Gateway saves a new registry revision, then notifies each Reverb process. Each process pulls the full snapshot and adds the app without restarting. A starting process must fetch a fresh snapshot before accepting connections.

`websocket:app:list` lists the apps by name. `websocket:app:show <name>` shows one app. Both return `id`, `name`, `app_id`, `key`, and `instance_ids`, the Instances that hold the app. Neither returns the secret.

A role failure keeps the saved app and its operation. App creation does not converge the role, restart Reverb, or publish private DNS. If delivery fails, keep the operation id and use `websocket:operation:show` or `websocket:sync` after recovery. Repeating the request with the same idempotency key returns the same app and operation. A pending unrelated app keeps the Gateway app available on processes that already serve it.

## Destroy an app

Destroy an app when no Instance uses it any more.

```bash
orbit websocket:app:destroy hauzer
```

The Gateway deletes the app and publishes a new registry revision. Each process stops accepting the app and closes only its sockets with code 4001. It refuses the `gateway` app with `websocket.app_reserved` (409). It refuses an app that an Instance still holds with `websocket.app_in_use` (409). Remove the app from each Instance first.

The CLI asks for confirmation, with No selected. Use `--force` to confirm in JSON or non-interactive mode. See [`websocket:app:destroy`](/cli/websocket#orbit-websocketappdestroy).

A saved change stays pending until every serving process confirms it. The API sends a revision notification and returns the current status without waiting for process reports. An unconfirmed change returns HTTP 202 with an operation ID and status URL. The CLI polls the operation to wait for application without restarting Reverb.

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

[orbit-reverb](https://github.com/hardimpactdev/orbit-reverb) contains the integration with Orbit. It has no separately versioned manager package. It uses Reverb's application provider and stock Pusher protocol. The server's protected environment holds its registry address and restricted machine credentials. It holds no static app list.

The Gateway owns the encrypted app secrets and a versioned registry. After commit, a revision-only webhook prompts each process to fetch the full snapshot. A 30-second reconciliation check repairs missed notifications. HTTP receipt confirms only delivery; a separate process report confirms application.

Each process registers a fresh boot identity before loading the registry. A report from an old boot cannot certify a replacement. Confirmation includes every serving process, including both ends of a role move. An absent or stale report keeps the operation pending. Each entry in operation `processes` includes the latest safe registry `error` code, or null after recovery or a new boot. The Gateway does not store connection measurements.

A running process keeps its last applied state when the Gateway is unavailable. This preserves other apps' connections, but removed credentials can remain usable until communication returns. A new process waits for a fresh snapshot before opening its listener.

### Remove a dead websocket Node

Use `orbit node:remove <node> --offline --force` only after confirming the machine is dead or isolated from clients. Orbit checks reachability before abandoning its roles. For an unreachable Node, this confirmation withdraws every runtime on that Node from apply confirmation and revokes its registry access. `orbit node:role:remove <node> websocket --offline --force` also withdraws its runtimes. An ordinary online role removal withdraws only the process it stopped.

The offline path cannot stop a process on the machine. Do not bring the abandoned machine back into service without reconverging it; it may still hold old app secrets. A live Node does not take the offline abandonment path.

### Rotate an app secret

Rotation keeps the app key, generates a fresh secret, and advances its credential generation. It updates attached Instances' stored environment in the same transaction. Each process closes that app's existing sockets with code 4201 when it applies the revision. Other apps stay connected.

Environment synchronization and application reload remain separate operations. The rotating app can have a short interruption while its publisher or private-channel authorization adopts the new secret. Revocation does not wait for an offline Instance.

### Server upgrades

Changes to PHP code still require a process restart. Announce a reconnect window for the initial migration and server upgrades. A replacement process with a drain period is separate work. Routine app additions, removals, and rotations do not converge the role.

After a successful server activation, Orbit keeps the active release and its immediate predecessor and deletes older staged releases. Activation waits up to 30 seconds for the local listener on the configured port. The server opens it only after loading a fresh registry snapshot. A timeout fails with `websocket.runtime_convergence_failed`, skips publication, and keeps the staged releases for recovery. A failed preparation or restart does not prune releases.

## Deploy and recover

Merge the managed orbit-reverb server first, then pin its squash-merge commit from main in the Gateway release and deploy that Gateway. Do not converge any websocket role between the server merge and the Gateway deployment: the older Gateway still installs server main. On that Gateway, app create and app destroy also converge the role, so pause those commands until the new Gateway deploys.

Each Gateway release pins an immutable orbit-reverb commit at `orbit.websocket.ref` in `apps/gateway/config/orbit.php`. This value has no environment override. Deploy the Gateway schema and control API, then converge the role in an announced reconnect window to install its pinned server and provision restricted process credentials. Confirm a fresh process report and Gateway realtime before closing the window.

Run `orbit node:role:add <node> websocket --converge-existing` only for server or role changes. App sync uses the registry API and does not restart the unit. After a rollback between pinned Gateway releases, converge the websocket role in an announced reconnect window to install the matching server commit. A Gateway rollback only switches Gateway code; it does not change the running Reverb process. Once the managed orbit-reverb server is on main, rollback to a Gateway release before this one is unsupported. Roll forward instead.

After restoring the Gateway database, restart every Reverb process in a reconnect window so it registers a new boot and loads a fresh snapshot. A changed registry epoch, a lower revision, or different contents at the same revision makes a running process exit with failure; systemd restarts it. An unchanged revision can return HTTP 304, so automatic detection does not replace the required restart after a restore. Recovery does not fall back to a static app list.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One server, one app per Project

Reverb already keeps apps apart by key and secret. One server per Project would add Processes, ports, and certificates for the same isolation. Sharing the Gateway's app was rejected, because a Project could then publish on the Gateway's channels.

### One durable registry with stock protocol handling

The Gateway is the credential authority. A full snapshot gives cold starts and reconciliation the same path. Revision-only webhooks carry no app secrets and reduce normal update latency. Periodic reconciliation repairs a lost notification without a second queue or registry store.

An SSH-delivered file would reuse existing transport but add per-Node secret copies and delivery state. A Redis snapshot would add a second durable credential authority. The integration uses Reverb's provider, route, and event extension points. It keeps stock Pusher behavior and makes no vendor patch.

### Availability during an outage

A running process keeps serving its last applied snapshot so a Gateway outage does not drop every app. Status reports expose pending revocations. A cold start requires fresh state to avoid restoring deleted apps or revoked secrets from disk.

### An app is an attachment, like a database

An Instance gets an app the way it gets a [Database connection](/reference/database-connections#add-a-connection-on-an-instance): a record on the Gateway, written into stored configuration, synchronized by `env:sync`. The attachment record lets destroy refuse an app that an Instance still holds.


### Confirmation and recovery

Operation status is `pending`, `applied`, or `superseded`. A later revision can confirm a change only if it preserves that change's app identity and, for rotation, its credential generation. Create followed by deletion therefore supersedes the create result.

The Gateway blocks Node deletion while a required Reverb process remains. A failed heartbeat does not withdraw required apply membership. After 60 seconds without a serving report, the Gateway excludes the endpoint from realtime config, auth, and broadcasting until a fresh serving report arrives. Verified shutdown of the default service withdraws only its own process slot.

Package convergence builds a separate release before stopping the service. A preparation failure preserves the running release. An activation failure requires forward recovery with a compatible package; Orbit does not automatically restart a static registry containing potentially revoked credentials.

The first release provisions one process per Node. Multiple workers on one Node need separate callback routes and service provisioning before production use. Two-process tests cover apply confirmation and stock Redis broadcasting.
