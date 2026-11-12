---
title: "Conn layer"
description: "How the Gateway signs in Conn devices as Nodes, shares workspaces, appearance, and per-device settings through profiles, holds an admin session for each registered T3 server, and mints and revokes device pairings."
covers:
  - apps/gateway/extensions/conn/**
  - swamp/extensions/behaviors/operations/conn-revocation/**
  - swamp/extensions/behaviors/operations/conn-pairing/**
  - swamp/workflows/workflow-operation-conn-pairing-create.yaml
  - swamp/workflows/workflow-operation-conn-pairing-revoke.yaml
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
  - apps/gateway/database/migrations/2027_02_03_000001_add_instance_to_conn_environments.php
---

# Conn layer

This page is for the person who builds the Conn client, and for the Gateway maintainer. Conn is the fork of T3 Code that works with Orbit. The Gateway gives Conn one central layer: a device signs in as its Node, sees every registered T3 server, and shares workspaces, appearance, and device settings with the other devices of its profile. T3 traffic does not pass through the Gateway. A device talks to each T3 server directly over WireGuard.

The Gateway answers lists, profiles, and pairing. It mints T3 pairing links with an admin session that each T3 server hands it at registration, and it revokes the sessions it paired.

The Conn layer is the `conn` [extension](/reference/extensions). Its code lives in `apps/gateway/extensions/conn`. While the switch is off, every endpoint below refuses with `extension.disabled` (409), including registration by a T3 server, and the MCP server hides the Conn tools. Profiles, servers, and pairings stay. New installs start with the switch off. Upgrades preserve its stored value. Activity keeps admin sessions and settings documents out through the `ActivityInputRedaction` plug-in point, also while the switch is off.

## Identity

A caller is the active Node that owns its WireGuard address, as for every other Gateway API call. Phones and laptops are Nodes too, without roles, much like an operator Node. A caller that is not an active Node gets `peer.identity_unknown` (403).

## Access

Conn uses the existing [node access](/cli/node/access-add) edges. It has no grant of its own. A T3 server belongs to the Node that registered it, so a server in a container counts as its host Node's server when that Node registers it.

| Endpoints | The caller needs |
| --- | --- |
| Server list, pairing list, show, create and revoke | Node access to the Node that registered the server. The list shows only those servers. |
| `GET /me`, `PUT /me/profile`, profiles and settings, Conn Operation show and cancel | Node access to at least one Node: the [collection rule](/cli/node#authority) that `node:list` uses. |
| Project list | Node access to at least one Node. The list shows only the Projects whose default Instance runs on a Node the caller has node access to. |
| Thread start, `POST /projects/{id}/instances` | Node access to the Node of the Project's default Instance. |
| `POST /environments`, registration | No edge. |

The Gateway Node, its serving host, and a Node with an edge to the Gateway Node have access to every Node, so they reach every server. When a server's Node was removed, only those callers reach it. A refused call gets `node_access.required` (403), with `details.consumer_node` and, for a server, `details.serving_node`.

For example, a phone with an edge to `beast` sees and pairs with the servers that `beast` registered, and no others. A [task VM](/reference/compute-drivers#placement-invariant) Node never holds an edge, so it can use no Conn endpoint except registration.

### Registration stays with its Node

The calling Node becomes the server's Node. Once a Node registered an environment id, another Node cannot register it again: it gets `conn.environment_owned` (409). When the Node is removed, the server has no Node. Then only a Node with Gateway access can register it again, which makes that Node the server's Node.

### Removing an edge

Removing an edge does not revoke sessions that the consumer already paired. Revoke them with `POST /environments/{environment_id}/pairings/revoke` and the consumer's `node_id`.

## Profiles

A profile is a named set of shared settings, such as "Nick". A Node belongs to at most one profile. On first connect the device lists the profiles, lets the user pick or create one, and binds to it. Later calls read the binding from `GET /api/v1/conn/me`. Every Node that may use profiles ([Access](#access)) sees every profile.

A profile holds one settings document. Its keys follow Conn's stores, so they stay camelCase. The document has three kinds of section:

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
  }
}
```

A section is `workspaces`, `appearance`, or `devices.<type>`. The document accepts no other top-level key. Only `workspaces` is always present; a section that no device wrote is absent.

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

A device type is 2 to 24 lowercase letters, digits, and hyphens, and starts with a letter, such as `desktop` or `iphone`.

A theme slot has a required `id` and a required `source`: `stock`, `builtin`, `custom`, or `published`. It may have a `name`, an `appearance` (`light` or `dark`), and `colors`. A `stock` or `builtin` slot needs only its id. A `custom` or `published` slot carries its resolved `colors`, so another device can draw a theme it does not have.

A custom theme has a `name`, an `appearance`, `colors`, and an `id` that is unique in the list. It may have `variants.light` and `variants.dark`, which are color maps.

A color map has at most 80 entries. A key is a color role: a letter, then letters and digits, at most 64 characters. A value is a string of at most 64 characters from `#A-Za-z0-9(),.%/ -`, so it cannot carry markup or a url.

A device `values` map has camelCase keys of at most 64 characters. A value is a boolean, a number, `null`, a string of at most 512 characters, or a list of at most 32 of those scalars. The Gateway checks the envelope and the value types. Each client checks its own keys and keeps the ones it does not know.

An empty object is refused wherever a map is expected, such as `colors`, `values`, or the `devices` map of a `PUT`. PHP cannot tell `{}` from `[]`, so the Gateway would hand it back as a list. Leave the key out. The whole document is at most 4 MB. The request stack trims every string and turns an empty string into `null`. A patch section `value` is the exception: an empty string there is refused, as [Versions](#versions) says.

A workspace covers its `projectRefs` and every project whose logical key is in `projectKeys`, as Conn combines them. T3 derives a logical key from the git remote by default, so devices that keep T3's default repository grouping agree on it.

### Versions

The document has a version, and so has each section. A new profile starts at document version 1 with no workspaces. Every write adds 1 to the document version. A write also adds 1 to the version of each section it changed. A section with no recorded version is at version 0. This includes a section written before sections had versions.

`GET /profiles/{id}/settings` returns `sections`: for each section that was written, its `version`, the time of the last write in `updated_at`, and the name of the Node that wrote it in `updated_by`. A section that is not listed is at version 0.

There are two ways to write. Prefer `PATCH`.

`PATCH /profiles/{id}/settings` changes the sections it holds, each with the version the client last read. It never touches a section it does not name. So a phone font change does not conflict with a Mac theme change.

`PUT /profiles/{id}/settings` replaces the sections it holds and keeps the others. It checks the document version, as it always did. `devices` replaces every device type, and the types it leaves out are removed. It must hold at least one type. To remove the last type, patch it to `null`.

A patch whose section is at another version is refused with `conn.settings_version_conflict` (409), and the Gateway writes nothing. `details.current_version` is the document version. `details.sections` holds the current version of each section the request named. The client reads again, merges the sections that changed, and retries.

A `value` of `null` removes `appearance` or a device section. Its version still goes up, so an old client cannot write it back. `workspaces` cannot be `null`: send `[]`. Only an explicit `null` removes a section. An empty or blank string is refused with `validation.failed` (422), and the error names the field, such as `sections.appearance.value`.

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
- The bumped revision also stops a Node's registration of the same id that read the row before the record: that registration compares the revision again in its lock and fails with `conn.environment_changed`.
- While Conn is off, a clone is refused with `operation.kind_unavailable`: nothing would record its server.
- A create admitted before Conn went off still records its server.

Destroy keeps this contribution available while Conn is disabled. Admission captures its environment. The run deletes T3 with the container, retiring all sessions and unused links. Terminal projection deletes its execution copies, environment and pairings before core deletes the Instance. New pairings refuse an Instance marked removing.

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

Conn follows that Operation with `GET /operations/{id}`. Conn Operation show also shows an `instance.create` that the caller started, here or with `instance:create`. Conn Operation cancel does not cancel it.

Operation show returns the same recorded `steps` as core Operation show, in recorded order: `name`, `job`, `status`, `started_at` and `finished_at`. Steps contain no command output, secrets or environment values. Both endpoints accept `after_sequence` but currently return all recorded steps.

Conn maps these stable container-clone step names to stage detail, in this order: `clone-container`, `clone-workspace`, `clone-t3`, `clone-register`. Other steps can occur between them. Renaming or removing any of these four names is a breaking change for Conn.

A server of an Instance names its `instance_id` and the container's `power_state`, `running` or `stopped`. Other servers have `null` for both. Conn shows a `stopped` server as asleep.

## Pairing and revocation

A device asks the Gateway for a pairing link to one registered T3 server. The native Swamp pairing step sends `POST <url>/api/auth/pairing-token` with its admin session, a unique label, such as `phone via Orbit k3j9x0qa`, and T3's administrative scopes (see below). T3 gives the session paired from that link the same client label. The Gateway records the pairing: the Node, the server, the T3 link id, the label, and the link's expiry.

The device opens the returned `pairing_url` and pairs as it does today, but without a manual step. The link is one-time and short-lived, so the device pairs right away.

To revoke, Orbit reads the initial `payload.clientSessions` snapshot from upstream T3’s `subscribeAuthAccess` WebSocket RPC at `/ws?orchestrationProtocol=2`, authenticated with the same admin bearer session. This path serves both stock T3 container servers and Conn host servers. It revokes each session whose label belongs to the Node's open pairings on that server with `POST <url>/api/auth/clients/revoke`, and it revokes each pairing link that was not used with `POST <url>/api/auth/pairing-links/revoke`. It never revokes its own current session. The device loses that server at once; its WireGuard access does not change.

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
| `PATCH /profiles/{id}/settings` | `{"sections": {"appearance": {"version": 2, "value": {...}}, "devices.iphone": {"version": 0, "value": null}}}` | settings |
| `GET /projects` | — | list of project |
| `GET /projects/{id}/instances/check` | `name` query string | name, available, reason, domain |
| `POST /projects/{id}/instances` | `{"name": "fix-login-k3j9"}` | Operation (202) |
| `GET /environments` | — | list of environment |
| `POST /environments` | `{"environment_id", "label", "url", "admin_session"}` | environment |
| `GET /environments/{environment_id}/pairings` | — | list of pairing, newest first |
| `POST /environments/{environment_id}/pairings` | — | Operation (202) |
| `POST /environments/{environment_id}/pairings/revoke` | `{"node_id": 4, "pairing_id": 7}`, both optional | Operation (202) |

The objects:

```json
{
  "node": {"id": 4, "name": "phone", "wireguard_ip": "10.44.0.77", "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"}},
  "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"},
  "settings": {"profile_id": 1, "version": 3, "settings": {"workspaces": []}, "sections": {"workspaces": {"version": 1, "updated_at": "2026-10-08T10:00:00+00:00", "updated_by": "phone"}}, "updated_at": "2026-10-08T10:00:00+00:00"},
  "environment": {"environment_id": "env-beast", "label": "beast", "url": "http://10.44.0.30:3773", "server_version": "0.9.1", "registered_by": "beast", "registered_at": "2026-10-08T09:00:00+00:00", "admin_session_expires_at": "2026-11-07T09:00:00+00:00", "status": "registered", "instance_id": null, "power_state": null, "project_id": null, "project_name": null, "project_slug": null, "instance_name": null, "node_name": null, "instance_url": null},
  "project": {"id": 3, "slug": "mealou", "name": "Mealou", "node_id": 7, "node_name": "beast", "instance_domain_suffix": ".mealou.test", "cloneable": true, "cloneable_reason": null, "checks": [{"key": "default_instance", "label": "Default Instance", "status": "pass"}, {"key": "container", "label": "Runs in a container", "status": "pass"}, {"key": "active", "label": "Active", "status": "pass"}, {"key": "seed_snapshot", "label": "Seed snapshot", "status": "pass"}, {"key": "node_access", "label": "Node access", "status": "pass"}, {"key": "source_access", "label": "Source access", "status": "pass"}, {"key": "source_defaults", "label": "Source defaults", "status": "pass"}, {"key": "production_placement", "label": "Development placement", "status": "pass"}, {"key": "node_active_linux", "label": "Active Linux Node", "status": "pass"}, {"key": "app_dev_role", "label": "App-dev role", "status": "pass"}, {"key": "source_profile", "label": "Source metadata", "status": "pass"}, {"key": "php_readiness", "label": "PHP readiness", "status": "pass"}, {"key": "runtime_contributions", "label": "Conn runtime", "status": "pass"}, {"key": "model_route", "label": "Model router", "status": "pass"}, {"key": "native_create", "label": "Create support", "status": "pass"}]},
  "pairing": {"id": 7, "environment_id": "env-beast", "node_id": 4, "node_name": "phone", "client_label": "phone via Orbit k3j9x0qa", "issued_at": "2026-10-08T10:00:00+00:00", "expires_at": "2026-10-08T10:05:00+00:00", "revoked_at": null}
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
| `validation.failed` | 422 | The request breaks a rule; `details` names each field. |
| `conn.settings_version_conflict` | 409 | The document, or a section a patch names, changed since the sent version. `details.current_version` is the document version; a patch adds `details.sections`, the current version of each section it named. |
| `conn.environment_mismatch` | 422 | `url` serves another environment. |
| `conn.admin_scope_missing` | 422 | The registered session is not an admin session. |
| `conn.session_expired` | 409 | The Gateway's admin session on that server expired. |
| `conn.server_unreachable` | 502 | The T3 server did not answer. |
| `conn.session_rejected` | 502 | T3 does not accept the admin session: it answered 401, or `/api/auth/session` reported it unauthenticated. |
| `conn.request_refused` | 502 | The T3 server refused the call with another status; `details` has its status and reason. |
| `conn.response_invalid` | 502 | The T3 server's answer did not have T3's shape. |

Every call is recorded as command activity with the calling Node. A registration records its environment id, label, and URL, never the admin session. A settings write records only section names and versions, because the document can hold workspace pictures, themes, and device values. A replace records the document version it replaced and the names of the sections it sent. A patch records each section name with the version the client sent.

## Why it works this way

The T3 Connect relay could also be self-hosted. It needs four outside services and puts Cloudflare in the traffic path, and it has no place for workspaces. The Gateway already knows every machine and runs on the private network, so it adds no new service.

The Gateway uses T3's own admin sessions and pairing instead of a new T3 auth scope. The host issues the admin session with the stock `t3` CLI, so servers run unmodified upstream T3, and the Gateway needs no SSH access to them. The cost is that the Gateway holds full admin access to every registered T3 server. On a private WireGuard network where the Gateway already manages every machine, that adds little new risk.

A device pairing link carries T3's administrative scopes, including `access:read` and `access:write`, which T3 lets an admin session grant. The servers are headless, so the device is the only place to manage a server's linked environments and to answer an agent's environment-link request. Each paired device therefore gets admin access to the server, the same trust the Gateway already holds. Sessions paired before this change keep their old scopes until the device pairs again.

### Why Conn uses node access

A pairing gives a device admin access to a T3 server, and the server runs agents with the rights of its host. So pairing with a server is acting on its Node, which is what a node access edge allows. Every task VM is an active Node with its own WireGuard identity; without a check, an agent inside one could mint an admin pairing to any server. Task VMs hold no edges, so they are refused, and no second grant system is needed.

The server is keyed on the Node that registered it, not on the host of its URL. A container's server is registered by its host Node, and a URL can name a forwarded port, so the URL does not identify the Node.

A registration cannot move an environment id to another Node. Otherwise any Node could register a server of its own under a known id and send devices that pair with it there. The same holds for a server whose Node was removed, so taking it over needs Gateway access. The ownership check reads the same row that the registration transaction locks and compares, so a concurrent registration by another Node fails with `conn.environment_changed`.

Removing an edge does not revoke paired sessions. The `node:access:remove` command lives in core, which cannot call an extension, and a hook for it is a plug-in point of its own.

The Node is the identity because the Gateway already knows every WireGuard peer as a Node, phones included, and WireGuard binds each address to one key. Revocation happens only in T3 and leaves WireGuard access alone, so a revoked device keeps its other servers and Orbit access.

A Node belongs to one profile because each device is its own Node: a profile is how a phone and a desktop share one copy of the workspaces. Settings use a version check rather than last-write-wins, so an edit on one device is never lost silently to an older copy on another.

### Why sections have their own versions

The devices of a profile edit different things at different times. With one version for the whole document, a font change on the phone would conflict with a theme change on the Mac. Every client would then merge the entire document to resolve it. With a version per section, only two edits of the same section conflict.

Each device type has its own section for the same reason: a phone and a desktop never conflict over values that belong to one of them. A patch is all or nothing, so a client that changes the mode and a theme in one step never leaves them half applied.

The Gateway stores the versions apart from the document, in `section_meta` on the profile. A profile from before sections has no entry, so every section of it is at version 0, and the first patch of a section sends version 0.

`PUT` stays for clients that only know workspaces. It replaces just the sections it sends, so such a client cannot erase the appearance or device settings that a newer client wrote.

## Session cleanup

Pairing revocation captures its exact pairing IDs, client labels, link IDs, issue windows, Node source IP and T3 URL at admission. The optional API and MCP `pairing_id` targets one pairing, including a revoked pairing; it must belong to the selected Node and environment. Without it, only open pairings are selected. Overlapping issue windows for that Node and environment refuse with `conn.revocation_ambiguous` before any revoke. Native fan-out uses fixed behavior names, and the Gateway admits one revocation per T3 environment. All native steps run on Node 209.

The Gateway sends the existing admin session through the shared execution-copy helper; workflow input holds only the vault and key names. Swamp reads the T3 session list once. Conn sessions retain the exact pairing label on the `one-time-token` subject. CLI-linked sessions use `mcp-client`: Orbit selects every non-current session with that subject, the exact Node WireGuard source IP, and an `issuedAt` inside the pairing’s issue-start to expiry window. 

Stock T3 0.1.7 stores no session-to-pairing key, so this bounded rule cannot prove token provenance. Orbit revokes selected sessions and pairing links in separate native steps and preserves the current admin. An empty pairing selection, no matching sessions, or no session actually revoked fails with `conn.revocation_unmatched` and keeps the pairing rows unchanged. A cleared credential proves delivery, not redemption; this check is conservative. A failed read is a failure, never an empty inventory. The native terminal check and captured public reply metadata project only the admitted pairings. Valid link completions are recorded even when a later link fails.

Steps make no Gateway calls.

### Gateway session cleanup

Gateway session cleanup runs the shared native revocation workflow on Node 209 for the fixed Gateway client label. It captures the T3 URL, registration revision and vault reference at admission, with no step-to-Gateway callback. The child lists sessions once and keeps the current admin and every other label. Failed HTTP or reply checks report `conn.revocation_failed`; the revision stays pending.

Registration stores the new encrypted admin session and increments its cleanup revision. After that transaction commits, the Gateway admits `conn.gateway_sessions_revoke` once. Registration keeps its normal response if admission or cleanup fails. A failed start records a safe warning, and the revision stays pending. No scheduled publisher scans registrations. Cleanup keeps the current admin session and other client labels.

Registration refuses with `operation.target_busy` while a cleanup or pairing revocation holds that environment. Session validation runs outside the database transaction; a concurrent revocation or registration refuses with `conn.environment_changed` before storing the candidate session. The list and registration replies expose the non-secret `cleanup_version`. The environment lock prevents overlapping revocations. A failed revision stays pending without another automatic Operation; a new registration or an explicit cleanup request permits a new attempt. A completed revision returns HTTP 204 without starting a run. Cleanup never discards the current admin session.

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

`conn.pairing_create` starts `operation-conn-pairing-create` with full native inputs and a fixed terminal selector. Pairing revocation uses `operation-conn-pairing-revoke`. Gateway session cleanup retains `operation-conn-direct` and its current-registration checks; that is a separate kind. Existing profile and registration reads remain local Gateway behavior.

Core sensitive resources provide protected generated outputs without a new custody protocol. Official core commands and vaults, plus the official extension catalogue, were checked first. There is no official T3 pairing API integration. The single typed HTTP action is needed to validate the provider response and mark the generated credential sensitive before native persistence. There is no custom transport or subscriber.

When a terminal result repeats, Conn returns the recorded result even after the pairing credential has been consumed or revoked. Conn projects bounded public metadata without a Kit execution contract. Native dispatch failures record a safe failure without changing pairing or cleanup state.


### Native pairing revocation

Swamp core command steps and native `forEach` supply the execution. Official SSH, cfgmgmt, datastore and cloud-vault extensions were checked at `d6f7afdcd6d04a63fdbc6ed56c403832770f67a6`; they do not implement T3 session APIs.

Session inventory on 209 uses a bounded WebSocket reader (`timeout 15 deno run`) that subscribes to `subscribeAuthAccess` and closes after the initial snapshot. Each revoke step makes one `curl` request to the existing T3 API.

curl reads the admin session from the step environment with `--variable` and `--expand-header`, so it never enters argv. curl writes each raw reply into a private worker directory. The `@nckrtl/conn-revocation-plan` data methods read the session list and revoke replies there; they publish only selected request bodies, valid completion indices and counts. The extension ships one bounded reader for T3’s auth snapshot. There is no custom transport, authentication protocol, Gateway relay or runtime restart. Since this is an API integration, no worker or SSH step runs on the subject Node.

Session revocations finish before unused-link revocations begin. A failed list or invalid session reply blocks link effects. The initial list preserves the current admin session. Duplicate runtime ownership checks are removed under rule 22. A valid `revoked: false` reply means the session or link is already absent. The native end check requires valid replies for all actions; declared cleanup removes the private directory after failure too.

After terminal cleanup, repeat the command to reconcile incomplete revocations. Without `pairing_id`, the new Operation captures only open pairings; with it, the Operation selects that exact pairing even if already revoked. It reads the current session list; already-absent sessions are not selected. Cleanup removes private replies even after failure, so resuming directly at an HTTP step cannot reuse its deleted scratch directory. A terminal Gateway Operation stays terminal after a manual native resume. Revocation requests are idempotent and never mint credentials.

The public terminal result contains only `backend` and `revoked_sessions`, or a bounded `conn.*` error code. The admin token, server response bodies and other client data never enter stdout or workflow resources.
