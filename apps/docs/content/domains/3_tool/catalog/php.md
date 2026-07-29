# Tool Catalog: `php`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP image tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php` |
| Label | PHP images |
| Backend | FrankenPHP Docker image capability |
| Support model | Selected by app/workspace runtime configuration |
| Category | `runtime` |
| Supported operating systems | Linux and macOS |
| Required container provider | Docker-compatible |
| Isolation | Docker container |

## Capabilities

`php` supports image inventory, `tool:update` where Orbit refreshes supported
image metadata, safe doctor fix, and safe doctor adopt. It does not install
host PHP packages or manage a host PHP-FPM service.

## Credentials

`php` does not support `tool:credentials`.

## Orbit Notes

The `php` tool owns PHP image capability evidence for app and workspace runtime
containers. PHP runtime selection is admitted as the separate `php:*` command
family because it mutates app configuration and workspace overrides rather than
only tool capability state.

On both Linux and macOS, this tool remains Docker-isolated and requires a
Docker-compatible container provider. macOS support does not introduce a
host-native PHP or FrankenPHP fallback.

Orbit resolves supported PHP versions to the approved FrankenPHP image family
that Orbit owns and builds from the upstream Debian/glibc image:
`ghcr.io/hardimpactdev/orbit-frankenphp:<image-major>-php<version>-bookworm`.
Alpine/musl FrankenPHP variants, PHP-FPM images, CLI-only PHP images, and host
PHP package names are invalid app/workspace runtime targets.

The approved PHP 8.5 image reports the same WAL-reset-safe SQLite release
through both `SQLite3::version()` and `select sqlite_version()` before
publication. Orbit's PHP 8.5 image recipe pins the official SQLite 3.44.6
safety backport.

## Doctor Relationship

`doctor --family=tool` verifies PHP image availability where image capability
is modeled as a tool fact. App/workspace runtime container drift belongs to the
app or workspace family that owns the runtime artifact.
