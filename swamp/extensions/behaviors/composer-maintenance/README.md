# Composer maintenance policy

Read the binding [Swamp shape guide](../../../docs/swamp-shape.md) before
authoring execution. This page describes policy and typed data, not the retired
worker driver or a many-action workflow inside a behavior.

## Project data

`@nckrtl/composer-maintenance` owns shared Composer policy. Project
configuration supplies a checkout, relative paths and the repository's existing
release-age assertion. Paths must stay inside the checkout and identify Composer
manifests. Do not invent an age rule for a project that has none.

Planning resolves eligible candidates together and returns exact targets and
typed blocked reasons. Each target identifies its path, manager, package, stable
version, purpose and advisories. Manifest range rules are in
[manifest range policy](../../../docs/website-packages.md). Preserve the source
checkout during planning.

Security policy verifies advisory identity and the final lockfile. Major targets
preserve the required range style. Policy never silences advisories, accepts
stale downgrades or adds a release-age exception to a batch. Per-package
security exceptions preserve existing settings, record their date and duration
as native exception data, and never reset an older record.

`exceptions` is schema 1 project data. Shared methods emit exception metadata as
a Swamp resource; they do not own a Gateway stage-completion handshake or a
repository maintenance ledger.
[Exception data](../../../docs/project-maintenance-exceptions.md) belongs to the
private project extension.

Summary data contains a title, body, complete text and structured changes. It
compares direct declarations and locked versions against the selected base. Only
cleared advisories count as fixes. Report package and path identity
consistently; an empty update does not produce a successful PR summary.

## Distribution and checks

Keep the shared helpers in `extensions/behaviors/_lib/maintenance/` with this
private extension. The manifest owns archive paths, README and license.
Publication requires its existing authority; this documentation does not publish
anything.

Run the focused specifications with the installed Deno runtime:

```sh
deno test --allow-all extensions/behaviors/composer-maintenance/mod_test.ts
```

The tests exercise parsing, Git comparisons, policy edits and native resource
data with external package binaries as fixtures. See
[fixture provenance](fixtures/README.md). Runtime authoring and the required
per-PR checks remain in the binding guide.

The shared
[scan and package isolation policy](../../../docs/service-dependency-detection.md)
defines the disposable tree, registry allowlist and credential-free package
environment.
