---
title: "Dependency contracts"
description: "The Swamp dependency producer, native resource lookup, bounds, provenance, and Gateway read contract."
covers:
  - apps/gateway/app/Domain/Instances/Dependencies/**
  - apps/gateway/app/Data/Instances/Dependencies/**
  - packages/php-sdk/src/Requests/Instances/ShowInstanceDependenciesRequest.php
  - packages/php-sdk/src/Requests/Projects/ShowProjectDependenciesRequest.php
---

# Dependency contracts

Swamp publishes dependency evidence. The Gateway reads it with its workflow/data read credential over the same verified WebSocket transport used by the [Swamp runs panel](/reference/tasks#swamp-run-output). No read starts work. [Instance dependencies](/reference/instance-dependencies) describes the views and native-feature retirement.

## orbit.dependencies.v1

The producer is `orbit-gateway`. It publishes deterministic Project/ref indexes and immutable source reports with exact model/data/version pointers. The shared success, running/partial, first-failure, blocked, and PR-unknown fixtures pin the native RPC envelope and content fields. The published shape must cover both Composer and JavaScript projects through the existing shared behaviors and private project extensions.

### Scheduled Project intent

The Gateway publishes the complete public Project, group and active-operation snapshot through native `workflow.trigger.set` inputs for `maintenance-projects`. The native schedule runs hourly and selects daily detection, weekly routine work and bounded PR observations. Its steps read no Gateway state and post no admissions. The shared commit-feed subscriber delivers its terminal `maintenance/result` to the Projects family, which records replay identity and uses normal guarded admission for the selected Project operation.

A schedule input producer declares `publishes_inputs: true` in its family `scheduled-results.php`. Only that exact workflow receives the native workflow-write scope needed by `workflow.trigger.set`, in addition to run/read. Context workflows and other Operations retain run/read. The source grant validator rejects missing producer write authority, write on other workflows, duplicates and admin authority.

The matching manifest row also declares `publishes_inputs: true`; the source validator requires it to match the family declaration. The load-policy compiler permits run/read/write only on such a marked workflow and emits the exact declared scope. A load owner applies the manifest separately.

Successful Project results bind the immutable routine or package batch to one external Task group, then use normal guarded Tasks provision admission. A capacity refusal keeps the captured Todo group pending. A later native schedule observation may request that same guarded admission; changed intent, assistance or an execution hold refuses it. The current Incus provision root supplies the one VM and source/setup. Its terminal receipt admits `tasks.maintenance`, which composes the shared `task-vm-workspace` child and runs every package action through official pinned SSH from Node209. There is no guest Swamp worker, package-admission dispatcher, maintenance factory or step-side Gateway client.

The maintenance DAG exposes directory inspection, audit, target verification, manifest changes, dependency update and lock verification as separate steps. Exact package targets preserve declaration range style, refuse downgrades and stale advisory evidence, verify fixed security findings and check release age for every changed resolved package. JavaScript temporarily pins the exact solve, restores its declaration ranges and confirms lock synchronization changes no resolved version. Expo batches must match their selected SDK catalog. Repository package commands run only in the TaskVM.

Terminal results admit the normal handoff check. A failed business check permits one bounded Pi repair turn, followed by the same deterministic maintenance and check. A second failure requests investigation and retains the workspace. A passed changed batch admits normal checked-candidate publication and review parking. A passed no-change result uses the native ending DAG to remove the workspace and VM before completion. Failures preserve ownership and records.

PR and target links come from the captured maintenance intent and its exact native root, from Gateway inputs. Native PR observations authorize cleanup only after the admitted published PR has ended; an open PR keeps its parked VM and review custody.


Committed Project, group or Project-operation changes invalidate the published snapshot. A failed publication remains unaccepted. A terminal selection from a changed or unaccepted snapshot admits no work. The Gateway records admission slots with the published revision so replay cannot start a second operation for the same selection.

`project-detect` records a running scan attempt and revokes the previous plan before acquiring source. Checkout, authentication or manifest failure therefore cannot leave old findings eligible for new work. Readers can still inspect the previous immutable source reports and their original observation times. A late failure from an older run cannot replace a newer plan.

`project-observe` receives the captured PR URLs and makes one bounded GitHub read per URL with the existing repository read credentials. It returns public PR identity, state and head evidence. The Gateway applies that evidence only while the captured group watch is still current. Missing or failed reads remain unknown evidence.

The separate `dependency-upgrade` workflow takes its scratch checkout and branch as run inputs. Placed Git steps fetch the branch and capture its exact commit. Native scan reads that commit; native plan receives the scan output directly. Neither step uses the native-host dispatcher or selects an inventory through a mutable latest-resource read.

### Project index

Publish one persistent JSON index per Orbit Project and source ref. It identifies immutable, versioned source reports and the open batch update PR. Publish reports before switching the index so one read cannot combine different generations.

| Field | Required meaning |
| --- | --- |
| `schema_version` | Integer `1`. |
| `project_id`, `repository` | Exact Orbit Project ID and canonical repository identity. Never match by basename or title. |
| `source_ref`, `source_commit` | Full source ref and commit scanned. A missing commit is unknown provenance, not the current Instance commit. |
| `generated_at` | UTC publication time. |
| `last_attempt` | Run ID, start/end time, and `running`, `succeeded`, `partial`, or `failed`, with a safe error code when it failed. |
| `sources` | Relative sub-project path, ecosystem, package manager, latest attempt, and locator of its last successful report. A failed attempt retains that locator. |
| `batch_pr` | Explicit `open`, `absent`, or `unknown` state and observation time. Open evidence includes repository, number, HTTPS URL, head commit, Orbit group ID, and Swamp run ID. |

An upstream read failure produces `unknown`, never `absent`. PR evidence expires after five minutes. Inventory expires after 26 hours. A failed refresh retains the immutable pointer and its original source commit and observation time. Running attempts are visible and do not replace last-good evidence. Partial scans cannot admit incomplete update batches.

Use a deterministic native resource locator or exact tags for lookup: contract, Orbit Project ID, canonical repository, source ref, and record kind. The producer must supply the actual `modelIdOrName`, `dataName`, and immutable version coordinates supported by Swamp. Multiple matching current indexes are an error.

### Source report

Each source report belongs to exactly one project, repository commit, relative path, ecosystem, and package manager. It includes observation time and evidence for the version list.

| Field | Required meaning |
| --- | --- |
| `schema_version`, `project_id`, `repository`, `source_commit`, `path` | Version and exact source identity, repeated so a mismatched resource is rejected. |
| `ecosystem`, `package_manager` | `composer` or `npm` ecosystem; manager `composer`, `npm`, `pnpm`, or `bun`. |
| `observed_at`, `run_id` | UTC observation time and the Swamp run that produced it. |
| `basis`, `observed_on`, `instance_id` | `locked` or `installed`; observation on `repository`, `task_worker`, or `instance`; exact Instance ID for an Instance observation, otherwise null. |
| `packages` | Stable source-local ID, package name, opaque version, origin (`registry`, `git`, or `path`), direct/transitive status, and regular/development scopes. Multiple resolutions preserve multiple versions. |
| `findings` | Stable finding ID, package, kind `major` or `advisory`, current versions, exact target version or null, advisory IDs for advisories, state `ready`, `blocked`, or `in_batch`, safe reason code/text, and linked Orbit group/run when present. |

A blocked advisory without a verified fix has a null target and an explicit reason. Never invent a target or infer policy eligibility in the Gateway. The same finding row supplies the blocked-findings view, so there is no second list to reconcile. Local/path packages stay classified as local even when there is no registry update target. Source failures and policy blocks stay distinct.

The Instance view compares the report commit with its recorded checkout or deployed commit. A Project report for another commit remains clearly identified as a Project observation. Locked versions are labelled locked. Worker installations are labelled worker observations. Only an installed observation for that exact Instance may be labelled its installed versions. No matching observation means unknown, not an empty package list.

### Bounds and failure semantics

Bounds are 64 KiB per project index, 64 source entries, and 1 MiB per selected source report, with at most 5,000 package resolutions and 500 findings. The Gateway fetches selected sources on demand rather than all sources on every page read. Text and links have fixed limits; raw command output, environment values, absolute paths, registry credentials, and local repository URLs are excluded.

A producer that exceeds a bound publishes an explicit source failure and keeps the last successful report. It does not silently truncate an inventory or findings list. Gateway endpoints paginate rendered rows within the bounded report. The index is persistent. Keep v1 reports indefinitely. The Gateway refresh cache lasts at most 60 seconds; the cache retains the last valid result longer only to show stale evidence. Use the shared success, running/partial, failed, blocked, and PR-unknown fixtures for producer and consumer checks.


## Native lookup

Read `modelIdOrName: orbit-gateway` and index `dataName: dependencies-v1-project-<project_id>-<ref_sha256>`. The suffix is the full lowercase SHA-256 digest of the UTF-8 full ref, such as `refs/heads/main`. Canonical GitHub repository identities are lowercase `github.com/<owner>/<repository>`, without a transport or `.git`.

Reports have write-once names `dependencies-v1-report-<publication_uuid>-<source_sha256>`. The source digest hashes compact UTF-8 JSON `[path, ecosystem, package_manager]` without a trailing newline. Each pointer carries native `modelIdOrName`, `dataName`, and `version`, plus the expected source identity and commit. Read the exact version. Never replace it with latest.

The producer publishes reports before switching the index. Every index and report uses infinite lifetime and version retention for v1. The Gateway validates the selected report against its pointer and the index before exposing it.

## Gateway reads

The Instance endpoint is `GET /api/v1/instances/{instance}/dependencies`; the Project endpoint is `GET /api/v1/projects/{project}/dependencies`. Both enforce their existing resource access boundary. The selected source is fetched on demand; returned package and finding rows are paginated. A missing source means unknown evidence. An inaccessible resource is refused before reading Swamp.

Only validated and redacted evidence enters the cache, as plain arrays. Reads rebuild typed data from those arrays; they do not require PHP class deserialization. Failed reads log only a fixed error code (`tls`, `auth`, `invalid`, `timeout`, or `unavailable`), stage, and Project ID, without upstream text or credentials. A failed index read skips remote report reads. Failed index and report reads wait 30 seconds before retrying, while cached evidence stays stale or unavailable.

A failed refresh keeps prior evidence and marks it stale; it never extends its observation time. Without prior evidence the result is unavailable. PR certainty is recomputed from its producer check time on every response, including cached responses.

## Separate monorepo dependency sources

Each configured path and ecosystem owns its report. Two sub-projects can resolve different versions of the same package without replacing each other. The Gateway never merges them into an Instance installation claim. Swamp owns source discovery and whole-project batch eligibility.

## Selected source and pagination

The Gateway accepts optional `source` as the source's SHA-256 ID, `packages_page`, and `findings_page`. Both page numbers start at one. Each page contains at most 100 rows. Without `source`, select the first configured source; an index with no source keeps a null selection. A supplied source absent from the index is refused. Never use the source query as a native resource name.

The response contains the Project and optional Instance identity, selected full ref, the recorded Instance commit when known, read availability, publication time, latest attempt, source summaries, PR evidence, and the selected report. Source summaries expose paths, managers, attempts and last-good commit/time; they omit native storage coordinates. The selected report carries package and finding counts with their pages. Pagination changes only rendering; validation checks the complete bounded report first.

A valid native reply must identify the requested producer and data name, report the exact requested version, and contain UTF-8 JSON within the resource byte limit. The socket message and frame limits are set per read to six times the resource bound plus 4 KiB for the native JSON envelope, before decoding it. Reject malformed content, mismatched identities, unsafe paths, unsupported schemas, duplicate resolution or finding IDs, and report/pointer disagreement. Invalid input returns unavailable or stale evidence without exposing upstream exception text.

## Shared fixture fields

`publication_id` identifies each index generation. Reports add `publication_id` and `published_at`, while keeping the original inventory `observed_at` and scan `run_id` when finding or PR status changes. A source entry has `path`, `ecosystem`, `package_manager`, `last_attempt`, and nullable `report`. A pointer repeats that source identity, original `source_commit`, `observed_at`, and report `publication_id`, beside its native coordinates.

A package uses `direct: boolean`, `scopes: [regular | development]`, and `origin: registry | git | path`. A finding uses `package`, `current_versions`, nullable `target_version`, `advisory_ids`, `state`, `reason: {code, text}`, and nullable `links: {group_id, run_id}`. PR evidence uses `status`, `checked_at`, nullable `last_success_at`, nullable `error_code`, and nullable `value`. Unknown evidence can retain a last-known PR value; its last successful check time stays unchanged.

The shared fixtures include native `data.get` requests and replies. The reply's `payload.data.content` is a UTF-8 JSON string, with `contentEncoding: utf-8`, exact `modelName`, `name`, `version`, byte `size`, and SHA-256 `checksum`. Both sides check success, running then partial with retained evidence, first failure without inventory, advisory with no verified target, and open then unknown PR lookup.

### Text limits

Text safety rejects local `file://` URLs, Windows drive and UNC paths, and paths under common host directories: `/home`, `/fast`, `/etc`, `/tmp`, `/root`, `/var`, `/Users`, `/srv`, `/opt`, `/private`, `/mnt`, `/media`, `/usr`, `/run`, `/dev`, `/proc`, `/sys`, `/Volumes`, `/workspace`, and `/workspaces`. Ordinary slash-separated prose, documentation paths such as `/docs`, and package `file:` specifiers remain valid text.

Text limits count Unicode code points: package names and IDs 512, versions 256, relative paths and full refs 1,024, run IDs 256, codes 128, reason text 1,024, PR URLs 2,048, and repositories 512. Codes use `[a-z][a-z0-9_]*`. Finding package names reference packages in the same report. Current versions are unique and refer to that package's resolutions; major findings have no advisory IDs. These field limits supplement the JSON byte limits.

### Cache reuse

The Gateway re-reads the current index after at most 60 seconds. It can reuse an unchanged immutable report by its exact version. The cache keeps the last valid index and reports for seven days to serve stale evidence during outages; that retention never extends inventory or PR freshness.
