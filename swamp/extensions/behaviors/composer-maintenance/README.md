# Composer maintenance policy

Read the binding [Swamp shape guide](../../../docs/swamp-shape.md) before
authoring execution. This page describes policy and typed data, not the retired
worker driver or a many-action workflow inside a behavior.

## Project data

`@nckrtl/composer-maintenance` owns shared Composer policy. It has two methods:
`detect` finds locked majors, advisories and due exceptions, and `plan` resolves
eligible targets in a disposable checkout. Project
configuration supplies a checkout, relative paths and the repository's existing
release-age assertion. Paths must stay inside the checkout and identify Composer
manifests. Do not invent an age rule for a project that has none.

Planning resolves eligible candidates together and returns exact targets and
typed blocked reasons. Each target identifies its path, manager, package, stable
version, purpose and advisories. Manifest range rules are in
[manifest range policy](../../../docs/website-packages.md). Preserve the source
checkout during planning.

Security policy verifies advisory identity and the final lockfile. Major targets
preserve the required range style. Policy never silences advisories or accepts
stale downgrades.

`exceptions` is schema 1 project input. `detect` reports elapsed exceptions and
never changes `vet.json` or the exception records.
[Exception data](../../../docs/project-maintenance-exceptions.md) belongs to the
Project entry in `projects.json`.

## Distribution and checks

Keep the shared helpers in `extensions/behaviors/_lib/maintenance/` with this
private extension. The manifest owns archive paths, README and license.
Publication requires its existing authority; this documentation does not publish
anything.

Run the focused specifications with the installed Deno runtime:

```sh
deno test --allow-all extensions/behaviors/composer-maintenance/mod_test.ts
```

The tests exercise parsing and native resource data with external package
binaries as fixtures. See
[fixture provenance](fixtures/README.md). Runtime authoring and the required
per-PR checks remain in the binding guide.

The shared
[scan and package isolation policy](../../../docs/service-dependency-detection.md)
defines the disposable tree, registry allowlist and credential-free package
environment.
