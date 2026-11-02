# JavaScript maintenance policy

Read the binding [Swamp shape guide](../../../docs/swamp-shape.md) before
authoring execution. This page describes policy and typed data, not the retired
worker driver or a many-action workflow inside a behavior.

## Project data

`@nckrtl/js-maintenance` owns shared npm, Bun, Vite+ and Expo policy. Project
configuration supplies relative application paths, package-manager identity and
existing release-age assertions. Do not change the manager declaration or invent
a release-age rule.

Manager selection uses the explicit package-manager declaration or the single
lockfile. Conflicts, missing locks, foreign paths and symlinks outside the
checkout refuse the input. Vite+ uses the project's own binary, not a global
installation. Package operations must preserve the clean environment boundary
and use only explicitly approved vault credentials.

Planning resolves exact stable targets and typed blocked reasons. Each target
identifies its path, manager, package, purpose and advisories. The shared
[manifest range policy](../../../docs/website-packages.md) preserves declared
operators and precision. Security updates verify advisory identity and final
lock state; major updates retain their required range style. Do not silence
advisories, combine unrelated targets or use an audit-fix shortcut.

Expo SDK targets align the coupled React and Expo packages. An unrelated coupled
major is not a separate update target. Preserve the project's existing security
and release-age policy; any supported exception records its original date and
duration as native project data.

`exceptions` is schema 1 project data. Shared methods emit exception metadata as
a Swamp resource; they do not own a Gateway stage-completion handshake or a
repository maintenance ledger.
[Exception data](../../../docs/project-maintenance-exceptions.md) belongs to the
private project extension.

Summary data contains a title, body, complete text and structured changes. It
reports changed direct dependencies, additions, removals and cleared advisories.
Preserve path identity for multi-application repositories. Only HTTPS advisory
URLs become links; a remaining advisory is not fixed.

## Checks

Run the focused specifications with the installed Deno runtime:

```sh
deno test --allow-all extensions/behaviors/js-maintenance/mod_test.ts \
  extensions/behaviors/composer-maintenance/mod_test.ts tests/lib_test.ts
```

The tests exercise parsers, graph walks, policy edits and native resource data
with external package binaries as fixtures. See
[fixture provenance](fixtures/README.md). The binding guide owns execution,
native control flow and the required per-PR checks.

The shared
[scan and package isolation policy](../../../docs/service-dependency-detection.md)
defines the disposable tree, registry allowlist and credential-free package
environment.
