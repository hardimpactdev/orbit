---
title: "Conn layer"
description: "How the Gateway signs in Conn devices as Nodes and shares their settings through profiles. It holds an admin session for each registered T3 server, mints and revokes device pairings, and holds each Node's session on container T3 servers."
covers:
  - apps/gateway/extensions/conn/**
  - swamp/extensions/behaviors/operations/conn-*/**
  - swamp/workflows/workflow-operation-conn-*.yaml
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/2027_02_03_000001_add_instance_to_conn_environments.php
  - apps/gateway/database/migrations/2027_02_10_000000_add_renewal_to_conn_environments.php
  - apps/gateway/database/migrations/2027_02_15_000000_create_conn_threads_table.php
  - apps/gateway/database/migrations/2027_02_16_000000_add_held_sessions_to_conn_pairings.php
---

# Conn layer

This page is for the person who builds the Conn client, and for the Gateway maintainer. Conn is the fork of T3 Code that works with Orbit. The Gateway gives Conn one central layer: a device signs in as its Node, sees every registered T3 server, and shares workspaces, appearance, device settings, and the attached threads with the other devices of its profile. T3 traffic does not pass through the Gateway. A device talks to each T3 server directly over WireGuard.

The Gateway answers lists, profiles, and pairing. It mints T3 pairing links with an admin session that each T3 server hands it at registration, and it revokes the sessions it paired. On the T3 server of a container Instance it pairs itself for a Node when that Node first asks, and holds that Node's session, so a device never pairs there: see [Held sessions](#held-sessions).

The Conn layer is the `conn` [extension](/reference/extensions). Its code lives in `apps/gateway/extensions/conn`. While the switch is off, every endpoint below refuses with `extension.disabled` (409), including registration by a T3 server, and the MCP server hides the Conn tools. Profiles, servers, and pairings stay. New installs start with the switch off. Upgrades preserve its stored value. Activity keeps admin sessions and settings documents out through the `ActivityInputRedaction` plug-in point, also while the switch is off.

## Identity

A caller is the active Node that owns its WireGuard address, as for every other Gateway API call. Phones and laptops are Nodes too, without roles, much like an operator Node. A caller that is not an active Node gets `peer.identity_unknown` (403).

## Access

Conn uses the existing [node access](/cli/node/access-add) edges. It has no grant of its own. A T3 server belongs to the Node that registered it, so a server in a container counts as its host Node's server when that Node registers it.

| Endpoints | The caller needs |
| --- | --- |
| Server list, pairing list, show, create and revoke, the held session | Node access to the Node that registered the server. The list shows only those servers. |
| `GET /me`, `PUT /me/profile`, profiles and settings, Conn Operation show and cancel | Node access to at least one Node: the [collection rule](/cli/node#authority) that `node:list` uses. |
| Project list | Node access to at least one Node. The list shows only the Projects whose default Instance runs on a Node the caller has node access to. |
| Thread index list, `GET /threads` | Node access to at least one Node. The list covers only the container servers the server list shows the caller. |
| Thread record, `POST /environments/{environment_id}/threads` | Node access to the Node that serves the server. |
| Thread start, `POST /projects/{id}/instances` | Node access to the Node of the Project's default Instance. |
| `POST /environments`, registration | No edge. |

The Gateway Node, its serving host, and a Node with an edge to the Gateway Node have access to every Node, so they reach every server. When a server's Node was removed, only those callers reach it. A refused call gets `node_access.required` (403), with `details.consumer_node` and, for a server, `details.serving_node`.

For example, a phone with an edge to `beast` sees and pairs with the servers that `beast` registered, and no others. A [task VM](/reference/compute-drivers#placement-invariant) Node never holds an edge, so it can use no Conn endpoint except registration.

### Registration stays with its Node

The calling Node becomes the server's Node. Once a Node registered an environment id, another Node cannot register it again: it gets `conn.environment_owned` (409). When the Node is removed, the server has no Node. Then only a Node with Gateway access can register it again, which makes that Node the server's Node.

### Removing an edge

Removing an edge ends the sessions the Gateway holds for the consumer on the servers that the consumer now cannot reach. The Gateway stops serving them at once and admits their revoke on each server ([Held sessions](#held-sessions)). It does not revoke sessions that the consumer's device paired itself. Revoke those with `POST /environments/{environment_id}/pairings/revoke` and the consumer's `node_id`.

## Profiles

A profile is a named set of shared settings, such as "Nick". A Node belongs to at most one profile. On first connect the device lists the profiles, lets the user pick or create one, and binds to it. Later calls read the binding from `GET /api/v1/conn/me`. Every Node that may use profiles ([Access](#access)) sees every profile.

A profile holds one settings document. Its keys follow Conn's stores, so they stay camelCase. The document has four kinds of section:

```json
{
  "workspaces": [
    {
      "id": "ws-3f1c",
      "name": "Orbit",
      "color": "blue",
      "icon": "rocket",
      "image": null,
      "projectRefs": ["env-beast:project-1", "env-mini:project-2"],
      "projectKeys": ["orbit"]
    }
  ],
  "appearance": {
    "schema": 1,
    "mode": "system",
    "contrast": 100,
    "themes": {
      "light": {"id": "t3-code", "source": "stock"},
      "dark": {"id": "dark-ocean", "name": "Dark Ocean", "source": "published", "appearance": "dark", "colors": {"background": "#0b1220"}}
    },
    "customThemes": [
      {"id": "mine", "name": "Mine", "appearance": "dark", "colors": {"background": "#101010"}, "variants": {"light": {"background": "#ffffff"}}}
    ]
  },
  "devices": {
    "desktop": {"schema": 1, "values": {"fontFamilySans": "Inter", "chatWidth": "wide"}},
    "iphone": {"schema": 1, "values": {"baseFontSize": 16, "codeWordBreak": false}}
  },
  "attachedThreads": {
    "schema": 1,
    "threads": [{"environmentId": "env-fix-login", "threadId": "thread-7d2a"}]
  }
}
```

A section is `workspaces`, `appearance`, `attachedThreads`, or `devices.<type>`. The document accepts no other top-level key. Only `workspaces` is always present; a section that no device wrote is absent.

| Field | Rule |
| --- | --- |
| `workspaces` | List of at most 200. |
| `workspaces[].id` | Required string, at most 100 characters, unique in the list. |
| `workspaces[].name` | Required string, at most 100 characters. |
| `workspaces[].color` | Required string, at most 32 characters. |
| `workspaces[].icon` | Required key; a string of at most 64 characters, or `null`. |
| `workspaces[].image` | Optional; `null`, or a PNG, JPEG, or WebP base64 data URL of at most 200,000 characters. |
| `workspaces[].projectRefs` | Optional list of `<environmentId>:<projectId>` strings, at most 500: one project on one server. |
| `workspaces[].projectKeys` | Optional list of T3 logical project keys, at most 500: a project on every server where it exists. |
| `appearance` | Object of at most 512 KB, serialized. |
| `appearance.schema` | Required integer, 1 or more. |
| `appearance.mode` | Required: `light`, `dark`, or `system`. |
| `appearance.contrast` | Required integer, 50 to 200. |
| `appearance.themes` | Optional. A `light` slot and a `dark` slot. |
| `appearance.customThemes` | Optional list of at most 50. |
| `devices.<type>` | Object of at most 64 KB, serialized. At most 8 types per profile. |
| `devices.<type>.schema` | Required integer, 1 or more. |
| `devices.<type>.values` | Required flat map of at most 128 entries. |
| `attachedThreads` | Object of at most 64 KB, serialized. |
| `attachedThreads.schema` | Required integer, 1 or more. |
| `attachedThreads.threads` | Required list of at most 500; it may be empty. Each thread appears once. |
| `attachedThreads.threads[].environmentId` | Required: the `environment_id` of a Conn environment, at most 255 characters, no spaces or colons. See [Attached threads](#attached-threads) for which environments a write may name. |
| `attachedThreads.threads[].threadId` | Required: the T3 thread id, at most 255 characters, no spaces. |

A device type is 2 to 24 lowercase letters, digits, and hyphens, and starts with a letter, such as `desktop` or `iphone`.

A theme slot has a required `id` and a required `source`: `stock`, `builtin`, `custom`, or `published`. It may have a `name`, an `appearance` (`light` or `dark`), and `colors`. A `stock` or `builtin` slot needs only its id. A `custom` or `published` slot carries its resolved `colors`, so another device can draw a theme it does not have.

A custom theme has a `name`, an `appearance`, `colors`, and an `id` that is unique in the list. It may have `variants.light` and `variants.dark`, which are color maps.

A color map has at most 80 entries. A key is a color role: a letter, then letters and digits, at most 64 characters. A value is a string of at most 64 characters from `#A-Za-z0-9(),.%/ -`, so it cannot carry markup or a url.

A device `values` map has camelCase keys of at most 64 characters. A value is a boolean, a number, `null`, a string of at most 512 characters, or a list of at most 32 of those scalars. The Gateway checks the envelope and the value types. Each client checks its own keys and keeps the ones it does not know.

An empty object is refused wherever a map is expected, such as `colors`, `values`, or the `devices` map of a `PUT`. PHP cannot tell `{}` from `[]`, so the Gateway would hand it back as a list. Leave the key out.

The whole document is at most 4 MB. These two document limits, 4 MB and 8 device types, refuse only a write that grows the document past them. A write that does not grow past what the document held still passes when the document is already over a limit. The request stack trims every string and turns an empty string into `null`. A patch section `value` is the exception: an empty string there is refused, as [Versions](#versions) says.

A workspace covers its `projectRefs` and every project whose logical key is in `projectKeys`, as Conn combines them. T3 derives a logical key from the git remote by default, so devices that keep T3's default repository grouping agree on it.

### Attached threads

`attachedThreads` is the profile's list of threads: the sidebar of every device on the profile. A thread is attached when it is on this list. Detaching a thread removes it from the list. It never touches the T3 server or the thread, which stays as it is on its Instance.

A device connects to a container T3 server only while the profile has a thread of it attached. The container servers a device connects to are the environments in `attachedThreads.threads` that `GET /environments` lists for that device with an `instance_id`. So each device keeps to its own [node access](#access). The Gateway adds no field for this set: the device derives it from the two reads it already makes.

When the last thread of a container server is detached, the server leaves the set. Every device on the profile then disconnects from it at its next read. A thread of a host server may be attached too, so it shows in the profile's list. A device keeps host servers connected whether or not one of their threads is attached.

Write the section with `PATCH`, as the whole set, with the section version the client read:

```json
{"sections": {"attachedThreads": {"version": 4, "value": {"schema": 1, "threads": [
  {"environmentId": "env-fix-login", "threadId": "thread-7d2a"},
  {"environmentId": "env-fix-login", "threadId": "thread-9b10"}
]}}}}
```

To attach a thread, send the set with the thread added. To detach one, send the set without it; send `"threads": []` to detach every thread. The set is a list inside an object, so an empty set keeps its shape. `null` removes the section, which reads as no attached threads.

The Gateway checks each entry on the locked profile, after the version check:

- **Carried entry.** An entry already in the stored set, with the same `environmentId` and `threadId`, may stay, whoever writes.
- **Merge safety.** A device may carry an entry it cannot see itself. A merge never fails on another device's access.
- **New entry.** Any other entry is new, also a new thread of an environment that is already attached.
- **New entry check.** Its environment must exist, and the writing Node must have node access to that environment's Node, as for `GET /environments`.
- **Refusal.** A new entry that fails this check refuses the whole request with `validation.failed` (422). The error names the field, such as `…threads.1.environmentId`.
- **Own patch.** A refusal also refuses every other section in the same request. Today's Conn profile sync sends its sections in one patch, so send the attached set in a patch of its own.
- **Recovery.** The client removes the named entries from its set and retries. A refusal like this follows the removal of a server whose thread the client had just attached.
- **Gone environment.** The Gateway drops an entry whose environment was deleted from what it stores.

The check uses the writer's access, not the access of the profile's other Nodes. The writer is the only identity a request carries, and a write needs no binding to the profile. So a Node attaches only threads of servers it may reach, which are also the only ones it can pick.

A host environment whose Node is removed keeps its record without a Node ([Registration stays with its Node](#registration-stays-with-its-node)). Its entries stay in the set, because the environment still exists. Only a Node with Gateway access still sees it in `GET /environments`, so it leaves the derived set of every other device. Such a device may still carry those entries, but it cannot add new ones.

Two devices that change the set at the same time are handled by the section version, as for every section ([Versions](#versions)). The second write gets `conn.settings_version_conflict` (409). That client reads the section again and merges three ways: the remote set, plus the threads it added since its base, minus the threads it removed since its base. Then it retries with the new version. So an attach on one device and a detach on another both land. The Gateway needs nothing more for a set.

Orbit drops the entries of an environment that is gone:

- **At removal.** The terminal projection of an Instance's destroy deletes its environment. It also removes that environment's threads from the profiles that hold one, and reads no other profile.
- **Never refused.** The drop only shrinks a document, so no document limit refuses it. A document that is already over a limit cannot stop a destroy.
- **Versions.** This drop adds 1 to the section version and to the document version, with `updated_by` `null`.
- **Merge.** A device sees the new `settings_version` in `GET /me`. It reads the section and merges the drop as a remote change.
- **No write-back.** A device that writes from its older version gets a conflict. It cannot write the threads back.
- **On read.** A read leaves out the threads of an environment that was deleted. It writes nothing and adds no version. The next write of the section drops them from the store.

A device learns of a change from `profile.settings_version` in `GET /me`, which moves with every write. The Gateway sends no event: how quickly another device follows is the interval at which it reads `GET /me`.

A profile from before this section has no `attachedThreads` key and no version for it. It reads as no attached threads, at section version 0, so the first patch sends version 0. No migration runs. A client that does not know the section keeps working: it ignores the key, `PUT` cannot send it and keeps it as it is, and a patch changes only the sections it names.

### Versions

The document has a version, and so has each section. A new profile starts at document version 1 with no workspaces. Every write adds 1 to the document version. A write also adds 1 to the version of each section it changed. A section with no recorded version is at version 0. This includes a section written before sections had versions.

`GET /profiles/{id}/settings` returns `sections`: for each section that was written, its `version`, the time of the last write in `updated_at`, and the name of the Node that wrote it in `updated_by`. It is `null` when Orbit wrote the section, or when the Node was removed since. A section that is not listed is at version 0.

There are two ways to write. Prefer `PATCH`.

`PATCH /profiles/{id}/settings` changes the sections it holds, each with the version the client last read. It never touches a section it does not name. So a phone font change does not conflict with a Mac theme change.

`PUT /profiles/{id}/settings` replaces the sections it holds and keeps the others. It checks the document version, as it always did. `devices` replaces every device type, and the types it leaves out are removed. It must hold at least one type. To remove the last type, patch it to `null`.

A patch whose section is at another version is refused with `conn.settings_version_conflict` (409), and the Gateway writes nothing. `details.current_version` is the document version. `details.sections` holds the current version of each section the request named. The client reads again, merges the sections that changed, and retries.

A `value` of `null` removes any section but `workspaces`. Its version still goes up, so an old client cannot write it back. `workspaces` cannot be `null`: send `[]`. Only an explicit `null` removes a section. An empty or blank string is refused with `validation.failed` (422), and the error names the field, such as `sections.appearance.value`.

Both run in one transaction on the locked profile row, so two writes from the same version cannot both win. A section name that Conn does not know, such as `theme`, `devices`, or `devices.Phone`, is refused with `validation.failed`. A rule error names its field with dots, such as `sections.devices.iphone.value.values.baseFontSize`.

## Registration

A T3 server registers from the Node it runs on. A job on that host issues an admin session for the Gateway with the stock T3 CLI, so the server runs unmodified upstream T3:

```bash
t3 auth session issue --label "Orbit Gateway" --token-only
```

The job sends that token as `admin_session`, with the server's environment id, its label, and the URL that devices use to reach it over WireGuard.

1. The environment id must be new or registered by the calling Node. An id whose Node was removed needs a caller with Gateway access. Otherwise the request fails with `conn.environment_owned` (409).
2. The environment must name no Instance. The T3 server of a [container Instance](/reference/development-containers#records) is registered only by that Instance's create flow. A Node's registration of it fails with `conn.environment_owned` (409).
3. The Gateway reads `GET <url>/.well-known/t3/environment`. The served `environmentId` must equal `environment_id`, or the request fails with `conn.environment_mismatch` (422) before the session is sent.
4. It reads `GET <url>/api/auth/session` with the session as its bearer token. A session T3 does not accept fails with `conn.session_rejected` (502).
5. The session must carry `access:read` and `access:write`. `t3 auth session issue` grants both. A session without them fails with `conn.admin_scope_missing` (422).
6. The Gateway stores the session encrypted, with the expiry T3 reports. T3 sessions last 30 days by default.
7. After the registration commits, it starts native Swamp cleanup of earlier `Orbit Gateway` sessions. A cleanup or dispatch failure leaves registration successful. This is why the job must issue the session with the label `Orbit Gateway`.

Registering again replaces the stored session. The job registers on each server start and before the session expires. An environment whose session expired shows `status: session_expired` and cannot mint pairings until it registers again.

### Instance servers

`GET /environments` supplies the clone’s Orbit row to Conn: `project_id`, `project_name`, `project_slug`, `instance_name`, `node_name`, and `instance_url` are flat fields beside `environment_id` and `instance_id`. Conn learns the Project and Instance from this Gateway record. Container T3 servers stay stock; they need no T3 change. The same record is returned by the MCP tool `conn-environment-list`.

`instance_url` is the Instance’s own URL: HTTPS on its authoritative Route’s domain, or its first Route’s domain when none is authoritative, and `null` when it has no Route. It is separate from `url`, which reaches T3. Host environments return all six fields as `null`, as they do for `instance_id` and `power_state`.

A clone of a container default runs its own T3 server. Its create flow registers it ([T3 registration](/reference/development-containers#t3-registration-and-pairing)): the run issues and checks the sessions in the container, and the Gateway records the server from the run's result through the plug-in point `InstanceLifecycleContribution`, which this extension contributes.

- The record names the Instance and its Node, with the URL `http://<Node WireGuard address>:<T3 port>` and the label `<project> / <instance>`.
- The Gateway reads the session from the vault key the result names. It does not call T3 again: the run made the checks above in the container.
- A new record starts with its cleanup done. Recording a server again bumps the cleanup revision and starts the cleanup.
- A resumed create can leave the sessions of its failed run valid in T3, until a later renewal cleans them.
- Orbit renews the Gateway's session on the server: see [Renewal of an Instance server](#renewal-of-an-instance-server).
- The bumped revision also stops a Node's registration of the same id that read the row before the record: that registration compares the revision again in its lock and fails with `conn.environment_changed`.
- While Conn is off, a clone is refused with `operation.kind_unavailable`: nothing would record its server.
- A create admitted before Conn went off still records its server.

Destroy keeps this contribution available while Conn is disabled. Admission captures its environment. The run deletes T3 with the container, retiring all sessions and unused links. Terminal projection deletes its execution copies, environment and pairings, and drops the environment's [attached threads](#attached-threads) from every profile, before core deletes the Instance. New pairings refuse an Instance marked removing.

### Renewal of an Instance server

T3 issues the Gateway's session for 30 days. Orbit keeps it alive; no device and no host job takes part. Orbit does not ask T3 for a longer session: a longer one would keep a stolen session valid for longer. The [renewal chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/environment-renew.mmd) has the steps.

#### When

Every hour the Gateway admits `conn.environment_renew` for each active Instance server whose session ends within seven days. It also admits one for a session that has ended. So a container that was stopped past the end gets a new session within an hour of its start. When a try found it stopped, the wait is up to six hours; the route below renews it at once.

The hourly renewal skips a [sleeping](/reference/development-containers#sleep-and-wake) Instance. A successful `instance.wake` admits the renewal at once instead, through `InstanceLifecycleContribution::projectWake()`, when the session ends within seven days or ended during the sleep. A wake's renewal does not wait for the one-per-Node limit below: the woken server gets its session at once. A refused admission (a renewal already runs, Conn is off) is logged and never fails the wake. The wake also renews the server's [held sessions](#held-sessions) that end within seven days. Devices that paired themselves renew their pairings through the Gateway as below, with no new pairing by the user.

#### The idle sleep's read

The [idle sleep](/reference/development-containers#sleep-and-wake) uses the same session to read a running clone's threads: one `GET /api/orchestration/shell` with the header `x-t3-orchestration-protocol: 2`. Conn contributes this read as `InstanceAgentActivity`. A refused or failed read, or Conn turned off, keeps the Instance awake. The read writes nothing in the container, and a sleeping Instance is not read.

One tick admits at most five renewals, the earliest end first. It admits at most one per Node at a time. A server whose last renewal failed comes after the others, so it never holds its Node's slot from them. Clones made on one day reach the margin in the same hour.

#### How

The run reaches the Node over its SSH account. In that Instance's own container, as `agent`, it issues a new `Orbit Gateway` session with `t3 auth session issue`. It needs no old session, so an ended session renews too.

A clone always gets its own session. The run names only that clone's container. It refuses a server that serves another environment id.

#### Order

The run proves the new session with one authenticated read of `/api/auth/session` at the recorded URL. That is the path a pairing takes. Only then does the run store the session.

The Gateway records the session. Only then does it admit the cleanup that revokes the older `Orbit Gateway` sessions. Pairing admission locks the server row, so a pairing carries either the new session or the old one. While a pairing that carries the old session still runs, the cleanup waits with `conn.pairing_in_progress`. The next tick admits it. So a pairing never fails because of a renewal.

#### Secrets

The run's input holds only the intent and an empty secrets map. The new session goes from a 0600 file on the Node to a native sensitive field in the vault. The run removes each private copy on every path. A cancelled run can leave its Node copy; the next renewal on that Node removes copies older than ten minutes. The session never enters an Operation input, result, Activity or log. The Gateway deletes the vault copy after it records the session.

#### Failure

A refusal fails the Operation with a `conn.*` code. The server record keeps the code as `renewal_error_code` and its time as `renewal_checked_at`. The old session stays, and the next tick tries again.

After `conn.environment_stopped`, the server waits six hours before the next try. So a stopped container does not fill the Operation list.

#### At once

`POST /environments/{environment_id}/renew` admits the same Operation now, also for a stopped container. It needs node access to the server's Node. It refuses a host server with `conn.environment_not_instance`. It refuses with `operation.target_busy` while a renewal or cleanup of that server runs.

The `Orbit Swamp` session of the container is not renewed: nothing uses it yet. A host T3 server renews its session itself, by registering again.

| Renewal code | Meaning |
| --- | --- |
| `conn.environment_stopped` | The container does not run. |
| `conn.environment_missing` | The Node has no such container. |
| `conn.environment_unreadable` | Incus did not answer for the container. |
| `conn.session_issue_failed` | T3 in the container did not issue a session. |
| `conn.node_unreachable` | The run did not reach the Node, or failed before its Node step. |
| `conn.server_unreachable` | The T3 server did not answer at its recorded URL. |
| `conn.environment_mismatch` | The URL serves another environment id. |
| `conn.session_rejected` | T3 did not accept the new session. |
| `conn.admin_scope_missing` | The new session lacks `access:read` or `access:write`. |
| `conn.response_invalid` | An answer did not have T3's shape. |
| `conn.renewal_incomplete` | The session was issued, but the run ended before it was stored. |
| `conn.renewal_failed` | The run failed without a `conn.*` code. |

## Thread index

The Gateway keeps an index of the threads on each container T3 server. A device reads one list from the Gateway to find a Project's or an Instance's threads. It connects to a server only when it opens one of that server's threads, not to every server at launch. The [index chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/thread-index.mmd) has the steps. Host servers, those with no Instance, have no index.

### A row

The table `conn_threads` holds one row per thread: the server's environment, its Instance, T3's thread id, the title, T3's thread `status` as `state`, `needs_input`, the time of the last user message and of the last run's end, and `seen_at`. A thread needs input when T3 holds a blocking runtime request for it, such as an approval or a question; a request that says `blocking: false` does not count, as for the idle sleep. `seen_at` is when the Gateway last read the thread on its server, or heard of it through a write-through. The index lists the shell's `threads`; an archived thread leaves it.

The index stores no message text. The title is the only text, and it can still leak: T3 names a thread after its first message by default, so a title can carry a customer name, a bug, a branch or a pasted secret. So the index has the same readers as the server list. A caller reads the rows of a server only through node access to that server's Node, the [rule above](#access). A write-through records the thread id in Activity, never its title.

### The poll

Every minute, one pass reads the shell of every container server whose Instance runs: `GET /api/orchestration/shell` with the admin session the Gateway holds and renews, and `x-t3-orchestration-protocol: 2`, the read of the [idle sleep](#the-idle-sleeps-read). It writes nothing in the container. All servers are read at once; each has 3 seconds to connect and 10 seconds in total, so one server never holds the pass. The pass is the command `conn:threads:read`, a unique job on the `problems` queue of the [Gateway queue](/reference/gateway-queue#scheduled-work). It waits behind task work and never runs on `operations`; it ends within seconds, so a release's Horizon hand-over waits at most one pass.

A server's read replaces its rows: each listed thread is written, and a thread that left the shell leaves the index. A row that a write-through recorded after the read began stays.

A read that began before the server's last written read is dropped, so a slow read never brings back a thread that a newer read removed. A failed try does not count for that order, and a late failure never sets the server's `read_at` back. Reads that begin within the same second are not ordered: the index stores times to the second.

A server that is not read keeps its rows and their `seen_at`, and its record says why in `threads_read_error`:

| Reason | Meaning |
| --- | --- |
| `conn.environment_asleep` | The Instance sleeps or is stopped. It is not read, and its error stays as it was. |
| `conn.session_expired` | The Gateway's session ended; its [renewal](#renewal-of-an-instance-server) comes first. |
| `conn.server_unreachable` | The server did not answer within its bounds. |
| `conn.session_rejected`, `conn.request_refused` | T3 refused the read. |
| `conn.response_invalid` | The shell did not have the shape the index reads. |
| `conn.index_write_failed` | The Gateway could not write the server's rows, for example while SQLite stayed busy. The error is reported; the next pass tries again. |

A failure of one server never ends the pass, and a failed read or write never fails its caller: the sleep, the task turn or the list.

### The switch

Every read of a server by the index waits for the operator's switch `ORBIT_CONN_THREAD_INDEX_POLL` (`orbit.conn.thread_index_poll`): the minute poll, the read-through of one Instance's list and the last read before a sleep. It is off by default. Turn it on in `/home/orbit/shared/gateway.env` and [apply the env change](/reference/gateway-recovery#apply-an-env-change); no release is needed, and the next scheduler tick sees it. While it is off, the routes and the write-through still run: the list shows only written-through rows and reads no server.

While the switch is off, nothing prunes the index: a thread that leaves its server stays in the index as the write-through recorded it. A thread leaves the index only through a read, so that rule holds only while the switch is on. Destroying the Instance still removes its rows.

### Write-through

A thread is in the index at once when Orbit or Conn starts it. Conn records a thread it started with `POST /environments/{environment_id}/threads`, after it launched the thread on the server. A task copy's turn records the thread that `t3 thread start` made, with the turn's title, when the turn's projection commits. Both go through one rule: the row is written with `seen_at` now and keeps the state of the last read, null for a new row. A thread that an agent or a user starts on the server by other means appears with the next read.

The write is one upsert on the row's key, so a read that writes the same thread at the same moment does not collide with it. A title longer than 255 characters is cut; a longer one is not refused.

### The list

`GET /threads` returns the rows of the covered servers, the newest user message first, and `meta.environments`: every covered server with its `power_state`, `read_at` and `read_error`, also a server without threads. `project` and `instance`, both optional positive ids, narrow it.

Browsing is one Gateway read. A list of every server, or of a Project, reads only the index and never a server; a device judges how fresh a server's rows are from its `read_at`.

Only a list that names one `instance`, the server a device opens a thread on, reads that server through first: while the switch is on, the server runs and its last read is older than 10 seconds. That read waits at most 0.5 seconds; a server that misses it keeps its rows and records the reason, and the list does not try it again within 10 seconds. So a list that names an Instance can take up to about 0.5 seconds more when its server is slow; the 300 ms target holds for the lists that read only the index.

### Cost

Measured on 2026-11-14 on a nested test fleet, against stock T3 0.1.7 shells of about 1.9 KB per thread, with 10 threads a server and 30 ms per answer:

| Servers running | One pass | Shell payload | Gateway memory |
| --- | --- | --- | --- |
| 3 real T3 servers | 33 to 164 ms | 2 to 6 KB a server | — |
| 43 | 150 to 190 ms | 0.8 MB | +12.5 MB |
| 200 | 0.6 s, 2 s when it first writes 2,000 rows | 3.8 MB | +30.5 MB |
| 200, 2 of them hanging | 10.6 s: the hanging ones end at their 10-second bound | 3.7 MB | +30.5 MB |

A list reads only the index unless it names one Instance. Measured on the nested test fleet's Gateway over HTTPS, with 200 servers and 2,000 rows: the full list (630 KB) answers in 78 ms (median of 20; at most 139 ms), a Project's list in 86 ms, and one Instance's list in 30 to 150 ms. `GET /environments` for the same 200 servers took 123 ms. The list builds its rows straight from the table, without models. With real servers, one Instance's read-through added 70 to 130 ms on the test fleet.

### Sleep and removal

Before a sleep is admitted, from the idle sleep or from [`instance:sleep`](/cli/instance/sleep), the index reads the server one last time. It reads after the sleep's refusals and replay, so a refused or repeated request waits for no read, and outside the Instance lock. It waits at most 3 seconds.

The sleep switch does not matter: the index's switch does. A failed read keeps the rows and does not stop the sleep. An asleep or stopped Instance keeps its last rows with their `seen_at` until it wakes and the next read runs. Destroying the Instance removes its server record, and the rows go with it.

## Threads in an Orbit Project

Conn starts a thread in an Orbit Project in a new clone of the Project's default Instance ([Threads](/reference/development-containers#threads)). The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps.

`GET /projects` (`conn-project-list`) defaults to the compatible answer for installed Conn clients: Projects with a reachable, active container default and a seed snapshot. Installed clients treat every returned Project as startable, so unavailable Projects require an explicit opt-in.

`include_unavailable=1` or `include_unavailable=true` returns all reachable Projects, using the same scope as `project:list`. A caller with Gateway access can see all Projects. Other callers can see each Project with any Instance on a Node they reach. Both forms return rows in ascending slug order; slugs which compare equal have no defined relative order.

The optional `include_unavailable` query accepts only `1`, `true`, `0` or `false`; absence means false. Any other value for `include_unavailable` fails with `validation.failed` (422). Unknown query fields are ignored. False preserves the pre-directory membership rule, which checks readiness and access rather than the later technical checks. A returned Project can therefore have `cloneable: false` for a technical refusal that also applied before the directory changed.

Each entry keeps its Project fields and adds `instance_domain_suffix`, `checks`, `cloneable` and `cloneable_reason`. The default's `node_id` and `node_name` are null when there is no default or the caller cannot reach its Node. A hidden default also has a null suffix.

`cloneable` uses the same read-only admission check as Conn create and the name check. It requires a reachable, active container default with a seed snapshot, valid Project source defaults, supported development placement and PHP, and the clone's runtime contributions and model route.

Each check returns a stable `key`, a short `label`, and `status`: `pass`, `fail` or `not_checked`. Only a failure includes `code` and `message`. The message says what to fix in Orbit. Checks stop at the first failure; later checks are `not_checked`. `cloneable` is true only when all checks pass. `cloneable_reason` is null or the first failure's code. With `include_unavailable=true`, an unavailable Project stays selectable so Conn can show its checks. Check again re-reads this list. Each read uses the current recorded migration, seed, Node and TLD state; eligibility is not cached.

The keys below are a stable contract, in this order. New keys may be appended. Clients must tolerate unknown keys. Labels are display text; clients identify checks by key.

| Key | Check | Failure code |
| --- | --- | --- |
| `default_instance` | Default Instance exists | `instance.default_missing` |
| `container` | Default runs in a container | `instance.default_missing` |
| `active` | Default is active | `instance.default_missing` |
| `seed_snapshot` | Default has a seed snapshot | `instance.default_missing` |
| `node_access` | Caller can reach the default's Node | `node_access.required` |
| `source_access` | Supported repository access | `operation.kind_unavailable` |
| `source_defaults` | Valid repository, default branch and root | `project.source_defaults_incomplete` |
| `production_placement` | No active app-prod role | `instance.candidate_required` |
| `node_active_linux` | Node is active and runs Linux | `instance.node_inactive` |
| `app_dev_role` | Active app-dev role | `instance.node_not_app_dev` |
| `source_profile` | Source metadata is valid | The recorded `source_profile_error` |
| `php_readiness` | Supported Project PHP | `project.source_defaults_incomplete` or `instance.host_unavailable` |
| `runtime_contributions` | Conn can record the clone's server | `operation.kind_unavailable` |
| `model_route` | Model router and guard are ready | `operation.kind_unavailable`, `ai_router.disabled` or `ai_router.guard_missing` |
| `native_create` | Node is not a live task VM | `instance.task_vm_node` |

The first four checks share one error code and give different fixes: create the default, migrate it into a container, finish its create, or deploy its seed snapshot. These readiness checks precede Node access for both create and the name check. If readiness and access both fail, they answer `instance.default_missing` (409). The Project name remains visible in the list, but a hidden default's Node fields and suffix stay null. Check messages and refusals contain no Node names or IDs.

`cloneable` does not cover host bridge and shared-folder readiness; create is the guard. It promises no capacity or later success. A read-only readiness method on `InstanceContainer` can join this check after the pending container changes land.

`instance_domain_suffix` is `.<project-slug>.<effective-tld>` from a visible default's usable placement. It is null without a default, usable placement or TLD, or for a non-web Project. It can exist while the default is not ready to clone. The name check uses this same suffix and validates the complete domain.

The list creates no records, including Activity rows.

`GET /projects/{id}/branches?query=` (MCP `conn-project-branches`) reads remote branches with the Project's source access. It requires access to the ready default Instance; a Project without one fails with `instance.default_missing` (409). Each entry has `name`, `default`, `pull_request` (`null` or `{number, title, url}`), and `instance` (`null` or `{id, name}`). The optional `query` is a case-insensitive substring of at most 255 characters; leading or trailing spaces are refused. Arrays, control characters and over-long whitespace fail with `validation.failed` (422). The source read has a ten-second budget starting before credential acquisition, including authentication and git. An unreadable remote, including a read that exceeds that budget or 4 MiB of remote-head output, fails with `source.head_unavailable` (502).

The response contains at most 100 matches, after filtering: the Project's default branch first if it matches, then bytewise ascending branch names. Further matches are omitted; narrow `query` to find them. Instance matching uses the recorded branch, then branch override, then the default branch for `default` or the Instance name. Only Instances on Nodes the caller reaches can match; the lowest Instance id wins when several match. Entries contain no Node details.

For a GitHub source, `pull_request` is the newest OPEN pull request whose head is that branch in the Project's repository, ordered by creation time descending, then number descending; closed requests are ignored.

One repository listing reads up to five pages of 100 open requests, with a separate ten-second budget starting before PR credential acquisition and at most five seconds per listing request. The two external reads take at most twenty seconds combined, plus local validation and projection. GitHub App mode looks up the installation once and mints separate contents-read and PR-read tokens within their respective budgets; CLI mode acquires its token once and reuses it.

If GitHub fails, returns malformed data, or has more than 500 open requests, all entries keep `pull_request: null` and `meta.pull_requests_unavailable` is `true`. Otherwise that marker is `false`. A non-GitHub source still lists readable branches with null pull requests and a false marker.

`GET /projects/{id}/instances/check?name=fix-login-k3j9` checks a proposed clone name without reserving it. It uses the same clone admission check as create. A missing default, checkout default, inactive default or missing seed still answers `instance.default_missing` (409), even though the Project can appear in the list. The MCP tool is `conn-instance-name-check`.

- It answers 200 with `{name, available, reason, domain}`. An available name has `reason: null`. Reasons are checked in order: `invalid`, then `default`, then `taken`, then `domain_invalid`.
- `invalid` means the name is not a lowercase DNS label of 1–63 characters, with no leading or trailing hyphen. An empty string is invalid.
- `default` means the name is `default` or the Project's default-branch alias.
- `taken` means any Instance of the Project holds the name, on any Node and in any state, including the caller's own Instance.
- `domain_invalid` means the generated domain cannot be issued by the live CSR producer. See [certificate limits](/reference/routes#certificate-request-forms).
- `domain` previews `<name>.<project-slug>.<effective-tld>`. The default's placement selects the active Cluster TLD before the Node TLD.

Create refuses an invalid domain with `route.domain_invalid` (422) before starting an Operation. Its admission transaction leaves no Instance reservation.

The domain is null for an invalid name, a non-web Project, or no usable domain.

- Missing or non-string `name`, or an unknown query field, fails with `validation.failed` (422). Access is checked before the name.

The check creates no records, including Activity rows. A later create can still refuse with `instance.name_taken`; create keeps its same-caller resume rule.

`POST /projects/{id}/instances` with `{"name"}` creates the thread's clone:

- It admits `instance.create` on the default's Node, as `instance:create PROJECT NODE NAME` does.
- It answers the Operation (202). Its `target_id` is the new Instance.
- The caller needs node access to the default's Node. After the four readiness checks pass, missing access answers `node_access.required` (403).
- The same name again resumes the caller's own clone, as `instance:create` resumes a failed create.
- A name that another device's create holds fails with `instance.name_taken` (409).
- A name that resolves to `default` fails with `validation.failed` (422).
- A Project without a ready container default fails with `instance.default_missing` (409).
- An `Idempotency-Key` is bound to the calling Node.

The optional `branch` object selects another source: `{"mode":"new","from":"release"}` creates the Instance-named branch at the fetched tip of `release`; `{"mode":"existing","name":"feature/login"}` checks out that remote branch. New mode requires `from` and forbids `name`; existing mode requires `name` and forbids `from`. Extra keys, null, and invalid Git branch names fail with `validation.failed` (422). With no `branch`, admission makes no remote read and preserves the existing create, result and retry behavior.

`branch.name` and `branch.from` take short branch names. Values starting with `refs/` fail with `validation.failed` (422) on that field. Names such as `heads/x` are valid.

A request that sends `branch` reserves one branch per Project inside create's transaction. Every Instance on every Node and in every state holds its recorded or intended branch until deleted. The default holds the Project's default branch. The client must not offer the default branch as a target. Old requests without `branch` and core create retain their existing rules.

Before reserving any Instance, Operation or container, explicit selection reads the credentialed remote heads once. Credential acquisition and git share ten seconds. Only a successful read proves absence. These admission refusals leave no new Instance, Operation or reservation:

| Code | Status | `details` |
| --- | --- | --- |
| `branch.in_use` | 409 | `{"instance":{"id":42,"name":"login"}}`, or `{}` when the holder's Node is hidden. |
| `branch.not_found` | 422 | `{"field":"branch.name"}` for missing existing branch; `{"field":"branch.from"}` for missing base. |
| `branch.exists` | 422 | `{"field":"name","branch":"fix-login"}` when the derived new target already exists remotely without a holder. |
| `source.head_unavailable` | 502 | Empty details; authentication, unreadable, malformed or timed-out remote read. |

The name check does not know remote branches; create reports `branch.exists`. A caller can resume the same name only with an identical selection; a changed mode, target or base answers `instance.placement_conflict` (409). An Idempotency-Key replay returns its Operation. Admission freezes the mode, target, base and source commit for resume. Explicit results add `branch` and `base_branch`; the latter is null in existing mode.

After admission, a remote change or an execution failure ends the Operation and keeps the Instance for retry. If mirror preparation observes a new target remotely, an existing branch or base disappears, a fetch cannot read the remote, or a rewritten source does not provide the frozen commit, `clone-container` fails with `instance.create_clone_failed`. That phase prepares the Node mirror; `clone-workspace` then checks out its bundle. A bundle, transfer or guest checkout failure carries `instance.create_workspace_failed`. These later failures cannot be closed by the earlier remote read.

A legacy or core create can also claim the same branch after opt-in admission, because those calls retain their old rules; this does not produce a later occupancy check or failure. Explicit admission serializes competing explicit creates, not remote repository writes or legacy calls.

A remote target created after the Node's final head read cannot be detected by create; it does not change the frozen local commit and produces no create failure. A later Git push can encounter that remote conflict.

Checkout stays inside `clone-workspace`. No setup or deploy command runs again after checkout. Dependencies and build come from the default's seed, and may need an install or build for the selected code.

Conn follows that Operation with `GET /operations/{id}`. Conn Operation show also shows an `instance.create` that the caller started, here or with the core Instance API, and an `instance.destroy` started through the Conn endpoint below. Other destroy Operations keep their existing 404 on this path. It also shows a clone's [completion](/cli/instance/complete), `instance.complete`, to the Node that started it and to the Node that created the clone: the create's `result.completion_operation_id` names the first completion. Conn Operation cancel cancels none of these three kinds; it answers 404, and a removal cancels a running completion.

Operation show returns the same recorded `steps` as core Operation show, in recorded order: `name`, `job`, `status`, `started_at` and `finished_at`. Steps contain no command output, secrets or environment values. Both endpoints accept `after_sequence` but currently return all recorded steps.

Conn maps these stable container-clone step names to stage detail, in this order: `clone-container`, `clone-workspace`, `clone-t3`, `clone-register`. Other steps can occur between them. `clone-t3` runs beside `clone-workspace`, so it can start and end before `clone-workspace` ends; `clone-register` always comes after both. Renaming or removing any of these four names is a breaking change for Conn. `clone-workspace` runs only the checkout part now: the branch, the checkout mark and `.env`.

A succeeded `instance.create` means the thread is usable: T3 is registered and the workspace is checked out. It does not mean fully set up. The create's result names the clone's completion in `completion_operation_id`; the database, the Processes, a nested fleet and the Route follow there, and only the Instance's `status: active` means fully set up ([Complete a clone](/reference/development-containers#complete-a-clone)). A Conn build that does not follow the completion marks every create row done at the create's success: `Copy the container`, `Copy the workspace`, `Start the T3 server` and `Register with Orbit` are done then, and `Publish the address` is not published yet. A failed baseline read fails the create at `Register with Orbit`.

A server of an Instance names its `instance_id` and the container's `power_state`, `running` or `stopped`. Other servers have `null` for both. Conn shows a `stopped` server as asleep.

## Remove an Instance

`POST /instances/{id}/destroy` requires an `Idempotency-Key` and `{"acknowledge_unsaved": true|false}`. The caller needs access to the Instance's Node. Only non-default container Instances can be removed here:

- A default refuses with `instance.default_protected` (409).
- A host checkout refuses with `instance.not_container` (409).
- Without acknowledgement, a `dirty` or `unknown` workspace refuses with `instance.unsaved_work` (409). Its error details are the whole read below.
- Without acknowledgement, a `clean` workspace is removed.

The remove sheet calls `GET /instances/{id}/work` when it opens. The removal without acknowledgement runs the same live read, so the sheet and the click cannot disagree. If work appears between them, the click is refused with the new details. The caller needs access to the Instance's Node, as for the removal. A host checkout refuses with `instance.not_container` (409). The read answers within about 4 s and never writes to Git. [Workspace work state](/reference/development-containers#workspace-work-state) defines what counts as work.

```json
{"instance_id": 2610, "state": "dirty", "reason": null, "read_at": "2026-11-13T20:31:02Z", "duration_ms": 180,
 "baseline": "recorded", "head": "feature-x", "uncommitted": 4, "unpushed": 2,
 "modified": {"count": 3, "names": ["app/Foo.php", "routes/web.php", "README.md"]},
 "untracked": {"count": 1, "names": ["notes.txt"]},
 "commits": {"count": 2, "branch_count": 1, "branches": [{"name": "feature-x", "count": 2}]},
 "stashes": {"count": 1, "names": ["stash@{0}: WIP on feature-x: 1a2b3c4 msg"]}}
```

| Field | Meaning |
| --- | --- |
| `state` | `clean`, `dirty` or `unknown`. |
| `reason` | Only for `unknown`: `stopped`, `asleep`, `timeout`, `not_a_repository` or `unreachable`. |
| `baseline` | `recorded`, `default_now` or `none`; `null` when `unknown`. |
| `uncommitted` | `modified.count` + `untracked.count`; `null` when `unknown`. |
| `unpushed` | `commits.count`; `null` when `unknown`. |
| `modified`, `untracked`, `stashes` | `count` and the first 5 `names`; `null` when `unknown`. |
| `commits` | `count` of distinct commits, `branch_count`, and the first 5 `branches` by name with their own count. |

Names come from the workspace. Each is at most 200 characters; Conn shows them as plain text.

With acknowledgement, Orbit admits the existing `instance.destroy` engine with force enabled and answers its Operation with HTTP 202. Existing lifecycle, Route and database blockers keep their codes and messages. The shared engine tries the same pure preparation before it stops Schedules and discards that result. Admission runs the preparation again inside its transaction and records only current facts. Conn runs no separate engine checks. If Schedule removal fails, no removal intent or Operation is recorded, and the Instance and Route stay active. Follow the Operation on `GET /operations/{id}`; `succeeded`, `failed`, `cancelled` and `interrupted` are terminal.

Inherited refusals keep their codes:

| Code | Meaning |
| --- | --- |
| `instance.lifecycle_busy` | A move is running or has not finished. |
| `instance.transfer_incomplete` | A transfer needs recovery. |
| `instance.clone_in_progress` | This Instance is an incomplete clone's candidate. |
| `websocket.route_in_use` | A public Reverb attachment still uses the Instance. |
| `analytics.tracking_hosts_exist` | Analytics still publishes a tracking host. |
| `instance.remove_refused` | The state, source layout or attached Route is unsafe to remove. |
| `instance.removal_conflict` | Saved removal, placement or Route inventory changed. |
| `instance.destroy_unsupported` | Removal requires its recorded development placement and non-root account. |
| `operation.target_busy` | Another destroy owns a member of the removal set, or a restore owns the Instance. |
| `operation.kind_unavailable` | The placement has no supported native destroy path. |
| `route.withdrawal_node_unavailable` | The admitted withdrawal Node is inactive. |
| `database.connection_attached` | An owned database is attached outside the removal set. |
| `database.ownership_unproven` | The database's owner or managed state cannot be proven. |
| `database.removal_conflict` | Owned database membership, server state or SQLite path is unsafe. |
| `database.server_required` | The remote database has no managed server. |
| `database.server_in_use` | Database creation is unfinished on its server. |
| `database.system_database` | A system database cannot be dropped. |
| `caddy.render_failed` | The shared Caddyfile could not be rendered safely (409). |
| `certificate.scope_invalid` | A Route leaf has no valid admitted account or scope. |
| `swamp.ssh_identity_unavailable` | The control Node SSH identity is not configured. |
| `swamp.ssh_target_unavailable` | The stored target address, account or host key is missing. |

Infrastructure failures keep their existing codes, such as `swamp.schedule_publish_unavailable` (503). A failed Operation can have `error: null` even when `error_code` is set; Conn must use that code without assuming `error.detail` exists.

A missing SSH pin or Caddy render fault found by the dry run refuses removal before any effect, for Conn, the core API and the CLI. If a fact changes after the dry run, admission uses its current value and may refuse; Schedules may already be stopped.

### Remove a clone whose completion runs

A clone's create ends when its thread is usable; its [completion](/cli/instance/complete) then sets up the rest. A removal from Conn while that completion runs is one call. In one transaction the Gateway records the cancel of the completion and admits the removal with status `waiting` and `after_operation_id` set to the completion. The call answers 202 with the removal at once; it is the one Operation to follow. Swamp is asked to stop the completion after the commit, and the reconciler asks again on each pass until it stops. A completion that had not started yet ends `cancelled` at once.

The removal starts when the completion has ended. The completion's SSH and shell steps take the abort at once: Swamp ends their connection to the Node. Its own `withdraw-route` step takes back a Route it published, and the removal then deletes the container, the clone's database, the Route, the completion's vault copies and the records.

A database fill that already runs is not cut: the cancel waits until the fill has ended, within its 600-second limit ([Cancel an operation](/reference/operations#cancel-an-operation)). Meanwhile the removal shows `waiting` and the completion keeps the clone's lock and its Route hold. Then the completion takes the cancel, withdraws its Route and ends, and the removal drops a database that no copy touches.

Three cases are not covered. A run that the Gateway times out or loses during the fill gets the cancel at once, so its copy can still outlive it. A cancel that reaches Swamp in the moment the fill starts, before the Gateway has recorded that start, can cut it. A CLI destroy of a clone whose create still runs its fill is admitted beside the create, and can race that copy.

The removal's run budget counts from its start, not from its admission. Several removals at once each wait for their own clone's completion; removals that share Route Nodes start one after another, oldest first, as every Route write does.

While the completion runs, the remove sheet's read answers as for any running container: the completion writes no tracked file, so a workspace without the user's work reads `clean`. Its `baseline` is `recorded`: the create records it before it ends. After a failed or cancelled completion the clone stays `source_resolved` with its container running; the read and the removal work as for a retained failed create.

A retry of a removal never starts after a completion that an earlier call named: from Conn it cancels the running completion and waits again; from the CLI it refuses while the completion runs.

The CLI and the core API keep their refusal: `instance:destroy` while the completion runs answers `operation.target_busy` (409) with `details.operation_id`, the completion. Follow it to its end, or cancel it, then destroy again.

The same caller, key, Instance ID and body return the same Operation with HTTP 202, even after the Instance was deleted. Reusing the key with a changed body refuses with `operation.idempotency_conflict` (409). A missing Instance without that receipt keeps the normal 404.

On success, the container, its volume and snapshots, T3 history, Instance-owned database records, pairings and environment entry are deleted. The Route is withdrawn and deleted. Only owned databases are dropped; an owned database attached elsewhere refuses with its existing code, and shared or foreign databases stay. A migrated Instance's retained Git folder stays on the Node, and the Operation result retains `retained_git_path` and `retained_git_at`. See [Instance removal](/reference/instance-removal) for the engine's full cleanup and retry rules.

## Retry a completion

`POST /instances/{id}/complete` is the Retry of a clone's failed or cancelled completion. It takes no body and requires an `Idempotency-Key`; the caller needs access to the Instance's Node. It admits the same `instance.complete` as [`instance:complete`](/cli/instance/complete) and answers its Operation with HTTP 202. A clone that is not `source_resolved` with its starting commit and a registered T3 server refuses with `instance.complete_unavailable` (409); a clone another Operation holds refuses with `operation.target_busy` (409). The same caller and key return the same Operation.

## Pairing and revocation

A device asks the Gateway for a pairing link to one registered T3 server. The native Swamp pairing step sends `POST <url>/api/auth/pairing-token` with its admin session, a unique label, such as `phone via Orbit k3j9x0qa`, and T3's administrative scopes (see below). T3 gives the session paired from that link the same client label. The Gateway records the pairing: the Node, the server, the T3 link id, the label, and the link's expiry.

The device opens the returned `pairing_url` and pairs as it does today, but without a manual step. The link is one-time and short-lived, so the device pairs right away.

### Renewal

A T3 session lasts 30 days and does not renew. Before it ends, the client asks for a new pairing with `{"renew": true}` and pairs again without the user. A revoke wins over a renewal: when the calling Node's newest pairing on that server has a revoke time, the Gateway refuses with `conn.pairing_revoked` (409) and starts no Operation. A Node with no earlier pairing on that server is renewed, because there is no revoke to honour. Without `renew`, or with `renew: false`, the request is an explicit pairing: it succeeds after a revoke, so the user can bring a server back. `renew` must be a JSON boolean, else `validation.failed` (422).

The Idempotency-Key covers the body. A client that repeats a renewal sends the same key with the same body; the same key with another body answers `operation.idempotency_conflict` (409).

To revoke, Orbit reads the initial `payload.clientSessions` snapshot from upstream T3’s `subscribeAuthAccess` WebSocket RPC at `/ws?orchestrationProtocol=2`, authenticated with the same admin bearer session. This path serves both stock T3 container servers and Conn host servers. It revokes each session whose label belongs to the Node's open pairings on that server with `POST <url>/api/auth/clients/revoke`, and it revokes each pairing link that was not used with `POST <url>/api/auth/pairing-links/revoke`. It never revokes its own current session. The device loses that server at once; its WireGuard access does not change.

## Held sessions

On the T3 server of a container Instance, a device never pairs. The Gateway pairs itself on demand: for a Node with node access to the server, at that Node's first read of its session, or earlier by the optional pre-mint below. It holds the resulting session, renews it, and hands it to the Node when the Node asks. The Node then talks to the server directly; the Gateway is not in that path. Container servers stay stock T3. The [session chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/session-hold.mmd) has the steps.

A held session is a held pairing: a pairing row with `held` set, whose link the Gateway minted and redeemed itself. So it has a `pairing_id`, and revoke works per pairing as for a device pairing.

### Attach

`GET /environments/{environment_id}/session` (MCP `conn-environment-session`) answers the calling Node's held session. The caller is identified as for every Conn call: the active Node that owns its WireGuard address. It needs node access to the server's Node, else `node_access.required` (403).

- With a held session that has not ended, it answers 200 at once, with no Operation and no T3 call. The answer has `Cache-Control: no-store`.
- Without one, it admits one `conn.session_hold` and answers 202 with that Operation, in the shape of the other Conn handles.
- The caller follows it on `GET /operations/{id}`. After it succeeds, the next read answers 200.
- Concurrent first reads of one Node start one Operation: admission takes the server's row lock, and a later read gets the open Operation.
- The read ignores `Idempotency-Key`: a repeated read answers the current state, never a replay of an earlier 202.

```json
{"data": {"environment_id": "env-fix-login", "url": "http://10.44.0.30:3812", "pairing_id": 41, "token_type": "Bearer", "access_token": "<session>", "expires_at": "2026-12-14T12:13:38+00:00"}}
```

The client presents `access_token` as its bearer to `url`: `Authorization: Bearer <access_token>` on HTTP, and the WebSocket ticket it gets with it. A detach costs nothing on the server: the client closes its socket and keeps nothing it must renew. When the server answers 401, the session was revoked or has ended: the client reads the handle again, which answers the current session or starts a new one.

Refusals before any Operation:

| Code | Status | Meaning |
| --- | --- | --- |
| `conn.environment_not_instance` | 409 | A host server. Its devices pair with it themselves. |
| `conn.environment_asleep` | 409 | The Instance sleeps and the Node holds no session yet. Wake it, then read again. A held session answers also while the Instance sleeps. |
| `conn.session_expired` | 409 | The Gateway's admin session on the server has ended; its renewal brings it back. |
| `instance.removal_conflict` | 409 | The Instance is being removed. |

### How the Gateway makes one

The run `operation-conn-session-hold` runs on the Swamp worker:

| Step | What it does |
| --- | --- |
| Mint a pairing link | `POST <url>/api/auth/pairing-token`, as a device pairing does, with the admin session, T3's administrative scopes and a unique label such as `phone via Orbit k3j9x0qa`. |
| Read the link | A typed data method reads T3's reply and stores the one-time link as a native sensitive field. |
| Redeem the link | `POST <url>/oauth/token`, T3's normal pairing endpoint: the OAuth token exchange a device makes, without a DPoP proof. curl reads the link from the vault into a variable, never into argv. |
| Store the session | A typed data method reads T3's access-token reply (`access_token`, `token_type: Bearer`, `expires_in`, `scope`) and stores the session as a native sensitive field. |

The Gateway reads the session key once, stores the session encrypted on the pairing, and deletes the run's vault keys. The result holds only `backend: held`, `pairing_id`, `pairing_link_id` and `session_expires_at`.

When T3 answered, no session exists. The Operation ends with that answer's code, and the pairing is settled with a revoke time: `conn.session_rejected` (the admin session got 401), `conn.request_refused` (another status), `conn.response_invalid` (a link reply without T3's shape) or `conn.redeem_refused` (T3 refused the link at `/oauth/token`). Any other failure is `conn.session_outcome_unknown`: a session may exist, so the Gateway withdraws the pairing and revokes it by its label. The next read starts a new Operation with a new label.

**Pre-mint.** The operator's switch `ORBIT_CONN_HELD_SESSION_PREMINT` (`orbit.conn.held_session_premint`) makes sessions before the first read. It is off by default, because it adds Swamp runs to every container create. The Gateway operator turns it on in `/home/orbit/shared/gateway.env` and [applies the env change](/reference/gateway-recovery#apply-an-env-change); no release is needed. While it is off, the first read makes each session. The handle, renewal and revoke do not depend on the switch.

While it is on, the Gateway pre-mints each time it records a container Instance's T3 server: at the end of `instance:create` of a development container, from Conn's New-instance flow or any other caller, and at the end of `instance:migrate`. It admits a `conn.session_hold` for each Node of the creating Node's profile that has node access to the server, or for the creating Node alone when it has no profile. The creating Node is the Node that started the Instance's first `instance.create`; an Instance that no Node created gets none. A refused admission is logged as `conn.held_premint_deferred`, and that Node's first read makes its session.

### Renewal of a held session

T3 issues the session for 30 days. The hourly renewal tick ([Renewal of an Instance server](#renewal-of-an-instance-server)) then admits a `conn.session_hold` for each held session that ends within seven days, at most 20 a tick, the earliest end first. A session whose last renewal failed goes after the others, so failing sessions never take every slot from healthy ones. It does so also for Instances no device is connected to.

A sleeping Instance is skipped; a successful wake renews its held sessions that end within seven days and admits the server's next owed revoke. A held session that waits on an ended admin session renews as soon as the renewed admin session is recorded. No device renews anything.

The old session serves until the new one is recorded. Then the Gateway withdraws the old one and admits its revoke by `pairing_id`.

### Revoke and removal

Revoke stays per pairing: `POST /environments/{environment_id}/pairings/revoke` with `pairing_id` ends one held session. Without `pairing_id` it keeps its meaning for installed clients: the Node's own open pairings, never a held one. The Node keeps its node access, so its next read makes a new held session. To stop a Node for good, remove its node access.

A revoke by `pairing_id` withdraws a held pairing at its admission: the handle stops serving it at once. An unmatched revoke of that one pairing proves that T3 holds no session with its label only when no hold of the pairing ran at the revoke's admission. Then the Gateway settles the pairing, and the next read makes a new one. If its hold ran, the session may come after the revoke read the server, so the pairing stays withdrawn and the hold's end revokes it again.

A client whose server answers 401 reads the handle again. If the handle answers the same `pairing_id` again, the client revokes that pairing by its id, then reads again.

A held session ends in two steps. The Gateway withdraws it: it stops serving it and clears it at once. Then it owes the revoke on the server: it admits a revoke by `pairing_id`. One revoke holds a server at a time, so when a revoke ends, the next owed revoke on that server starts. While the server cannot take one (asleep, busy, its admin session ended) the hourly tick admits it again. A session past its end owes nothing, and the tick settles it.

- Removing a Node's node access withdraws its held sessions on the servers that the Node now cannot reach. Core calls Conn through the `NodeAccessRemovalHook` plug-in point of `node:access:remove`.
- The hourly tick also withdraws a held session whose Node lost access another way.

Deleting a Node ([`node:remove`](/cli/node/remove)) withdraws its unsettled held sessions just before the Node row goes. Core calls Conn through the `NodeRemovalHook` plug-in point.

- Each of those pairings passes to the Gateway Node, which made it. So the row survives the delete.
- The owed revoke by `pairing_id` then runs as above.
- The first revoke on each server starts at once. One whose hold still runs revokes at that hold's end.
- With no active Gateway Node, nothing can revoke them, so the pairings go with the Node.
- The Gateway then logs `conn.held_handover_skipped`. Each of those sessions stays valid on T3 until it ends, at most 30 days.

Destroying the Instance deletes its held pairings with its other pairings. T3 goes with the container, so nothing is left to revoke.

`GET /environments/{environment_id}/pairings` keeps its default answer: device pairings only. `include_held=1` or `include_held=true` adds the held pairings, and then each row has `held`. The optional `include_held` accepts only `1`, `true`, `0` or `false`, else `validation.failed` (422). A device's `renew: true` pairing looks only at the device's own pairings, so a revoked held pairing never refuses it.

### Security

**Who may read a held session.** Only the Node it is held for, and only while that Node has node access to the server's Node. The handle answers the calling Node's own session; no endpoint answers another Node's, and a Node with Gateway access gets only its own.

**What a stolen answer is worth.** A bearer session with T3's administrative scopes on that one server. That is the same trust as a device pairing, and it gives no access to Orbit.

T3 0.1.7 does not bind the session to a client. It checks only the signature, the end and the revocation. It records the address and user agent of the redeemer only as labels. So anyone who reaches the server over WireGuard can use a stolen copy until one of these comes first:

- it ends, at most 30 days after it was issued;
- the next renewal revokes it, at most 23 days after it was issued;
- a revoke by `pairing_id`, removing the Node's node access, or deleting the Node: it ends once the server takes that revoke;
- a server that is asleep or down takes that revoke at its wake or at the next hourly tick.

Binding would need a DPoP proof key that the client presents when the link is redeemed. The Gateway redeems for the client, so it cannot present the client's key. Stock T3 also issues a bound token for one hour only.

**The admin session never leaves the Gateway and Swamp.** It is stored encrypted on the server record and goes to the run only as a Swamp execution copy. No handle answers it.

**Where the held session lives.** Encrypted in `conn_pairings.session` with the Gateway's application key, hidden from serialization. It never enters an Operation input or result, Activity, a log or run history: the run's result and the Operation hold only vault key names and public metadata.

**Vault keys.** Each lives for one run, seconds:

| Key | Holds | Removed |
| --- | --- | --- |
| `conn-session-hold-<uuid>` | The execution copy of the admin session | The Gateway deletes it when the run ends, on every path |
| `nckrtl-conn-session-<model>-link-link-pairing-<id>-credential` | The one-time link; T3 consumes it at the redeem, and an unused one expires within five minutes | The Gateway deletes it when the run ends, on every path |
| `nckrtl-conn-session-<model>-hold-held-pairing-<id>-access_token` | The session | The Gateway deletes it when the run ends, on every path: after it stores the session encrypted, or, on a failure, by the name it derives from the link key |

A failed delete is logged as `conn.held_vault_cleanup_failed`.

**The revoke race of [issue 3540](https://github.com/hardimpactdev/orbit/issues/3540).**

A revoke by `pairing_id` during the hold that makes that pairing's session reads the server before the session exists. It can end first, unmatched, or after the hold. The Gateway closes the gap from both ends:

- The revoke withdraws the held pairing at its admission and records that its hold still runs.
- Its unmatched answer then settles nothing.
- No owed revoke of that pairing starts during its hold.
- When the hold ends, it finds the pairing withdrawn. It never serves the session, and it admits the revoke by id.
- If the first revoke still holds the server, the end of that revoke starts the next one.

So no session outlives a revoke that named it, in either order. A renewal makes a new pairing, so a revoke of the old pairing ends only the old session; the renewal's own session is a new `pairing_id`.

Two pairings of one device within five minutes are not ambiguous when one is held. The Gateway redeemed the held link itself, and T3 keeps the grant label on that `one-time-token` session. So a held session is matched by its exact label alone, and the MCP rule of source address and issue window does not apply to it. A revoke of a device pairing that overlaps another device pairing still refuses with `conn.revocation_ambiguous`.

### Host servers

Host servers are not covered: their devices pair with them as before. The same run would work there, because it needs only the Gateway's admin session, and each host's job keeps that fresh by registering again. Covering them would take three changes:

- allow `conn.session_hold` for an environment with no Instance;
- renew the held sessions of host servers in the hourly tick;
- decide how a host that was down for longer than the margin comes back, because Orbit cannot wake a host.

## API

Every endpoint is under `https://gateway.orbit/api/v1/conn` and answers `{"data": ..., "meta": {"request_id": ...}}`. A failure answers `{"error": {"code", "message", "details"}}`. The generated [API reference](/api/overview) lists the same operations with their schemas.

| Method and path | Request | Response `data` |
| --- | --- | --- |
| `GET /me` | — | node, with `profile` `null` before a binding |
| `PUT /me/profile` | `{"profile_id": 1}` | node |
| `GET /profiles` | — | list of profile |
| `POST /profiles` | `{"name": "Nick"}` | profile (201) |
| `GET /profiles/{id}/settings` | — | settings |
| `PUT /profiles/{id}/settings` | `{"version": 3, "settings": {"workspaces": [...]}}`; any of `workspaces`, `appearance`, `devices` | settings |
| `PATCH /profiles/{id}/settings` | `{"sections": {"appearance": {"version": 2, "value": {...}}, "devices.iphone": {"version": 0, "value": null}}}`; any of `workspaces`, `appearance`, `attachedThreads`, `devices.<type>` | settings |
| `GET /projects` | — | list of project |
| `GET /projects/{id}/instances/check` | `name` query string | name, available, reason, domain |
| `POST /projects/{id}/instances` | `{"name": "fix-login-k3j9"}` | Operation (202) |
| `GET /instances/{id}/work` | — | workspace work state |
| `POST /instances/{id}/destroy` | `{"acknowledge_unsaved": true}` and `Idempotency-Key` | Operation (202) |
| `POST /instances/{id}/complete` | no body; `Idempotency-Key` | Operation (202) |
| `GET /environments` | — | list of environment |
| `POST /environments` | `{"environment_id", "label", "url", "admin_session"}` | environment |
| `GET /environments/{environment_id}/pairings` | `include_held` query, optional | list of pairing, newest first |
| `GET /environments/{environment_id}/session` | — | held session (200), or Operation (202) |
| `POST /environments/{environment_id}/pairings` | `{"renew": true}`, optional | Operation (202) |
| `POST /environments/{environment_id}/pairings/revoke` | `{"node_id": 4, "pairing_id": 7}`, both optional | Operation (202) |
| `POST /environments/{environment_id}/renew` | — | Operation (202) |
| `GET /threads` | `project`, `instance` query, both optional | list of thread, and `meta.environments` |
| `POST /environments/{environment_id}/threads` | `{"thread_id": "a1b2", "title": "Fix the login"}` | thread (201, or 200 when recorded already) |

The objects:

```json
{
  "node": {"id": 4, "name": "phone", "wireguard_ip": "10.44.0.77", "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"}},
  "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"},
  "settings": {"profile_id": 1, "version": 3, "settings": {"workspaces": []}, "sections": {"workspaces": {"version": 1, "updated_at": "2026-10-08T10:00:00+00:00", "updated_by": "phone"}}, "updated_at": "2026-10-08T10:00:00+00:00"},
  "environment": {"environment_id": "env-beast", "label": "beast", "url": "http://10.44.0.30:3773", "server_version": "0.9.1", "registered_by": "beast", "registered_at": "2026-10-08T09:00:00+00:00", "admin_session_expires_at": "2026-11-07T09:00:00+00:00", "status": "registered", "instance_id": null, "power_state": null, "project_id": null, "project_name": null, "project_slug": null, "instance_name": null, "node_name": null, "instance_url": null, "renewal_error_code": null, "renewal_checked_at": null},
  "project": {"id": 3, "slug": "mealou", "name": "Mealou", "node_id": 7, "node_name": "beast", "instance_domain_suffix": ".mealou.test", "cloneable": true, "cloneable_reason": null, "checks": [{"key": "default_instance", "label": "Default Instance", "status": "pass"}, {"key": "container", "label": "Runs in a container", "status": "pass"}, {"key": "active", "label": "Active", "status": "pass"}, {"key": "seed_snapshot", "label": "Seed snapshot", "status": "pass"}, {"key": "node_access", "label": "Node access", "status": "pass"}, {"key": "source_access", "label": "Source access", "status": "pass"}, {"key": "source_defaults", "label": "Source defaults", "status": "pass"}, {"key": "production_placement", "label": "Development placement", "status": "pass"}, {"key": "node_active_linux", "label": "Active Linux Node", "status": "pass"}, {"key": "app_dev_role", "label": "App-dev role", "status": "pass"}, {"key": "source_profile", "label": "Source metadata", "status": "pass"}, {"key": "php_readiness", "label": "PHP readiness", "status": "pass"}, {"key": "runtime_contributions", "label": "Conn runtime", "status": "pass"}, {"key": "model_route", "label": "Model router", "status": "pass"}, {"key": "native_create", "label": "Create support", "status": "pass"}]},
  "held_session": {"environment_id": "env-fix-login", "url": "http://10.44.0.30:3812", "pairing_id": 41, "token_type": "Bearer", "access_token": "<session>", "expires_at": "2026-12-14T12:13:38+00:00"},
  "pairing": {"id": 7, "environment_id": "env-beast", "node_id": 4, "node_name": "phone", "client_label": "phone via Orbit k3j9x0qa", "issued_at": "2026-10-08T10:00:00+00:00", "expires_at": "2026-10-08T10:05:00+00:00", "revoked_at": null},
  "thread": {"environment_id": "env-fix-login", "instance_id": 12, "project_id": 3, "thread_id": "a1b2", "title": "Fix the login", "state": "waiting", "needs_input": true, "last_user_message_at": "2026-11-14T11:55:00+00:00", "last_run_completed_at": null, "seen_at": "2026-11-14T12:00:00+00:00"},
  "meta.environments": [{"environment_id": "env-fix-login", "instance_id": 12, "power_state": "running", "read_at": "2026-11-14T12:00:00+00:00", "read_error": null}]
}
```

`pairing_url` has T3's form, `<url origin>/pair#token=<token>`.

| Error code | Status | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller is not an active Node. |
| `node_access.required` | 403 | The caller has no node access to the server's Node, or to any Node for the profile and Operation endpoints. |
| `conn.environment_owned` | 409 | Another Node registered this environment id. |
| `instance.name_taken` | 409 | Another device's create holds the name of a thread's clone. |
| `instance.default_missing` | 409 | The Project has no default Instance in a container that is active and has a seed snapshot. |
| `instance.complete_unavailable` | 409 | The clone is not `source_resolved` with its starting commit and a registered T3 server. |
| `validation.failed` | 422 | The request breaks a rule; `details` names each field. This includes an attached thread whose environment is new to the set and is gone or not one the caller may see. |
| `conn.settings_version_conflict` | 409 | The document, or a section a patch names, changed since the sent version. `details.current_version` is the document version; a patch adds `details.sections`, the current version of each section it named. |
| `conn.environment_mismatch` | 422 | `url` serves another environment. |
| `conn.admin_scope_missing` | 422 | The registered session is not an admin session. |
| `conn.session_expired` | 409 | The Gateway's admin session on that server expired. |
| `conn.environment_not_instance` | 409 | A renewal or a held session asked for a host server; its host renews its own session, and its devices pair themselves. |
| `conn.thread_index_unsupported` | 409 | A thread record named a host server; only container servers have an index. |
| `conn.pairing_in_progress` | 409 | A Gateway session cleanup waits for a pairing that runs with the earlier session. |
| `conn.environment_asleep` | 409 | The Instance sleeps and the Node holds no session on its server yet. |
| `conn.pairing_revoked` | 409 | A renewal asked for a server where the Node's newest pairing was revoked. Pair without `renew` to bring it back. |
| `conn.server_unreachable` | 502 | The T3 server did not answer. |
| `conn.session_rejected` | 502 | T3 does not accept the admin session: it answered 401, or `/api/auth/session` reported it unauthenticated. |
| `conn.request_refused` | 502 | The T3 server refused the call with another status; `details` has its status and reason. |
| `conn.response_invalid` | 502 | The T3 server's answer did not have T3's shape. |

Every call is recorded as command activity with the calling Node. A registration records its environment id, label, and URL, never the admin session. A settings write records only section names and versions, because the document can hold workspace pictures, themes, and device values. A replace records the document version it replaced and the names of the sections it sent. A patch records each section name with the version the client sent. A thread record records the thread id, never the title. The thread list is a read and records no activity.

## Why it works this way

The T3 Connect relay could also be self-hosted. It needs four outside services and puts Cloudflare in the traffic path, and it has no place for workspaces. The Gateway already knows every machine and runs on the private network, so it adds no new service.

The Gateway uses T3's own admin sessions and pairing instead of a new T3 auth scope. The host issues the admin session with the stock `t3` CLI, so servers run unmodified upstream T3, and the Gateway needs no SSH access to them. The cost is that the Gateway holds full admin access to every registered T3 server. On a private WireGuard network where the Gateway already manages every machine, that adds little new risk.

A device pairing link carries T3's administrative scopes, including `access:read` and `access:write`, which T3 lets an admin session grant. The servers are headless, so the device is the only place to manage a server's linked environments and to answer an agent's environment-link request. Each paired device therefore gets admin access to the server, the same trust the Gateway already holds. Sessions paired before this change keep their old scopes until the device pairs again.

### Why Conn uses node access

A pairing gives a device admin access to a T3 server, and the server runs agents with the rights of its host. So pairing with a server is acting on its Node, which is what a node access edge allows. Every task VM is an active Node with its own WireGuard identity; without a check, an agent inside one could mint an admin pairing to any server. Task VMs hold no edges, so they are refused, and no second grant system is needed.

The server is keyed on the Node that registered it, not on the host of its URL. A container's server is registered by its host Node, and a URL can name a forwarded port, so the URL does not identify the Node.

A registration cannot move an environment id to another Node. Otherwise any Node could register a server of its own under a known id and send devices that pair with it there. The same holds for a server whose Node was removed, so taking it over needs Gateway access. The ownership check reads the same row that the registration transaction locks and compares, so a concurrent registration by another Node fails with `conn.environment_changed`.

Removing an edge ends the sessions the Gateway holds for the consumer, through the core plug-in point `NodeAccessRemovalHook`: core cannot call an extension, so Conn contributes the hook. It does not revoke sessions a device paired itself: the Gateway does not hold those, and their revoke stays the device owner's choice.

The Node is the identity because the Gateway already knows every WireGuard peer as a Node, phones included, and WireGuard binds each address to one key. Revocation happens only in T3 and leaves WireGuard access alone, so a revoked device keeps its other servers and Orbit access.

A Node belongs to one profile because each device is its own Node: a profile is how a phone and a desktop share one copy of the workspaces. Settings use a version check rather than last-write-wins, so an edit on one device is never lost silently to an older copy on another.

### Why sections have their own versions

The devices of a profile edit different things at different times. With one version for the whole document, a font change on the phone would conflict with a theme change on the Mac. Every client would then merge the entire document to resolve it. With a version per section, only two edits of the same section conflict.

Each device type has its own section for the same reason: a phone and a desktop never conflict over values that belong to one of them. A patch is all or nothing, so a client that changes the mode and a theme in one step never leaves them half applied.

The Gateway stores the versions apart from the document, in `section_meta` on the profile. A profile from before sections has no entry, so every section of it is at version 0, and the first patch of a section sends version 0.

`PUT` stays for clients that only know workspaces. It replaces just the sections it sends, so such a client cannot erase the appearance or device settings that a newer client wrote.

### Why the attached set is a profile section

The devices on a profile should connect to the same servers, so the set lives in the profile, beside the settings every device already reads. As a section it gets its own version, so attaching a thread never conflicts with a theme change, and it needs no new route and no client clock. A set merges three ways without loss, so the per-section version and a client retry are enough. The Gateway still checks the entries, so a device cannot attach a server it may not reach, and a removed Instance leaves no entry behind.

## Session cleanup

Pairing revocation captures its exact pairing IDs, client labels, link IDs, issue windows, Node source IP and T3 URL at admission. The optional API and MCP `pairing_id` targets one pairing, including a revoked pairing; it must belong to the selected Node and environment. Without it, only open pairings are selected. Overlapping issue windows for that Node and environment refuse with `conn.revocation_ambiguous` before any revoke. Native fan-out uses fixed behavior names, and the Gateway admits one revocation per T3 environment. All native steps run on Node 209.

The Gateway sends the existing admin session through the shared execution-copy helper; workflow input holds only the vault and key names. Swamp reads the T3 session list once. Conn sessions retain the exact pairing label on the `one-time-token` subject. CLI-linked sessions use `mcp-client`: Orbit selects every non-current session with that subject, the exact Node WireGuard source IP, and an `issuedAt` inside the pairing’s issue-start to expiry window. 

Stock T3 0.1.7 stores no session-to-pairing key, so this bounded rule cannot prove token provenance. Orbit revokes selected sessions and pairing links in separate native steps and preserves the current admin. An empty pairing selection, no matching sessions, or no session actually revoked fails with `conn.revocation_unmatched` and keeps the pairing rows unchanged. A cleared credential proves delivery, not redemption; this check is conservative. A failed read is a failure, never an empty inventory. The native terminal check and captured public reply metadata project only the admitted pairings. Valid link completions are recorded even when a later link fails.

Steps make no Gateway calls.

### Gateway session cleanup

Gateway session cleanup runs the shared native revocation workflow on Node 209 for the fixed Gateway client label. It captures the T3 URL, registration revision and vault reference at admission, with no step-to-Gateway callback. The child lists sessions once and keeps the current admin and every other label. Failed HTTP or reply checks report `conn.revocation_failed`; the revision stays pending.

Registration, and the [renewal of an Instance server](#renewal-of-an-instance-server), store the new encrypted admin session and increment its cleanup revision. After that transaction commits, the Gateway admits `conn.gateway_sessions_revoke` once. It refuses with `conn.pairing_in_progress` while a `conn.pairing_create` or a `conn.session_hold` of that server runs, because that run carries the earlier session; the revision stays pending. For an Instance server, the hourly renewal tick admits a pending cleanup again. Registration keeps its normal response if admission or cleanup fails. A failed start records a safe warning, and the revision stays pending. No scheduled publisher scans host registrations. Cleanup keeps the current admin session and other client labels.

Registration refuses with `operation.target_busy` while a cleanup or pairing revocation holds that environment. Session validation runs outside the database transaction; a concurrent revocation or registration refuses with `conn.environment_changed` before storing the candidate session. The list and registration replies expose the non-secret `cleanup_version`. The environment lock prevents overlapping revocations. For a host server, a failed revision stays pending without another automatic Operation; a new registration or an explicit cleanup request permits a new attempt. A completed revision returns HTTP 204 without starting a run. Cleanup never discards the current admin session.

## Pairing Operations

Pairing creation returns HTTP 202 with an Operation. The Gateway captures the environment URL, pairing identity, client label and complete request body with its administrative scopes, and copies its existing admin session through the shared execution-copy helper. One bounded native curl step on Node 209 calls T3's pairing-token API and writes the raw response in a private directory. A typed data method reads that response. The typed resource marks the issued credential sensitive, so Swamp core stores it in its vault and records only a vault reference.

The typed method reads T3's reply as T3's `AuthPairingCredentialResult`: `id`, `credential`, `expiresAt` and the echoed `label`. It ignores fields a newer T3 adds and stores only the link id, expiry and credential. A missing label, or another label than the admitted one, is invalid.

Each pairing has its own resource instance and native vault key; a later issue cannot overwrite an unread credential. Declared cleanup removes the private response after success or failure, and a native assert checks completion. No step reads intent, fetches private input or posts an output to the Gateway.

After the shared terminal reader proves success, projection reads the pairing's native vault key through the existing authenticated Swamp connection, stores the credential encrypted for its owner, and deletes that exact key with the native `vault.delete` frame. Only public link metadata enters the Operation result. A refused vault read leaves the credential available for shared delivery without issuing another token. A terminal replay neither reads nor deletes the key again. A lost issue response remains uncertain; the unused T3 token expires, and another command admits a new pairing label.

After the Operation succeeds, the calling Node reads `GET /api/v1/conn/environments/{environment}/pairings/{pairing}` to receive its pairing URL once. The owner-checked read clears the encrypted credential. A second read, an expired pairing or a read before completion returns HTTP 409. Another Node cannot read it. Listing pairings never includes it; revocation removes it. Repeating the same admission idempotency key returns the same Operation without consuming the credential.

When T3 answered, the Operation ends with that answer's code. The code is final for this pairing:

| Error code | T3's answer |
| --- | --- |
| `conn.session_rejected` | 401: register the T3 server again. |
| `conn.request_refused` | Another status that is not 2xx, such as 503 while T3 restarts. |
| `conn.response_invalid` | A 2xx reply without T3's shape, or with another label. T3 may hold an unused link; it expires within minutes. |

Callers do not retry these codes on their own; a person or the operator decides when to ask again. Only `conn.pairing_outcome_unknown` means Orbit read no answer, or could not store the credential, so the outcome is uncertain.

A lost T3 response leaves issuance uncertain. There is no automatic retry, fallback token or issuance ledger. Inspect the native report and establish the external outcome before explicitly retrying a failed issue step or admitting another pairing.

Conn callers follow their own pairing Operations at `GET /api/v1/conn/operations/{operation}` and cancel them at `POST /api/v1/conn/operations/{operation}/cancel`. These endpoints check the original requester, including when a revocation names another Node. They expose no other Operation or Gateway authority.

A failed session-list read ends the revocation Operation with its safe `conn.*` error code before any revoke call. Native pairing revocation reports `conn.revocation_failed`; its native report identifies the failed action. The retained legacy kinds use `conn.response_invalid` for invalid JSON. No failed read proves an empty session list or successful cleanup. The registration revision stays pending until a new registration or an explicit operator cleanup retry.

## Direct Swamp writer

`conn.pairing_create` starts `operation-conn-pairing-create` with full native inputs and a fixed terminal selector. `conn.session_hold` starts `operation-conn-session-hold` the same way. Pairing revocation uses `operation-conn-pairing-revoke`. Gateway session cleanup retains `operation-conn-direct` and its current-registration checks; that is a separate kind. Existing profile and registration reads remain local Gateway behavior.

Core sensitive resources provide protected generated outputs without a new custody protocol. Official core commands and vaults, plus the official extension catalogue, were checked first. There is no official T3 pairing API integration. The single typed HTTP action is needed to validate the provider response and mark the generated credential sensitive before native persistence. There is no custom transport or subscriber.

When a terminal result repeats, Conn returns the recorded result even after the pairing credential has been consumed or revoked. Conn projects bounded public metadata without a Kit execution contract. Native dispatch failures record a safe failure without changing pairing or cleanup state.


### Native pairing revocation

Swamp core command steps and native `forEach` supply the execution. Official SSH, cfgmgmt, datastore and cloud-vault extensions were checked at `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`; they do not implement T3 session APIs.

Session inventory on 209 uses a bounded WebSocket reader (`timeout 15 deno run`) that subscribes to `subscribeAuthAccess` and closes after the initial snapshot. Each revoke step makes one `curl` request to the existing T3 API.

curl reads the admin session from the step environment with `--variable` and `--expand-header`, so it never enters argv. curl writes each raw reply into a private worker directory. The `@nckrtl/conn-revocation-plan` data methods read the session list and revoke replies there; they publish only selected request bodies, valid completion indices and counts. The extension ships one bounded reader for T3’s auth snapshot. There is no custom transport, authentication protocol, Gateway relay or runtime restart. Since this is an API integration, no worker or SSH step runs on the subject Node.

Session revocations finish before unused-link revocations begin. A failed list or invalid session reply blocks link effects. The initial list preserves the current admin session. Duplicate runtime ownership checks are removed under rule 22. A valid `revoked: false` reply means the session or link is already absent. The native end check requires valid replies for all actions; declared cleanup removes the private directory after failure too.

After terminal cleanup, repeat the command to reconcile incomplete revocations. Without `pairing_id`, the new Operation captures only open pairings; with it, the Operation selects that exact pairing even if already revoked. It reads the current session list; already-absent sessions are not selected. Cleanup removes private replies even after failure, so resuming directly at an HTTP step cannot reuse its deleted scratch directory. A terminal Gateway Operation stays terminal after a manual native resume. Revocation requests are idempotent and never mint credentials.

The public terminal result contains only `backend` and `revoked_sessions`, or a bounded `conn.*` error code. The admin token, server response bodies and other client data never enter stdout or workflow resources.
