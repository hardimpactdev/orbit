<?php

declare(strict_types=1);

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Actions\Nodes\AssignRoleAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\SwampNode;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

function bootstrapFixture(): array
{
    $home = sys_get_temp_dir().'/'.TestOrbitHome::Prefix.'bootstrap-'.bin2hex(random_bytes(4));
    mkdir($home, 0o700);
    Config::set('orbit.home', $home);
    $bytes = pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32);
    file_put_contents($home.'.hostkey.pub', 'ssh-ed25519 '.base64_encode($bytes)." root@fixture\n");

    $processes = new class(app(ProcessRunner::class), $home) implements ProcessRunner
    {
        public int $keyGenerations = 0;

        public function __construct(private ProcessRunner $native, private string $home) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            if (array_slice($invocation->arguments, 0, 2) !== ['openssl', 'genrsa']) {
                return $this->native->run($invocation);
            }

            // Prime search has unbounded runtime. Keep a real 4096-bit key, signing and SSH generation; fix only that input.
            expect($invocation->arguments)->toBe(['openssl', 'genrsa', '-out', $this->home.'/ca/root.key', '4096']);
            if (! copy(__DIR__.'/../../../Fixtures/gateway-bootstrap-root.key', $this->home.'/ca/root.key')) {
                throw new RuntimeException('Could not copy the test-only Gateway CA key.');
            }
            $this->keyGenerations++;

            return new CommandResult(0, '', '', 0, false);
        }
    };
    app()->instance(ProcessRunner::class, $processes);

    return [$home, 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, true)), '='), $processes];
}

function bootstrapData(string $ip = '10.45.0.1', ?string $operator = null): BootstrapGatewayData
{
    return new BootstrapGatewayData(publicHost: '10.232.2.9', wireguardIp: $ip, wireguardSubnet: '10.45.0.0/24',
        wireguardEndpoint: '10.232.2.9:51820', dnsServer: $ip, domain: 'orbit', operatorIp: $operator);
}

it('records the Gateway, makes its CA and SSH key, and keeps them on a second run', function (): void {
    [$home, $fingerprint, $processes] = bootstrapFixture();
    $action = new BootstrapGatewayAction(app(AssignRoleAction::class), app(VpnSettings::class),
        app(ProcessRunner::class), app(GatewayServingHost::class), $home.'.hostkey.pub');

    $node = $action->execute(bootstrapData());

    expect($processes->keyGenerations)->toBe(1)
        ->and(openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents($home.'/ca/root.key')))['bits'])->toBe(4096);
    expect($node->name)->toBe('gateway')
        ->and(SwampNode::id())->toBe($node->id)
        ->and($node->status)->toBe(LifecycleStatus::Active)
        ->and($node->wireguard_ip)->toBe('10.45.0.1')
        ->and($node->ssh_host_fingerprint)->toBe($fingerprint)
        ->and($node->roles->mapWithKeys(fn ($role): array => [$role->role->value => $role->status->value])->all())
        ->toEqual(['gateway' => 'active', 'vpn' => 'provisioning', 'swamp' => 'provisioning'])
        ->and(app(VpnSettings::class)->subnet())->toBe('10.45.0.0/24')
        ->and(fileperms($home.'/ca/root.key') & 0o777)->toBe(0o600)
        ->and(fileperms($home.'/ssh/id_ed25519') & 0o777)->toBe(0o600)
        ->and(is_file($home.'/ssh/id_ed25519.pub'))->toBeTrue()
        ->and(Route::query()->where('domain', 'swamp.orbit')->sole()->only(['node_id', 'status']))->toEqual(['node_id' => $node->id, 'status' => RouteStatus::Active])
        ->and(Route::query()->where('domain', 'swamp.orbit')->sole()->customProxy->upstream)->toBe('http://127.0.0.1:9418');
    $ca = file_get_contents($home.'/ca/root.pem');
    $key = file_get_contents($home.'/ssh/id_ed25519.pub');

    $action->execute(bootstrapData());

    expect(file_get_contents($home.'/ca/root.pem'))->toBe($ca)
        ->and(file_get_contents($home.'/ssh/id_ed25519.pub'))->toBe($key)
        ->and($processes->keyGenerations)->toBe(1)
        ->and(Node::query()->count())->toBe(1)
        ->and(Route::query()->count())->toBe(1);
});

it('refuses an address outside the WireGuard subnet before it changes anything', function (): void {
    [$home] = bootstrapFixture();
    $action = new BootstrapGatewayAction(app(AssignRoleAction::class), app(VpnSettings::class),
        app(ProcessRunner::class), app(GatewayServingHost::class), $home.'.hostkey.pub');

    expect(fn () => $action->execute(bootstrapData('10.46.0.1')))->toThrow(NodeProvisioningException::class);
    expect(Node::query()->count())->toBe(0)->and(is_dir($home.'/ca'))->toBeFalse();
});

it('records the operator without roles or key, with access to the Gateway, once', function (): void {
    [$home] = bootstrapFixture();
    $action = new BootstrapGatewayAction(app(AssignRoleAction::class), app(VpnSettings::class),
        app(ProcessRunner::class), app(GatewayServingHost::class), $home.'.hostkey.pub');

    $gateway = $action->execute(bootstrapData(operator: '10.45.0.10'));
    $action->execute(bootstrapData(operator: '10.45.0.10'));

    $operator = Node::query()->where('name', 'operator')->sole();
    expect($operator->only(['status', 'wireguard_ip', 'wireguard_public_key']))->toEqual(['status' => LifecycleStatus::Active, 'wireguard_ip' => '10.45.0.10', 'wireguard_public_key' => null])
        ->and($operator->roles()->count())->toBe(0)
        ->and($operator->accessibleNodes()->pluck('nodes.id')->all())->toBe([$gateway->id]);
});

it('refuses an operator address outside the subnet or on the Gateway', function (string $operator): void {
    [$home] = bootstrapFixture();
    $action = new BootstrapGatewayAction(app(AssignRoleAction::class), app(VpnSettings::class),
        app(ProcessRunner::class), app(GatewayServingHost::class), $home.'.hostkey.pub');

    expect(fn () => $action->execute(bootstrapData(operator: $operator)))->toThrow(NodeProvisioningException::class)
        ->and(Node::query()->count())->toBe(0);
})->with(['outside' => '10.46.0.10', 'gateway' => '10.45.0.1']);
