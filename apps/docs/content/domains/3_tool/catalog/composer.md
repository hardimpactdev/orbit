# Tool Catalog: `composer`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Composer tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `composer` |
| Label | Composer |
| Backend | host binary (`/usr/local/bin/composer`) |
| Support model | Installable and updatable by Orbit on app-dev/app-prod nodes |
| Category | `runtime` |

## Capabilities

`composer` supports `tool:install`, `tool:update`, and safe doctor adopt.

`tool:install composer` installs the Composer phar to `/usr/local/bin/composer`
using the default `php` binary (provided by `php-cli`). The official integrity
check from `https://composer.github.io/installer.sig` is performed before
running the installer. The script fails loudly if the SHA-384 hash of the
downloaded installer does not match the published signature.

`tool:update composer` runs `composer self-update` to upgrade the installed
phar to the latest stable release.

## Credentials

`composer` does not support `tool:credentials`.

## Orbit Notes

Composer is installed as a host binary on app-dev and app-prod nodes and is
used by `app:exec`, `app:deploy`, and related commands to manage PHP project
dependencies directly on the host. It is also a prerequisite for the
`laravel-installer` tool.

## Doctor Relationship

`doctor --family=tool` probes the `composer` binary on the host. When the
binary is absent on an app-dev or app-prod node it emits
`tool.capability_missing` and the fixer runs `tool:install composer` to
restore the host Composer installation.
