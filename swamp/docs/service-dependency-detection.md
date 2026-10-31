# Dependency scan and package isolation policy

Execution follows the binding [Swamp shape guide](swamp-shape.md). This page
preserves package isolation and scan data rules, not the retired
Services/Gateway driver or task-creation protocol.

Detection reads configured manifests and lockfiles at one exact repository
commit. It does not install dependencies or run repository scripts, and it does
not allocate an update VM merely to scan. Preserve project paths, workspace
relationships, package-manager identity and existing release-age policy. An
unsupported or unsolved target remains a typed blocked finding.

Package tools run only in a disposable planning tree containing tracked
manifests, lockfiles and required workspace inputs. Repository `.npmrc`, Bun
configuration, scripts, executables, Git metadata and credentials never enter
that tree. Use a fresh home, explicit approved public-registry configuration,
scripts and Composer plugins disabled, and an allowlisted environment. Preserve
safe internal workspace links; refuse external links and paths outside the
planning tree.

Registry and dependency download endpoints must use the approved public endpoint
allowlist. Refuse unsupported Composer repository types and custom or Git
sources before invoking package tools. Local workspace references must resolve
inside the planning tree. Do not give package tools the acquisition token or an
agent's inherited environment.

Repository acquisition uses a scoped short-lived credential through the guide's
vault/minting boundary. That credential is not project configuration, a
package-tool environment variable or a scan result. Treat descriptive funding
metadata separately from executable or download endpoints.

Publish inventory, source identity, freshness, exact targets and blocked reasons
coherently as native data. A failed refresh records its attempt and retains
explicitly marked last-good data; it cannot become an empty successful scan or
authorize updates. Revalidate the source head and targets before applying queued
work.

The [producer fixtures](../tests/fixtures/dependency-results-v1/README.md)
describe the synthetic data contract.
[Project configuration](project-extensions.md) owns paths and policy. Neither
grants live admission or production authority.
