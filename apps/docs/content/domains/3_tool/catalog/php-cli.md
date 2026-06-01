# Tool Catalog: `php-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP CLI toolchain's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php-cli` |
| Label | PHP CLI |
| Backend | prebuilt static binaries (dl.static-php.dev bulk preset) |
| Support model | Installable and updatable by Orbit on app-dev/app-prod nodes |
| Category | `runtime` |

## Capabilities

`php-cli` supports `tool:install`, `tool:update`, and safe doctor adopt.

`tool:install php-cli` downloads prebuilt, statically linked PHP CLI binaries
from `https://dl.static-php.dev/static-php-cli/bulk` and installs them for
every supported minor version (8.3, 8.4, 8.5). The bulk preset includes the
full extension set required by Laravel, including `intl`. Binaries are
installed to `/opt/orbit/php/<minor>/bin/php` with per-version symlinks at
`/usr/local/bin/php<minor>`. PHP 8.5 is set as the default `php` at
`/usr/local/bin/php`.

The installer auto-detects the host OS (`linux` or `macos`) and architecture
(`x86_64` or `aarch64`) so the same toolchain installs correctly on both
Ubuntu production nodes and macOS development machines.

`tool:update php-cli` re-downloads and re-links all pinned binaries. It uses
the same logic as install and is safe to re-run.

## Credentials

`php-cli` does not support `tool:credentials`.

## Orbit Notes

`php-cli` is separate from `php`. `php-cli` describes the **host** PHP CLI
toolchain installed on app-dev and app-prod nodes; it is used by
`app:exec` and `app:deploy` (and related commands) to run PHP workloads
directly on the host. `php` owns PHP image capability evidence for
containerised app and workspace execution.

All three supported minor versions (8.3, 8.4, 8.5) are installed side-by-side
at pinned patch releases. The default `php` binary resolves to the 8.5 binary.
Per-version presence probing is a known doctor follow-up; the current probe
verifies the default `php` binary only.

## Doctor Relationship

`doctor --family=tool` probes the default `php` binary on the host. When the
binary is absent on an app-dev or app-prod node it emits
`tool.capability_missing` and the fixer runs `tool:install php-cli` to
restore the host toolchain.
