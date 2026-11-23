---
title: "Dependency contracts"
description: "The Swamp dependency producer, native resource lookup, bounds, provenance, and Gateway read contract."
covers:
  - apps/gateway/app/Domain/Instances/Dependencies/**
  - apps/gateway/app/Data/Instances/Dependencies/**
---

# Dependency contracts

Swamp publishes dependency evidence, and the Gateway reads it using its read-only workflow/data credential over the same verified WebSocket transport used by the [Swamp runs panel](/reference/tasks#swamp-run-output). No read starts work. See [Instance dependencies](/reference/instance-dependencies) for UI views and freshness rules.

## orbit.dependencies.v1

The `orbit-gateway` producer publishes deterministic Project/ref indexes and immutable source reports with exact `modelIdOrName`, `dataName`, and `version` pointers for Composer and JavaScript ecosystems.

### Project index

Each Orbit Project and source ref has one JSON index referencing immutable source reports and any open batch update PR. Reports are published before switching the index so a read never mixes generations.

| Field | Required meaning |
| --- | --- |
| `schema_version` | Integer `1`. |
| `publication_id` | Identifier for this index generation. |
| `project_id`, `repository` | Exact Orbit Project ID and canonical repository (`github.com/<owner>/<repository>`). |
| `source_ref`, `source_commit` | Full ref (such as `refs/heads/main`) and scanned commit. |
| `generated_at` | UTC publication timestamp. |
| `last_attempt` | Run ID, start/end times, and status (`running`, `succeeded`, `partial`, or `failed` with a safe error code). |
| `sources` | Sub-project `path`, `ecosystem`, `package_manager`, `last_attempt`, and nullable `report` pointer. |
| `batch_pr` | Explicit `open`, `absent`, or `unknown` state, `checked_at`, `last_success_at`, `error_code`, and `value` (repository, number, `https://github.com/.../pull/<n>` URL, head commit, Orbit group ID, Swamp run ID). |

An upstream read failure produces `unknown` rather than `absent`. PR evidence expires after 5 minutes, and inventory expires after 26 hours. Failed refreshes keep the prior immutable pointer, commit, and observation time; partial scans never admit incomplete batches.

### Source report

Each source report belongs to one Project, commit, relative path, ecosystem, and package manager:

| Field | Required meaning |
| --- | --- |
| `schema_version`, `publication_id`, `published_at` | Schema `1` and report publication metadata. |
| `project_id`, `repository`, `source_commit`, `path` | Exact source identity, verified against the index pointer. |
| `ecosystem`, `package_manager` | `composer` or `npm`; manager `composer`, `npm`, `pnpm`, or `bun`. |
| `observed_at`, `run_id` | UTC scan timestamp and producing Swamp run ID. |
| `basis`, `observed_on`, `instance_id` | `locked` or `installed`; `repository`, `task_worker`, or `instance`; Instance ID only for `instance` observations, else `null`. |
| `packages` | Source-local ID, package name, opaque version, `origin` (`registry`, `git`, or `path`), `direct` boolean, and `scopes` (`regular`, `development`). |
| `findings` | Finding ID, package, `kind` (`major` or `advisory`), `current_versions`, nullable `target_version`, `advisory_ids`, `state` (`ready`, `blocked`, or `in_batch`), `reason` (`code`, `text`), and nullable `links` (`group_id`, `run_id`). |

Blocked advisories without a verified fix keep `target_version: null` and an explicit reason. Finding package names must reference packages in the same report, `current_versions` must be unique (up to 5,000 items of 256 characters), and major findings have no `advisory_ids`.

### Bounds and failure semantics

Every published index and report must stay within these size and string limits:

| Bound | Limit |
| --- | --- |
| Project index | 64 KiB, up to 64 source entries |
| Source report | 1 MiB, up to 5,000 package resolutions and 500 findings |
| String limits (Unicode code points) | Package names/IDs, repositories, and `advisory_ids`: 512; versions and run IDs: 256; paths, refs, and reason text: 1,024; codes (`[a-z][a-z0-9_]*`): 128; PR URLs (`https://github.com/.../pull/<n>`): 2,048 |

Unsafe text is rejected, including `file://` URLs, Windows/UNC paths, and host directory prefixes (`/home`, `/etc`, `/tmp`, `/var`, `/srv`, `/opt`, `/usr`, `/root`, `/Users`, and similar). Exceeding a bound publishes an explicit source failure and retains the last successful report instead of truncating.

## Native lookup

The Gateway reads `modelIdOrName: orbit-gateway` with index `dataName: dependencies-v1-project-<project_id>-<ref_sha256>` (lowercase SHA-256 of the UTF-8 full ref) and write-once report `dataName: dependencies-v1-report-<publication_uuid>-<source_sha256>` (SHA-256 of compact JSON `[path, ecosystem, package_manager]`) at the exact pinned `version`.

Swamp retains indexes and reports for [7 days](/reference/operations#swamp-datastore-watch). The Gateway validates exact producer, name, version, byte size, and SHA-256 checksum before accepting any reply.

## Gateway reads

The Gateway exposes two read-only dependency endpoints:

- `GET /api/v1/instances/{instance}/dependencies`
- `GET /api/v1/projects/{project}/dependencies`

Both enforce normal resource access before contacting Swamp. Only validated plain arrays enter the cache (60-second refresh TTL; 7-day stale fallback retention without extending observation times). Failed reads log only fixed codes (`tls`, `auth`, `invalid`, `timeout`, `busy`, or `unavailable`), stage, and Project ID, backing off 30 seconds before retrying. When the [handshake ledger](/reference/swamp-command-steps#handshake-ledger) reserves Swamp capacity for Operations, reads return cached stale/unavailable data with HTTP 200 and `Retry-After`.

One request reads its index and selected report on one connection and piggybacks stale default-branch indexes for up to 200 other Projects.

## Separate monorepo dependency sources

Each configured path and ecosystem owns an independent report. Multiple sub-projects can resolve different versions of the same package without overwriting each other, and the Gateway never merges them into a single Instance claim.

## Selected source and pagination

Optional query parameters:

- `source`: 64-character lowercase hex SHA-256 source ID (defaults to the first source in the index; unknown IDs are refused).
- `packages_page`: 1 through 50 (up to 100 rows per page).
- `findings_page`: 1 through 5 (up to 100 rows per page).

Validation always checks the complete bounded report before paginating rows for the response, and source summaries omit internal storage coordinates.
