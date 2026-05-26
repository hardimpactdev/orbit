# E2E Runtime Speed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make ephemeral VM Pest E2E complete faster after topology templates exist, without weakening the real-VM coverage.

**Architecture:** Keep in-memory Pest as the fast default lane and ephemeral VM Pest E2E as the real infrastructure lane. Optimize E2E by measuring phase timings, using smaller runtime VM resource profiles, placing leases across the Incus host pool, starting topology roles concurrently, and resetting acquired topologies through snapshots when a test needs multiple clean states.

**Tech Stack:** Laravel 13, Pest 4, Incus VMs on `beast`, `sidecar1`, and `sidecar2`, existing `tests/E2E/Support` topology and provider classes.

---

## CPU And Memory Decision

Use 1 vCPU for prepared feature-topology clones by default. That is useful for parallelization because most feature E2E work is SSH, SQLite, command execution, small API calls, and readiness polling rather than sustained CPU work. On modern `beast`, `sidecar1`, and `sidecar2` hardware, more 1-vCPU VMs will usually complete a feature suite faster than fewer 2-vCPU VMs.

Do not blindly lower every E2E VM to 1 vCPU:

- Keep image preparation and provisioning E2E at the current default of `ORBIT_E2E_CPUS=2` and `ORBIT_E2E_MEMORY=2GiB` until timings prove otherwise. Installer/provisioning work can be CPU and package-manager bound.
- Add separate topology clone settings:
  - `ORBIT_E2E_TOPOLOGY_CPUS=1`
  - `ORBIT_E2E_TOPOLOGY_MEMORY=2GiB`
- Apply topology resource limits to disposable clones, not to reusable template instances unless a task explicitly verifies that copied instances inherit the intended limits.

If memory becomes the real bottleneck, reduce memory only after timing data shows it is safe. A 1-vCPU/2GiB feature clone is the conservative first step.

---

## Current State

Relevant files:

- `TESTING.md` documents `ORBIT_E2E_INCUS_HOSTS`, `ORBIT_E2E_INCUS_MAX_VMS_PER_HOST`, and `ORBIT_E2E_TOPOLOGY_RESET`.
- `tests/E2E/Support/E2EConfig.php` has one global `cpus`/`memory` pair used by runtime providers and image preparation.
- `tests/E2E/Support/IncusHostPool.php` chooses the first host that has templates; it does not enforce capacity.
- `tests/E2E/Support/IncusTopologyTemplate.php` copies, starts, and waits for topology roles sequentially.
- `tests/E2E/Support/E2ETopologyFactory.php` authorizes SSH and waits for SSH sequentially for every acquired instance.
- `tests/E2E/Support/E2ETopologyLease.php` supports `fresh-clone`; `snapshot-restore` is explicitly not implemented.
- `tests/E2E/Support/IncusHost.php` opens a fresh SSH process for every Incus operation.
- Pest supports `php artisan test --parallel`, so E2E feature tests can eventually run in parallel once host capacity and lease placement are safe.

---

## Task 1: Add E2E Phase Timing

**Files:**

- Create: `tests/E2E/Support/E2EPhaseTimer.php`
- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `tests/E2E/Support/IncusTopologyTemplate.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Test: `tests/Feature/E2EPhaseTimerTest.php`

- [ ] Create a small timing helper that records named phases and writes to STDERR only when `ORBIT_E2E_TIMINGS=1`.

```php
<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final class E2EPhaseTimer
{
    /** @var list<array{name: string, seconds: float}> */
    private array $events = [];

    public function measure(string $name, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $this->events[] = [
                'name' => $name,
                'seconds' => microtime(true) - $start,
            ];
        }
    }

    /**
     * @return list<array{name: string, seconds: float}>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function flush(string $label): void
    {
        if (getenv('ORBIT_E2E_TIMINGS') !== '1') {
            return;
        }

        foreach ($this->events as $event) {
            fwrite(STDERR, sprintf(
                "[orbit-e2e] %s %s %.3fs\n",
                $label,
                $event['name'],
                $event['seconds'],
            ));
        }
    }
}
```

- [ ] Add focused Pest coverage for `measure()`, `events()`, and disabled `flush()`.

Run:

```bash
php artisan test --compact tests/Feature/E2EPhaseTimerTest.php
```

Expected: the new timer tests pass.

- [ ] Wrap the following phases without changing behavior:
  - topology availability check;
  - clone/copy;
  - start;
  - agent readiness;
  - SSH authorization;
  - SSH readiness;
  - cleanup;
  - reset.

- [ ] Document `ORBIT_E2E_TIMINGS=1` in `TESTING.md`.

- [ ] Run:

```bash
php artisan test --compact tests/Feature/E2EPhaseTimerTest.php tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyResetTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

---

## Task 2: Split Topology Clone Resource Limits From Provisioning Limits

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/E2E/Support/IncusHost.php`
- Modify: `tests/E2E/Support/IncusTopologyTemplate.php`
- Modify: `TESTING.md`
- Test: `tests/Feature/E2EConfigTest.php`
- Test: `tests/Feature/IncusTopologyTemplateTest.php`

- [ ] Add `topologyCpus` and `topologyMemory` to `E2EConfig`.

Use these defaults:

```php
topologyCpus: self::envString('ORBIT_E2E_TOPOLOGY_CPUS', '1'),
topologyMemory: self::envString('ORBIT_E2E_TOPOLOGY_MEMORY', '2GiB'),
```

Keep existing `cpus` and `memory` defaults unchanged:

```php
cpus: self::envString('ORBIT_E2E_CPUS', '2'),
memory: self::envString('ORBIT_E2E_MEMORY', '2GiB'),
```

- [ ] Add an Incus host method for disposable clone limits:

```php
public function setInstanceLimits(string $name, string $cpus, string $memory): ProcessResult
{
    return $this->run(sprintf(
        'incus config set %s limits.cpu=%s limits.memory=%s',
        escapeshellarg($name),
        escapeshellarg($cpus),
        escapeshellarg($memory),
    ));
}
```

- [ ] In `IncusTopologyTemplate::clone()`, apply `topologyCpus` and `topologyMemory` after `incus copy` and before `incus start`.

- [ ] Add tests that prove:
  - default topology CPU is `1`;
  - default provisioning CPU remains `2`;
  - env overrides work independently;
  - topology clone limit commands run before start commands.

- [ ] Document the resource split in `TESTING.md`.

Run:

```bash
php artisan test --compact tests/Feature/E2EConfigTest.php tests/Feature/IncusTopologyTemplateTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

---

## Task 3: Enforce Incus Host Pool Capacity

**Files:**

- Modify: `tests/E2E/Support/E2EConfig.php`
- Modify: `tests/E2E/Support/IncusHost.php`
- Modify: `tests/E2E/Support/IncusHostPool.php`
- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `TESTING.md`
- Test: `tests/Feature/E2EProviderPoolTest.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`

- [ ] Add `incusMaxVmsPerHost` to `E2EConfig`.

```php
incusMaxVmsPerHost: self::envInt('ORBIT_E2E_INCUS_MAX_VMS_PER_HOST', 4),
```

- [ ] Add a host method that counts running Orbit-owned E2E instances.

Use the existing prefix so user VMs are not counted:

```php
public function runningE2EInstanceCount(): int
{
    $result = $this->run(sprintf(
        "incus list --format csv -c n,s | awk -F, '$1 ~ /^%s/ && $2 == \"RUNNING\" { count++ } END { print count + 0 }'",
        preg_quote($this->config->instancePrefix, '/'),
    ));

    if (! $result->successful()) {
        throw new \RuntimeException("Could not count running E2E instances on {$this->config->host}: {$result->errorOutput()}");
    }

    return (int) trim($result->output());
}
```

If shell quoting makes this brittle, replace the awk command with `incus list --format json` plus PHP JSON parsing in the test support layer.

- [ ] Update `firstAvailableFor()` to require enough free slots for all roles in the requested topology:

```php
$requiredSlots = count(IncusTopologyTemplate::rolesFor($kind));
$freeSlots = $host->config->incusMaxVmsPerHost - $host->runningE2EInstanceCount();
```

Select the first host that has all templates and `freeSlots >= requiredSlots`.

- [ ] Add tests for:
  - host skipped when templates are missing;
  - host skipped when capacity is insufficient;
  - next host selected when it has templates and capacity;
  - no host returns `null`.

- [ ] Document the capacity rule and the recommended default:

```bash
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2
ORBIT_E2E_INCUS_MAX_VMS_PER_HOST=4
```

Run:

```bash
php artisan test --compact tests/Feature/E2EProviderPoolTest.php tests/Feature/E2ETopologyFactoryTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

---

## Task 4: Batch Clone And Start Topology Roles

**Files:**

- Modify: `tests/E2E/Support/IncusHost.php`
- Modify: `tests/E2E/Support/IncusTopologyTemplate.php`
- Test: `tests/Feature/IncusTopologyTemplateTest.php`

- [ ] Replace per-role copy/start loops with one remote batch per topology:

```bash
set -euo pipefail
incus copy orbit-template-control-gateway-dev-prod-control orbit-e2e-<run>-control &
incus copy orbit-template-control-gateway-dev-prod-gateway orbit-e2e-<run>-gateway &
incus copy orbit-template-control-gateway-dev-prod-dev orbit-e2e-<run>-dev &
incus copy orbit-template-control-gateway-dev-prod-prod orbit-e2e-<run>-prod &
wait
incus config set orbit-e2e-<run>-control limits.cpu=1 limits.memory=2GiB
incus config set orbit-e2e-<run>-gateway limits.cpu=1 limits.memory=2GiB
incus config set orbit-e2e-<run>-dev limits.cpu=1 limits.memory=2GiB
incus config set orbit-e2e-<run>-prod limits.cpu=1 limits.memory=2GiB
incus start orbit-e2e-<run>-control &
incus start orbit-e2e-<run>-gateway &
incus start orbit-e2e-<run>-dev &
incus start orbit-e2e-<run>-prod &
wait
```

Generate this script from PHP using `escapeshellarg()` for every template and clone name.

- [ ] Keep agent readiness checks after all starts have been submitted. Sequential readiness checks are acceptable for the first pass because boot happens concurrently after batched starts.

- [ ] Add tests that assert:
  - copy commands appear before start commands;
  - starts are batched for all requested roles;
  - a failed batch throws a useful exception with the host error output.

Run:

```bash
php artisan test --compact tests/Feature/IncusTopologyTemplateTest.php
vendor/bin/pint --dirty --format agent
```

---

## Task 5: Implement Snapshot-Restore Reset

**Files:**

- Modify: `tests/E2E/Support/IncusHost.php`
- Modify: `tests/E2E/Support/IncusInstance.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `TESTING.md`
- Test: `tests/Feature/E2ETopologyResetTest.php`

- [ ] Add host methods:

```php
public function restoreSnapshot(string $name, string $snapshot): ProcessResult
{
    return $this->run(sprintf(
        'incus restore %s %s',
        escapeshellarg($name),
        escapeshellarg($snapshot),
    ));
}

public function deleteSnapshot(string $name, string $snapshot): ProcessResult
{
    return $this->run(sprintf(
        'incus snapshot delete %s %s >/dev/null 2>&1 || true',
        escapeshellarg($name),
        escapeshellarg($snapshot),
    ));
}
```

- [ ] After topology acquisition and SSH authorization, create a per-clone snapshot named `lease-clean`.

This snapshot must include the injected test SSH key, so reset does not need to re-authorize SSH.

- [ ] Implement `E2ETopologyLease::reset()` for `snapshot-restore`:
  - stop each instance;
  - restore `lease-clean`;
  - start each instance;
  - wait for Incus agent;
  - wait for SSH only on instances that the lease will use through SSH;
  - keep the same instance names and object handles.

- [ ] Keep `fresh-clone` as the default. Unknown values must continue to fall back to `fresh-clone`.

- [ ] Update the existing test that expects `snapshot-restore` to throw. It should now assert restore/start/wait methods are called.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php tests/Feature/E2ETopologyFactoryTest.php
composer docs-lint
vendor/bin/pint --dirty --format agent
```

---

## Task 6: Add Safe Parallel Feature E2E Script

**Files:**

- Modify: `composer.json`
- Modify: `TESTING.md`
- Test: no PHP test needed; verify with command help and dry invocation.

- [ ] Add a separate opt-in script for parallel feature E2E after host capacity is enforced:

```json
"test:e2e:features:parallel": [
    "Composer\\Config::disableProcessTimeout",
    "ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --parallel --processes=3"
]
```

- [ ] Document that `--processes=3` maps naturally to `beast`, `sidecar1`, and `sidecar2` when capacity is available.

- [ ] Do not parallelize provisioning E2E by default. Provisioning tests mutate blank/ready machines and should stay intentionally conservative unless a later plan adds explicit isolation.

Run:

```bash
php artisan test --help | rg -- '--parallel|processes'
composer docs-lint
```

Expected: help output shows parallel support and docs lint passes.

---

## Task 7: Reduce Unneeded SSH Setup For Feature Topology Tests

**Files:**

- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Modify: `tests/E2E/NodeListTopologyTest.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`

- [ ] Add a way for feature tests to declare whether they need host-to-VM SSH for all nodes, control only, or none.

Recommended first API:

```php
$topology = E2ETopologyFactory::fromEnvironment()
    ->withSshUsers(['control' => 'control'])
    ->require(E2ETopologyKind::ControlGatewayDevProd);
```

- [ ] Default to current behavior for compatibility.

- [ ] Update read-only topology tests that only run commands from the control node to authorize and wait for SSH on the control node only.

- [ ] Keep dedicated SSH behavior coverage in provisioning tests.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php
ORBIT_E2E=1 composer test:e2e:features -- --filter=NodeListTopology
vendor/bin/pint --dirty --format agent
```

---

## Recommended Execution Order

1. Task 1: timing, so every later change has measurable impact.
2. Task 2: 1-vCPU topology clones, because it is low risk and unlocks parallel headroom.
3. Task 3: host-pool capacity, because parallel E2E needs safe placement.
4. Task 4: batched clone/start, because it reduces full-topology acquisition time.
5. Task 5: snapshot-restore reset, because it helps tests that need multiple clean states from one lease.
6. Task 6: parallel feature script, because it should only land after capacity is enforced.
7. Task 7: selective SSH setup, because it is useful but should be driven by timing data.

---

## Success Criteria

- Feature E2E can acquire `control-gateway-dev-prod` with 1-vCPU disposable clones.
- Host placement uses `beast`, `sidecar1`, and `sidecar2` without exceeding configured capacity.
- Clone/start work for multi-node topologies is batched enough that all roles boot concurrently.
- `ORBIT_E2E_TOPOLOGY_RESET=snapshot-restore` works for acquired topology clones.
- `composer test:e2e:features:parallel` exists and is documented as opt-in.
- Timing output shows phase-level duration before and after optimizations.
- In-memory Pest remains the default fast lane; ephemeral VM Pest E2E remains opt-in.
