# Tool Catalog: `laravel-installer`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Laravel Installer tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `laravel-installer` |
| Label | Laravel Installer |
| Backend | Composer global package (`laravel/installer`) |
| Support model | Installable, updatable, and removable by Orbit on app-dev nodes |
| Category | `runtime` |

## Capabilities

`laravel-installer` supports `tool:install`, `tool:update`, and `tool:remove`.

`tool:install laravel-installer` runs `composer global require laravel/installer`
as the `orbit` runtime user with `COMPOSER_HOME=/home/orbit/.config/composer`,
then symlinks the resulting `laravel` binary into `/usr/local/bin/laravel` so it
is available on the system PATH.

`tool:update laravel-installer` runs `composer global update laravel/installer`
as the `orbit` runtime user.

`tool:remove laravel-installer` runs `composer global remove laravel/installer`
as the `orbit` runtime user and removes the `/usr/local/bin/laravel` symlink.

## Credentials

`laravel-installer` does not support `tool:credentials`.

## Orbit Notes

`laravel-installer` depends on both `php-cli` and `composer` being installed on
the host. It is converged automatically on app-dev nodes as part of the app-dev setup
baseline. (`php-cli` and `composer` converge on both app-dev and app-prod; the
Laravel installer is app-dev only.)

The global Composer home for the `orbit` user is
`/home/orbit/.config/composer`. The `laravel` binary is symlinked into
`/usr/local/bin/laravel` so it is available to all system users without
modifying `$PATH`.

## Doctor Relationship

`doctor --family=tool` probes the `laravel` binary on the host. When the
binary is absent on an app-dev node it emits
`tool.capability_missing` and the fixer runs `tool:install laravel-installer`
to restore the installer.
