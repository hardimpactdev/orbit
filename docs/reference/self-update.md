---
title: "Orbit self-update"
description: "The desired fleet state the Gateway serves for its commit, how orbit self-update replaces the CLI from it, and how the CLI learns that a newer release exists."
covers:
  - apps/gateway/app/{Data,Infrastructure}/Fleet/**
  - apps/gateway/app/Domain/Fleet/{Cli,Desired,FleetRelease,FleetRollout,FleetServing,NodeFootprint,RecipeMaintenance,Release}*.php
  - apps/gateway/app/Actions/Gateway/ShowDesiredFleetStateAction.php
  - apps/gateway/app/Http/Middleware/AnnounceDesiredCliVersion.php
  - apps/gateway/app/Console/Commands/ResolveDesiredFleetStateCommand.php
  - apps/cli/app/Services/SelfUpdate/**
  - packages/php-sdk/src/Requests/Gateway/ShowDesiredFleetStateRequest.php
  - packages/php-sdk/src/Responses/Gateway/{DesiredFleetStateResponse,DesiredCliReleaseResponse,DesiredAgentResponse,FleetReleaseAssetResponse}.php
---

# Orbit self-update

`orbit self-update` updates the local `orbit` binary to the CLI release matching the active Gateway's commit. It never touches `orbit-agent` (managed Nodes receive the Gateway's pinned agent via [install on mismatch](/reference/node-agent#install-on-mismatch) and [fleet rollout](/reference/gateway-recovery#fleet-rollout)).

## Desired fleet state

The Gateway exposes the target CLI and agent binaries for its deployed commit as **desired fleet state**:

| Field | Contents |
| --- | --- |
| `commit` | Full SHA of the Gateway's commit, or `null` for non-commit versions. |
| `cli` | [CLI release](/reference/cli-binaries#release-contract-for-clients) metadata: `status`, `reason`, `version`, `tag`, `commit`, `checksums_url`, and platform `assets` (`linux-x86_64`, `macos-arm64`). |
| `agent` | Pinned [`orbit-agent`](/reference/node-agent#install-and-upgrade) `version`, `commit`, and platform `assets` (`linux-x86_64`, `executor-linux-x86_64`, `macos-aarch64`, `macos-x86_64`). `self-update` ignores this field. |

Each asset carries `platform`, `name`, HTTPS `url`, and lowercase hex `sha256`.

### Where it is served

`GET /api/v1/gateway/desired-fleet-state` (MCP `gateway-desired-fleet-state`) is open to every active WireGuard peer without requiring a Node access grant (`peer.identity_unknown`, 403, for non-peers). `gateway:status` also returns the cached `desired_fleet_state` to active peers without waiting on Git or GitHub.

### How the Gateway resolves it

The Gateway resolves desired state from its deployed commit (`APP_VERSION`) and counts commits via `git rev-list --count` to derive `cli-v0.N.0`. It confirms the GitHub release and tag (using the [GitHub App](/reference/github-app) token when installed, or anonymously; `ORBIT_CLI_RELEASE_REPOSITORY` overrides the default repo). It then verifies `provenance.sigstore.json` offline ([Release provenance](/reference/gateway-recovery#release-provenance)), reads digests from `SHA256SUMS`, and attaches the pinned agent manifest.

| `cli.status` | `reason` | Meaning |
| --- | --- | --- |
| `available` | `null` | Release for the Gateway's exact commit is verified and ready. |
| `available` | `release_missing`, `release_mismatch`, `release_incomplete` | [Fallback ancestor release](#fallback-to-an-ancestor-release); `reason` explains why the commit's own release was not used. |
| `pending` | `release_missing` | Release is not published yet; retry in a few minutes. |
| `unavailable` | `gateway_commit_unknown`, `history_unavailable`, `release_mismatch`, `release_incomplete`, `release_unverified`, `provenance_unconfigured`, `github_unavailable` | Release cannot be resolved or verified (no fallback stands in for `release_unverified`). |

Available releases cache for 30 days, fallbacks recheck the commit's own release every 5 minutes, and `pending`/`unavailable` results cache for 60 seconds (5 minutes after an empty fallback search). Concurrent lookups share a 60-second cache lock (30-second wait). Run `php artisan orbit:desired-fleet-state` on the Gateway to inspect the resolved JSON directly.

### Fallback to an ancestor release

If a commit's CLI release remains `release_missing`, `release_mismatch`, or `release_incomplete` for 30 minutes, the Gateway searches up to 50 first-parent ancestors on `main` whose CLI build inputs (`apps/cli`, `packages/php-sdk`, `bin/orbit-build-cli-binary`, `bin/orbit-version`, `.github/workflows/orbit-cli-binary.yml`) match the Gateway's commit, and selects the newest verified published release. It continues checking every 5 minutes for the commit's own release and promotes it once published.

## What the command does

[`orbit self-update`](/cli/self-update) fetches desired fleet state from the active Gateway profile and replaces the CLI binary atomically.

### Where it downloads from

The Gateway names the target version and SHA-256, never the download host. The CLI constructs URLs from `https://github.com/hardimpactdev/orbit/releases/download` (overridable locally with `ORBIT_SELF_UPDATE_RELEASES`) and refuses mismatches (`self_update.release_mismatch`) before downloading.

Downloads run `curl --disable` over HTTPS only with `--connect-timeout 20`, `--max-time 120`, at most 5 redirects, and size caps of 256 MiB (binary) and 64 KiB (`SHA256SUMS`).

### One update at a time

Updates acquire `/run/lock/orbit-self-update.lock` (root on Linux), `/var/run/orbit-self-update.lock` (root on macOS), or `$ORBIT_HOME/self-update.lock` (non-root), waiting up to 120 seconds before failing with `self_update.busy` (or `self_update.lock_failed`). Stale `.<file>.orbit-candidate-<random>` files from interrupted runs are cleaned before download.

### Update the CLI

The CLI chooses its action from the running binary and desired state:

| Situation | Outcome |
| --- | --- |
| Source checkout | `skipped` (`source_checkout`); update with `git pull`. |
| PHAR run via `php` | `skipped` (`not_a_release_binary`). |
| CLI release `pending` or `unavailable` | `pending` or `skipped` with Gateway reason. |
| Unsupported platform | `skipped` (`platform_unsupported`). |
| Running binary SHA-256 already matches | `unchanged`. |
| Non-release build | Refused (`self_update.version_unknown`); reinstall a published release. |
| Older target release | Refused (`self_update.downgrade_refused`) unless `--allow-downgrade` or `--allow-downgrade-to=<version>` is passed. |
| Otherwise | Installs new release (`updated`). |

Releases live side by side behind a symlink (`/usr/local/bin/orbit -> orbit-0.4681.0`) so running commands keep reading their existing binary:

1. Download `SHA256SUMS` and verify its digest matches the Gateway's `sha256`.
2. Download the binary to a candidate file next to `orbit` (`self_update.download_failed` on failure) and verify its SHA-256 (`self_update.checksum_mismatch`).
3. Match mode and owner, flush to disk, and run `<candidate> --version` in an isolated temporary `ORBIT_HOME` (60-second timeout), requiring `Orbit <version>` (`self_update.candidate_invalid`).
4. Rename the candidate to `orbit-<version>` (`self_update.install_failed` or `self_update.not_writable`), atomically swap the `orbit` symlink, keep the previous release, and prune older `orbit-0.N.0` files.

### Result

The command exits `1` on `failed` and `0` on `incomplete`, `pending`, `updated`, or `unchanged`. JSON output returns `{ gateway, commit, outcome, steps, request_id }` with `before`, `after`, `path`, and `error` on the `cli` step.

## Running it on a Node

On a managed Node that [carries the CLI](/reference/node-provisioning#orbit-cli), run `sudo orbit self-update --json`. Root reads `/root/.orbit/config.json` (root-owned, mode `0600`) configured with the active Gateway WireGuard URL (for example `https://<gateway-wg-ip>`) and `ca_path` (such as `/etc/orbit/agent/ca.pem`).

## The newer-release notice

Every CLI request sends `X-Orbit-Client-Version`. When the Gateway has a cached available release, responses return `X-Orbit-Cli-Version`. If newer than the running release binary, the CLI prints a one-line notice to stderr at most once every 24 hours (tracked in `$ORBIT_HOME/self-update-notice.json`, mode `0600`), skipping JSON mode, non-TTY stderr, `CI`, non-release builds, and `self-update` itself.

## Why it works this way

### The CLI trusts the Gateway's provenance check

Verifying Sigstore provenance once on the Gateway keeps a single trust root that Gateway releases update cleanly, while requiring `orbit self-update` to construct GitHub URLs locally and verify `SHA256SUMS` against the Gateway's digest prevents a compromised Gateway from pointing clients at arbitrary downloads.

### Side-by-side binaries and atomic link swaps

Because a PHPacker binary reads its appended PHAR from disk while running, overwriting a file in place breaks concurrent commands. Installing `orbit-<version>` beside the running binary, verifying `--version` first, and switching the `orbit` symlink atomically keeps running commands intact. Using system `curl` ensures the system CA trust store works reliably across hosts.
