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
- Do not publish releases from separate source repositories for PHP packages.
  Those split repos are generated outputs of the monorepo release workflow:
  - `hardimpactdev/orbit-core` from `packages/core`
  - `hardimpactdev/orbit-sdk-laravel` from `packages/sdk`
  - `hardimpactdev/orbit-cli` from `apps/cli`
  - `hardimpactdev/orbit-gateway` from `apps/gateway`
- `@hardimpactdev/orbit-sdk-typescript` is **independently versioned** from root
  `VERSION` (package `package.json` is authoritative; initial public version
  `0.1.0`). Canonical source is `packages/sdk-typescript` in this monorepo
  (`private=true`). npm publish is **not** part of monorepo
  `orbit-release.yml`. Prepare with
  `bin/orbit-prepare-release-package --package=sdk-typescript` (version from
  package.json), push the prepared tree to
  `hardimpactdev/orbit-sdk-typescript`, and publish a GitHub Release there so
  the package repository’s OIDC workflow (`publish.yml`) runs
  `npm publish --provenance --access public`. Configure Trusted Publisher on
  that package repository, not on monorepo `orbit-release.yml`.
- Release artifacts are built once as release candidates and exposed through a
  topology-reachable `topology-candidate` manifest. Candidate CLI binaries,
  manifests, and private runtime image archives live in the central artifact
  store on the Laravel `orbit-artifacts` disk, under immutable
  `candidates/<BUILD_ID>/` paths.
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
- If the user requests a release-candidate refresh for the current live
  version, do not bump `VERSION` just to create candidate artifacts. Build from
  the pushed `origin/main` commit, give the candidate a unique `build_id`, and
  make the no-GitHub boundary explicit in the report.
- The GitHub Actions release workflow must verify the promoted
  `orbit-linux-x64`, `orbit-macos-arm64`, `orbit-release-manifest.json`, and
  digest-pinned `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>` image, then
  publish the split package repos and matching tags. It must not run CLI binary
  builds, gateway image builds, or `gh release upload --clobber`.
- `orbit update:all` is the acceptance path. It updates the operator CLI,
  gateway service, scheduler service, selected workload node CLIs, and Orbit
  Agent binaries from the candidate manifest before GitHub publication.
- Live topology doctor status is the release safety baseline. Capture it before
  publishing a new release so post-`update:all` doctor output can be compared
  against known pre-existing drift. Always use
  `orbit doctor --all --stream-json` for broad fleet doctor runs; the non-
  streaming `--json` form can be slow and look hung even while work is still
  progressing.
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
2. Confirm the release intent and choose whether this is a versioned GitHub
   release candidate or a no-GitHub live candidate refresh for the current
   version. If legacy split repos contain higher tags, choose a version higher
   than those tags or clean those generated repos intentionally.
3. For a versioned release, update only the root `VERSION` file for the version
   bump. For a current-version candidate refresh, leave `VERSION` unchanged and
   record the current version in the release evidence.
4. Run focused tests for release and update behavior:

   ```bash
   bin/orbit-gateway-pest --compact tests/Feature/Release tests/Feature/Services/Operations/WorkloadNodeUpdaterTest.php
   ```

5. Commit the version bump when there is one, then run the broad quality gate
   before publishing candidate artifacts:

   ```bash
   version="$(bin/orbit-version)"
   git add VERSION
   git commit -m "Bump version to ${version}"
   composer quality-check
   composer quality-gate:final-check
   ```

   For a no-bump candidate refresh, replace the `git add VERSION` and
   `git commit` lines with a source identity check that proves the current
   commit already contains the accepted changes.

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
   source commit with the release-candidate helper:

   ```bash
   bin/orbit-release-candidate build
   ```

   The helper sources the release env before Laravel reads `apps/gateway`
   config: `ORBIT_RELEASE_ENV_FILE` when set, else `./.env` in the repo root
   (prepared worktrees symlink the primary checkout's root `.env`), else
   `${ORBIT_PRIMARY_CHECKOUT:-$HOME/orbit}/.env`. That env must provide
   `ORBIT_ARTIFACTS_BASE_URL` and either the `ORBIT_ARTIFACTS_*` keys or the
   `S3_UPCLOUD_*` fallback keys; `build` fails fast when either is missing.
   The candidate channel defaults to `live-test` and can be overridden with
   `ORBIT_RELEASE_CANDIDATE_CHANNEL`.

   `build` re-runs the origin/main preflight (it exits 1 with "Candidate
   artifacts must be built from the pushed origin/main commit." when HEAD
   differs from `origin/main`), builds both CLI binaries, builds and pushes
   the candidate gateway image to GHCR, captures the pushed digest, generates
   the `topology-candidate` manifest, uploads the immutable candidate assets
   under `candidates/<build_id>/`, and publishes the stable channel manifest.
   It stops at candidate channel publication — it never creates GitHub
   releases, pushes tags, or promotes images — and finishes by printing
   `Candidate channel manifest: <url>` and `Release candidate state: <dir>`.
   Do not use S3 image tarballs as the normal gateway image path; Docker Swarm
   must consume the OCI registry reference recorded in the manifest.

   Durable candidate state lands in `.orbit/release-candidates/<build_id>/`:
  `candidate.env` (version, build id, commit, candidate image, gateway
  digest, channel manifest URL, and the sha256 of each CLI and Orbit Agent
  binary), the image push log, CLI binaries, Orbit Agent binaries, and the
  candidate manifest. No secrets are written to state or logs.
  `.orbit/release-candidates/latest` holds the newest build id; `env` and
  `verify` read it when `--build-id` is absent, and an explicit
  `--build-id=<id>` always wins over the pointer.

   Load the candidate state into the shell and point the target gateway at
   the stable channel URL:

   ```bash
   eval "$(bin/orbit-release-candidate env)"
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
   known before `update:all` so new regressions are visible. Also capture
   `orbit gateway:status --json` and `orbit node:list --json`; these are the
   fast identity checks for the gateway version and the actual fleet being
   updated, including macOS nodes such as Mini or the local disk machine.
9. Run live topology acceptance against the activated candidate channel from the
   operator node. Prefer the source CLI in the release worktree so the update
   command definitely understands the candidate manifest contract. If you use
   an installed `orbit`, first prove it is current enough and actually reads
   the selected candidate manifest.

   ```bash
   eval "$(bin/orbit-release-candidate env)"
   ORBIT_RELEASE_MANIFEST_URL="$candidate_channel_manifest_url" ./apps/cli/orbit update:all --stream-json
   orbit activity:show <activity-id> --json
   orbit gateway:status --json
   orbit doctor --all --stream-json
   orbit node:list --json
   ```

   Do not use `orbit doctor --all --json` for broad release checks. It may stay
   quiet long enough to be mistaken for a hang. Use the stream events to show
   progress and keep the final event as the doctor summary in the release
   evidence.

10. Confirm:
    - gateway service image is the tested digest-pinned
      `ghcr.io/hardimpactdev/orbit-gateway:<VERSION>-candidate-<BUILD_ID>`
      image;
    - scheduler service image matches gateway;
    - every selected workload node reports `Orbit <VERSION>`;
    - post-update `orbit doctor` output has no new regressions compared with the
      pre-release baseline;
    - `orbit node:list` succeeds after the update.

11. After live acceptance, promote the accepted FrankenPHP candidate digest to
    its stable runtime-family tag without rebuilding:

   ```bash
   eval "$(bin/orbit-release-candidate env)"
   bin/orbit-release-candidate promote-runtime --build-id="$build_id" --accepted
   ```

   This promotion does not create a GitHub release, move a gateway version tag,
   or publish CLI assets. It records the promoted source, target, and verified
   digest in the candidate state. Before acceptance, do not run it: leaving a
   candidate unpromoted keeps the stable runtime tag unchanged.

   If the user requested no GitHub release, stop here after recording the live
   acceptance and runtime-promotion evidence. Otherwise, stop and ask for
   explicit human approval to
    publish the accepted candidate to GitHub. Do not create a GitHub release,
    push a `v<VERSION>` tag, upload GitHub release assets, or move the final
    GHCR version tag until approval is given for the candidate identified by
    `build_id`, commit, CLI hashes, and gateway digest.

12. After approval, promote the accepted gateway image digest to the final GHCR
    version tag without rebuilding, then verify the promotion against the
    stored candidate state:

   ```bash
   eval "$(bin/orbit-release-candidate env)"
   release_image="ghcr.io/hardimpactdev/orbit-gateway:${version}"

   docker buildx imagetools create \
     -t "$release_image" \
     "${candidate_image}@${gateway_digest}"

   bin/orbit-release-candidate verify --release-image="$release_image"
   ```

   `verify` recomputes the sha256 of the stored CLI binaries against
   `candidate.env` and compares the promoted image digest (via
   `docker buildx imagetools inspect`) against the recorded gateway digest.
   It prints a `PASS`/`FAIL` line per key and exits 1 on any `FAIL`; do not
   continue past a failed verify.

13. Generate the final GitHub manifest from the stored candidate artifacts. It
    must have `source=github-release` and the same CLI hashes and gateway
    digest as the accepted candidate; copying the binaries from the durable
    candidate state dir (already hash-verified in step 12) guarantees
    byte-identical assets:

   ```bash
   eval "$(bin/orbit-release-candidate env)"

   cp "${candidate_dir}/orbit-linux-x64" orbit-linux-x64
   cp "${candidate_dir}/orbit-macos-arm64" orbit-macos-arm64

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
     --role-image="orbit-frankenphp=${stable_frankenphp_image}@${frankenphp_digest}" \
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
   bin/orbit-release-candidate verify --release-image="ghcr.io/hardimpactdev/orbit-gateway:${version}"
   ```

   The final `verify` proves the published version tag still resolves to the
   accepted candidate's gateway digest and that the stored CLI binaries still
   match their recorded hashes.

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
  evidence directories. `bin/orbit-release-candidate build` already uses this
  helper-free temporary `DOCKER_CONFIG` login internally for the candidate
  image push; the manual recipe below is for ad hoc pushes outside the helper.
- If `docker login` still invokes the macOS credential helper even with a
  temporary config, write a minimal helper-free config instead of retrying
  interactively:

  ```bash
  ghcr_docker_config="$(mktemp -d)"
  ghcr_user="$(gh api user -q .login)"
  ghcr_auth="$(printf '%s:%s' "$ghcr_user" "$(gh auth token)" | base64 | tr -d '\n')"
  umask 077
  printf '{"auths":{"ghcr.io":{"auth":"%s"}}}\n' "$ghcr_auth" > "${ghcr_docker_config}/config.json"
  DOCKER_CONFIG="$ghcr_docker_config" docker push "$candidate_image"
  rm -rf "$ghcr_docker_config"
  unset ghcr_auth
  ```

  Do not tee this command output in a way that could capture the auth value.
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
