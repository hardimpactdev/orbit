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
- The GitHub Actions release workflow must publish:
  - split package repos and matching tags,
  - `orbit-linux-x64`,
  - `orbit-macos-arm64`,
  - `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>`,
  - `orbit-release-manifest.json`.
- `orbit update:all` is the acceptance path. It updates the operator CLI,
  gateway service, scheduler service, and selected workload node CLIs from the
  published manifest.

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

5. Run the broad quality gate before tagging:

   ```bash
   composer quality-check
   ```

6. Merge the worktree branch back to `main`, push `main`, then publish the
   monorepo GitHub release. The release workflow runs on the
   `release.published` event, so a tag push alone is not enough:

   ```bash
   version="$(bin/orbit-version)"
   git push origin main
   gh release create "v${version}" --target main --title "Orbit v${version}" --notes "Orbit ${version}."
   ```

7. Watch the `Orbit Release` workflow until it succeeds.
8. Verify public artifacts without authentication:

   ```bash
   version="$(bin/orbit-version)"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-release-manifest.json"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-linux-x64"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-macos-arm64"
   tmp="$(mktemp -d)"
   trap 'rm -rf "$tmp"' EXIT
   DOCKER_CONFIG="$tmp" docker pull "ghcr.io/hardimpactdev/orbit-gateway:${version}"
   ```

9. Run the live fleet acceptance from the operator node:

   ```bash
   orbit update:all
   orbit node:list
   ```

10. Confirm:
    - gateway service image is `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>`;
    - scheduler service image matches gateway;
    - every selected workload node reports `Orbit <VERSION>`;
    - `orbit node:list` succeeds after the update.

The release is not complete until the GitHub workflow and live fleet acceptance
both pass.

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
