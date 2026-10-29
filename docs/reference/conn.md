---
title: "Conn layer"
description: "How the Gateway signs in Conn devices as Nodes, shares workspaces, appearance, and per-device settings through profiles, holds an admin session for each registered T3 server, and mints and revokes device pairings."
covers:
  - apps/gateway/extensions/conn/**
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Conn layer

This page is for the person who builds the Conn client, and for the Gateway maintainer. Conn is the fork of T3 Code that works with Orbit. The Gateway gives Conn one central layer: a device signs in as its Node, sees every registered T3 server, and shares workspaces, appearance, and device settings with the other devices of its profile. T3 traffic does not pass through the Gateway. A device talks to each T3 server directly over WireGuard.

The Gateway answers lists, profiles, and pairing. It mints T3 pairing links with an admin session that each T3 server hands it at registration, and it revokes the sessions it paired.

The Conn layer is the `conn` [extension](/reference/extensions). Its code lives in `apps/gateway/extensions/conn`. While the switch is off, every endpoint below refuses with `extension.disabled` (409), including registration by a T3 server, and the MCP server hides the Conn tools. Profiles, servers, and pairings stay. New installs start with the switch off. Upgrades preserve its stored value. Activity keeps admin sessions and settings documents out through the `ActivityInputRedaction` plug-in point, also while the switch is off.

## Identity

A caller is the active Node that owns its WireGuard address, as for every other Gateway API call. Phones and laptops are Nodes too, without roles, much like an operator Node. A caller that is not an active Node gets `peer.identity_unknown` (403).

This slice has no grants. The Conn endpoints need no node access edge: every active Node may read and change every profile, register a T3 server, mint a pairing link, and revoke any Node's sessions.

## Profiles

A profile is a named set of shared settings, such as "Nick". A Node belongs to at most one profile. On first connect the device lists the profiles, lets the user pick or create one, and binds to it. Later calls read the binding from `GET /api/v1/conn/me`. Every Node sees every profile.

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

1. The Gateway reads `GET <url>/.well-known/t3/environment`. The served `environmentId` must equal `environment_id`, or the request fails with `conn.environment_mismatch` (422) before the session is sent.
2. It reads `GET <url>/api/auth/session` with the session as its bearer token. A session T3 does not accept fails with `conn.session_rejected` (502).
3. The session must carry `access:read` and `access:write`. `t3 auth session issue` grants both. A session without them fails with `conn.admin_scope_missing` (422).
4. The Gateway stores the session encrypted, with the expiry T3 reports. T3 sessions last 30 days by default.
5. It lists the server's client sessions and revokes every earlier `Orbit Gateway` session. A failure here does not fail the registration. This is why the job must issue the session with the label `Orbit Gateway`.

Registering again replaces the stored session. The job registers on each server start and before the session expires. An environment whose session expired shows `status: session_expired` and cannot mint pairings until it registers again.

## Pairing and revocation

A device asks the Gateway for a pairing link to one registered T3 server. The Gateway calls `POST <url>/api/auth/pairing-token` with its admin session, a unique label, such as `phone via Orbit k3j9x0qa`, and T3's administrative scopes (see below). T3 gives the session paired from that link the same client label. The Gateway records the pairing: the Node, the server, the T3 link id, the label, and the link's expiry.

The device opens the returned `pairing_url` and pairs as it does today, but without a manual step. The link is one-time and short-lived, so the device pairs right away.

To revoke, the Gateway lists `GET <url>/api/auth/clients`. It revokes each session whose label belongs to the Node's open pairings on that server with `POST <url>/api/auth/clients/revoke`, and it revokes each pairing link that was not used with `POST <url>/api/auth/pairing-links/revoke`. It never revokes its own current session. The device loses that server at once; its WireGuard access does not change.

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
| `GET /environments` | — | list of environment |
| `POST /environments` | `{"environment_id", "label", "url", "admin_session"}` | environment |
| `GET /environments/{environment_id}/pairings` | — | list of pairing, newest first |
| `POST /environments/{environment_id}/pairings` | optional `{"async": true}` | `{pairing, pairing_url}` (201), or Operation (202) when opted in |
| `POST /environments/{environment_id}/pairings/revoke` | `{"node_id": 4}`, optional | Operation (202) |

The objects:

```json
{
  "node": {"id": 4, "name": "phone", "wireguard_ip": "10.44.0.77", "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"}},
  "profile": {"id": 1, "name": "Nick", "settings_version": 3, "updated_at": "2026-10-08T10:00:00+00:00"},
  "settings": {"profile_id": 1, "version": 3, "settings": {"workspaces": []}, "sections": {"workspaces": {"version": 1, "updated_at": "2026-10-08T10:00:00+00:00", "updated_by": "phone"}}, "updated_at": "2026-10-08T10:00:00+00:00"},
  "environment": {"environment_id": "env-beast", "label": "beast", "url": "http://10.44.0.30:3773", "server_version": "0.9.1", "registered_by": "beast", "registered_at": "2026-10-08T09:00:00+00:00", "admin_session_expires_at": "2026-11-07T09:00:00+00:00", "status": "registered"},
  "pairing": {"id": 7, "environment_id": "env-beast", "node_id": 4, "node_name": "phone", "client_label": "phone via Orbit k3j9x0qa", "issued_at": "2026-10-08T10:00:00+00:00", "expires_at": "2026-10-08T10:05:00+00:00", "revoked_at": null}
}
```

`pairing_url` has T3's form, `<url origin>/pair#token=<token>`.

| Error code | Status | Meaning |
| --- | --- | --- |
| `peer.identity_unknown` | 403 | The caller is not an active Node. |
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

The Node is the identity because the Gateway already knows every WireGuard peer as a Node, phones included, and WireGuard binds each address to one key. Revocation happens only in T3 and leaves WireGuard access alone, so a revoked device keeps its other servers and Orbit access.

A Node belongs to one profile because each device is its own Node: a profile is how a phone and a desktop share one copy of the workspaces. Settings use a version check rather than last-write-wins, so an edit on one device is never lost silently to an older copy on another.

### Why sections have their own versions

The devices of a profile edit different things at different times. With one version for the whole document, a font change on the phone would conflict with a theme change on the Mac. Every client would then merge the entire document to resolve it. With a version per section, only two edits of the same section conflict.

Each device type has its own section for the same reason: a phone and a desktop never conflict over values that belong to one of them. A patch is all or nothing, so a client that changes the mode and a theme in one step never leaves them half applied.

The Gateway stores the versions apart from the document, in `section_meta` on the profile. A profile from before sections has no entry, so every section of it is at version 0, and the first patch of a section sends version 0.

`PUT` stays for clients that only know workspaces. It replaces just the sections it sends, so such a client cannot erase the appearance or device settings that a newer client wrote.

## Pairing Operations

Existing Conn clients keep synchronous pairing creation: omit `async` or send `false` to receive HTTP 201 with `{pairing, pairing_url}`. This path creates no Operation and stores no credential. Send `{"async": true}` to opt into HTTP 202 with a normal Operation. Revocation returns an Operation. Swamp executes the fixed Conn command through the Gateway executor. The Gateway resolves the stored environment session after checking the Operation's owner. A successful pairing stores its one-time credential encrypted on the pairing record through the executor's secret-output contract. Operation receipts contain safe identifiers and a stored-secret reference only.

After creation succeeds, the calling Node reads `GET /api/v1/conn/environments/{environment}/pairings/{pairing}` to receive its pairing URL. Read the URL once and open it immediately. The owner-checked read clears the encrypted credential; a second read, an expired pairing, or a read before completion returns HTTP 409. Another Node cannot read that credential. Listing pairings never includes it. Revocation removes it. A Gateway restart in the uncertain issue window never mints a second pairing link automatically; the Operation fails with `conn.pairing_outcome_unknown`, and an explicit new create uses a new pairing identity.

Async pairing creation is unavailable with `operation.kind_unavailable` until the executor enforces secret outputs. The family declares `credential` as a secret stored through `conn.pairing.credential`. Its owner check binds the pairing, calling Node and environment, and refuses a revoked pairing or a conflicting credential.

The producer is the Gateway-local `conn.pairing-issue` command. Kit H replaces its transient credential with `{"$secret":"conn.pairing.credential","id":<pairing id>}` before a run receipt, step or completion is stored. The encrypted owner write and run receipt commit together. A retry reads that reference and does not mint or store another credential. The existing profile and registration reads remain local Gateway behavior.

Conn callers follow their own pairing Operations at `GET /api/v1/conn/operations/{operation}` and cancel them at `POST /api/v1/conn/operations/{operation}/cancel`. These endpoints check the original requester, including when a revocation names another Node. They expose no other Operation or Gateway authority. Re-registration keeps synchronous cleanup of older Gateway sessions, so repeated registrations cannot drop an intermediate session while cleanup is pending.

Merge this cutover only after the paired orbit-swamp integration workflow runs on a placed worker. The synchronous pairing reply stays bound until both the Conn app and the relink script support the opt-in Operation flow.
