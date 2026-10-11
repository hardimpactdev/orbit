---
title: "ADR 0205: Publish database credentials to Vaultwarden"
sidebarTitle: "0205 Vaultwarden credentials"
description: "In progress. The Gateway copies every MySQL and PostgreSQL credential it holds into one Vaultwarden collection, as Database items that Vault and Conn read. Orbit stays the source of truth."
---

# ADR 0205: Publish database credentials to Vaultwarden

The Gateway copies the database credentials it holds into one collection of a Vaultwarden organization. Each MySQL or PostgreSQL connection, and each extra user on a Database server, becomes one Database item. Orbit stays the source of truth: it creates, rewrites, and permanently deletes those items. People and tools read credentials from Vaultwarden instead of from Orbit.

## Status

In progress.

Principle: [security fits the real threat model](/mission#principles). The Gateway already holds every database password. Copying them to a vault that people already trust gives them a reader without giving Orbit's API a way to return passwords.

## Context

Orbit creates and rotates database passwords, and no API response returns them. A person or a desktop tool that needs a password must ask the Gateway's operator or read the Instance's `.env` file. Copies made that way go stale when Orbit rotates a password.

The Vault app reads Vaultwarden, including shared organization items. It shows a Secure Note whose custom fields follow the Database schema as a Database item. Conn reads Database items through Vault.

Vaultwarden runs as an Orbit Process at `https://vault.orbit`. Bitwarden encrypts every item on the client. A writer needs a Vaultwarden account, its master password, and the organization key.

`database:user:create` sets a password in MySQL but does not store it. Orbit has nothing to publish for such a user.

## Decision

The Gateway owns the copy. Vaultwarden holds only what the Gateway publishes, and the Gateway never reads credentials back from it.

### Setup

`orbit vault:setup` stores the Vaultwarden origin, the API key and master password of one Vaultwarden account for the Gateway, and one organization and collection. The operator creates the account, the organization, and the collection in Vaultwarden, and gives the account **Can manage** on the collection. The Gateway logs in and checks every value before it stores them encrypted. It refuses an account that uses Argon2id, because PHP cannot derive Bitwarden's Argon2id keys. `vault:status` shows the result. `vault:teardown` deletes Orbit's items and the stored values.

The origin must be HTTPS on a `.orbit` host, and every request trusts only the Orbit root CA. A caller with Gateway access chooses where the passwords go, so that choice stays within servers that Orbit routes and issues certificates for.

### Items

The Gateway publishes one Secure Note for each of these, into the configured collection:

- a `mysql` or `pgsql` Database connection with a host, port, database, username, and password;
- a Database user whose password Orbit stores.

Item fields follow Vault's Database schema version 1: `vault.schema`, `type`, `driver` (`mysql` or `postgresql`), `host`, `port`, `database`, `username`, `password` (hidden), and `tls`. `tls` is `disabled`, because Orbit records no TLS mode and its servers listen on WireGuard. An `orbit.source` field names the source record. Redis and SQLite connections get no item.

`database:user:create` now stores the user's password encrypted on the Database user record. Users created before this change get an item after `database:user:create` sets their password again.

### Sync

`orbit:vault-sync` runs every minute from the Gateway scheduler. It compares the wanted items with a ledger of published items. It creates an item that is missing, rewrites an item whose content changed, and permanently deletes an item whose source is gone. It contacts Vaultwarden only when something changed, and once an hour to repair items that someone changed or deleted in Vaultwarden. `vault:sync` runs the same reconcile at once, with the repair check, and fails with `vault.sync_failed` when any change failed. A failure keeps the ledger as it was, so the next run retries. Database operations never wait for Vaultwarden.

Every run that contacts Vaultwarden matches the collection to the ledger by each item's `orbit.source` field. It adopts an Orbit item that has no ledger row, such as one whose create timed out after Vaultwarden stored it, and permanently deletes duplicates, trashed copies, and items whose source is gone. Teardown deletes every Orbit item in the collection, recorded or not.

The Gateway encrypts items itself with the organization key, in the standard Bitwarden format. It needs no Bitwarden CLI and no Node.js.

## Rejected alternatives

- Store secrets only in Vaultwarden: the Gateway would still need the organization key to use them, so a Gateway compromise exposes the same secrets. Deploys and recovery would depend on a service that runs on an Orbit Node.
- Return passwords through the Orbit API: every client with Gateway access reads every password, and copies still go stale.
- Write items with the Bitwarden CLI: it needs Node.js on the Gateway and a long-lived unlocked session.
- Sync inside each database write: a Vaultwarden outage would fail database operations, and the Gateway has no general queue.
- Move removed items to the trash: Orbit is the source of truth, so a removed credential must not stay readable.
- Trust the system certificate store and any HTTPS URL: a caller with Gateway access then sends every password to a server it controls.
- Record an item before creating it: Vaultwarden assigns the item ID, so a create that times out still leaves an unknown item. Matching by `orbit.source` finds it.

## Consequences

- People and Conn read current credentials from Vaultwarden.
- The Gateway holds one more secret: the Vaultwarden account's master password. It unlocks only what that account can reach.
- Changes reach Vaultwarden within a minute. A change made in Vaultwarden is overwritten within an hour.
- Extra users made before this change have no item until their password is set again.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: none
- Detail: [Vaultwarden](/reference/vaultwarden)
- Verify: `apps/gateway/tests/Feature/Vault/*`, `apps/gateway/tests/Unit/Infrastructure/Vault/*`, `apps/cli/tests/Feature/Vault/*`, `packages/php-sdk/tests/Unit/Requests/Vault/*`
