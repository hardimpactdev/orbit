---
title: "SQLite WAL default"
description: "Why Orbit-owned SQLite connections use WAL mode and how to verify it."
covers:
  - apps/gateway/config/database.php
---

# SQLite WAL default

## Problem

A Laravel app on SQLite fails with `database is locked` under concurrent access when SQLite opens the database in its default `DELETE` journal mode.

## Cause

Laravel sets `PRAGMA journal_mode` only when `config/database.php` specifies a non-null `journal_mode`. Leaving `journal_mode => null` creates new databases in `DELETE` mode.

## Solution

Configure every Orbit-owned SQLite connection with these settings (matching `apps/gateway/config/database.php`):

```php
'busy_timeout' => 5000,
'journal_mode' => 'WAL',
'synchronous' => 'NORMAL',
'transaction_mode' => 'IMMEDIATE',
```

`busy_timeout` with `IMMEDIATE` transactions makes writers wait for locks instead of failing immediately. Existing databases keep their current journal mode until an operator converts them or a connection with a non-null `journal_mode` opens them.

## Limits

WAL creates `-wal` and `-shm` sidecar files alongside the database file; backups, copies, and restores must keep all three files together. MySQL and PostgreSQL connections are unaffected.

## Verification

Confirm `config('database.connections.sqlite.journal_mode')` equals `WAL` and `PRAGMA journal_mode` on an open connection returns `wal`.
