# Tool Catalog: `php-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP CLI tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php-cli` |
| Label | PHP CLI |
| Backend | system binary |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`php-cli` is probed and adopted as the PHP binary Orbit itself needs to run.
It does not support lifecycle commands, reload, logs, credentials, or removal.

## Credentials

`php-cli` does not support `tool:credentials`.

## Orbit Notes

`php-cli` is separate from `php`. `php-cli` is the baseline CLI binary; `php`
owns installable PHP-FPM runtime versions for app and workspace execution.

## Doctor Relationship

`doctor --family=tool` may adopt an existing PHP CLI binary and report missing
baseline drift.
