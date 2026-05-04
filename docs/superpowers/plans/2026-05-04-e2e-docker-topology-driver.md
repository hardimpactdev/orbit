# E2E Docker Topology Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Docker as an optional fast prepared-topology provider for feature E2E without weakening VM-backed provisioning coverage.

**Architecture:** Keep provisioning E2E on real VM providers. Split prepared topology selection from provisioning provider selection, then add a Docker provider that starts disposable containers for `e2e-feature` tests only. Docker optimizes command and gateway behavior feedback loops; Incus and hcloud remain the authority for SSH, sudo, OS trust stores, systemd, package installs, and real host mutation. Topology selection accepts optional capability requirements so a test that needs VM behavior can refuse Docker even when Docker is first in the pool.

**Tech Stack:** Laravel 13, Pest 4, Composer scripts, Docker CLI, existing `tests/E2E/Support` provider and topology classes, Incus VMs on `beast`, `sidecar1`, and `sidecar2`.

---

## Decisions

- Docker is a prepared-topology provider, not a provisioning provider.
- Docker feature runs may use container command transport for `E2EInstance::ssh()`. That does not count as real SSH coverage.
- Tests that need real SSH, sudo convergence, trust-store mutation, package installation, systemd, kernel networking, or cloud image behavior must require a VM provider via explicit capability requirements.
- Topology selection takes optional capability requirements. Without them, tests get whichever provider is first in the pool. With them, providers lacking the requested capability are skipped and the next provider is tried.
- Incus remains the default topology provider until Docker timing data proves it should be preferred for feature lanes.
- The first Docker implementation should run on the local Docker daemon. Remote Docker support follows as an explicit offload path so a low-resource laptop can run Pest locally while `beast` runs Docker containers through `DOCKER_HOST=ssh://beast`.
- Docker uses subnet `10.6.0.0/16` to match the seeded WireGuard addresses (`10.6.0.2` gateway, `10.6.0.3` control) without an additional address-rewriting step. Documented trade-off: a developer running an Orbit VPN client locally that already routes `10.6.0.x` will hit a conflict and must stop the tunnel before running Docker E2E.
- Docker does not run WireGuard inside containers. It substitutes direct Docker bridge reachability at the same `10.6.0.x` addresses. Docker topology builders and providers must not call `E2ENetwork::assignWireGuardIp()` or `E2ENetwork::routeWireGuardPeer()`; those remain Incus/VM responsibilities.
- The Docker topology image pins to `php:8.4-cli-bookworm`. `composer.json` requires `php: ^8.3`; bumping to PHP 8.5 should follow CI, not lead it.
- The Docker provider's lease passes `snapshotReset: null`. Current `E2ETopologyLease::reset()` already owns the fallback: it only uses snapshot restore when the closure is non-null, otherwise it cleans up and rebuilds. Setting `ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore` therefore falls back to fresh container recreation for Docker. Re-creating containers is cheap enough that snapshot-restore is not worth implementing.
- `E2EInstance::ssh()` keeps its name. The Docker implementation maps it to `docker exec --user $user`. Tests that need real SSH must declare `realSsh: true` so the pool refuses Docker for them. (Resolves Open Question 2.)
- Remote Docker hosts (Task 9) use `DOCKER_HOST=ssh://host` rather than wrapping every command in `ssh host docker ...`. The persistent connection is materially faster, and the Docker CLI handles credential forwarding cleanly. This is supported even if local Docker is not the long-term default, because remote Docker is useful for offloading feature E2E from resource-constrained machines. (Resolves Open Question 1.)
- Prepared Docker topologies must seed the same SQLite, certificate, and Node-registry state that Incus topologies seed via `IncusTopologyBuilder::provisionInstances()`. Task 6 splits this work into runtime image (6a), prepared per-role image build (6b), and per-test acquire (6c) so the seeding work is explicit rather than buried in plain English.
- The Docker bridge network is created per test and torn down on cleanup in the MVP. While Docker uses the fixed `10.6.0.0/16` subnet, Docker feature runs are serial per Docker daemon. Parallel Docker feature E2E requires either non-overlapping subnets or the deferred per-worker network design from Task 8.
- Hidden internal commands used only for E2E test setup (the existing `orbit:internal:bootstrap-gateway-local`, the new `orbit:internal:bake-app-node`) live under `app/Console/Commands/Internal/` and stay marked `$hidden = true`. Once the count grows (~5+) or an external tool wants to reuse them, extract into a dedicated `orbit-test-utilities` Composer package — but not before, since the in-app location is cheap to maintain while interfaces are still moving.

## Current Constraints

- `E2EConfig::$providerNames` currently controls provisioning providers.
- `E2ETopologyFactory` still has Incus-specific topology acquisition logic and reads `ORBIT_E2E_PROVIDER` directly via `getenv()` instead of through `E2EConfig`. After Task 3 the factory's `$provider` field is dead and must be removed.
- `E2EProvider` includes prepared-topology methods, but Incus prepared topology acquisition bypasses `IncusProvider`.
- `E2EInstance` has VM-shaped methods, so Docker must either satisfy the same call surface or feature tests must declare stronger capabilities before running.
- `E2ETopologyLease::reset()` currently reads `ORBIT_E2E_TOPOLOGY_RESET` directly via `getenv()`. This plan preserves that behavior; it only relies on the existing fallback where `snapshot-restore` without a snapshot reset closure uses cleanup + rebuild.
- Current feature scripts run topology contract tests before feature assertions, which is still the right safety model.
- `withE2EConfigEnvironment()` (in `tests/Feature/E2EConfigTest.php`) and `withE2EProviderEnvironment()` (in `tests/Feature/E2EProviderPoolTest.php`) overlap and will diverge as more topology env vars get added. Task 1 consolidates them into a shared helper.
- Incus prepared topologies seed real Orbit state during template build via `IncusTopologyBuilder::provisionInstances()`: `E2EControlIdentity::ensure()` writes Node rows, real `orbit gateway:add` runs end-to-end and produces a CA root + leaf certificate at `/home/orbit/orbit/storage/app/orbit/certs/10.6.0.2.crt`, real `orbit node:new` runs from control for dev/prod app nodes, and the resulting state gets snapshotted as `clean`. Per-clone acquire then re-applies WireGuard IPs and restarts `E2EGatewayApi::start()` (which depends on the cert file existing). Docker topologies must reproduce this state before feature assertions can use them.

## Task 0: Confirm Current E2E Baseline

**Files:**

- Inspect: `composer.json`
- Inspect: `TESTING.md`
- Inspect: `tests/E2E/Support/E2ETopologyFactory.php`
- Inspect: `tests/E2E/Support/E2ETopologyLease.php`
- Inspect: `app/Console/Commands/Internal/*`

- [x] Check current worktree and recent E2E commits.

Run:

```bash
git status --short --branch --untracked-files=all
git log --oneline --decorate -8
```

Expected:

- no unrelated active-agent files are edited;
- the current E2E baseline is clear before changing provider boundaries.

- [x] Confirm the landed E2E speed baseline.

The `2026-05-04-e2e-fast-suite-hardening.md` plan has already landed. Confirm the expected code signals before Task 1 starts:

- topology contract tests exist and run before feature assertions;
- `fresh-clone` reset fully rehydrates topology state;
- selective SSH setup exists;
- Incus host capacity and parallel feature script behavior exist and are documented;
- timing output exists and includes cleanup/reset phases.

Run:

```bash
rg -n "test:e2e:topology-contract|withSshUsers|fresh-clone|snapshot-restore|ORBIT_E2E_TIMINGS|features:parallel|INCUS_MAX" composer.json TESTING.md tests/E2E tests/Feature
```

Expected: every bullet above can be tied to current code. If this fails, stop and reconcile the baseline before introducing Docker topology providers.

- [x] Check which topology improvements already landed.

Run:

```bash
rg -n "withSshUsers|features:parallel|ORBIT_E2E_TIMINGS|snapshot-restore|fresh-clone|E2ETopologyProvider|Docker" composer.json TESTING.md tests/E2E tests/Feature docs/superpowers/plans
```

Expected:

- selective SSH setup, parallel feature scripts, timing, reset, and Incus capacity signals are present;
- no topology-provider abstraction exists yet, unless another worker has already started this Docker plan.

- [x] Run the existing safe E2E support tests.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/E2EProviderPoolTest.php tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyResetTest.php
```

Expected: all selected tests pass before refactoring.

- [x] Check whether a `.dockerignore` already exists.

Run:

```bash
ls -la .dockerignore docker/e2e/topology/Dockerfile.dockerignore docker/ 2>/dev/null
```

Expected:

- record whether a project root `.dockerignore` already exists (Task 6a may need to add or extend it);
- record whether a Dockerfile-specific ignore file already exists;
- record whether a `docker/` directory already houses other Dockerfiles.

- [x] Locate the local gateway bootstrap command that Docker topology baking will reuse.

Run:

```bash
BOOTSTRAP_FILE="$(rg -l "bootstrap-gateway-local" app/Console/Commands/Internal | head -n 1)"
test -n "$BOOTSTRAP_FILE"
sed -n '1,220p' "$BOOTSTRAP_FILE"
```

Expected:

- the command source is found by discovery rather than a hardcoded filename;
- the command exists before Docker builder work begins;
- any system-service or host-mutation assumptions are recorded for Task 6b.

Commit after this task only if it changed documentation:

```bash
git add docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md
git commit -m "docs: add docker e2e topology driver plan"
```

## Task 1: Split Topology Provider Config From Provisioning Provider Config

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Test: `tests/Feature/E2EConfigTest.php`
- Test: `tests/Feature/E2EProviderPoolTest.php`
- Create: `tests/Helpers/E2EEnvironment.php`
- Modify: `composer.json` (autoload-dev `files` for the helpers)
- Modify: `TESTING.md`

- [x] Add a failing config test for topology provider defaults.

Append to `tests/Feature/E2EConfigTest.php`:

```php
it('defaults topology providers to incus independently from provisioning providers', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus'])
            ->and($config->providerNames)->toBe(['incus']);
    });
});

it('expands topology provider auto to the safe vm-backed default', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'auto',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus']);
    });
});
```

Update the helper keys in `withE2EConfigEnvironment()`:

```php
$keys = [
    'ORBIT_E2E_CPUS',
    'ORBIT_E2E_MEMORY',
    'ORBIT_E2E_TOPOLOGY_CPUS',
    'ORBIT_E2E_TOPOLOGY_MEMORY',
    'ORBIT_E2E_PROVIDER',
    'ORBIT_E2E_PROVIDERS',
    'ORBIT_E2E_TOPOLOGY_PROVIDER',
    'ORBIT_E2E_TOPOLOGY_PROVIDERS',
];
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='defaults topology providers|topology provider auto'
```

Expected: fail because `E2EConfig::$topologyProviderNames` does not exist.

- [x] Add topology provider names to `E2EConfig`.

In `tests/E2E/Support/E2EConfig.php`, add the constructor property after `providerNames`:

```php
/** @var list<string> */
public array $topologyProviderNames,
```

In `fromEnvironment()`, add:

```php
topologyProviderNames: self::topologyProviderNames(),
```

In `forHost()`, pass through:

```php
topologyProviderNames: $this->topologyProviderNames,
```

Every new `E2EConfig` constructor property introduced by this plan must be threaded through `fromEnvironment()` and `forHost()` in the same commit. Add an assertion to the config tests that `$config->forHost('sidecar1')` preserves `topologyProviderNames`.

Add the parser:

```php
/**
 * @return list<string>
 */
private static function topologyProviderNames(): array
{
    $providers = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDERS', '');

    if ($providers !== '') {
        $names = self::parseProviderNames($providers);

        return in_array('auto', $names, true) ? ['incus'] : $names;
    }

    $provider = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDER', '');

    if ($provider !== '') {
        if ($provider === 'auto') {
            return ['incus'];
        }

        return self::parseProviderNames($provider);
    }

    return ['incus'];
}
```

`ORBIT_E2E_TOPOLOGY_PROVIDER=auto` and `ORBIT_E2E_TOPOLOGY_PROVIDERS=auto` intentionally expand to `['incus']` at this stage. Docker is not part of `auto` until Task 8 timing data proves it should be preferred for feature lanes; if Task 8 changes the recommended provider order, update this parser and its test in that same task.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='defaults topology providers|topology provider auto'
```

Expected: pass.

- [x] Add a failing config test for Docker topology provider selection.

Append:

```php
it('uses explicit topology providers without changing provisioning providers', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_PROVIDER' => 'hcloud',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS' => 'docker, incus',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->providerNames)->toBe(['hcloud'])
            ->and($config->topologyProviderNames)->toBe(['docker', 'incus']);
    });
});
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='explicit topology providers'
```

Expected: pass after Task 1 implementation.

- [x] Consolidate the E2E environment test helpers.

Today `withE2EConfigEnvironment()` (in `tests/Feature/E2EConfigTest.php`) and `withE2EProviderEnvironment()` (in `tests/Feature/E2EProviderPoolTest.php`) maintain duplicate key lists. With topology provider keys being added, the two will diverge. Move the shared logic into `tests/Helpers/E2EEnvironment.php`:

```php
<?php

declare(strict_types=1);

/**
 * @param  list<string>  $additionalKeys
 * @param  array<string, string>  $values
 */
function withE2EEnvironment(array $additionalKeys, array $values, Closure $callback): void
{
    $keys = array_values(array_unique(array_merge([
        'ORBIT_E2E_PROVIDER',
        'ORBIT_E2E_PROVIDERS',
        'ORBIT_E2E_TOPOLOGY_PROVIDER',
        'ORBIT_E2E_TOPOLOGY_PROVIDERS',
    ], $additionalKeys)));

    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key);
    }

    foreach ($values as $key => $value) {
        putenv("{$key}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($previous as $key => $value) {
            if (is_string($value)) {
                putenv("{$key}={$value}");

                continue;
            }

            putenv($key);
        }
    }
}
```

Define the delegate helpers in `tests/Helpers/E2EEnvironment.php` too:

```php
function withE2EConfigEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_CPUS',
        'ORBIT_E2E_MEMORY',
        'ORBIT_E2E_TOPOLOGY_CPUS',
        'ORBIT_E2E_TOPOLOGY_MEMORY',
    ], $values, $callback);
}

function withE2EProviderEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([], $values, $callback);
}

function withE2ETopologyEnvironment(array $values, Closure $callback): void
{
    withE2EEnvironment([
        'ORBIT_E2E_TOPOLOGY_STRATEGY',
        'ORBIT_E2E_INCUS_HOSTS',
        'ORBIT_E2E_HOST',
    ], $values, $callback);
}
```

Then delete the local helper function definitions from:

- `tests/Feature/E2EConfigTest.php`;
- `tests/Feature/E2EProviderPoolTest.php`;
- `tests/Feature/E2ETopologyFactoryTest.php`.

Do not leave thin local delegates in those test files. The helper file is autoloaded through Composer, so duplicate global function names will fatal with `Cannot redeclare ...`.

Verify no local helper definitions remain:

```bash
rg -n "function withE2E(Config|Provider|Topology)Environment" tests/Feature tests/Helpers
```

Expected: every match is in `tests/Helpers/E2EEnvironment.php`.

Add the helper file to `composer.json` autoload-dev `files`:

```json
"autoload-dev": {
    "psr-4": {
        "OrbitDocsLinter\\": "tool/docs-linter/src/",
        "Tests\\": "tests/"
    },
    "files": [
        "tests/Helpers/E2EEnvironment.php"
    ]
}
```

Run:

```bash
composer dump-autoload
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/E2EProviderPoolTest.php
```

Expected: all tests still pass with no env leakage.

- [x] Document the split in `TESTING.md`.

Add these environment lines near the existing E2E environment block:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDER=incus      # Prepared topology provider
ORBIT_E2E_TOPOLOGY_PROVIDERS=incus     # Ordered prepared topology provider pool
```

Add this paragraph:

```markdown
`ORBIT_E2E_PROVIDER` and `ORBIT_E2E_PROVIDERS` choose provisioning providers.
`ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS` choose
prepared topology providers for `e2e-feature` tests. Keep provisioning provider
selection VM-backed when a test proves machine setup, SSH, sudo, package
installation, trust-store mutation, or system services.

`ORBIT_E2E_TOPOLOGY_PROVIDER=auto` currently expands to `incus`, not Docker.
Docker can be selected explicitly with `ORBIT_E2E_TOPOLOGY_PROVIDER=docker`;
only add Docker to `auto` after measured timing data proves it should become
part of the default feature-lane pool.
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/E2EProviderPoolTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Expected: tests and docs lint pass.

Commit:

```bash
git add composer.json tests/E2E/Support/E2EConfig.php tests/Feature/E2EConfigTest.php tests/Feature/E2EProviderPoolTest.php tests/Helpers/E2EEnvironment.php TESTING.md
git commit -m "test: split e2e topology provider config"
```

## Task 2: Introduce Prepared Topology Provider Boundary With Capability Requirements

**Files:**

- Create: `tests/E2E/Support/E2ETopologyCapabilities.php`
- Create: `tests/E2E/Support/E2ETopologyAcquisitionOptions.php`
- Create: `tests/E2E/Support/E2ETopologyProvider.php`
- Create: `tests/E2E/Support/E2ETopologyProviderSelection.php`
- Create: `tests/E2E/Support/E2ETopologyProviderPool.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

- [x] Create a failing provider-pool test.

Create `tests/Feature/E2ETopologyProviderPoolTest.php`:

```php
<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EPhaseTimer;
use Tests\E2E\Support\E2ETopologyAcquisitionOptions;
use Tests\E2E\Support\E2ETopologyCapabilities;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\E2ETopologyLease;
use Tests\E2E\Support\E2ETopologyProvider;
use Tests\E2E\Support\E2ETopologyProviderPool;
use Tests\E2E\Support\ProviderAvailability;

it('selects the first topology provider with the requested kind available', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', false),
        fakeTopologyProvider('incus', true),
    ]);

    $selection = $pool->select(E2ETopologyKind::ControlGateway);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus')
        ->and($selection->message)->toBe('incus: ready');
});

it('reports topology provider failures when none are available', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', false),
        fakeTopologyProvider('incus', false),
    ]);

    $selection = $pool->select(E2ETopologyKind::Control);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('docker: unavailable')
        ->and($selection->message)->toContain('incus: unavailable');
});

it('skips topology providers that lack required capabilities', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
        fakeTopologyProvider('incus', true, E2ETopologyCapabilities::vm()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: true,
        systemd: false,
        hostMutation: false,
        kernelNetworking: false,
    );

    $selection = $pool->select(E2ETopologyKind::Control, $required);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus')
        ->and($selection->message)->toContain('incus:');
});

it('reports capability mismatch when no provider satisfies the requirement', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: true,
        systemd: true,
        hostMutation: true,
        kernelNetworking: true,
    );

    $selection = $pool->select(E2ETopologyKind::Control, $required);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('docker: capabilities do not satisfy required');
});

it('treats every requested capability flag independently', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
        fakeTopologyProvider('incus', true, E2ETopologyCapabilities::vm()),
    ]);

    $required = new E2ETopologyCapabilities(
        realSsh: false,
        systemd: true,
        hostMutation: false,
        kernelNetworking: false,
    );

    $selection = $pool->select(E2ETopologyKind::Control, $required);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus');
});

function fakeTopologyProvider(string $name, bool $available, ?E2ETopologyCapabilities $capabilities = null): E2ETopologyProvider
{
    return new class($name, $available, $capabilities ?? E2ETopologyCapabilities::vm()) implements E2ETopologyProvider
    {
        public function __construct(
            private readonly string $name,
            private readonly bool $available,
            private readonly E2ETopologyCapabilities $capabilities,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function capabilities(): E2ETopologyCapabilities
        {
            return $this->capabilities;
        }

        public function availability(E2ETopologyKind $kind): ProviderAvailability
        {
            return $this->available
                ? ProviderAvailability::available('ready')
                : ProviderAvailability::unavailable('unavailable');
        }

        public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
        {
            throw new RuntimeException('Fake topology provider cannot acquire leases.');
        }
    };
}
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyProviderPoolTest.php
```

Expected: fail because the topology provider classes do not exist.

- [x] Add topology capabilities with a `satisfies()` check.

Create `tests/E2E/Support/E2ETopologyCapabilities.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyCapabilities
{
    /**
     * hostMutation means mutation outside the disposable test environment: the developer machine,
     * a real VM host, or a real cloud resource. Container-internal mutations during Docker
     * seeding are prepared topology state, not host mutation.
     */
    public function __construct(
        public bool $realSsh,
        public bool $systemd,
        public bool $hostMutation,
        public bool $kernelNetworking,
    ) {}

    public static function vm(): self
    {
        return new self(
            realSsh: true,
            systemd: true,
            hostMutation: true,
            kernelNetworking: true,
        );
    }

    public static function containerFeature(): self
    {
        return new self(
            realSsh: false,
            systemd: false,
            hostMutation: false,
            kernelNetworking: false,
        );
    }

    /**
     * Returns true when this capability set meets or exceeds every flag set on $required.
     * Flags that $required leaves false are not enforced.
     */
    public function satisfies(self $required): bool
    {
        return (! $required->realSsh || $this->realSsh)
            && (! $required->systemd || $this->systemd)
            && (! $required->hostMutation || $this->hostMutation)
            && (! $required->kernelNetworking || $this->kernelNetworking);
    }
}
```

- [x] Add topology acquisition options.

Create `tests/E2E/Support/E2ETopologyAcquisitionOptions.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyAcquisitionOptions
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    public function __construct(
        public ?array $sshUsers = null,
    ) {}
}
```

- [x] Add topology provider interface.

Create `tests/E2E/Support/E2ETopologyProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

interface E2ETopologyProvider
{
    public function name(): string;

    public function capabilities(): E2ETopologyCapabilities;

    public function availability(E2ETopologyKind $kind): ProviderAvailability;

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease;
}
```

- [x] Add provider selection.

Create `tests/E2E/Support/E2ETopologyProviderSelection.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyProviderSelection
{
    public function __construct(
        public ?E2ETopologyProvider $provider,
        public string $message,
    ) {}

    public function available(): bool
    {
        return $this->provider !== null;
    }

    public function provider(): E2ETopologyProvider
    {
        return $this->provider ?? throw new \RuntimeException($this->message);
    }
}
```

- [x] Add provider pool with capability-aware selection.

Create `tests/E2E/Support/E2ETopologyProviderPool.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyProviderPool
{
    /**
     * @param  list<E2ETopologyProvider>  $providers
     */
    public function __construct(
        private array $providers,
    ) {}

    public function select(E2ETopologyKind $kind, ?E2ETopologyCapabilities $required = null): E2ETopologyProviderSelection
    {
        $failures = [];

        foreach ($this->providers as $provider) {
            if ($required !== null && ! $provider->capabilities()->satisfies($required)) {
                $failures[] = "{$provider->name()}: capabilities do not satisfy required";

                continue;
            }

            $availability = $provider->availability($kind);

            if ($availability->available) {
                return new E2ETopologyProviderSelection($provider, "{$provider->name()}: {$availability->message}");
            }

            $failures[] = "{$provider->name()}: {$availability->message}";
        }

        return new E2ETopologyProviderSelection(null, implode('; ', $failures));
    }
}
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyProviderPoolTest.php
vendor/bin/pint --dirty --format agent
```

Expected: provider-pool tests pass.

Commit:

```bash
git add tests/E2E/Support/E2ETopologyCapabilities.php tests/E2E/Support/E2ETopologyAcquisitionOptions.php tests/E2E/Support/E2ETopologyProvider.php tests/E2E/Support/E2ETopologyProviderSelection.php tests/E2E/Support/E2ETopologyProviderPool.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "test: add prepared topology provider boundary"
```

## Task 3: Move Incus Prepared Topology Acquisition Behind The Boundary

**Files:**

- Create: `tests/E2E/Support/IncusTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyProviderPool.php`
- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

- [x] Add a failing factory test for topology provider selection.

Append to `tests/Feature/E2ETopologyFactoryTest.php`:

```php
it('skips with topology provider failure details when no prepared provider is available', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'incus',
        'ORBIT_E2E_INCUS_HOSTS' => 'orbit-e2e-nonexistent.invalid',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment();

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(SkippedWithMessageException::class, 'Prepared topology not available');
    });
});

it('refuses providers that do not satisfy required capabilities', function (): void {
    withE2ETopologyEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
    ], function (): void {
        $factory = E2ETopologyFactory::fromEnvironment()
            ->requireCapabilities(new E2ETopologyCapabilities(
                realSsh: true,
                systemd: false,
                hostMutation: false,
                kernelNetworking: false,
            ));

        expect(fn () => $factory->require(E2ETopologyKind::Control))
            ->toThrow(SkippedWithMessageException::class, 'capabilities do not satisfy required');
    });
});
```

Ensure `withE2ETopologyEnvironment()` from `tests/Helpers/E2EEnvironment.php` includes `ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS`.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php --filter='topology provider failure details|capabilities do not satisfy'
```

Expected: fail until the factory reads topology providers and exposes capability requirements.

- [x] Create `IncusTopologyProvider`.

Use the current `E2ETopologyFactory` source as the anchor for this extraction. Do not re-implement Incus acquisition from the prose in this plan; move the current behavior behind the provider boundary and keep behavior equivalent.

Before editing, read the exact source being extracted:

```bash
sed -n '42,260p' tests/E2E/Support/E2ETopologyFactory.php
rg -n "E2EControlIdentity::ensure|snapshot\\('lease-clean'\\)|restoreSnapshot\\('lease-clean'\\)|waitForAgent|IncusTopologyTemplate::clone" tests/E2E/Support/E2ETopologyFactory.php tests/E2E/Support/IncusTopologyTemplate.php tests/E2E/Support/IncusInstance.php
```

Expected: the extraction keeps every behavior represented by those matches.

Move the Incus-specific acquisition body from `E2ETopologyFactory::require()` into `tests/E2E/Support/IncusTopologyProvider.php`. The provider exposes:

```php
public function name(): string
{
    return 'incus';
}

public function capabilities(): E2ETopologyCapabilities
{
    return E2ETopologyCapabilities::vm();
}

public function availability(E2ETopologyKind $kind): ProviderAvailability
{
    $host = IncusHostPool::fromEnvironment($this->config)->firstAvailableFor($kind);

    if ($host === null) {
        return ProviderAvailability::unavailable("prepared topology {$kind->value} is not available on any Incus host");
    }

    return ProviderAvailability::available("prepared topology {$kind->value} is available");
}
```

The `acquire()` method must accept `E2ETopologyAcquisitionOptions $options`. Verbatim move the body of `E2ETopologyFactory::require()`'s Incus try-block and the Incus helper methods it depends on into the provider. Preserve:

- `IncusTopologyTemplate::clone(...)` for initial acquire and rebuild;
- `createSshKeyPair(...)`;
- `prepareInstances(...)`, including SSH authorization/readiness, `E2EControlIdentity::ensure(...)`, one `snapshot('lease-clean')` per role, and WireGuard rehydration;
- `sshUsersFor(...)`, but read the override from `$options->sshUsers` instead of `$this->sshUsers`;
- `snapshotResetFor(...)`, including `stop()`, `restoreSnapshot('lease-clean')`, `start()`, `waitForAgent()`, WireGuard rehydration, and selected-user SSH readiness;
- `reestablishWireGuard(...)`, including `E2EGatewayApi::start(...)`;
- the existing `E2ETopologyLease` rebuild + snapshotReset closure behavior.

`IncusTopologyTemplate` stays in `tests/E2E/Support/IncusTopologyTemplate.php`; only orchestration moves into `IncusTopologyProvider`.

Keep Incus internals concrete, then widen only at the lease boundary. `E2EInstance` does not define `snapshot()`, `stop()`, `restoreSnapshot()`, `start()`, or `waitForAgent()`, so `IncusTopologyProvider` helper PHPDocs should use `array<string, IncusInstance>` where those methods are called. When constructing `E2ETopologyLease`, pass those `IncusInstance` values as `E2EInstance` handles through the constructor; the provider's public return type stays `E2ETopologyLease`.

Note: `withSshUsers()` stays on `E2ETopologyFactory`. The factory passes the resolved user map through `E2ETopologyAcquisitionOptions`; providers that do not need host-to-guest SSH may ignore it, but must still accept the options object so the interface stays stable.

- [x] Add topology provider factory methods.

Update `tests/E2E/Support/E2ETopologyProviderPool.php`:

```php
public static function fromEnvironment(?E2EConfig $config = null): self
{
    $config ??= E2EConfig::fromEnvironment();

    return new self(array_map(
        fn (string $provider): E2ETopologyProvider => self::makeProvider($provider, $config),
        $config->topologyProviderNames,
    ));
}

private static function makeProvider(string $provider, E2EConfig $config): E2ETopologyProvider
{
    return match ($provider) {
        'incus' => new IncusTopologyProvider($config),
        default => throw new \InvalidArgumentException("Unknown E2E topology provider [{$provider}]."),
    };
}
```

- [x] Slim `E2ETopologyFactory` and remove the dead `$provider` field.

`E2ETopologyFactory` currently stores `$provider` and `$strategy` from `getenv()` and uses `$provider` only to early-skip non-Incus runs. After this task the topology provider lookup happens through the pool, so:

- delete the `$provider` constructor property;
- keep `$strategy` (still used by `resolveKind()`);
- add `$capabilityRequirements` for the new `requireCapabilities()` method;
- add `$sshUsers` if it is not already present.

```php
final class E2ETopologyFactory
{
    /**
     * @param  array<string, string>|null  $sshUsers
     */
    private function __construct(
        private readonly string $strategy,
        private readonly ?array $sshUsers = null,
        private readonly ?E2ETopologyCapabilities $capabilityRequirements = null,
    ) {}

    public static function fromEnvironment(): self
    {
        $strategy = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

        return new self(
            strategy: is_string($strategy) && $strategy !== '' ? $strategy : 'minimal',
        );
    }

    public function requireCapabilities(E2ETopologyCapabilities $required): self
    {
        return new self(
            strategy: $this->strategy,
            sshUsers: $this->sshUsers,
            capabilityRequirements: $required,
        );
    }

    /**
     * @param  array<string, string>  $sshUsers
     */
    public function withSshUsers(array $sshUsers): self
    {
        return new self(
            strategy: $this->strategy,
            sshUsers: $sshUsers,
            capabilityRequirements: $this->capabilityRequirements,
        );
    }

    public function require(E2ETopologyKind $kind): E2ETopologyLease
    {
        $resolved = $this->resolveKind($kind);
        $timer = new E2EPhaseTimer;

        try {
            $config = E2EConfig::fromEnvironment();
            $selection = E2ETopologyProviderPool::fromEnvironment($config)->select($resolved, $this->capabilityRequirements);

            if (! $selection->available()) {
                test()->markTestSkipped('Prepared topology not available: '.$selection->message);
            }

            return $selection->provider()->acquire(
                $resolved,
                E2ERun::id(),
                $timer,
                new E2ETopologyAcquisitionOptions(sshUsers: $this->sshUsers),
            );
        } finally {
            $timer->flush('acquire');
        }
    }
}
```

Update existing tests in `tests/Feature/E2ETopologyFactoryTest.php` that previously asserted on `$provider` to assert on `$strategy` only.

- [x] Run focused support tests.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyProviderPoolTest.php tests/Feature/E2ETopologyResetTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests pass and existing Incus behavior is unchanged.

Commit:

```bash
git add tests/E2E/Support/IncusTopologyProvider.php tests/E2E/Support/E2ETopologyProviderPool.php tests/E2E/Support/E2ETopologyFactory.php tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "test: route prepared topologies through provider pool"
```

## Task 4: Add Docker Process Primitives

**Files:**

- Create: `tests/E2E/Support/DockerHost.php`
- Create: `tests/E2E/Support/DockerInstance.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`

> Note on `Process::fake()` patterns: Laravel's matcher uses fnmatch wildcards, and `escapeshellarg('foo')` produces `'foo'` (with single quotes around it). The fake key has to match the literal output including the quotes. The patterns below quote container names accordingly.

- [x] Add a failing test for Docker instance command construction.

Create `tests/Feature/DockerTopologyProviderTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Tests\E2E\Support\DockerHost;
use Tests\E2E\Support\DockerInstance;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\SshKeyPair;

it('runs docker exec for instance commands', function (): void {
    Process::fake([
        "docker exec 'orbit-e2e-run-control' sh -lc *" => Process::result(output: "ok\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->exec('echo ok');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("ok\n");
});

it('maps ssh transport to user-scoped docker exec for container feature topologies', function (): void {
    Process::fake([
        "docker exec --user 'control' 'orbit-e2e-run-control' sh -lc *" => Process::result(output: "control\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->ssh('control', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("control\n");
});

it('reads ipv4 from the named docker network only', function (): void {
    Process::fake([
        "docker inspect -f '{{(index .NetworkSettings.Networks \"orbit-e2e-run\").IPAddress}}' 'orbit-e2e-run-control'" => Process::result(output: "10.6.0.3\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control', 'orbit-e2e-run');

    expect($instance->waitForIpv4())->toBe('10.6.0.3');
});
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php
```

Expected: fail because Docker classes do not exist.

- [x] Add `DockerHost`.

Create `tests/E2E/Support/DockerHost.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

final readonly class DockerHost
{
    public function __construct(
        public E2EConfig $config,
    ) {}

    public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)->run($command);
    }

    public function mustRun(string $command, string $errorContext, ?int $timeoutSeconds = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds);

        if (! $result->successful()) {
            throw new \RuntimeException("{$errorContext}: ".trim($result->errorOutput().' '.$result->output()));
        }

        return $result;
    }
}
```

- [x] Add `DockerInstance`.

Create `tests/E2E/Support/DockerInstance.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;

final class DockerInstance implements E2EInstance
{
    private ?string $ipv4 = null;

    public function __construct(
        private readonly DockerHost $host,
        private readonly string $name,
        private readonly ?string $networkName = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(sprintf(
            'docker exec %s sh -lc %s',
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
    {
        return $this->host->run(sprintf(
            'docker exec --user %s %s sh -lc %s',
            escapeshellarg($user),
            escapeshellarg($this->name),
            escapeshellarg($command),
        ), $timeoutSeconds);
    }

    public function authorizeSsh(string $user, SshKeyPair $keyPair): void
    {
        $result = $this->exec(sprintf(
            'test -d %s',
            escapeshellarg("/home/{$user}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Docker feature topology user [{$user}] does not exist in {$this->name}.");
        }
    }

    public function copyFileToInstance(string $sourcePath, string $targetPath): void
    {
        $result = $this->host->run(sprintf(
            'docker cp %s %s',
            escapeshellarg($sourcePath),
            escapeshellarg("{$this->name}:{$targetPath}"),
        ));

        if (! $result->successful()) {
            throw new \RuntimeException("Could not copy {$sourcePath} into {$this->name}: {$result->errorOutput()}");
        }
    }

    public function waitForIpv4(): string
    {
        if ($this->ipv4 !== null) {
            return $this->ipv4;
        }

        $template = $this->networkName === null
            ? '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}'
            : sprintf('{{(index .NetworkSettings.Networks %s).IPAddress}}', json_encode($this->networkName, JSON_THROW_ON_ERROR));

        $result = $this->host->run(sprintf(
            'docker inspect -f %s %s',
            escapeshellarg($template),
            escapeshellarg($this->name),
        ));

        if (! $result->successful() || trim($result->output()) === '') {
            throw new \RuntimeException("Docker container {$this->name} does not have an IPv4 address.");
        }

        return $this->ipv4 = trim($result->output());
    }

    public function waitForSsh(string $user, SshKeyPair $keyPair): void
    {
        $result = $this->ssh($user, $keyPair, 'test "$(uname -s)" = Linux && test -r /etc/os-release', timeoutSeconds: 10);

        if (! $result->successful()) {
            throw new \RuntimeException("Docker command transport is not ready for {$user}@{$this->name}.");
        }
    }

    public function delete(): void
    {
        $this->host->run(sprintf(
            'docker rm -f %s >/dev/null 2>&1 || true',
            escapeshellarg($this->name),
        ), timeoutSeconds: 120);
    }
}
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php
vendor/bin/pint --dirty --format agent
```

Expected: Docker primitive tests pass.

Commit:

```bash
git add tests/E2E/Support/DockerHost.php tests/E2E/Support/DockerInstance.php tests/Feature/DockerTopologyProviderTest.php
git commit -m "test: add docker e2e instance primitives"
```

## Task 5: Add Docker Topology Provider MVP

**Files:**

- Create: `tests/E2E/Support/DockerTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyProviderPool.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

> Note: this MVP only wires availability checks and capability advertising. The real `acquire()` lands in Task 6c after the prepared per-role images exist.

- [x] Add failing provider availability tests.

Append to `tests/Feature/DockerTopologyProviderTest.php`:

```php
use Tests\E2E\Support\DockerTopologyProvider;
use Tests\E2E\Support\E2ETopologyCapabilities;
use Tests\E2E\Support\E2ETopologyKind;

it('reports docker unavailable when docker is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse();
});

it('reports docker unavailable when prepared per-role image is missing', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-control-current' >/dev/null" => Process::result(exitCode: 1),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->availability(E2ETopologyKind::Control)->available)->toBeFalse()
        ->and($provider->availability(E2ETopologyKind::Control)->message)->toContain('orbit-e2e-topology:control-control-current');
});

it('advertises container feature capabilities', function (): void {
    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect($provider->capabilities())->toEqual(E2ETopologyCapabilities::containerFeature());
});
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php --filter='docker unavailable|container feature capabilities'
```

Expected: fail because `DockerTopologyProvider` does not exist.

- [x] Add `DockerTopologyProvider`.

Create `tests/E2E/Support/DockerTopologyProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class DockerTopologyProvider implements E2ETopologyProvider
{
    public function __construct(
        private E2EConfig $config,
    ) {}

    public function name(): string
    {
        return 'docker';
    }

    public function capabilities(): E2ETopologyCapabilities
    {
        return E2ETopologyCapabilities::containerFeature();
    }

    public function availability(E2ETopologyKind $kind): ProviderAvailability
    {
        $host = new DockerHost($this->config);

        if (! $host->run('command -v docker >/dev/null', timeoutSeconds: 10)->successful()) {
            return ProviderAvailability::unavailable('docker command is not available');
        }

        if (! $host->run('docker info >/dev/null', timeoutSeconds: 10)->successful()) {
            return ProviderAvailability::unavailable('docker daemon is not reachable');
        }

        foreach ($this->rolesFor($kind) as $role) {
            $image = $this->imageNameFor($kind, $role);

            if (! $host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: 10)->successful()) {
                return ProviderAvailability::unavailable("docker prepared image {$image} is not available");
            }
        }

        return ProviderAvailability::available("docker prepared topology {$kind->value} is available");
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
    {
        throw new \RuntimeException('Docker topology acquisition lands in Task 6c.');
    }

    public function imageNameFor(E2ETopologyKind $kind, string $role): string
    {
        return "orbit-e2e-topology:{$kind->value}-{$role}-current";
    }

    /**
     * @return list<string>
     */
    public function rolesFor(E2ETopologyKind $kind): array
    {
        return match ($kind) {
            E2ETopologyKind::Control => ['control'],
            E2ETopologyKind::ControlGateway => ['control', 'gateway'],
            E2ETopologyKind::ControlGatewayDev => ['control', 'gateway', 'dev'],
            E2ETopologyKind::ControlGatewayDevProd => ['control', 'gateway', 'dev', 'prod'],
        };
    }
}
```

Image tags are intentionally stable daemon-level resources and do not include `instancePrefix`. Runtime containers, build containers, and Docker networks are per-run resources and must include `instancePrefix` to avoid collisions in shared development or CI environments.

- [x] Register Docker in the topology provider pool.

Update `makeProvider()` in `tests/E2E/Support/E2ETopologyProviderPool.php`:

```php
return match ($provider) {
    'incus' => new IncusTopologyProvider($config),
    'docker' => new DockerTopologyProvider($config),
    default => throw new \InvalidArgumentException("Unknown E2E topology provider [{$provider}]."),
};
```

- [x] Add provider-pool coverage for Docker config.

Append to `tests/Feature/E2ETopologyProviderPoolTest.php`:

```php
it('can create a docker topology provider from environment config', function (): void {
    withE2EProviderEnvironment([
        'ORBIT_E2E_TOPOLOGY_PROVIDER' => 'docker',
    ], function (): void {
        $pool = E2ETopologyProviderPool::fromEnvironment();
        $selection = $pool->select(E2ETopologyKind::Control);

        expect($selection->message)->toContain('docker:');
    });
});
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyProviderPoolTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests pass, with Docker unavailable in environments without prepared per-role images.

Commit:

```bash
git add tests/E2E/Support/DockerTopologyProvider.php tests/E2E/Support/E2ETopologyProviderPool.php tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "test: register docker topology provider"
```

## Task 6a: Build Bare Docker Runtime Image

**Files:**

- Create: `docker/e2e/topology/Dockerfile`
- Create: `docker/e2e/topology/Dockerfile.dockerignore`
- Create: `app/Console/Commands/E2EPrepareDockerRuntimeCommand.php`
- Test: `tests/Feature/Commands/E2EPrepareDockerRuntimeCommandTest.php`

This task only builds a bare runtime image: PHP, Composer dependencies, the Orbit source, and the `control` and `orbit` users. It does not seed Node rows, certificates, or run `gateway:add` / `node:new` — that lands in Task 6b on top of this image.

- [x] Add a failing command test for runtime image preparation dry-run.

Create `tests/Feature/Commands/E2EPrepareDockerRuntimeCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('documents docker runtime image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-runtime')
        ->expectsOutputToContain('orbit-e2e-topology-runtime:current')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EPrepareDockerRuntimeCommandTest.php
```

Expected: fail because the command does not exist.

- [x] Add a focused Dockerfile-specific ignore file.

Create `docker/e2e/topology/Dockerfile.dockerignore`. The build context defaults to project root, which on a normal Orbit checkout includes `node_modules/`, host `vendor/`, `.git/`, the SQLite DB, secrets in `.env`, and the `tests/` tree. Docker reads a Dockerfile-specific ignore file from the Dockerfile directory when it is named `Dockerfile.dockerignore`; a plain `.dockerignore` in `docker/e2e/topology/` would be ignored because the context root is `base_path()`.

This requires BuildKit, which is the default in modern Docker. If `DOCKER_BUILDKIT=0` is set, the Dockerfile-specific ignore file may be ignored and the build context will be much larger.

```
.git
.idea
.vscode
node_modules
vendor
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
database/database.sqlite*
*.log
.env
.env.*
!.env.example
docs/superpowers/plans/solo-orchestration
.agents
docker
tests/E2E
Dockerfile*
```

- [x] Add the runtime Dockerfile.

Create `docker/e2e/topology/Dockerfile`:

```dockerfile
FROM php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        git \
        iproute2 \
        libonig-dev \
        libzip-dev \
        sqlite3 \
        sudo \
        unzip \
    && docker-php-ext-install mbstring pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN useradd -m -s /bin/bash control \
    && useradd -m -s /bin/bash orbit \
    && echo 'control ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/control \
    && echo 'orbit ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/orbit \
    && chmod 440 /etc/sudoers.d/control /etc/sudoers.d/orbit

WORKDIR /opt/orbit-source
COPY . /opt/orbit-source

RUN install -d -o control -g control /home/control/orbit \
    && install -d -o orbit -g orbit /home/orbit/orbit \
    && cp -a /opt/orbit-source/. /home/control/orbit/ \
    && cp -a /opt/orbit-source/. /home/orbit/orbit/ \
    && chown -R control:control /home/control/orbit \
    && chown -R orbit:orbit /home/orbit/orbit \
    && sudo -iu control bash -lc 'cd /home/control/orbit && composer install --no-interaction --no-progress --no-dev' \
    && sudo -iu orbit bash -lc 'cd /home/orbit/orbit && composer install --no-interaction --no-progress --no-dev'

CMD ["sleep", "infinity"]
```

Note: `--no-dev` keeps the runtime small. The Tests namespace is autoload-dev so Pest helpers won't be available inside containers — that's fine because containers are seeded by orchestration from outside, not by Pest from within. Before leaving Task 6a, verify that the runtime image can run normal app commands without dev dependencies:

```bash
composer e2e:prepare-docker-runtime -- --force
docker run --rm orbit-e2e-topology-runtime:current sh -lc 'cd /home/control/orbit && php artisan --version && php artisan list --raw >/dev/null'
```

Expected: both commands succeed. If runtime commands need a dev-only package, move that package to `require` before continuing instead of switching the image to `composer install` with dev dependencies.

- [x] Add the prepare-runtime command.

Create `app/Console/Commands/E2EPrepareDockerRuntimeCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'e2e:prepare-docker-runtime')]
class E2EPrepareDockerRuntimeCommand extends Command
{
    protected $signature = 'e2e:prepare-docker-runtime {--force : Build the Docker runtime image}';

    protected $description = 'Prepare the bare Docker runtime image used by the Docker prepared topology pipeline';

    public function handle(): int
    {
        $image = 'orbit-e2e-topology-runtime:current';

        $this->line("Docker runtime image: {$image}");

        if (! $this->option('force')) {
            $this->line('Dry run. Pass --force to build the Docker runtime image.');

            return self::SUCCESS;
        }

        $result = Process::timeout(1800)->run(sprintf(
            'docker build -f %s -t %s %s',
            escapeshellarg(base_path('docker/e2e/topology/Dockerfile')),
            escapeshellarg($image),
            escapeshellarg(base_path()),
        ));

        if (! $result->successful()) {
            $this->error($result->output().$result->errorOutput());

            return self::FAILURE;
        }

        $this->info("Built {$image}.");

        return self::SUCCESS;
    }
}
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EPrepareDockerRuntimeCommandTest.php
vendor/bin/pint --dirty --format agent
```

Expected: dry-run test passes.

Commit:

```bash
git add docker/e2e/topology/Dockerfile docker/e2e/topology/Dockerfile.dockerignore app/Console/Commands/E2EPrepareDockerRuntimeCommand.php tests/Feature/Commands/E2EPrepareDockerRuntimeCommandTest.php
git commit -m "test: add docker runtime image scaffolding"
```

## Task 6b: Build Prepared Per-Role Docker Images

**Files:**

- Create: `app/Services/Nodes/NodeRegistryWriter.php`
- Modify: `app/Console/Commands/NodeNewCommand.php` to delegate row writes to the new service
- Create: `app/Console/Commands/Internal/BakeAppNodeCommand.php`
- Test: `tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php`
- Create: `tests/E2E/Support/DockerTopologyBuilder.php`
- Create: `app/Console/Commands/E2EPrepareDockerTopologyCommand.php`
- Test: `tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php`
- Test: `tests/Feature/DockerTopologyBuilderTest.php`

This task mirrors the prepared topology state that `IncusTopologyBuilder::provisionInstances()` produces, but not its exact mechanics. Incus has a two-stage setup: gateway image preparation bakes gateway-local CA state, then topology preparation runs `gateway:add` and `node:new`. Docker has no separate gateway-image bake stage, so Docker collapses both stages into this one topology bake: it creates gateway-local CA state inside the transient gateway build container, then commits the seeded role containers into per-role per-topology images. Per-test acquire (Task 6c) launches from those images and skips the seeding work.

Each role has its own checkout and SQLite database:

| Role | Checkout | Runtime user | SQLite responsibility |
| --- | --- | --- | --- |
| control | `/home/control/orbit` | `control` | local control identity, `gateway:add` settings and registry rows |
| gateway | `/home/orbit/orbit` | `orbit` | local gateway identity, CA files, control row, app rows exposed by the gateway API |
| dev/prod | `/home/orbit/orbit` | `orbit` | app-node local runtime state only |

Run every command from the checkout listed above. `gateway:add` runs from control and writes to control SQLite. `bootstrap-gateway-local`, `E2EGatewayApi::seedControlIdentity()`, `E2EGatewayApi::start()`, and `orbit:internal:bake-app-node` run against the gateway checkout and write to gateway SQLite. This split is intentional and matches the VM topology model.

The bake target image names mirror Incus's template names:

| Incus template name                                | Docker image tag                                        |
| --- | --- |
| `orbit-template-control-control`                   | `orbit-e2e-topology:control-control-current`            |
| `orbit-template-control-gateway-control`           | `orbit-e2e-topology:control-gateway-control-current`    |
| `orbit-template-control-gateway-gateway`           | `orbit-e2e-topology:control-gateway-gateway-current`    |
| `orbit-template-control-gateway-dev-prod-control`  | `orbit-e2e-topology:control-gateway-dev-prod-control-current` |
| ... | ... |

- [x] Verify container compatibility of gateway bootstrap before building.

Read the command that Docker will run inside the gateway container:

```bash
BOOTSTRAP_FILE="$(rg -l "bootstrap-gateway-local" app/Console/Commands/Internal | head -n 1)"
test -n "$BOOTSTRAP_FILE"
sed -n '1,220p' "$BOOTSTRAP_FILE"
rg -n "systemctl|service .* start|caddy|requireTty|sudo -S" app/Console/Commands/Internal app/Services/Ca app/Models
php -r '$c=json_decode(file_get_contents("composer.json"), true); echo "require\n"; foreach($c["require"] as $k=>$v){echo "$k $v\n";} echo "require-dev\n"; foreach($c["require-dev"] as $k=>$v){echo "$k $v\n";}'
```

Expected:

- `orbit:internal:bootstrap-gateway-local` requires `name` and `wireguard-address` arguments and only touches the local database and CA files;
- no systemd, Caddy, TTY-only sudo, or host-service assumptions are present;
- packages needed by app commands live in `require`, not only `require-dev`.

If any assumption fails, update this plan before writing `DockerTopologyBuilder`.

- [x] Add a failing command test for topology image preparation dry-run.

Create `tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('documents docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'control-gateway-dev-prod'])
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-control-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-gateway-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-dev-current')
        ->expectsOutputToContain('orbit-e2e-topology:control-gateway-dev-prod-prod-current')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});

it('rejects unknown kind', function (): void {
    $this->artisan('e2e:prepare-docker-topology', ['kind' => 'invalid'])
        ->expectsOutputToContain('Invalid topology kind')
        ->assertFailed();
});
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
```

Expected: fail because the command does not exist.

- [x] Add hidden `orbit:internal:bake-app-node` command and shared row-writer service.

Extract `NodeNewCommand`'s app-row write logic into `App\Services\Nodes\NodeRegistryWriter` (matching the existing `App\Services\Ca\OrbitCaService` location convention). Both `NodeNewCommand` and the new bake command call into it, so the Eloquent shape stays consistent. No SSH, no installer.

Use a small service method, not a static helper:

```php
<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;

final class NodeRegistryWriter
{
    public function writeAppNode(
        string $name,
        string $environment,
        ?string $tld,
        string $host,
        string $wireguardAddress,
        ?string $gatewayEndpoint,
        string $sshUser,
        string $user,
    ): Node {
        return Node::query()->updateOrCreate(
            ['name' => $name],
            [
                'role' => 'app',
                'environment' => $environment,
                'tld' => $tld,
                'platform' => 'unknown',
                'host' => $host,
                'wireguard_address' => $wireguardAddress,
                'gateway_endpoint' => $gatewayEndpoint,
                'ssh_user' => $sshUser,
                'user' => $user,
                'orbit_path' => "/home/{$user}/orbit",
                'status' => 'active',
                'is_local' => false,
            ],
        );
    }
}
```

`NodeNewCommand` still owns validation, installer calls, `nextWireguardAddress()`, and `gatewayEndpoint()` lookup; it passes those resolved values into `NodeRegistryWriter`. The bake command skips installer work and receives enough options to call the same writer directly.

Add `app/Console/Commands/Internal/BakeAppNodeCommand.php`, mirroring the existing `Internal/` command shape. Mark it `protected $hidden = true;` so it stays out of `php artisan list` for normal users. Signature:

```
orbit:internal:bake-app-node {name}
    --role=app
    --environment=development|production
    --host=10.6.0.4
    --wireguard-address=10.6.0.4
    --gateway-endpoint=10.6.0.2
    --ssh-user=orbit
    --user=orbit
    {--tld=}
```

The command parses options, hands them to `NodeRegistryWriter`, and exits. It is upsert-safe so the Docker builder can call it during reruns of `e2e:prepare-docker-topology --force` without exploding.

Add `tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Node;

it('writes an app node row with the same shape as node:new produces', function (): void {
    $this->artisan('orbit:internal:bake-app-node', [
        'name' => 'app-dev-1',
        '--role' => 'app',
        '--environment' => 'development',
        '--host' => '10.6.0.4',
        '--wireguard-address' => '10.6.0.4',
        '--gateway-endpoint' => '10.6.0.2',
        '--ssh-user' => 'orbit',
        '--user' => 'orbit',
        '--tld' => 'test',
    ])->assertSuccessful();

    $node = Node::query()->where('name', 'app-dev-1')->firstOrFail();

    expect($node->role)->toBe('app')
        ->and($node->environment)->toBe('development')
        ->and($node->host)->toBe('10.6.0.4')
        ->and($node->wireguard_address)->toBe('10.6.0.4')
        ->and($node->gateway_endpoint)->toBe('10.6.0.2')
        ->and($node->ssh_user)->toBe('orbit')
        ->and($node->user)->toBe('orbit')
        ->and($node->tld)->toBe('test');
});

it('is idempotent across repeated runs', function (): void {
    $args = [
        'name' => 'app-prod-1',
        '--role' => 'app',
        '--environment' => 'production',
        '--host' => '10.6.0.5',
        '--wireguard-address' => '10.6.0.5',
        '--gateway-endpoint' => '10.6.0.2',
        '--ssh-user' => 'orbit',
        '--user' => 'orbit',
    ];

    $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();
    $this->artisan('orbit:internal:bake-app-node', $args)->assertSuccessful();

    expect(Node::query()->where('name', 'app-prod-1')->count())->toBe(1);
});
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php tests/Feature/Commands/NodeNewCommandTest.php
vendor/bin/pint --dirty --format agent
```

Expected: bake-app-node tests pass; existing `node:new` tests still pass after the row-write extraction (refactor-safe since both code paths now go through `NodeRegistryWriter`).

Commit this step on its own so the service refactor stays separable from the Docker builder:

```bash
git add app/Services/Nodes/NodeRegistryWriter.php app/Console/Commands/NodeNewCommand.php app/Console/Commands/Internal/BakeAppNodeCommand.php tests/Feature/Commands/Internal/BakeAppNodeCommandTest.php
git commit -m "feat: extract node registry writer for bake-app-node"
```

- [x] Add `DockerTopologyBuilder`.

Create `tests/E2E/Support/DockerTopologyBuilder.php`. The builder mirrors `IncusTopologyBuilder` and produces real seeded state. Key responsibilities:

1. Verify the runtime image `orbit-e2e-topology-runtime:current` exists.
2. Create a transient build network on `10.6.0.0/16` named `{$config->instancePrefix}-build-{$kind->value}`.
3. For each role, launch a transient build container with `--cap-add NET_ADMIN --cap-add NET_BIND_SERVICE`, with a stable `10.6.0.x` IP per role:
   - control → 10.6.0.3
   - gateway → 10.6.0.2
   - dev → 10.6.0.4
   - prod → 10.6.0.5
4. In each container, run `php artisan migrate --force` from the role checkout as the right user (control as `control`, all other roles as `orbit`).
5. In gateway, from `/home/orbit/orbit`, run `php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2`. This creates the CA root and seeds the local gateway row named `gateway`, matching `E2EGatewayApi` and topology contract expectations.
6. In gateway, call `E2EGatewayApi::seedControlIdentity($gatewayInstance, '10.6.0.3', 'control')` so the gateway SQLite contains the `control-1` row that feature tests read.
7. In gateway, call `E2EGatewayApi::start($gatewayInstance, "docker-build-{$kind->value}")`. Do not hand-roll the HTTP/TLS startup: this helper issues the gateway leaf certificate in `/home/orbit/orbit/storage/app/orbit/certs`, writes the TLS server script, then starts HTTP and TLS servers. The TLS server binds `tls://10.6.0.2:443`, so gateway containers must have `NET_BIND_SERVICE`.
8. In control, call `E2EControlIdentity::ensure($controlInstance, 'control', new SshKeyPair('/dev/null', '/dev/null'))`. Before using this placeholder, verify `SshKeyPair` is still a path-only value object:

   ```bash
   sed -n '1,80p' tests/E2E/Support/SshKeyPair.php
   ```

   If the constructor starts validating path existence or readability, create a temporary keypair in Docker test support and pass that instead.
9. From control, call `E2ECommand::ssh($controlInstance, 'control', new SshKeyPair('/dev/null', '/dev/null'), 'cd /home/control/orbit && php artisan gateway:add 10.6.0.2 --json')`.
10. For dev: from inside the gateway container, exec `cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host=10.6.0.4 --wireguard-address=10.6.0.4 --environment=development --tld=test --gateway-endpoint=10.6.0.2 --ssh-user=orbit --user=orbit`. This writes the gateway registry row that `node:new` would have written, without running the SSH/installer chain.
11. Repeat (10) for prod with name `app-prod-1`, `--host=10.6.0.5`, `--wireguard-address=10.6.0.5`, `--environment=production`, no `--tld`.
12. Do not try to preserve background PHP server processes in the image. `docker commit` captures filesystem state, not running processes. Commit the build containers after the database and certificate files are written; then remove the build containers. Task 6c restarts the gateway HTTP/TLS servers for each acquired lease.
13. `docker commit {build-container} {prepared-image-tag}` per role. Use `--change 'CMD ["sleep", "infinity"]'` to keep the prepared images quiescent on launch.
14. `docker rm -f` the build containers and `docker network rm` the build network.

Build container and build network names must use `E2EConfig::$instancePrefix`. Prepared image tags stay hardcoded as `orbit-e2e-topology:*` because they are reusable artifacts shared across runs, not disposable per-run resources.

The Docker runtime image does not need an `orbit` symlink. Use `php artisan` from an explicit checkout directory for every Docker builder command. The VM builders may keep using `orbit`; Docker should stay consistent with `php artisan`.

Start the builder from this concrete shape:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class DockerTopologyBuilder
{
    public function __construct(
        private DockerHost $host,
    ) {}

    /**
     * @return list<array{role: string, container: string, image: string}>
     */
    public function build(E2ETopologyKind $kind): array
    {
        $provider = new DockerTopologyProvider($this->host->config);
        $network = "{$this->host->config->instancePrefix}-build-{$kind->value}";
        $roles = $provider->rolesFor($kind);
        $containers = [];

        $this->host->mustRun(
            sprintf('docker image inspect %s >/dev/null', escapeshellarg('orbit-e2e-topology-runtime:current')),
            'Docker topology runtime image is missing',
        );

        try {
            $this->host->mustRun(
                sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)),
                "Could not create Docker build network {$network}",
            );

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $containers[$role] = new DockerInstance($this->host, $container, $network);

                $this->host->mustRun($this->runCommand($container, $network, $this->ipForRole($role)), "Could not start {$container}");
                $this->migrate($containers[$role], $role);
            }

            $this->seedTopology($kind, $containers);

            $manifest = [];

            foreach ($roles as $role) {
                $container = "{$network}-{$role}";
                $image = $provider->imageNameFor($kind, $role);

                $this->host->mustRun(
                    sprintf('docker commit --change %s %s %s', escapeshellarg('CMD ["sleep", "infinity"]'), escapeshellarg($container), escapeshellarg($image)),
                    "Could not commit {$container}",
                );

                $manifest[] = ['role' => $role, 'container' => $container, 'image' => $image];
            }

            return $manifest;
        } finally {
            foreach (array_keys($containers) as $role) {
                $this->host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg("{$network}-{$role}")), timeoutSeconds: 120);
            }

            $this->host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 60);
        }
    }

    private function runCommand(string $container, string $network, string $ip): string
    {
        return sprintf(
            'docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name %s --network %s --ip %s %s',
            escapeshellarg($container),
            escapeshellarg($network),
            escapeshellarg($ip),
            escapeshellarg('orbit-e2e-topology-runtime:current'),
        );
    }

    private function migrate(DockerInstance $instance, string $role): void
    {
        $user = $role === 'control' ? 'control' : 'orbit';
        $path = $role === 'control' ? '/home/control/orbit' : '/home/orbit/orbit';

        E2ECommand::ssh($instance, $user, new SshKeyPair('/dev/null', '/dev/null'), "cd {$path} && php artisan migrate --force", timeoutSeconds: 120);
    }

    /**
     * @param  array<string, DockerInstance>  $containers
     */
    private function seedTopology(E2ETopologyKind $kind, array $containers): void
    {
        $control = $containers['control'] ?? null;
        $gateway = $containers['gateway'] ?? null;

        if ($control === null || $gateway === null) {
            return;
        }

        E2ECommand::ssh($gateway, 'orbit', new SshKeyPair('/dev/null', '/dev/null'), 'cd /home/orbit/orbit && php artisan orbit:internal:bootstrap-gateway-local gateway 10.6.0.2', timeoutSeconds: 120);
        E2EGatewayApi::seedControlIdentity($gateway, '10.6.0.3', 'control');
        E2EGatewayApi::start($gateway, "docker-build-{$kind->value}");
        E2EGatewayApi::waitForGatewayApi($control, 'control', new SshKeyPair('/dev/null', '/dev/null'));
        E2EControlIdentity::ensure($control, 'control', new SshKeyPair('/dev/null', '/dev/null'));
        E2ECommand::ssh($control, 'control', new SshKeyPair('/dev/null', '/dev/null'), 'cd /home/control/orbit && php artisan gateway:add 10.6.0.2 --json', timeoutSeconds: 600);

        if (isset($containers['dev'])) {
            E2ECommand::ssh($gateway, 'orbit', new SshKeyPair('/dev/null', '/dev/null'), 'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-dev-1 --role=app --host=10.6.0.4 --wireguard-address=10.6.0.4 --environment=development --tld=test --gateway-endpoint=10.6.0.2 --ssh-user=orbit --user=orbit', timeoutSeconds: 120);
        }

        if (isset($containers['prod'])) {
            E2ECommand::ssh($gateway, 'orbit', new SshKeyPair('/dev/null', '/dev/null'), 'cd /home/orbit/orbit && php artisan orbit:internal:bake-app-node app-prod-1 --role=app --host=10.6.0.5 --wireguard-address=10.6.0.5 --environment=production --gateway-endpoint=10.6.0.2 --ssh-user=orbit --user=orbit', timeoutSeconds: 120);
        }
    }

    private function ipForRole(string $role): string
    {
        return match ($role) {
            'gateway' => '10.6.0.2',
            'control' => '10.6.0.3',
            'dev' => '10.6.0.4',
            'prod' => '10.6.0.5',
            default => throw new \RuntimeException("Unknown Docker topology role {$role}."),
        };
    }
}
```

The `node:new` integration is the hardest piece because Incus's builder leans on real SSH from control to a blank app VM. The Docker builder skips the SSH/installer chain entirely — that's exactly what the `e2e-provisioning` lane covers on Incus. Tests that need that path are gated by `requireCapabilities(realSsh: true)` and routed to Incus by the pool.

Instead, the builder calls a hidden `orbit:internal:bake-app-node` command (added in the next sub-task) inside the gateway container to write the same Node row that `node:new` would have produced. The command and `node:new` share a `NodeRegistryWriter` service, so the baked Docker rows stay in lockstep with whatever `node:new` writes today and tomorrow.

- [x] Add the prepare-topology command.

Create `app/Console/Commands/E2EPrepareDockerTopologyCommand.php`. Mirror the shape of the existing `E2EPrepareTopologyCommand` (which prepares Incus templates):

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Tests\E2E\Support\DockerTopologyBuilder;
use Tests\E2E\Support\DockerHost;
use Tests\E2E\Support\DockerTopologyProvider;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;

#[Signature('e2e:prepare-docker-topology
    {kind=control-gateway-dev-prod : Topology kind to prepare (control|control-gateway|control-gateway-dev|control-gateway-dev-prod)}
    {--force : Build the Docker prepared per-role images}
    {--json : Output as JSON}')]
#[Description('Prepare per-role Docker images used by the Docker prepared topology provider')]
class E2EPrepareDockerTopologyCommand extends Command
{
    protected $hidden = true;

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');
        $kind = E2ETopologyKind::tryFrom($kindValue);

        if ($kind === null) {
            $this->error("Invalid topology kind [{$kindValue}]. Supported: control, control-gateway, control-gateway-dev, control-gateway-dev-prod.");

            return self::FAILURE;
        }

        $config = E2EConfig::fromEnvironment();
        $provider = new DockerTopologyProvider($config);
        $images = array_map(
            fn (string $role): string => $provider->imageNameFor($kind, $role),
            $provider->rolesFor($kind),
        );

        if (! $this->option('force')) {
            $this->line('Dry run. Pass --force to build the Docker prepared images.');

            foreach ($images as $image) {
                $this->line("planned: {$image}");
            }

            return self::SUCCESS;
        }

        $builder = new DockerTopologyBuilder(new DockerHost($config));

        try {
            $manifest = $builder->build($kind);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($manifest as $entry) {
            $this->line("created: {$entry['image']}");
        }

        return self::SUCCESS;
    }
}
```

- [x] Add a unit test for the builder using `Process::fake()`.

Create `tests/Feature/DockerTopologyBuilderTest.php` that fakes the docker-cli surface and asserts that the builder issues the expected `docker run`, in-container `php artisan` calls, and `docker commit` commands per role for the `control-gateway` topology. Use fnmatch wildcards that account for `escapeshellarg` quoting around container names and image tags.

```php
Process::fake([
    "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-build-control-gateway'" => Process::result(),
    "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-control-gateway-control' *" => Process::result(output: "control-id\n"),
    "docker run -d --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE --name 'orbit-e2e-build-control-gateway-gateway' *" => Process::result(output: "gateway-id\n"),
    "docker exec * sh -lc *" => Process::result(),
    "docker commit --change * 'orbit-e2e-build-control-gateway-control' 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(),
    "docker commit --change * 'orbit-e2e-build-control-gateway-gateway' 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(),
    "docker rm -f *" => Process::result(),
    "docker network rm *" => Process::result(),
]);
```

Add a second assertion or test with `ORBIT_E2E_INSTANCE_PREFIX=ci-foo` proving the builder uses `ci-foo-build-control-gateway` and `ci-foo-build-control-gateway-{role}` for build resources while still committing the stable image tags `orbit-e2e-topology:control-gateway-{role}-current`.

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyBuilderTest.php tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
vendor/bin/pint --dirty --format agent
```

Expected: builder + command tests pass.

Commit (separate from the bake-command commit above):

```bash
git add tests/E2E/Support/DockerTopologyBuilder.php app/Console/Commands/E2EPrepareDockerTopologyCommand.php tests/Feature/DockerTopologyBuilderTest.php tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
git commit -m "test: add docker prepared topology builder"
```

## Task 6c: Acquire Docker Topology From Prepared Images

**Files:**

- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`
- Test: `tests/Feature/E2ETopologyResetTest.php`

With per-role prepared images in place, acquire() does the cheap work:

1. Create per-test docker network on `10.6.0.0/16`, name `{instancePrefix}-{runId}`.
2. For each role, launch a container from the prepared image at the role's pinned WireGuard IP (control 10.6.0.3, gateway 10.6.0.2, dev 10.6.0.4, prod 10.6.0.5) with `--cap-add NET_ADMIN --cap-add NET_BIND_SERVICE`.
3. Restart the gateway HTTP+TLS server in the gateway container (build-time processes don't survive `docker commit`).
4. Build a `DockerInstance` per role.
5. Return `E2ETopologyLease` with `snapshotReset: null` and a teardown closure that removes the per-test Docker network after all containers are deleted. `E2ETopologyLease::reset()` must remain the fallback owner: when the env value is `snapshot-restore` but `snapshotReset` is null, reset uses cleanup + rebuild, which is fresh container recreation for Docker.

- [x] Add a failing acquisition test.

Append to `tests/Feature/DockerTopologyProviderTest.php`:

```php
use Tests\E2E\Support\E2ETopologyAcquisitionOptions;
use Tests\E2E\Support\E2EPhaseTimer;

it('acquires a control-gateway lease by launching containers from prepared images', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-gateway-current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' --network 'orbit-e2e-run123' --ip '10.6.0.3' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-control-current'" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' --network 'orbit-e2e-run123' --ip '10.6.0.2' --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE 'orbit-e2e-topology:control-gateway-gateway-current'" => Process::result(output: "gateway-id\n"),
        "docker exec * sh -lc *" => Process::result(),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

    expect($lease->control()->name())->toBe('orbit-e2e-run123-control')
        ->and($lease->gateway()?->name())->toBe('orbit-e2e-run123-gateway');
});
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php --filter='acquires a control-gateway lease'
```

Expected: fail because `acquire()` still throws.

- [x] Add a failing partial-acquire cleanup test.

Append a test that fakes successful network creation and control container launch, then makes the gateway container launch fail. Assert the provider deletes the control container and Docker network before rethrowing:

```php
it('cleans containers and network when docker acquire fails partway through', function (): void {
    Process::fake([
        'command -v docker >/dev/null' => Process::result(),
        'docker info >/dev/null' => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-control-current' >/dev/null" => Process::result(),
        "docker image inspect 'orbit-e2e-topology:control-gateway-gateway-current' >/dev/null" => Process::result(),
        "docker network create --subnet '10.6.0.0/16' 'orbit-e2e-run123'" => Process::result(),
        "docker run -d --name 'orbit-e2e-run123-control' *" => Process::result(output: "control-id\n"),
        "docker run -d --name 'orbit-e2e-run123-gateway' *" => Process::result(exitCode: 1, errorOutput: "failed\n"),
        "docker rm -f 'orbit-e2e-run123-control' >/dev/null 2>&1 || true" => Process::result(),
        "docker rm -f 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true" => Process::result(),
        "docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true" => Process::result(),
    ]);

    $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());

    expect(fn () => $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions))
        ->toThrow(RuntimeException::class, 'Could not start container');

    Process::assertRan("docker rm -f 'orbit-e2e-run123-control' >/dev/null 2>&1 || true");
    Process::assertRan("docker rm -f 'orbit-e2e-run123-gateway' >/dev/null 2>&1 || true");
    Process::assertRan("docker network rm 'orbit-e2e-run123' >/dev/null 2>&1 || true");
});
```

Expected: the test fails until `acquire()` wraps partial setup in cleanup-on-error.

- [x] Add lease teardown support before wiring Docker cleanup.

Update `tests/E2E/Support/E2ETopologyLease.php` so its constructor accepts an optional teardown closure:

```php
/**
 * @param  (\Closure(E2EPhaseTimer): void)|null  $teardown
 */
public function __construct(
    private readonly E2ETopologyKind $kind,
    private E2EInstance $control,
    private ?E2EInstance $gateway,
    private ?E2EInstance $dev,
    private ?E2EInstance $prod,
    private readonly SshKeyPair $sshKeyPair,
    private readonly \Closure $rebuild,
    private ?\Closure $snapshotReset = null,
    private readonly ?\Closure $teardown = null,
) {}
```

At the end of `cleanup()`, after deleting instances, run:

```php
if ($this->teardown !== null) {
    $timer->measure('cleanup.teardown', fn () => ($this->teardown)($timer));
}
```

Add focused coverage in `tests/Feature/E2ETopologyResetTest.php` proving:

- teardown runs once during cleanup and does not run again on a second cleanup call;
- `ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore` falls back to cleanup + rebuild when `snapshotReset` is null.

- [x] Implement `DockerTopologyProvider::acquire()`.

Replace the placeholder body:

```php
public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer, E2ETopologyAcquisitionOptions $options): E2ETopologyLease
{
    $host = new DockerHost($this->config);
    $network = "{$this->config->instancePrefix}-{$runId}";
    $roles = $this->rolesFor($kind);
    $ipForRole = [
        'gateway' => '10.6.0.2',
        'control' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
    ];

    $instances = [];

    try {
        $timer->measure('docker.network', fn () => $host->mustRun(
            sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)),
            "Could not create Docker network {$network}",
        ));

        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $image = $this->imageNameFor($kind, $role);
            $ip = $ipForRole[$role] ?? throw new \RuntimeException("Unknown Docker topology role {$role}.");

            $timer->measure("docker.start.{$role}", fn () => $host->mustRun(
                sprintf(
                    'docker run -d --name %s --network %s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s',
                    escapeshellarg($name),
                    escapeshellarg($network),
                    escapeshellarg($ip),
                    escapeshellarg($image),
                ),
                "Could not start container {$name}",
            ));

            $instances[$role] = new DockerInstance($host, $name, $network);
        }

        if (isset($instances['gateway'])) {
            $timer->measure('docker.gateway-restart', fn () => E2EGatewayApi::start($instances['gateway'], "topology-{$runId}"));
        }
    } catch (\Throwable $exception) {
        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($name)), timeoutSeconds: 60);
        }

        $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);

        throw $exception;
    }

    $rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $kind, $runId, $network, $roles, $ipForRole): array {
        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $cycleTimer->measure("reset.delete.{$role}", fn () => $host->run(sprintf('docker rm -f %s >/dev/null 2>&1 || true', escapeshellarg($name)), timeoutSeconds: 60));
        }

        $cycleTimer->measure('reset.network.recreate', function () use ($host, $network): void {
            $host->run(sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)), timeoutSeconds: 30);
            $host->mustRun(sprintf('docker network create --subnet %s %s', escapeshellarg('10.6.0.0/16'), escapeshellarg($network)), "Could not recreate Docker network {$network}");
        });

        $newInstances = [];

        foreach ($roles as $role) {
            $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
            $image = $this->imageNameFor($kind, $role);
            $ip = $ipForRole[$role];

            $cycleTimer->measure("reset.start.{$role}", fn () => $host->mustRun(
                sprintf(
                    'docker run -d --name %s --network %s --ip %s --cap-add NET_ADMIN --cap-add NET_BIND_SERVICE %s',
                    escapeshellarg($name),
                    escapeshellarg($network),
                    escapeshellarg($ip),
                    escapeshellarg($image),
                ),
                "Could not restart container {$name}",
            ));

            $newInstances[$role] = new DockerInstance($host, $name, $network);
        }

        if (isset($newInstances['gateway'])) {
            $cycleTimer->measure('reset.gateway-restart', fn () => E2EGatewayApi::start($newInstances['gateway'], "topology-{$runId}"));
        }

        return [
            'instances' => $newInstances,
            'snapshotReset' => null,
        ];
    };

    $teardown = fn (E2EPhaseTimer $cleanupTimer) => $cleanupTimer->measure(
        'cleanup.network',
        fn () => $host->run(
            sprintf('docker network rm %s >/dev/null 2>&1 || true', escapeshellarg($network)),
            timeoutSeconds: 30,
        ),
    );

    return new E2ETopologyLease(
        kind: $kind,
        control: $instances['control'],
        gateway: $instances['gateway'] ?? null,
        dev: $instances['dev'] ?? null,
        prod: $instances['prod'] ?? null,
        sshKeyPair: new SshKeyPair('/dev/null', '/dev/null'),
        rebuild: $rebuild,
        snapshotReset: null,
        teardown: $teardown,
    );
}
```

Network cleanup is mandatory. Do not accept network leakage in the MVP; fixed `10.6.0.0/16` networks make leaks turn into immediate collisions on later runs.

The `SshKeyPair('/dev/null', '/dev/null')` placeholder is intentional only while `SshKeyPair` remains a path-only value object: Docker `ssh()` ignores the keypair. Tests that need a real keypair will be refused by capability requirements before they reach this provider. If `SshKeyPair` later validates file paths, replace this placeholder with a temporary keypair created by Docker test support.

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyResetTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests pass.

Commit:

```bash
git add tests/E2E/Support/DockerTopologyProvider.php tests/E2E/Support/E2ETopologyLease.php tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyResetTest.php
git commit -m "test: implement docker topology acquire"
```

## Task 6d: Classify Existing Feature Tests For Docker Safety

**Files:**

- Modify: `tests/E2E/NodeListTopologyTest.php` if it needs explicit capability requirements
- Modify: future `tests/E2E/*TopologyTest.php` files only when they already exist
- Test: `tests/Feature/E2ETopologyFactoryTest.php`

- [x] Audit current feature E2E tests before exposing Docker scripts.

Run:

```bash
rg -n "pest\\(\\)->group\\('e2e-feature|e2e-feature-|requireCapabilities|withSshUsers|E2ECommand::ssh|authorizeSsh|sudo|systemctl|update-ca-certificates|apt-get" tests/E2E
rg -l "e2e-feature" tests/E2E
```

Classify each `e2e-feature-*` test:

- Docker-safe: command assertions, registry reads, gateway API reads, forwarding behavior that can run over container command transport.
- VM-required: real SSH authentication, sudo convergence, trust-store mutation, systemd, package installs, kernel networking, cloud image behavior, or host mutation.

Current expected state:

- `tests/E2E/NodeListTopologyTest.php` is Docker-safe only if it keeps using `withSshUsers(['control' => 'control'])` and does not assert real SSH behavior.
- Provisioning tests stay outside Docker because they are `e2e-provisioning`, not `e2e-feature`.

Do not assume `NodeListTopologyTest.php` is the only feature test. The audit must list every `tests/E2E/*` file containing an `e2e-feature` group and classify each one before Task 7 exposes Docker scripts.

- [x] Add capability requirements only where needed.

For VM-required feature tests, update the acquisition call:

```php
$topology = E2ETopologyFactory::fromEnvironment()
    ->requireCapabilities(E2ETopologyCapabilities::vm())
    ->require(E2ETopologyKind::ControlGatewayDevProd);
```

For Docker-safe feature tests, leave them without VM requirements so provider order can choose Docker later.

- [x] Do not add Composer scripts for topology feature groups that have no tests.

Run:

```bash
rg -n "e2e-feature-control|e2e-feature-control-gateway|e2e-feature-control-gateway-dev-prod" tests/E2E
```

Expected before Task 7:

- only groups that appear in this output get Docker feature scripts;
- empty groups are not exposed as Composer scripts because Pest exits with code `1` for zero tests.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php
vendor/bin/pint --dirty --format agent
```

Commit:

```bash
git add tests/E2E tests/Feature/E2ETopologyFactoryTest.php
git commit -m "test: classify feature e2e topology capabilities"
```

## Task 6e: Add Docker E2E Reaper

**Files:**

- Create: `app/Console/Commands/E2EReapDockerCommand.php`
- Test: `tests/Feature/Commands/E2EReapDockerCommandTest.php`
- Modify: `composer.json`
- Modify: `TESTING.md`

The acquire path must clean up after partial failures, but interrupted processes can still leave Docker resources behind. Add a Docker reaper that mirrors the Incus/Hcloud cleanup surface.

- [x] Add failing reaper command tests.

Create `tests/Feature/Commands/E2EReapDockerCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('reports docker e2e resources in dry-run json mode', function (): void {
    Process::fake([
        "docker ps -a --format * --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format * --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--json' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('orbit-e2e-run-control')
        ->expectsOutputToContain('orbit-e2e-run');
});

it('removes docker e2e resources when forced', function (): void {
    Process::fake([
        "docker ps -a --format * --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run-control\n"),
        "docker network ls --format * --filter 'name=orbit-e2e-'" => Process::result(output: "orbit-e2e-run\n"),
        "docker rm -f 'orbit-e2e-run-control'" => Process::result(),
        "docker network rm 'orbit-e2e-run'" => Process::result(),
    ]);

    $this->artisan('e2e:reap-docker', ['--older-than' => '0m', '--force' => true])
        ->assertSuccessful();

    Process::assertRan("docker rm -f 'orbit-e2e-run-control'");
    Process::assertRan("docker network rm 'orbit-e2e-run'");
});
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EReapDockerCommandTest.php
```

Expected: fail because the command does not exist.

- [x] Add `e2e:reap-docker`.

Create `app/Console/Commands/E2EReapDockerCommand.php` with this behavior:

- signature: `e2e:reap-docker {--force : Delete stale Docker E2E containers and networks} {--older-than=6h : Only include resources older than this when creation time is available} {--json : Output as JSON}`;
- list containers with `docker ps -a --format '{{.Names}}' --filter 'name=orbit-e2e-'`;
- list networks with `docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-'`;
- count only names where `str_starts_with($name, $config->instancePrefix.'-')`;
- in dry-run mode, print matching containers and networks;
- in force mode, run `docker rm -f` for containers, then `docker network rm` for networks;
- treat missing resources during deletion as already-clean and continue.

Keep `--older-than` in the signature for parity with other reapers, but if Docker's list output cannot reliably expose created-at timestamps in the first slice, document that Docker reaping is prefix-based until timestamp filtering lands.

- [x] Add Composer and docs entries.

Add to `composer.json`:

```json
"e2e:reap-docker": "@php artisan e2e:reap-docker"
```

Add to `TESTING.md` Docker cleanup docs:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EReapDockerCommandTest.php
composer validate --no-check-publish
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Commit:

```bash
git add app/Console/Commands/E2EReapDockerCommand.php tests/Feature/Commands/E2EReapDockerCommandTest.php composer.json TESTING.md
git commit -m "test: add docker e2e reaper"
```

## Task 7: Add Docker Feature Lane Scripts And Documentation

**Files:**

- Modify: `composer.json`
- Modify: `TESTING.md`

- [x] Add Composer scripts for Docker feature runs that have tests.

Expose only topology sizes that already have matching `e2e-feature-*` tests. At the current baseline, that means `control-gateway-dev-prod`; add narrower Docker scripts later in the same commit that introduces narrower feature tests.

```json
"e2e:prepare-docker-runtime": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan e2e:prepare-docker-runtime"
],
"e2e:prepare-docker-topology": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan e2e:prepare-docker-topology"
],
"test:e2e:features:docker": [
    "Composer\\Config::disableProcessTimeout",
    "@test:e2e:features:docker:control-gateway-dev-prod"
],
"test:e2e:features:docker:control-gateway-dev-prod": [
    "Composer\\Config::disableProcessTimeout",
    "ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker php artisan test --testsuite=E2E --group=e2e-topology-contract-control-gateway-dev-prod --fail-on-empty-test-suite @no_additional_args",
    "ORBIT_E2E=1 ORBIT_E2E_TOPOLOGY_PROVIDER=docker php artisan test --testsuite=E2E --group=e2e-feature-control-gateway-dev-prod --exclude-group=e2e-topology-contract @additional_args"
]
```

Run:

```bash
composer validate --no-check-publish
```

Expected: Composer file is valid.

- [x] Document Docker as feature-only in `TESTING.md`.

Add this section under prepared topology documentation:

````markdown
### Docker Feature Topologies

Docker is an optional fast provider for `e2e-feature` tests:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod
ORBIT_E2E_TOPOLOGY_PROVIDER=docker composer test:e2e:features:docker
```

Docker topologies are disposable containers seeded from per-role prepared images. They are useful for fast command, registry, gateway API, and forwarding assertions. They do not prove real SSH, sudo, OS trust-store mutation, systemd, package installation, cloud-init, or VM networking behavior. Tests that need those capabilities must call `E2ETopologyFactory::fromEnvironment()->requireCapabilities(E2ETopologyCapabilities::vm())->require(...)` so the provider pool refuses Docker for them.

`composer test:e2e:features:docker` is serial in the MVP. Parallel Docker E2E is not supported while each test creates a fixed `10.6.0.0/16` bridge network; parallel support needs non-overlapping subnets or the per-worker network design from the Docker topology plan.

Tests must reach Docker topology services through topology handles such as `$topology->control()->ssh(...)`, not by calling `https://10.6.0.x` directly from the Pest process. On macOS, Docker Desktop runs containers inside a Linux VM, so the bridge subnet is reachable from containers but not necessarily from the developer host.

To offload Docker work to `beast` after Task 9 lands, keep Pest running locally and target the remote Docker daemon:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDER=docker ORBIT_E2E_DOCKER_HOSTS=beast composer test:e2e:features:docker
```

The local machine only needs the Docker CLI and SSH access; the prepared Docker images must exist on `beast`.

The Docker bridge uses subnet `10.6.0.0/16` to match seeded WireGuard addresses. If you also run an Orbit VPN client locally on `10.6.0.x`, stop the tunnel before running Docker E2E or Docker network creation will fail with a subnet overlap error.

If Docker resources accumulate from interrupted runs, prefer the reaper:

```bash
composer e2e:reap-docker
composer e2e:reap-docker -- --force --older-than=0m
```

Manual cleanup remains possible:

```bash
docker ps -aq --filter 'name=orbit-e2e-' | while read -r id; do [ -n "$id" ] && docker rm -f "$id"; done
docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-' | while read -r name; do [ -n "$name" ] && docker network rm "$name"; done
```
````

Run:

```bash
composer docs-lint
```

Expected: docs lint passes.

Commit:

```bash
git add composer.json TESTING.md
git commit -m "docs: add docker feature e2e lane"
```

## Task 8: Measure Docker Against Incus Before Changing Defaults

**Files:**

- Modify: `docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md`
- Optionally modify: `TESTING.md`

Goal: produce defensible timing data, not noise. A single sample is not signal. Methodology below uses warmup + three measured runs per provider per topology size, reports median.

- [x] Prepare the Docker runtime + topology images.

Run:

```bash
composer e2e:prepare-docker-runtime -- --force
composer e2e:prepare-docker-topology -- --force control-gateway-dev-prod
```

Expected: `orbit-e2e-topology-runtime:current` and the per-role per-topology images exist.

- [x] Warm up Docker provider page caches.

Run once and discard the result:

```bash
ORBIT_E2E_TIMINGS=1 composer test:e2e:features:docker -- --filter=NodeListTopology
```

Expected: succeeds; first-run page-cache cost is paid before measurement begins.

- [x] Run three Docker timing samples on each topology size that has feature tests.

For each `(provider, topology)` cell that has at least one feature test, run three measured iterations. Current baseline example for `control-gateway-dev-prod` + Docker:

```bash
for i in 1 2 3; do
  ORBIT_E2E_TIMINGS=1 composer test:e2e:features:docker -- --filter=NodeListTopology 2>&1 \
    | tee "/tmp/orbit-e2e-docker-cgdp-${i}.log"
done
```

Capture wall-clock from each log (sum of the `[orbit-e2e]` `acquire`, test runtime, and `cleanup` lines, or use `time` on the outer composer call).

- [x] Run three Incus timing samples on the same topology sizes.

```bash
for i in 1 2 3; do
  ORBIT_E2E_TIMINGS=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus composer test:e2e:features:control-gateway-dev-prod -- --filter=NodeListTopology 2>&1 \
    | tee "/tmp/orbit-e2e-incus-cgdp-${i}.log"
done
```

- [x] Record measured medians in this plan.

Add a `Measured Results` section with one table per topology size. Do not commit sample placeholders; write rows only with real measured values:

### Measured Results — control-gateway-dev-prod

| Provider | Run 1 | Run 2 | Run 3 | Median | Acquire dominant? |
| --- | --- | --- | --- | --- | --- |
| Docker on Beast via OrbStack CLI (`ORBIT_E2E_DOCKER_HOSTS=beast`) | 209.78s | 176.84s | 170.55s | 176.84s | Yes; container start and gateway restart dominate. |
| Incus | 165.36s | 186.64s | 218.15s | 186.64s | Yes; clone/start, SSH authorization, snapshots, and WireGuard dominate. |

Notes:

- Measurement command used `NodeListTopology` because `control-gateway-dev-prod` is the only topology size with a Docker feature script in this plan.
- Docker logs were written to `/tmp/orbit-e2e-docker-cgdp-task8-cleanup-{1,2,3}.log`.
- Incus logs were written to `/tmp/orbit-e2e-incus-cgdp-task8-{1,2,3}.log`.
- Docker cleanup timings required flushing the standalone `E2ETopologyLease::cleanup()` timer; without that flush, `cleanup.network` was measured but not emitted in ordinary non-reset cleanup paths.
- Median Docker `docker.network` was 0.849s and median Docker `cleanup.network` was 1.103s. `(0.849 + 1.103) / 176.84 = 1.1%`, below the 10% per-worker network threshold.

Commit only real measured values.

- [x] Decide provider order from data.

If Docker median is materially faster (>= 2x) and the per-topology feature tests do not require VM capabilities, update recommended feature-lane examples to:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDERS=docker,incus
```

If Docker becomes part of the recommended automatic pool, update `E2EConfig::topologyProviderNames()` so `ORBIT_E2E_TOPOLOGY_PROVIDER=auto` expands to the same measured provider order, and update its Task 1 test.

If Docker is not materially faster, keep Incus first and document Docker as an optional local debugging lane.

Decision: keep Incus as the `auto` provider and keep Docker as an explicit feature lane for local/offloaded debugging. Docker's median was 176.84s versus Incus at 186.64s, about 5% faster, not the required >= 2x improvement. Do not update `E2EConfig::topologyProviderNames()` for `auto`.

- [x] Decide whether per-worker network sharing is justified.

Compute `(median docker.network + median docker.cleanup-network) / median test wall time` from the Task 8 timings. If the ratio exceeds **10%**, implement per-worker network sharing per the design below as a follow-up task. Otherwise, leave the per-test network in place — the overhead does not justify the cleanup complexity.

Record the ratio in the `Measured Results` tables alongside the timing data.

Decision: leave Docker network mode at per-test. The measured Docker network create/delete ratio was 1.1% of median wall time, below the 10% threshold. Per-worker network sharing would add cleanup complexity without enough measured benefit.

### Per-Worker Network Design (Deferred Implementation)

If the 10% threshold is exceeded, the implementation is:

- Network name: `orbit-e2e-worker-{processToken}`. Pest exposes `TEST_TOKEN` for parallel workers; serial runs use `local`.
- First test in the worker creates the network; subsequent tests reuse it via `docker network create ... 2>/dev/null || true`.
- Container names stay per-test (`{instancePrefix}-{runId}-{role}`) so per-test cleanup stays clean.
- Per-test cleanup runs `docker network disconnect --force {network} {container}` before `docker rm -f {container}`. This avoids zombie attachments that can collide on the fixed `--ip 10.6.0.X` slots.
- Network teardown happens on Pest suite shutdown via an `afterAll()` hook at the E2E suite level. Leaked networks from interrupted runs are mopped up with `docker network ls --format '{{.Name}}' --filter 'name=orbit-e2e-worker-' | while read -r name; do [ -n "$name" ] && docker network rm "$name"; done`.
- The provider gains `ORBIT_E2E_DOCKER_NETWORK_MODE=per-test|per-worker`, default `per-test`. Tests do not change. Switching modes is a deployment-only knob.

Run:

```bash
composer docs-lint
```

Expected: docs lint passes.

Commit:

```bash
git add docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md TESTING.md
git commit -m "docs: record docker e2e timing decision"
```

## Task 9: Add Remote Docker Host Pool For Offloading

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/E2E/Support/DockerHost.php`
- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`
- Modify: `TESTING.md`

This task uses `DOCKER_HOST=ssh://host` as the remote transport, not `ssh host docker ...`. The persistent connection is materially faster, the Docker CLI handles auth, and there is no command quoting layer. Implement this after the local Docker MVP works; it is useful even before Docker becomes the default because it lets a low-resource development machine run Pest locally while `beast` runs the Docker containers.

- [x] Add config tests for remote Docker hosts.

```php
it('parses docker topology hosts independently from incus hosts', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast,sidecar1,sidecar2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2']);
    });
});

it('defaults docker max containers per host', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerMaxContainersPerHost)->toBe(8);
    });
});
```

Add `ORBIT_E2E_DOCKER_HOSTS` and `ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST` to the helper key list.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='docker topology hosts|docker max containers'
```

Expected: fail until the config properties are added.

- [x] Add Docker host pool config.

In `E2EConfig`, add:

```php
/** @var list<string> */
public array $dockerHosts,
public int $dockerMaxContainersPerHost,
```

In `fromEnvironment()`:

```php
dockerHosts: self::parseProviderNames(self::envString('ORBIT_E2E_DOCKER_HOSTS', 'local')),
dockerMaxContainersPerHost: self::envInt('ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST', 8),
```

In `forHost()`, preserve both fields.

Also update the Task 9 config tests to assert `$config->forHost('sidecar1')` preserves `dockerHosts` and `dockerMaxContainersPerHost`. `E2EConfig` uses named constructor arguments; forgetting `forHost()` will fail at runtime only when pooled providers switch hosts.

- [x] Teach `DockerHost` to target local or remote Docker daemons via `DOCKER_HOST=ssh://`.

Use this constructor:

```php
public function __construct(
    public E2EConfig $config,
    public string $host = 'local',
) {}
```

Use this `run()` implementation:

```php
public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
{
    $process = Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds);

    if ($this->host !== 'local') {
        $process = $process->env(['DOCKER_HOST' => "ssh://{$this->host}"]);
    }

    return $process->run($command);
}
```

This keeps the command string identical for local and remote runs — the Docker CLI on the local machine becomes the client, and the daemon over SSH becomes the server. Container exec, network create, image inspect, and so on all use the same docker subcommands without any wrapping.

- [x] Add host capacity selection.

In `DockerTopologyProvider`, choose the first Docker host where:

- `docker info` succeeds;
- per-role topology images exist;
- running Orbit-owned container count plus requested role count is at or below `ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST`.

Count containers by name substring and enforce the prefix in PHP:

```bash
docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'
```

Do not rely on `^orbit-e2e` anchoring in Docker's `name` filter. Parse the names returned by Docker and count only those where `str_starts_with($name, $this->config->instancePrefix.'-')`.

- [x] Document optional Docker host pooling.

Add:

```bash
ORBIT_E2E_DOCKER_HOSTS=local
ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST=8
```

Add an offload example:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDER=docker ORBIT_E2E_DOCKER_HOSTS=beast composer test:e2e:features:docker
```

Note: remote Docker hosts must have the runtime + per-role topology images already pushed/built. The Docker provider checks `docker image inspect` against the *remote* daemon (because `DOCKER_HOST=ssh://...` forwards every CLI call), so missing images on a remote host show up as `unavailable` and the pool moves on.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/DockerTopologyProviderTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Expected: tests and docs lint pass.

Commit:

```bash
git add tests/E2E/Support/E2EConfig.php tests/E2E/Support/DockerHost.php tests/E2E/Support/DockerTopologyProvider.php tests/Feature/E2EConfigTest.php tests/Feature/DockerTopologyProviderTest.php TESTING.md
git commit -m "test: add docker topology host pooling"
```

## Recommended Execution Order

1. Run Task 0 and confirm the current E2E baseline.
2. Implement Tasks 1-3 to make prepared topology providers explicit, including capability requirements.
3. Implement Tasks 4-5 for Docker primitives and provider scaffolding.
4. Implement Task 6a (runtime image) before 6b (per-role prepared images) before 6c (acquire). 6b is the riskiest step; expect to iterate on the seeding pipeline.
5. Run Tasks 6d and 6e before exposing Docker scripts so existing feature tests are classified and cleanup tooling exists.
6. Implement Task 7 (composer scripts + docs).
7. Run Task 8 before changing any provider default.
8. Implement Task 9 after the local Docker MVP works when offloading to `beast` or another Docker host is useful.

## Success Criteria

- Feature E2E can run with `ORBIT_E2E_TOPOLOGY_PROVIDER=docker`.
- VM-backed E2E remains required for provisioning and host-mutation coverage.
- Topology provider selection is independent from provisioning provider selection.
- Capability requirements are enforced at the pool level: a test that requires `realSsh` will be skipped on Docker even when Docker is first in the pool, with a clear message.
- Docker provider starts disposable containers, cleans them up reliably, and never mutates standing infrastructure.
- Docker prepared per-role images contain real seeded state (CA root, gateway leaf cert, control identity, gateway and dev/prod Node rows) — not empty SQLite databases.
- Docker feature lane timing is measured against Incus (warmup + 3 runs + median) before provider ordering changes.
- Docker feature E2E can target a remote Docker host such as `beast` through `ORBIT_E2E_DOCKER_HOSTS=beast`.
- Documentation clearly states what Docker E2E does and does not prove.

## Open Questions

None at present. Both originals (provider boundary capability handling, dev/prod seeding strategy) were resolved into Decisions. Docker network teardown is resolved by Task 6c's explicit `E2ETopologyLease` teardown closure, not by `DockerInstance::delete()` side effects. The per-worker network question was converted into a measurement-driven decision in Task 8 with the threshold and design pre-staged.
