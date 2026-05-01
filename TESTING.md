# TESTING.md

Environment-specific verification for the clean Orbit rebuild.

## Current Real Nodes

The standing real-node smoke path uses the gateway registry:

- `gateway`: `10.6.0.2`, SSH alias `gateway`
- `mini`: `10.6.0.8`, registered as a control node
- `beast`: `10.6.0.7`, registered as an app node

`gateway` owns the current `nodes` registry used by `update:all`.

## E2E Entrypoint

Use:

```bash
bin/e2e --local
bin/e2e --real
```

`--local` runs the local test suite.

`--real` runs local tests, SSHes to the gateway, runs `update:all`, runs
gateway-side tests, and prints `node:list`.

Environment overrides:

```bash
ORBIT_E2E_GATEWAY_SSH=gateway
ORBIT_E2E_GATEWAY_PATH=~/orbit
```

## Rule

Use standing real nodes only for the current smoke path. Full destructive or
provisioning E2E must use ephemeral nodes once that suite is restored.

