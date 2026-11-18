---
title: "Conn layer"
description: "How the Gateway signs in Conn devices as Nodes and shares their settings through profiles. It holds an admin session for each registered T3 server, mints and revokes device pairings, and holds each Node's session on container T3 servers."
covers:
  - apps/gateway/extensions/conn/**
  - swamp/extensions/behaviors/operations/conn-*/**
  - swamp/workflows/workflow-operation-conn-*.yaml
  - swamp/ansible/conn-*.yml
  - swamp/{ansible/t3_copy_rpc.mjs,tests/t3_copy_rpc_test.ts}
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/*_conn_*.php
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
| Durable launch admission and replay, `POST /launches` | An active peer, matching `expected_node_id` and `expected_profile_id` checked against the authenticated Node and its current binding in the admission transaction, and current target Node access. The collection access rule also applies. |
| Durable launch reads and controls | An active peer, a current binding to the recorded profile, and access to the recorded target Node. The collection access rule also applies. |
| `POST /environments`, registration | No edge. |

The Gateway Node, its serving host, and a Node with an edge to the Gateway Node have access to every Node, so they reach every server. When a server's Node was removed, only those callers reach it. A refused call gets `node_access.required` (403), with `details.consumer_node` and, for a server, `details.serving_node`.

For example, a phone with an edge to `beast` sees and pairs with the servers that `beast` registered, and no others. A [task VM](/reference/compute-drivers#placement-invariant) Node never holds an edge, so it can use no Conn endpoint except registration.

### Registration stays with its Node

The calling Node becomes the server's Node. Once a Node registered an environment id, another Node cannot register it again: it gets `conn.environment_owned` (409). When the Node is removed, the server has no Node. Then only a Node with Gateway access can register it again, which makes that Node the server's Node.

### Removing an edge

Removing an edge ends the sessions the Gateway holds for the consumer on the servers that the consumer now cannot reach. The Gateway stops serving them at once and admits their revoke on each server ([Held sessions](#held-sessions)). It does not revoke sessions that the consumer's device paired itself. Revoke those with `POST /environments/{environment_id}/pairings/revoke` and the consumer's `node_id`.

On a managed target, the same removal also revokes the edge's managed tokens and makes the target fence the grant: see [Managed grant revocation](#managed-grant-revocation).

## Profiles

A profile is a named set of shared settings, such as "Nick". A Node belongs to at most one profile. On first connect the device lists the profiles, lets the user pick or create one, and binds to it. Later calls read the binding from `GET /api/v1/conn/me`. Every Node that may use profiles ([Access](#access)) sees every profile.

A profile holds one settings document. Its keys follow Conn's stores, so they stay camelCase. The document has five kinds of section:

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
  },
  "sidebarHiddenThreads": {
    "schema": 1,
    "threads": [{"environmentId": "env-beast", "threadId": "thread-41c0"}]
  }
}
```

A section is `workspaces`, `appearance`, `attachedThreads`, `sidebarHiddenThreads`, or `devices.<type>`. The document accepts no other top-level key. Only `workspaces` is always present; a section that no device wrote is absent.

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
| `attachedThreads.threads` | Required list of at most 500; it may be empty. Each thread appears once. An object, even `{}`, is refused. |
| `attachedThreads.threads[].environmentId` | Required: the `environment_id` of a Conn environment, at most 255 characters, no spaces or colons. See [Attached threads](#attached-threads) for which environments a write may name. |
| `attachedThreads.threads[].threadId` | Required: the T3 thread id, at most 255 characters, no spaces. |
| `sidebarHiddenThreads` | Object of at most 64 KB, serialized, with the same keys and rules as `attachedThreads`. |
| `sidebarHiddenThreads.schema` | Required integer, 1 to 1000. |
| `sidebarHiddenThreads.threads` | Required list of at most 500; it may be empty. Each thread appears once. An object, even `{}`, is refused. |
| `sidebarHiddenThreads.threads[].environmentId` | Required: a Conn `environment_id`, 1 to 255 bytes, no ASCII whitespace, such as a space or a tab, and no colon. The Gateway checks only its form ([Sidebar hidden threads](#sidebar-hidden-threads)). |
| `sidebarHiddenThreads.threads[].threadId` | Required: the T3 thread id, 1 to 255 bytes, no ASCII whitespace, such as a space or a tab. |

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

- **At removal.** The terminal projection of an Instance's destroy deletes its environment. It also removes that environment's threads from the profiles that hold one. It reads no profile whose document does not name the environment.
- **Never refused.** The drop only shrinks a document, so no document limit refuses it. A document that is already over a limit cannot stop a destroy.
- **Versions.** This drop adds 1 to the section version and to the document version, with `updated_by` `null`.
- **Merge.** A device sees the new `settings_version` in `GET /me`. It reads the section and merges the drop as a remote change.
- **No write-back.** A device that writes from its older version gets a conflict. It cannot write the threads back.
- **On read.** A read leaves out the threads of an environment that was deleted. It writes nothing and adds no version. The next write of the section drops them from the store.

A failure of the drop never stops the destroy:

- **After the destroy.** The drop runs after the destroy commits. It cannot fail, roll back or delay the destroy.
- **One profile at a time.** The drop writes each profile in its own transaction.
- **Skipped profile.** The drop skips a profile it cannot read or write. This includes a document that is not valid JSON but names the environment. The other profiles still get their drop.
- **Deleted profile.** A profile deleted while the drop runs is skipped without a report.
- **Problem.** The Gateway logs `conn.attached_threads_drop_failed` with the profile and the environments. Its log collector records that error as a Problem. A log channel that fails is ignored.
- **Hidden entries.** The skipped entries stay stored, and every read leaves them out.

A device learns of a change from `profile.settings_version` in `GET /me`, which moves with every write. The Gateway sends no event: how quickly another device follows is the interval at which it reads `GET /me`.

A profile from before this section has no `attachedThreads` key and no version for it. It reads as no attached threads, at section version 0, so the first patch sends version 0. No migration runs. A client that does not know the section keeps working: it ignores the key, `PUT` cannot send it and keeps it as it is, and a patch changes only the sections it names.

### Sidebar hidden threads

`sidebarHiddenThreads` is the profile's list of threads that the sidebar hides. It is a display choice that every device on the profile shares. A device that reads a thread on this list leaves it out of its sidebar. Showing the thread again removes it from the list.

An entry names one thread on one server, as `environmentId` and `threadId`, as in `attachedThreads`. The pair is stable: it names the same thread on every device and after a restart, so a device matches it without any local id.

The list changes only what a sidebar shows:

- **No detach.** Hiding a thread does not detach it. Showing it does not attach it.
- **Apart from attachment.** A write of this section leaves `attachedThreads` as it is. A write of `attachedThreads` leaves this section as it is.
- **Destroy.** Orbit's drop of a removed Instance's threads changes only `attachedThreads`.
- **No connection.** Hiding or showing a thread never changes which servers a device connects to. That set comes only from `attachedThreads` and `GET /environments` ([Attached threads](#attached-threads)).
- **Container servers.** A hidden mark cannot keep a container server connected. Detaching its last attached thread takes the server out of the set.
- **Host servers.** A device keeps a host server connected even with no attached thread, whether its threads are hidden or not.

The list also opens no access:

- **No registration.** An entry never registers, enables, or pairs an environment.
- **No access.** An entry grants no access. It may name a thread that is not attached, or a server the writing device cannot see.
- **Form only.** The Gateway checks only the form of an entry and the limits in the table above.
- **No lookup.** It does not look the environment up or check node access. So a write never fails on another device's access.
- **Same boundary.** Who may read and write the section is the same as for every profile section ([Access](#access)).
- **Gone servers.** The Gateway keeps the entries of a deleted environment, at removal and on read.
- **Stale entry.** Such an entry hides nothing, because no device lists that thread. A client may remove it at its next write.

Write the section with `PATCH`, as the whole list, with the section version the client read. The envelope is the one `attachedThreads` uses:

```json
{"sections": {"sidebarHiddenThreads": {"version": 2, "value": {"schema": 1, "threads": [
  {"environmentId": "env-beast", "threadId": "thread-41c0"}
]}}}}
```

To hide a thread, send the list with it added. To show it, send the list without it. To show every thread, send `{"schema": 1, "threads": []}` or `null`. A bare `[]` lacks both required keys and is refused with `validation.failed` (422). Unlike other sections, `null` does not remove this section: the Gateway stores `{"schema": 1, "threads": []}`. It stores the same value for an empty list sent with another schema. Each of these writes adds 1 to the section version and to the document version.

Two devices that change the list at the same time are handled by the section version ([Versions](#versions)). The second write gets `conn.settings_version_conflict` (409). That client reads the section again and merges three ways, as for attached threads: the remote list, plus the threads it hid since its base, minus the threads it showed since its base. Then it retries with the new version. A patch is all or nothing. A patch that also holds `attachedThreads` is refused as a whole when one of its attached entries is refused.

A read shows the section by its version:

- **Version 0.** A profile whose section was never written has no `sidebarHiddenThreads` key and no entry for it in `sections`. It reads as no hidden threads, so the first patch sends version 0. No migration runs.
- **Version 1 or more.** Once the section has been written, `GET /profiles/{id}/settings` and the `PATCH` reply always hold its stored value in `settings.sidebarHiddenThreads`. Its version is in `sections`.
- **Empty list.** After a write of `null` or of an empty list, with any schema, the value is `{"schema": 1, "threads": []}`.
- **Confirm a show.** So a client confirms a write that shows the last hidden thread from the value, not only from the version.
- **Other sections.** They keep their own rules: a section that was removed with `null` is absent.

Every write of the section moves the document version, which `profile.settings_version` in `GET /me` shows. So a device that follows the document version, by any means, sees a change to this list as it sees any other section.

A `PUT` cannot send this section and keeps it as it is ([Versions](#versions)). So a client that does not know the section, and writes only `workspaces`, never erases the threads another device hid. If its document version is behind a hide, the `PUT` gets a conflict; the client reads again and sends its workspaces without the section.

### Versions

The document has a version, and so has each section. A new profile starts at document version 1 with no workspaces. Every write adds 1 to the document version. A write also adds 1 to the version of each section it changed. A section with no recorded version is at version 0. This includes a section written before sections had versions.

`GET /profiles/{id}/settings` returns `sections`: for each section that was written, its `version`, the time of the last write in `updated_at`, and the name of the Node that wrote it in `updated_by`. It is `null` when Orbit wrote the section, or when the Node was removed since. A section that is not listed is at version 0.

There are two ways to write. Prefer `PATCH`.

`PATCH /profiles/{id}/settings` changes the sections it holds, each with the version the client last read. It never touches a section it does not name. So a phone font change does not conflict with a Mac theme change.

`PUT /profiles/{id}/settings` replaces the sections it holds and keeps the others. It checks the document version, as it always did.

A `PUT` accepts only `workspaces`, `appearance`, and `devices`. A body with any other section, such as `attachedThreads` or `sidebarHiddenThreads`, is refused with `validation.failed` (422) and writes nothing. So a client that sends back the whole document as it read it gets a refusal. A client must not send `attachedThreads` or `sidebarHiddenThreads` in a `PUT`; it changes them with `PATCH`. A `PUT` keeps `sidebarHiddenThreads`, which it cannot send, at its value and version. Every installed Conn client sends only `workspaces` here, and patches the other sections. `devices` replaces every device type, and the types it leaves out are removed. It must hold at least one type. To remove the last type, patch it to `null`.

A patch whose section is at another version is refused with `conn.settings_version_conflict` (409), and the Gateway writes nothing. `details.current_version` is the document version. `details.sections` holds the current version of each section the request named. The client reads again, merges the sections that changed, and retries.

A `value` of `null` removes any section but `workspaces` and `sidebarHiddenThreads`. Its version still goes up, so an old client cannot write it back. `workspaces` cannot be `null`: send `[]`. A `null` for `sidebarHiddenThreads` stores an empty list ([Sidebar hidden threads](#sidebar-hidden-threads)). Only an explicit `null` removes a section. An empty or blank string, including one of Unicode spaces such as a no-break space, is refused with `validation.failed` (422), and the error names the field, such as `sections.appearance.value`.

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

The Gateway's label stays `<project> / <instance>`. The T3 server itself shows the [machine name](/reference/development-containers#the-machine-name) `<instance>.<project slug>`. A client keeps the label it stored for a saved server when it paired.

- The record names the Instance and its Node, with the URL `http://<Node WireGuard address>:<T3 port>` and the label `<project> / <instance>`.
- The Gateway reads the session from the vault key the result names. It does not call T3 again: the run made the checks above in the container.
- A new record starts with its cleanup done. Recording a server again bumps the cleanup revision and starts the cleanup.
- A resumed create can leave the sessions of its failed run valid in T3, until a later renewal cleans them.
- Orbit renews the Gateway's session on the server: see [Renewal of an Instance server](#renewal-of-an-instance-server).
- The bumped revision also stops a Node's registration of the same id that read the row before the record: that registration compares the revision again in its lock and fails with `conn.environment_changed`.
- A create admitted before Conn went off still records its server.

Destroy keeps this contribution available while Conn is disabled. Admission captures its environment. The run deletes T3 with the container, retiring all sessions and unused links. Terminal projection deletes its execution copies, environment and pairings before core deletes the Instance. After that commits, Orbit drops the environment's [attached threads](#attached-threads) from the profiles. New pairings refuse an Instance marked removing.

The contribution records Instance servers from create and migrate results even while Conn is off. The container run starts T3 and creates its admin session. Completion verifies the record. Session renewal and held-session pre-minting wait until Conn is enabled. Enabling Conn later picks up the recorded servers.

### Renewal of an Instance server

T3 issues the Gateway's session for 30 days. Orbit keeps it alive; no device and no host job takes part. Orbit does not ask T3 for a longer session: a longer one would keep a stolen session valid for longer. The [renewal chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/conn/environment-renew.mmd) has the steps.

#### When

Every hour the Gateway admits `conn.environment_renew` for each active Instance server whose session ends within seven days. It also admits one for a session that has ended. So a container that was stopped past the end gets a new session within an hour of its start. When a try found it stopped, the wait is up to six hours; the route below renews it at once.

The hourly renewal skips a [sleeping](/reference/development-containers#sleep-and-wake) Instance. A successful `instance.wake` admits the renewal at once instead, through `InstanceLifecycleContribution::projectWake()`, when the session ends within seven days or ended during the sleep. A wake's renewal does not wait for the one-per-Node limit below: the woken server gets its session at once. A refused admission (a renewal already runs, Conn is off) is logged and never fails the wake. The wake also renews the server's [held sessions](#held-sessions) that end within seven days. Devices that paired themselves renew their pairings through the Gateway as below, with no new pairing by the user.

#### The idle sleep's read

The [idle sleep](/reference/development-containers#sleep-and-wake) uses the same session to read a running clone's threads: one `GET /api/orchestration/shell` with the header `x-t3-orchestration-protocol: 2`. Conn contributes this read as `InstanceAgentActivity`. A refused or failed read, or Conn turned off, keeps the Instance awake. The read writes nothing in the container, and a sleeping Instance is not read.

One tick admits at most five renewals, the earliest end first. It admits at most one per Node at a time. A server whose last renewal failed comes after the others, so it never holds its Node's slot from them. Clones made on one day reach the margin in the same hour.

#### How

The run reaches the Node over its SSH account. In that Instance's own container, as `agent`, it issues a new `Orbit Gateway` session with `auth session issue` of the container's [recorded server](/reference/development-containers#container-server), `t3` or `conn-server`, whose store holds the sessions. It needs no old session, so an ended session renews too.

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

### Restart for the machine name

A container T3 server reads its label, the [machine name](/reference/development-containers#the-machine-name), only when it starts. [`conn:environment-restart`](/cli/conn/environment-restart) and [`conn:node-environments-restart`](/cli/conn/node-environments-restart) restart a running server whose label is not the name yet. [Existing Instances](/reference/development-containers#existing-instances) says when to run them. The Gateway's own label of the server, `<project> / <instance>`, does not change.

For each container Instance, the Gateway first answers what needs no thread read:

1. A sleeping container (`power_state` `stopped`) is `skipped`. Its reason comes from `ContainerResume::frozenBySleep()`, the wake's rule: `frozen` after a sleep that froze it, else `stopped`.
2. An Instance with no T3 port or no server row is `skipped` with `no_t3`.
3. The Gateway reads the labels of all candidates at once from `GET /.well-known/t3/environment`, with no session, in at most 5 seconds. No answer is `skipped` with `t3_unreadable`.
4. A label that equals `<instance>.<project slug>` is `current`.

Then it takes each candidate left, by Instance ID:

1. Past 120 seconds, or past four restarts in one call, the rest are `skipped` with `deadline` or `next_run`.
2. Another open Conn Operation on the server, such as a renewal, a pairing or a held session, is `busy` with `conn_operation`.
3. The Gateway reads the threads from `GET /api/orchestration/shell` with its admin session. An unreadable answer is `skipped` with `t3_unreadable`.
4. `ConnAgentActivity::restartBlocked()` reads the threads. A run that works by the idle sleep's rule makes them busy. So does any pending runtime request, blocking or not. A busy server is `busy` with `t3_working`.
5. Otherwise the Gateway admits `conn.environment_restart` at once, on the Instance's lock.
6. A refused admission is `busy` with its error code, such as `operation.target_busy`. Any other failure answers that Instance `failed`, and the walk goes on.

The intent holds the Instance, its container, T3's port in the container, the name and the time of the read. It holds no session.

The Node form walks the Node's container Instances by ID and reads each one right before its own admission.

The run checks that `/etc/machine-info` holds the name (else `skipped` with `name_not_written`), and reads the members of T3's service once more. A terminal, a command or an unreadable read answers `busy` (`processes`, `processes_unreadable`), and nothing restarts; [Existing Instances](/reference/development-containers#existing-instances) has the rule.

Otherwise the run restarts T3 in the container and reads its label inside the container until it is the name. It succeeds as `restarted` with `window_ms`, or fails as `failed` with `conn.t3_unready` or `conn.t3_label_mismatch`. An open T3 terminal and what it runs are not ended, unless the terminal opens in the moment (about a second) between that read and the restart.

The Operation does not retry. T3's environment ID, threads, sessions and pairings stay, so devices keep their pairings. A connected client reconnects, and an unsent draft stays in its browser. A turn that started after the read is cut, and T3 continues it after its start.

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

## Tasks panel

Conn shows the tasks of a Project or of one workspace, and opens one task with its brief, its subtasks in order, its agents and its metrics. From an agent, a device opens that agent's thread on the workspace's T3 server. The panel only reads: it controls no task, adds no realtime event, and shows no view across every Project beyond the one `task:list` already shows. Both routes need the `tasks` extension as well as `conn`, and node access to some Node, like the [task list](/reference/tasks#list-tasks). Neither records command activity.

### The task list

`GET /tasks` lists top-level tasks, newest first, with the scope and paging of [`task:list`](/reference/tasks#list-tasks). It reads the same action.

| Query | Meaning |
| --- | --- |
| `project` | Only this Project's tasks. |
| `instance` | Only the task whose workspace is this Instance. |
| `status` | Only tasks with this raw task status, such as `running` or `waiting_for_review`. |
| `limit` | 1 to 100 rows; 20 by default. |
| `cursor` | Start below this task id: `meta.next_cursor` of the page before. |

`meta.next_cursor` is null on the last page. Each row:

| Field | Meaning |
| --- | --- |
| `id`, `title` | The task. |
| `status` | The raw task status. |
| `attention` | What the task asks, or null. See below. |
| `project` | `{id, slug, name, code}`. |
| `workspace` | The task copy, or null when the task has none or its Instance row is gone: `{instance_id, name, status, power_state, node: {id, name}, branch, conn_environment_id}`. `status` is the Instance status, `power_state` the container's power state or null, `branch` the Instance's branch. |
| `workspace.conn_environment_id` | The T3 server of the workspace, only when the caller reaches it by the rule of the [thread index](#a-row): node access to the server's Node. Otherwise null. |
| `pull_request` | `{number, url, state}`, or null. The watched pull request first; else the published `pr_url`, with its number read from the URL and `state` null. |
| `subtask_counts` | `{total, todo, running, completed, failed, cancelled}`. `reserved` and `reviewing` count as `running`. |
| `created_at`, `updated_at` | The task row's times. |
| `last_activity_at` | When the task last did something: [Last activity](/reference/tasks#last-activity). |

`attention` is `{kind, question, reason}`. Its `kind` is one of these, in this order:

- `question`: a current agent thread of the task holds questions. A current thread is a subtask's implementer or the task's reviewer. `question` is the first question's text, and `reason` is null.
- The task's assistance kind: `direction`, `failure`, `removal_failure` or `observation`. `question` and `reason` are the task's own.

`attention` is null when neither applies.

### One task

`GET /tasks/{id}` returns a top-level task: its list row and these fields. A subtask id, or an id that does not exist, answers 404 `http.404`.

| Field | Meaning |
| --- | --- |
| `brief` | The task's brief. |
| `execution` | `{implementer_mode: "per_subtask"}`: each subtask has its own implementer thread. |
| `metrics` | `{duration_ms, tokens, lines_added, lines_deleted}` of the task. |
| `subtasks` | In position order: `{id, position, title, brief, deliverables, status, attention, current_implementer_agent_id, metrics}`. |
| `agents` | Every started agent thread of the task, oldest first. |

A subtask's `deliverables` are `{id, type, description}`. Its `status` is the raw subtask status. Its `attention` has the same rule as the task's, for the subtask's own implementer thread and its own call for help. `current_implementer_agent_id` is the id of its current implementer in `agents`, or null. Its `metrics` are `{duration_ms, tokens, lines_added, lines_deleted}`.

Each agent:

| Field | Meaning |
| --- | --- |
| `id` | Orbit's id of the agent thread. It is never a T3 thread id. |
| `role` | `implementer` or `reviewer`. |
| `subtask_ids` | The subtask it worked on, `[id]`, or `[]` when it has none. |
| `current` | True when it is its subtask's implementer or the task's reviewer now. |
| `attempt` | 1 for the first started thread of its role on its subtask, 2 for the one that replaced it, and so on. |
| `provider` | `claude` or `codex`: from the T3 provider instance it started on, else from its model's name (`claude-`, `gpt-`). Null when neither says. |
| `model`, `effort` | As the thread started. |
| `state` | `idle`, `working`, `asking_for_input`, `done`, `failed`, or null before its first observation. |
| `needs_input` | True when it holds questions and its state is `asking_for_input`. |
| `created_at`, `observed_at` | When it was created and last observed. |
| `conn` | `{environment_id, thread_id}` to open the thread in Conn, or null. |

`conn.environment_id` is the workspace's `conn_environment_id` and `conn.thread_id` is T3's thread id, the agent thread's external id, such as `orbit:7c1e…`. `conn` is null when the task has no workspace, the workspace has no T3 server, or the caller does not reach it. A thread that Orbit reserved but has not started, whose external id starts with `pending:`, is not listed.

### Metrics

A null metric is unknown, never zero.

| Metric | Meaning |
| --- | --- |
| `duration_ms` | Wall-clock time from the first start to the end, across every attempt. It grows while the work runs, and is null before it starts. |
| `tokens` | For the task: every started thread counted once, reviewers and earlier attempts included. For a subtask: the usage its turns added. Null while unreported. |
| `lines_added`, `lines_deleted` | For the task: one snapshot of the whole task branch against its base, not a sum. For a subtask: always null. |

A subtask starts at its first turn in the task copy and ends when it is completed, failed or cancelled; a reopen runs its clock again. Orbit records no line change per subtask, so Conn must not derive one from the task's lines.

[Settle metrics](/reference/tasks#settle-metrics) owns how Orbit records them.

### Realtime

The panel adds no event. It follows the [task events](/reference/events#tasks) on `private-orbit`: `task_group.created`, `task_group.updated`, `agent_thread.updated`, and `tasks.updated` when the extension is switched. An event is a notice: on one, Conn reads the list or the open task again. On load and on every reconnect, Conn reads both again, and that read is the truth. A change only to tokens, line counts or duration sends no event; the next read shows it.

### Errors

The panel's routes answer these errors in the [API](#api) error shape.

| Code | Status | Meaning |
| --- | --- | --- |
| `node_access.required` | 403 | The caller has node access to no Node. |
| `extension.disabled` | 409 | The `conn` or `tasks` extension is off. |
| `http.404` | 404 | `GET /tasks/{id}` named a subtask or no task. |
| `validation.failed` | 422 | A query value breaks a rule, such as `limit` above 100. |

## Threads in an Orbit Project

Conn starts a thread in an Orbit Project in a new clone of the Project's default Instance ([Threads](/reference/development-containers#threads)). The [thread chart](https://github.com/hardimpactdev/orbit/blob/main/docs/cli/instance/container/thread.mmd) has the steps.

`GET /projects` (`conn-project-list`) defaults to the compatible answer for installed Conn clients: Projects with a reachable, active container default and a seed snapshot. Installed clients treat every returned Project as startable, so unavailable Projects require an explicit opt-in.

`include_unavailable=1` or `include_unavailable=true` returns all reachable Projects, using the same scope as `project:list`. A caller with Gateway access can see all Projects. Other callers can see each Project with any Instance on a Node they reach. Both forms return rows in ascending slug order; slugs which compare equal have no defined relative order.

The optional `include_unavailable` query accepts only `1`, `true`, `0` or `false`; absence means false. Any other value for `include_unavailable` fails with `validation.failed` (422). Unknown query fields are ignored. False preserves the pre-directory membership rule, which checks readiness and access rather than the later technical checks. A returned Project can therefore have `cloneable: false` for a technical refusal that also applied before the directory changed.

Each entry keeps its Project fields and adds `instance_domain_suffix`, `checks`, `cloneable` and `cloneable_reason`. The default's `node_id` and `node_name` are null when there is no default or the caller cannot reach its Node. A hidden default also has a null suffix.

`cloneable` uses the same read-only admission check as Conn create and the name check. It requires a reachable, active container default with a seed snapshot, valid Project source defaults, supported development placement and PHP, and the clone's runtime contributions and model route. A Conn device thread needs a model. Conn checks the route even though core `instance:create` and `instance:migrate` admit without a ready route.

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

After admission, a remote change or an execution failure ends the Operation and keeps the Instance for retry. If mirror preparation observes a new target remotely, an existing branch or base disappears, a fetch cannot read the remote, or a rewritten source does not provide the frozen commit, `clone-container` fails with `instance.create_clone_failed`. That phase prepares the Node mirror; `clone-t3` then checks out its bundle. A bundle, transfer or guest checkout failure carries `instance.create_workspace_failed`. These later failures cannot be closed by the earlier remote read.

A legacy or core create can also claim the same branch after opt-in admission, because those calls retain their old rules; this does not produce a later occupancy check or failure. Explicit admission serializes competing explicit creates, not remote repository writes or legacy calls.

A remote target created after the Node's final head read cannot be detected by create; it does not change the frozen local commit and produces no create failure. A later Git push can encounter that remote conflict.

Checkout stays inside `clone-t3`. No setup or deploy command runs again after checkout. Dependencies and build come from the default's seed, and may need an install or build for the selected code.

Conn follows that Operation with `GET /operations/{id}`. Conn Operation show also shows an `instance.create` that the caller started, here or with the core Instance API, and an `instance.destroy` started through the Conn endpoint below. Other destroy Operations keep their existing 404 on this path. It also shows a clone's [completion](/cli/instance/complete), `instance.complete`, to the Node that started it and to the Node that created the clone: the create's `result.completion_operation_id` names the first completion. Conn Operation cancel cancels the create and the completion that show shows to the caller ([Cancel a create or a completion](#cancel-a-create-or-a-completion)). It answers 404 for a destroy: a removal is not cancelled.

Operation show returns the same recorded `steps` as core Operation show, in recorded order: `name`, `job`, `status`, `started_at` and `finished_at`. Steps contain no command output, secrets or environment values. Both endpoints accept `after_sequence` but currently return all recorded steps.

Conn maps these stable container-clone step names to stage detail, in this order: `clone-container`, `clone-t3`, `clone-session-copy`, `clone-register`. Other steps can occur between them.

Two other steps of the clone job can come before `clone-container`: `clone-ci-hold`, which only a proof copy runs, and `clone-seed-wait`, which every clone runs. `clone-seed-wait` waits for the default's seed during a retake, for at most 120 seconds; a normal copy passes it in about one second ([A retake keeps the seed](/reference/development-containers#a-retake-keeps-the-seed)). The Gateway reports both under their own names. A Conn build that does not know a step name shows no row for it, so the `Copy the container` row starts with `clone-container`, as before.

`clone-container` stages the release scripts itself, so a create reports no `input` and no `clone-input` step. `clone-t3` runs the checkout (the branch, the checkout mark and `.env`) beside T3, then checks the sessions; `clone-session-copy` and `clone-register` always come after it. `clone-register` also builds the create's result, so a ready create's `finish` is skipped. Renaming or removing any of these four names is a breaking change for Conn.

A clone's completion reports `clone-services` as its one Node step: it stages the release scripts, starts the services and checks the clone. It reports no `clone-input` and no `clone-check` step. A plain clone reports `input` and `cleanup` skipped; a completion that publishes the Route, or starts a nested fleet, runs them. Then `finish` and `clone-private-cleanup` side by side, and `accepted`.

A create reports no `clone-workspace` and no `clone-sessions` step: their work runs inside `clone-t3`. A Conn build that still has a row for `clone-workspace` sees no step for it; the row stays pending until the create ends, and is done when the create succeeds.

A succeeded `instance.create` means the thread is usable: T3 is registered and the workspace is checked out. It does not mean fully set up. The create's result names the clone's completion in `completion_operation_id`; the database, the Processes and a nested fleet follow there, and only the Instance's `status: active` means fully set up ([Complete a clone](/reference/development-containers#complete-a-clone)). A Conn build that does not follow the completion marks every create row done at the create's success: `Copy the container`, `Copy the workspace`, `Start the T3 server` and `Register with Orbit` are done then. A failed baseline read fails the create at `Register with Orbit`.

The address of a clone with a Route is published in the create when its Route Nodes are free at admission, else in the completion ([the address during the create](/reference/development-containers#the-address-during-the-create)). The create's steps then hold job `publication`: `clone-route-csr-<i>` per certificate leaf and `clone-route`, with the Route create children (`csr`, `sign`, `Install cert, publish site-<i>`, `site`, `Publish private DNS-<i>`, `records`, `catalog`, `restart`, `Probe the site over TLS-<i>`). The create's `result.route_publication` names the outcome:

| `route_publication` | Create steps | Completion steps | `Publish the address` |
| --- | --- | --- | --- |
| `published` | `clone-route-csr-<i>` and `clone-route` succeeded | no `route-csr-<i>`; `route` skipped | done when `clone-route` ends |
| `deferred` | `clone-route` skipped and no `clone-route-csr-<i>`; or `clone-route` failed because a Node refused the merge of the site | job `publication`: `route-csr-<i>` and `route`, as before | follows the completion's job `publication` |
| `failed` | a `clone-route-csr-<i>` or `clone-route` failed; the create still succeeds | job `publication` publishes again | follows the completion's job `publication`; its failure is `failed_step: route`, `instance.create_route_failed`, and Retry is `instance:complete` |
| absent | no Route: `clone-route` skipped | `route` skipped | done |

The step names and jobs that installed Conn builds map stay: the completion's job `publication` with `route-csr-<i>` and `route`, the completion's `failed_step: route`, the create's clone steps, and the older names `csr`, `sign`, `clone-route`, `site` and the address prefixes.

An installed build that maps create steps by name shows `clone-route` and its children on `Publish the address` during the create, ignores `clone-route-csr-<i>`, `records`, `catalog` and `restart`, and marks the row running again at the create's success until the completion ends. So after `published` the row can read done, then running for the about 7 seconds of the completion, then done; it is never done before the address answers TLS. A newer build reads `route_publication`: `published` keeps the row done; `deferred` and `failed` follow the completion's job `publication`. Beside `deferred`, the result also names `route_publication_reason` ([the address during the create](/reference/development-containers#the-address-during-the-create)); Conn does not read it, and the row shows the same as for any `deferred`.

A server of an Instance names its `instance_id` and the container's `power_state`, `running` or `stopped`. Other servers have `null` for both. Conn shows a `stopped` server as asleep.

### Cancel a create or a completion

`POST /operations/{id}/cancel` with an empty JSON object cancels an `instance.create` or `instance.complete` for the Node that Conn Operation show serves it to: any create the Node started, as show does, and the completions show names. Like show, it checks the requester only, not node access to the Operation's Node. It runs the same cancel as core [Cancel an operation](/reference/operations#cancel-an-operation). It answers 200 with the Operation record, without its `steps`; read them with `GET /operations/{id}`. Another Node gets 404, and so does every `instance.destroy`.

An Operation whose run has not started ends `cancelled` at once. A running one keeps its status and reads `cancel_requested: true`; Swamp is asked to stop it after the answer, and the Operation ends `cancelled` when the run has stopped. A second cancel answers the same. For a running Operation it asks Swamp to stop again; an ended one stays as it is.

A cancel that arrives as a clone's create ends can find the create `succeeded`, with `cancel_requested` true or false. The create then names its completion in `result.completion_operation_id`: cancel that completion on the same route.

A cancelled clone create keeps the clone for its owner. The Instance stays `reserved`, or `source_resolved` once its checkout ran, with its runtime and any container; Orbit attempts to stop the container. The create's `withdraw-route` step takes back an address that it started and did not finish publishing, and its end frees the Route Nodes it held. An address that `clone-route` already published stays up until the clone is removed. The result's `recovery` names a retry of the create and the removal. Conn removes the clone with `POST /instances/{id}/destroy`. A cancelled create admits no completion ([Complete a clone](/reference/development-containers#complete-a-clone)).

A cancelled completion keeps the clone `source_resolved`, its container running and its thread usable. Its own `withdraw-route` step takes back an address that it published. Retry it with `POST /instances/{id}/complete`, or remove the clone. A database fill that already runs is not cut: the cancel waits until the fill has ended, with the two exceptions in [Remove a clone whose completion runs](#remove-a-clone-whose-completion-runs).

## Durable launches (v1)

**Contract v1.1.** One launch prepares an Orbit Instance, sends one first message to its T3 project, and publishes the accepted thread to the Conn profile. The device can disconnect after admission and another device on the same profile can follow the launch. The contract supports text only on the Orbit T3 recipe `0.1.7`. This is an additive Gateway API: the existing Conn endpoints and the installed clients' create-then-send path do not change. Conn Rust parity is out of scope.

### Admit and replay

`POST /api/v1/conn/launches` requires `Idempotency-Key: <launch_id>` and a JSON object with these fields. Before Send the client allocates four stable opaque UUIDs: `launch_id` (in the header only), `command_id`, `thread_id`, and `message_id`. A repeat uses all four unchanged.

| Field | Contract |
| --- | --- |
| `expected_profile_id`, `expected_node_id` | Required positive integers for the profile and device Node the client expects to launch as. They do not grant access. |
| `project_id` | Orbit Project ID. |
| `workspace_id` | Optional workspace ID in the caller's profile. Omit it or send `null` to attach to the profile without a workspace. |
| `target` | New clone: `{ "kind": "new", "name": "fix-login-k3j9", "branch": … }`. Existing Instance: `{ "kind": "existing", "instance_id": 42 }` in this Project. An existing target keeps its checkout; omit `branch`. |
| `target.branch` | On a new target, use Conn's existing branch selection: `{ "mode": "new", "from": "main" }` or `{ "mode": "existing", "name": "fix-login" }`. Omit it or send `null` for the current default Conn clone branch behavior. |
| `prompt` | First user message as text, without attachments. Preserve its exact text, including whitespace and Unicode. |
| `model_selection`, `runtime_mode`, `interaction_mode` | Optional Conn/T3 selection and modes. Request numbers must round-trip without loss within the JavaScript safe integer range. |
| `source` | `web` for desktop or web; `mobile` for iOS. |
| `command_id`, `thread_id`, `message_id` | Stable client UUIDs for the T3 command, thread, and first message. |

The Gateway derives the initiating Node from WireGuard and the profile from its current `ConnProfileBinding`. Inside the admission transaction, before any launch effect or replay response, compare both expected IDs with those authoritative IDs. Reuse the existing binding row and hold it through commit: SQLite uses the configured `IMMEDIATE` transaction to serialize writes; other drivers lock the binding row. Do not rely on a check before the transaction or let idempotency middleware replay a response first.

Missing or invalid expected IDs fail request validation. A mismatch or absent binding returns `409 conn.launch_scope_changed`. Neither refusal adds an Operation, private input, or child, or returns an old snapshot.

Matching IDs still require current target access. For a new clone the Gateway checks the same Project and default placement access as Conn create; for an existing Instance it checks the recorded Project, registered target and target Node access. It commits one `conn.launch` Operation and its encrypted private request before it returns `202` with `{ "data": LaunchSnapshot, "meta": { "request_id": … } }`.

The admission identity is the original Node and `launch_id`, not the profile or a later control device. On a lost reply, the same Node can replay the same key and normalized body. Before returning the same Operation's current snapshot, the Gateway performs the same locked expected-ID check and rechecks current access to the recorded profile and target Node. A rebind makes a replay with the original expected IDs fail with `conn.launch_scope_changed`; supplying new expected IDs does not move the original launch to the new profile. One device does not inherit another device's admission key.

Compare every accepted request field, including a keyed, protected fingerprint of the exact prompt. Normalize omitted and `null` `workspace_id`, and omitted and `null` new-target `branch`, to the same values before fingerprinting. Compare the admitted selectors, not defaults resolved later from live state. A changed normalized request for the same admission identity returns `409`.

For a new admission, the model selection can use up to 16,384 bytes in the JSON Orbit stores and freezes for dispatch. The count includes JSON escapes and the decimal part of integral floats such as `1.0`. A larger selection returns `422` on `model_selection` before Orbit records an Operation, private input, or child. The limit applies to the selection only. Already admitted requests keep the replay rules above.

Keep the exact prompt and the private part of the frozen dispatch request encrypted. Only the declared private-material transport for the admitted native run may hydrate it on the target. Do not put them in public Operation input or results, Activity, broadcasts, runner arguments or output, or persistent Swamp data. Use a title seed that does not disclose the prompt; T3 generates the thread title. Keep only bounded acceptance evidence and the protected admission fingerprint after acceptance or verified cleanup. T3 keeps the actual message as part of the user's thread.

### Prepare, dispatch and publish

The Gateway advances records under the launch Operation's row lock. It admits existing native create, wake, completion and teardown work with keyed child identities and records the exact child IDs, target and ownership before consuming their results. Children retain `node:<initiator>` and the admitted target even when another device controls the launch. Reusing an already running Instance, joining a wake or observing work that another caller admitted conveys no ownership. Do not follow the newest Operation by name or switch to another target on recovery.

Workspace-server readiness comes from the Swamp create's recorded T3 registration or from the wake's T3 readiness on the admitted target, not from application preview. The [placed Swamp phase](/reference/development-containers#conn-launch-transport) discovers the live T3 project rooted at `/srv/workspace`.

Use the requested model selection if its provider instance is enabled for the project; a health check still in progress does not count as a disabled provider. Otherwise, or with no selection, use the server's configured default selection, then the project's provider default. If neither supplies a model, leave preparation retryable without sending. The omitted runtime and interaction modes default to `full-access` and `default`, respectively.

Freeze the resolved `modelSelection` (including provider instance and options), modes, all RPC fields and the persisted T3 incarnation before sending. A retry cannot resolve a different model or change the request. Report the actual selection, not the requested one when fallback applied.

The same Operation lock commits either `cancel_requested` or `dispatch_committed`, never both. The worker can send only after `dispatch_committed`; cancellation that wins the lock prevents both dispatch and admission of further launch work. The placed worker calls stock `orchestration.launchThread` with this payload; the stock WebSocket handler supplies `createdBy: "user"`. The service session grants transport access only; the launch retains the initiating human device's Node and profile for audit.

```text
{
  commandId, threadId, projectId, title, generateTitle: true,
  modelSelection, runtimeMode, interactionMode,
  workspaceStrategy: { type: "root" }, creationSource,
  initialMessage: { messageId, text, attachments: [] }
}
```

Here `projectId` is T3's project ID, `creationSource` is the admitted `source`, and `text` is the exact submitted prompt. This is the same first-turn shape as Conn's `orbitFirstTurnInput` and `startThreadTurn`, not a separate create followed by a second send.

On every attempt, verify the live server against the pinned persisted incarnation independently of its URL. The worker checks the environment and database file identity, plus the presence of the original probe session row in the local stock T3 database. Only that row's presence matters. A normal restart retains the row. An older database restored into the same file lacks it.

After an ambiguous reply, ask `orchestration.getThreadProjection` on that incarnation for the exact `threadId`. Verify the exact thread and project, a user message with the exact `messageId`, and the original message text byte for byte. Apply the same identity and text checks to an acknowledged launch.

If the first message has left the RPC snapshot, read the same thread through the authenticated bounded snapshot and older conversation pages. An older item must be a local, user-authored message of that thread. A match proves acceptance: store the bounded receipt and finish publication only, even if T3's run failed after accepting the message.

The native handoff streams the private request over pinned SSH to a mode-0600 file in the Swamp worker’s run folder. The play reads and removes that file before passing its exact bytes to T3 on stdin. The first send claims one execution in that folder. After an ambiguous first reply, the reconciler starts a read-only recovery attempt. Further Retry requests use the same read-only action.

After a lost reply, send the identical frozen command only if an authoritative read proves the expected message is absent **and** the original incarnation is still within a known safe replay window. A stable URL, the passage of time alone, an empty thread, or a generic read error is not proof. A replacement server, mismatched identity or text, a failed read, or an incomplete projection without authoritative absence and a safe window leaves the launch recoverable without another send. V1 does not assume a stock T3 receipt lifetime from a successful test.

Acceptance is separate from publication. As soon as acceptance is known, store `thread` and clear the private prompt. Use the existing Conn thread index and attached-thread services to publish idempotently under the recorded profile's lock. Check the workspace on that locked record. If it was deleted, attach the thread to the profile without a workspace and do not recreate it. A lost publication reply retries the record write, never the T3 command.

### Cancel, retry and follow

The launch routes in the [API table](#api) return the same snapshot shape. List returns visible launches newest first. Show reconciles the exact Operation from recorded facts. Both reads and both controls require an active WireGuard peer, a current binding to the launch's recorded profile, and current access to its recorded target Node. Filter list rows and return `404` for an invisible individual launch, subject to the existing collection access gate.

Recheck control authority and the action under lock. Audit which Node requested Cancel or Retry separately from the initiator. The caller-only rule on the existing Conn Operation routes does not change. HTTP snapshots are authoritative. Reverb, if used, only invalidates reads for the same audience and carries no launch payload.

If Cancel wins, settle owned child work before admitting teardown. If a probe times out, wait for evidence that its native run ended and for the remaining T3 session lifetime to expire. Measure that wait from the recorded native end, not the Gateway's timeout.

Use the ordinary Conn workspace check and confirm there is no T3 activity before removing a launch-owned clone, even if its create failed. A reservation whose create never ran needs no workspace read. Keep cleanup pending if the workspace is dirty, a required read fails, or another launch uses the Instance. Admission of a launch on an existing clone and admission of its cleanup both lock the Instance. If the other launch claims it first, cleanup waits. If cleanup claims it first, the other launch is refused.

Verify that teardown removes the exact Instance and its owned resources. If a teardown response is lost, reconcile the recorded child and its effect before admitting another attempt. Keep the launch `cancelling` with a sanitized reason when cleanup fails or remains blocked, and resume it after restart. If the launch's own removal stopped the container before it failed, Orbit can retry while the workspace reports the container stopped. A retry that fails before starting its native run keeps the earlier Stop evidence within the same owned removal chain. The ownership and other-launch checks still apply.

Preserve pre-existing Instances and unrelated work. Joining an open `instance.wake` or `instance.start` records shared use under the same Operation lock that launch cleanup takes before cancelling queued work. If the join wins, the wake runs for the other caller and cleanup leaves the Instance awake. If cleanup cancels an unshared queued wake first, the next caller can admit a fresh wake. A failed activity read keeps cleanup pending with `conn.launch_wake_restore_unproven`.

To restore a completed wake, read activity outside the Instance lock. When admitting native sleep, take the same Instance lock as existing-target launch admission. Confirm that the cancelling launch still owns the current wake. Check whether another caller joined that wake or another launch used the Instance since the wake began. If a new launch claims the Instance first, cleanup leaves it running. If sleep restoration is admitted first, the next launch waits for that sleep to settle, then wakes the Instance if needed before probing T3.

Only verified cleanup clears the private prompt on cancellation and ends the launch as `cancelled`.

Once `dispatch_committed` is recorded, Cancel answers `409 conn.launch_handoff_committed`, including while the reply is uncertain. Stock T3 can start the agent before it replies, so response receipt is too late for this cutoff. After acceptance, use the normal T3 thread Stop or Remove actions, not launch Cancel.

`POST /launches/{operation_id}/retry` accepts `{ "target": "launch" }` for the same launch, or `{ "target": "application" }` to retry application completion alone. Launch retry resumes from the recorded child or receipt and respects the same dispatch fence and replay rules. Application retry uses the existing completion admission and can fail or succeed independently once the thread is ready. It never restarts T3 or sends a message. Report a known prerequisite, such as `project_process_definition`, before offering a retry that cannot work.

If a newer completion for the same Instance and Node is admitted before the accepted application retry can admit its own completion, Orbit uses that completion and clears the retry. The launch keeps the earlier completion in its history and shows the newer completion’s progress or result.

If the target Instance is gone when an accepted application retry runs, Orbit ends the retry and stops polling. Orbit also ends the retry if admission refuses completion on an existing Instance. In either case, `application_preview` shows a sanitized error. The launch stays `ready` and keeps the last admitted completion ID. A later admitted completion replaces the refusal in the snapshot.

### Launch snapshot

Project the safe fields of `OperationData::summary`: `id`, `kind`, `status`, `created_at`, `started_at`, `finished_at`, `cancel_requested`, `poll_after_seconds`, and `error_code`. The `kind` is `conn.launch`. Do not include raw Operation input, state, result or native diagnostics. Add these fields:

| Field | Contract |
| --- | --- |
| `launch_id`, `project_id`, `instance_id`, `workspace_id` | Admission UUID, Orbit Project, exact Instance once known, and requested workspace. The last two can be `null`. |
| `profile_id` | Recorded numeric Conn profile ID, present on every snapshot. |
| `target_node_id`, `initiator_node_id`, `source` | Recorded target Node, initiating device Node, and `web` or `mobile`. |
| `phase` | `preparing`, `dispatching`, `publishing`, `ready`, `needs_retry`, `cancelling`, or `cancelled`. `ready` means the accepted thread is published, not that the application preview works. `needs_retry` indicates launch recovery; application-only failure does not undo `ready`. |
| `stages` | Object keyed by `workspace_server`, `first_message`, `publication`, `application_preview`, `cleanup`. Each value has `status` (`pending`, `running`, `succeeded`, `failed`, `cancelled`, `skipped`), `started_at`, `finished_at`, and `error` (`null` or sanitized `{ "code": …, "detail": … }`). An unrecorded timestamp is `null`. |
| `readiness` | Object with `workspace_server`, `first_message`, and `application_preview`, each `true`, `false` or `null` (not known). Use the create registration or wake T3 result, the acceptance receipt, and `InstanceCompleteProjection` or its recorded prerequisite respectively. |
| `actions` | Object keyed by `cancel`, `retry_launch`, and `retry_application`. Each has boolean `available` and nullable strings `reason` and `requires`. `requires` is a machine-readable prerequisite code, for example `project_process_definition`. |
| `children` | Object with nullable `create_operation_id`, `wake_operation_id`, `completion_operation_id`, and `destroy_operation_id`. A null ID means no child is recorded for that role. |
| `thread` | `null` until acceptance is known; then `{ "environment_id": …, "project_id": …, "thread_id": …, "message_id": …, "model_selection": …, "accepted_at": … }`. The project ID here is T3's. The receipt is available during `publishing`. |

The `stages`, `actions`, and `children` fields are keyed JSON objects even in the initial snapshot. Record stage times and errors from Gateway transitions and native results, never from a client's socket. Use the message's recorded time for `thread.accepted_at`, not the time a reply arrives. Keep core Operation status nonterminal while launch retry or cleanup remains pending. Do not reopen a terminal Operation to represent later application progress.

Successful workspace preparation keeps its recorded times even if later launch preparation needs a retry or cancellation removes the server. A launch retry after a later preparation failure uses the same Instance. If an existing Instance slept again after its wake succeeded, Retry records a fresh wake or joins an open one before running a new T3 probe.

Keep following `poll_after_seconds` after the launch reaches `ready` if application completion or an application retry is pending. Read `application_preview` separately from thread readiness. A ready launch with no completion can report `skipped` with no readiness value. When you run [`instance:complete`](/cli/instance/complete) for the admitted Instance on its target Node, settlement can record it as the first completion on the same ready launch. Settlement can also replace a failed completion with a newer one. The accepted thread and its first message stay the same.

When a Project process definition is required for retry, Orbit compares the expected unit with the one the failed completion used. Applying the repaired definition to the Instance first does not prevent a retry.

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

To stop the completion and keep the clone, [cancel it](#cancel-a-create-or-a-completion) instead of removing it.

The removal starts when the completion has ended. The completion's SSH and shell steps take the abort at once: Swamp ends their connection to the Node. Its own `withdraw-route` step takes back a Route it published, and the removal then deletes the container, the clone's database, the Route, the completion's vault copies and the records.

A database fill that already runs is not cut: the cancel waits until the fill has ended, within its 600-second limit ([Cancel an operation](/reference/operations#cancel-an-operation)). Meanwhile the removal shows `waiting` and the completion keeps the clone's lock and its Route hold. Then the completion takes the cancel, withdraws its Route and ends, and the removal drops a database that no copy touches.

Two cases are not covered. A run that the Gateway times out or loses during the fill gets the cancel at once, so its copy can still outlive it. A cancel that reaches Swamp in the moment the fill starts, before the Gateway has recorded that start, can cut it.

The removal's run budget counts from its start, not from its admission. Several removals at once each wait for their own clone's completion; removals that share Route Nodes start one after another, oldest first, as every Route write does.

While the completion runs, the remove sheet's read answers as for any running container: the completion writes no tracked file, so a workspace without the user's work reads `clean`. Its `baseline` is `recorded`: the create records it before it ends. After a failed or cancelled completion the clone stays `source_resolved` with its container running; the read and the removal work as for a retained failed create.

A retry of a removal never starts after a completion that an earlier call named: from Conn it cancels the running completion and waits again; from the CLI it refuses while the completion runs.

The CLI and the core API keep their refusal: `instance:destroy` while the completion runs answers `operation.target_busy` (409) with `details.operation_id`, the completion. Follow it to its end, or cancel it, then destroy again.

### Remove a clone whose create runs

A removal while the clone's create runs is accepted at once from every caller: Conn, the CLI and the core API. In one transaction the Gateway records the cancel of the create and admits the removal with status `waiting` and `after_operation_id` set to the create. The call answers 202. Swamp is asked to stop the create after the commit, and the reconciler asks again on each pass until it stops. A create that had not started yet ends `cancelled` at once.

The removal starts when the create has ended. The create's own `withdraw-route` step takes back a publication that it started and did not finish. A removal admitted during the create marks the Route retiring, so an address that `clone-route` publishes after it stays down, and the removal's withdrawal captures the clone's site in either state. The create's end records nothing of the removed clone and admits no completion. Several removals at once each wait behind their own clone's create. Without a removal, Conn [cancels the create](#cancel-a-create-or-a-completion) and keeps the clone.

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

Admission records the held-session Operation and a vault reference. The start worker copies the current admin session to that reference before it submits the run, outside the database writer. If a wake retries delivery and finds its renewal still queued and unstarted, it can submit the same Operation again without creating another pairing.

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

## Managed grant revocation

This section is the contract for the first isolated proof: a lab Gateway removes one direct Node edge, and a disposable Rust Conn target fences that grant at once. It covers one manually enrolled lab target. It changes no live Node, edge, grant policy or legacy credential.

A managed target is a registered Conn environment with one managed target record. The record holds the environment UUID, the registration generation, the serving Node and the digest of the target's service credential. The serving Node is the environment's registered Node.

The switch `orbit.conn.managed_targets` (`ORBIT_CONN_MANAGED_TARGETS`) is off by default. While it is off, every route below refuses with `conn.managed_disabled` (404), before any peer, extension or input check. The switch gates only the routes. Edge removal writes managed revisions for every enrolled target whether the switch is on or off. So a target cannot probe while the switch is off, its lease ends, and it fails closed. When the switch is on again, the target sees every removal it missed.

### Credentials

The proof uses two new secrets: one that proves the target and one that proves a caller.

| Credential | Format | Holder | The Gateway stores |
| --- | --- | --- | --- |
| Service credential | `conn_svc_` and 43 base64url characters from 32 random bytes | The Rust target process only, in a file with mode `0600` that the target user owns | Its SHA-256 digest, the environment UUID, the generation and the serving Node ID |
| Managed token | `conn_mt_` and 43 base64url characters from 32 random bytes | One caller Node | Its SHA-256 digest, the target record, the generation, the caller Node ID, the `node_access` row ID, the fixed scopes, the expiry, and the revision that revoked it |

No log, response or Activity row holds token or credential material, apart from the one response that returns a new managed token. The prefixes keep managed tokens apart from native Conn tokens.

### Enrol a lab target

An operator on the Gateway runs `php artisan conn:managed-target:enrol {environment_id} --credential-file=<path>`. The environment must be registered, its Node active, and its ID a lowercase canonical UUID; registration itself accepts any string without whitespace, so enrolment checks the UUID and refuses with `conn.managed_environment_id_invalid` (422).

The command starts generation 1 at revision 0, or replaces the generation of an existing record with the next one. It writes the new credential to the file with mode `0600` and never prints it. The file is delivered as the last step before the commit: when delivery fails, the command fails and the current generation, credential and owed rows stay as they were. A new generation refuses the old credential, every token of the old generation, and every owed acknowledgement of the old generation at once.

The operator gives the target its protected provisioning input: the Gateway URL, the path of the lab Orbit CA, the expected environment UUID, the generation and the credential file path. The target treats the UUID and the generation as fixed. A clone or a replacement at the same address is enrolled again before its service starts. An ordinary service restart keeps the generation and the credential. The public instance file never holds these values.

### Target routes

The target calls these routes over HTTPS. It verifies the Gateway name against the lab Orbit CA only. Every request carries `Authorization: Bearer conn_svc_…`, the `environment_id` and the `generation`. The Gateway accepts the request only when:

- the WireGuard peer is the target's serving Node, by the rule of `RequireActiveWireGuardPeer`;
- the credential digest matches the record of that environment;
- the environment UUID and generation are the current ones of that record.

A managed token, a native Conn token, or a credential of another target on the same Node never passes. Each route reads and writes in one transaction that checks the generation again, so a request authenticated before a rotation never reads or settles the new generation's state. The ordinary `/api/v1/realtime` and `/api/v1/broadcasting/auth` stay out of this path.

| Method and path under `/api/v1/conn/managed` | Request | Response `data` |
| --- | --- | --- |
| `POST /realtime` | — | `url`, `key` and `channel`: `private-conn-target.{target_id}.{generation}` for the current generation |
| `POST /broadcasting/auth` | `socket_id`, `channel_name` | Pusher `auth` for the current generation's channel only |
| `POST /authority` | `nonce`, `after_revision`: an integer or `null` | The [authority state](#authority-state) |
| `POST /introspect` | `nonce`, `token`, `peer_address`: the TCP peer of the Conn socket | The [token claims](#token-claims) |
| `POST /ack` | `revision`, `kind`: `observed` or `recovered`, `notice_received_at`, `fenced_at`, `handlers_closed_at`, `recovered_at` | `environment_id`, `generation`, `revision`, `kind`, `acknowledged_at` |

A `nonce` is 16 to 64 base64url characters that the target draws at random for each request. Every reply of `/authority` and `/introspect` echoes `nonce`, `environment_id` and `generation`. The target accepts a reply only when all three match the one request it has outstanding on that path. It drops a reply whose nonce it already consumed or never sent.

### Caller route

`POST /api/v1/conn/environments/{environment_id}/managed-tokens` with `{"purpose": "reader"}` issues one managed token for one managed target. The caller is the active Node of `RequireActiveWireGuardPeer`. It needs a direct `node_access` row to the target's serving Node. A caller whose only access is Gateway authority gets no token. The input holds `purpose` and nothing else, as JSON, form fields or query: any other field, such as `scopes`, is refused even when it is empty or `null`. `reader` maps to `orchestration:read` and `terminal:read`. The response (201) holds `token`, `expires_at`, `scopes`, `generation` and `revision`.

The token expires 15 minutes after issue, at a whole second, and has no renewal; the caller asks for a new one. Introspection enforces exactly the advertised `expires_at`. This lifetime is a lab choice. It does not bound enforcement: revocation ends a token at once, whatever its expiry.

Issue runs in the same transaction kind as edge removal. It reads the edge row and the target's revision under the write lock, so no token is issued against an edge that is already removed.

### Token claims

The target calls `/introspect` at the first transport admission of each managed bearer: an HTTP request, an RPC WebSocket upgrade or a media ticket redemption. The Gateway accepts the token only when:

- the token belongs to the authenticated target record;
- the token's generation is the current one;
- `peer_address` resolves by the active Node rule to the token's caller Node;
- the token's edge row still exists, and the token is neither revoked nor expired.

So two environments on one serving Node never accept each other's tokens, even at the same generation and with the same caller edge.

The reply holds `nonce`, `environment_id`, `generation`, `token_id`, `caller_node_id`, `edge_id`, `scopes`, `expires_at` and `revision`: the target's committed revision when the reply was read.

The target admits the token only when all of these hold:

- `nonce`, `environment_id` and `generation` match its request and its own provisioning;
- `revision` is not greater than its applied revision; otherwise it reconciles first and introspects again;
- `edge_id` is in neither its confirmed nor its provisional fenced set;
- its authority lease is live;
- `expires_at` is in the future.

A verified reply is the only way an edge ID enters the target's admitted set. No cache extends the expiry. A forwarding header is never read. The target never substitutes the serving Node's address for a caller.

### Revision order

Each managed target has one committed revision, which starts at 0 and counts removals only. Adding an edge does not raise it: the new edge gets a new ID, which only a verified introspection can admit. `node_access` IDs are never reused, so re-adding an edge between the same pair never revives the old ID.

Removing a direct edge through `DELETE /api/v1/nodes/{servingNode}/access/{consumerNode}` ([node:access-remove](/cli/node/access-remove)) runs one IMMEDIATE transaction. Core calls Conn inside it through the `NodeAccessRemovalTransactionHook` plug-in point ([Extensions](/reference/extensions#plug-in-points)):

1. Lock the edge row and capture its ID, then lock the managed targets of its serving Node in ascending ID order.
2. Delete the edge.
3. Raise each target's revision by one, and record the removed edge ID at that revision.
4. Mark the edge's managed tokens as revoked at that revision.
5. Record one owed acknowledgement for each target: its environment UUID, generation, revision and `committed_at`.

The transaction captures each target's generation with its revision. After the outermost commit, the Gateway records `commit_observed_at` and publishes `conn.authority.revoked` on the channel of that captured generation, `private-conn-target.{target_id}.{generation}`, and never on another. Its data are `environment_id`, the captured `generation`, `revision` and `edge_ids`; the Gateway records `notice_sent_at`.

Publication and rotation of one target take that target's lock, a cache lock that is not the database writer lock. Publication holds it across the generation check, the send and its record, so no rotation lands between them. Each database step is its own short transaction, so a slow Reverb never delays a probe or another target. A rotation that finds the lock taken waits up to 10 seconds, then refuses with `conn.managed_target_busy`. A publication that finds it taken that long skips the notice and records `notice_suppressed_at`; it never sends to a generation that may have rotated.

When a new generation replaced the captured one before the publish, the Gateway skips the notice and records `notice_suppressed_at`. It never sends or relabels an old generation's removal for its successor. It does not wait for an Operation. A rollback publishes nothing. The existing held-session withdrawal ([Removing an edge](#removing-an-edge)) runs after the outermost commit, in its own transaction, even when the removal ran inside an enclosing transaction. It checks broader server access and is neither managed enforcement nor its acknowledgement.

A replaced target keeps no notice path: its old subscription is on the old generation's channel, which carries no notice of a later generation, and the Gateway signs only the current one. The owed rows of a replaced generation become `superseded`: enrolment records `superseded_at` in its transaction. They are never recorded as acknowledged, and `/ack` for them refuses with `conn.managed_generation_mismatch`.

### Authority state

`/authority` reads the current committed state. Besides the echoed `nonce`, `environment_id` and `generation`, every reply holds `head_revision`, `server_time`, `owed` and one of three kinds:

| `kind` | When | Content |
| --- | --- | --- |
| `snapshot` | `after_revision` is `null` | `edges`: every direct edge to the serving Node now, as `edge_id` and `consumer_node_id` |
| `delta` | `after_revision` is below the head | `revisions`: each revision after `after_revision` up to the head, in order, with its `edge_ids` |
| `current` | `after_revision` equals the head | Nothing more: no revision happened since |

`owed` lists every owed acknowledgement of this generation that is not settled, as `revision` and `edge_ids`.

A target starts with a snapshot after connect or restart, and takes one to recover from failing closed. It never takes one while managed access is open. Only a target that has not failed closed reconciles through `delta` and `current` replies. Each snapshot runs behind a barrier:

1. Pause managed admission. Callers that arrive wait.
2. Drop every outstanding introspection: its reply is discarded when it arrives.
3. Send the snapshot request and apply its reply.
4. Introspect each waiting caller afresh.

When it applies a snapshot, the target confirms the fence of every edge ID it had admitted that the snapshot does not list, before any dispatch or output resumes. The barrier makes this safe: every admitted ID came from a reply that the Gateway read before the snapshot. A listed edge is not yet admitted: it needs a verified introspection like any other.

The target keeps two fenced sets:

| Set | Filled by | Ends |
| --- | --- | --- |
| Provisional | A notice | When verified state for that revision applies, or a probe sent after the notice disproves it |
| Confirmed | A verified `delta`, `owed` list or snapshot | Never, for the life of the generation |

When verified state for a revision applies, the target moves each of its edge IDs to the confirmed set and closes any of their handlers still open. It then clears each provisional ID of that revision that the verified state does not name. A cleared ID can be admitted again only by a fresh introspection. Clearing never reopens a socket that the fence closed.

A notice can also be disproved. The target records the monotonic time each notice arrived. When it applies a verified `current` or `delta` reply to a probe it sent after that time, and that reply's head is below the notice's revision, the notice named a revision that does not exist. The target clears that notice's provisional IDs, under the same rules as above. A reply to a probe sent before the notice arrived never clears it.

It runs one probe at a time for both its timer and Reverb notices. When a greater revision becomes known while a probe runs, it probes again at once.

| The target sees | It does |
| --- | --- |
| A notice for its next revision | Fences those edges provisionally at once: closes their handlers and refuses their admission. Other edges keep their live lease while a probe confirms the change. |
| A stale or duplicate notice | Ignores it. A notice only restricts access. |
| A `current` reply to its outstanding probe | Renews the lease from that probe's send time. |
| A reply whose nonce it already consumed or never sent | Drops it. It renews nothing. |
| A revision ahead of its next one, without a contiguous edge list | Blocks every managed admission, dispatch and output until a probe applies the head. |
| A missing revision, a wrong generation, a failed check, a closed control connection, or an expired lease | Fails closed for all managed access. It recovers only through the snapshot barrier above, with a live lease. A `delta` never reopens access after failing closed. |

A notice is not proof of what the Gateway committed. Only an applied HTTPS reply moves the confirmed revision forward, renews the lease or names the edges of a revision. A Reverb message, presence or a WebSocket ping never renews authority.

### Lease

For the lab, the target probes every second. The lease ends 3 seconds after the monotonic send time of the last probe whose reply it applied. These two values are lab hypotheses. Their measurements set later production values. A reply that arrives after its lease ended cannot renew it or reopen a socket. An independent timer fences idle sockets. The target compares monotonic time at every admission and data boundary. There is no grace period.

A revoked grant and unavailable authority are two states. A revoked edge stays refused after the authority returns. Unavailable authority refuses all managed access until it returns, then the unrelated edges recover.

### Fence and acknowledgement

On a revocation, the target blocks the edge and cancels every connection, subscription, queued frame, media viewer and unspent ticket of that edge in one synchronized step. It registers each managed socket before its upgrade completes, so an upgrade cannot pass the fence. It checks expiry, revision and edge at admission, before each RPC dispatch and before each application frame. Other edges keep their streams. Work that the server already accepted, such as an engine run, stays with the server.

The target acknowledges one revision at a time. It sends `/ack` for a revision only when:

- it learned that revision's edges from a verified `delta` or from `owed`, never from a notice alone;
- every handler of those edges has ended, and no queued frame of them can be sent.

Bytes already passed to the operating system cannot be recalled. `notice_received_at` is `null` when no notice arrived for that revision. An `observed` ACK carries the measured `fenced_at` and `handlers_closed_at`, and `recovered_at` is `null`. `handlers_closed_at` is the time the target verified, after the fence, that the edges had no handler left and no queued frame that could still be sent. It is the same rule when that was already true at the fence, such as for an edge that never opened a handler.

A restarted target observed no close: its handlers ended with the old process. Once it has applied the snapshot, it sends a `recovered` ACK for each `owed` revision. That ACK confirms that no affected handler remains. Its `recovered_at` is the time it applied the snapshot, an upper bound on the close; `fenced_at`, `handlers_closed_at` and `notice_received_at` are `null`. The timing proof leaves `recovered` ACKs out of every fence and close measure.

The Gateway settles only the owed row with the same environment UUID, generation and revision. One ACK never settles another revision. It records `acknowledged_at` and the reported target times on the first valid ACK. An exact retry answers that first result and changes nothing.

`revision` is a JSON integer. Every reported time is an RFC 3339 string with an offset that names a real date and time, and `handlers_closed_at` is not earlier than `fenced_at`, compared at every fractional digit. An ACK that breaks this is refused with `validation.failed` (422), and the revision stays owed. A lost request leaves the row owed, and the target sends it again while `owed` lists it.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `conn.managed_disabled` | 404 | The switch is off. |
| `conn.managed_target_unknown` | 404 | The environment has no managed target record. |
| `conn.managed_environment_id_invalid` | 422 | Enrolment names an environment ID that is not a canonical UUID. |
| `conn.managed_target_identity` | 403 | The peer is not the serving Node, or the service credential does not match. |
| `conn.managed_generation_mismatch` | 409 | The generation is not the current one. |
| `conn.managed_revision_future` | 409 | `after_revision` names a revision above the head. |
| `conn.managed_ack_unknown` | 409 | The ACK names no owed revision of this target and generation. |
| `conn.managed_direct_edge_required` | 403 | The caller has no direct edge to the serving Node. |
| `conn.managed_token_invalid` | 403 | The token is unknown, expired, revoked, of another target or of another generation. |
| `conn.managed_peer_mismatch` | 403 | The socket peer does not resolve to the token's caller. |
| `conn.managed_channel_forbidden` | 403 | The channel is not the current generation's channel of this target. |
| `conn.managed_target_busy` | 409 | Enrolment found the target's notice publication running for 10 seconds; enrol again. |

An owed row without `acknowledged_at` means enforcement is unconfirmed: the target, its probe or its ACK did not arrive.

### Managed ingress

The target serves managed callers on a managed ingress, separate from its unmanaged ingress. Every path of the managed ingress needs a managed principal, including RPC and media ticket redemption. A native session, a native bearer, a cookie or a device-paired credential is refused there, even on an endpoint the reader profile allows. A `conn_mt_` token validates only through the Gateway, and a failed one never falls through to native auth. Native credentials keep their own rules on the unmanaged ingress.

The `reader` profile allows shell and thread snapshots and subscriptions, `terminal.observe`, `subscribeTerminalMetadata`, and a read-only viewer of a known test tab. RPC and media tickets are allowed only when they carry the exact managed principal and expiry. The target refuses on this ingress: pairing and credential administration, MCP, asset and upload URL minting, interactive preview, terminal writes, agent delegation and every other endpoint the proof has not covered. It also refuses native pairing exchange there and shows no startup pairing code. Fixture setup, such as seeding a terminal and a preview, runs on its separate restricted path.

### Timestamps

The Gateway and the target record these times separately, so the proof can measure each step.

| Time | Clock | Recorded by |
| --- | --- | --- |
| `committed_at` | Gateway | Inside the edge-removal transaction, before its writes: a lower bound on the commit |
| `commit_observed_at` | Gateway | In the after-commit callback, before the publish: an upper bound on the commit |
| `notice_sent_at` | Gateway | After the Reverb publish returns success; a later rotation records `superseded_at` and leaves it |
| `notice_received_at` | Target | On the notice, reported in the ACK; `null` when none arrived |
| `fenced_at` | Target | When the edge is blocked; `null` in a `recovered` ACK |
| `handlers_closed_at` | Target | When, after the fence, the target verified zero affected handlers and no sendable queued frame, including when that was already true; `null` in a `recovered` ACK |
| `recovered_at` | Target | When a restarted target applied the snapshot; only in a `recovered` ACK, and only an upper bound on the close |
| `notice_suppressed_at` | Gateway | When the Gateway skipped the notice: its generation was replaced, or the target's lock stayed taken |
| `acknowledged_at` | Gateway | On the first valid ACK of that revision |
| `superseded_at` | Gateway | When enrolment started a new generation before the row was acknowledged |

The proof reports fault to detection, last confirmed probe send to fence, commit to fence, and fence to ACK, with maximum values for idle and busy runs. It records the clock offset bounds between Gateway and target, from `server_time` and the probe round trip.

### Preflight

The preflight ran in the group's task copy against its nested fleet, with a disposable container on the nested `app-dev` Node. It showed network identity only:

- A plain TCP listener in the container, not Rust Conn, saw four distinct caller addresses through the NAT-mode proxy.
- That path needed the per-container route rule that Orbit adds for a T3 port; the lab added it by hand.
- The Gateway saw the container's outbound requests as the serving Node.
- TLS to Gateway and Reverb verified their names against the lab Orbit CA.

It did not show credential isolation. It checked selected environment variable names, `/etc/orbit` and a listing of `/root` without hidden files. It did not check hidden files, `/home/agent` or mounted inputs.

It proved no enforcement. The nested fleet had no Conn registration and one edge, from the operator Node to the Gateway Node, so no second clean caller and no direct edge to the serving Node. The copy had no Rust toolchain, so no Conn target ran.

### Gateway receipts

Two runs on 2026-11-18 make up these receipts, both in task copy `task-5868` (Instance 3705) against its nested fleet. In each, the nested Gateway served the copy's checkout with no local changes and the switch on for the run only.

| Run | Commit | Cases |
| --- | --- | --- |
| 17:09 UTC | `9839be03601b` | Revocation with two streams, rotation, and the ACK identity checks |
| 16:54 UTC | `514a9d7c99dd` | Dropped notice, lost ACK and restart, silent loss |

Both trees hold this task's changes on top of `main` as merged at `5e470c768`, including that merge's other changes under `apps/gateway`. A later merge from `main` is in neither run. Between the two commits only notice publication and enrolment changed: the per-target lock. The 17:09 run repeated every case that publishes and checks a notice against a rotation, or rotates. The 16:54 cases measure probes, ACKs and recovery, whose code did not change. Commits after `9839be03601b` in this task change only documentation.

The target was a scripted Bun process on the nested `app-dev` Node, not Rust Conn. It drove the real routes with a 1 s probe and a 3 s lease, and served a small managed ingress with RPC and long streams of one frame every 200 ms. The preflight's fixture container was gone, so the script ran on the serving Node's host. All machines share one host clock, so the times compare directly. Target times have millisecond resolution; caller times have microseconds.

The serving Node was Node 3 (`app-dev`, 10.45.0.2), with a lab environment row that has no T3 server. Caller A was Node 4 (`app-prod`, 10.45.0.3). Caller B was Node 2 (`operator`, 10.45.0.10), the copy itself; it also holds the operator's edge to the Gateway Node. Caller C was Node 1, the Gateway Node, with no direct edge. Edges were added and removed through `node:access:add` and `node:access:remove`.

#### Commit and fence

This is the 17:09 run, target 3. The Gateway brackets each commit: `committed_at` is sampled inside the transaction before its writes, and `commit_observed_at` in the after-commit callback. Each interval below is measured from both bounds, from `commit_observed_at` first.

Revision 1 removed A's edge 17 while A and B held streams. The commit lies between 34.414810 and 34.416589. Then:

| Step | Time | After the commit |
| --- | --- | --- |
| Notice received, fence | 34.426 | 9.4 to 11.2 ms |
| A's client reads end of stream, after its frame 14 at 34.305426 | 34.426807 | 10.2 to 12.0 ms |
| Reverb publish returned, `notice_sent_at` | 34.427937 | 11.3 to 13.1 ms |
| Verified handler close, from the verified `delta` | 34.466 | 49.4 to 51.2 ms |
| ACK settled | 34.498881 | 82.3 to 84.1 ms |

B's client received frame 15 at 34.432967, 7.0 ms after A's fence. It kept receiving frames up to frame 44 at 40.234936, 30 frames after the fence, until its client timeout. B's token still introspected. A's old token got `conn.managed_token_invalid`. A new token request got `conn.managed_direct_edge_required`, as did caller C.

#### Rotation

This is the 17:09 run. Revision 2 removed A's edge 19 while the target sent no ACK. Its notice was sent at 42.092360. Enrolment then rotated the target to generation 2 and recorded `superseded_at` at 45.393921; the row keeps its `notice_sent_at`. The old process's next probe, 173 ms after enrolment returned, got `conn.managed_target_identity`. It failed closed, and B's client read the end of its stream 1 ms later. Generation 2 started with `owed: []`. B's generation 1 token got `conn.managed_token_invalid`, and a new token was admitted.

An ACK of revision 2, a generation 1 revision, after the rotation:

| Credential | Requested generation | Response |
| --- | --- | --- |
| Generation 1 | 1 | 403 `conn.managed_target_identity` |
| Generation 1 | 2 | 403 `conn.managed_target_identity` |
| Generation 2 | 1 | 409 `conn.managed_generation_mismatch`: the request names a replaced generation |
| Generation 2 | 2 | 409 `conn.managed_generation_mismatch`: the revision belongs to a replaced generation |

#### Recovery

This is the 16:54 run, target 2. Adding A's edge again gave the new edge ID 14. The old token stayed refused, and a new token was admitted on edge 14.

For revision 2 the target dropped the notice. Its probe fenced, closed and verified at 36.378, 5.2 to 8.8 ms after the commit, because a probe happened to leave just after it. The `observed` ACK settled at 36.408950 with `notice_received_at` `null`.

For revision 3 the target sent no ACK, and the row stayed owed. After a restart, the snapshot listed `owed: [3]`, and the `recovered` ACK settled 64 ms later. An exact retry returned the first result. An ACK of revision 99 got `conn.managed_ack_unknown`.

#### Authority loss

The rotation above was a clean loss.

For a silent loss in the 16:54 run, the serving Node dropped its traffic to the Gateway at 59.361340. The target failed closed at 01.730, 2.369 s after the fault and 30 ms after its lease ended. B's client read the end of its stream at 01.731164. A snapshot recovered the target at 05.773, 0.321 s after the fault ended. B came back only through a fresh introspection.

In each run, the teardown removal of B's edge committed a last revision and sent its notice. The target was already stopped, so that row stays owed: enforcement unconfirmed.

Each teardown removed both lab edges, the target and environment rows, every lab credential and token file, the packet filter and the switch. It turned Conn off again in the nested Gateway. No live Node, edge or grant changed.

The Rust target proof still has to show:

- the Rust fence on real RPC, terminal and preview streams;
- media tickets, including a stale one;
- reconnect of a Rust RPC or media session after revocation;
- the managed ingress refusing native credentials;
- the caller's address, seen through the NAT-mode proxy from a container;
- target egress through a product route instead of the serving host;
- a second caller that holds no Gateway edge, beside an unrelated one;
- fence, close and ACK times under busy load.

### Limits

These limits come from the preflight and from paths this slice does not cover.

#### Ingress

A caller on the serving Node shows as the serving Node. A path through Caddy, the egress proxy or a relay hides the caller, so managed tokens refuse it. A Rust Conn server behind the NAT-mode proxy must still show that it sees the caller's address on RPC and media.

#### Target egress

The Gateway sees a container on its bridge as its serving Node. Other containers on that Node share this address; only the service credential tells them apart.

No product route lets an Instance container reach the Gateway or Reverb today. The container ACLs reject the traffic, and the egress proxy refuses private addresses. The lab admits port 443 of Gateway and Reverb for the target alone, by a lab-only ACL and forward rule. The product route belongs to the container network owners.

#### Other paths

Node removal, disable, registration reassignment and Gateway authority bypass this transaction. A later slice covers them. Stock T3, its device proxy and relay, and host servers cannot claim this guarantee. Legacy held, phone-paired and peer-linked credentials stay as they are on unmanaged paths.

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
| `PATCH /profiles/{id}/settings` | `{"sections": {"appearance": {"version": 2, "value": {...}}, "devices.iphone": {"version": 0, "value": null}}}`; any of `workspaces`, `appearance`, `attachedThreads`, `sidebarHiddenThreads`, `devices.<type>` | settings |
| `GET /projects` | — | list of project |
| `GET /projects/{id}/instances/check` | `name` query string | name, available, reason, domain |
| `POST /projects/{id}/instances` | `{"name": "fix-login-k3j9"}` | Operation (202) |
| `GET /instances/{id}/work` | — | workspace work state |
| `POST /instances/{id}/destroy` | `{"acknowledge_unsaved": true}` and `Idempotency-Key` | Operation (202) |
| `POST /instances/{id}/complete` | no body; `Idempotency-Key` | Operation (202) |
| `POST /launches` | [Launch request](#admit-and-replay) and `Idempotency-Key` | [Launch snapshot](#launch-snapshot) (202) |
| `GET /launches` | — | List of launch snapshots, newest first |
| `GET /launches/{operation_id}` | — | Launch snapshot |
| `POST /launches/{operation_id}/cancel` | No fields | Launch snapshot (202) |
| `POST /launches/{operation_id}/retry` | `{ "target": "launch" }` or `{ "target": "application" }` | Launch snapshot (202) |
| `GET /environments` | — | list of environment |
| `POST /environments` | `{"environment_id", "label", "url", "admin_session"}` | environment |
| `GET /environments/{environment_id}/pairings` | `include_held` query, optional | list of pairing, newest first |
| `GET /environments/{environment_id}/session` | — | held session (200), or Operation (202) |
| `POST /environments/{environment_id}/pairings` | `{"renew": true}`, optional | Operation (202) |
| `POST /environments/{environment_id}/pairings/revoke` | `{"node_id": 4, "pairing_id": 7}`, both optional | Operation (202) |
| `POST /environments/{environment_id}/renew` | — | Operation (202) |
| `GET /threads` | `project`, `instance` query, both optional | list of thread, and `meta.environments` |
| `POST /environments/{environment_id}/threads` | `{"thread_id": "a1b2", "title": "Fix the login"}` | thread (201, or 200 when recorded already) |
| `GET /tasks` | `project`, `instance`, `status`, `limit`, `cursor` query, all optional | list of task row, and `meta.next_cursor` ([Tasks panel](#tasks-panel)) |
| `GET /tasks/{id}` | — | task ([Tasks panel](#tasks-panel)) |

The objects:

```json
{
  "node": {"id": 4, "name": "phone", "wireguard_ip": "10.44.0.77", "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"}},
  "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"},
  "settings": {"profile_id": 1, "version": 3, "settings": {"workspaces": []}, "sections": {"workspaces": {"version": 1, "updated_at": "2026-10-08T10:00:00+00:00", "updated_by": "phone"}}, "updated_at": "2026-10-08T10:00:00+00:00"},
  "environment": {"environment_id": "env-beast", "label": "beast", "url": "http://10.44.0.30:3773", "server_version": "0.9.1", "registered_by": "beast", "registered_at": "2026-10-08T09:00:00+00:00", "admin_session_expires_at": "2026-11-07T09:00:00+00:00", "status": "registered", "instance_id": null, "power_state": null, "project_id": null, "project_name": null, "project_slug": null, "instance_name": null, "node_name": null, "instance_url": null, "renewal_error_code": null, "renewal_checked_at": null},
  "project": {"id": 3, "slug": "mealou", "name": "Mealou", "node_id": 7, "node_name": "beast", "instance_domain_suffix": ".mealou.test", "cloneable": true, "cloneable_reason": null, "checks": [{"key": "default_instance", "label": "Default Instance", "status": "pass"}, {"key": "container", "label": "Runs in a container", "status": "pass"}, {"key": "active", "label": "Active", "status": "pass"}, {"key": "seed_snapshot", "label": "Seed snapshot", "status": "pass"}, {"key": "node_access", "label": "Node access", "status": "pass"}, {"key": "source_access", "label": "Source access", "status": "pass"}, {"key": "source_defaults", "label": "Source defaults", "status": "pass"}, {"key": "production_placement", "label": "Development placement", "status": "pass"}, {"key": "node_active_linux", "label": "Active Linux Node", "status": "pass"}, {"key": "app_dev_role", "label": "App-dev role", "status": "pass"}, {"key": "source_profile", "label": "Source metadata", "status": "pass"}, {"key": "php_readiness", "label": "PHP readiness", "status": "pass"}, {"key": "runtime_contributions", "label": "Conn runtime", "status": "pass"}, {"key": "model_route", "label": "Model router", "status": "pass"}]},
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

Every call is recorded as command activity with the calling Node. A registration records its environment id, label, and URL, never the admin session. A settings write records only section names and versions, because the document can hold workspace pictures, themes, and device values. A replace records the document version it replaced and the names of the sections it sent. A patch records each section name with the version the client sent. A thread record records the thread id, never the title. The thread list and the Tasks panel are reads and record no activity.

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

### Why hidden threads are their own section

Hiding is a display choice, and attaching decides which servers a device connects to. Hiding or showing a thread never changes the connection state: a hidden mark cannot keep a container server connected after its last attachment goes, and a host server stays connected with no attached thread.

With one list for both, hiding a thread could disconnect a container server if that thread were its last attachment, and a device could not hide a thread without the Gateway's attachment check. So the hidden list is a section of its own, with its own version. A hide on one device never conflicts with an attach on another. It grants nothing, so the Gateway checks only its form.

A `null` keeps the section as an empty list rather than removing it. A removed section would read like a section that was never written, and a client that showed its last hidden thread could not tell its write from an old profile.

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

Conn callers follow their own pairing Operations at `GET /api/v1/conn/operations/{operation}` and cancel them at `POST /api/v1/conn/operations/{operation}/cancel`; the same endpoints serve a clone's create and completion ([Cancel a create or a completion](#cancel-a-create-or-a-completion)). These endpoints check the original requester, including when a revocation names another Node. They expose no other Operation or Gateway authority.

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
