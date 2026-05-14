# Tool Catalog: `php`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP-FPM tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php` |
| Label | PHP-FPM |
| Backend | system service |
| Support model | Installable and removable by Orbit |
| Category | `runtime` |

## Capabilities

`php` supports `tool:install`, `tool:remove`, lifecycle actions,
`tool:reload`, `tool:update`, `tool:logs`, safe doctor fix, and safe doctor
adopt.

## Credentials

`php` does not support `tool:credentials`.

## Orbit Notes

The `php` tool owns PHP-FPM runtime installation and service lifecycle. PHP
runtime selection is admitted as the separate `php:*` command family because it
mutates app configuration, workspace overrides, and node CLI defaults rather than only
tool lifecycle state.

## Doctor Relationship

`doctor --family=tool` verifies installed PHP-FPM versions and service drift.
App PHP-FPM pool drift belongs to the app or workspace family that owns the
runtime artifact.
