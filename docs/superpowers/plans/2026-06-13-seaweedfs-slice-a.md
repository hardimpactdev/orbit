# SeaweedFS Slice A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Orbit's single-node RustFS S3 backend with a single-node SeaweedFS head while preserving the existing S3 command and routing contract.

**Architecture:** The `s3` role materializes a `seaweedfs` managed tool row and renders one Docker runtime container running `weed server -filer -s3` as the SeaweedFS head. Router and ingress flows stay unchanged except the backend port moves from `9000` to SeaweedFS S3 port `8333`; `s3-volume`, replication automation, and migration from existing RustFS data are deferred.

**Tech Stack:** Laravel 13 gateway, PHP 8.5, Pest 4, SQLite-backed `NodeTool` rows with encrypted credentials, Docker runtime container rendering, Caddy proxy route rendering, SeaweedFS `chrislusf/seaweedfs:4.33` image.

---

## File Map

- `apps/docs/content/product-decisions.md`: add the direction-changing S3 backend decision.
- `apps/docs/content/architecture.md`, `apps/docs/content/tech-stack.md`, `apps/docs/content/concepts.md`: replace RustFS authority text with the single-node SeaweedFS head contract.
- `apps/docs/content/domains/19_s3/**`, `apps/docs/content/domains/3_tool/**`, `apps/docs/content/domains/8_proxy/**`, `apps/docs/content/domains/1_node/**`: align S3, tool, proxy, and node-role docs with SeaweedFS and port `8333`.
- `apps/gateway/app/Tools/RustfsTool.php`: rename to `SeaweedfsTool.php` and change slug/probe metadata.
- `apps/gateway/app/Providers/AppServiceProvider.php`: register `SeaweedfsTool`.
- `apps/gateway/app/Services/S3/**`: change tool slug, runtime image/command, credentials config rendering, route backend port, doctor keys, and user-facing step labels.
- `apps/gateway/app/Http/Controllers/Api/S3PublishController.php`, `apps/gateway/app/Http/Controllers/Api/S3UnpublishController.php`: change progress labels from RustFS to SeaweedFS.
- `apps/gateway/tests/**/S3*Test.php`, `apps/cli/tests/Feature/Commands/S3/*Test.php`, `apps/gateway/tests/Unit/Services/Tools/ToolCatalogTest.php`: update expectations first, then implementation.

## Task 1: Product Documentation

- [x] Add a `2026-06-13` product-decision entry saying the `s3` role uses SeaweedFS instead of RustFS for the single-node backend.
- [x] Replace RustFS terms in S3 authority docs with SeaweedFS head wording.
- [x] Rename the tool catalog page from `rustfs.md` to `seaweedfs.md` and update catalog tables/links.
- [x] Change documented S3 backend port references from `9000` to `8333`.
- [x] Run `composer docs-lint`; expected result is no errors.

## Task 2: Failing Tests

- [x] Update `ToolCatalogTest` to expect slug `seaweedfs`, service `seaweedfs`, and container `orbit-seaweedfs`.
- [x] Update S3 runtime renderer tests to expect `chrislusf/seaweedfs:4.33`, command `weed server -filer -s3`, S3 port `8333`, and an identities config mount.
- [x] Update S3 baseline/configurator tests to expect a `seaweedfs` tool row with `mode=head`.
- [x] Update route registrar/proxy doctor tests to expect port `8333`.
- [x] Update doctor tests to expect `tool.seaweedfs.*` issue keys.
- [x] Update CLI/gateway command tests to expect SeaweedFS progress labels and `tool=seaweedfs` where the backend slug is exposed.
- [x] Run the focused S3/tool tests and confirm they fail because implementation still says RustFS.

## Task 3: Implementation

- [x] Rename `RustfsTool` to `SeaweedfsTool`; set slug `seaweedfs`, label `SeaweedFS`, required role `s3`, and Docker metadata for `orbit-seaweedfs`.
- [x] Update S3 service configuration to find, create, and return the `seaweedfs` tool row; preserve existing credential idempotency.
- [x] Render a SeaweedFS head container with S3 port `8333`, the role data path mounted at `/data`, and a generated `/etc/seaweedfs/s3.json` credentials config.
- [x] Change router backend URLs and proxy checks from port `9000` to `8333`.
- [x] Change doctor issue keys and summaries from `tool.rustfs.*` to `tool.seaweedfs.*`.
- [x] Change command progress labels and JSON metadata exposed by S3 commands from RustFS/rustfs to SeaweedFS/seaweedfs.

## Task 4: Verification And Merge

- [x] Run focused tests for S3, tool catalog, and CLI S3 commands.
- [x] Run `bin/orbit-gateway-vendor-bin pint --dirty --format agent`.
- [x] Run `composer docs-lint`.
- [x] Run `composer quality-check`.
- [x] Run targeted Docker prepared-topology E2E for the S3 private and ingress route tests with `ORBIT_E2E_DOCKER_MIN_PROCESSES=4`.
- [ ] Commit the worktree branch, merge it into `main` from `/Users/nckrtl/orbit`, remove the worktree/branch, and leave the primary checkout on updated `main`.
