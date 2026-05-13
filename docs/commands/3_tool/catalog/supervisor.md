# Tool Catalog: `supervisor`

[Back to tool catalog.](README.md)

## Catalog

| Field | Value |
| --- | --- |
| Slug | `supervisor` |
| Label | Supervisor |
| Backend | system service |
| Support model | Required baseline, adopted and kept converged |
| Category | `always` |

## Capabilities

`supervisor` supports lifecycle actions (`tool:start`, `tool:stop`,
`tool:restart`), `tool:reload`, `tool:logs`, safe doctor fix, and safe
doctor adopt.

`tool:install supervisor` and `tool:remove supervisor` are not supported
because Supervisor is a required node baseline tool. It is installed by
node provisioning and kept alive by host init (the distro
`supervisor.service` unit on Ubuntu).

## Credentials

`supervisor` does not support `tool:credentials`.

## Orbit Notes

Supervisor is the process manager on every gateway and app node. It
supervises Orbit-managed runtime units (rendered as Supervisor programs)
and the `orbit_scheduler` Supervisor program that runs the Orbit Scheduler
daemon.

The tool family owns Supervisor's installation status, the `supervisord`
daemon's reachability, and lifecycle drift on the node. It does not own
the Supervisor program registry — Orbit-defined runtime units belong to
the `process` family, and the Orbit Scheduler program belongs to the
`schedule` family.

Supervisor itself runs under host init, not under another supervisor.
Other host services (Caddy, PHP-FPM, Docker) also run under host init as
peers of Supervisor; they are not supervised by Supervisor.

## Doctor Relationship

`doctor --family=tool` may adopt an existing Supervisor installation and
may fix safe service drift such as a stopped `supervisord` daemon.

When `process.runtime_backend_unavailable` or
`schedule.runtime_backend_unavailable` is reported by their owning family
doctors, the `tool` family is the right doctor to repair the Supervisor
installation or restart the `supervisord` daemon.

The `process` family owns Supervisor program drift for Orbit-managed
runtime units; the `schedule` family owns Orbit Scheduler liveness and
heartbeat drift. The `tool` family does not duplicate those checks.
