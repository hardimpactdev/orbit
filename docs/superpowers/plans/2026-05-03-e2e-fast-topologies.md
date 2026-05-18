# E2E Fast Topologies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Orbit E2E fast for feature testing by cloning prepared node topologies instead of reprovisioning control, gateway, development app, and production app nodes in every test.

**Architecture:** Keep provisioning E2Es as a separate slow lane that proves Orbit can build the world from blank/ready images. Add a fast topology lane where tests request the smallest prepared topology they need, and the harness clones disposable VMs from prepared Incus topology templates. Treat `beast`, `sidecar1`, and `sidecar2` as one Incus capacity pool so topology leases can be placed on any healthy host with enough VM slots. Hcloud starts as a supported provider for existing image-based E2E and can later gain equivalent snapshot topologies without blocking Incus speedups.

**Tech Stack:** Laravel 13, Pest 4, Incus VMs on `beast`, `sidecar1`, and `sidecar2`, optional hcloud snapshots, existing `tests/E2E/Support` provider abstractions.

---

## Current State

Relevant files:

- `tests/Pest.php` groups `tests/E2E` as `e2e` and skips unless `ORBIT_E2E=1`.
- `tests/E2E/Support/E2EProvider.php` can start a run, create SSH keys, launch `E2EImage` instances, and cleanup instances.
- `tests/E2E/Support/IncusProvider.php` launches VMs from prepared images.
- `tests/E2E/Support/HcloudProvider.php` launches hcloud servers from configured base/snapshot images.
- `tests/E2E/GatewayAddTest.php`, `tests/E2E/NodeNewDevelopmentAppTest.php`, and `tests/E2E/NodeNewProductionAppTest.php` contain duplicated network, gateway API, SSH, and assertion helpers.
- Provisioning E2Es currently create the desired topology by running actual commands such as `gateway:add` and `node:new`, which is correct for provisioning tests but too slow as the default setup path for feature E2Es.
- Incus capacity exists on `beast`, `sidecar1`, and `sidecar2`. Each sidecar has 12 threads and should be able to run at least four VMs, so the harness should not hard-code all E2E work to `beast`.

Keep these two lanes distinct:

- **Provisioning lane:** tests that prove Orbit can set up or mutate machines.
- **Feature lane:** tests that start from an already-shaped topology and test Orbit behavior after provisioning.

---

## Target API

Feature E2Es should look like this:

```php
use Tests\E2E\Support\E2ETopology;
use Tests\E2E\Support\E2ETopologyKind;

it('lists nodes from a control node', function (): void {
    $topology = E2ETopology::fromEnvironment()->require(E2ETopologyKind::ControlGatewayDevProd);

    try {
        $control = $topology->control();
        $result = $topology->ssh($control, 'orbit node:list --json');

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
        expect($result->output())->toContain('gateway');
        expect($result->output())->toContain('app-dev-1');
        expect($result->output())->toContain('app-prod-1');
    } finally {
        $topology->cleanup();
    }
});
```

Topology kinds:

```php
enum E2ETopologyKind: string
{
    case Control = 'control';
    case ControlGateway = 'control-gateway';
    case ControlGatewayDev = 'control-gateway-dev';
    case ControlGatewayDevProd = 'control-gateway-dev-prod';
}
```

Required environment knobs:

```bash
ORBIT_E2E=1
ORBIT_E2E_PROVIDER=incus
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone
```

Strategy values:

- `minimal`: clone exactly the smallest topology requested by each test.
- `superset`: allow `control-gateway-dev-prod` to satisfy smaller topology requests in a suite cycle.

Reset values:

- `fresh-clone`: delete/reclone topology VMs for each test file or lease.
- `snapshot-restore`: future optimization for restoring an already-cloned topology snapshot inside one run.

Incus host selection:

- `ORBIT_E2E_INCUS_HOSTS` is a comma-separated list of SSH hosts.
- If unset, preserve the current `ORBIT_E2E_HOST` behavior and default to `beast`.
- A topology lease must stay on one Incus host because synthetic `10.6.0.x` routes assume local VM networking.
- Different topology leases may run on different Incus hosts.
- Placement should choose the first healthy host with required templates and available VM capacity, then fall back to the next host.

---

## Task 1: Mark E2E Lanes Explicitly

**Files:**

- Modify: `tests/Pest.php`
- Modify: `composer.json`
- Modify: `TESTING.md`
- Modify: `docs/porting/PORTING.md`

- [ ] Add two Pest groups while preserving the existing `e2e` group:

```php
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        if (env('ORBIT_E2E') !== '1') {
            $this->markTestSkipped('Set ORBIT_E2E=1 to run ephemeral E2E tests.');
        }
    })
    ->group('e2e')
    ->in('E2E');
```

Keep this as-is, then add file-level groups in E2E files:

```php
pest()->group('e2e-provisioning');
```

for:

- `tests/E2E/BlankVmLifecycleTest.php`
- `tests/E2E/ControlNodeSmokeTest.php`
- `tests/E2E/GatewayAddTest.php`
- `tests/E2E/NodeNewGatewayTest.php`
- `tests/E2E/NodeNewDevelopmentAppTest.php`
- `tests/E2E/NodeNewProductionAppTest.php`

Feature topology tests added later should use:

```php
pest()->group('e2e-feature');
```

- [ ] Add Composer scripts:

```json
"test:e2e:provisioning": [
    "Composer\\Config::disableProcessTimeout",
    "ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provisioning"
],
"test:e2e:features": [
    "Composer\\Config::disableProcessTimeout",
    "ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature"
]
```

- [ ] Document lane rules in `TESTING.md`:
  - provisioning E2E may mutate disposable VMs and test setup flows;
  - feature E2E must start from prepared topology clones and should not run installer/provisioning commands unless the feature under test requires it;
  - live/standing infra smoke tests are sunset.

- [ ] Run:

```bash
composer test
composer docs-lint
```

Expected: both pass.

---

## Task 2: Extract Shared E2E Helpers

**Files:**

- Create: `tests/E2E/Support/E2ECommand.php`
- Create: `tests/E2E/Support/E2ENetwork.php`
- Create: `tests/E2E/Support/E2EGatewayApi.php`
- Create: `tests/E2E/Support/E2ENodeProbe.php`
- Modify: `tests/E2E/GatewayAddTest.php`
- Modify: `tests/E2E/NodeNewDevelopmentAppTest.php`
- Modify: `tests/E2E/NodeNewProductionAppTest.php`

- [ ] Create `E2ECommand` for command assertions:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

final readonly class E2ECommand
{
    public static function exec(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->exec($command, $timeoutSeconds);

        expect($result->successful())->toBeTrue($message."\n".$result->output().$result->errorOutput());

        return $result;
    }

    public static function orbit(E2EInstance $instance, string $command, string $message, ?int $timeoutSeconds = null): ProcessResult
    {
        return self::exec(
            $instance,
            'sudo -iu orbit bash -lc '.escapeshellarg($command),
            $message,
            $timeoutSeconds,
        );
    }

    public static function ssh(E2EInstance $instance, string $user, SshKeyPair $key, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $instance->ssh($user, $key, $command, $timeoutSeconds);

        expect($result->successful())->toBeTrue($result->output().$result->errorOutput());

        return $result;
    }
}
```

- [ ] Create `E2ENetwork`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ENetwork
{
    public static function assignWireGuardIp(E2EInstance $instance, string $wireGuardIp): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; ip addr add %s dev "$iface" 2>/dev/null || true; ip link set "$iface" up',
                escapeshellarg("{$wireGuardIp}/16"),
            ),
            "Could not assign {$wireGuardIp} to {$instance->name()}",
        );
    }

    public static function routeWireGuardPeer(E2EInstance $instance, string $wireGuardIp, string $providerIp, string $sourceWireGuardIp): void
    {
        E2ECommand::exec(
            $instance,
            sprintf(
                'iface="$(ip -o -4 route show default | awk \'{print $5; exit}\')"; test -n "$iface"; ip route replace %s via %s dev "$iface" src %s',
                escapeshellarg("{$wireGuardIp}/32"),
                escapeshellarg($providerIp),
                escapeshellarg($sourceWireGuardIp),
            ),
            "Could not route {$wireGuardIp} via {$providerIp} from {$instance->name()}",
        );
    }
}
```

- [ ] Move gateway API test-server setup into `E2EGatewayApi`.
  - Keep the current small TLS PHP server approach.
  - Include support for `GET /api/me`.
  - Include support for `POST /api/nodes` by shelling to `php artisan node:new` on the gateway, matching the development and production app E2Es.
  - Keep root CA served by Laravel on HTTP port 80.

- [ ] Move installed-node checks into `E2ENodeProbe`:

```php
public static function assertOrbitInstalled(E2EInstance $instance): void
{
    $install = $instance->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan');
    $version = $instance->exec("sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");

    expect($install->successful())->toBeTrue($install->output().$install->errorOutput())
        ->and($version->successful())->toBeTrue($version->output().$version->errorOutput());
}
```

- [ ] Refactor the three existing E2E files to use these helpers without changing behavior.

- [ ] Run:

```bash
php -l tests/E2E/Support/E2ECommand.php
php -l tests/E2E/Support/E2ENetwork.php
php -l tests/E2E/Support/E2EGatewayApi.php
php -l tests/E2E/Support/E2ENodeProbe.php
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e --filter='joins a prepared gateway from a ready control VM'
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e --filter='provisions a development app node from a ready control VM through a ready gateway VM'
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e --filter='provisions a production app node from a ready control VM through a ready gateway VM'
vendor/bin/pint --dirty --format agent
```

Expected: all pass.

---

## Task 3: Add Topology Domain Objects

**Files:**

- Create: `tests/E2E/Support/E2ETopologyKind.php`
- Create: `tests/E2E/Support/E2ETopologyLease.php`
- Create: `tests/E2E/Support/E2ETopologyFactory.php`
- Create: `tests/Feature/E2ETopologyFactoryTest.php`

- [ ] Create `E2ETopologyKind`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

enum E2ETopologyKind: string
{
    case Control = 'control';
    case ControlGateway = 'control-gateway';
    case ControlGatewayDev = 'control-gateway-dev';
    case ControlGatewayDevProd = 'control-gateway-dev-prod';
}
```

- [ ] Create `E2ETopologyLease` with typed accessors:

```php
public function control(): E2EInstance;
public function gateway(): ?E2EInstance;
public function devApp(): ?E2EInstance;
public function prodApp(): ?E2EInstance;
public function sshKeyPair(): SshKeyPair;
public function cleanup(): void;
```

The lease owns cleanup, not the test file. Cleanup must be idempotent.

- [ ] Create `E2ETopologyFactory::fromEnvironment()` and `require(E2ETopologyKind $kind): E2ETopologyLease`.

Initial behavior:

- select provider from `ProviderPool::fromEnvironment()`;
- if provider is Incus and a prepared topology template exists, clone it;
- if provider cannot supply prepared topology, skip with a clear message using `$this->markTestSkipped()` from the calling test.

- [ ] Add feature tests for:
  - enum values;
  - `minimal` strategy returns requested topology kind;
  - `superset` strategy may substitute `ControlGatewayDevProd` for smaller feature topologies;
  - unknown strategy falls back to `minimal`.

- [ ] Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php
vendor/bin/pint --dirty --format agent
```

Expected: pass.

---

## Task 4: Implement Incus Topology Templates

**Files:**

- Modify: `tests/E2E/Support/IncusProvider.php`
- Modify: `tests/E2E/Support/IncusInstance.php`
- Modify: `tests/E2E/Support/IncusHost.php`
- Create: `tests/E2E/Support/IncusHostPool.php`
- Create: `tests/E2E/Support/IncusTopologyTemplate.php`
- Create: `tests/Feature/IncusTopologyTemplateTest.php`

Template names:

```text
orbit-template-control
orbit-template-control-gateway
orbit-template-control-gateway-dev
orbit-template-control-gateway-dev-prod
```

Clone names:

```text
orbit-e2e-<run-id>-control
orbit-e2e-<run-id>-gateway
orbit-e2e-<run-id>-dev
orbit-e2e-<run-id>-prod
```

- [ ] Add host helpers:

```php
public function instanceExists(string $name): bool;
public function snapshotExists(string $instance, string $snapshot): bool;
public function copyInstance(string $source, string $target): ProcessResult;
public function startInstance(string $name): ProcessResult;
public function stopInstance(string $name): ProcessResult;
public function snapshotInstance(string $name, string $snapshot): ProcessResult;
```

Use `incus info`, `incus copy`, `incus start`, `incus stop`, and `incus snapshot`.

- [ ] Add `IncusHostPool`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class IncusHostPool
{
    /** @param list<IncusHost> $hosts */
    public function __construct(private array $hosts) {}

    public static function fromEnvironment(E2EConfig $config): self
    {
        $hostNames = getenv('ORBIT_E2E_INCUS_HOSTS');

        if (! is_string($hostNames) || trim($hostNames) === '') {
            return new self([new IncusHost($config)]);
        }

        $hosts = array_values(array_filter(array_map(
            fn (string $host): string => trim($host),
            explode(',', $hostNames),
        )));

        return new self(array_map(
            fn (string $host): IncusHost => new IncusHost($config->forHost($host)),
            $hosts,
        ));
    }

    public function firstAvailableFor(E2ETopologyKind $kind): ?IncusHost
    {
        foreach ($this->hosts as $host) {
            if (IncusTopologyTemplate::availableOn($host, $kind)) {
                return $host;
            }
        }

        return null;
    }
}
```

Add `E2EConfig::forHost(string $host): self` so workers do not mutate global config.

- [ ] `IncusTopologyTemplate` maps each topology kind to required template instance names:

```php
Control => ['control']
ControlGateway => ['control', 'gateway']
ControlGatewayDev => ['control', 'gateway', 'dev']
ControlGatewayDevProd => ['control', 'gateway', 'dev', 'prod']
```

- [ ] Clone implementation:
  - choose one host from `IncusHostPool`;
  - verify every required `orbit-template-<kind>-<role>` instance exists;
  - clone each template into the run;
  - start each clone;
  - wait for Incus agent and SSH;
  - return `E2ETopologyLease`.

Do not split one topology across multiple Incus hosts. Cross-host WireGuard simulation and routes are a later feature.

- [ ] Cleanup deletes all cloned instances and the run directory.

- [ ] Run feature tests with fake/process-level assertions where possible:

```bash
php artisan test --compact tests/Feature/IncusTopologyTemplateTest.php
```

Expected: pass.

---

## Task 5: Add Topology Preparation Command

**Files:**

- Create: `app/Console/Commands/E2EPrepareTopologyCommand.php`
- Modify: `composer.json`
- Create: `tests/Feature/Commands/E2EPrepareTopologyCommandTest.php`

Command:

```bash
php artisan e2e:prepare-topology {kind=control-gateway-dev-prod} {--force} {--json}
```

Supported kinds:

- `control`
- `control-gateway`
- `control-gateway-dev`
- `control-gateway-dev-prod`

Implementation rules:

- Hidden command, same style as `e2e:prepare-hcloud-images`.
- Default dry-run unless `--force` is present.
- Incus-only for the first implementation.
- Build templates from existing prepared images and existing provisioning E2Es logic.
- Template build can be pragmatic:
  - create VMs from `orbit-ready-control`, `orbit-ready-gateway`, and blank image;
  - use the same setup flow already proven by provisioning E2Es;
  - stop VMs once shaped;
  - snapshot each template as `clean`.

Important: this command prepares templates. It is allowed to run provisioning steps. Feature tests are not.

- [ ] The command should output JSON like:

```json
{
  "success": {
    "data": {
      "provider": "incus",
      "dry_run": false,
      "kind": "control-gateway-dev-prod",
      "templates": [
        {"role": "control", "name": "orbit-template-control-gateway-dev-prod-control", "snapshot": "clean"},
        {"role": "gateway", "name": "orbit-template-control-gateway-dev-prod-gateway", "snapshot": "clean"},
        {"role": "dev", "name": "orbit-template-control-gateway-dev-prod-dev", "snapshot": "clean"},
        {"role": "prod", "name": "orbit-template-control-gateway-dev-prod-prod", "snapshot": "clean"}
      ]
    }
  }
}
```

- [ ] Add Composer script:

```json
"e2e:prepare-topology": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan e2e:prepare-topology"
]
```

- [ ] Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EPrepareTopologyCommandTest.php
composer analyse
vendor/bin/pint --dirty --format agent
```

Expected: pass.

---

## Task 6: Add First Feature E2E Using Prepared Topology

**Files:**

- Create: `tests/E2E/NodeListTopologyTest.php`

Purpose: prove the fast topology lane works without provisioning.

Test:

```php
pest()->group('e2e-feature');

it('lists nodes from a prepared full topology', function (): void {
    $topology = E2ETopologyFactory::fromEnvironment()->require(E2ETopologyKind::ControlGatewayDevProd);

    try {
        $control = $topology->control();
        $key = $topology->sshKeyPair();

        $result = E2ECommand::ssh($control, 'control', $key, 'cd /home/control/orbit && orbit node:list --json');
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'gateway'
        );
        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'app-dev-1'
        );
        expect($payload['success']['data']['nodes'])->toContain(
            fn (array $node): bool => $node['name'] === 'app-prod-1'
        );
    } finally {
        $topology->cleanup();
    }
});
```

Adjust exact JSON path to current `node:list --json` output.

- [ ] Run:

```bash
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --filter='lists nodes from a prepared full topology'
```

Expected: pass once topology templates are prepared. If templates are missing, skip with a clear message.

---

## Task 7: Reset Semantics

**Files:**

- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Modify: `tests/E2E/Support/IncusTopologyTemplate.php`
- Create: `tests/Feature/E2ETopologyResetTest.php`

Initial reset behavior:

- `fresh-clone`: cleanup deletes clones; next lease reclones from template.
- `snapshot-restore`: skip or throw unsupported for now unless implemented fully.

Add:

```php
public function reset(): void;
```

For first implementation, `reset()` can:

1. cleanup current clones;
2. reclone from the same template;
3. update lease instance references.

This gives a simple, reliable reset API before optimizing with Incus snapshot restore.

- [ ] Add tests for:
  - cleanup idempotency;
  - reset calls cleanup and re-acquires instances;
  - unsupported reset strategy yields clear exception/message.

- [ ] Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php
vendor/bin/pint --dirty --format agent
```

Expected: pass.

---

## Task 8: Superset Lease Optimization

**Files:**

- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Create: `tests/Feature/E2ETopologyStrategyTest.php`

Behavior:

- `minimal`: request `ControlGateway` clones a `ControlGateway` template.
- `superset`: request `ControlGateway`, `ControlGatewayDev`, or `Control` may clone `ControlGatewayDevProd` if that template exists.

Do not share one mutable lease between parallel Pest tests. Superset means “use bigger template”, not “reuse same live VMs across unrelated tests”.

Add environment parsing:

```php
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal|superset
```

- [ ] Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyStrategyTest.php
```

Expected: pass.

---

## Task 9: Provider Capability Model

**Files:**

- Modify: `tests/E2E/Support/E2EProvider.php`
- Modify: `tests/E2E/Support/IncusProvider.php`
- Modify: `tests/E2E/Support/HcloudProvider.php`
- Create: `tests/Feature/E2EProviderCapabilityTest.php`

Add provider capabilities:

```php
public function supportsPreparedTopologies(): bool;
public function topologyAvailability(E2ETopologyKind $kind): ProviderAvailability;
public function acquireTopology(E2ETopologyKind $kind, string $label): E2ETopologyLease;
```

Incus:

- returns true once template support exists;
- checks every configured Incus host from `ORBIT_E2E_INCUS_HOSTS`;
- reports unavailable only when no configured host has the required topology templates and enough capacity.

Capacity rules:

- Add a conservative per-host capacity setting:

```bash
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4
```

- Default to `4` for `sidecar1` and `sidecar2` class hosts; allow override for `beast`.
- Count running `orbit-e2e-*` VMs on a host before placement.
- A `ControlGatewayDevProd` lease needs four VM slots.
- A `ControlGatewayDev` lease needs three VM slots.
- A `ControlGateway` lease needs two VM slots.
- A `Control` lease needs one VM slot.
- Prefer hosts in configured order, but skip hosts without enough free slots.

Hcloud:

- returns false initially;
- feature topology tests skip with: `hcloud prepared topologies are not implemented yet`;
- existing provisioning E2Es continue to work.

- [ ] Run:

```bash
php artisan test --compact tests/Feature/E2EProviderCapabilityTest.php
```

Expected: pass.

---

## Task 10: Reaper And Cleanup Hardening

**Files:**

- Modify: `bin/e2e`
- Modify: `app/Console/Commands/E2EReapHcloudCommand.php`
- Create: `app/Console/Commands/E2EReapIncusCommand.php`
- Create: `tests/Feature/Commands/E2EReapIncusCommandTest.php`

Add Incus reap command:

```bash
php artisan e2e:reap-incus {--older-than=6h} {--dry-run} {--json}
```

Rules:

- Delete run clones with prefix `orbit-e2e-*`.
- Never delete template instances with prefix `orbit-template-*`.
- Never delete prepared images `orbit-ready-*`.
- Dry run by default unless `--force` is added, or use `--dry-run` if matching existing command style.

Add script:

```json
"e2e:reap-incus": "@php artisan e2e:reap-incus"
```

- [ ] Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EReapIncusCommandTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Expected: pass.

---

## Task 11: Documentation Update

**Files:**

- Modify: `TESTING.md`
- Modify: `docs/porting/PORTING.md`
- Modify: `docs/domains/README.md` if command testing guidance mentions E2E lanes.

Document:

- two test classes: in-memory Pest and ephemeral E2E;
- two E2E lanes: provisioning and feature topology;
- topology kinds and when to choose each;
- commands:

```bash
composer test
composer test:e2e:provisioning
composer test:e2e:features
composer e2e:prepare-topology -- --force control-gateway-dev-prod
composer e2e:reap-incus
```

- environment:

```bash
ORBIT_E2E=1
ORBIT_E2E_PROVIDER=incus
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4
ORBIT_E2E_TOPOLOGY_STRATEGY=minimal
ORBIT_E2E_TOPOLOGY_RESET=fresh-clone
```

- safety rule: no standing live infra smoke lane.

- [ ] Run:

```bash
composer docs-lint
```

Expected: pass.

---

## Task 12: Final Verification Gate

Run after all previous tasks are complete:

```bash
vendor/bin/pint --dirty --format agent
composer test
composer analyse
composer docs-lint
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature
```

Run provisioning E2E only if the branch touched installer/provisioning behavior:

```bash
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provisioning
```

Expected:

- non-E2E suite passes;
- PHPStan passes;
- docs lint passes;
- feature E2E either passes or skips only when prepared topology templates are explicitly missing;
- provisioning E2E passes when run with prepared images available.

---

## Orchestration Notes For Claude

- Assign Task 1 to one Kimi agent.
- Assign Task 2 to one Kimi agent because it touches the existing E2E files heavily.
- Assign Tasks 3 and 4 separately; Task 4 depends on Task 3.
- Assign Task 5 after Task 4.
- Assign Task 6 after Task 5.
- Assign Tasks 7 and 8 after Task 6 is green.
- Assign Task 9 after Task 3 but before broad conversion work.
- Assign Task 10 independently after Task 4 establishes template naming.
- Assign Task 11 near the end.
- Keep write scopes disjoint. Do not let two agents edit the same E2E support file at the same time.
- Require every agent final report to include exact commands run and whether E2E was pass, fail, or skipped.
- When running multiple Kimi/OpenCode agents, set `ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2` so topology leases can spread across all Incus capacity. Do not point multiple agents at a single fixed host unless the task is host-specific.

The highest-risk pieces are Task 4 and Task 5. If those run long, pause after Task 3 and Task 2; they already make the existing E2Es easier to maintain and prepare the codebase for topology cloning.
