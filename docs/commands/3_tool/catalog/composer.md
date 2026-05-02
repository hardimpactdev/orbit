# Tool Catalog: `composer`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `composer` |
| Label | Composer |
| Backend | system binary |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`composer` supports `tool:update` and safe doctor adopt. It does not support
lifecycle commands, reload, logs, credentials, or removal.

## Credentials

`composer` does not support `tool:credentials`.

## Orbit Notes

Composer is a baseline dependency for PHP project setup and Orbit installation.
Project dependency commands remain app or deployment concerns, not tool-family
state.

## Doctor Relationship

`doctor --family=tool` may adopt an existing Composer binary and report missing
baseline drift.
