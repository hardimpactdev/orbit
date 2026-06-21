---
name: release
description: Use when creating a new Orbit release, publishing release artifacts, bumping Orbit versions, or handling requests like "let's create a new release".
---

# Release

Use this skill for Orbit release work. Orbit is a monorepo: one root version
applies to core, CLI, gateway, Docker image tags, release assets, and update
manifests.

## Release Contract

- The canonical version is the root `VERSION` file without a `v` prefix.
- The monorepo release tag is `v<VERSION>` on `hardimpactdev/orbit`.
- Do not publish releases from separate source repositories. The split repos
  are generated outputs:
  - `hardimpactdev/orbit-core` from `packages/core`
  - `hardimpactdev/orbit-cli` from `apps/cli`
  - `hardimpactdev/orbit-gateway` from `apps/gateway`
- Release artifacts are built once as release candidates and exposed through a
  topology-reachable `topology-candidate` manifest. GitHub publication promotes
  those exact tested assets; it does not rebuild them.
- The GitHub Actions release workflow must verify the promoted
  `orbit-linux-x64`, `orbit-macos-arm64`, `orbit-release-manifest.json`, and
  digest-pinned `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>` image, then
  publish the split package repos and matching tags. It must not run CLI binary
  builds, gateway image builds, or `gh release upload --clobber`.
- `orbit update:all` is the acceptance path. It updates the operator CLI,
  gateway service, scheduler service, and selected workload node CLIs from the
  candidate manifest before GitHub publication.
- Live topology doctor status is the release safety baseline. Capture it before
  publishing a new release so post-`update:all` doctor output can be compared
  against known pre-existing drift.
- No release may be published without E2E proof that the release candidate
  artifacts are functional. The proof must apply to the target version and
  commit being released, not an older branch, previous artifact set, or stale
  prepared topology.

## Workflow

1. Work from a prepared implementation worktree, not directly on `main`.
2. Confirm the release intent and choose the next version. If legacy split
   repos contain higher tags, choose a version higher than those tags or clean
   those generated repos intentionally.
3. Update only the root `VERSION` file for the version bump.
4. Run focused tests for release and update behavior:

   ```bash
   bin/orbit-gateway-pest --compact tests/Feature/Release tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php
   ```

5. Run release-candidate E2E proof before publishing anything. Without passing
   E2E proof, stop; do not merge, tag, or publish a GitHub release.

   ```bash
   composer test:e2e
   composer test:e2e:binary
   composer test:e2e:docker:binary-acceptance
   ```

   Run any additional provider-specific or artifact-backed E2E lane required by
   `apps/docs/content/testing/README.md` for the release assets or behavior
   being shipped. Use `composer e2e:ensure-artifacts` only to prepare missing
   artifacts; artifact preparation output is not proof by itself.

6. Build the release candidate assets once and generate a candidate manifest
   that points at the topology-reachable asset source:

   ```bash
   version="$(bin/orbit-version)"
   build_id="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"

   bin/orbit-build-cli-binary mac arm "$version"
   bin/orbit-build-cli-binary linux x64 "$version"

   # Build and publish/load the gateway image, then capture its sha256 digest.
   # The manifest must use the digest-pinned gateway image actually tested.

   bin/orbit-release-manifest \
     --version="$version" \
     --source=topology-candidate \
     --build-id="$build_id" \
     --asset-base-url="https://<topology-artifact-host>/releases/candidates/${build_id}" \
     --gateway-image="ghcr.io/hardimpactdev/orbit-gateway:${version}" \
     --gateway-digest="sha256:<tested-digest>" \
     --cli-artifact="linux-amd64=orbit-linux-x64=apps/cli/builds/dist/linux/linux-x64" \
     --cli-artifact="darwin-arm64=orbit-macos-arm64=apps/cli/builds/dist/mac/mac-arm" \
     --role-image="orbit-caddy=caddy:2-alpine" \
     --role-image="orbit-websocket=ghcr.io/hardimpactdev/orbit-websocket:${version}" \
     --output="orbit-release-manifest.candidate.json"
   ```

   Publish `orbit-linux-x64`, `orbit-macos-arm64`, and
   `orbit-release-manifest.candidate.json` to the topology asset host. Configure
   the target gateway's `ORBIT_RELEASE_MANIFEST_URL` to that candidate manifest.

7. Run the broad quality gate before tagging:

   ```bash
   composer quality-check
   ```

8. Capture live topology doctor status before publishing. Record the exact
   command, timestamp, target topology, and result summary in the release
   report. Existing drift does not necessarily block the release, but it must be
   known before `update:all` so new regressions are visible.
9. Run live topology acceptance against the candidate manifest from the operator
   node:

   ```bash
   version="$(bin/orbit-version)"
   orbit update:all
   orbit doctor
   orbit node:list
   ```

10. Confirm:
    - gateway service image is the tested digest-pinned
      `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>` image;
    - scheduler service image matches gateway;
    - every selected workload node reports `Orbit <VERSION>`;
    - post-update `orbit doctor` output has no new regressions compared with the
      pre-release baseline;
    - `orbit node:list` succeeds after the update.

11. Generate the final GitHub manifest from the same tested local assets. It must
    have `source=github-release` and the same CLI hashes and gateway digest as the
    accepted candidate:

   ```bash
   version="$(bin/orbit-version)"

   cp apps/cli/builds/dist/linux/linux-x64 orbit-linux-x64
   cp apps/cli/builds/dist/mac/mac-arm orbit-macos-arm64

   bin/orbit-release-manifest \
     --version="$version" \
     --source=github-release \
     --build-id="$build_id" \
     --gateway-image="ghcr.io/hardimpactdev/orbit-gateway:${version}" \
     --gateway-digest="sha256:<tested-digest>" \
     --repository="hardimpactdev/orbit" \
     --cli-artifact="linux-amd64=orbit-linux-x64=orbit-linux-x64" \
     --cli-artifact="darwin-arm64=orbit-macos-arm64=orbit-macos-arm64" \
     --role-image="orbit-caddy=caddy:2-alpine" \
     --role-image="orbit-websocket=ghcr.io/hardimpactdev/orbit-websocket:${version}" \
     --output="orbit-release-manifest.json"
   ```

12. Merge the worktree branch back to `main`, push `main`, then create a draft
    release, attach the tested files, and publish the draft. The release workflow
    runs on the `release.published` event, so a tag push alone is not enough:

   ```bash
   version="$(bin/orbit-version)"
   git push origin main

   gh release create "v${version}" \
     --target main \
     --title "Orbit v${version}" \
     --notes "Orbit ${version}." \
     --draft

   gh release upload "v${version}" \
     orbit-linux-x64 \
     orbit-macos-arm64 \
     orbit-release-manifest.json

   gh release edit "v${version}" --draft=false
   ```

13. Watch the `Orbit Release` workflow until it succeeds. It verifies the
    attached assets and digest-pinned gateway image, then publishes the split
    package repositories.

14. Verify public artifacts without authentication:

   ```bash
   version="$(bin/orbit-version)"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-release-manifest.json"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-linux-x64"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-macos-arm64"
   tmp="$(mktemp -d)"
   trap 'rm -rf "$tmp"' EXIT
   DOCKER_CONFIG="$tmp" docker pull "ghcr.io/hardimpactdev/orbit-gateway:${version}"
   ```

15. If doctor output changed after `update:all`, classify the delta before
    accepting the release. Fix release-caused regressions immediately when
    feasible. For intentional or pre-existing live-topology migration work,
    create scoped follow-up tasks with the before/after doctor evidence.

The release is not eligible to publish until release-candidate E2E proof and
live candidate `update:all` acceptance pass. It is not complete until the GitHub
workflow verifies the promoted assets and publishes the split package repos.

## Failure Handling

- If split package publishing fails, inspect `ORBIT_RELEASE_TOKEN` in the
  monorepo secrets. The workflow needs a token that can push to the public split
  repos.
- If Packagist does not show `hardimpactdev/orbit-core` after the split tag is
  pushed, check whether Packagist webhook/API credentials are configured. The
  GitHub split tag is still the source artifact.
- If GHCR pulls return 403 or unauthorized from an empty Docker config, make the
  `orbit-gateway` container package public before accepting the release. A
  credentialed gateway pre-pull is only a temporary live-diagnosis workaround,
  not release acceptance.
- If workload node updates fail, inspect the durable operation error first.
  Workload fan-out failures should fail before final verification and include
  per-node results.
