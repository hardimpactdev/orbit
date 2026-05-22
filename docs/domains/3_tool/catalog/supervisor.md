# Tool Catalog: `supervisor`

[Back to tool catalog.](README.md)

## Catalog

These fields describe the Supervisor tool's identity, backend, and support model in Orbit.

| Field | Value |
| --- | --- |
| Slug | `supervisor` |
| Label | Supervisor |
| Backend | system service |
| Support model | Explicit residual runtime only where configured |
| Category | `runtime` |

## Capabilities

`supervisor` supports lifecycle actions (`tool:start`, `tool:stop`,
`tool:restart`), `tool:reload`, `tool:logs`, safe doctor fix, and safe
doctor adopt.

`tool:install supervisor` and `tool:remove supervisor` are supported only for
nodes that explicitly use `process.runtime=supervisor` or another documented
residual runtime on the host for non-PHP work. Supervisor is not a general node
baseline and is not a host PHP fallback.

## Credentials

`supervisor` does not support `tool:credentials`.

## Orbit Notes

Supervisor is an explicit residual process runtime for supported non-PHP
host-side units. Docker process runtime is the default for PHP app and
workspace process units. The Orbit Scheduler runs inside gateway
`orbit-runtime`, not as a host Supervisor program.

The tool family owns Supervisor's installation status, the `supervisord`
daemon's reachability, and lifecycle drift only where explicit Supervisor
runtime is configured. It does not own the Supervisor program registry;
Orbit-defined runtime units belong to the `process` family.

Supervisor itself runs under host init when an explicit residual runtime needs
it. Docker remains the baseline host service for Orbit runtime containers.

## Doctor Relationship

`doctor --family=tool` may adopt an existing Supervisor installation and
may fix safe service drift such as a stopped `supervisord` daemon.

When `process.runtime_backend_unavailable` is reported for an explicit
`supervisor` process runtime, the `tool` family is the right doctor to repair
the Supervisor installation or restart the `supervisord` daemon.

The `process` family owns Supervisor program drift for residual runtime units
that Orbit manages. The `schedule` family owns Orbit Scheduler liveness and
heartbeat drift in `orbit-runtime`. The `tool` family does not duplicate those
checks.
