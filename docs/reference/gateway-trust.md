---
title: "Gateway trust"
description: "How the CLI stores Gateway profiles, pins the Gateway root certificate, installs it in the operating-system trust store, and recovers when a profile changes during a trust command."
covers:
  - apps/cli/app/Services/Trust/**
  - apps/cli/app/Repositories/GatewayConfigRepository.php
  - apps/cli/app/Data/GatewayProfile.php
  - apps/cli/app/Support/OrbitHome.php
  - packages/php-sdk/src/GatewayRootCaClient.php
  - apps/gateway/app/Http/Controllers/Api/RootCaCertificatesController.php
---

# Gateway trust

The CLI reaches the Gateway over HTTPS using a certificate signed by the Orbit root certificate authority (CA). The CLI pins that root certificate per Gateway profile and installs it into the operating-system trust store. [`gateway`](/cli/gateway) lists the commands.

## Profiles

Profiles live in `$ORBIT_HOME/config.json` (`ORBIT_HOME` defaults to `$HOME/.orbit`, capped at 1 MiB). The file stores the active profile name plus each profile's `url`, `ca_path`, and optional `realtime_url` (`wss://` origin, max 2,048 chars) and `realtime_key` (`[A-Za-z0-9._-]{1,128}`).

The CLI writes `config.json` under a lock with mode `0600` and refuses any symlink, foreign owner, or group/other permission bits (`gateway.config_not_private`). Profile names are 1–63 lowercase letters, digits, `.`, `_`, or `-` starting with a letter or digit; profile URLs are HTTPS origins without credentials, path, query, or fragment.

The first profile added becomes active automatically; later profiles activate only with `--use` or `gateway:use`. Pinned certificates live in `$ORBIT_HOME/gateways/<slug>-<hash>/ca/<sha256>.pem` (`<slug>` up to 48 chars, `<hash>` first 12 hex chars of the name's SHA-256; directories `0700`, files `0600`).

## Trust sequence

`gateway:add` and `gateway:trust` run five steps:

1. **Bootstrap fetch:** calls `GET /api/v1/ca/root` without TLS verification and checks the SHA-256 fingerprint (`ca.unavailable`, 503).
2. **Pin check:** compares against `--ca` or the saved pin. Mismatches fail with `gateway.ca_changed` without `--accept-ca-change`.
3. **Pinned verification:** saves the certificate privately and repeats the request verified against that root (`gateway.ca_verification_failed`).
4. **OS trust store:** installs the certificate into the macOS System keychain or `/usr/local/share/ca-certificates/` with `update-ca-certificates`.
5. **Profile save:** `gateway:add` writes the profile; `gateway:trust` updates the pin under the [profile guard](#profile-guard).

JSON responses omit certificate material: `gateway:add --json` returns `{ name, url, ca_path, realtime_url, realtime_key, active, trust_status, sha256, request_id }`, and `gateway:trust --json` returns `{ gateway, status, sha256, ca_path, request_id }`. Additional error codes: `gateway.ca_unavailable`, `gateway.ca_fetch_failed`, `gateway.ca_invalid`, `gateway.ca_pin_invalid`, `gateway.ca_store_failed`, `gateway.ca_install_failed`, and `gateway.ca_response_invalid`.

## Profile guard

`gateway:trust` writes the updated pin under the config lock only if the profile's name, URL, and prior pin path still match. If another command modified the profile concurrently, it fails with `gateway.ca_profile_update_failed` while keeping the other change (the OS trust store entry remains installed).

## Removal

`gateway:remove` deletes the profile entry and its private pinned certificate without touching the OS trust store. Removing the active profile requires `--force` (`gateway.profile_active` otherwise), and non-interactive calls require `--yes` (`input.confirmation_required`).

## Why it works this way

With a new profile and no `--ca`, both the certificate and its SHA-256 fingerprint come from the same unverified `GET /api/v1/ca/root` response. The second pinned request proves only continuity between those two fetches; it does not authenticate the first response against an active interceptor. Pass `--ca` or verify the fingerprint out of band before trusting a new Gateway. Requiring `--accept-ca-change` catches unexpected CA replacements on known profiles, and leaving the OS trust store untouched on `gateway:remove` protects other profiles or local tools sharing the same root CA.
