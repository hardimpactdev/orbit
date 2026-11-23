---
title: "CLI binaries"
description: "Published Orbit CLI releases for every green main commit: versions, tags, assets, checksums, provenance, how to find and install one, the pull-request builds, and how to build locally."
covers:
  - .github/workflows/orbit-cli-binary.yml
  - .github/workflows/orbit-cli-release.yml
  - bin/orbit-build-cli-binary
  - bin/orbit-cli-release-assets
  - bin/orbit-cli-release-version
  - bin/orbit-version
  - apps/cli/box.json
  - apps/cli/phpacker/**
---

# CLI binaries

The standalone `orbit` binary runs the CLI without PHP or Composer installed. It talks to the Gateway over HTTPS and never opens a local SQLite database. The Gateway itself deploys from a monorepo checkout ([Quickstart](/quickstart)).

## Published releases

Every first-parent `main` commit whose `Main delivery checks`, `Required checks`, and `Gateway release inputs` (`gateway_release == 'true'`) pass gets a public GitHub release on [hardimpactdev/orbit](https://github.com/hardimpactdev/orbit/releases). Docs-only commits with `gateway_release=false` do not trigger a release. When agent source changed since the previous release, the release also includes [`orbit-agent`](/reference/node-agent#releases) and `orbit-executor`.

CI's `Start the CLI release` job dispatches `.github/workflows/orbit-cli-release.yml`, which verifies first-parent `main` history and the trusted `ci.yml` run before building and publishing. Releases never expire and are never overwritten.

### Version and tag

Each release derives its version from its commit count on `main`:

| Item | Format | Example |
| --- | --- | --- |
| Release number `N` | `git rev-list --count <commit>` | `4681` |
| Version | `0.N.0` | `0.4681.0` |
| Tag | `cli-v0.N.0` (lightweight tag) | `cli-v0.4681.0` |
| Title | `orbit 0.N.0` | `orbit 0.4681.0` |

Compare releases by `N`, not by date or tag string. Release binaries print `Orbit 0.N.0` from `orbit --version` (matching `^0\.[1-9][0-9]*\.0$`); PR builds print a short commit hash, and source checkouts print the nearest tag.

### Assets

Each GitHub release publishes these platform binaries, checksums, and provenance files:

| Asset | Platform | Built on |
| --- | --- | --- |
| `orbit-0.N.0-linux-x86_64` | Linux x86_64 | Hosted `ubuntu-26.04` |
| `orbit-0.N.0-macos-arm64` | macOS Apple silicon | Hosted `ubuntu-26.04` |
| `orbit-agent-linux-x86_64` | Agent for Linux x86_64 (agent releases only) | Hosted `ubuntu-26.04` |
| `orbit-executor-linux-x86_64` | [Executor](/reference/executor) for Linux x86_64 (agent releases only) | Hosted `ubuntu-26.04` |
| `orbit-agent-macos-aarch64`, `orbit-agent-macos-x86_64` | Agents for macOS (agent releases only) | Hosted `macos-15` |
| `manifest.json` | Agent delivery manifest (agent releases only) | Publish job |
| `SHA256SUMS` | Sorted `sha256sum` digests for all binaries | Publish job |
| `provenance.sigstore.json` | [Sigstore SLSA v1 provenance](#provenance) | Publish job |

Each CLI binary bundles a static PHP 8.5 runtime with `orbit.phar` using PHPacker.

### Agent build cache

Agent jobs cache Cargo downloads and `apps/agent/target` keyed by runner OS, architecture, Rust version, target triples, Cargo manifests/lockfile, and agent source commit, always building with `cargo build --locked --release`. `build-delivery --commit` records the agent commit in `manifest.json` without changing the pin or provenance.

### Provenance

The publish job signs all release files with `actions/attest-build-provenance` using its OIDC identity (`orbit-cli-release.yml@refs/heads/main`, issuer `https://token.actions.githubusercontent.com`) and attaches `provenance.sigstore.json` in the same `gh release create` call. The Gateway verifies this bundle offline ([Release provenance](/reference/gateway-recovery#release-provenance)). To verify manually:

```bash
gh attestation verify SHA256SUMS --bundle provenance.sigstore.json --repo hardimpactdev/orbit \
  --cert-identity https://github.com/hardimpactdev/orbit/.github/workflows/orbit-cli-release.yml@refs/heads/main \
  --cert-oidc-issuer https://token.actions.githubusercontent.com
```

### Release contract for clients

The Gateway, Node installers, and `orbit self-update` rely on this contract:

| Item | Rule |
| --- | --- |
| Tag for commit `C` | `cli-v0.N.0`, where `N` is `git rev-list --count C` (requires a full clone) |
| Asset URL | `https://github.com/hardimpactdev/orbit/releases/download/cli-v<version>/orbit-<version>-<platform>` (`linux-x86_64`, `macos-arm64`) |
| Checksums & provenance URLs | `.../cli-v<version>/SHA256SUMS` and `.../cli-v<version>/provenance.sigstore.json` |
| Signing identity | `https://github.com/hardimpactdev/orbit/.github/workflows/orbit-cli-release.yml@refs/heads/main` |
| Immutability | Published assets never change; releases are created with `--latest=false` (never use `releases/latest`) |

From a full checkout, run `bin/orbit-cli-release-version [--tag] <commit>` to compute the version or tag. Without a checkout:

```bash
git ls-remote --tags --refs https://github.com/hardimpactdev/orbit 'cli-v*' \
  | sed 's#.*/cli-v##' | sort -t. -k2,2n | tail -n 1
```

### Install a release

Download the binary and `SHA256SUMS`, verify the checksum, and install:

```bash
version=0.4681.0
asset="orbit-${version}-linux-x86_64"
base="https://github.com/hardimpactdev/orbit/releases/download/cli-v${version}"
curl -fsSL -O "${base}/${asset}" -O "${base}/SHA256SUMS"
grep "  ${asset}\$" SHA256SUMS | sha256sum --check
sudo install -m 0755 "$asset" /usr/local/bin/orbit
orbit --version
```

On macOS Apple silicon, use `orbit-${version}-macos-arm64` and `shasum -a 256 --check`. Existing installs update via `sudo orbit self-update` ([Orbit self-update](/reference/self-update)).

### Publish a missed commit

To backfill a green `main` commit that missed publication, run `Orbit CLI Release` via `workflow_dispatch` on `main` with the full commit SHA. The workflow re-verifies first-parent history and CI status, deletes any unfinished draft, and skips publishing if an identical complete release already exists.

### Commits without a release

GitHub refuses to create a tag with `GITHUB_TOKEN` when the target commit's `.github/workflows` files differ from the tip of `main` (`HTTP 403`, failing with `Release refused for a non-tip commit`). Gateway deployments proceed independently using the frozen agent pin, and after 30 minutes without a CLI release the Gateway's desired fleet state [falls back to the newest matching ancestor release](/reference/self-update#fallback-to-an-ancestor-release).

## Pull-request builds

`.github/workflows/orbit-cli-binary.yml` runs via `workflow_call` (from CI when `cli` is selected, and from `orbit-cli-release.yml`) or `workflow_dispatch`. A single `build` job on `ubuntu-26.04` (2-minute timeout) builds both `orbit-linux-x64` (`bin/orbit-build-cli-binary linux x64`) and `orbit-macos-arm64` (`bin/orbit-build-cli-binary mac arm`) using the recipe PHP build and uploads artifacts (7-day retention on PRs, 30 days on release/manual runs).

## Build locally

Install the CLI and PHPacker dependencies, then build a target (`GITHUB_TOKEN` or `GH_TOKEN` required for PHPacker's `php-bin` downloads, retried up to 4 times):

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary linux x64
```

The builder packages `apps/cli` and `packages/php-sdk` with `--no-dev` into `apps/cli/builds/orbit.phar` (GZ compression via `apps/cli/box.json`) and appends it to PHPacker's static PHP 8.5 binary. `bin/orbit-cli-release-assets <version> <artifact-dir> <out-dir>` validates and packages downloaded artifacts alongside `SHA256SUMS`.

## Limits

The binary contract has these limits:

- Only Linux `x86_64` and macOS Apple silicon (`arm64`) are built; Linux `arm64`, Windows, and Intel Macs are unsupported.
- Binaries are not notarized; browser downloads on macOS need `xattr -d com.apple.quarantine` (`curl` downloads do not).
- `SHA256SUMS` checks integrity only; verify `provenance.sigstore.json` to prove release origin.
- Operator machines update via `orbit self-update`; eligible fleet Nodes receive `/usr/local/bin/orbit-<version>` via [fleet rollout](/reference/gateway-recovery#rollout-set-and-order).

## Why it works this way

### Provenance from the workflow identity

Signing with Sigstore OIDC binds release digests directly to `orbit-cli-release.yml@refs/heads/main` without long-lived secrets or GitHub App credentials, and lets the Gateway verify bundles offline from public downloads.

### Version from commit count and immutable releases

`git rev-list --count` on first-parent `main` gives every release a strictly increasing `N` for downgrade protection without an external counter. Publishing releases immutably with `--latest=false` keeps checksum verification deterministic even when older commits finish CI later.

### Build both targets on Linux amd64

PHPacker appends a PHAR to a prebuilt static PHP binary rather than compiling natively, so one `ubuntu-26.04` runner builds both Linux `x86_64` and macOS `arm64` binaries cleanly. Keeping PHPacker in `apps/cli/phpacker` avoids Symfony 7 vs Symfony 8 dependency conflicts with the CLI.
