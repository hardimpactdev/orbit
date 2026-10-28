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

Swamp owns dependency scanning, advisory checks, target selection, and updates. The Gateway reads its versioned results from `swamp.orbit`. Opening an Instance or Project dependency view starts no scan, workflow, task, or package command. The browser calls the Gateway only. [Dependency contracts](/reference/instance-dependency-contracts) defines the producer data and read limits.

## Versions and evidence

Select a source by its repository-relative path and ecosystem. Each source shows its package manager, distinct package resolutions, direct or transitive status, regular and development scopes, and registry, Git, or local/path origin. Versions stay opaque; two resolutions of the same package remain separate.

The view names the scanned repository commit and observation time. A Git scan reports **locked repository versions**, not an Instance's installed versions. A worker installation is a worker observation. Only an installed observation for the exact Instance can describe its installed versions. A missing or mismatched observation means unknown Instance state. It does not mean that the Instance has no dependencies.

The Project view reads its default branch. The Instance view reads its recorded source branch and compares the report commit with its recorded checkout or deployed commit when available. A report for another commit remains Project evidence and is marked stale for the Instance. Orbit does not contact the Instance to inspect packages during a read.

## Updates and blocked findings

Swamp supplies major-version and advisory findings, current versions, verified exact target versions, advisory IDs, and safe reasons. Findings are ready, blocked, or in a batch. A blocked finding without a verified fix has no target. Orbit does not infer one or change Swamp's policy.

The view links the open whole-project batch PR and its task group when known. PR evidence has three states: open, confirmed absent, and unknown. A failed lookup is unknown, even if the last known PR is shown. Partial scans cannot admit incomplete batches. Swamp owns admission and update execution through the existing task VM flow.

## Freshness and failure

Inventory becomes stale after 26 hours, after a failed source refresh, or when its known source commit differs from the selected source. A running attempt is visible alongside the prior successful report. Failed refreshes keep that report's original commit and time.

PR lookup evidence expires after five minutes. Gateway refresh caching lasts at most 60 seconds and never resets observation times. If Swamp is unreachable or returns invalid data, the Gateway shows the last validated evidence as stale. Without previous evidence it shows unavailable. An error never becomes an empty successful inventory.

## Native feature retirement

Orbit retires `instance:dependencies:scan` and `instance:dependencies:update`, their API and MCP operations, and the Node agent commands used only for dependencies. Remove operator-owned Schedules that invoke those commands before deploying this change. The known Gateway `dependency-nightly-scan` Schedule was removed through Orbit's Schedule API.

Deploy the Swamp producer that passes the shared v1 fixtures before releasing the Gateway reader. Confirm that it publishes Project/ref indexes and immutable reports for the Projects in scope. The reader has no native inventory fallback.

The migration deletes native dependency observations, attempts, package identities, resolutions, edges, and the Instance sub-project discovery column. Old inventory is not imported into Swamp. A rollback restores empty tables, not deleted history. Generic Instance resolution, runtime hibernation, deployment package installation, and Task capabilities keep their existing owners. Domain and directory resolution errors use the `instance` family: `instance.domain_invalid`, `instance.directory_invalid`, `instance.target_not_found`, `instance.target_ambiguous`, and `instance.unavailable`.

## Why it works this way

Swamp already owns dependency maintenance. Keeping native scans or a fallback would leave two systems with different policy and evidence. The Gateway provides one bounded, authorized read path while Swamp owns the results and their targets. Immutable report pointers preserve a coherent publication and last-good provenance during failed refreshes. Explicit PR certainty prevents a failed lookup from claiming that no update is open.
