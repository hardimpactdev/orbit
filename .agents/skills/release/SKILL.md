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
  topology-reachable `topology-candidate` manifest. Candidate CLI binaries and
  manifests live in the central artifact store on the Laravel
  `orbit-artifacts` disk, under immutable `candidates/<BUILD_ID>/` paths.
  Activating a candidate copies its manifest to a stable channel path,
  `channels/<CHANNEL>/orbit-release-manifest.json`, so live-test gateways can
  keep one custom manifest URL selected through `orbit manifest:update <url>`
  while each new candidate is still identified by its immutable build id.
  Candidate gateway images are pushed to GHCR package tags such as
  `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>-candidate-<BUILD_ID>` and are
  always digest-pinned in the manifest.
- GitHub publication promotes those exact tested assets; it does not rebuild
  CLI binaries or gateway images. The accepted gateway image digest is promoted
  to the final `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>` package tag
  before the GitHub release is published.
- GitHub release tags and release assets are created only after live candidate
  acceptance and explicit human approval to publish. A successful candidate
  `update:all` is not by itself approval to create a GitHub release.
- If the user requests a live artifact release with no GitHub release, stop
  after live candidate acceptance. Do not create a GitHub tag, publish a GitHub
  release, or move the final GHCR version tag in that mode.
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
- Candidate artifacts are published from a commit already reachable from
  `origin/main`. Merge and push the release VERSION commit before artifact
  publication, then record the release worktree commit, primary `main` commit,
  `origin/main` commit, and `VERSION`.
- Topology-specific retained-environment verification is outside this release
  workflow. If the release scope depends on that evidence, complete it before
  starting this skill and carry only the evidence reference into the release
  notes. Do not acquire or troubleshoot retained environments during the
  artifact publication flow.

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

5. Commit the version bump, then run the broad quality gate before publishing
   candidate artifacts:

   ```bash
   version="$(bin/orbit-version)"
   git add VERSION
   git commit -m "Bump version to ${version}"
   composer quality-check
   composer quality-gate:final-check
   ```

6. Merge the release worktree branch back to primary `main`, push `main`, and
   prove the pushed source identity before uploading candidate bytes:

   ```bash
   version="$(bin/orbit-version)"
   release_branch="$(git branch --show-current)"
   release_commit="$(git rev-parse HEAD)"
   git status --short --branch

   primary_checkout="${ORBIT_PRIMARY_CHECKOUT:-${HOME}/orbit}"
   git -C "$primary_checkout" fetch origin
   git -C "$primary_checkout" status --short --branch
   (cd "$primary_checkout" && bin/orbit-feature-finalization-check git merge "$release_branch")
   git -C "$primary_checkout" push origin main

   primary_main_commit="$(git -C "$primary_checkout" rev-parse main)"
   origin_main_commit="$(git -C "$primary_checkout" ls-remote origin refs/heads/main | awk '{ print $1 }')"
   if [ "$release_commit" != "$primary_main_commit" ] || [ "$release_commit" != "$origin_main_commit" ]; then
     echo "Release commit, primary main, and origin/main must match before artifact publication." >&2
     exit 1
   fi
   ```

   When the release is Mini-owned, run this proof on Mini and record the Mini
   release worktree path, branch, commit, `origin/main` commit, and clean/dirty
   status. Do not infer Mini state from another machine.

7. Build and publish the release candidate artifacts once from the pushed
   source commit. Use the central artifact store for files and GHCR for the
   gateway image. Do not use S3
   image tarballs as the normal gateway image path; Docker Swarm must consume an
   OCI registry reference.

   ```bash
   version="$(bin/orbit-version)"
   source_commit="$(git rev-parse HEAD)"
   origin_main_commit="$(git ls-remote origin refs/heads/main | awk '{ print $1 }')"
   if [ "$source_commit" != "$origin_main_commit" ]; then
     echo "Candidate artifacts must be built from the pushed origin/main commit." >&2
     exit 1
   fi

   build_id="$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD)"
   candidate_tag="${version}-candidate-${build_id}"
   candidate_image="ghcr.io/hardimpactdev/orbit-gateway:${candidate_tag}"
   candidate_dir="$(mktemp -d)"
   candidate_prefix="candidates/${build_id}"
   candidate_channel="${ORBIT_RELEASE_CANDIDATE_CHANNEL:-live-test}"

   # Prepared worktrees symlink the primary checkout's root .env. Source that
   # local release env into this shell before Laravel reads apps/gateway config.
   # The env must include ORBIT_ARTIFACTS_BASE_URL and either ORBIT_ARTIFACTS_*
   # keys or the S3_UPCLOUD_* fallback keys.
   primary_checkout="${ORBIT_PRIMARY_CHECKOUT:-${HOME}/orbit}"
   primary_env="${ORBIT_RELEASE_ENV_FILE:-}"
   if [ -z "$primary_env" ]; then
     if [ -f ".env" ]; then
       primary_env=".env"
     else
       primary_env="${primary_checkout}/.env"
     fi
   fi

   if [ -f "$primary_env" ]; then
     set -a
     source "$primary_env"
     set +a
   fi

   artifact_base_url="$(bin/orbit-gateway-artisan tinker --execute='echo rtrim((string) config("orbit.artifacts.base_url"), "/");')"
   if [ -z "$artifact_base_url" ]; then
     echo "ORBIT_ARTIFACTS_BASE_URL is required for candidate artifact publishing." >&2
     exit 1
   fi

   artifact_disk_ready="$(bin/orbit-gateway-artisan tinker --execute='echo collect([
       config("filesystems.disks.orbit-artifacts.key"),
       config("filesystems.disks.orbit-artifacts.secret"),
       config("filesystems.disks.orbit-artifacts.bucket"),
       config("filesystems.disks.orbit-artifacts.endpoint"),
   ])->every(fn ($value) => filled($value)) ? "yes" : "no";')"
   if [ "$artifact_disk_ready" != "yes" ]; then
     echo "orbit-artifacts disk config is incomplete; check ORBIT_ARTIFACTS_* or S3_UPCLOUD_* env values." >&2
     exit 1
   fi

   candidate_asset_base_url="${artifact_base_url}/${candidate_prefix}"

   bin/orbit-build-cli-binary mac arm "$version"
   bin/orbit-build-cli-binary linux x64 "$version"

   cp apps/cli/builds/dist/linux/linux-x64 "${candidate_dir}/orbit-linux-x64"
   cp apps/cli/builds/dist/mac/mac-arm "${candidate_dir}/orbit-macos-arm64"

   docker buildx build \
     --platform linux/amd64 \
     -f docker/orbit-gateway/Dockerfile \
     --tag "$candidate_image" \
     --load \
     .

   gh auth status -h github.com
   ghcr_docker_config="$(mktemp -d)"
   trap 'rm -rf "$ghcr_docker_config"' EXIT
   gh auth token \
     | DOCKER_CONFIG="$ghcr_docker_config" docker login ghcr.io \
       -u "$(gh api user -q .login)" \
       --password-stdin

   DOCKER_CONFIG="$ghcr_docker_config" docker push "$candidate_image" \
     | tee "${candidate_dir}/gateway-image-push.log"
   rm -rf "$ghcr_docker_config"
   trap - EXIT

   gateway_digest="$(awk '/digest: sha256:/ { print $3 }' "${candidate_dir}/gateway-image-push.log" | tail -1)"
   if [ -z "$gateway_digest" ]; then
     echo "Failed to capture the pushed gateway image digest." >&2
     exit 1
   fi

   bin/orbit-release-manifest \
     --version="$version" \
     --source=topology-candidate \
     --build-id="$build_id" \
     --asset-base-url="$candidate_asset_base_url" \
     --gateway-image="$candidate_image" \
     --gateway-digest="$gateway_digest" \
     --cli-artifact="linux-amd64=orbit-linux-x64=${candidate_dir}/orbit-linux-x64" \
     --cli-artifact="darwin-arm64=orbit-macos-arm64=${candidate_dir}/orbit-macos-arm64" \
     --role-image="orbit-caddy=caddy:2-alpine" \
     --role-image="orbit-websocket=ghcr.io/hardimpactdev/orbit-websocket:${version}" \
     --output="${candidate_dir}/orbit-release-manifest.candidate.json"

   ORBIT_CANDIDATE_PREFIX="$candidate_prefix" \
   ORBIT_CANDIDATE_DIR="$candidate_dir" \
   ORBIT_CANDIDATE_CHANNEL="$candidate_channel" \
     bin/orbit-gateway-artisan tinker --execute='
       $disk = Illuminate\Support\Facades\Storage::disk(config("orbit.artifacts.disk"));
       $prefix = trim((string) getenv("ORBIT_CANDIDATE_PREFIX"), "/");
       $dir = rtrim((string) getenv("ORBIT_CANDIDATE_DIR"), "/");
       $channel = trim((string) getenv("ORBIT_CANDIDATE_CHANNEL"), "/");

       foreach (["orbit-linux-x64", "orbit-macos-arm64", "orbit-release-manifest.candidate.json"] as $asset) {
           $disk->put("{$prefix}/{$asset}", file_get_contents("{$dir}/{$asset}"), "public");
       }

       $disk->put(
           "channels/{$channel}/orbit-release-manifest.json",
           file_get_contents("{$dir}/orbit-release-manifest.candidate.json"),
           "public",
       );
     '

   candidate_channel_manifest_url="${artifact_base_url}/channels/${candidate_channel}/orbit-release-manifest.json"
   echo "Candidate channel manifest: ${candidate_channel_manifest_url}"
   ```

   Point the target gateway at the stable channel URL:

   ```bash
   orbit manifest:update "$candidate_channel_manifest_url"
   ```

   Future candidate rehearsals should upload immutable files under
   `candidates/<BUILD_ID>/` and replace the same channel manifest object;
   `orbit update:all` will resolve the current channel manifest during its
   `Checking for updates` step. Run `orbit manifest:update` again only when the
   channel URL changes. If the gateway should stop accepting candidate
   manifests, run `orbit manifest:remove` to restore the configured default
   release manifest source.

8. Capture live topology doctor status before publishing. Record the exact
   command, timestamp, target topology, and result summary in the release
   report. Existing drift does not necessarily block the release, but it must be
   known before `update:all` so new regressions are visible.
9. Run live topology acceptance against the activated candidate channel from the
   operator node. Prefer the source CLI in the release worktree so the update
   command definitely understands the candidate manifest contract. If you use
   an installed `orbit`, first prove it is current enough and actually reads
   the selected candidate manifest.

   ```bash
   version="$(bin/orbit-version)"
   ORBIT_RELEASE_MANIFEST_URL="$candidate_channel_manifest_url" ./apps/cli/orbit update:all --stream-json
   orbit activity:show <activity-id> --json
   orbit gateway:status --json
   orbit doctor --all --json
   orbit node:list --json
   ```

10. Confirm:
    - gateway service image is the tested digest-pinned
      `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>-candidate-<BUILD_ID>`
      image;
    - scheduler service image matches gateway;
    - every selected workload node reports `Orbit <VERSION>`;
    - post-update `orbit doctor` output has no new regressions compared with the
      pre-release baseline;
    - `orbit node:list` succeeds after the update.

11. If the user requested no GitHub release, stop here after recording the live
    acceptance evidence. Otherwise, stop and ask for explicit human approval to
    publish the accepted candidate to GitHub. Do not create a GitHub release,
    push a `v<VERSION>` tag, upload GitHub release assets, or move the final
    GHCR version tag until approval is given for the candidate identified by
    `build_id`, commit, CLI hashes, and gateway digest.

12. After approval, promote the accepted gateway image digest to the final GHCR
    version tag without rebuilding:

   ```bash
   version="$(bin/orbit-version)"
   candidate_image="ghcr.io/hardimpactdev/orbit-gateway:${version}-candidate-${build_id}"
   release_image="ghcr.io/hardimpactdev/orbit-gateway:${version}"

   docker buildx imagetools create \
     -t "$release_image" \
     "${candidate_image}@${gateway_digest}"

   docker buildx imagetools inspect "$release_image"
   ```

13. Generate the final GitHub manifest from the same tested local assets. It must
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
     --gateway-digest="$gateway_digest" \
     --repository="hardimpactdev/orbit" \
     --cli-artifact="linux-amd64=orbit-linux-x64=orbit-linux-x64" \
     --cli-artifact="darwin-arm64=orbit-macos-arm64=orbit-macos-arm64" \
     --role-image="orbit-caddy=caddy:2-alpine" \
     --role-image="orbit-websocket=ghcr.io/hardimpactdev/orbit-websocket:${version}" \
     --output="orbit-release-manifest.json"
   ```

14. Create a draft release, attach the tested files, and publish the draft. The
    release workflow runs on the `release.published` event, so a tag push alone
    is not enough:

   ```bash
   version="$(bin/orbit-version)"

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

15. Watch the `Orbit Release` workflow until it succeeds. It verifies the
    attached assets and digest-pinned gateway image, then publishes the split
    package repositories.

16. Verify public artifacts without authentication:

   ```bash
   version="$(bin/orbit-version)"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-release-manifest.json"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-linux-x64"
   curl -fsSI "https://github.com/hardimpactdev/orbit/releases/download/v${version}/orbit-macos-arm64"
   tmp="$(mktemp -d)"
   trap 'rm -rf "$tmp"' EXIT
   DOCKER_CONFIG="$tmp" docker pull "ghcr.io/hardimpactdev/orbit-gateway:${version}"
   ```

17. If doctor output changed after `update:all`, classify the delta before
    accepting the release. Fix release-caused regressions immediately when
    feasible. For intentional or pre-existing live-topology migration work,
    create scoped follow-up tasks with the before/after doctor evidence.

GitHub publication is not eligible until live candidate `update:all`
acceptance passes and the human approves the accepted build id, commit, CLI
hashes, and gateway digest. A no-GitHub live artifact release is complete when
the live acceptance evidence is recorded and the no-GitHub boundary is explicit.
A GitHub release is not complete until the GitHub workflow verifies the
promoted assets and publishes the split package repos.

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
- If Mini's Docker credential helper fails or asks for interaction, use a
  helper-free temporary `DOCKER_CONFIG` for `docker login` and `docker push`.
  Remove it after the push and verify no auth token strings were written into
  evidence directories.
- If workload node updates fail, inspect the durable operation error first.
  Workload fan-out failures should fail before final verification and include
  per-node results.
- If live `update:all` was started by an old installed operator CLI and the
  operation did not use the candidate manifest, stop that follower, inspect the
  durable operation/activity, and rerun acceptance from the release worktree
  source CLI with `ORBIT_RELEASE_MANIFEST_URL` set to the candidate channel.
- If `update:all` reports `update_lease_conflict`, inspect the existing
  operation run and wait for the lease to expire unless you can prove the lease
  belongs to a stale failed release attempt. Do not start overlapping fleet
  updates blindly.
