# Tool Catalog: `git`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Git tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `git` |
| Label | Git |
| Backend | system binary |
| Support model | Role baseline for the `app-dev`, `app-prod`, and `agent` roles, adopted and kept converged |
| Category | `runtime` |

## Capabilities

`git` supports `tool:install`, `tool:update`, and safe doctor adopt. It has no
remove, credentials, or reconfigure surface.

`tool:install git` installs Git through apt after enabling Git's official
upstream stable Ubuntu PPA (`ppa:git-core/ppa`). It does not use Homebrew or
Linuxbrew on Linux.

`tool:update git` ensures the same upstream stable PPA is available, refreshes
apt metadata, and upgrades the installed `git` package through apt.

## Credentials

`git` does not support `tool:credentials`. Repository authentication belongs to
Git credential helpers, deploy keys, or provider-specific auth flows, not Orbit
tool credentials.

## Orbit Notes

Git is required for repository clone, fetch, and checkout workflows on app and
agent nodes. Ubuntu distribution packages can lag upstream Git releases, so
Orbit-managed Ubuntu Git uses `ppa:git-core/ppa` as the apt source for upstream
stable Git. App nodes use Git alongside `gh` and Composer during development
and deployment. Agent nodes use Git for autonomous repository workflows.

## Doctor Relationship

`doctor --family=tool` probes the `git` binary on the host. When the binary is
absent on an `app-dev`, `app-prod`, or `agent` node it emits
`tool.capability_missing` and the fixer runs `tool:install git` to restore the
host Git installation.
