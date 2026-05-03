# E2E Fast Suite Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the E2E runtime-speed work so the feature E2E suite is fast, reset-safe, and able to use `beast`, `sidecar1`, and `sidecar2` without making test results less trustworthy.

**Architecture:** Keep the two-lane model: in-memory Pest remains the default fast lane, and ephemeral VM Pest E2E remains the real infrastructure lane. Harden topology lease reset semantics first, then reduce unnecessary SSH setup, then reconcile parallel execution scripts and run real timing checks. Do not edit parallelization work that is still in progress by another agent; recheck landed code before changing scripts.

**Tech Stack:** Laravel 13, Pest 4, Composer scripts, Incus VMs on `beast`, `sidecar1`, and `sidecar2`, existing `tests/E2E/Support` topology support classes.

---

## Current Known Gaps

- `fresh-clone` reset is the default but does not re-run the full post-clone preparation path after rebuilding clones.
- `snapshot-restore` exists, but the fresh-clone path and snapshot path are not represented by one coherent lease-state model.
- `composer test:e2e:features:parallel` may be in progress in a parallel agent. Reconcile before editing `composer.json`.
- `withSshUsers(...)`-style selective SSH setup from the runtime-speed plan is not landed.
- Timing docs mention copy/start phases, while the current implementation records one combined `batch.copy-start` phase.
- Any `GET /api/nodes` support in the E2E gateway fixture must preserve query filters before it can validate forwarded `node:list` variants.

## Coordination Rules

- Treat uncommitted changes in `tests/E2E/NodeListTopologyTest.php` and `tests/E2E/Support/E2EGatewayApi.php` as another agent's work unless assigned otherwise.
- Do not change `composer.json` parallel scripts until Task 0 confirms the parallelization agent's landed state.
- Run `git status --short --branch --untracked-files=all` before every task and avoid editing files with unrelated uncommitted changes.
- Prefer direct `php artisan test --testsuite=E2E --list-tests` for dry selection checks. Do not use `composer test:e2e:features -- --list-tests`, because the contract command intentionally strips additional arguments and will start real VMs.

---

## Task 0: Reconcile Landed Parallelization Work

**Files:**

- Inspect: `composer.json`
- Inspect: `TESTING.md`
- Inspect: `tests/E2E/NodeListTopologyTest.php`
- Inspect: `tests/E2E/Support/E2EGatewayApi.php`
- Inspect: `tests/E2E/Support/E2ETopologyFactory.php`
- Inspect: `tests/E2E/Support/E2ETopologyLease.php`

- [ ] Check the current branch and uncommitted work.

Run:

```bash
git status --short --branch --untracked-files=all
git log --oneline --decorate -8
```

Expected:

- no unrelated uncommitted files owned by another active agent are edited in this task;
- recent commits show whether parallelization work has landed.

- [ ] Check which runtime-speed tasks are present.

Run:

```bash
rg -n "features:parallel|--parallel|processes=3|withSshUsers|ORBIT_E2E_TIMINGS|batch.copy-start|lease-clean|snapshot-restore|runningE2EInstanceCount" composer.json TESTING.md tests/E2E tests/Feature
```

Expected:

- record whether `test:e2e:features:parallel` exists;
- record whether `withSshUsers` exists;
- record whether gateway fixture `GET /api/nodes` support exists.

- [ ] Run safe selection checks without launching VMs.

Run:

```bash
ORBIT_E2E=1 php artisan test --testsuite=E2E --list-groups
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-topology-contract --list-tests
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --exclude-group=e2e-topology-contract --list-tests
```

Expected:

- topology contract groups list one test per supported topology;
- feature groups list only feature tests, not contract tests;
- no Incus VM is started.

- [ ] Confirm no stale E2E VM was created during inspection.

Run:

```bash
php artisan e2e:reap-incus --older-than=0m --json
```

Expected:

- `resources` is empty, or every listed resource is known from another active real E2E run.

Commit:

```bash
git add docs/superpowers/plans/2026-05-04-e2e-fast-suite-hardening.md
git commit -m "docs: add e2e fast suite hardening plan"
```

---

## Task 1: Make Fresh-Clone Reset Fully Rehydrate Topology State

**Files:**

- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Test: `tests/Feature/E2ETopologyResetTest.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`

- [ ] Write a failing test that proves fresh-clone reset replaces raw clones with prepared clones.

Add this test to `tests/Feature/E2ETopologyResetTest.php`:

```php
it('fresh-clone reset uses a prepared rebuild state', function (): void {
    $oldControl = m::mock(E2EInstance::class);
    $oldControl->shouldReceive('delete')->once();

    $newControl = m::mock(E2EInstance::class);

    $prepared = false;
    $rebuild = function () use ($newControl, &$prepared): array {
        $prepared = true;

        return [
            'instances' => [
                'control' => $newControl,
            ],
            'snapshotReset' => null,
        ];
    };

    $lease = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $oldControl,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/fake', '/tmp/fake.pub'),
        rebuild: $rebuild,
    );

    $lease->reset();

    expect($prepared)->toBeTrue()
        ->and($lease->control())->toBe($newControl);
});
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php --filter='fresh-clone reset uses a prepared rebuild state'
```

Expected: fail because `E2ETopologyLease::reset()` still expects the rebuild closure to return raw instances.

- [ ] Update `E2ETopologyLease` to accept prepared rebuild payloads and refresh the snapshot-reset closure.

In `tests/E2E/Support/E2ETopologyLease.php`, change the rebuild PHPDoc and make `$snapshotReset` mutable:

```php
/**
 * @param  \Closure(E2EPhaseTimer): array{
 *     instances: array<string, E2EInstance>,
 *     snapshotReset: \Closure(E2EPhaseTimer): void|null
 * }  $rebuild
 * @param  \Closure(E2EPhaseTimer): void|null  $snapshotReset
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
) {}
```

Update the fresh-clone branch in `reset()`:

```php
$this->cleanup($timer);
$payload = ($this->rebuild)($timer);
$instances = $payload['instances'];

$this->control = $instances['control'];
$this->gateway = $instances['gateway'] ?? null;
$this->dev = $instances['dev'] ?? null;
$this->prod = $instances['prod'] ?? null;
$this->snapshotReset = $payload['snapshotReset'];
$this->cleaned = false;
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php
```

Expected: reset tests pass after existing tests are updated to return the prepared payload shape.

- [ ] Update existing reset tests to use prepared payloads.

Replace raw rebuild returns like this:

```php
$rebuild = fn (): array => [
    'instances' => [
        'control' => $newControl,
    ],
    'snapshotReset' => null,
];
```

For full topology tests, use:

```php
$rebuild = fn (): array => [
    'instances' => [
        'control' => $newControl,
        'gateway' => $newGateway,
        'dev' => $newDev,
        'prod' => $newProd,
    ],
    'snapshotReset' => null,
];
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php
```

Expected: all reset tests pass.

- [ ] Extract post-clone preparation in `E2ETopologyFactory` so initial acquire and fresh-clone rebuild use the same path.

In `tests/E2E/Support/E2ETopologyFactory.php`, add:

```php
/**
 * @param  array<string, IncusInstance>  $instances
 * @return array<string, string>
 */
private function prepareInstances(array $instances, E2EConfig $config, SshKeyPair $sshKeyPair, E2EPhaseTimer $timer): array
{
    $primaryUsers = [];

    foreach ($instances as $role => $instance) {
        $primaryUser = match ($role) {
            'control' => $config->controlUser,
            default => 'orbit',
        };

        $primaryUsers[$role] = $primaryUser;

        $timer->measure("ssh-authorize.{$role}", function () use ($instance, $config, $primaryUser, $sshKeyPair): void {
            $instance->authorizeSsh($config->bootstrapUser, $sshKeyPair);

            if ($primaryUser !== $config->bootstrapUser) {
                $instance->authorizeSsh($primaryUser, $sshKeyPair);
            }
        });

        $timer->measure("ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
        $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
    }

    $timer->measure('wireguard', fn () => $this->reestablishWireGuard($instances));

    return $primaryUsers;
}
```

Add a helper that creates the snapshot reset closure for the current instances:

```php
/**
 * @param  array<string, IncusInstance>  $instances
 * @param  array<string, string>  $primaryUsers
 */
private function snapshotResetFor(array $instances, array $primaryUsers, SshKeyPair $sshKeyPair): \Closure
{
    return function (E2EPhaseTimer $cycleTimer) use ($instances, $primaryUsers, $sshKeyPair): void {
        foreach ($instances as $role => $instance) {
            $cycleTimer->measure("reset.stop.{$role}", fn () => $instance->stop());
            $cycleTimer->measure("reset.restore.{$role}", fn () => $instance->restoreSnapshot('lease-clean'));
            $cycleTimer->measure("reset.start.{$role}", fn () => $instance->start());
        }

        foreach ($instances as $role => $instance) {
            $cycleTimer->measure("reset.agent-ready.{$role}", fn () => $instance->waitForAgent());
        }

        $cycleTimer->measure('reset.wireguard', fn () => $this->reestablishWireGuard($instances));

        foreach ($instances as $role => $instance) {
            $cycleTimer->measure("reset.ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUsers[$role], $sshKeyPair));
        }
    };
}
```

Use both helpers inside `require()`:

```php
$instances = IncusTopologyTemplate::clone($host, $resolved, $runId, $timer);
$sshKeyPair = $this->createSshKeyPair($host, $runId);
$primaryUsers = $this->prepareInstances($instances, $config, $sshKeyPair, $timer);
$snapshotReset = $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair);
```

Change the rebuild closure:

```php
$rebuild = function (E2EPhaseTimer $cycleTimer) use ($host, $resolved, $runId, $config, $sshKeyPair): array {
    $instances = IncusTopologyTemplate::clone($host, $resolved, $runId, $cycleTimer);
    $primaryUsers = $this->prepareInstances($instances, $config, $sshKeyPair, $cycleTimer);

    return [
        'instances' => $instances,
        'snapshotReset' => $this->snapshotResetFor($instances, $primaryUsers, $sshKeyPair),
    ];
};
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyResetTest.php tests/Feature/E2ETopologyFactoryTest.php
vendor/bin/pint --dirty --format agent
```

Expected: tests and Pint pass.

Commit:

```bash
git add tests/E2E/Support/E2ETopologyFactory.php tests/E2E/Support/E2ETopologyLease.php tests/Feature/E2ETopologyResetTest.php tests/Feature/E2ETopologyFactoryTest.php
git commit -m "test: rehydrate fresh-clone e2e resets"
```

---

## Task 2: Add Selective SSH Setup For Feature Topology Tests

**Files:**

- Modify: `tests/E2E/Support/E2ETopologyFactory.php`
- Modify: `tests/E2E/Support/E2ETopologyLease.php`
- Modify: `tests/E2E/NodeListTopologyTest.php`
- Test: `tests/Feature/E2ETopologyFactoryTest.php`

- [ ] Add a failing factory test for requested SSH users.

Add this test to `tests/Feature/E2ETopologyFactoryTest.php`:

```php
it('stores requested SSH users immutably', function (): void {
    $factory = E2ETopologyFactory::fromEnvironment();
    $controlOnly = $factory->withSshUsers(['control' => 'control']);

    expect($controlOnly)->not->toBe($factory)
        ->and((new ReflectionClass($controlOnly))->getProperty('sshUsers')->getValue($controlOnly))
        ->toBe(['control' => 'control'])
        ->and((new ReflectionClass($factory))->getProperty('sshUsers')->getValue($factory))
        ->toBeNull();
});
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php --filter='stores requested SSH users immutably'
```

Expected: fail because `withSshUsers()` does not exist yet.

- [ ] Add immutable SSH-user selection to `E2ETopologyFactory`.

Change the constructor:

```php
/**
 * @param  array<string, string>|null  $sshUsers
 */
private function __construct(
    private readonly string $provider,
    private readonly string $strategy,
    private readonly ?array $sshUsers = null,
) {}
```

Change `fromEnvironment()` to pass `sshUsers: null`.

Add:

```php
/**
 * @param  array<string, string>  $sshUsers
 */
public function withSshUsers(array $sshUsers): self
{
    return new self(
        provider: $this->provider,
        strategy: $this->strategy,
        sshUsers: $sshUsers,
    );
}
```

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php --filter='stores requested SSH users immutably'
```

Expected: test passes.

- [ ] Use selected SSH users during preparation while keeping default behavior unchanged.

Add:

```php
/**
 * @param  array<string, IncusInstance>  $instances
 * @return array<string, string>
 */
private function sshUsersFor(array $instances, E2EConfig $config): array
{
    if ($this->sshUsers !== null) {
        return $this->sshUsers;
    }

    $users = [];

    foreach (array_keys($instances) as $role) {
        $users[$role] = match ($role) {
            'control' => $config->controlUser,
            default => 'orbit',
        };
    }

    return $users;
}
```

In `prepareInstances()`, replace primary-user calculation with:

```php
$sshUsers = $this->sshUsersFor($instances, $config);

foreach ($sshUsers as $role => $primaryUser) {
    $instance = $instances[$role] ?? null;

    if ($instance === null) {
        continue;
    }

    $primaryUsers[$role] = $primaryUser;

    $timer->measure("ssh-authorize.{$role}", function () use ($instance, $config, $primaryUser, $sshKeyPair): void {
        $instance->authorizeSsh($config->bootstrapUser, $sshKeyPair);

        if ($primaryUser !== $config->bootstrapUser) {
            $instance->authorizeSsh($primaryUser, $sshKeyPair);
        }
    });

    $timer->measure("ssh-ready.{$role}", fn () => $instance->waitForSsh($primaryUser, $sshKeyPair));
}

foreach ($instances as $role => $instance) {
    $timer->measure("snapshot.{$role}", fn () => $instance->snapshot('lease-clean'));
}
```

Keep `snapshotResetFor()` waiting for SSH only on `$primaryUsers`.

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyResetTest.php
```

Expected: factory and reset tests pass.

- [ ] Update control-only feature E2E usage.

Change `tests/E2E/NodeListTopologyTest.php` acquisition to:

```php
$topology = E2ETopologyFactory::fromEnvironment()
    ->withSshUsers(['control' => 'control'])
    ->require(E2ETopologyKind::ControlGatewayDevProd);
```

Run:

```bash
php artisan test --compact tests/E2E/NodeListTopologyTest.php
ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature-control-gateway-dev-prod --exclude-group=e2e-topology-contract --list-tests
vendor/bin/pint --dirty --format agent
```

Expected:

- local E2E file is skipped without `ORBIT_E2E=1`;
- list-tests selects `NodeListTopologyTest`;
- Pint passes.

Commit:

```bash
git add tests/E2E/Support/E2ETopologyFactory.php tests/E2E/Support/E2ETopologyLease.php tests/E2E/NodeListTopologyTest.php tests/Feature/E2ETopologyFactoryTest.php tests/Feature/E2ETopologyResetTest.php
git commit -m "test: allow selective ssh setup for e2e topologies"
```

---

## Task 3: Align Timing Documentation With Actual Batch Phases

**Files:**

- Modify: `TESTING.md`
- Test: `tests/Feature/VerificationScriptsTest.php`

- [ ] Add a docs assertion for the actual timing event names.

Add this test to `tests/Feature/VerificationScriptsTest.php`:

```php
it('documents e2e topology timing event names', function (): void {
    $testing = file_get_contents(base_path('TESTING.md'));

    expect($testing)
        ->toContain('batch.copy-start')
        ->toContain('agent-ready.<role>')
        ->toContain('ssh-authorize.<role>')
        ->toContain('ssh-ready.<role>')
        ->toContain('snapshot.<role>')
        ->toContain('wireguard')
        ->toContain('cleanup.<role>');
});
```

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php --filter='documents e2e topology timing event names'
```

Expected: fail until `TESTING.md` uses the actual names.

- [ ] Update `TESTING.md` to describe the current timing output without claiming split copy/start timings.

Replace the timing paragraph with:

```markdown
Set `ORBIT_E2E_TIMINGS=1` to surface per-phase durations from the topology
factory and lease. Current event names include `availability`,
`batch.copy-start`, `agent-ready.<role>`, `ssh-authorize.<role>`,
`ssh-ready.<role>`, `snapshot.<role>`, `wireguard`, `cleanup.<role>`, and
`reset.*`. Output goes to STDERR with the prefix `[orbit-e2e]` so it interleaves
cleanly with Pest output. The clone/start batch intentionally stays one remote
SSH operation; split copy/start timing should only be added if it can keep that
single remote operation.
```

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php --filter='documents e2e topology timing event names'
composer docs-lint
```

Expected: test and docs lint pass.

Commit:

```bash
git add TESTING.md tests/Feature/VerificationScriptsTest.php
git commit -m "docs: align e2e timing event names"
```

---

## Task 4: Harden Gateway Fixture Node List Forwarding

**Files:**

- Modify: `tests/E2E/Support/E2EGatewayApi.php`
- Test: `tests/Feature/Commands/NodeListCommandTest.php`

- [ ] Recheck whether `GET /api/nodes` fixture support has already landed.

Run:

```bash
rg -n "GET /api/nodes|node:list --json" tests/E2E/Support/E2EGatewayApi.php
```

Expected:

- if no match exists, add the handler in this task;
- if a handler exists, verify it preserves `role` and `environment` query filters.

- [ ] Add query-preserving handler logic inside the gateway TLS test server script.

In `tests/E2E/Support/E2EGatewayApi.php`, inside the generated PHP server loop, use:

```php
if (str_starts_with($requestLine, 'GET /api/nodes ') || str_starts_with($requestLine, 'GET /api/nodes?')) {
    $path = explode(' ', $requestLine)[1] ?? '/api/nodes';
    $queryString = parse_url($path, PHP_URL_QUERY);
    $query = [];

    if (is_string($queryString)) {
        parse_str($queryString, $query);
    }

    $parts = ['cd /home/orbit/orbit && php artisan node:list --json'];

    foreach (['role', 'environment'] as $option) {
        $value = $query[$option] ?? null;

        if (is_string($value) && $value !== '') {
            $parts[] = "--{$option}=".escapeshellarg($value);
        }
    }

    $output = [];
    $exitCode = 0;
    exec(implode(' ', $parts).' 2>&1', $output, $exitCode);
    respond($connection, $exitCode === 0 ? 200 : 422, implode("\n", $output));
    fclose($connection);

    continue;
}
```

Run:

```bash
php -l tests/E2E/Support/E2EGatewayApi.php
php artisan test --compact tests/Feature/Commands/NodeListCommandTest.php
vendor/bin/pint --dirty --format agent
```

Expected: syntax check, node:list feature tests, and Pint pass.

Commit:

```bash
git add tests/E2E/Support/E2EGatewayApi.php tests/Feature/Commands/NodeListCommandTest.php
git commit -m "test: preserve node list filters in e2e gateway fixture"
```

---

## Task 5: Reconcile And Finish Parallel Feature E2E Script

**Files:**

- Modify: `composer.json`
- Modify: `TESTING.md`
- Test: `tests/Feature/VerificationScriptsTest.php`

- [ ] Check whether the parallelization agent already added the script.

Run:

```bash
rg -n "test:e2e:features:parallel|--parallel|processes=3" composer.json TESTING.md tests/Feature
```

Expected:

- if the script exists, compare it against the desired shape below;
- if the script is missing, add it in this task.

- [ ] Add or normalize the opt-in parallel script.

Use this script shape in `composer.json`:

```json
"test:e2e:features:parallel": [
    "Composer\\Config::disableProcessTimeout",
    "@test:e2e:topology-contract",
    "ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --exclude-group=e2e-topology-contract --parallel --processes=3"
]
```

This keeps topology contracts serial and trustworthy, then runs only feature assertions in parallel.

- [ ] Add docs for the parallel script.

Add this paragraph to `TESTING.md` near the E2E commands:

```markdown
`composer test:e2e:features:parallel` is opt-in. It runs topology contracts first,
then runs feature assertions with Pest parallel mode and `--processes=3`, which
maps naturally to `beast`, `sidecar1`, and `sidecar2` when prepared templates
and capacity are available. Provisioning E2E remains serial by default.
```

- [ ] Add a script visibility assertion.

Add this test to `tests/Feature/VerificationScriptsTest.php`:

```php
it('exposes opt-in parallel feature e2e after topology contracts', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e:features:parallel'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        '@test:e2e:topology-contract',
        'ORBIT_E2E=1 php artisan test --testsuite=E2E --group=e2e-feature --exclude-group=e2e-topology-contract --parallel --processes=3',
    ]);
});
```

Run:

```bash
php artisan test --help | rg -- '--parallel|processes'
php artisan test --compact tests/Feature/VerificationScriptsTest.php --filter='parallel feature e2e'
composer validate --no-check-publish
composer docs-lint
```

Expected:

- help output includes `--parallel`;
- the verification script test passes;
- Composer validates;
- docs lint passes.

Commit:

```bash
git add composer.json TESTING.md tests/Feature/VerificationScriptsTest.php
git commit -m "test: add opt-in parallel feature e2e script"
```

---

## Task 6: Real E2E Timing And Capacity Validation

**Files:**

- Modify: `TESTING.md`
- Optional test evidence file: none

- [ ] Confirm host pool is clean enough to run the suite.

Run:

```bash
php artisan e2e:reap-incus --older-than=0m --json
```

Expected:

- no stale `orbit-e2e-*` resources are listed;
- prepared `orbit-template-*` resources may appear in `skipped`.

- [ ] Run the serial feature lane with timings.

Run:

```bash
ORBIT_E2E=1 \
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2 \
ORBIT_E2E_TIMINGS=1 \
composer test:e2e:features
```

Expected:

- topology contract passes;
- full-topology feature tests pass;
- timing lines with `[orbit-e2e]` are printed.

- [ ] Run the opt-in parallel feature lane with timings.

Run:

```bash
ORBIT_E2E=1 \
ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2 \
ORBIT_E2E_TIMINGS=1 \
composer test:e2e:features:parallel
```

Expected:

- topology contracts pass before parallel feature assertions start;
- feature assertions pass;
- host pool does not exceed `ORBIT_E2E_INCUS_MAX_VMS_PER_HOST`.

- [ ] Reap and verify no disposable instances remain.

Run:

```bash
php artisan e2e:reap-incus --older-than=0m --json
```

Expected:

- `resources` is empty after successful cleanup;
- if resources remain from an interrupted run, delete them with `php artisan e2e:reap-incus --older-than=0m --force --json`.

- [ ] Record measured runtime guidance in `TESTING.md`.

Add a short note with the observed serial and parallel durations:

```markdown
Latest local timing check:

- serial feature E2E: `<duration>` with `ORBIT_E2E_INCUS_HOSTS=beast,sidecar1,sidecar2`;
- parallel feature E2E: `<duration>` with `--processes=3`;
- cleanup check: no stale `orbit-e2e-*` clones remained.
```

Replace `<duration>` with the measured wall-clock values from the commands above before committing.

Run:

```bash
composer docs-lint
```

Expected: docs lint passes.

Commit:

```bash
git add TESTING.md
git commit -m "docs: record e2e runtime timing baseline"
```

---

## Success Criteria

- `fresh-clone` reset returns fully prepared clones with SSH readiness, snapshots, WireGuard routes, and gateway API state restored.
- `snapshot-restore` keeps same handles and waits only for roles that need SSH.
- Read-only feature E2E can request control-only SSH setup.
- Timing docs match actual emitted phase names.
- Gateway fixture `GET /api/nodes` preserves `role` and `environment` filters.
- Opt-in parallel feature E2E runs contracts first, then feature assertions in parallel across available Incus capacity.
- Real serial and parallel feature E2E runs pass and leave no disposable VM residue.

## Open Questions

- Should `composer test:e2e:features:parallel` run all topology contracts first, or only contracts for topology feature groups that currently have tests?
- Should timing baselines live in `TESTING.md`, or should repeated benchmark history move to a separate E2E operations note after the first measured run?
