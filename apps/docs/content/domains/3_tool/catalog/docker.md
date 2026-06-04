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

Docker is infrastructure for process-defined services such as MySQL and Redis,
for app/workspace runtime containers, for compatibility tools such as Mailpit
and Reverb while they remain tool-backed, for the `websocket` role's Laravel
Reverb runtime container, for the `s3` role's RustFS runtime container, and for
`dns`. It is not itself a database, cache, mail, realtime, object-storage, or
DNS configuration owner.

## Doctor Relationship

`doctor --family=tool` reports Docker baseline drift. Docker-backed process
drift is reported against the concrete process row; any remaining Docker-backed
tool capability drift is reported against the concrete tool row that depends on
Docker.
