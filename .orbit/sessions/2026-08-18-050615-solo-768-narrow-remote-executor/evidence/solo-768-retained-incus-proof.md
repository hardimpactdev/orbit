# Todo 768 — Retained-Incus Runtime Proof

## Identity
- Candidate: `1fe426f20e29aff6937baae2ed4c28898a9e18e6` (amended from `d1df7e05`;
  orphaned `tests/Fakes/RemoteShellBackedRemoteExecutor.php` deleted). Topology re-synced
  to this candidate via `composer e2e:incus -- --sync --id=dev-52d37d`.
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `orbit-e2e-dev-52d37d` (operator_gateway_app-dev; roles operator, gateway, dev)
- Source binding: operator VM `/home/orbit/orbit` verified bound to the candidate by
  file-content sha256 (worktree == VM):
  - `apps/gateway/app/Services/RemoteShell/SshRemoteShell.php`
    `6921a106b0f98388467b443ff524b8c2925a584cb444d55f7d4cdab380594620`
  - orphaned fake `tests/Fakes/RemoteShellBackedRemoteExecutor.php`: absent in VM (deleted).
  - aggregate `RemoteExecutor.php`: absent in both (deleted).

## What was exercised
Contract Pest for the narrowed transport surfaces, executed inside the retained
operator VM against the candidate-bound source:

- `tests/Unit/Services/RemoteShell` — executor contracts, local executor metadata,
  host/orbit-gateway executor `start()` behavior, architecture test.
- `tests/Feature/Services/SshRemoteShellTest.php` — host-process `start()`, ssh
  dispatch, wireguard address selection, audit redaction.
- `tests/Feature/Services/Operations/ProvisioningAgentInstallerTest.php` — provisioning
  SSH install/start via the `RemoteShell`-narrowed transport, refusal after node active.

## Observed
```
PASS Tests\Unit\Services\RemoteShell\* / SshRemoteShellTest / ProvisioningAgentInstallerTest
Tests: 300 passed (1379 assertions)
Duration: 29.25s
```

## Receipt (structured)
- candidate=`1fe426f20e29aff6937baae2ed4c28898a9e18e6`
- venue=retained-incus
- environment=dev-fixture
- expected=narrowed RemoteShell/StartsRemoteShellProcesses/RunsInternalCommands contracts
  observed as green in the retained operator VM; capable-executor `start()` retained
- observed=300 passed / 1379 assertions, 0 failures
- result=passed
- command=`ssh beast incus exec orbit-e2e-dev-52d37d-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit/apps/gateway && DB_DATABASE=/tmp/768fix-test.sqlite APP_ENV=testing HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test tests/Unit/Services/RemoteShell tests/Feature/Services/SshRemoteShellTest.php tests/Feature/Services/Operations/ProvisioningAgentInstallerTest.php --compact'`

Release: `composer e2e:incus -- --stop --id=dev-52d37d`
