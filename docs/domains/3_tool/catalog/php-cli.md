# Tool Catalog: `php-cli`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the PHP CLI capability's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `php-cli` |
| Label | PHP CLI |
| Backend | runtime container capability |
| Support model | Provided by `orbit-runtime` and app/workspace PHP images |
| Category | `runtime` |

## Capabilities

`php-cli` is probed as the PHP binary available inside Orbit-managed runtime
containers. It does not support host lifecycle commands, reload, logs,
credentials, or removal.

## Credentials

`php-cli` does not support `tool:credentials`.

## Orbit Notes

`php-cli` is separate from `php`. `php-cli` describes the executable available
inside runtime containers; `php` owns PHP image capability evidence for app and
workspace execution.

## Doctor Relationship

`doctor --family=tool` may report PHP capability drift inside runtime
containers. It must not adopt host PHP as an Orbit command fallback.
