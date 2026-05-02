# Tool Catalog: `gh`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `gh` |
| Label | GitHub CLI |
| Backend | system binary |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`gh` supports `tool:update` and safe doctor adopt. It does not support
lifecycle commands, reload, logs, credentials, or removal.

## Credentials

`gh` does not support `tool:credentials`. GitHub authentication state belongs
to the GitHub CLI and provider-specific auth flows, not Orbit tool
credentials.

## Orbit Notes

Production app flows may depend on `gh` for repository access, but app and
deployment intent remain owned by their command families.

## Doctor Relationship

`doctor --family=tool` may adopt an existing `gh` binary and report missing
baseline drift.
