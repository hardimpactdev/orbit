# Canonical orbit.dependencies.v1 fixtures

These five files pin the Swamp producer contract for Orbit ADR 0239 (originally 0238). They are synthetic examples for `github.com/fixture/dependency-app`, Project 20, `refs/heads/main`. Package versions, advisory IDs, groups and PRs are examples, not live evidence. No resources at these example coordinates have been deployed.

| File | Snapshots |
| --- | --- |
| `success.json` | Successful Composer and Bun inventories; direct/transitive, both scopes, multiple JS resolutions, registry/git/path origins; verified target; confirmed PR absence. |
| `running-partial.json` | First a running refresh at a new head with both last-good reports retained; then a partial refresh with one failed source retaining its old report and one new successful report. No batch admission. |
| `failed.json` | First scan fails before a commit is known; no last-good report; PR lookup also fails without prior evidence. Unavailable inventory, never an empty success. |
| `blocked.json` | Successful inventory and advisory finding, null verified target and explicit blocked reason. No batch admission. |
| `pr-unknown.json` | Open PR, then failed lookup retaining that value and its original last-success time. Includes a lifecycle report publication with its original inventory observation time. |

Every file contains `fixture_version: 1`, `scenario`, and ordered `snapshots`. Each snapshot contains one `index` exchange, selected `reports` exchanges, and `expected` reader outcomes. These wrapper fields are fixture metadata, not producer fields. Each exchange contains the literal native WebSocket `request` and `response`. JSON-decode `response.payload.data.content` to obtain the producer document. Reports in a snapshot supply exactly the referenced immutable versions; production readers fetch only selected sources.

## Native lookup

The producer behavior name is `orbit-gateway`, type `@nckrtl/orbit-gateway`. The behavior ID currently configured in this repository is `f5fa45d6-6307-48b6-a094-9aae8f79febc`; consumers use the stable name and the server's resolved ID, not a hardcoded environment UUID.

The deterministic current index name for Project 20 and `refs/heads/main` is:

```text
dependencies-v1-project-20-f921bd05e68b03740c450e565e0e6173e546193170b2dd404ddb6f153e9b5bf3
```

The suffix is lowercase SHA-256 of the UTF-8 full ref, without a newline. Read the current index with:

```json
{
  "type": "data.get",
  "id": "reader-request-id",
  "payload": {
    "modelIdOrName": "orbit-gateway",
    "dataName": "dependencies-v1-project-20-f921bd05e68b03740c450e565e0e6173e546193170b2dd404ddb6f153e9b5bf3",
    "includeContent": true
  }
}
```

Omitting `version` is intentional for the current index only. Every report request must pass its pointer's exact positive integer `version`. Native versions start at 1; they are not strings, timestamps or content hashes. A missing version is an error; never retry as latest. Exact-tag discovery, if used, must match `contract=orbit.dependencies.v1`, `kind=index`, decimal `project_id`, canonical `repository`, and full `source_ref`, and require exactly one current index.

Report names are `dependencies-v1-report-<publication_id>-<source_sha256>`. The source hash is SHA-256 over UTF-8 `JSON.stringify([path, ecosystem, package_manager])`, with no spaces or trailing newline. For `[".","composer","composer"]` it is `64da9c6b9583ae0c64fd780c2d9ccea8959252e57cd608441c257ca98dc27dcf`.

## Pinned producer fields

All fields shown in the fixture documents are required, including nullable fields. Times are UTC RFC 3339 strings. Repositories are lowercase `github.com/<owner>/<repo>`, without scheme or `.git`. Commits are full hexadecimal Git object IDs. Paths are relative checkout directories; `.` means root, without `..` components, leading slash or backslash. Package versions are opaque strings. Do not apply semantic-version validation to locked versions.

An index contains:

```text
schema_version: 1
publication_id: UUID
project_id: positive integer
repository: canonical repository
source_ref: full Git ref
source_commit: full SHA | null
generated_at: publication time
last_attempt: attempt
sources: source[]
batch_pr: PR evidence
```

`source_commit` is the latest attempted head, or null when acquisition failed before it was known. It does not overwrite a retained report's commit. A successful index has a known head and successful reports from every configured source at that head. Source identity is the exact `(path, ecosystem, package_manager)` tuple; duplicate tuples are invalid. The index includes every configured source, including failed ones.

An attempt is `{run_id, started_at, finished_at, status, error_code}`. The run ID is a bounded non-empty Swamp run identifier. Index status is `running | succeeded | partial | failed`. Each source attempt is atomic: `running | succeeded | failed`. `finished_at` is null only while running. `error_code` is null on running/success and a safe code on partial/failure. Failure messages and raw output are not included. Attempt times describe execution, not inventory freshness.

A source entry is:

```json
{
  "path": ".",
  "ecosystem": "composer",
  "package_manager": "composer",
  "last_attempt": {
    "run_id": "scan-run-id",
    "started_at": "2026-10-28T00:00:00Z",
    "finished_at": "2026-10-28T00:00:00Z",
    "status": "succeeded",
    "error_code": null
  },
  "report": {
    "modelIdOrName": "orbit-gateway",
    "dataName": "dependencies-v1-report-<publication_id>-<source_sha256>",
    "version": 1,
    "source_commit": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "observed_at": "2026-10-28T00:00:00Z",
    "publication_id": "<publication UUID>",
    "path": ".",
    "ecosystem": "composer",
    "package_manager": "composer"
  }
}
```

`report` is null only without a last-good inventory. Otherwise retain the entire pointer through a failed/running attempt. Validate every repeated identity field, commit, observation time and publication ID against the fetched report. `ecosystem` is `composer | npm`; `package_manager` is `composer | npm | pnpm | bun`, with Composer paired only with Composer and npm paired with the other three.

A source report contains `schema_version`, `publication_id`, `published_at`, `project_id`, `repository`, `source_commit`, `path`, `ecosystem`, `package_manager`, `observed_at`, `run_id`, `basis`, `observed_on`, `instance_id`, `packages`, and `findings`. Reports always have a known commit. Phase 4f produces `basis: locked`, `observed_on: repository`, `instance_id: null`. General provenance permits `basis: locked | installed` and `observed_on: repository | task_worker | instance`; only an actual observation on `instance` carries its positive `instance_id`. `installed` on `repository` is invalid. Worker observations use null Instance ID.

`publication_id` is a fresh UUID for each atomic index publication and for newly written reports in that publication. Retained reports keep their original publication UUID. Thus a new index can reference reports from older publications. `published_at` is the report publication time. `observed_at` and `run_id` belong to the original inventory scan. Republish a report for finding/admission changes under a new UUID and data name, preserving inventory observation time, scan run, commit and packages. Never overwrite a report name. Index generations are identified by native index version and `publication_id`; report pointers identify exact report generations.

A package resolution is:

```json
{
  "id": "example/library@1.0.0",
  "name": "example/library",
  "version": "1.0.0",
  "direct": true,
  "scopes": ["regular", "development"],
  "origin": "registry"
}
```

`direct` is a boolean: true if any relevant manifest directly declares the resolution; false means transitive only. `scopes` is a non-empty unique array of `regular | development`; preserve both when both apply. There is no separate `dev` flag or `transitive` string field. IDs are unique and stable within the same source across lifecycle republication. Preserve distinct resolved versions as separate rows, even with the same name. `origin` is `registry | git | path`; do not include raw origin URLs or local paths.

A finding is:

```json
{
  "id": "finding-example-advisory",
  "package": "example/library",
  "kind": "advisory",
  "current_versions": ["1.0.0"],
  "target_version": null,
  "advisory_ids": ["GHSA-2345-6789-cfgh"],
  "state": "blocked",
  "reason": {
    "code": "no_verified_target",
    "text": "No verified compatible fix is available."
  },
  "links": null
}
```

Finding IDs are unique and stable within their source; `package` references a package name in that report. `kind` is `major | advisory`. `current_versions` is non-empty and unique. `target_version` is an exact jointly verified version or null, never a range or a guess. Advisory IDs are non-empty for advisories and empty for majors. `state` is `ready | blocked | in_batch`. A null target requires blocked state. Policy may block an otherwise verified target. `reason` always contains a safe machine code and bounded human text. `links` is null or `{group_id: positive integer, run_id: non-empty string}`. Both fields must be present together. `in_batch` requires links and a target. These are the batch group and batch Swamp run, not the scan run or a PR URL.

PR evidence is `{status, checked_at, last_success_at, error_code, value}`. `status` is `open | absent | unknown`. `checked_at` is the latest lookup attempt time. `last_success_at` is the latest successful authoritative lookup time, or null without one. Successful `open`/`absent` has `checked_at == last_success_at` and null error. `absent` has null value; `open` has `{repository, number, url, head_commit, group_id, run_id}`. The repository is canonical; the URL is the matching HTTPS GitHub PR URL, and the head is a full SHA. `unknown` has a safe error code and retains the last successful value and timestamp, if any. A retained value is last-known evidence, not proof that the PR is currently open. `unknown` with null value and non-null last-success time can follow confirmed absence. Unknown with both null has no successful lookup history. Never infer absence from a null/missing URL or a failed lookup.

## Native reply envelope

The native WebSocket response is `{type: "data.get", id: <same request ID>, payload: {data: <native metadata>}}`. The metadata includes `name`, resolved `modelId`, `modelName`, `modelType`, positive integer `version`, `contentType: application/json`, `contentEncoding: utf-8`, and `content` as a JSON **string**. JSON-decode it exactly once. Native metadata fields such as ID, createdAt, size, checksum, tags and ownerDefinition are transport metadata, not producer fields. The fixture checksums cover the exact UTF-8 `content` bytes. `contentPath` is a native relative storage location; readers must not open it or expose it as a product link.

This envelope is verified against Swamp's `handleDataGet`, `DataGetResponse`, and `DataGetData` definitions. The installed pinned CLI is `20261022.021804.0-sha.983e2929`. Its `data get --json` presentation decodes JSON content to an object; that CLI presentation is different from the WebSocket wire reply. Error frames use native `type: error`; they do not become empty success documents. The consumer supplies rejection cases separately.

## Bounds and read semantics

Inclusive bounds are 65,536 UTF-8 JSON bytes per index, 64 sources, 1,048,576 UTF-8 JSON bytes per selected report, 5,000 resolutions and 500 findings per report. Measure decoded producer JSON content bytes, not fixture indentation or the WebSocket envelope. Overflow is an explicit failed attempt that retains prior good pointers; never truncate rows. No new batch can be admitted from running, partial or failed scan state, or from blocked findings without verified targets.

Safe field bounds in Unicode code points: package names and IDs 512, versions 256, relative paths/full refs 1,024, run IDs 256, codes 128, reason text 1,024, PR URL 2,048, repository 512. Codes use `[a-z][a-z0-9_]*`. Arrays remain subject to document byte bounds. Exclude credentials, raw command output, environment values and absolute local paths. Native metadata paths are not producer payload paths.

Inventory is stale at 26 hours since `observed_at`, immediately after a failed source refresh, or on a known head mismatch. A running refresh retains prior evidence; a new attempted head cannot relabel it. Blocked target findings can still be fresh inventory. PR evidence expires at five minutes from `last_success_at` and is unknown immediately on lookup failure. Validated Gateway cache lasts at most 60 seconds. Index/report lifetime and native version retention are both `infinite`; referenced old generations remain readable indefinitely.
