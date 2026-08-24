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
catalog for host package management. Orbit probes and adopts Docker as baseline
node capability instead of treating it as an operator-installed package tool.

## macOS Provider Support

Orbit Desktop-supervised macOS nodes use only the Desktop-owned Colima profile
`orbit`. Colima runs Docker, disables Kubernetes and activation, and exposes
`unix://$HOME/.colima/orbit/docker.sock` to the Agent. Ordinary startup never
installs prerequisites. The explicit `Install Local Runtime` action uses
existing Homebrew to install `colima` and `docker`.

```bash
brew install colima docker
```

The first profile creation uses 50 percent of logical CPU and memory and
Colima's upstream disk default. Later starts omit resource flags, so manual
Colima CLI overrides persist. OrbStack and Docker Desktop can run beside this
profile, but they are not Desktop Agent providers and cannot see or manage its
containers, images, or volumes. Orbit does not change the global Docker
context. Linux uses direct Docker Engine; there is no Linux Colima lane.

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

On macOS, the Agent uses the owned Colima socket. If it is unavailable, Doctor
reports `orbit_agent_unavailable` and directs the operator to open Orbit
Desktop.
