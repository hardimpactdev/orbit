# Todo 765 — Retained-Incus Runtime Proof

- Candidate: `208409f16517fb9556453d602842eb1ce7d7815c`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `operator_gateway_app-dev` id `dev-b06040` (host `beast`)
- VMs: `orbit-e2e-dev-b06040-{operator,gateway,dev}`
- Bind: sha256 of the changed gateway files (composer.json, phpunit.xml, tests/Pest.php,
  GatewayComposerBoundaryTest.php, VerificationScriptsTest.php) match exactly between the
  worktree candidate and the operator VM mount `/home/orbit/orbit`.
- Note: the operator_gateway_app-dev acquisition was re-run with
  `COMPOSER_PROCESS_TIMEOUT=0` because the default 300s composer wrapper timeout tripped
  during the wireguard phase under heavy host load (many retained topologies present).

## Part A — gateway boots on the candidate

The topology brought up `operator`, `gateway`, `dev` on the candidate checkout; the
gateway API reported ready (`gateway-api.ready`) with WireGuard gateway `10.6.0.2` / dev
`10.6.0.4`. This confirms the facade removal does not break the deployed gateway's boot
or command surface.

## Part B — facade removed and KEEP items intact on the deployed runtime

Ran the facade-contract and E2E-support Pest inside the operator VM against an isolated
disposable test DB (NOT `composer test:e2e*`):
`ssh beast incus exec orbit-e2e-dev-b06040-operator -- sudo -u orbit bash -lc 'cd
/home/orbit/orbit/apps/gateway && DB_DATABASE=/tmp/765-test.sqlite APP_ENV=testing
HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test
tests/Feature/Architecture/GatewayComposerBoundaryTest.php
tests/Feature/E2ESupport/VerificationScriptsTest.php
tests/Feature/E2ESupport/E2EPestHelpersTest.php --compact'`.

Observed: **68 passed (692 assertions)** on the retained-topology runtime — proving:
- `GatewayComposerBoundaryTest` now asserts the dead gateway E2E script keys are ABSENT
  from the deployed `apps/gateway/composer.json`;
- `VerificationScriptsTest` (E2E support) passes with the removed-script references
  updated;
- `E2EPestHelpersTest` passes, confirming the preserved `tests/E2E/Support/Pest.php`
  helper tree still loads (minimal-path preservation — `tests/E2E/` not deleted).

Result: passed.
