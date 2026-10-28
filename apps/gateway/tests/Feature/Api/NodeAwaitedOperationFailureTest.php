<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Actions\Nodes\SetNodeWireGuardKeyAction;
use App\Actions\Nodes\UpdateNodeSettingsAction;
use App\Data\Nodes\NodeStorageAppsData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function nodeAwaitedFailure(string $kind, Node $node, string $status, string $error): Operation
{
    $identity = app(OperationIdempotency::class);
    $identity->kind = $kind;
    $identity->key = 'node-awaited-'.str_replace('.', '-', $kind);
    $identity->fingerprint = 'node-awaited-fingerprint';

    return Operation::query()->create(['kind' => $kind, 'idempotency_key' => $identity->key, 'idempotency_fingerprint' => $identity->fingerprint,
        'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id, 'status' => $status,
        'error_code' => $status === 'cancelled' ? null : $error, 'input' => [], 'sequence' => 1, 'attempts' => 1]);
}

it('does not let an awaited PHP package refusal become deploy prerequisite success', function (string $status): void {
    $node = FleetFixtures::node('awaited-php');
    nodeAwaitedFailure('node.prepare_php_packages', $node, $status, 'app-prod.php_install_failed');

    expect(fn () => app(RemotePhpPackageManager::class)->installPackagesOnlyForAppProd($node, collect(['8.5'])))
        ->toThrow(fn (RuntimeConvergenceException $error) => expect($error->errorCode)->toBe($status === 'cancelled' ? 'operation.cancelled' : 'app-prod.php_install_failed'));
})->with(['failed', 'cancelled']);

it('does not report new Node settings after an awaited storage refusal', function (string $status): void {
    $node = FleetFixtures::node('awaited-settings');
    $node->update(['settings' => ['apps' => ['path' => '/srv/old']]]);
    nodeAwaitedFailure('node.settings', $node, $status, 'node.settings_root_failed');
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldReceive('resolve')->once()->andReturn(new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    app()->instance(ManagedUserAccountResolver::class, $accounts);

    expect(fn () => app(UpdateNodeSettingsAction::class)->execute($node, new NodeSettingsPatch(true, new NodeStorageAppsData('/srv/new'))))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe($status === 'cancelled' ? 'operation.cancelled' : 'node.settings_root_failed'));

    expect($node->refresh()->settings['apps']['path'])->toBe('/srv/old');
})->with(['failed', 'cancelled']);

it('does not report completed access revocation after the awaited reload failed', function (): void {
    $consumer = FleetFixtures::node('awaited-access-consumer');
    $gateway = FleetFixtures::node('awaited-access-gateway', [RoleName::Gateway, RoleName::Metrics]);
    nodeAwaitedFailure('node.access_remove', $gateway, 'failed', 'metrics.stream_revocation_failed');

    expect(fn () => app(RemoveNodeAccessAction::class)->execute($consumer, $gateway, $gateway))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('metrics.stream_revocation_failed'));
});

it('keeps the desired WireGuard key while reporting failed awaited hub publication', function (): void {
    FleetFixtures::node('awaited-wg-hub', [RoleName::Vpn]);
    $node = FleetFixtures::node('awaited-wg-peer', managed: false);
    nodeAwaitedFailure('node.wireguard_key', $node, 'failed', 'vpn.server_config_install_failed');
    $key = base64_encode(str_repeat('p', 32));

    expect(fn () => app(SetNodeWireGuardKeyAction::class)->execute($node, $key))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('vpn.server_config_install_failed'));

    expect($node->refresh()->wireguard_public_key)->toBe($key);
});
