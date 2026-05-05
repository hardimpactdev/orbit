# E2E Resource Lease Pool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace deterministic E2E host assignment with a shared blocking slot lease pool for Docker feature tests and Incus provisioning tests.

**Architecture:** Add a local filesystem-backed lease pool under `storage/framework/e2e/leases` because all Pest workers run on the local machine even when work happens on remote Docker or Incus hosts. Docker and Incus providers will ask the pool for a backend-specific host slot before allocating containers or VMs, block until a slot is available, and release the slot during existing topology/run cleanup. Hetzner support is explicitly out of scope; stop after Docker and Incus lease pooling are working and merge this work into `main` before adding any Hetzner runner.

**Tech Stack:** Laravel 13 console/test harness code, Pest 4 parallel testing, Composer scripts, Docker over local/SSH daemons, Incus over SSH hosts, local filesystem lock files.

---

## Scope Boundary

This plan ends when:

- `composer test:e2e` uses Docker leases instead of deterministic `TEST_TOKEN -> host` routing.
- `composer test:e2e:provision` runs with Pest parallel mode and uses Incus leases.
- Tests wait for free slots instead of skipping or overloading hosts.
- Stale leases expire by TTL.
- Documentation explains Docker and Incus slot pooling.

Do not implement Hetzner, HCloud runner provisioning, or Docker-on-Hetzner in this branch. Once this plan is complete and verified, merge the branch/worktree back into `main`. Hetzner support starts as a new branch after that merge.

## Files

- Create: `tests/E2E/Support/E2EResourceLease.php`
- Create: `tests/E2E/Support/E2EResourceLeasePool.php`
- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Modify: `tests/E2E/Support/IncusProvider.php`
- Modify: `tests/E2E/Support/E2ERun.php`
- Modify: `composer.json`
- Modify: `TESTING.md`
- Test: `tests/Feature/E2EResourceLeasePoolTest.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`
- Test: `tests/Feature/E2EConfigTest.php`
- Test: `tests/Feature/E2EProviderPoolTest.php`
- Test: `tests/Feature/VerificationScriptsTest.php`

## Task 1: Lease Pool Core

**Files:**
- Create: `tests/E2E/Support/E2EResourceLease.php`
- Create: `tests/E2E/Support/E2EResourceLeasePool.php`
- Test: `tests/Feature/E2EResourceLeasePoolTest.php`

- [ ] **Step 1: Write failing tests for acquiring, blocking, release, and TTL cleanup**

Create `tests/Feature/E2EResourceLeasePoolTest.php`:

```php
<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EResourceLeasePool;

beforeEach(function (): void {
    $this->leaseDirectory = storage_path('framework/e2e/test-leases-'.bin2hex(random_bytes(4)));
    mkdir($this->leaseDirectory, 0777, true);
});

afterEach(function (): void {
    if (is_dir($this->leaseDirectory)) {
        exec('rm -rf '.escapeshellarg($this->leaseDirectory));
    }
});

it('acquires and releases a named resource slot', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 60);

    $lease = $pool->acquire('docker', ['sidecar1' => 2], requiredSlots: 1);

    expect($lease->backend())->toBe('docker')
        ->and($lease->host())->toBe('sidecar1')
        ->and($lease->slot())->toBe(1);

    expect($pool->snapshot('docker', ['sidecar1' => 2]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => true],
        ['host' => 'sidecar1', 'slot' => 2, 'leased' => false],
    ]);

    $lease->release();

    expect($pool->snapshot('docker', ['sidecar1' => 2]))->toMatchArray([
        ['host' => 'sidecar1', 'slot' => 1, 'leased' => false],
        ['host' => 'sidecar1', 'slot' => 2, 'leased' => false],
    ]);
});

it('allocates different slots while prior leases are held', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 60);

    $first = $pool->acquire('docker', ['sidecar1' => 2], requiredSlots: 1);
    $second = $pool->acquire('docker', ['sidecar1' => 2], requiredSlots: 1);

    expect($first->slot())->toBe(1)
        ->and($second->slot())->toBe(2);

    $first->release();
    $second->release();
});

it('waits for unavailable slots and then fails with a useful message', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 0, staleSeconds: 60);
    $held = $pool->acquire('incus', ['sidecar1' => 1], requiredSlots: 1);

    expect(fn () => $pool->acquire('incus', ['sidecar1' => 1], requiredSlots: 1))
        ->toThrow(RuntimeException::class, 'No incus E2E slot became available');

    $held->release();
});

it('reclaims stale leases before acquiring', function (): void {
    $pool = new E2EResourceLeasePool($this->leaseDirectory, waitSeconds: 1, staleSeconds: 0);
    $stale = $pool->acquire('docker', ['beast' => 1], requiredSlots: 1);

    $fresh = $pool->acquire('docker', ['beast' => 1], requiredSlots: 1);

    expect($stale->slot())->toBe(1)
        ->and($fresh->slot())->toBe(1);

    $fresh->release();
});
```

- [ ] **Step 2: Run tests and verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/E2EResourceLeasePoolTest.php
```

Expected: fail because `E2EResourceLeasePool` and `E2EResourceLease` do not exist.

- [ ] **Step 3: Implement `E2EResourceLease`**

Create `tests/E2E/Support/E2EResourceLease.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2EResourceLease
{
    private bool $released = false;

    public function __construct(
        private readonly string $backend,
        private readonly string $host,
        private readonly int $slot,
        private readonly string $path,
    ) {}

    public function backend(): string
    {
        return $this->backend;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function slot(): int
    {
        return $this->slot;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }
}
```

- [ ] **Step 4: Implement `E2EResourceLeasePool`**

Create `tests/E2E/Support/E2EResourceLeasePool.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

use RuntimeException;

final readonly class E2EResourceLeasePool
{
    public function __construct(
        private string $directory,
        private int $waitSeconds,
        private int $staleSeconds,
    ) {}

    /**
     * @param  array<string, int>  $hostSlots
     */
    public static function fromEnvironment(): self
    {
        return new self(
            storage_path('framework/e2e/leases'),
            waitSeconds: self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
            staleSeconds: self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
        );
    }

    /**
     * @param  array<string, int>  $hostSlots
     */
    public function acquire(string $backend, array $hostSlots, int $requiredSlots = 1): E2EResourceLease
    {
        $deadline = time() + $this->waitSeconds;

        do {
            $this->ensureDirectory();
            $this->reapStaleLeases($backend, $hostSlots);

            foreach ($hostSlots as $host => $slots) {
                for ($slot = 1; $slot <= $slots; $slot++) {
                    $path = $this->path($backend, $host, $slot);

                    if (@file_put_contents($path, $this->payload($backend, $host, $slot), LOCK_EX | LOCK_NB) !== false) {
                        return new E2EResourceLease($backend, $host, $slot, $path);
                    }
                }
            }

            if ($this->waitSeconds <= 0) {
                break;
            }

            usleep(250_000);
        } while (time() <= $deadline);

        throw new RuntimeException("No {$backend} E2E slot became available within {$this->waitSeconds}s.");
    }

    /**
     * @param  array<string, int>  $hostSlots
     * @return list<array{host: string, slot: int, leased: bool}>
     */
    public function snapshot(string $backend, array $hostSlots): array
    {
        $this->ensureDirectory();
        $snapshot = [];

        foreach ($hostSlots as $host => $slots) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $snapshot[] = [
                    'host' => $host,
                    'slot' => $slot,
                    'leased' => is_file($this->path($backend, $host, $slot)),
                ];
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, int>  $hostSlots
     */
    private function reapStaleLeases(string $backend, array $hostSlots): void
    {
        foreach ($hostSlots as $host => $slots) {
            for ($slot = 1; $slot <= $slots; $slot++) {
                $path = $this->path($backend, $host, $slot);

                if (! is_file($path)) {
                    continue;
                }

                $age = time() - (filemtime($path) ?: time());

                if ($age >= $this->staleSeconds) {
                    @unlink($path);
                }
            }
        }
    }

    private function payload(string $backend, string $host, int $slot): string
    {
        return json_encode([
            'backend' => $backend,
            'host' => $host,
            'slot' => $slot,
            'pid' => getmypid(),
            'created_at' => time(),
        ], JSON_THROW_ON_ERROR);
    }

    private function path(string $backend, string $host, int $slot): string
    {
        $safeHost = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $host);

        return "{$this->directory}/{$backend}-{$safeHost}-{$slot}.lease";
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0777, true);
        }
    }

    private static function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return (int) $value;
    }
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/E2EResourceLeasePoolTest.php
```

Expected: pass.

## Task 2: Configuration Slots

**Files:**
- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/Helpers/E2EEnvironment.php`
- Test: `tests/Feature/E2EConfigTest.php`

- [ ] **Step 1: Write failing config tests**

Add to `tests/Feature/E2EConfigTest.php`:

```php
it('parses incus host slots for blocking provisioning workers', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:2, sidecar2:2, beast:3',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->incusHostSlots)->toBe([
            'sidecar1' => 2,
            'sidecar2' => 2,
            'beast' => 3,
        ])->and($config->forHost('beast')->incusHostSlots)->toBe([
            'sidecar1' => 2,
            'sidecar2' => 2,
            'beast' => 3,
        ]);
    });
});

it('exposes slot lease wait and stale settings', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '120',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '3600',
    ], function (): void {
        $config = E2EConfig::fromEnvironment();

        expect($config->slotWaitSeconds)->toBe(120)
            ->and($config->slotStaleSeconds)->toBe(3600);
    });
});
```

- [ ] **Step 2: Run tests and verify they fail**

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php --filter='incus host slots|slot lease'
```

Expected: fail because the new config properties do not exist.

- [ ] **Step 3: Extend `E2EConfig`**

In `tests/E2E/Support/E2EConfig.php`:

- add constructor properties:

```php
/** @var array<string, int> */
public array $incusHostSlots = [],
public int $slotWaitSeconds = 900,
public int $slotStaleSeconds = 7200,
```

- reuse the existing Docker slot parser by renaming `parseDockerHostSlots()` to `parseHostSlots()`;
- populate:

```php
incusHostSlots: self::parseHostSlots(self::envString('ORBIT_E2E_INCUS_HOST_SLOTS', '')),
slotWaitSeconds: self::envInt('ORBIT_E2E_SLOT_WAIT_SECONDS', 900),
slotStaleSeconds: self::envInt('ORBIT_E2E_SLOT_STALE_SECONDS', 7200),
```

- preserve those properties in `forHost()`.

- [ ] **Step 4: Add environment helper keys**

In `tests/Helpers/E2EEnvironment.php`, add these keys to the default list:

```php
'ORBIT_E2E_INCUS_HOST_SLOTS',
'ORBIT_E2E_SLOT_WAIT_SECONDS',
'ORBIT_E2E_SLOT_STALE_SECONDS',
```

- [ ] **Step 5: Run tests**

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php
```

Expected: pass.

## Task 3: Docker Lease Pool Integration

**Files:**
- Modify: `tests/E2E/Support/DockerTopologyProvider.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Test: `tests/Feature/DockerTopologyProviderTest.php`

- [ ] **Step 1: Write failing Docker lease tests**

Add to `tests/Feature/DockerTopologyProviderTest.php`:

```php
it('uses the first free docker host slot instead of deterministic test token routing', function (): void {
    Process::fake(function ($process) {
        $host = $process->environment['DOCKER_HOST'] ?? 'local';

        if ($process->command === 'command -v docker >/dev/null') {
            return Process::result();
        }

        if ($process->command === 'docker info >/dev/null') {
            return Process::result();
        }

        if (str_contains($process->command, 'docker image inspect')) {
            return Process::result();
        }

        if ($process->command === "docker ps --format '{{.Names}}' --filter 'name=orbit-e2e-'") {
            return Process::result();
        }

        if (str_starts_with($process->command, 'docker network create')) {
            expect($host)->toBe('ssh://sidecar1');

            return Process::result();
        }

        if (str_starts_with($process->command, 'docker run -d ')) {
            return Process::result(output: "container-id\n");
        }

        if (str_starts_with($process->command, 'docker exec ')) {
            return Process::result();
        }

        return Process::result();
    });

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_HOST_SLOTS' => 'sidecar1:1,sidecar2:1',
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '1',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '60',
    ], function (): void {
        putenv('TEST_TOKEN=2');

        try {
            $provider = new DockerTopologyProvider(E2EConfig::fromEnvironment());
            $lease = $provider->acquire(E2ETopologyKind::ControlGateway, 'run123', new E2EPhaseTimer, new E2ETopologyAcquisitionOptions);

            expect($lease->control()->name())->toBe('orbit-e2e-run123-control');

            $lease->cleanup();
        } finally {
            putenv('TEST_TOKEN');
        }
    });
});
```

- [ ] **Step 2: Run test and verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php --filter='first free docker host slot'
```

Expected: fail because the current provider maps `TEST_TOKEN=2` to `sidecar2`.

- [ ] **Step 3: Allow topology lease cleanup to release resource leases**

In `tests/E2E/Support/E2ETopologyLease.php`, add an optional constructor property:

```php
private readonly ?E2EResourceLease $resourceLease = null,
```

In `cleanup()`, release it in `finally` after teardown:

```php
if ($this->resourceLease !== null) {
    $timer->measure('cleanup.resource-lease', fn () => $this->resourceLease->release());
}
```

- [ ] **Step 4: Replace Docker deterministic host routing with lease acquisition**

In `DockerTopologyProvider`:

- remove `dockerHostCandidates()` deterministic `TEST_TOKEN` routing;
- add:

```php
private function dockerHostSlots(): array
{
    if ($this->config->dockerHostSlots !== []) {
        return $this->config->dockerHostSlots;
    }

    return array_fill_keys($this->config->dockerHosts, max(1, intdiv($this->config->dockerMaxContainersPerHost, 4)));
}
```

- in `acquire()`, call:

```php
$resourceLease = E2EResourceLeasePool::fromEnvironment()
    ->acquire('docker', $this->dockerHostSlots(), requiredSlots: 1);
```

- construct `$host = new DockerHost($this->config, $resourceLease->host());`;
- if acquisition fails after the lease is held, release the resource lease in the catch block;
- pass `resourceLease: $resourceLease` into `E2ETopologyLease`.

- [ ] **Step 5: Keep Docker availability non-blocking**

Change `availability()` to inspect configured hosts without acquiring a lease. It should report available if at least one configured Docker host is reachable and has required images. It should not fail because all slots are busy; queuing belongs in `acquire()`.

- [ ] **Step 6: Run Docker tests**

Run:

```bash
php artisan test --compact tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2EResourceLeasePoolTest.php
```

Expected: pass.

## Task 4: Incus Provisioning Lease Integration

**Files:**
- Modify: `tests/E2E/Support/IncusProvider.php`
- Modify: `tests/E2E/Support/E2ERun.php`
- Test: `tests/Feature/E2EProviderPoolTest.php`

- [ ] **Step 1: Write failing Incus provider test**

Add to `tests/Feature/E2EProviderPoolTest.php`:

```php
it('acquires an incus resource lease before starting a provisioning run', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_HOST_SLOTS' => 'sidecar1:1,sidecar2:1',
        'ORBIT_E2E_SLOT_WAIT_SECONDS' => '1',
        'ORBIT_E2E_SLOT_STALE_SECONDS' => '60',
    ], function (): void {
        $provider = new IncusProvider(E2EConfig::fromEnvironment());

        expect($provider->config()->host)->toBe('beast');

        $selection = (new ProviderPool([$provider]))->select(E2EImage::Base);

        if (! $selection->available()) {
            $this->markTestSkipped($selection->message);
        }

        expect($selection->provider()?->config()->host)->toBe('sidecar1');
    });
});
```

If this test is too coupled to real Incus availability, replace real provider checks with a small fake `IncusHost` injected into a test-only provider factory. The assertion must still prove that slot host selection changes the provider config from the default host to the leased host.

- [ ] **Step 2: Run test and verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/E2EProviderPoolTest.php --filter='incus resource lease'
```

Expected: fail because Incus provisioning does not use `ORBIT_E2E_INCUS_HOST_SLOTS`.

- [ ] **Step 3: Make `IncusProvider` lease-aware**

In `tests/E2E/Support/IncusProvider.php`:

- remove `readonly` from the class;
- add nullable properties:

```php
private ?E2EResourceLease $resourceLease = null;
```

- add method:

```php
private function acquireResourceLease(): void
{
    if ($this->resourceLease !== null) {
        return;
    }

    $hostSlots = $this->config->incusHostSlots !== []
        ? $this->config->incusHostSlots
        : [$this->config->host => $this->config->incusMaxVmsPerHost];

    $this->resourceLease = E2EResourceLeasePool::fromEnvironment()
        ->acquire('incus', $hostSlots, requiredSlots: 1);

    $this->config = $this->config->forHost($this->resourceLease->host());
    $this->host = new IncusHost($this->config);
}
```

- call `acquireResourceLease()` before availability host checks and before `startRun()`;
- update `cleanup()` to release `$this->resourceLease` after instance cleanup and remote directory cleanup.

- [ ] **Step 4: Ensure `E2ERun::cleanup()` releases Incus leases**

No direct `E2ERun` change should be necessary if `IncusProvider::cleanup()` releases the provider lease. Add a focused test if cleanup does not call provider cleanup when there are no instances.

- [ ] **Step 5: Run provider tests**

Run:

```bash
php artisan test --compact tests/Feature/E2EProviderPoolTest.php tests/Feature/E2EResourceLeasePoolTest.php
```

Expected: pass.

## Task 5: Parallel Provisioning Composer Script

**Files:**
- Modify: `composer.json`
- Modify: `tests/Feature/VerificationScriptsTest.php`
- Modify: `TESTING.md`

- [ ] **Step 1: Write failing script verification**

In `tests/Feature/VerificationScriptsTest.php`, change the expected `test:e2e:provision` script to:

```php
expect($composer['scripts']['test:e2e:provision'])->toBe([
    'Composer\\Config::disableProcessTimeout',
    'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provision --parallel --processes=${ORBIT_E2E_PROVISION_PARALLEL_PROCESSES:-2} @additional_args',
]);
```

- [ ] **Step 2: Run test and verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php --filter='test:e2e:provision'
```

Expected: fail because the script is still serial.

- [ ] **Step 3: Update Composer script**

In `composer.json`, change `test:e2e:provision` to:

```json
"test:e2e:provision": [
    "Composer\\Config::disableProcessTimeout",
    "ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-provision --parallel --processes=${ORBIT_E2E_PROVISION_PARALLEL_PROCESSES:-2} @additional_args"
]
```

- [ ] **Step 4: Update TESTING.md**

Document:

```bash
ORBIT_E2E_INCUS_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3
ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=7
ORBIT_E2E_SLOT_WAIT_SECONDS=900
ORBIT_E2E_SLOT_STALE_SECONDS=7200
```

Explain:

- `--processes` is maximum concurrency;
- host slots are actual capacity;
- busy slots queue;
- stale leases are reclaimed;
- Docker and Incus use separate slot pools.

- [ ] **Step 5: Run script tests**

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php
composer validate --strict --no-check-publish
```

Expected: pass.

## Task 6: Real Verification

**Files:**
- No code changes.

- [ ] **Step 1: Format PHP changes**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: pass.

- [ ] **Step 2: Run focused harness suite**

Run:

```bash
php artisan test --compact \
  tests/Feature/E2EResourceLeasePoolTest.php \
  tests/Feature/E2EConfigTest.php \
  tests/Feature/DockerTopologyProviderTest.php \
  tests/Feature/E2EProviderPoolTest.php \
  tests/Feature/VerificationScriptsTest.php
```

Expected: pass.

- [ ] **Step 3: Run Docker feature E2E with pooled slots**

Run:

```bash
ORBIT_E2E_DOCKER_HOST_SLOTS=sidecar1:2,sidecar2:2,beast:3 \
ORBIT_E2E_PARALLEL_PROCESSES=7 \
composer test:e2e
```

Expected: pass. While running, sample:

```bash
for h in sidecar1 sidecar2 beast; do
  echo "$h"
  DOCKER_HOST=ssh://$h docker ps --format '{{.Names}} {{.Networks}}' | rg '^orbit-e2e-' || true
done
```

Expected: containers appear on any free Docker host slots; distribution does not need to match worker numbers exactly.

- [ ] **Step 4: Run a narrow provisioning E2E with pooled Incus slots**

Run a safe single provisioning test first:

```bash
ORBIT_E2E_INCUS_HOST_SLOTS=sidecar1:1,sidecar2:1 \
ORBIT_E2E_PROVISION_PARALLEL_PROCESSES=2 \
composer test:e2e:provision -- --filter='blank VM lifecycle'
```

Expected: pass or skip only if the required Incus images are genuinely unavailable. It must not skip because all slots are busy; it must wait until a lease is available.

- [ ] **Step 5: Run cleanup checks**

Run:

```bash
for h in sidecar1 sidecar2 beast; do
  echo "$h"
  DOCKER_HOST=ssh://$h docker ps --format '{{.Names}} {{.Status}} {{.Networks}}' | rg '^orbit-e2e-' || true
done

composer e2e:reap-incus
composer e2e:reap-docker
```

Expected: no unexpected stale resources.

## Task 7: Stop Before Hetzner

**Files:**
- No code changes.

- [ ] **Step 1: Confirm no Hetzner runner work was added**

Run:

```bash
git diff --name-only | rg 'Hcloud|Hetzner|hcloud' || true
```

Expected: no new Hetzner Docker runner implementation files. Existing HCloud provisioning code may appear only if it was already touched before this plan; do not add new Hetzner behavior in this pass.

- [ ] **Step 2: Final quality checks**

Run:

```bash
composer validate --strict --no-check-publish
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/E2EResourceLeasePoolTest.php tests/Feature/E2EConfigTest.php tests/Feature/DockerTopologyProviderTest.php tests/Feature/E2EProviderPoolTest.php tests/Feature/VerificationScriptsTest.php
```

Expected: pass.

- [ ] **Step 3: Merge boundary**

If this work was done in a worktree or feature branch, merge it into `main` after verification. If the work was done directly on `main`, report that no merge is needed because the checkout is already `main`.

Do not start Docker-on-Hetzner or HCloud elastic slot work until after this boundary is complete.

## Self-Review

- Spec coverage: Docker slot pooling, Incus slot pooling, blocking queue behavior, stale lease cleanup, parallel provisioning, and the Hetzner stop boundary are all covered by tasks.
- Placeholder scan: No `TODO`, `TBD`, or deferred implementation placeholders remain.
- Type consistency: The plan consistently uses `E2EResourceLease`, `E2EResourceLeasePool`, `dockerHostSlots`, `incusHostSlots`, `slotWaitSeconds`, and `slotStaleSeconds`.
