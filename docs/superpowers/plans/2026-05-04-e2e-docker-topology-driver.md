# E2E Docker Topology Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Docker as an optional fast prepared-topology provider for feature E2E without weakening VM-backed provisioning coverage.

**Architecture:** Keep provisioning E2E on real VM providers. Split prepared topology selection from provisioning provider selection, then add a Docker provider that starts disposable containers for `e2e-feature` tests only. Docker optimizes command and gateway behavior feedback loops; Incus and hcloud remain the authority for SSH, sudo, OS trust stores, systemd, package installs, and real host mutation.

**Tech Stack:** Laravel 13, Pest 4, Composer scripts, Docker CLI, existing `tests/E2E/Support` provider and topology classes, Incus VMs on `beast`, `sidecar1`, and `sidecar2`.

---

## Decisions

- Docker is a prepared-topology provider, not a provisioning provider.
- Docker feature runs may use container command transport for `E2EInstance::ssh()`. That does not count as real SSH coverage.
- Tests that need real SSH, sudo convergence, trust-store mutation, package installation, systemd, kernel networking, or cloud image behavior must require a VM provider.
- Incus remains the default topology provider until Docker timing data proves it should be preferred for feature lanes.
- The first Docker implementation should run on the local Docker daemon. Remote Docker host pooling can follow once local timing proves the provider is worth scaling.

## Current Constraints

- `E2EConfig::$providerNames` currently controls provisioning providers.
- `E2ETopologyFactory` still has Incus-specific topology acquisition logic.
- `E2EProvider` includes prepared-topology methods, but Incus prepared topology acquisition bypasses `IncusProvider`.
- `E2EInstance` has VM-shaped methods, so Docker must either satisfy the same call surface or feature tests must declare stronger capabilities before running.
- Current feature scripts run topology contract tests before feature assertions, which is still the right safety model.

## Task 0: Reconcile Current E2E Speed Work

**Files:**

- Inspect: `docs/superpowers/plans/2026-05-04-e2e-fast-suite-hardening.md`
- Inspect: `composer.json`
- Inspect: `TESTING.md`
- Inspect: `tests/E2E/Support/E2ETopologyFactory.php`
- Inspect: `tests/E2E/Support/E2ETopologyLease.php`

- [ ] Check current worktree and recent E2E commits.

Run:

```bash
git status --short --branch --untracked-files=all
git log --oneline --decorate -8
```

Expected:

- no unrelated active-agent files are edited;
- the current speed hardening state is clear before changing provider boundaries.

- [ ] Check which topology improvements already landed.

Run:

```bash
rg -n "withSshUsers|features:parallel|ORBIT_E2E_TIMINGS|snapshot-restore|fresh-clone|E2ETopologyProvider|Docker" composer.json TESTING.md tests/E2E tests/Feature docs/superpowers/plans
```

Expected:

- record whether selective SSH setup landed;
- record whether parallel feature scripts landed;
- record whether a topology-provider abstraction already exists.

- [ ] Run the existing safe E2E support tests.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/E2EProviderPoolTest.php tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyResetTest.php
```

Expected: all selected tests pass before refactoring.

Commit after this task only if it changed documentation:

```bash
git add docs/superpowers/plans/2026-05-04-e2e-docker-topology-driver.md
git commit -m "docs: add docker e2e topology driver plan"
```

## Task 1: Split Topology Provider Config From Provisioning Provider Config

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Test: `tests/Feature/E2EConfigTest.php`
- Modify: `TESTING.md`

- [ ] Add a failing config test for topology provider defaults.

Append to `tests/Feature/E2EConfigTest.php`:

```php
it('defaults topology providers to incus independently from provisioning providers', function (): void {
    withE2EConfigEnvironment([], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->topologyProviderNames)->toBe(['incus'])
            ->and($config->providerNames)->toBe(['incus']);
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
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='defaults topology providers'
```

Expected: fail because `E2EConfig::$topologyProviderNames` does not exist.

- [ ] Add topology provider names to `E2EConfig`.

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

Add the parser:

```php
/**
 * @return list<string>
 */
private static function topologyProviderNames(): array
{
    $providers = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDERS', '');

    if ($providers !== '') {
        return self::parseProviderNames($providers);
    }

    $provider = self::envString('ORBIT_E2E_TOPOLOGY_PROVIDER', '');

    if ($provider !== '') {
        return self::parseProviderNames($provider);
    }

    return ['incus'];
}
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='defaults topology providers'
```

Expected: pass.

- [ ] Add a failing config test for Docker topology provider selection.

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

- [ ] Document the split in `TESTING.md`.

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
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

Expected: tests and docs lint pass.

Commit:

```bash
git add tests/E2E/Support/E2EConfig.php tests/Feature/E2EConfigTest.php TESTING.md
git commit -m "test: split e2e topology provider config"
```

## Task 2: Introduce Prepared Topology Provider Boundary

**Files:**

- Create: `tests/E2E/Support/E2ETopologyCapabilities.php`
- Create: `tests/E2E/Support/E2ETopologyProvider.php`
- Create: `tests/E2E/Support/E2ETopologyProviderSelection.php`
- Create: `tests/E2E/Support/E2ETopologyProviderPool.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

- [ ] Create a failing provider-pool test.

Create `tests/Feature/E2ETopologyProviderPoolTest.php`:

```php
<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EPhaseTimer;
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

function fakeTopologyProvider(string $name, bool $available): E2ETopologyProvider
{
    return new class($name, $available) implements E2ETopologyProvider
    {
        public function __construct(
            private readonly string $name,
            private readonly bool $available,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function capabilities(): E2ETopologyCapabilities
        {
            return E2ETopologyCapabilities::vm();
        }

        public function availability(E2ETopologyKind $kind): ProviderAvailability
        {
            return $this->available
                ? ProviderAvailability::available('ready')
                : ProviderAvailability::unavailable('unavailable');
        }

        public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer): E2ETopologyLease
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

- [ ] Add topology capabilities.

Create `tests/E2E/Support/E2ETopologyCapabilities.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ETopologyCapabilities
{
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
}
```

- [ ] Add topology provider interface.

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

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer): E2ETopologyLease;
}
```

- [ ] Add provider selection.

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

- [ ] Add provider pool.

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

    public function select(E2ETopologyKind $kind): E2ETopologyProviderSelection
    {
        $failures = [];

        foreach ($this->providers as $provider) {
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
git add tests/E2E/Support/E2ETopologyCapabilities.php tests/E2E/Support/E2ETopologyProvider.php tests/E2E/Support/E2ETopologyProviderSelection.php tests/E2E/Support/E2ETopologyProviderPool.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "test: add prepared topology provider boundary"
```

## Task 3: Move Incus Prepared Topology Acquisition Behind The Boundary

**Files:**

- Create: `tests/E2E/Support/IncusTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyProviderPool.php`
- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

- [ ] Add a failing factory test for topology provider selection.

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
```

Add `ORBIT_E2E_TOPOLOGY_PROVIDER` and `ORBIT_E2E_TOPOLOGY_PROVIDERS` to the helper key list in that file.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php --filter='topology provider failure details'
```

Expected: fail until the factory reads topology providers.

- [ ] Create `IncusTopologyProvider`.

Move the Incus-specific acquisition body from `E2ETopologyFactory::require()` into `tests/E2E/Support/IncusTopologyProvider.php`. The provider must:

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

The `acquire()` method must preserve the current behavior:

- choose an Incus host from `IncusHostPool`;
- clone the requested `IncusTopologyTemplate`;
- create the test SSH key pair;
- authorize SSH;
- create `lease-clean` snapshots;
- reestablish the synthetic gateway route;
- return an `E2ETopologyLease`.

- [ ] Add topology provider factory methods.

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

- [ ] Slim `E2ETopologyFactory`.

Change `E2ETopologyFactory::require()` so it resolves the kind, creates a timer, selects a topology provider, and delegates acquisition:

```php
$resolved = $this->resolveKind($kind);
$timer = new E2EPhaseTimer;

try {
    $config = E2EConfig::fromEnvironment();
    $selection = E2ETopologyProviderPool::fromEnvironment($config)->select($resolved);

    if (! $selection->available()) {
        test()->markTestSkipped('Prepared topology not available: '.$selection->message);
    }

    return $selection->provider()->acquire($resolved, E2ERun::id(), $timer);
} finally {
    $timer->flush('acquire');
}
```

- [ ] Run focused support tests.

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

- [ ] Add a failing test for Docker instance command construction.

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
        'docker exec orbit-e2e-run-control sh -lc *' => Process::result(output: "ok\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->exec('echo ok');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("ok\n");
});

it('maps ssh transport to user-scoped docker exec for container feature topologies', function (): void {
    Process::fake([
        'docker exec --user control orbit-e2e-run-control sh -lc *' => Process::result(output: "control\n"),
    ]);

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control');

    $result = $instance->ssh('control', new SshKeyPair('/tmp/fake', '/tmp/fake.pub'), 'whoami');

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe("control\n");
});
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php
```

Expected: fail because Docker classes do not exist.

- [ ] Add `DockerHost`.

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
        $process = Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds);

        return $process->run($command);
    }
}
```

- [ ] Add `DockerInstance`.

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

        $result = $this->host->run(sprintf(
            "docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' %s",
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

- [ ] Add failing provider availability tests.

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

- [ ] Add `DockerTopologyProvider`.

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

        $image = $this->imageName();
        if (! $host->run(sprintf('docker image inspect %s >/dev/null', escapeshellarg($image)), timeoutSeconds: 10)->successful()) {
            return ProviderAvailability::unavailable("docker topology image {$image} is not available");
        }

        return ProviderAvailability::available("docker topology image {$image} is available");
    }

    public function acquire(E2ETopologyKind $kind, string $runId, E2EPhaseTimer $timer): E2ETopologyLease
    {
        throw new \RuntimeException('Docker topology acquisition lands in Task 6.');
    }

    private function imageName(): string
    {
        return 'orbit-e2e-topology:current';
    }
}
```

- [ ] Register Docker in the topology provider pool.

Update `makeProvider()` in `tests/E2E/Support/E2ETopologyProviderPool.php`:

```php
return match ($provider) {
    'incus' => new IncusTopologyProvider($config),
    'docker' => new DockerTopologyProvider($config),
    default => throw new \InvalidArgumentException("Unknown E2E topology provider [{$provider}]."),
};
```

- [ ] Add provider-pool coverage for Docker config.

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

If `withE2EProviderEnvironment()` does not include topology provider keys, add them to that helper.

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyProviderPoolTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests pass, with Docker unavailable in environments without a prepared Docker image.

Commit:

```bash
git add tests/E2E/Support/DockerTopologyProvider.php tests/E2E/Support/E2ETopologyProviderPool.php tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "test: register docker topology provider"
```

## Task 6: Build And Acquire Docker Feature Topologies

**Files:**

- Create: `docker/e2e/topology/Dockerfile`
- Create: `app/Console/Commands/E2EPrepareDockerTopologyCommand.php`
- Modify: `bootstrap/app.php` or the current command registration location if needed
- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Test: `tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`

- [ ] Add a failing command test for Docker image preparation dry-run.

Create `tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('documents docker topology image preparation without force', function (): void {
    Process::fake();

    $this->artisan('e2e:prepare-docker-topology')
        ->expectsOutputToContain('orbit-e2e-topology:current')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Process::assertNothingRan();
});
```

Run:

```bash
php artisan test --compact tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
```

Expected: fail because the command does not exist.

- [ ] Add the Docker topology image Dockerfile.

Create `docker/e2e/topology/Dockerfile`:

```dockerfile
FROM php:8.5-cli-bookworm

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
    && sudo -iu control bash -lc 'cd /home/control/orbit && composer install --no-interaction --no-progress' \
    && sudo -iu orbit bash -lc 'cd /home/orbit/orbit && composer install --no-interaction --no-progress'

CMD ["sleep", "infinity"]
```

- [ ] Add the prepare command.

Create `app/Console/Commands/E2EPrepareDockerTopologyCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'e2e:prepare-docker-topology')]
class E2EPrepareDockerTopologyCommand extends Command
{
    protected $signature = 'e2e:prepare-docker-topology {--force : Build the Docker topology image}';

    protected $description = 'Prepare Docker images used by fast feature E2E topologies';

    public function handle(): int
    {
        $image = 'orbit-e2e-topology:current';

        $this->line("Docker topology image: {$image}");

        if (! $this->option('force')) {
            $this->line('Dry run. Pass --force to build the Docker topology image.');

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
php artisan test --compact tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
```

Expected: command dry-run test passes.

- [ ] Implement Docker topology acquisition.

In `DockerTopologyProvider::acquire()`, create a per-run network and containers for the requested roles:

```php
$host = new DockerHost($this->config);
$network = "orbit-e2e-{$runId}";
$image = $this->imageName();
$roles = match ($kind) {
    E2ETopologyKind::Control => ['control'],
    E2ETopologyKind::ControlGateway => ['control', 'gateway'],
    E2ETopologyKind::ControlGatewayDev => ['control', 'gateway', 'dev'],
    E2ETopologyKind::ControlGatewayDevProd => ['control', 'gateway', 'dev', 'prod'],
};

$timer->measure('docker.network', fn () => $this->mustRun($host, sprintf(
    'docker network create --subnet 10.6.0.0/16 %s',
    escapeshellarg($network),
)));

$instances = [];
foreach ($roles as $role) {
    $name = "{$this->config->instancePrefix}-{$runId}-{$role}";
    $ip = match ($role) {
        'gateway' => '10.6.0.2',
        'control' => '10.6.0.3',
        'dev' => '10.6.0.4',
        'prod' => '10.6.0.5',
        default => throw new \RuntimeException("Unknown Docker topology role {$role}."),
    };

    $timer->measure("docker.start.{$role}", fn () => $this->mustRun($host, sprintf(
        'docker run -d --name %s --network %s --ip %s %s',
        escapeshellarg($name),
        escapeshellarg($network),
        escapeshellarg($ip),
        escapeshellarg($image),
    )));

    $instances[$role] = new DockerInstance($host, $name);
}
```

Then seed the same prepared state that Incus feature topologies expose:

- control node registry contains local `control-1`;
- gateway node registry contains `gateway`;
- gateway API can respond to `/api/me`, `/api/ca/root`, `/api/nodes`, and `POST /api/nodes`;
- dev app node is `app-dev-1` when present;
- prod app node is `app-prod-1` when present.

Return an `E2ETopologyLease` whose cleanup deletes containers and the Docker network. Its reset closure may delete and recreate the containers from the prepared image because that is cheap enough for the first Docker implementation.

- [ ] Add provider acquisition tests with `Process::fake()`.

In `tests/Feature/DockerTopologyProviderTest.php`, add a test that fakes:

```php
Process::fake([
    'docker network create *' => Process::result(),
    'docker run -d --name *control*' => Process::result(output: "control-id\n"),
    'docker run -d --name *gateway*' => Process::result(output: "gateway-id\n"),
    'docker exec *' => Process::result(),
]);
```

Assert:

```php
$lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer);

expect($lease->control()->name())->toContain('control')
    ->and($lease->gateway()?->name())->toContain('gateway');
```

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests pass.

Commit:

```bash
git add docker/e2e/topology/Dockerfile app/Console/Commands/E2EPrepareDockerTopologyCommand.php tests/E2E/Support/DockerTopologyProvider.php tests/Feature/DockerTopologyProviderTest.php tests/Feature/Commands/E2EPrepareDockerTopologyCommandTest.php
git commit -m "test: add docker prepared topology acquisition"
```

## Task 7: Add Docker Feature Lane Scripts And Documentation

**Files:**

- Modify: `composer.json`
- Modify: `TESTING.md`
- Test: no PHP test needed

- [ ] Add Composer scripts for Docker feature runs.

Add:

```json
"e2e:prepare-docker-topology": [
    "Composer\\Config::disableProcessTimeout",
    "@php artisan e2e:prepare-docker-topology"
],
"test:e2e:features:docker": [
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

- [ ] Document Docker as feature-only in `TESTING.md`.

Add this section under prepared topology documentation:

````markdown
### Docker Feature Topologies

Docker is an optional fast provider for `e2e-feature` tests:

```bash
composer e2e:prepare-docker-topology -- --force
ORBIT_E2E_TOPOLOGY_PROVIDER=docker composer test:e2e:features:docker
```

Docker topologies are disposable containers. They are useful for fast command,
registry, gateway API, and forwarding assertions after a topology is already in
the desired shape. They do not prove real SSH, sudo, OS trust-store mutation,
systemd, package installation, cloud-init, or VM networking behavior. Keep those
assertions in VM-backed `e2e-provisioning` and VM-backed topology contract runs.
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

- [ ] Prepare the Docker image.

Run:

```bash
composer e2e:prepare-docker-topology -- --force
```

Expected: `orbit-e2e-topology:current` exists.

- [ ] Run one Docker feature timing sample.

Run:

```bash
ORBIT_E2E_TIMINGS=1 composer test:e2e:features:docker -- --filter=NodeListTopology
```

Expected:

- topology contract passes;
- NodeList topology test passes;
- timing output includes Docker acquisition and cleanup phases.

- [ ] Run one Incus feature timing sample.

Run:

```bash
ORBIT_E2E_TIMINGS=1 ORBIT_E2E_TOPOLOGY_PROVIDER=incus composer test:e2e:features:control-gateway-dev-prod -- --filter=NodeListTopology
```

Expected:

- topology contract passes;
- NodeList topology test passes;
- timing output includes Incus acquisition and cleanup phases.

- [ ] Record the observed timing result in this plan.

Add a `Measured Results` section with one table row for Docker and one table row
for Incus. Use the exact command that was run, numeric seconds from
`ORBIT_E2E_TIMINGS=1`, and a short note explaining whether acquisition, test
execution, or cleanup dominated runtime. Commit only real measured values.

- [ ] Decide provider order from data.

If Docker is materially faster and the feature test does not require VM capabilities, update recommended feature-lane examples to:

```bash
ORBIT_E2E_TOPOLOGY_PROVIDERS=docker,incus
```

If Docker is not materially faster, keep Incus first and document Docker as an optional local debugging lane.

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

## Task 9: Add Remote Docker Host Pool Only If Local Docker Wins

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/E2E/Support/DockerHost.php`
- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`
- Modify: `TESTING.md`

- [ ] Add config tests for remote Docker hosts.

Add:

```php
it('parses docker topology hosts independently from incus hosts', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOSTS' => 'beast,sidecar1,sidecar2',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->dockerHosts)->toBe(['beast', 'sidecar1', 'sidecar2']);
    });
});
```

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='docker topology hosts'
```

Expected: fail until the config property is added.

- [ ] Add Docker host pool config.

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

- [ ] Teach `DockerHost` to run local or remote Docker commands.

Use this constructor:

```php
public function __construct(
    public E2EConfig $config,
    public string $host = 'local',
) {}
```

Use this `run()` implementation:

```php
$wrapped = $this->host === 'local'
    ? $command
    : sprintf('ssh %s %s', escapeshellarg($this->host), escapeshellarg($command));

return Process::timeout($timeoutSeconds ?? $this->config->timeoutSeconds)->run($wrapped);
```

- [ ] Add host capacity selection.

In `DockerTopologyProvider`, choose the first Docker host where:

- `docker info` succeeds;
- topology image exists;
- running Orbit-owned container count plus requested role count is at or below `ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST`.

Count containers with:

```bash
docker ps --format '{{.Names}}' | awk '/^orbit-e2e/ { count++ } END { print count + 0 }'
```

- [ ] Document optional Docker host pooling.

Add:

```bash
ORBIT_E2E_DOCKER_HOSTS=local
ORBIT_E2E_DOCKER_MAX_CONTAINERS_PER_HOST=8
```

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

1. Finish `2026-05-04-e2e-fast-suite-hardening.md`.
2. Run Task 0 here and recheck current code.
3. Implement Tasks 1-3 to make prepared topology providers explicit.
4. Implement Tasks 4-7 for local Docker MVP.
5. Run Task 8 before changing any provider default.
6. Implement Task 9 only if Docker is materially faster and local Docker becomes the feature-lane bottleneck.

## Success Criteria

- Feature E2E can run with `ORBIT_E2E_TOPOLOGY_PROVIDER=docker`.
- VM-backed E2E remains required for provisioning and host-mutation coverage.
- Topology provider selection is independent from provisioning provider selection.
- Docker provider starts disposable containers, cleans them up reliably, and never mutates standing infrastructure.
- Docker feature lane timing is measured against Incus before provider ordering changes.
- Documentation clearly states what Docker E2E does and does not prove.

## Open Questions

- Should Docker remote host pooling use plain `ssh host docker ...` first, or Docker contexts through `DOCKER_HOST=ssh://host`?
- Should Docker feature contracts use the existing `E2EInstance::ssh()` method name, or should a later cleanup rename that transport to avoid implying real SSH?
