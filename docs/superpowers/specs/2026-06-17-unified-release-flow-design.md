# Unified Orbit Release Flow Design

Date: 2026-06-17

## Goal

Orbit is a monorepo. A release tag on `hardimpactdev/orbit` must publish one
version across all release surfaces:

- `hardimpactdev/orbit-core` Composer package source and tag.
- `hardimpactdev/orbit-cli` split source and tag.
- `hardimpactdev/orbit-gateway` split source and tag.
- GitHub Release assets on `hardimpactdev/orbit` for native CLI binaries.
- `ghcr.io/hardimpactdev/orbit-gateway:<version>` Docker image.
- `orbit-release-manifest.json` with digest-pinned image, CLI asset URLs, and
  hashes.

`orbit update:all` must consume that immutable manifest so an operator node can
update itself, replace the gateway and scheduler services, fan out CLI updates
to workload nodes, and finish only after verification passes.

## Current Findings

- `hardimpactdev/orbit`, `hardimpactdev/orbit-core`, and
  `hardimpactdev/orbit-cli` are public.
- `hardimpactdev/orbit-gateway` did not exist; it is now created as a public
  split target.
- `hardimpactdev/orbit-core` exists on Packagist. `orbit-cli` and
  `orbit-gateway` do not.
- Current workflows read separate version literals from CLI and gateway config
  files. That is not a monorepo release contract.
- The current release workflow builds CLI binaries, the gateway image, and a
  release manifest, but it does not publish split package repos or validate that
  the tag matches a canonical monorepo version.
- The durable update runner currently ignores failed per-node workload results
  and lets final verification report a generic CLI verification failure.

## Release Contract

`VERSION` at the monorepo root is the canonical release version without a `v`
prefix. Application config may read this file, but no app config file owns the
release number.

The monorepo release tag is `v<VERSION>`. The release workflow must fail before
publishing if the tag and `VERSION` differ.

The release workflow publishes split repositories from the tag checkout:

| Monorepo path | Public repo | Composer package | Release mutation |
| --- | --- | --- | --- |
| `packages/core` | `hardimpactdev/orbit-core` | `hardimpactdev/orbit-core` | push `main`, force-create tag `v<VERSION>`, trigger Packagist when configured |
| `apps/cli` | `hardimpactdev/orbit-cli` | `hardimpactdev/orbit-cli` | push `main`, force-create tag `v<VERSION>` |
| `apps/gateway` | `hardimpactdev/orbit-gateway` | `hardimpactdev/orbit-gateway` | push `main`, force-create tag `v<VERSION>` |

Split package publishing rewrites package metadata only in the temporary split
checkout. The monorepo keeps path repositories and `dev-main` constraints for
development; split outputs use the exact released core version.

Packagist publishing for `orbit-core` is best-effort and credential-gated:
when `PACKAGIST_USERNAME` and `PACKAGIST_TOKEN` exist, the workflow triggers an
update for `hardimpactdev/orbit-core`; otherwise the public split tag remains
the source of truth and Packagist can update from GitHub webhook/manual sync.

## Update Contract

`orbit update` and workload updates download public GitHub Release assets from
`hardimpactdev/orbit` by default. No GHCR or GitHub authentication is required
for release downloads.

The gateway update plan stores the manifest snapshot before side effects. The
runner uses that snapshot for the gateway image, CLI asset URLs, hashes, and
role image references.

Workload fan-out continues remaining nodes after one node fails, but the
workload phase is failed if any selected node failed. The runner records the
failed node results before final verification and does not hide the failure
behind a later generic CLI verification error.

## Verification

Code verification:

- Focused Pest tests for release workflow contract, version source, manifest
  generation, split package metadata rewriting, workload failure aggregation,
  and verification diagnostics.
- `composer quality-check`.

Operational verification:

- Create a new monorepo release tag.
- Confirm GitHub Actions succeeds.
- Confirm unauthenticated CLI release assets and manifest are downloadable.
- Confirm `ghcr.io/hardimpactdev/orbit-gateway:<version>` can be pulled.
- Run `orbit update:all` from the operator node.
- Confirm gateway and scheduler run the released gateway image.
- Confirm every selected workload node reports the released CLI version.
- Confirm `orbit node:list` still works after the update.
