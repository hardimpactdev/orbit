# Tool Catalog: `docker`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `docker` |
| Label | Docker |
| Backend | system service |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`docker` is a required prerequisite for Docker-backed tools. Orbit probes and
adopts Docker as baseline node capability. Safe fix may repair supported
service drift once the node bootstrap contract provides Docker.

`tool:install docker` and `tool:remove docker` are not supported by the tool
family.

## Credentials

`docker` does not support `tool:credentials`.

## Orbit Notes

Docker is infrastructure for `postgres`, `mysql`, `redis`, `mailpit`, `reverb`,
and `dns`. It is not itself a database, cache, mail, or DNS intent owner.

## Doctor Relationship

`doctor --family=tool` reports Docker baseline drift. Docker-backed tool drift
is still reported against the concrete tool row that depends on Docker.
