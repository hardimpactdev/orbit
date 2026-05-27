# Tool Catalog: `composer`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Composer tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `composer` |
| Label | Composer |
| Backend | runtime container capability |
| Support model | Provided inside `orbit-runtime` and app/workspace PHP images |
| Category | `runtime` |

## Capabilities

`composer` supports `tool:update` for container image capability metadata. It
does not support host lifecycle commands, reload, logs, credentials, or
removal.

## Credentials

`composer` does not support `tool:credentials`.

## Orbit Notes

Composer is a runtime-container dependency for PHP project setup, app
execution, workspace execution, and Orbit dependency installation inside
`orbit-runtime`. Project dependency commands remain app, workspace, or
deployment concerns, not tool-family state.

## Doctor Relationship

`doctor --family=tool` may report missing Composer capability in managed
runtime images. It must not adopt host Composer as an Orbit command fallback.
