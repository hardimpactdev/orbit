# Tool Catalog: `php`

[Back to tool catalog.](README.md)

## Catalog

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

The `php` tool owns PHP-FPM runtime installation and service lifecycle. CLI
default selection and per-app PHP runtime intent may be owned by PHP runtime
commands when that family is ported.

## Doctor Relationship

`doctor --family=tool` verifies installed PHP-FPM versions and service drift.
App PHP-FPM pool drift belongs to the app or workspace family that owns the
runtime artifact.
