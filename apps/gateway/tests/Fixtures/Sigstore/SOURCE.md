# Sigstore fixtures

`cli-cli-v2.103.2-linux-arm64.sigstore.json` is a real Sigstore public-good bundle. `actions/attest-build-provenance`
wrote it for `gh_2.103.2_linux_arm64.tar.gz` of the `cli/cli` release `v2.103.2`, and Rekor v1 logged it on
23 Oct 2026. It was downloaded on 26 Oct 2026 with:

```bash
gh release download v2.103.2 -R cli/cli -p gh_2.103.2_linux_arm64.tar.gz
gh attestation download gh_2.103.2_linux_arm64.tar.gz -R cli/cli --predicate-type https://slsa.dev/provenance/v1
```

The statement names the tarball with SHA-256 `4c3e99a50bd8b181a3fe9d69b25dd978bac768148d88f01507c6b4d1ef61a443`.
The signer is `https://github.com/cli/cli/.github/workflows/deployment.yml@refs/heads/trunk`, issued by
`https://token.actions.githubusercontent.com`. `gh attestation verify --bundle` accepts it against that identity.

The tests verify it against the committed trust root, `resources/sigstore/trusted_root.json`. Tests that need
Orbit's own identity sign with `Tests\Support\SigstoreTestSigner`, which has its own CA and Rekor key.
