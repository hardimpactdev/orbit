# Tool Catalog: `supervisor`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Supervisor tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `supervisor` |
| Label | Supervisor |
| Backend | system service |
| Support model | Host process manager for configured app/workspace processes |
| Category | `runtime` |

## Capabilities

`supervisor` supports install/adopt/remove capability management, safe doctor
fix, and safe doctor adopt. Runtime lifecycle and logs for Supervisor-backed
services belong to the related process family, not the Supervisor tool.

`tool:install supervisor` and `tool:remove supervisor` are supported only where
the node/tool provisioning contract allows the Supervisor process manager to be
managed through `tool:*`. Supervisor is not a host PHP fallback.

## Credentials

`supervisor` does not support `tool:credentials`.

## Orbit Notes

Supervisor is the host process runtime for app and workspace configured process
units. The Orbit Scheduler runs inside gateway `orbit-scheduler`, not as a host
Supervisor program.

The tool family owns Supervisor's installation status and the `supervisord`
daemon's reachability. It does not own the Supervisor program registry or
Orbit-defined process lifecycle; Orbit-defined runtime units belong to the
`process` family.

Supervisor itself runs under host init. Docker remains the baseline host
service for Orbit runtime containers and service backends.

## Doctor Relationship

`doctor --family=tool` may adopt an existing Supervisor installation and
may fix safe service drift such as a stopped `supervisord` daemon.

When `process.runtime_backend_unavailable` is reported for a Supervisor process
runtime, the `tool` family is the right doctor to repair the Supervisor
installation or restart the `supervisord` daemon.

The `process` family owns Supervisor program drift for runtime units that Orbit
manages. The `schedule` family owns Orbit Scheduler liveness and heartbeat
drift in `orbit-scheduler`. The `tool` family does not duplicate those checks.
