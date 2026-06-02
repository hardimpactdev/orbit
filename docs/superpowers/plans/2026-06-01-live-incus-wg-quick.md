# Live Incus wg-quick Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer e2e:incus -- --live --topology=<topology>` acquire a retained Incus topology, start a local disposable `wg-quick` tunnel, add the local Orbit gateway, and verify access.

**Architecture:** Keep the orchestration in `E2EIncusCommand`, but move host-local shell calls behind a small `LiveIncusLocalMachine` boundary so tests never mutate the developer machine. Store live local metadata in the retained topology manifest so `composer e2e:incus -- --stop` can tear down the matching `wg-quick` tunnel before releasing Incus instances. Add a compact E2E-local step tree renderer instead of importing gateway internals.

**Tech Stack:** Laravel 13 console command in `apps/e2e`, Pest 4, Laravel Process facade, `wg`, `wg-quick`, local Orbit CLI.

---

## File Map

- Modify `apps/e2e/app/Console/Commands/E2EIncusCommand.php`: add `--manual`, default local setup, local cleanup on stop, manifest live metadata, progress tree output, JSON payload changes.
- Create `apps/e2e/app/E2E/Support/LiveIncusLocalMachine.php`: process boundary for `wg`, `wg-quick`, `orbit gateway:add`, and gateway API verification.
- Create `apps/e2e/app/E2E/Support/LiveIncusStepTree.php`: small renderer for human `--live` progress tree labels.
- Modify `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`: cover default setup, manual mode, missing local tools, local tunnel cleanup, progress labels, and JSON shape.
- Modify `apps/docs/content/testing/e2e/prepared-topologies.md`: document automatic `wg-quick` setup, `--manual`, listing, and stop behavior.
- Modify `apps/docs/content/testing/e2e/environment.md`: keep the endpoint environment variable documented for LAN access.

---

### Task 1: Add Local Machine Boundary

**Files:**
- Create: `apps/e2e/app/E2E/Support/LiveIncusLocalMachine.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Add failing coverage through command fakes**

Add a fake local machine helper near the existing fake Incus host helpers:

```php
function recordingLiveIncusLocalMachine(ArrayObject $log, bool $toolsAvailable = true): object
{
    return new class($log, $toolsAvailable)
    {
        public function __construct(
            private readonly ArrayObject $log,
            private readonly bool $toolsAvailable,
        ) {}

        public function hasWireGuardTools(): bool
        {
            return $this->toolsAvailable;
        }

        public function wireGuardInterfaces(): array
        {
            return (array) ($this->log['interfaces'] ?? []);
        }

        public function realWireGuardInterface(string $interface): ?string
        {
            return $this->log['real'][$interface] ?? null;
        }

        public function startWireGuard(string $configPath): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "wg-quick up {$configPath}"];
            $this->log['interfaces'] = ['utun42'];
            $this->log['real']['oe2eabc123'] = 'utun42';

            return \Illuminate\Support\Facades\Process::result(output: '');
        }

        public function stopWireGuard(string $configPath): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "wg-quick down {$configPath}"];

            return \Illuminate\Support\Facades\Process::result(output: '');
        }

        public function addGateway(string $gatewayIp, string $gatewayName): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "orbit gateway:add {$gatewayIp} --name={$gatewayName} --json"];

            return \Illuminate\Support\Facades\Process::result(output: '{"success":{"gateway":{"name":"'.$gatewayName.'"}}}');
        }

        public function verifyGateway(string $gatewayIp): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->log['local_runs'] = [...($this->log['local_runs'] ?? []), "curl http://{$gatewayIp}/api/ca/root"];

            return \Illuminate\Support\Facades\Process::result(output: 'ok');
        }
    };
}
```

Add a command helper:

```php
function incusLiveCommandWithLocalMachine(ArrayObject $remoteLog, object $localMachine): void
{
    $command = app(E2EIncusCommand::class);
    $command->hostFactoryUsing(fn (string $host): IncusHost => recordingIncusLiveHost(incusReleaseConfig($host), $remoteLog));
    $command->localMachineUsing(fn (): object => $localMachine);
    app()->instance(E2EIncusCommand::class, $command);
}
```

- [ ] **Step 2: Run the focused test to confirm the helper does not compile yet**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='live accessible'
```

Expected: FAIL because `E2EIncusCommand::localMachineUsing()` does not exist.

- [ ] **Step 3: Create the local machine process boundary**

Create `apps/e2e/app/E2E/Support/LiveIncusLocalMachine.php`:

```php
<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class LiveIncusLocalMachine
{
    public function hasWireGuardTools(): bool
    {
        return Process::timeout(5)->run('command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1')->successful();
    }

    /**
     * @return list<string>
     */
    public function wireGuardInterfaces(): array
    {
        $result = Process::timeout(10)->run(['wg', 'show', 'interfaces']);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($result->output())) ?: []));
    }

    public function realWireGuardInterface(string $interface): ?string
    {
        $namePath = "/var/run/wireguard/{$interface}.name";

        if (! is_file($namePath)) {
            return in_array($interface, $this->wireGuardInterfaces(), true) ? $interface : null;
        }

        $realInterface = trim((string) file_get_contents($namePath));

        return $realInterface !== '' ? $realInterface : null;
    }

    public function startWireGuard(string $configPath): ProcessResult
    {
        return Process::timeout(180)->tty($this->hasTty())->run(['sudo', 'wg-quick', 'up', $configPath]);
    }

    public function stopWireGuard(string $configPath): ProcessResult
    {
        return Process::timeout(180)->tty($this->hasTty())->run(['sudo', 'wg-quick', 'down', $configPath]);
    }

    public function addGateway(string $gatewayIp, string $gatewayName): ProcessResult
    {
        return Process::timeout(120)->run(['orbit', 'gateway:add', $gatewayIp, "--name={$gatewayName}", '--json']);
    }

    public function verifyGateway(string $gatewayIp): ProcessResult
    {
        return Process::timeout(30)->run(['curl', '--fail', '--silent', '--show-error', '--max-time', '10', "http://{$gatewayIp}/api/ca/root"]);
    }

    private function hasTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
```

- [ ] **Step 4: Add the command injection hook**

In `E2EIncusCommand`, import `LiveIncusLocalMachine`, add a property, add `localMachineUsing()`, and a resolver:

```php
use App\E2E\Support\LiveIncusLocalMachine;

/** @var (Closure(): object)|null */
private ?Closure $localMachineFactory = null;

/**
 * @param  Closure(): object  $factory
 */
public function localMachineUsing(Closure $factory): void
{
    $this->localMachineFactory = $factory;
}

private function localMachine(): object
{
    return $this->localMachineFactory !== null
        ? ($this->localMachineFactory)()
        : new LiveIncusLocalMachine;
}
```

- [ ] **Step 5: Run the focused test**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='live accessible'
```

Expected: PASS when the fake is wired into the live flow, or FAIL for the missing local setup assertions until Task 2 is complete.

---

### Task 2: Start Local wg-quick Tunnel by Default

**Files:**
- Modify: `apps/e2e/app/Console/Commands/E2EIncusCommand.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Update the live JSON test to expect local setup**

In `it('creates a live accessible Incus topology and prints local onboarding instructions')`, use `incusLiveCommandWithLocalMachine()` and assert:

```php
expect($liveTopology['wireguard']['interface'])->toBe('oe2eabc123')
    ->and($liveTopology['wireguard']['real_interface'])->toBe('utun42')
    ->and($liveTopology['wireguard']['started'])->toBeTrue()
    ->and($liveTopology['wireguard']['gateway_added'])->toBeTrue()
    ->and($liveTopology['commands']['stop'])->toBe('composer e2e:incus -- --stop --id=dev-abc123')
    ->and($liveTopology['commands']['gateway_check'])->toBe('orbit node:list --json')
    ->and($log['local_runs'])->toContain("wg-quick up {$this->manifestDirectory}/oe2eabc123.conf")
    ->and($log['local_runs'])->toContain('orbit gateway:add 10.6.0.2 --name=incus-dev-abc123 --json')
    ->and($log['local_runs'])->toContain('curl http://10.6.0.2/api/ca/root');
```

Also assert the manifest stores live metadata:

```php
expect($manifest['live']['wireguard']['interface'])->toBe('oe2eabc123')
    ->and($manifest['live']['wireguard']['started'])->toBeTrue()
    ->and($manifest['live']['gateway']['name'])->toBe('incus-dev-abc123')
    ->and($manifest['live']['gateway']['added'])->toBeTrue();
```

- [ ] **Step 2: Run the focused test and verify failure**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='live accessible'
```

Expected: FAIL because live setup still only writes and prints the config.

- [ ] **Step 3: Add options and interface naming**

Extend the command signature:

```php
{--manual : Generate the live WireGuard config and instructions without starting wg-quick or adding the local gateway}
```

Add helper methods:

```php
private function wireGuardInterfaceName(string $topologyId): string
{
    $suffix = preg_replace('/^dev-/', '', strtolower($topologyId)) ?? $topologyId;
    $suffix = preg_replace('/[^a-z0-9]/', '', $suffix) ?? $suffix;
    $suffix = substr($suffix, 0, 10);

    if ($suffix === '') {
        throw new RuntimeException('Live Incus topology id must contain at least one letter or number for the WireGuard interface name.');
    }

    return "oe2e{$suffix}";
}
```

Change `writeWireGuardConfig()` to accept the interface name and write `<interface>.conf`:

```php
private function writeWireGuardConfig(string $interface, string $config): string
{
    $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());
    $directory = $store->directory();

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$this->fileName($interface).'.conf';
    $written = file_put_contents($path, $config);

    if ($written === false) {
        throw new RuntimeException("Could not write WireGuard config to [{$path}].");
    }

    chmod($path, 0600);

    return $path;
}
```

- [ ] **Step 4: Add local setup orchestration**

After writing the config in `live()`, call a new method unless `--manual` is set:

```php
$wireGuardInterface = $this->wireGuardInterfaceName($manifest['id']);
$wireGuardConfigPath = $this->writeWireGuardConfig($wireGuardInterface, $wireGuardConfig);
$localSetup = (bool) $this->option('manual')
    ? $this->manualLocalSetup($wireGuardInterface)
    : $this->startLocalSetup($wireGuardInterface, $wireGuardConfigPath, $manifest['gateway_ip'], $gatewayName);
```

Add:

```php
private function startLocalSetup(string $interface, string $configPath, string $gatewayIp, string $gatewayName): array
{
    $machine = $this->localMachine();

    if (! $machine->hasWireGuardTools()) {
        throw new RuntimeException('local_wireguard_unavailable: Install wg and wg-quick before running live Incus local setup, or pass --manual.');
    }

    $realInterface = $machine->realWireGuardInterface($interface);

    if ($realInterface === null) {
        $start = $machine->startWireGuard($configPath);

        if (! $start->successful()) {
            throw new RuntimeException('local_wireguard_failed: '.trim($start->errorOutput() ?: $start->output()));
        }

        $realInterface = $machine->realWireGuardInterface($interface);
    }

    $interfaces = $machine->wireGuardInterfaces();

    if ($realInterface === null || ! in_array($realInterface, $interfaces, true)) {
        throw new RuntimeException('local_wireguard_failed: wg-quick started but the tunnel was not visible through wg show interfaces.');
    }

    $gateway = $machine->addGateway($gatewayIp, $gatewayName);

    if (! $gateway->successful()) {
        throw new RuntimeException('local_gateway_failed: '.trim($gateway->errorOutput() ?: $gateway->output()));
    }

    $verify = $machine->verifyGateway($gatewayIp);

    if (! $verify->successful()) {
        throw new RuntimeException('gateway_unreachable: '.trim($verify->errorOutput() ?: $verify->output()));
    }

    return [
        'mode' => 'wg-quick',
        'started' => true,
        'gateway_added' => true,
        'real_interface' => $realInterface,
        'verified' => true,
    ];
}
```

Add:

```php
private function manualLocalSetup(string $interface): array
{
    return [
        'mode' => 'manual',
        'started' => false,
        'gateway_added' => false,
        'real_interface' => null,
        'verified' => false,
    ];
}
```

- [ ] **Step 5: Reshape payload and manifest live metadata**

Change `livePayload()` to include nested `wireguard` and `commands` keys while retaining legacy top-level fields for compatibility:

```php
'wireguard' => [
    'endpoint' => $endpoint,
    'interface' => $wireGuardInterface,
    'real_interface' => $localSetup['real_interface'],
    'config_path' => $wireGuardConfigPath,
    'started' => $localSetup['started'],
    'gateway_added' => $localSetup['gateway_added'],
    'verified' => $localSetup['verified'],
],
'commands' => [
    'stop' => $releaseCommand,
    'gateway_check' => 'orbit node:list --json',
],
```

After payload creation, persist:

```php
(E2EDevTopologyManifestStore::fromEnvironment(repo_path()))->write([
    ...$manifest,
    'live' => [
        'operator_node' => $operatorName,
        'wireguard' => $payload['wireguard'],
        'gateway' => [
            'name' => $gatewayName,
            'ip' => $manifest['gateway_ip'],
            'added' => $payload['wireguard']['gateway_added'],
        ],
    ],
]);
```

- [ ] **Step 6: Run the focused test**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='live accessible'
```

Expected: PASS.

---

### Task 3: Add Manual Mode and Local Failure Coverage

**Files:**
- Modify: `apps/e2e/app/Console/Commands/E2EIncusCommand.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Add manual mode test**

Add:

```php
it('can create a live topology in manual mode without mutating the local host', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');
    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWithLocalMachine($log, recordingLiveIncusLocalMachine($log));

    $output = new BufferedOutput;
    $exitCode = app(Kernel::class)->call('e2e:incus', [
        '--live' => true,
        '--manual' => true,
        '--json' => true,
    ], $output);

    $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    $liveTopology = $payload['success']['live_topology'];

    expect($exitCode)->toBe(0)
        ->and($liveTopology['wireguard']['started'])->toBeFalse()
        ->and($liveTopology['wireguard']['gateway_added'])->toBeFalse()
        ->and($log['local_runs'] ?? [])->toBe([]);
});
```

- [ ] **Step 2: Add missing tools failure test**

Add:

```php
it('fails live local setup before mutation when wg quick tooling is unavailable', function (): void {
    putenv('ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820');
    incusDevTopologyCommandWith(fn (E2ETopologyKind $kind, array $roles): array => fakeIncusPreparedTopology());

    $log = new ArrayObject(['runs' => [], 'local_runs' => []]);
    incusLiveCommandWithLocalMachine($log, recordingLiveIncusLocalMachine($log, toolsAvailable: false));

    $this->artisan('e2e:incus', ['--live' => true, '--json' => true])
        ->expectsOutputToContain('local_wireguard_unavailable')
        ->assertExitCode(1);

    expect($log['local_runs'] ?? [])->toBe([]);
});
```

- [ ] **Step 3: Improve error code rendering**

Replace the current broad `live_setup_failed` catch with a helper that extracts known local setup prefixes:

```php
private function renderLiveException(Throwable $exception, bool $json): int
{
    $message = $exception->getMessage();

    foreach (['local_wireguard_unavailable', 'local_wireguard_failed', 'local_gateway_failed', 'gateway_unreachable'] as $code) {
        if (str_starts_with($message, "{$code}:")) {
            return $this->renderError($code, trim(substr($message, strlen($code) + 1)), $json);
        }
    }

    return $this->renderError('live_setup_failed', $message, $json);
}
```

Use it in the `catch` block:

```php
} catch (Throwable $exception) {
    return $this->renderLiveException($exception, $json);
}
```

- [ ] **Step 4: Run the two new tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='manual mode|wg quick tooling'
```

Expected: PASS.

---

### Task 4: Stop Local Tunnel Before Releasing Topology

**Files:**
- Modify: `apps/e2e/app/Console/Commands/E2EIncusCommand.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Add stop cleanup test**

Add:

```php
it('stops a recorded live wg quick tunnel before releasing the topology', function (): void {
    writeIncusRetainedManifest($this->manifestDirectory, 'dev-abc123');

    $store = new E2EDevTopologyManifestStore($this->manifestDirectory);
    $manifest = $store->read('dev-abc123');
    $store->write([
        ...$manifest,
        'live' => [
            'wireguard' => [
                'interface' => 'oe2eabc123',
                'real_interface' => 'utun42',
                'config_path' => "{$this->manifestDirectory}/oe2eabc123.conf",
                'started' => true,
            ],
        ],
    ]);

    file_put_contents("{$this->manifestDirectory}/oe2eabc123.conf", '[Interface]');

    $log = new ArrayObject(['deleted' => [], 'runs' => [], 'local_runs' => [], 'interfaces' => ['utun42'], 'real' => ['oe2eabc123' => 'utun42']]);
    incusReleaseCommandWith($log);

    $command = app(E2EIncusCommand::class);
    $command->localMachineUsing(fn (): object => recordingLiveIncusLocalMachine($log));
    app()->instance(E2EIncusCommand::class, $command);

    $this->artisan('e2e:incus', [
        '--stop' => true,
        '--id' => 'dev-abc123',
        '--json' => true,
    ])->assertSuccessful();

    expect($log['local_runs'])->toContain("wg-quick down {$this->manifestDirectory}/oe2eabc123.conf")
        ->and($log['deleted'])->toHaveCount(1);
});
```

- [ ] **Step 2: Run the stop test and verify failure**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='stops a recorded live'
```

Expected: FAIL because stop only delegates to `e2e:dev-topology:release`.

- [ ] **Step 3: Add cleanup before delegated release**

In `E2EIncusCommand::stop()`, before `$this->call('e2e:dev-topology:release', $parameters)`, load the target manifests and clean them:

```php
$store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());

foreach ($this->manifestsForStop($store) as $manifest) {
    $this->stopLocalTunnelIfRecorded($manifest);
}
```

Add:

```php
private function manifestsForStop(E2EDevTopologyManifestStore $store): array
{
    if ((bool) $this->option('all')) {
        return $store->list();
    }

    $id = $this->stringOption('id');

    if ($id === null || $id === 'dry-run') {
        return [];
    }

    $manifest = $store->read($id);

    return $manifest === null ? [] : [$manifest];
}

private function stopLocalTunnelIfRecorded(array $manifest): void
{
    $wireguard = $manifest['live']['wireguard'] ?? null;

    if (! is_array($wireguard) || ($wireguard['started'] ?? false) !== true) {
        return;
    }

    $interface = is_string($wireguard['interface'] ?? null) ? $wireguard['interface'] : null;
    $configPath = is_string($wireguard['config_path'] ?? null) ? $wireguard['config_path'] : null;

    if ($interface === null || $configPath === null) {
        return;
    }

    $machine = $this->localMachine();
    $realInterface = $machine->realWireGuardInterface($interface);

    if ($realInterface === null || ! in_array($realInterface, $machine->wireGuardInterfaces(), true)) {
        return;
    }

    $result = $machine->stopWireGuard($configPath);

    if (! $result->successful()) {
        throw new RuntimeException('local_wireguard_failed: '.trim($result->errorOutput() ?: $result->output()));
    }
}
```

Wrap local cleanup errors in `stop()` with `renderLiveException()`.

- [ ] **Step 4: Run stop tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='stops a retained|stops every retained|stops a recorded live'
```

Expected: PASS.

---

### Task 5: Add Progress Tree Human Output

**Files:**
- Create: `apps/e2e/app/E2E/Support/LiveIncusStepTree.php`
- Modify: `apps/e2e/app/Console/Commands/E2EIncusCommand.php`
- Test: `apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php`

- [ ] **Step 1: Add human output expectation**

Update `it('prints the live WireGuard config and follow-up gateway commands in human mode')` to expect tree labels:

```php
->expectsOutputToContain('Preparing live Incus topology')
->expectsOutputToContain('Validate live endpoint')
->expectsOutputToContain('Acquire topology')
->expectsOutputToContain('Mint local operator identity')
->expectsOutputToContain('Write WireGuard config')
->expectsOutputToContain('Start local tunnel')
->expectsOutputToContain('Add local gateway')
->expectsOutputToContain('Verify gateway API')
```

- [ ] **Step 2: Create a minimal renderer**

Create `apps/e2e/app/E2E/Support/LiveIncusStepTree.php`:

```php
<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class LiveIncusStepTree
{
    /**
     * @param  list<string>  $steps
     */
    public function renderInitial(OutputInterface $output, string $title, array $steps): void
    {
        $output->writeln("  ┌  {$title}");

        foreach ($steps as $step) {
            $output->writeln('  │');
            $output->writeln("  ○  {$step}");
        }

        $output->writeln('  │');
        $output->writeln('  └  Working...');
    }

    public function renderDone(OutputInterface $output, string $footer): void
    {
        $output->writeln("  └  {$footer}");
    }
}
```

- [ ] **Step 3: Render the tree in human live mode**

In `live()`, before side effects when `$json === false`, render:

```php
$tree = new LiveIncusStepTree;
$steps = [
    'Validate live endpoint',
    'Acquire topology',
    'Mint local operator identity',
    'Write WireGuard config',
    'Start local tunnel',
    'Add local gateway',
    'Verify gateway API',
];

if ($json === false) {
    $tree->renderInitial($this->output, 'Preparing live Incus topology', $steps);
}
```

At the end of `renderLive()`, for human mode, print:

```php
$this->line("Live Incus topology [{$payload['id']}] is ready.");
```

Keep the renderer simple for this pass; the product labels are the contract under test.

- [ ] **Step 4: Run the human mode test**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php --filter='prints the live WireGuard config'
```

Expected: PASS.

---

### Task 6: Update E2E Documentation

**Files:**
- Modify: `apps/docs/content/testing/e2e/prepared-topologies.md`
- Modify: `apps/docs/content/testing/e2e/environment.md`

- [ ] **Step 1: Update retained topology live section**

Replace the old follow-up text in `prepared-topologies.md` with:

```markdown
`composer e2e:incus -- --live --topology=<topology>` builds on the same retained
topology acquisition, mints an additional operator identity, writes a local
`wg-quick` config, starts that tunnel, adds `incus-<id>` as the local active
gateway, and verifies the gateway API through the tunnel.

Use `--manual` to stop after writing the WireGuard config and print the
follow-up commands instead of mutating local network and gateway state.

Live E2E tunnels use short `oe2e<id>` `wg-quick` config names. On macOS,
`wg-quick` maps those logical names to `utun*`; inspect active WireGuard
interfaces with:

```bash
wg show interfaces
```

Release the topology with `composer e2e:incus -- --stop --id=<id>`. If the live
command started a local `wg-quick` tunnel, the stop command brings that tunnel
down before reaping the Incus VMs.
```

- [ ] **Step 2: Confirm environment doc mentions endpoint**

Ensure `apps/docs/content/testing/e2e/environment.md` includes:

```markdown
`ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT` is the public or LAN endpoint written into
local live topology WireGuard configs. For a trusted LAN Incus host:

```bash
ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT=192.168.1.150:51820
```
```

- [ ] **Step 3: Run docs lint**

Run:

```bash
composer docs-lint
```

Expected: PASS.

---

### Task 7: Final Verification and Commit

**Files:**
- Modify only the files listed in this plan.

- [ ] **Step 1: Run focused E2E command tests**

Run:

```bash
cd apps/e2e && vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php
```

Expected: PASS.

- [ ] **Step 2: Format PHP**

Run:

```bash
apps/e2e/vendor/bin/pint --dirty --format agent
```

Expected: PASS with no formatting errors.

- [ ] **Step 3: Run broad quality check**

Run:

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 4: Review diff**

Run:

```bash
git diff --check
git diff --stat
```

Expected: no whitespace errors; diff is scoped to E2E command, tests, and docs.

- [ ] **Step 5: Commit implementation**

Run:

```bash
git add apps/e2e/app/Console/Commands/E2EIncusCommand.php \
    apps/e2e/app/E2E/Support/LiveIncusLocalMachine.php \
    apps/e2e/app/E2E/Support/LiveIncusStepTree.php \
    apps/e2e/tests/Feature/E2ESupport/Commands/E2EIncusCommandTest.php \
    apps/docs/content/testing/e2e/prepared-topologies.md \
    apps/docs/content/testing/e2e/environment.md
git commit -m "feat(e2e): automate live Incus wg-quick setup"
```

Expected: one focused implementation commit.

## Open Questions

None. The approved design chooses `wg-quick` only; WireGuard.app integration remains out of scope.
