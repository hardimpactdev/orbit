# Retained Incus acceptance: proxy restore verification

- Topology id: `dev-2b9262`
- Kind: `operator_gateway_app-prod_ingress`
- Provider/host: `incus` on `beast`
- Isolated instances:
  - operator: `orbit-e2e-dev-2b9262-operator`
  - gateway/router: `orbit-e2e-dev-2b9262-gateway`
  - app-prod/ingress: `orbit-e2e-dev-2b9262-prod`
- Runtime checkout: `/home/orbit/orbit-run`
- Disposable app: `orbit-proof`, domain `orbit-proof.test`

## Token and execution-lane proof

The real app creation and Doctor probes dispatched Agent-push commands through
`/home/orbit/.local/bin/orbit`. Gateway operation runs show consumed tokens for
gateway-local and Agent-push commands:

```text
gateway target, internal:tool:run-script:
status=succeeded
operation_token_consumed_at=2026-07-16 17:27:53

app-prod target, internal:tool:run-script:
status=succeeded
operation_token_consumed_at=2026-07-16 17:27:57
```

The retained node exposed an additional real failure: `bash -lc` ran
`/home/orbit/.bash_logout`, whose `clear_console -q` changed a successful proxy
probe to exit 1. After changing the local tool runner to `bash -c`, the same
real probe completed and Doctor returned structured drift instead of a
transport exception.

## Complete proxy read-back and failed enactment proof

Command:

```text
./apps/cli/orbit doctor --app=orbit-proof --node=app-prod-1 --family=proxy --restore --stream-json --no-interaction
```

Result: exit 1, `healthy=false`, `issues=6`, `fixed=0`, `failed=5`.

The fresh post-restore read-back still reported:

```text
proxy.public_route_missing
proxy.router_route_missing
proxy.backend_route_missing
proxy.enactment_incomplete
proxy.caddy_container_missing
proxy.global_config_missing
```

The persisted partial enactment identified the exact failed layer, node, and
operation:

```json
{
  "status": "partial",
  "failure": {
    "layer": "backend",
    "node": "app-prod-1",
    "operation": "caddy.backend.install"
  }
}
```

The restore action remained failed and did not report convergence:

```text
Proxy route 'orbit-proof.test' failed on node 'app-prod-1' during 'caddy.backend.install'.
```

`proxy:list --json` reported the route as `status=partial`, not `expected` or
`converged`, while the backend, router, and ingress artifacts were absent.

The prepared topology intentionally lacked a managed `orbit-caddy` tool record,
so Doctor correctly refused container recreation and preserved the failed
read-back. Successful backend -> router -> ingress restoration is verified
separately on the real Orbit main topology after candidate installation.
