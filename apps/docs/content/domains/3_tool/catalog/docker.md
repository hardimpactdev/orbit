# Tool Catalog: `docker`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Docker tool's identity, backend, and support model in Orbit.

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

Docker is infrastructure for `postgres`, `mysql`, `redis`, `mailpit`, the
compatibility `reverb` tool, the `websocket` role's Laravel Reverb runtime
container, the `s3` role's RustFS runtime container, and `dns`. It is not
itself a database, cache, mail, realtime, object-storage, or DNS configuration
owner.

## Doctor Relationship

`doctor --family=tool` reports Docker baseline drift. Docker-backed tool drift
is still reported against the concrete tool row that depends on Docker.
