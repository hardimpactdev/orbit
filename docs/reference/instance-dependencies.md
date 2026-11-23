---
title: "Instance dependencies"
description: "Read Swamp-owned dependency versions, findings, and batch update pull requests through the Gateway."
covers:
  - apps/gateway/app/Actions/Instances/Dependencies/**
  - apps/gateway/app/Http/Controllers/Api/*DependenciesController.php
  - apps/web/src/pages/InstanceDependencies.tsx
  - apps/web/src/api/dependencies.ts
---

# Instance dependencies

Swamp owns dependency scanning, advisory checks, target selection, and batch updates. The Gateway reads Swamp's versioned results from `swamp.orbit` and serves them to the browser. Opening an Instance or Project dependency view never starts a scan, workflow, task, or package command. See [Dependency contracts](/reference/instance-dependency-contracts) for producer schemas and bounds.

## Versions and evidence

Select a dependency source by its repository-relative path and ecosystem (`source` query parameter, 64-character lowercase hex SHA-256). Pagination accepts `packages_page` (1 through 50) and `findings_page` (1 through 5). Each source lists its package manager, distinct package resolutions, direct or transitive status, regular and development scopes, and `registry`, `git`, or `path` origin.

Each report records the scanned commit and observation time:

- **Locked repository versions:** produced by a Git scan of lockfiles, not an inspection of installed files on an Instance.
- **Worker observations:** installed packages observed inside a worker.
- **Instance installed versions:** labelled as installed only when observed on that exact Instance.

The Project view and development `default` Instances read the Project's `default_branch`; other Instances read their recorded source branch and compare the report commit against their recorded checkout or deployed commit. Inactive or removing Instances return HTTP 409. Orbit never contacts an Instance to inspect packages during a dependency read.

## Updates and blocked findings

Swamp supplies major-version and security advisory findings with current versions, verified target versions, advisory IDs, and status (`ready`, `blocked`, or `in_batch`). A blocked finding without a verified fix has a `null` target; Orbit never infers one.

The view links any open whole-project batch PR and its task group. PR evidence is explicitly `open`, `absent`, or `unknown` (a failed lookup stays `unknown` even when showing the last known PR). Partial scans never admit incomplete batches.

## Freshness and failure

Inventory becomes stale after 26 hours, after a failed source refresh, or when the report commit differs from the Instance's known commit. PR evidence expires after 5 minutes. Gateway refresh caching lasts up to 60 seconds without resetting observation times; a single index refresh also piggybacks stale default-branch indexes for up to 200 other Projects on the same connection.

If Swamp is unreachable, returns invalid data, or defers view reads behind active Operations via the [handshake ledger](/reference/swamp-command-steps#handshake-ledger) (HTTP 200 with `Retry-After`), the Gateway serves the last validated evidence marked stale (or unavailable if none exists). Errors never produce an empty successful inventory.

## Native feature retirement

Dependency scanning and updates run solely through Swamp; there are no native `instance:dependencies:scan` or `instance:dependencies:update` commands, API/MCP endpoints, agent commands, or local inventory tables in the Gateway. Domain and directory resolution errors use the `instance` error family (`instance.domain_invalid`, `instance.directory_invalid`, `instance.target_not_found`, `instance.target_ambiguous`, and `instance.unavailable`).

## Why it works this way

Having Swamp own scanning, policy, and batch updates while the Gateway provides a single bounded, authorized read path avoids duplicating dependency policy across two systems. Immutable report pointers preserve coherent last-good provenance during failed refreshes, and explicit PR certainty prevents a failed lookup from claiming no update PR is open.
