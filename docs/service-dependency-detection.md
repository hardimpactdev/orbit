# Services-side dependency detection

Phase 4f producer implementation. Swamp owns dependency scanning, policy, target planning
and updates. Orbit reads dependency state and supplies the existing task
operations. The producer contract below answers Orbit draft ADR 0239 (originally 0238),
`orbit.dependencies.v1`. This branch implements the producer; these
resources are not deployed yet.

The canonical wire fixtures and pinned field definitions are in
[`tests/fixtures/dependency-results-v1/README.md`](../tests/fixtures/dependency-results-v1/README.md).
Those files define the producer/consumer boundary before cutover. They are
synthetic examples, not observations of the live fleet.

## Scan without a task VM

Keep each private project extension's repository, default branch, ecosystem
paths, policy and schedules. Its detect entry starts a native Swamp workflow on
Services. Detection creates no Orbit group, reserves no maintenance VM and
enrolls no worker.

Use a bounded Git CLI clone with hooks and checkout disabled to obtain a disposable checkout and pin
the scan to one default-branch SHA. A checkout preserves workspace and
local-package relationships that individual GitHub file reads could miss. Use
Services-side repository read access; credentials do not belong in project
config or results.

The shared Composer and JS extensions read manifests and lockfiles at the
configured paths, including `mobile/`. Lockfile inventory contains the resolved
versions, including transitive packages and multiple JS resolutions. These are
locked versions at a Git commit, never an Instance installation observation.
Existing ecosystem code owns registry/advisory reads, Expo alignment,
release-age policy and eligible target selection. Project extensions contain no
scanning logic.

Scanning does not install dependencies or run project scripts. In particular,
replace the JS detect path's Vite+ bootstrap with lockfile-based inspection.
Verify joint target compatibility through native lockfile-only resolution in a
disposable planning tree, using the package task's platform constraints.
Preserve tracked symlinks and workspace inputs instead of rejecting all
non-regular files. An unsupported or unsolved target remains a blocked finding
with its reason.

Services scans run on a separate native `dependency-scanner` model. Only bounded publication calls take the `orbit-gateway` lock. The scanner serializes scan entries independently of minute orchestration ticks; no scan holds the admission/group-stage lock. Each scan has a two-minute abort budget, shorter than deployment's three-minute drain. Git acquisition and package processes receive the abort signal. PR lookups have a three-second deadline and cancellation signal. No custom lease or background process is involved.

Package commands run only in a disposable tree containing tracked manifests and lockfiles. Repository `.npmrc`, Bun config, scripts, executables, Git metadata and credentials never enter it. Commands use a fresh home, explicit public-registry config, `git=git`, scripts disabled and an allowlisted environment. Composer repository types other than the approved public Composer registry are refused. External dependency URLs must name approved public endpoints; local packages must resolve within the manifest tree. Unsupported inputs remain failed/blocked data rather than running on Services.

## Persist state, then admit updates

Save the inventory, findings, eligible exact targets and blocked reasons as one
coherent, versioned snapshot tied to the scanned SHA and policy. A failed
refresh records its attempt and error and retains the last successful snapshot
with explicit freshness information. It cannot masquerade as a clean scan or
admit unverified targets.

Only a successful plan with eligible updates enters existing batch admission.
Keep one immutable whole-project package group and one PR, security priority,
fair rotation and one active package group per project. The shared four-VM cap
now covers routine and package execution. A full cap delays updates without
preventing scans or state publication. Revalidate the source head and targets
before applying a queued batch; a changed head requires a fresh plan.

The official package factory retains provisioning, worker execution, checks,
normal repair in the same VM, publication and cleanup. Do not fork
`@swamp/software-factory`. Retire the detect factory and its provision/worker
path from new detection. Existing groups require explicit operator cleanup;
deployment does not cancel or rewrite them.

## Orbit producer contract: orbit.dependencies.v1

Accept ADR 0239's ownership and seven producer requirements, with the explicit
attempt, PR-lookup, freshness and retention details below. Use native versioned
Swamp resources, following the manual's
[behaviors and data model](https://swamp-club.com/manual/explanation/models-types-and-methods),
not a new HTTP service. The Gateway reads and validates this contract; it never
starts maintenance or derives targets.

### Project index — accept, add running attempt status

Publish one persistent index per exact Orbit Project ID and full source ref.
Fields are `schema_version: 1`, `project_id`, `repository`, `source_ref`,
`source_commit`, `generated_at`, `last_attempt`, `sources`, and `batch_pr`.
Canonical repository identity is `github.com/<owner>/<repo>`, without a
transport, credentials or `.git`. For GitHub, owner/repository identity is lower
case. Source refs are full refs such as `refs/heads/main`; commits are full Git
SHAs.

`last_attempt` records `run_id`, `started_at`, nullable `finished_at`, status
and safe error code. Add `running` to `succeeded`, `partial`, `failed`, so an
active refresh is visible without inventing a final outcome. Each source repeats
path, ecosystem, package manager and its latest attempt, plus an immutable
last-good report locator. A failure preserves that locator. No prior successful
report means an absent locator with a failed/unavailable state, never an empty
success.

Publish all new immutable reports before switching the index in one write. A
partial refresh explicitly identifies each retained report's original commit and
observation time. It does not relabel an older report with the latest attempt's
commit. A source pointer includes its expected `source_commit` as well as native
resource coordinates. A failed source never supplies eligible targets to new
admission; other verified sources may publish their own results. New project
batch admission requires successful detection/planning for every configured
source at the same head and policy, so a partial scan cannot silently omit
eligible updates.

### Version reports — accept

Each report repeats `schema_version`, `project_id`, `repository`,
`source_commit`, `path`, `ecosystem`, `package_manager`, `observed_at` and
`run_id`. `ecosystem` is `composer` or `npm`; manager is `composer`, `npm`,
`pnpm` or `bun`. Advertising a lockfile inventory format does not promise that
manager supports update planning; unsupported planning is an explicit blocked
reason.

`packages` preserves each distinct resolution with a stable source-local ID,
package name, opaque locked version, direct/transitive classification, regular
and development scopes, and origin `registry`, `git` or `path`. A resolution
needed by both scopes retains both. Multiple resolved versions in a lockfile
remain separate rows. Local/path packages retain their origin without exposing
local URLs, credentials or absolute paths.

### Provenance — accept

Reports use `basis: locked | installed`,
`observed_on: repository | task_worker | instance`, and nullable `instance_id`.
Phase 4f's Git scan produces only `locked` + `repository` + null Instance. Only
an actual installed observation on the exact Instance may be labelled that
Instance's installed versions. A worker observation cannot acquire Instance
provenance by matching its repository or commit. An unmatched commit is explicit
Project evidence or unknown Instance state, never an empty installed inventory.

### Findings — accept

`findings` contains stable IDs, package identity, `kind: major | advisory`,
current versions, exact verified target or null, advisory IDs, state
`ready | blocked | in_batch`, safe reason code/text and linked group/run when
present. A finding without a valid target is blocked data and is never admitted.
Keep policy eligibility and target verification in Swamp. Source read failure
and a valid policy block are different outcomes.

Source reports are immutable snapshots. Admission or PR lifecycle changes
publish new report snapshots and switch the index, retaining the original
inventory observation time and scan run. A publication generation distinguishes
those snapshots. This updates finding state and batch links without rescanning
or mutating a previously referenced report.

### Batch PR — accept, make lookup certainty explicit

Use a discriminated `batch_pr` object with `status: open | absent | unknown`,
`checked_at`, nullable `error_code`, and `value`. An open `value` contains
`repository`, `number`, `url`, `head_commit`, `group_id` and `run_id`. `absent`
requires a successful authoritative lookup and a null value. `unknown` means the
lookup failed; retain the last known PR value, if any, and its last successful
check time. Unknown without previous evidence has null value but never means
confirmed absence. The batch group/run remain available in findings even before
a PR exists. Do not infer absence from a missing URL or a failed GitHub request.

### Native lookup and fixtures — accept

Use the existing shared `orbit-gateway` behavior as producer, with index data
name `dependencies-v1-project-<project_id>-<ref_sha256>`. `ref_sha256` is the
full lower-case SHA-256 hex digest of the UTF-8 full source ref. For Project 20
the lookup is `modelIdOrName: orbit-gateway` and that exact deterministic data
name; validate `repository` in the payload as well as Project ID and ref.

Index tags are `contract=orbit.dependencies.v1`, `kind=index`,
`project_id=<decimal ID>`, `repository=<canonical repository>`, and
`source_ref=<full ref>`. Exact-tag discovery must produce exactly one current
index; zero is unavailable and multiple matches are a contract error.

A report uses a write-once data name
`dependencies-v1-report-<publication_uuid>-<source_sha256>`, where the source
hash is SHA-256 over UTF-8 `JSON.stringify([path, ecosystem, package_manager])`,
without whitespace or a trailing newline. Pointers carry `modelIdOrName`,
`dataName`, and the native returned `version` value, plus expected source
identity/commit. Consumers must read that exact version, never substitute
latest. The phase 4f producer tests will pin the actual native RPC arguments and
byte-level hash encoding; no deployed locator or unverified transport shape is
claimed by this design.

Provide checked-in fixtures for success, partial refresh retaining old reports,
first failure without inventory, blocked findings, and PR lookup failure. Add
native reader round-trip coverage, duplicate-index rejection, immutable-version
reads and boundary failures. Deliver these with the producer implementation
before Orbit switches its reader.

### Bounds, freshness and retention — accept with explicit defaults

Enforce 64 KiB UTF-8 JSON per index, at most 64 source entries, and 1 MiB UTF-8
JSON per selected source report, with at most 5,000 distinct resolutions and 500
findings per report. Limits are inclusive. An overflow records an explicit
source failure and preserves the last-good report; if the index itself would
overflow, publish a bounded failed-attempt index retaining its prior report
pointers. Never truncate rows, hide sources or admit an incomplete plan. A
configuration with more than 64 sources fails before scanning instead of listing
only 64. Safe text/URL limits belong to the producer schema and reader fixtures;
exclude raw output, credentials, environment values and local absolute paths.

Agreed freshness: inventory/findings become stale 26 hours after their last
successful observation, immediately on a failed refresh for that source, or when
a known source-head mismatch is observed. Republishing batch status does not
reset inventory freshness. PR lookup evidence becomes stale after five minutes;
a failed lookup is unknown immediately. Gateway cache age must be at most 60
seconds and cannot turn stale evidence into current evidence. These are read
semantics; project policy and production schedules do not change.

Index history follows the seven-day, reference-aware retention policy below. Latest indexes remain readable and reports referenced by retained indexes are never age-pruned. The five frozen wire examples and their original illustrative metadata remain unchanged. The Gateway reader does not interpret GC metadata.

## Delivery and verification

The existing 19 project detect workflows now call Services detection directly.
They create no schedule receipt, group, worker or VM, and are not blocked by an
unfinished package PR. Each project extension pins the JavaScript manager per
path in `worker.jsPackageManagers`; root and mobile locks were checked against
the repositories. npm v2/v3, Bun text locks and pnpm v9 inventories preserve
distinct resolutions and scopes. pnpm planning remains unsupported: its real
registry/advisory candidates become blocked findings. Composer and npm/Bun
planning reuse the shared native lockfile-only solvers and existing policy.
Detection never bootstraps Vite+ or imports project code. Composer queries and
planning disable plugins and scripts. Tracked internal symlinks are preserved;
external symlinks fail before repository contents are scanned.

Only a complete successful scan writes admission-eligible internal findings.
The scheduler rechecks the exact report-generation scan run, source SHA, ref,
freshness and full config/policy identity, then reads the remote head through
official Git before creating a package group. A mismatch requires a fresh scan.
The package group pins the same SHA and refuses execution when provisioning
checked out a different head. Old unstarted VM-era detect receipts are never
admitted. Their records and existing groups stay intact for operator cleanup;
old unpinned findings cannot admit new packages.
An obsolete package intent is superseded only when no create was ever
attempted and no group is bound. Its complete request and targets remain in
the completed ledger with `supersededBy`; only an unbound reservation is released.
Uncertain creates, operator assistance and real groups remain held.

The minute project tick rotates over at most six indexes for PR reconciliation.
It reads supported Orbit group responses and Swamp publish/owner evidence,
then verifies recorded PR URLs and heads through GitHub. Failure is immediately
`unknown`, retaining last success and existing links. Successful absence is
explicit. Lifecycle report publications keep original inventory commit, time,
packages and scan run. Existing one-batch-per-project and four-VM admission
guards continue to own updates; publication adds no Gateway admission logic.

Before deployment, Services needs Git, `/usr/bin/setsid` (util-linux), and the shared PHP/Composer, npm and Bun tools
and read access to all configured GitHub repositories. The ordinary task
platform and Composer `config.platform` constraints must match Services
planning. The Git and GitHub CLIs consume the service account's configured
credentials; no credential is placed in project config, findings or reports.
Git acquisition and PR observation run outside the package-tool policy and retain the Services `orbit` account's HOME (`/home/orbit`); only package commands use the disposable scratch HOME. PR observation passes `GH_CONFIG_DIR=${XDG_CONFIG_HOME:-$HOME/.config}/gh` explicitly to gh. With the standard Services home this is `/home/orbit/.config/gh/hosts.yml`, owned by `orbit`, provisioned by the operator through gh's credential setup. gh reads that config itself; the producer never reads, copies or logs it. Environment tokens (`GH_TOKEN`, `GITHUB_TOKEN`) are not forwarded. Git acquisition similarly relies on the account's configured Git credentials (for HTTPS, gh's credential helper can use the same account config). No credentials enter the package-tool tree or environment.

The historical Services host had no GitHub access, so Nick verifies this prerequisite during deployment: a real nckrtl.com scan must report `batch_pr.status` as `open` or `absent`, not `unknown`. Missing gh credentials or a failed lookup produce `batch_pr: unknown`, preserving the prior value and `last_success_at`, without crashing the scan. A missing tool or credential yields
a failed/partial index or unknown PR evidence, never a native Orbit fallback.

Release order: merge the reviewed producer, deploy it to Services, invoke the
existing nckrtl.com detect entry, and read Project 20's exact `refs/heads/main`
index and all pointed report versions through authenticated `data.get`.
Confirm real inventory and provenance. Only then merge Orbit #1553, whose
Gateway auto-release retires the native inventory path. A successful scan may
still contain blocked findings; it must not invent eligible targets.

First validate the contract fixtures/native lookup and Services source
acquisition. Then move ecosystem inventory/detection/planning onto that source,
wire the existing project detect entries to publish state and submit eligible
batches, and remove new detect groups from VM admission. Preserve project policy
and production cadence.

Cutover must also retire the pending VM-era detect intents before enabling the
new entry path. Otherwise the old queue could still allocate detect VMs after
deployment. Preserve the current wave's evidence and have the operator clean up
its four parked groups; do not reset factories or silently rewrite live groups.

Affected tests cover zero Orbit group/worker/VM calls during detect; supported
lockfiles, symlinks and workspaces; policy and blocked-target evidence; advisory
changes at an unchanged SHA; head changes while queued; atomic read snapshots;
and idempotent batch admission under the four-VM cap. After review, merge and
orchestrator deployment, prove one VM-free scan and one admitted package batch
before a further fleet wave.

## Publication retention

PR observations reuse the existing index when their meaning is unchanged and its certainty is less than four minutes old. A changed state/value/error or an expiring certainty publishes immediately. Scan attempts and lifecycle changes remain explicit publications. Index versions are retained for seven days, keeping the latest index even when older. Immutable reports remain while any retained index references their exact version; unreferenced reports can be removed only after seven days. The pinned Swamp duration GC already protects each name's latest version. Only the reference-aware prune method applies age retention to these resources; native GC uses the maximum supported version count, not an age cutoff. Cleanup runs under the same short publication lock and uses native repository APIs. The frozen wire examples remain unchanged; their retention metadata is synthetic.

A successful source beside a failed sibling publishes fresh inventory with `sibling_source_failed` findings. A planner failure publishes inventory with `project_planning_failed` findings. Neither supplies batch eligibility. The stored native JSON is compact; actual UTF-8 content, not a pretty-printed estimate, must satisfy the 64 KiB index and 1 MiB report limits.

The scanner reads manifest blobs from the Git index without checkout or smudge filters. Manifest symlinks are unsupported. pnpm YAML is capped at 16 MiB, uses JSON-schema parsing, and refuses standard anchor/alias tokens before parsing. All 19 configured project extensions currently declare npm or Bun, not pnpm. Registry endpoint allowlists are explicit. JS dependency specs and npm lock resolutions accept only semver ranges, dist-tags, strict npm aliases, approved HTTPS registry URLs, workspace ranges and local package directories within the scratch tree. Bare tarball paths and unrecognized forms are refused. Bun validates descriptor resolutions and the separate tarball location, not package names or arbitrary metadata. Unsupported git resolutions and custom registries do not reach package tools.

Local JS dependencies use file-URL resolution relative to the manifest directory, followed by a scratch-root boundary check. Drive markers (`:` or `|`), any `~` path segment and encoded or alternate path spellings are refused before resolving. Bun folder tuples may carry metadata in the second element; only a string there is a tarball location. Empty registry specs mean `*`. Catalog references and their definitions are validated separately from workspace paths; npm `$package` references are accepted only inside overrides and must name a declared dependency. npm lock checks visit package entries and dependency constraints, not unrelated metadata such as a bin named `version`.
