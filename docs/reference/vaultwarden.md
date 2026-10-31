---
title: "Vaultwarden"
description: "How the Gateway publishes database credentials into a Vaultwarden collection as Database items, keeps them current, and deletes them when their source is gone."
covers:
  - apps/gateway/app/Infrastructure/Certificates/OrbitCaCurlFactory.php
  - apps/gateway/extensions/vault/**
  - apps/gateway/database/migrations/2026_12_03_000000_allow_cold_recipe_image_rebuilds.php
---

# Vaultwarden

The Gateway can publish the database credentials it holds into one collection of a [Vaultwarden](https://github.com/dani-garcia/vaultwarden) organization. Each credential becomes one **Database item**. The Vault app and Conn read those items, so people get current credentials without asking Orbit. Orbit stays the source of truth: it creates, rewrites, and permanently deletes the items. [`vault`](/cli/vault) lists the commands.

Vaultwarden publishing is the `vault` [extension](/reference/extensions). Its code lives in `apps/gateway/extensions/vault`. While the switch is off, its API operations and MCP tools refuse with `extension.disabled`, the CLI hides its commands, and the sync does not run. The stored setup and the record of published items stay. New installs start with the switch off. Upgrades preserve its stored value.

## Set up

Do these steps in Vaultwarden first:

1. Create an account for the Gateway. Keep the default KDF, PBKDF2-SHA256. The Gateway refuses an account that uses Argon2id.
2. Create an organization, for example `Orbit`, and a collection in it, for example `Databases`.
3. Invite the Gateway account to the organization and give it **Can manage** on the collection. Confirm the membership.
4. Sign in as the Gateway account and copy its personal API key: `client_id` and `client_secret`.

Vaultwarden must run behind an Orbit Route, such as `https://vault.orbit`. Setup accepts only an HTTPS origin on a `.orbit` host, with no user info, path, query, or fragment. Every request trusts only the Orbit root CA, so the Gateway sends credentials only to a server with a certificate that Orbit issued.

Then run [`vault:setup`](/cli/vault#orbit-vaultsetup) with the Vaultwarden origin, the API key, the master password, and the organization and collection, each by name or ID. The Gateway records the encrypted setup intent. Its Swamp worker logs in, unlocks the account, and checks that it can write to the collection. The Gateway stores the configuration only after Swamp returns proven safe metadata. Setup can run again, for example with a new API key or master password. It refuses another organization or collection with `vault.items_published` while items are published, because they would stay behind in the old one. Run [`vault:teardown`](#tear-down) first.

No response or Activity entry returns the API key or the master password. The MCP `vault-setup` tool takes them as arguments, so they reach the agent's transcript. Run setup with the CLI, which reads them from files, when that matters.

### Who can redirect the credentials

Setup, sync, and teardown need access to the Gateway. A caller with that access chooses where the Gateway publishes every MySQL and PostgreSQL password, within the hosts that Orbit routes. Grant Gateway access only to Nodes you trust with those passwords.

## Items

The Gateway publishes one item for each of these:

| Source | Item name | Credentials |
| --- | --- | --- |
| A `mysql` or `pgsql` [Database connection](/reference/database-connections) with a host, port, database, username, and password | The connection slug | The connection's own |
| A [Database user](/reference/database-servers#add-a-user) whose password Orbit stores | `<slug> (<username>)` | The user's, with the connection's host, port, and database |

Redis and SQLite connections get no item. A `mysql` or `pgsql` connection that lacks one of the fields, and a Database user whose password Orbit does not store, are listed as skipped in [`vault:status`](/cli/vault#orbit-vaultstatus).

Each item is a Secure Note in the configured collection. Every write names the Gateway account as `encryptedFor`, which Vaultwarden 1.37 requires, and sends a Bitwarden client version header. Its custom fields follow schema version 1 of Vault's Database item:

| Field | Value |
| --- | --- |
| `vault.schema` | `1` |
| `type` | `database` |
| `driver` | `mysql`, or `postgresql` for a `pgsql` connection |
| `host`, `port`, `database`, `username` | From the source |
| `password` | From the source, as a hidden field |
| `tls` | `disabled` |
| `orbit.source` | `database-connection:<id>` or `database-user:<id>` |

The note says that Orbit manages the item. Orbit overwrites changes made in Vaultwarden.

### Database user passwords

[`database:user:create`](/cli/database#orbit-databaseusercreate) stores the user's password encrypted on the Database user record. No response returns it. A user created before Orbit stored passwords has no item until `database:user:create` sets the password again.

## Sync

The scheduler runs `orbit:vault-sync` every minute while the `vault` extension is on and Vaultwarden is set up. It does not run while `vault` is off, so a published item stays as it was until the switch turns on again. The first run after that creates, rewrites, and deletes whatever changed meanwhile. The scheduler records an Operation for a placed Swamp integration worker. It skips every unfinished Operation, including one waiting for children. The command builds the wanted items from the database records and compares them with the published items it recorded:

| Wanted item | Published item | Action |
| --- | --- | --- |
| Present | None | Create it in the collection. |
| Present, content changed | Present | Rewrite it. |
| Present, content unchanged | Present | Nothing. |
| None | Present | Delete it permanently. |

A run that finds nothing to do does not contact Vaultwarden, except once an hour. A run that contacts Vaultwarden also reads the collection and recognizes Orbit's items by their `orbit.source` field:

| Found in the collection | Action |
| --- | --- |
| A published cipher that is gone or in the trash | Create the item with a new ID. Do not adopt another cipher for that recorded source. |
| A published item that someone changed | Rewrite it. |
| An item for a wanted source for which the ledger has no entry | Adopt it and rewrite it. This covers a create that timed out before its first ledger record, and wanted items left by `vault:teardown --keep-items`. |
| A recorded item in the trash or whose source is gone from Orbit, with an ID and decrypted source that match the publishing ledger | Delete it permanently, unless it belongs to another visible collection. |
| A copy of a recorded source with a different cipher ID, or an unrecorded item for an unwanted source | Leave it, even if the recorded cipher is missing or trashed. A marker alone does not authorize adoption, rewriting, or deletion. |
| An item without `orbit.source` | Leave it. |

The publishing ledger identifies Orbit's items. Do not add items with an `orbit.source` field by hand.

One failed change does not stop the others. The run records its error, keeps that item's state, and the next run retries it. Database operations never wait for Vaultwarden, so an outage delays only the copy. The scheduled command exits with failure while the Gateway records an error. The generic executor health command reports unclaimed or stalled Operations, including queued initial setup, in Activity and Operation assistance. Starting intent does not update `last_synced_at` or clear an error; successful completion does.

[`vault:sync`](/cli/vault#orbit-vaultsync) runs the same reconcile at once, including the hourly check. It returns HTTP 202 with an Operation; follow that Operation until completion. A failed Operation records its safe error code, and a later run retries. Only one Operation holds the Vault target at a time.

## Status

`vault:status` returns:

| Field | Meaning |
| --- | --- |
| `configured` | Whether Vaultwarden is set up. The other fields are null or empty when it is not. |
| `url` | The Vaultwarden origin. |
| `email` | The Gateway account's email. |
| `organization`, `collection` | Each an object with `id` and `name`. |
| `items` | The number of published items. |
| `pending` | The number of new or changed credential intents plus ledger records for removed sources. This local count does not plan remote writes. |
| `skipped` | Each source that gets no item: `source`, `slug`, `username`, and `reason` (`incomplete` or `password_unknown`). |
| `last_synced_at` | When a run last finished without an error, or null. |
| `last_error` | The `code` and `message` of the last failed run or item, or null. |

## Tear down

[`vault:teardown`](/cli/vault#orbit-vaultteardown) permanently deletes published items whose recorded ID and decrypted `orbit.source` still match in the configured organization and collection, then deletes the stored configuration and publishing ledger. It preserves unrecorded copies, including copies with an `orbit.source` field, and items whose source differs from the ledger.

Teardown refuses before deletion if a matching published item also belongs to another collection, because Vaultwarden deletes the whole item. It needs Vaultwarden to answer. With `--keep-items`, it leaves the items in Vaultwarden and does not contact it. After a later setup on the same collection, sync can adopt items for wanted sources and preserves the remaining unrecorded items. If Vaultwarden cannot delete an item, teardown fails and keeps the setup and the items it did not delete, so it can run again.

Vaultwarden's `collectionIds` response lists only collections visible to the account. The sharing check detects those memberships; an absent ID does not prove that the item has no membership in a collection hidden from the account. Sync uses the same check before deleting a recorded item.

## API

All operations need access to the Gateway.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/api/v1/vault` | Show the status. |
| `PUT` | `/api/v1/vault` | Set up, with `url`, `client_id`, `client_secret`, `master_password`, `organization`, and `collection`. Returns an Operation (202). |
| `POST` | `/api/v1/vault/sync` | Record a sync Operation (202). |
| `DELETE` | `/api/v1/vault` | Tear down. Optional body `{"keep_items": true}`. Returns an Operation (202). |

| Error | Status | When |
| --- | --- | --- |
| `extension.disabled` | 409 | The `vault` extension is off. |
| `vault.not_configured` | 409 | Sync or teardown while Vaultwarden is not set up. |
| `vault.sync_busy` | 409 | Another setup, sync, or teardown is running. |
| `vault.items_published` | 409 | Setup names another organization or collection while items are published. |
| `vault.item_shared` | 409 | A published item selected for deletion also belongs to another visible collection. No item is deleted. |
| `validation.failed` | 422 | The `url` is not an HTTPS origin on a `.orbit` host. |
| `vault.sync_failed` | 502 | `vault:sync` could not make at least one change. `details` holds `failed` and `first_error`. |
| `vault.login_failed` | 422 | Vaultwarden refused the API key, or the master password does not unlock the account. |
| `vault.kdf_unsupported` | 422 | The account uses Argon2id, or more than 2,000,000 PBKDF2 iterations. |
| `vault.organization_missing` | 422 | The account is not a confirmed member of the organization. |
| `vault.collection_missing` | 422 | The organization has no such collection that the account can reach. |
| `vault.collection_read_only` | 422 | The account cannot write to the collection. |
| `vault.unreachable` | 502 | Vaultwarden did not answer, or answered with an error. |

## Why it works this way

The Gateway already holds every database password, and the API never returns one. Vaultwarden gives people a reader they already trust, with sharing and history, without a new way to read passwords from Orbit.

### Orbit keeps its own copy

Orbit stays the source of truth. If the secrets lived only in Vaultwarden, the Gateway would still need the organization key to use them, so they would not be hidden from it. Deploys and recovery would also depend on Vaultwarden, which runs on an Orbit Node.

### The API does not return passwords

Returning passwords through the Orbit API was rejected. Every client with Gateway access would read every password, and copies made that way would still go stale when Orbit rotates a password.

### The Gateway holds one more secret

The Gateway stores the master password of its Vaultwarden account, so that it can unlock the account for each run. That password unlocks only what the account can reach.

### TLS is disabled in items

Orbit records no TLS mode for a connection, and its Database servers listen on WireGuard. So each item says `tls` `disabled`.

### Items are matched by their source

Vaultwarden assigns the item ID. A create that times out can still store the item, so recording an item before its create does not tell the Gateway which item exists. The `orbit.source` field names the source record, so the next run finds that item and adopts it.

### The scheduler runs the sync

The sync runs from the scheduler instead of inside each database write. When Vaultwarden is down, database operations still succeed, and a later run copies the change.

### The sync stops while the extension is off

Publishing is the feature itself, not the end of work that was already recorded, as the Documents cleanup is. If the sync kept running while `vault` is off, the Gateway would keep sending credentials to Vaultwarden after the operator turned the feature off. A stale item is the smaller cost, and the first run after the switch turns on fixes it.

### The account keeps PBKDF2

The collection program derives the account's keys with PBKDF2 and refuses an account configured for Argon2id.

### The Gateway encrypts items itself

The named collection program writes the standard Bitwarden format instead of driving the Bitwarden CLI. That avoids Node.js and an unlocked session that outlives a run.

### TLS trusts only Orbit

The Gateway pins the Orbit root CA and accepts only `.orbit` hosts. With the system trust store and any URL, a caller with Gateway access could point setup at a server it controls and receive every password within a minute.

The Gateway reads the CA from `root.pem` in its state directory, next to the root key, and checks it before each request. It never copies the CA to a shared temporary directory, where another local account could plant its own CA under the expected name.

That CA file is the only trust anchor. The system trust store, such as `/etc/ssl/certs`, does not apply, so a certificate from a public CA or any other CA fails the TLS check. The collection program builds its TLS context with that CA alone and rejects redirects. Each request has a 15-second overall deadline, including connecting, headers, and the body. A peer that sends headers slowly cannot extend that deadline. A process alarm enforces that deadline across the complete request.

### Removed credentials are deleted

When a credential is removed, its item is deleted permanently instead of moved to the trash, so a removed password does not stay readable.

## Operation execution

Setup, synchronization, and teardown record an Operation and return HTTP 202. Swamp runs the named Vault collection program on the recorded Gateway Node. The shared private-material route resolves the Operation's secret reference into its encrypted snapshot of the submitted credentials and wanted Database items. Only the exact recorded run on that Node can read it while the Operation is unfinished.

Swamp uses the private material in memory and through protected stdin; only safe metadata and published item identifiers reach its resources. Credentials never enter Operation input, results, step output, or Swamp data. Terminal completion deletes its encrypted snapshot and snapshots without an unfinished Operation; only unfinished Operations retain their inputs for recovery.

A retry reconciles the collection by `orbit.source` before it creates an item. It adopts an earlier create whose reply was lost, removes duplicates and orphans, and retains unrelated items. A failed teardown keeps its configuration and ledger for retry. Scheduled synchronization records intent; it does no network work in PHP.

Direct setup, sync, and teardown results carry only collection identifiers and ledger metadata. Each ledger list contains at most 4,096 entries; each entry string is at most 1 KiB, and the whole result is at most 256 KiB. The Gateway refuses an oversized result before projecting its ledger and stores no partial result.

## Direct Swamp writer

`vault.sync`, `vault.setup`, and `vault.teardown` start `operation-vault-direct` through the shared helper and require their direct writers. An explicit `polled` setting refuses because its old execution contract is removed. Rollback reverts the owning PR.

The exact admitted Node worker retrieves the encrypted snapshot through the shared run-bound private-material endpoint. Credentials stay in the effect-only callback and protected stdin. The program keeps its 900-second overall deadline. It refuses protected values echoed into scalar or nested public metadata before any result request. The Gateway projects bounded ledger metadata through its existing completion and removes terminal credential snapshots. No family route, token or relay is added.
