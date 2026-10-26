---
title: "ADR 0235: Verify release provenance on the Gateway"
sidebarTitle: "0235 Release provenance"
description: "In progress. CI signs every release file with its own workflow identity through Sigstore and publishes the bundle in the release. The Gateway verifies it offline before it installs an agent or names a CLI digest."
---

# ADR 0235: Verify release provenance on the Gateway

The release workflow signs every file of a commit's release with a Sigstore attestation, made with the workflow's own OIDC identity. It publishes the bundle in the same release as `provenance.sigstore.json`. The Gateway verifies that bundle offline, against a trust root committed in the repository, before it installs a release's agent and before it hands out a CLI digest. `orbit self-update` and `cli.install` keep taking their digest from the Gateway.

## Status

In progress.

Principle: [Security fits the real threat model](/mission#principles). The release bytes come from public GitHub downloads that nothing proves CI built. The Gateway is the trust anchor of the fleet, so it alone checks the proof. No CLI repeats it, because a CLI already trusts the Gateway for its digest.

## Context

Each green `main` commit gets a public release ([CLI binaries](/reference/cli-binaries#published-releases)). The Gateway downloads `manifest.json` and `SHA256SUMS` without authentication and checks each binary against them ([Prepare a release](/reference/gateway-recovery#prepare-a-release)). Those files come from the same release, so they detect a damaged download, not a forged release.

Nick set these limits: no new token, GitHub App, secret, or environment; downloads stay public and unauthenticated; the Gateway makes no runtime call to an authenticated API.

## Decision

- The `publish` job of `orbit-cli-release.yml` runs `actions/attest-build-provenance` with `id-token: write` and `attestations: write`. Its subjects are the nine release files. The job adds the bundle to the release before `gh release create`, so the release appears with it.
- The signing identity is the release workflow on `main`: certificate SAN `https://github.com/<owner>/<repo>/.github/workflows/orbit-cli-release.yml@refs/heads/main` and OIDC issuer `https://token.actions.githubusercontent.com`. A `workflow_run` and a `workflow_dispatch` run both run on `refs/heads/main`, so both have this identity.
- One Gateway verifier checks a Sigstore bundle v0.3 with PHP's OpenSSL extension against the pinned Sigstore public-good trust root. It accepts only a Rekor v1 `dsse` 0.0.1 entry with a signed entry timestamp and an inclusion proof.
- Prepare and the CLI release catalog share one step that downloads and verifies the bundle. A release without a valid bundle is never installed and never named.

## Rejected alternatives

- Ask GitHub's attestation API at runtime: it needs an authenticated call.
- Sign with a key that the workflow holds: it needs a secret, and the key would need rotation.
- Verify in every CLI too: the CLI takes its digest from the Gateway, so the check adds nothing against a compromised Gateway. A stale trust root in an installed CLI would stop its updates.
- A PHP Sigstore library: none exists in the locks, and a large dependency would carry more risk than a small verifier.

## Consequences

- A forged or unsigned release cannot reach a Node through the Gateway.
- Releases published before this change have no bundle. The Gateway never installs them again by prepare, and the catalog does not name them.
- A Sigstore key rotation that the pinned trust root does not hold yet, or a move to Rekor v2, stops every new release until the trust root or the verifier changes.

## Affects

- Components: apps/gateway, apps/cli, apps/e2e
- ADRs: none
- Detail: [CLI binaries: Why it works this way](/reference/cli-binaries#why-it-works-this-way); [Update and recover a Gateway: Release provenance](/reference/gateway-recovery#release-provenance)
- Verify: `apps/gateway/tests/Unit/Releases/SigstoreBundleVerifierTest.php`, `GatewayReleaseAgentDeliveryTest`, `GitHubCliReleaseCatalogTest`, `CliReleaseContractTest`, `WorkflowSecurityTest`
