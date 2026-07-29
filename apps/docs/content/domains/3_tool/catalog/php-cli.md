# Tool Catalog: `php-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP CLI toolchain's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php-cli` |
| Label | PHP CLI |
| Backend | Static PHP binaries; Orbit-owned PHP 8.5 build via static-php-cli |
| Support model | Installable and updatable by Orbit on app-dev/app-prod nodes |
| Category | `runtime` |

## Capabilities

`php-cli` supports `tool:install`, `tool:update`, and safe doctor adopt.

`tool:install php-cli` downloads statically linked PHP CLI binaries and
installs them for every supported minor version (8.3, 8.4, 8.5). PHP 8.5 uses
an Orbit-owned artifact built with the full extension set required by Laravel,
including `intl`, and pins the official SQLite 3.44.6 safety backport. Orbit rejects that artifact before
installation unless both `SQLite3::version()` and `select sqlite_version()`
report the same release and that release is SQLite 3.44.6, 3.50.7, or 3.51.3
and newer. Those releases contain the WAL-reset corruption fix. Binaries are installed to
`/opt/orbit/php/<minor>/bin/php` with per-version symlinks at
`/usr/local/bin/php<minor>`. PHP 8.5 is set as the default `php` at
`/usr/local/bin/php`.

The installer auto-detects the host OS and architecture. Orbit's PHP 8.5
artifacts cover the supported host pairs: Ubuntu `x86_64` production nodes and
macOS `aarch64` development machines.

`tool:update php-cli` re-downloads, verifies, and re-links all pinned binaries.
It uses the same logic as install and is safe to re-run. A failed PHP 8.5
checksum, PHP-version, extension, or SQLite-version check leaves that installed
runtime unchanged.

## Credentials

`php-cli` does not support `tool:credentials`.

## Orbit Notes

`php-cli` is separate from `php`. `php-cli` describes the **host** PHP CLI
toolchain installed on app-dev and app-prod nodes; it runs deploy steps and
running app PHP/Artisan workloads directly on the host. `php` owns PHP image
capability evidence for containerised app and workspace web serving.

All three supported minor versions (8.3, 8.4, 8.5) are installed side-by-side
at pinned patch releases. The default `php` binary resolves to the 8.5 binary.
Per-version presence probing is a known doctor follow-up; the current probe
verifies the default `php` binary only.

## Doctor Relationship

`doctor --family=tool` probes the default `php` binary on the host. When the
binary is absent on an app-dev or app-prod node it emits
`tool.capability_missing` and the fixer runs `tool:install php-cli` to
restore the host toolchain.
