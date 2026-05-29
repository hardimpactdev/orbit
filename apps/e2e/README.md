# Orbit E2E Harness

`apps/e2e` is the dedicated external E2E runner for Orbit.

The apps/e2e internal support namespace is the temporary home for migration
support code. Helpers here may drive Orbit through CLI, gateway HTTP API, SSH,
Docker, Incus, and process boundaries.

Tests in this app must not import gateway services, models, controllers, jobs,
or internal commands as product behavior. Gateway internals stay in
`apps/gateway`, and shared helper packages such as `packages/e2e-support` are
out of scope until a separate architecture task approves them.
