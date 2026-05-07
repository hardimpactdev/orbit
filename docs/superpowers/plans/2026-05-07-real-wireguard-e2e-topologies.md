# Real WireGuard E2E Topologies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every prepared E2E topology containing a gateway use a real `wg-orbit` WireGuard interface instead of synthetic routes on the provider interface.

**Architecture:** Gateway-capable prepared topologies become Incus-only. Incus gateway VMs run Docker plus `wg-easy`, each topology role installs a real `wg-orbit` config, and clone acquisition retargets endpoints to the current clone provider IP before returning the lease. Docker topology remains available only for non-gateway topology kinds.

**Tech Stack:** Laravel 13 console/test harness, Pest 4, Incus VMs, Docker inside Incus gateway VMs, `wg-easy`, `wireguard-tools`, `wg-quick`, UFW.

---

## File Structure

- Modify `app/E2E/Support/E2ETopologyProviderPool.php` to reject Docker for gateway topology kinds before availability checks.
- Modify `app/E2E/Support/E2ETopologyCapabilities.php` to expose a named real-WireGuard VM requirement if provider selection needs it later.
- Create `app/E2E/Support/E2EWireGuardMesh.php` to render WireGuard configs, install configs on topology roles, restart interfaces, and verify peer reachability.
- Create `app/E2E/Support/E2EWgEasyGateway.php` to install Docker when missing, start `wg-easy`, seed its SQLite state, and install the gateway host `wg-orbit` interface.
- Modify `app/E2E/Support/IncusTopologyBuilder.php` to install Docker on gateway templates and replace synthetic route setup with the real WireGuard mesh during template preparation.
- Modify `app/E2E/Support/IncusTopologyProvider.php` to retarget and verify the real WireGuard mesh after cloning/resetting.
- Modify `app/E2E/Support/E2ENetwork.php` only after all callers have moved off it; either delete it or leave it unused only for Docker non-gateway code if a caller remains.
- Modify `tests/E2E/PreparedTopologyContractTest.php` to assert real `wg-orbit` behavior in live Incus topology contracts.
- Modify `tests/E2E/FirewallDoctorAdoptTest.php` to remove synthetic-network SSH allowances and assert UFW behavior through `wg-orbit`.
- Modify `composer.json`, `docs/porting/testing-infrastructure.md`, and affected docs/tests that currently present Docker as gateway-capable.

## Task 1: Reject Docker For Gateway Topologies

**Files:**
- Modify: `app/E2E/Support/E2ETopologyProviderPool.php`
- Test: `tests/Feature/E2ETopologyProviderPoolTest.php`

- [ ] **Step 1: Write the failing provider-selection test**

Add this test before `it('can create a docker topology provider from environment config'...)`:

```php
it('rejects docker for gateway topology kinds', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
        fakeTopologyProvider('incus', true, E2ETopologyCapabilities::vm()),
    ]);

    $selection = $pool->select(E2ETopologyKind::ControlGatewayDev);

    expect($selection->available())->toBeTrue()
        ->and($selection->provider()->name())->toBe('incus')
        ->and($selection->message)->toContain('incus:');
});

it('reports docker gateway topology rejection when no incus provider is available', function (): void {
    $pool = new E2ETopologyProviderPool([
        fakeTopologyProvider('docker', true, E2ETopologyCapabilities::containerFeature()),
    ]);

    $selection = $pool->select(E2ETopologyKind::ControlGateway);

    expect($selection->available())->toBeFalse()
        ->and($selection->message)->toContain('docker: gateway topologies require Incus with real WireGuard');
});
```

- [ ] **Step 2: Run the failing test**

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyProviderPoolTest.php --filter='gateway topology'
```

Expected: FAIL because Docker is still selected or reported as available.

- [ ] **Step 3: Implement provider rejection**

In `E2ETopologyProviderPool::select()`, add the gateway-kind guard before capability checks:

```php
foreach ($this->providers as $provider) {
    if ($provider->name() === 'docker' && $this->kindRequiresRealWireGuard($kind)) {
        $failures[] = 'docker: gateway topologies require Incus with real WireGuard';

        continue;
    }

    if ($required !== null && ! $provider->capabilities()->satisfies($required)) {
        $failures[] = "{$provider->name()}: capabilities do not satisfy required";

        continue;
    }

    // existing availability logic
}
```

Add this private method:

```php
private function kindRequiresRealWireGuard(E2ETopologyKind $kind): bool
{
    return in_array($kind, [
        E2ETopologyKind::ControlGateway,
        E2ETopologyKind::ControlGatewayDev,
        E2ETopologyKind::ControlGatewayDevProd,
    ], true);
}
```

- [ ] **Step 4: Verify provider tests pass**

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyProviderPoolTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/E2E/Support/E2ETopologyProviderPool.php tests/Feature/E2ETopologyProviderPoolTest.php
git commit -m "Require Incus for gateway E2E topologies"
```

## Task 2: Add Real WireGuard Mesh Support

**Files:**
- Create: `app/E2E/Support/E2EWireGuardMesh.php`
- Test: `tests/Feature/E2EWireGuardMeshTest.php`

- [ ] **Step 1: Write failing config-rendering tests**

Create `tests/Feature/E2EWireGuardMeshTest.php`:

```php
<?php

declare(strict_types=1);

use App\E2E\Support\E2EWireGuardMesh;

it('renders a gateway host config with app and control peers', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        gatewayPrivateKey: 'gateway-private',
        gatewayPublicKey: 'gateway-public',
        controlPrivateKey: 'control-private',
        controlPublicKey: 'control-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $config = $mesh->gatewayHostConfig();

    expect($config)->toContain('Address = 10.6.0.2/24')
        ->and($config)->toContain('ListenPort = 51820')
        ->and($config)->toContain('PublicKey = control-public')
        ->and($config)->toContain('AllowedIPs = 10.6.0.3/32')
        ->and($config)->toContain('PublicKey = dev-public')
        ->and($config)->toContain('AllowedIPs = 10.6.0.4/32');
});

it('renders a peer config that points at the gateway provider endpoint', function (): void {
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        gatewayPrivateKey: 'gateway-private',
        gatewayPublicKey: 'gateway-public',
        controlPrivateKey: 'control-private',
        controlPublicKey: 'control-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $config = $mesh->peerConfig(role: 'dev');

    expect($config)->toContain('Address = 10.6.0.4/24')
        ->and($config)->toContain('PublicKey = gateway-public')
        ->and($config)->toContain('AllowedIPs = 10.6.0.0/24')
        ->and($config)->toContain('Endpoint = 10.231.0.11:51820')
        ->and($config)->toContain('PersistentKeepalive = 25');
});
```

- [ ] **Step 2: Run the failing tests**

Run:

```bash
php artisan test --compact tests/Feature/E2EWireGuardMeshTest.php
```

Expected: FAIL because `E2EWireGuardMesh` does not exist.

- [ ] **Step 3: Create the mesh support class**

Create `app/E2E/Support/E2EWireGuardMesh.php`:

```php
<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

final readonly class E2EWireGuardMesh
{
    /**
     * @param  array<string, array{address: string, private_key: string, public_key: string}>  $roles
     */
    public function __construct(
        private string $gatewayProviderIp,
        private array $roles,
    ) {}

    public static function standard(
        string $gatewayProviderIp,
        string $gatewayPrivateKey,
        string $gatewayPublicKey,
        string $controlPrivateKey,
        string $controlPublicKey,
        ?string $devPrivateKey = null,
        ?string $devPublicKey = null,
        ?string $prodPrivateKey = null,
        ?string $prodPublicKey = null,
    ): self {
        $roles = [
            'gateway' => ['address' => '10.6.0.2', 'private_key' => $gatewayPrivateKey, 'public_key' => $gatewayPublicKey],
            'control' => ['address' => '10.6.0.3', 'private_key' => $controlPrivateKey, 'public_key' => $controlPublicKey],
        ];

        if ($devPrivateKey !== null && $devPublicKey !== null) {
            $roles['dev'] = ['address' => '10.6.0.4', 'private_key' => $devPrivateKey, 'public_key' => $devPublicKey];
        }

        if ($prodPrivateKey !== null && $prodPublicKey !== null) {
            $roles['prod'] = ['address' => '10.6.0.5', 'private_key' => $prodPrivateKey, 'public_key' => $prodPublicKey];
        }

        return new self($gatewayProviderIp, $roles);
    }

    public function addressFor(string $role): string
    {
        return $this->role($role)['address'];
    }

    public function gatewayHostConfig(): string
    {
        $gateway = $this->role('gateway');
        $lines = [
            '[Interface]',
            "PrivateKey = {$gateway['private_key']}",
            "Address = {$gateway['address']}/24",
            'ListenPort = 51820',
            '',
        ];

        foreach (['control', 'dev', 'prod'] as $role) {
            if (! isset($this->roles[$role])) {
                continue;
            }

            $peer = $this->roles[$role];
            $lines = [
                ...$lines,
                '[Peer]',
                "PublicKey = {$peer['public_key']}",
                "AllowedIPs = {$peer['address']}/32",
                'PersistentKeepalive = 25',
                '',
            ];
        }

        return implode("\n", $lines);
    }

    public function peerConfig(string $role): string
    {
        if ($role === 'gateway') {
            return $this->gatewayHostConfig();
        }

        $peer = $this->role($role);
        $gateway = $this->role('gateway');

        return implode("\n", [
            '[Interface]',
            "PrivateKey = {$peer['private_key']}",
            "Address = {$peer['address']}/24",
            '',
            '[Peer]',
            "PublicKey = {$gateway['public_key']}",
            'AllowedIPs = 10.6.0.0/24',
            "Endpoint = {$this->gatewayProviderIp}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    /**
     * @return array{address: string, private_key: string, public_key: string}
     */
    private function role(string $role): array
    {
        return $this->roles[$role] ?? throw new RuntimeException("WireGuard role [{$role}] is not present in this mesh.");
    }
}
```

- [ ] **Step 4: Verify mesh tests pass**

Run:

```bash
php artisan test --compact tests/Feature/E2EWireGuardMeshTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/E2E/Support/E2EWireGuardMesh.php tests/Feature/E2EWireGuardMeshTest.php
git commit -m "Add E2E WireGuard mesh renderer"
```

## Task 3: Install And Verify WireGuard Interfaces

**Files:**
- Modify: `app/E2E/Support/E2EWireGuardMesh.php`
- Test: `tests/Feature/E2EWireGuardMeshTest.php`

- [ ] **Step 1: Add failing install-command tests**

Append to `tests/Feature/E2EWireGuardMeshTest.php`:

```php
it('installs and restarts wg-orbit on a role instance', function (): void {
    $instance = new RecordingE2EInstance('dev');
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        gatewayPrivateKey: 'gateway-private',
        gatewayPublicKey: 'gateway-public',
        controlPrivateKey: 'control-private',
        controlPublicKey: 'control-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $mesh->installRole($instance, 'dev');

    expect($instance->commands)->toHaveCount(1)
        ->and($instance->commands[0])->toContain('/etc/wireguard/wg-orbit.conf')
        ->and($instance->commands[0])->toContain('wg-quick down wg-orbit')
        ->and($instance->commands[0])->toContain('wg-quick up wg-orbit')
        ->and($instance->commands[0])->toContain('systemctl enable wg-quick@wg-orbit');
});

it('verifies a role has a real wg-orbit interface and can reach peers', function (): void {
    $instance = new RecordingE2EInstance('gateway');
    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: '10.231.0.11',
        gatewayPrivateKey: 'gateway-private',
        gatewayPublicKey: 'gateway-public',
        controlPrivateKey: 'control-private',
        controlPublicKey: 'control-public',
        devPrivateKey: 'dev-private',
        devPublicKey: 'dev-public',
    );

    $mesh->verifyRole($instance, 'gateway', ['control', 'dev']);

    expect($instance->commands[0])->toContain('ip link show wg-orbit')
        ->and($instance->commands[0])->toContain('wg show wg-orbit')
        ->and($instance->commands[0])->toContain('ping -c 1 -W 2 10.6.0.3')
        ->and($instance->commands[0])->toContain('ping -c 1 -W 2 10.6.0.4');
});

final class RecordingE2EInstance implements \App\E2E\Support\E2EInstance
{
    /** @var list<string> */
    public array $commands = [];

    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function exec(string $command, ?int $timeoutSeconds = null): \Illuminate\Contracts\Process\ProcessResult
    {
        $this->commands[] = $command;

        return new class implements \Illuminate\Contracts\Process\ProcessResult
        {
            public function successful(): bool { return true; }
            public function failed(): bool { return false; }
            public function exitCode(): int { return 0; }
            public function output(): string { return ''; }
            public function errorOutput(): string { return ''; }
        };
    }

    public function ssh(string $user, \App\E2E\Support\SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): \Illuminate\Contracts\Process\ProcessResult
    {
        return $this->exec($command, $timeoutSeconds);
    }

    public function authorizeSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}
    public function copyFileToInstance(string $sourcePath, string $targetPath): void {}
    public function copyLocalFileToInstance(string $sourcePath, string $targetPath): void {}
    public function waitForAgent(): void {}
    public function refreshNetworkIdentity(): void {}
    public function waitForIpv4(): string { return '10.231.0.10'; }
    public function waitForSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}
    public function delete(): void {}
    public function stop(): void {}
    public function start(): void {}
    public function snapshot(string $snapshot): void {}
    public function snapshotStatefully(string $snapshot): void {}
    public function restoreSnapshot(string $snapshot, bool $stateful = false): void {}
}
```

- [ ] **Step 2: Run the failing tests**

Run:

```bash
php artisan test --compact tests/Feature/E2EWireGuardMeshTest.php --filter='installs and restarts|verifies a role'
```

Expected: FAIL because `installRole()` and `verifyRole()` do not exist.

- [ ] **Step 3: Implement install and verify methods**

Add to `E2EWireGuardMesh`:

```php
public function installRole(E2EInstance $instance, string $role): void
{
    $config = base64_encode($this->peerConfig($role));

    E2ECommand::exec(
        $instance,
        sprintf(
            'set -euo pipefail; sudo mkdir -p /etc/wireguard; printf %%s %s | base64 -d | sudo tee /etc/wireguard/wg-orbit.conf >/dev/null; sudo chmod 600 /etc/wireguard/wg-orbit.conf; sudo wg-quick down wg-orbit 2>/dev/null || true; sudo wg-quick up wg-orbit; sudo systemctl enable wg-quick@wg-orbit >/dev/null 2>&1',
            escapeshellarg($config),
        ),
        "Could not install wg-orbit on {$instance->name()}",
    );
}

/**
 * @param  list<string>  $peerRoles
 */
public function verifyRole(E2EInstance $instance, string $role, array $peerRoles): void
{
    $pings = array_map(
        fn (string $peerRole): string => 'ping -c 1 -W 2 '.escapeshellarg($this->addressFor($peerRole)).' >/dev/null',
        $peerRoles,
    );

    E2ECommand::exec(
        $instance,
        implode('; ', [
            'set -euo pipefail',
            'ip link show wg-orbit >/dev/null',
            'wg show wg-orbit >/dev/null',
            ...$pings,
        ]),
        "WireGuard verification failed on {$instance->name()}",
    );
}
```

- [ ] **Step 4: Verify tests pass**

Run:

```bash
php artisan test --compact tests/Feature/E2EWireGuardMeshTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/E2E/Support/E2EWireGuardMesh.php tests/Feature/E2EWireGuardMeshTest.php
git commit -m "Install real WireGuard in E2E meshes"
```

## Task 4: Add Gateway Docker And wg-easy Runtime Support

**Files:**
- Create: `app/E2E/Support/E2EWgEasyGateway.php`
- Test: `tests/Feature/E2EWgEasyGatewayTest.php`

- [ ] **Step 1: Write failing gateway runtime tests**

Create `tests/Feature/E2EWgEasyGatewayTest.php`:

```php
<?php

declare(strict_types=1);

use App\E2E\Support\E2EWgEasyGateway;

it('starts wg-easy with the gateway runtime container shape', function (): void {
    $instance = new RecordingE2EInstance('gateway');

    (new E2EWgEasyGateway)->start($instance, advertisedHost: '10.231.0.11');

    $command = implode("\n", $instance->commands);

    expect($command)->toContain('apt-get install -y -qq docker.io sqlite3')
        ->and($command)->toContain('systemctl enable --now docker')
        ->and($command)->toContain('docker run -d')
        ->and($command)->toContain('--name wg-easy')
        ->and($command)->toContain('-p 51820:51820/udp')
        ->and($command)->toContain('--cap-add NET_ADMIN')
        ->and($command)->toContain('--cap-add SYS_MODULE')
        ->and($command)->toContain('-v /lib/modules:/lib/modules:ro')
        ->and($command)->toContain('ghcr.io/wg-easy/wg-easy:15');
});
```

- [ ] **Step 2: Run the failing test**

Run:

```bash
php artisan test --compact tests/Feature/E2EWgEasyGatewayTest.php
```

Expected: FAIL because `E2EWgEasyGateway` does not exist.

- [ ] **Step 3: Implement `E2EWgEasyGateway`**

Create `app/E2E/Support/E2EWgEasyGateway.php`:

```php
<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EWgEasyGateway
{
    public function start(E2EInstance $gateway, string $advertisedHost): void
    {
        E2ECommand::exec(
            $gateway,
            sprintf(
                'set -euo pipefail; export DEBIAN_FRONTEND=noninteractive; if ! command -v docker >/dev/null 2>&1; then sudo apt-get update -qq && sudo apt-get install -y -qq docker.io sqlite3; fi; sudo systemctl enable --now docker; docker rm -f wg-easy >/dev/null 2>&1 || true; mkdir -p ~/.wg-easy; docker run -d --name wg-easy --restart unless-stopped -p 51820:51820/udp -p 127.0.0.1:51821:51821/tcp --cap-add NET_ADMIN --cap-add SYS_MODULE --sysctl net.ipv4.conf.all.src_valid_mark=1 --sysctl net.ipv4.ip_forward=1 -v ~/.wg-easy:/etc/wireguard -v /lib/modules:/lib/modules:ro ghcr.io/wg-easy/wg-easy:15; for i in $(seq 1 30); do test -f ~/.wg-easy/wg-easy.db && break; sleep 1; done; test -f ~/.wg-easy/wg-easy.db; sqlite3 ~/.wg-easy/wg-easy.db %s || true',
                escapeshellarg("UPDATE user_configs_table SET host = '{$advertisedHost}', default_dns = '[\"10.6.0.2\"]', default_persistent_keepalive = 25; UPDATE general_table SET setup_step = 0;"),
            ),
            "Could not start wg-easy on {$gateway->name()}",
        );
    }
}
```

The SQL statement is intentionally best-effort because wg-easy may initialize the database after first boot. Live contract tests in Task 7 verify the runtime shape; production-facing peer config is installed through `E2EWireGuardMesh`.

- [ ] **Step 4: Verify gateway runtime tests pass**

Run:

```bash
php artisan test --compact tests/Feature/E2EWgEasyGatewayTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/E2E/Support/E2EWgEasyGateway.php tests/Feature/E2EWgEasyGatewayTest.php
git commit -m "Start wg-easy in Incus gateway topologies"
```

## Task 5: Replace Synthetic Networking In Incus Preparation

**Files:**
- Modify: `app/E2E/Support/IncusTopologyBuilder.php`
- Modify: `tests/Feature/IncusTopologyBuilderTest.php`

- [ ] **Step 1: Write failing builder assertions**

In `tests/Feature/IncusTopologyBuilderTest.php`, update the final expectations in `it('builds prepared topology templates through staged node:new snapshots'...)`:

```php
expect($commandOutput)->toContain('docker run -d')
    ->and($commandOutput)->toContain('--name wg-easy')
    ->and($commandOutput)->toContain('/etc/wireguard/wg-orbit.conf')
    ->and($commandOutput)->toContain('wg-quick up wg-orbit')
    ->and($commandOutput)->not->toContain('ip addr add')
    ->and($commandOutput)->not->toContain('ip route replace');
```

- [ ] **Step 2: Run the failing builder test**

Run:

```bash
php artisan test --compact tests/Feature/IncusTopologyBuilderTest.php --filter='staged node:new snapshots'
```

Expected: FAIL because the builder still emits synthetic route commands and does not start `wg-easy`.

- [ ] **Step 3: Generate keys and install real mesh in builder**

In `IncusTopologyBuilder`, add imports:

```php
use App\Services\WireGuard\WireGuardKeyGenerator;
```

Add a private mesh factory:

```php
private function meshFor(array $instances): E2EWireGuardMesh
{
    $generator = app(WireGuardKeyGenerator::class);
    $gateway = $generator->generateKeyPair();
    $control = $generator->generateKeyPair();
    $dev = isset($instances['dev']) ? $generator->generateKeyPair() : null;
    $prod = isset($instances['prod']) ? $generator->generateKeyPair() : null;

    return E2EWireGuardMesh::standard(
        gatewayProviderIp: $instances['gateway']->waitForIpv4(),
        gatewayPrivateKey: $gateway['private_key'],
        gatewayPublicKey: $gateway['public_key'],
        controlPrivateKey: $control['private_key'],
        controlPublicKey: $control['public_key'],
        devPrivateKey: $dev['private_key'] ?? null,
        devPublicKey: $dev['public_key'] ?? null,
        prodPrivateKey: $prod['private_key'] ?? null,
        prodPublicKey: $prod['public_key'] ?? null,
    );
}
```

Replace calls to `reestablishWireGuardRoutes($instances)` in gateway/app stages with:

```php
$this->timer->measure('wireguard.real', fn () => $this->installRealWireGuard($instances));
```

Add:

```php
private function installRealWireGuard(array $instances): void
{
    if (! isset($instances['gateway'], $instances['control'])) {
        return;
    }

    $mesh = $this->meshFor($instances);

    (new E2EWgEasyGateway)->start($instances['gateway'], advertisedHost: $instances['gateway']->waitForIpv4());

    foreach (['gateway', 'control', 'dev', 'prod'] as $role) {
        if (! isset($instances[$role])) {
            continue;
        }

        $mesh->installRole($instances[$role], $role);
    }

    $mesh->verifyRole($instances['gateway'], 'gateway', array_values(array_filter(['control', isset($instances['dev']) ? 'dev' : null, isset($instances['prod']) ? 'prod' : null])));
}
```

Remove `reestablishWireGuardRoutes()` and `routeAppPeer()` from `IncusTopologyBuilder` after all references are gone.

- [ ] **Step 4: Verify builder tests pass**

Run:

```bash
php artisan test --compact tests/Feature/IncusTopologyBuilderTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/E2E/Support/IncusTopologyBuilder.php tests/Feature/IncusTopologyBuilderTest.php
git commit -m "Use real WireGuard in Incus topology preparation"
```

## Task 6: Retarget Real WireGuard On Clone Acquisition And Reset

**Files:**
- Modify: `app/E2E/Support/IncusTopologyProvider.php`
- Modify: `tests/Feature/IncusTopologyTemplateTest.php`
- Modify: `tests/E2E/PreparedTopologyContractTest.php`

- [ ] **Step 1: Add unit assertions that acquisition no longer uses synthetic routes**

Add to `tests/Feature/IncusTopologyTemplateTest.php`:

```php
it('does not use synthetic provider-interface routes for prepared gateway clones', function (): void {
    $source = file_get_contents(app_path('E2E/Support/IncusTopologyProvider.php'));

    expect($source)->not->toContain('ip addr add')
        ->and($source)->not->toContain('ip route replace')
        ->and($source)->toContain('wg-quick up wg-orbit');
});
```

- [ ] **Step 2: Run the failing assertion**

Run:

```bash
php artisan test --compact tests/Feature/IncusTopologyTemplateTest.php --filter='synthetic provider-interface routes'
```

Expected: FAIL because `IncusTopologyProvider` still calls synthetic route helpers.

- [ ] **Step 3: Replace provider retargeting**

In `IncusTopologyProvider::prepareInstances()`, replace:

```php
$timer->measure('wireguard', fn () => $this->reestablishWireGuardRoutes($instances, $networkPlan));
$timer->measure('retarget', fn () => $this->retargetTopology($instances, $config, $sshKeyPair, $networkPlan));
$timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances, $networkPlan));
```

with:

```php
$timer->measure('wireguard.retarget', fn () => $this->retargetRealWireGuard($instances, $networkPlan));
$timer->measure('retarget', fn () => $this->retargetTopology($instances, $config, $sshKeyPair, $networkPlan));
$timer->measure('network-ready', fn () => $this->waitForPeerRoutes($instances, $networkPlan));
```

Add:

```php
private function retargetRealWireGuard(array $instances, DockerTopologyNetworkPlan $networkPlan): void
{
    if (! isset($instances['gateway'], $instances['control'])) {
        return;
    }

    $gatewayIp = $instances['gateway']->waitForIpv4();

    $mesh = E2EWireGuardMesh::standard(
        gatewayProviderIp: $gatewayIp,
        gatewayPrivateKey: trim($instances['gateway']->exec('sudo wg show wg-orbit private-key')->output()),
        gatewayPublicKey: trim($instances['gateway']->exec('sudo wg show wg-orbit public-key')->output()),
        controlPrivateKey: trim($instances['control']->exec('sudo wg show wg-orbit private-key')->output()),
        controlPublicKey: trim($instances['control']->exec('sudo wg show wg-orbit public-key')->output()),
        devPrivateKey: isset($instances['dev']) ? trim($instances['dev']->exec('sudo wg show wg-orbit private-key')->output()) : null,
        devPublicKey: isset($instances['dev']) ? trim($instances['dev']->exec('sudo wg show wg-orbit public-key')->output()) : null,
        prodPrivateKey: isset($instances['prod']) ? trim($instances['prod']->exec('sudo wg show wg-orbit private-key')->output()) : null,
        prodPublicKey: isset($instances['prod']) ? trim($instances['prod']->exec('sudo wg show wg-orbit public-key')->output()) : null,
    );

    foreach (['gateway', 'control', 'dev', 'prod'] as $role) {
        if (! isset($instances[$role])) {
            continue;
        }

        $mesh->installRole($instances[$role], $role);
    }
}
```

Update reset paths to call `retargetRealWireGuard()` instead of `reestablishWireGuardRoutes()`. Delete `reestablishWireGuardRoutes()` and `routeAppPeer()` from `IncusTopologyProvider`.

- [ ] **Step 4: Keep gateway registry addresses aligned**

Keep `retargetTopology()` writing `host` and `wireguard_address` to `10.6.0.2`, `10.6.0.3`, `10.6.0.4`, and `10.6.0.5`. Do not write provider IPs into node records.

- [ ] **Step 5: Verify focused tests**

Run:

```bash
php artisan test --compact tests/Feature/IncusTopologyTemplateTest.php --filter='synthetic provider-interface routes'
php artisan test --compact tests/Feature/E2ETopologyResetTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/E2E/Support/IncusTopologyProvider.php tests/Feature/IncusTopologyTemplateTest.php tests/E2E/PreparedTopologyContractTest.php
git commit -m "Retarget real WireGuard for Incus topology clones"
```

## Task 7: Strengthen Live Prepared Topology Contracts

**Files:**
- Modify: `tests/E2E/PreparedTopologyContractTest.php`

- [ ] **Step 1: Add live WireGuard assertions**

Add helper:

```php
function expectPreparedWireGuard(E2EInstance $instance, string $user, SshKeyPair $key, string $expectedAddress): void
{
    $result = E2ECommand::ssh(
        $instance,
        $user,
        $key,
        sprintf(
            'set -euo pipefail; ip link show wg-orbit >/dev/null; ip -o address show dev wg-orbit | grep -F %s; sudo wg show wg-orbit >/dev/null',
            escapeshellarg("{$expectedAddress}/24"),
        ),
    );

    expect($result->successful())->toBeTrue();
}
```

Call it from existing topology helpers:

```php
expectPreparedWireGuard($gateway, 'orbit', $key, '10.6.0.2');
expectPreparedWireGuard($control, $config->controlUser, $key, '10.6.0.3');
expectPreparedWireGuard($dev, 'orbit', $key, '10.6.0.4');
expectPreparedWireGuard($prod, 'orbit', $key, '10.6.0.5');
```

Add gateway-to-app SSH assertion:

```php
$sshOverWireGuard = E2ECommand::ssh(
    $gateway,
    'orbit',
    $key,
    'ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o ConnectTimeout=5 orbit@10.6.0.4 true',
    timeoutSeconds: 30,
);

expect($sshOverWireGuard->successful())->toBeTrue();
```

- [ ] **Step 2: Run live contract when Incus topology is available**

Run:

```bash
composer test:e2e:incus -- --group=e2e-topology-contract-control-gateway-dev --fail-on-empty-test-suite
```

Expected before Tasks 5 and 6 are fully working on prepared images: FAIL with missing `wg-orbit` or failed WireGuard SSH.

- [ ] **Step 3: Rebuild prepared topology**

Run:

```bash
composer e2e:prepare-topology -- --force control-gateway-dev-prod
```

Expected: PASS. If this fails because the base image lacks Docker or kernel modules, update `bin/_e2e-deps.sh` to include `docker.io` and rebuild the Incus base image with:

```bash
php artisan e2e:prepare-base-image --force
```

- [ ] **Step 4: Re-run live contract**

Run:

```bash
composer test:e2e:incus -- --group=e2e-topology-contract-control-gateway-dev-prod --fail-on-empty-test-suite
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/E2E/PreparedTopologyContractTest.php bin/_e2e-deps.sh
git commit -m "Assert real WireGuard in prepared topology contracts"
```

## Task 8: Make Firewall E2E Depend On Real WireGuard Baseline

**Files:**
- Modify: `tests/E2E/FirewallDoctorAdoptTest.php`
- Modify: `docs/porting/4_firewall.md`

- [ ] **Step 1: Remove synthetic SSH allowance from firewall test**

In `tests/E2E/FirewallDoctorAdoptTest.php`, replace the UFW setup command with:

```php
$topology->ssh(
    'dev',
    sprintf(
        'sudo apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ufw && sudo ufw --force reset && sudo ufw default deny incoming && sudo ufw default allow outgoing && sudo ufw allow in on wg-orbit comment "orbit:node-wireguard-baseline" && sudo ufw allow from %s to any port 5173 proto tcp comment "orbit:local-vite" && sudo ufw --force enable',
        escapeshellarg($wireGuardCidr),
    ),
    timeoutSeconds: 180,
);
```

Keep `$wireGuardCidr` derived from the lease gateway IP until all topologies are fixed to `10.6.0.0/24`.

- [ ] **Step 2: Add assertion that UFW baseline is interface-based**

After reading UFW status:

```php
expect($ufwOutput)->toContain('wg-orbit')
    ->and($ufwOutput)->toContain('5173');
```

- [ ] **Step 3: Run focused E2E when Incus topology is available**

Run:

```bash
composer test:e2e:incus -- --filter='adopts observed UFW rules'
```

Expected: PASS when real WireGuard topology is available; SKIP only when prepared topology is unavailable.

- [ ] **Step 4: Run in-memory firewall coverage**

Run:

```bash
php artisan test --compact tests/Feature/Commands/Operations/DoctorCommandContractTest.php tests/Unit/Services/Firewall/FirewallRuleProbeTest.php
composer docs-lint
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/E2E/FirewallDoctorAdoptTest.php docs/porting/4_firewall.md
git commit -m "Test firewall doctor over real WireGuard"
```

## Task 9: Update E2E Scripts And Documentation

**Files:**
- Modify: `composer.json`
- Modify: `docs/porting/testing-infrastructure.md`
- Modify: `docs/porting/PORTING.md`
- Modify: `tests/Feature/VerificationScriptsTest.php`

- [ ] **Step 1: Update verification script expectations**

In `tests/Feature/VerificationScriptsTest.php`, assert that Docker feature scripts no longer target gateway topology groups. Add:

```php
expect($scripts)
    ->not->toHaveKey('test:e2e:features:docker:control-gateway-dev-prod')
    ->not->toHaveKey('test:e2e:topology-contract');
```

If `test:e2e:topology-contract` remains, update its expected environment from Docker to Incus:

```php
expect($scripts['test:e2e:topology-contract'][1])
    ->toContain('ORBIT_E2E_TOPOLOGY_PROVIDER=incus')
    ->toContain('--group=e2e-topology-contract-control-gateway-dev-prod');
```

- [ ] **Step 2: Run failing verification script test**

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php --filter='e2e'
```

Expected: FAIL where composer scripts still advertise Docker gateway topology.

- [ ] **Step 3: Update `composer.json` scripts**

Change topology-contract scripts that use gateway topologies to set:

```json
"ORBIT_E2E_TOPOLOGY_PROVIDER=incus"
```

Remove Docker gateway feature aliases. Keep Docker scripts only for non-gateway E2E lanes.

- [ ] **Step 4: Update docs**

In `docs/porting/testing-infrastructure.md`, state:

```markdown
Gateway-capable prepared topologies are Incus-only because they require real
`wg-orbit` interfaces and a gateway VM running `wg-easy`. Docker topology is
reserved for tests that do not involve gateway, VPN, firewall, or
gateway-to-app SSH behavior.
```

In `docs/porting/PORTING.md`, update the lane guidance so Incus is required for gateway semantics and Docker is described as non-gateway only.

- [ ] **Step 5: Verify docs and script tests**

Run:

```bash
php artisan test --compact tests/Feature/VerificationScriptsTest.php
composer docs-lint
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add composer.json docs/porting/testing-infrastructure.md docs/porting/PORTING.md tests/Feature/VerificationScriptsTest.php
git commit -m "Document Incus-only gateway E2E topology"
```

## Task 10: Final Verification

**Files:**
- Verify all touched files.

- [ ] **Step 1: Run PHP formatting**

Run:

```bash
vendor/bin/pint --dirty --format agent
```

Expected: PASS.

- [ ] **Step 2: Run narrow feature suite**

Run:

```bash
php artisan test --compact tests/Feature/E2ETopologyProviderPoolTest.php tests/Feature/E2EWireGuardMeshTest.php tests/Feature/E2EWgEasyGatewayTest.php tests/Feature/IncusTopologyBuilderTest.php tests/Feature/IncusTopologyTemplateTest.php tests/Feature/E2ETopologyResetTest.php tests/Feature/VerificationScriptsTest.php
```

Expected: PASS.

- [ ] **Step 3: Run broad quality check**

Run:

```bash
composer quality-check
```

Expected: PASS.

- [ ] **Step 4: Run live Incus smoke**

Run:

```bash
composer e2e:prepare-topology -- --force control-gateway-dev-prod
composer test:e2e:incus -- --group=e2e-topology-contract-control-gateway-dev-prod --fail-on-empty-test-suite
composer test:e2e:incus -- --filter='adopts observed UFW rules'
```

Expected: PASS or SKIP only when the Incus host/images are unavailable. Failure because `wg-orbit`, `wg-easy`, gateway API, or gateway-to-app SSH is missing means the implementation is incomplete.

- [ ] **Step 5: Commit final cleanup**

```bash
git status --short
git add .
git commit -m "Finish real WireGuard E2E topology migration"
```

Only run the final commit if Step 5 shows intentional uncommitted changes from this plan.

## Self-Review

- Spec coverage: provider gating, real `wg-orbit`, Docker-in-gateway VM, clone retargeting, live contract checks, firewall test behavior, and docs are covered by Tasks 1-10.
- Placeholder scan: no unresolved placeholder markers are intentionally present.
- Type consistency: planned classes use the existing `App\E2E\Support` namespace and existing `E2EInstance`, `E2ECommand`, `E2ETopologyKind`, and `DockerTopologyNetworkPlan` types.
