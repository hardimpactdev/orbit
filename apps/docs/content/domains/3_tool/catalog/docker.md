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

`docker` is a required prerequisite for Docker-backed processes and any
remaining Docker-backed tool capabilities. Orbit probes and
adopts Docker as baseline node capability. Safe fix may repair supported
service drift once the node bootstrap contract provides Docker.

`tool:install docker` and `tool:remove docker` are not supported by the tool
family.

## Credentials

`docker` does not support `tool:credentials`.

## Orbit Notes

Docker is the infrastructure substrate for process-defined services such as MySQL
and Redis, app/workspace runtime containers, the `websocket` role's Laravel Reverb
runtime, the `s3` role's RustFS runtime, and `dns`. Compatibility tools such as
Mailpit and Reverb run as Docker services while they remain tool-backed. Docker is
not itself a database, cache, mail, realtime, object-storage, or DNS configuration
owner.

## Doctor Relationship

`doctor --family=tool` reports Docker baseline drift. Process drift for
Docker-backed processes is reported against the concrete process row. Drift in
the capabilities of tools that depend on Docker is reported against their
concrete tool row.
