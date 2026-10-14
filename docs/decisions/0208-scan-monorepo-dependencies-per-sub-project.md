---
title: "Scan monorepo dependencies per sub-project"
description: "Keep separate dependency observations and errors for qualifying apps and packages in a monorepo Instance, without expanding dependency updates."
---

# ADR 0208: Scan monorepo dependencies per sub-project

Orbit records a separate dependency inventory for each qualifying web project under a monorepo's `apps/` and `packages/`. The Gateway owns these inventories within the Instance; the API, SDK, and CLI expose the same source boundaries.

## Status

In progress.

Principle: [Deterministic first and One way, one name](/mission#principles). The presence of specific files decides whether a source qualifies. Each source has one identity across storage and responses. There is no exception to a mission principle: root fields retain their single-directory meaning rather than becoming a second representation of the sub-project graphs.

## Context

The existing [Instance inventory](/reference/instance-dependencies#what-the-inventory-holds) reads one dependency directory. A monorepo can have independently locked apps and packages below its repository root, so reading only the root misses those dependency trees. Two sources can resolve different versions of one package, and one invalid lockfile must not prevent successful sources from publishing their observations.

Adding sources changes the ownership of stored observations and attempts and the inventory returned across the Gateway, SDK, and CLI. The existing reader refusals, last-success preservation, read-only collection, and path safety remain the baseline. This decision does not fix unsupported formats or stale lockfiles.

## Decision

- Apply discovery only to Instances whose Project type is `monorepo`. All other Project types keep their existing single-directory behavior exactly.
- Consider only direct child directories of the repository's top-level `apps/` and `packages/`, in the checkout or selected production release. Do not recurse into candidates or search elsewhere.
- A candidate qualifies if it contains `composer.json`, or `package.json` with at least one of `package-lock.json`, `npm-shrinkwrap.json`, `pnpm-lock.yaml`, or `bun.lock`. Skip other directories and record nothing for them. Qualification does not imply that the files parse successfully.
- If neither `apps/` nor `packages/` exists, keep the existing single-directory scan. The presence of either directory enables discovery even if no candidate qualifies.
- Scope each sub-project's observations and attempts by Instance, repository-relative sub-project identity (such as `apps/gateway`), and ecosystem. Keep resolutions and requirements within that observation, while package identities remain shared. Do not combine graphs or overwrite another source's resolved versions.
- Apply existing reader errors and last-success preservation separately to each sub-project and ecosystem. A failed source records its own error and does not fail or stop siblings. Successful siblings still publish. The overall scan reports failure if a scanned ecosystem fails.
- Keep collection read-only and retain the existing per-file size, total-size, path-safety, and SSH limits. Batch candidate discovery and reads within the Instance's collection flow, not one SSH request per candidate. If the collector reads in one SSH round trip per Instance, keep that property.
- Return per-sub-project inventory through scan and read operations, including `instance:dependencies:show`, the API, SDK, and CLI. Preserve the existing root fields and their single-directory meaning for existing clients; do not turn them into a merged graph. This contract does not mandate a new JSON property name or database schema.
- Leave `instance:dependencies:update` repository-root-only for monorepos. Updates for other Project types remain unchanged. Discovery authorizes file reads, not package commands in sub-projects.

The detailed rules live in [Monorepo sub-projects](/reference/instance-dependencies#monorepo-sub-projects), [Collection](/reference/instance-dependency-contracts#collection), [Stored inventory](/reference/instance-dependency-contracts#stored-inventory), and [Responses](/reference/instance-dependency-contracts#responses). This decision does not change the application-directory derivation in [ADR 0196](/decisions/0196-derive-application-directory-from-web-root) for non-monorepo types.

## Rejected alternatives

- Scan only the repository root: it misses independently locked apps and packages.
- Merge every source into one Instance graph: it loses source ownership, can mix resolution IDs, and cannot preserve separate failures and resolved versions.
- Recursively discover every manifest: it can select nested fixtures and dependency directories that are not independent web projects.
- Fail the whole monorepo on one invalid lockfile: it prevents valid siblings from refreshing their inventory.
- Enable workspace readers or update each discovered project: these are separate capabilities, not required to read independently locked sources.

## Consequences

- Monorepo inventory describes each qualifying web project's locked dependencies, including differing versions and source-specific errors.
- Storage and response decoding must preserve the sub-project boundary. The root response remains available without serving as a monorepo rollup.
- Non-qualifying directories have no inventory or attempts. Unsupported inputs inside qualifying sources still fail under the existing reader rules.
- No Project-level rollup, new nightly scheduling behavior, reader-error repair, or per-sub-project update is introduced.
- The completing implementation subtask absorbs this record into the owning reference pages and retires it under the [decision lifecycle](/decisions/overview#decision-lifecycle).

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: none amended; ADR 0196 retains its non-monorepo application-directory contract.
- Detail: [Dependency contracts: Separate monorepo dependency sources](/reference/instance-dependency-contracts#separate-monorepo-dependency-sources)
- Verify: Gateway tests for discovery, non-monorepo and no-directory fallback, independent versions, isolated failures, last-success preservation, read-only safe collection, and root-only updates; SDK and CLI tests for per-sub-project scan/read output and unchanged root fields; `composer docs-build`, `composer docs-lint`, and `composer check`.
