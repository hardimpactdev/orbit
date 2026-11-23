---
title: "Vaultwarden"
description: "How the Gateway publishes database credentials into a Vaultwarden collection as Database items, keeps them current, and deletes them when their source is gone."
covers:
  - apps/gateway/app/Infrastructure/Certificates/OrbitCaCurlFactory.php
  - apps/gateway/extensions/vault/**
  - swamp/extensions/behaviors/operations/vault-sync/**
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Vaultwarden

The Gateway can publish the database credentials it holds into one collection of a [Vaultwarden](https://github.com/dani-garcia/vaultwarden) organization. Each credential becomes a **Database item** that the Vault app and Conn can read, so people get current credentials without asking Orbit. Orbit stays the source of truth: it creates, rewrites, and permanently deletes items. [`vault`](/cli/vault) lists the commands.

Vaultwarden publishing is the `vault` [extension](/reference/extensions). While the switch is off, its API endpoints and MCP tools return `extension.disabled` (409), the CLI hides its commands, and scheduled sync pauses. Turning the switch off keeps the stored setup and publishing ledger intact. New installs start with the switch off, and upgrades preserve its state.

## Set up

Prepare Vaultwarden first:

1. Create an account for the Gateway using the default PBKDF2-SHA256 KDF (at most 2,000,000 iterations). Argon2id accounts are refused.
2. Create an organization (for example `Orbit`) and a collection inside it (for example `Databases`).
3. Invite the Gateway account to the organization with **Can manage** access on the collection and confirm membership.
4. Sign in as the Gateway account and copy its personal API key (`client_id` and `client_secret`).

Vaultwarden must run behind an Orbit Route such as `https://vault.orbit`. Setup accepts only an HTTPS origin on a `.orbit` host with no user info, path, query, or fragment. Orbit verifies TLS using the Bitwarden CLI's default trust store plus the public Orbit CA root (`NODE_EXTRA_CA_CERTS`) and never disables verification.

Run [`vault:setup`](/cli/vault/setup) with the origin, API key, master password, organization, and collection (each by name or ID). Field limits: `url` up to 2,048 characters, `client_id`, `organization`, and `collection` up to 255 characters, and `client_secret` and `master_password` up to 1,024 characters.

Setup starts an [Operation](/reference/operations) that logs in with the pinned Bitwarden CLI, unlocks the account, verifies write access to the collection by creating and deleting a temporary probe item, logs out, and removes its private scratch directory. The Gateway stores the configuration only after verification and cleanup both succeed. You can re-run setup to rotate the API key or master password; changing the organization or collection while items are published fails with `vault.items_published` (run [`vault:destroy`](#tear-down) first).

No response or Activity entry exposes the API key or master password. Because the MCP `vault-setup` tool receives them as arguments, prefer the CLI (which reads them from files) when keeping secrets out of an agent transcript matters.

### Who can redirect the credentials

Setup, sync, and teardown require Gateway Node access. Anyone with that access controls which `.orbit` Vaultwarden origin receives MySQL and PostgreSQL passwords, so grant Gateway access only to trusted Nodes.

## Items

The Gateway publishes one item for each eligible source:

| Source | Item name | Credentials |
| --- | --- | --- |
| A `mysql` or `pgsql` [Database connection](/reference/database-connections) with host, port, database, username, and password | The connection slug | The connection's own |
| A [Database user](/reference/database-servers#add-a-user) whose password Orbit stores (skipping any user whose username matches its connection's username) | `<slug> (<username>)` | The user's credentials with the connection's host, port, and database |

Redis and SQLite connections get no item. Incomplete `mysql`/`pgsql` connections and Database users without a stored password appear under `skipped` in [`vault:status`](/cli/vault/status).

Each item is a Secure Note in the configured collection using schema version 1 of Vault's Database item:

| Field | Value |
| --- | --- |
| `vault.schema` | `1` |
| `type` | `database` |
| `driver` | `mysql`, or `postgresql` for a `pgsql` connection |
| `host`, `port`, `database`, `username` | From the source |
| `password` | From the source, as a hidden field |
| `tls` | `disabled` |
| `orbit.source` | `database-connection:<id>` or `database-user:<id>` |

The note indicates that Orbit manages the item. Orbit overwrites manual edits made in Vaultwarden.

### Database user passwords

[`database:user:create`](/cli/database/user-create) stores the user's password encrypted on the Database user record without returning it in any response. Any user created without a stored password gets no Vaultwarden item until `database:user:create` sets its password again.

## Sync

The scheduler runs `orbit:vault-sync` every minute while `vault` is enabled and configured, skipping whenever another Vault Operation is already running. When nothing changed locally, the sync skips contacting Vaultwarden except for an hourly reconciliation check.

Each sync compares desired items against the publishing ledger and, when contacting Vaultwarden, reconciles collection items by their `orbit.source` field:

| State in Vaultwarden | Action |
| --- | --- |
| Wanted item absent from ledger and collection | Create it in the collection. |
| Wanted item content changed or edited manually | Rewrite it. |
| Published cipher missing or trashed | Create a replacement with a new ID without adopting another cipher. |
| Unrecorded item matching a wanted source | Adopt and rewrite it (covers timed-out creates and items kept by `vault:destroy --keep-items`). |
| Recorded item trashed or whose source is gone, with matching ID and `orbit.source` | Delete it permanently, unless it also belongs to another visible collection. |
| Unrecorded copy of a recorded source, unrecorded unwanted item, or item without `orbit.source` | Leave it untouched. |

Do not add `orbit.source` fields to manual items. Sync runs item mutations sequentially using a disposable `0600` session directory and always logs out and deletes that cache when finished. Ledger results are capped at 4,096 entries per list, 1 KiB per entry, and 256 KiB total; oversized results are rejected before updating the ledger.

If a sync fails, the publishing ledger stays unchanged and the next run reconciles remote state before retrying. Database operations never wait on Vaultwarden. `last_synced_at` updates and `last_error` clears only after a successful run.

[`vault:sync`](/cli/vault/sync) triggers the same full reconciliation immediately (including the hourly check) and returns HTTP 202 with an Operation. Only one Vault Operation runs at a time.

## Status

`vault:status` returns:

| Field | Meaning |
| --- | --- |
| `configured` | Whether Vaultwarden is set up (other fields are `null` or empty when `false`). |
| `url` | The Vaultwarden origin. |
| `email` | The Gateway account's email. |
| `organization`, `collection` | Objects with `id` and `name`. |
| `items` | Count of published items. |
| `pending` | Count of new or changed credential intents plus ledger records for removed sources. |
| `skipped` | Sources omitted from sync: `source`, `slug`, `username`, and `reason` (`incomplete` or `password_unknown`). |
| `last_synced_at` | Timestamp of the last error-free sync, or `null`. |
| `last_error` | `code` and `message` of the last failed run, or `null`. |

## Tear down

[`vault:destroy`](/cli/vault/destroy) permanently deletes every published item whose recorded ID and decrypted `orbit.source` still match in the configured organization and collection, then clears the stored configuration and publishing ledger. Unrecorded copies and items with mismatched sources stay untouched.

Before deleting anything, teardown refuses with `vault.item_shared` if any selected item also belongs to another visible collection (Vaultwarden deletes the whole item across collections). Sync uses the same guard. Note that Vaultwarden's `collectionIds` lists only collections visible to the Gateway account.

Pass `--keep-items` (API body `{"keep_items": true}`) to clear Orbit's configuration and ledger immediately without contacting Vaultwarden. A later setup on the same collection adopts wanted items during sync. If Vaultwarden fails to delete some items, teardown keeps the configuration and removes only the ledger entries that succeeded so you can retry.

## API

All endpoints require access to the Gateway Node.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/vault` | Show status. |
| `PUT` | `/api/v1/vault` | Set up with `url`, `client_id`, `client_secret`, `master_password`, `organization`, and `collection`. Returns an Operation (202). |
| `POST` | `/api/v1/vault/sync` | Start a sync Operation (202). |
| `DELETE` | `/api/v1/vault` | Tear down (optional `{"keep_items": true}`). Returns an Operation (202). |

### Synchronous admission errors

The API returns these errors synchronously before admitting an Operation:

| Code | HTTP | When |
| --- | --- | --- |
| `extension.disabled` | 409 | The `vault` extension is off. |
| `vault.not_configured` | 409 | Sync or teardown called before setup. |
| `vault.sync_busy` | 409 | Another setup, sync, or teardown Operation is running. |
| `vault.gateway_missing` | 409 | The Gateway Node record is missing. |
| `validation.failed` | 422 | Invalid field length, or `url` is not an HTTPS origin on a `.orbit` host. |

### Operation errors

These codes appear on failed Vault Operations:

| Code | Meaning |
| --- | --- |
| `vault.items_published` | Setup names a different organization or collection while items are published. |
| `vault.item_shared` | A published item selected for deletion also belongs to another visible collection. |
| `vault.login_failed` | Vaultwarden refused the API key, or the master password could not unlock the account. |
| `vault.kdf_unsupported` | The account uses Argon2id or more than 2,000,000 PBKDF2 iterations. |
| `vault.organization_missing` | The account is not a confirmed member of the organization. |
| `vault.collection_missing` | The organization has no accessible collection matching the request. |
| `vault.collection_read_only` | The account cannot write to the collection. |
| `vault.unreachable` | Vaultwarden did not answer or returned an error. |
| `vault.sync_failed` | Sync could not complete one or more remote changes. |
| `vault.result_invalid` | The sync or setup result exceeded size limits or failed validation. |
| `vault.intent_changed` | The vault intent changed while the Operation was running. |

## Why it works this way

### Orbit keeps its own copy

Orbit stays the source of truth so deploys and recovery never depend on Vaultwarden, and credentials are never exposed through the Orbit API where any Gateway client could read stale copies.

### TLS is disabled in items

Managed Database servers listen on WireGuard without TLS, so Orbit records `tls` as `disabled` on published items.

### Items are matched by their source

Because Vaultwarden assigns cipher IDs after creation, a timed-out create can still succeed remotely. Recording `orbit.source` lets the next sync find and adopt that item instead of duplicating it.

### Scheduled sync with the official CLI

Running sync from the scheduler keeps database writes independent of Vaultwarden availability, and pausing sync while the `vault` extension is off stops credential publishing immediately. Delegating login, encryption, and item writes to the pinned `@bitwarden/cli` package avoids maintaining a custom Vaultwarden client, and permanently deleting removed items prevents revoked passwords from lingering in the trash.
