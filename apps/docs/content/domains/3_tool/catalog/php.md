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
| Supported operating systems | Linux |
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

Orbit resolves supported PHP versions to the approved FrankenPHP image family
that Orbit owns and builds from the upstream Debian/glibc image:
`ghcr.io/hardimpactdev/orbit-frankenphp:1-php<version>-bookworm`.
Alpine/musl FrankenPHP variants, PHP-FPM images, CLI-only PHP images, and host
PHP package names are invalid app/workspace runtime targets.

## Doctor Relationship

`doctor --family=tool` verifies PHP image availability where image capability
is modeled as a tool fact. App/workspace runtime container drift belongs to the
app or workspace family that owns the runtime artifact.
