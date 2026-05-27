# E2E HTTP Reachability Assertions

> **For agentic workers:** Use `docs/testing/README.md` and
> `docs/testing/e2e/**` as authority before changing E2E code, tests, or
> prepared artifacts.

**Goal:** Verify runtime HTTP reachability from inside prepared E2E topologies:
operator node to gateway over WireGuard and DNS, then HTTP(S) to the resolved
service.

**Architecture:** Reachability assertions run from the operator node through
prepared topology handles. They use `E2EReachability` for DNS and HTTP checks
and never require a test to build a topology from base VMs.

**Tech Stack:** Pest E2E, `E2ETopologyKind`, `e2eTopology(...)`,
`E2EReachability`, Docker and Incus prepared topology providers.

## Current Contract

Reachability assertions use the smallest prepared topology that contains the
service under test:

- gateway DNS and gateway API assertions use `operator_gateway`;
- development app assertions use `operator_gateway_app-dev`;
- production app assertions use `operator_gateway_app-dev_app-prod` or
  `operator_gateway_app-prod_ingress`, depending on route placement;
- Incus-only VM assertions carry `e2e-provider-incus`.

`E2EReachability` provides the reusable checks:

- `assertDnsResolvesOverWg(...)`
- `assertHttpReachable(...)`
- `assertHttpResponseContains(...)`
- `assertHttpNotServing(...)`

The checks run over SSH inside the prepared topology. Host-side curl or DNS
checks are not a substitute because they bypass the topology network path.

## Test Shape

Feature tests acquire a prepared topology and overlay the current checkout when
the command under test needs branch code:

```php
$topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev, withGatewayApi: true);

try {
    $topology->withCurrentCheckout(roles: ['operator', 'gateway', 'dev']);

    E2EReachability::assertDnsResolvesOverWg(
        $topology->instance('operator'),
        'orbit',
        $topology->lease()->sshKeyPair(),
        'docs.test',
        '10.6.0.4',
    );
} finally {
    $topology->cleanup();
}
```

## Verification

Use the prepared topology aggregate for integrated reachability coverage:

```bash
composer test:e2e
```

Use provider-specific lanes for focused diagnosis:

```bash
composer test:e2e:docker
composer test:e2e:incus
```
