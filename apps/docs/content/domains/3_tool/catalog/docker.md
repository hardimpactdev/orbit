# Tool Catalog: `docker`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Docker tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `docker` |
| Label | Docker |
| Backend | system service on Linux; Docker-compatible container provider on macOS |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`docker` is a required prerequisite for Docker-backed processes and any
remaining Docker-backed tool capabilities. Orbit probes and adopts Docker as
baseline node capability. During Ubuntu managed-node setup, safe fix can
install the `docker.io` package and enable its system service through Agent
push. Later safe fix repairs supported service drift.

`tool:install docker` and `tool:remove docker` are not supported by the tool

## macOS Provider Support

On macOS workload nodes, a container provider that is Docker-compatible and
reachable supplies the Docker capability; there is no host system service.
Orbit first uses a Docker provider that is already working: it probes the
current `docker` command and context, does not mutate the global Docker
context, and does not install or start providers during role convergence. When no Docker provider is
reachable on a macOS node, Orbit recommends Colima:

```bash
brew install docker colima
colima start --runtime docker
```

OrbStack and Docker Desktop are compatible providers when they are already
installed and licensed or allowed for the user's context. OrbStack is not the
default recommendation because it requires a license for commercial, business,
nonprofit, government, and freelance use after evaluation. Linux repair
behavior is unchanged; Orbit does not emit systemd repair commands for Docker
on macOS.

## Credentials

`docker` does not support `tool:credentials`.

## Orbit Notes

Docker is the infrastructure substrate for process-defined services such as MySQL and
Valkey, app/workspace runtime containers, the `websocket` role's Laravel Reverb
runtime, the `s3` role's SeaweedFS runtime, and `dns`. Mailpit remains a tool-backed
Docker service; Reverb is owned by the websocket role rather than a separate Tool
row. Docker is not itself a database, cache, mail, realtime, object-storage, or DNS
configuration owner.

## Doctor Relationship

`doctor --family=tool` reports Docker baseline drift. Process drift for
Docker-backed processes is reported against the concrete process row. Drift in
the capabilities of tools that depend on Docker is reported against their
concrete tool row.
