<?php

declare(strict_types=1);

use App\Librarian\TransitionalSshConsumerClassifier;
use App\Librarian\TransitionalSshConsumerFinder;
use App\Librarian\TransitionalSshInventoryBuilder;
use Illuminate\Support\Facades\Artisan;

it('keeps SSH limited to the provisioning and bootstrap lane', function (): void {
    $inventory = app(TransitionalSshInventoryBuilder::class)->build();

    expect($inventory['unmarked_consumers'])
        ->toBeEmpty()
        ->and($inventory['generated_from']['source_roots'])
        ->toBe([
            'apps/gateway/app',
            'apps/cli/app',
        ])
        ->and(array_column($inventory['provisioning_ssh'], 'path'))
        ->toBe([
            'apps/gateway/app/Services/Nodes/GatewayNodeCreator.php',
            'apps/gateway/app/Services/OrbitHostInstaller.php',
            'apps/gateway/app/Services/RemoteShell/RemoteHostExecutor.php',
            'apps/gateway/app/Services/RemoteShell/SshRemoteShell.php',
            'apps/gateway/app/Services/Security/HomeDirectoryLockdownInstaller.php',
            'apps/gateway/app/Services/Security/PublicSshDenyInstaller.php',
            'apps/gateway/app/Services/Security/SecurityInstaller.php',
            'apps/gateway/app/Services/Security/SecurityInstallerTransport.php',
            'apps/gateway/app/Services/Security/SshdHardenedInstaller.php',
            'apps/gateway/app/Services/Security/SysctlBaselineInstaller.php',
            'apps/gateway/app/Services/Security/UnattendedUpgradesInstaller.php',
        ])
        ->and($inventory['transitional_ssh'])
        ->toBeEmpty();
});

it('keeps the committed transitional SSH inventory fresh', function (): void {
    $builder = app(TransitionalSshInventoryBuilder::class);

    expect($builder->artifactPath())
        ->toBeFile()
        ->and(file_get_contents($builder->artifactPath()))
        ->toBe($builder->toJson($builder->build()));
});

it('reports unmarked consumers and rejects conflicting lane markers', function (): void {
    $classifier = app(TransitionalSshConsumerClassifier::class);

    expect(
        $classifier->classify(consumers: [
            'apps/gateway/app/Example.php' => '<?php',
        ])['unmarked_consumers'],
    )->toBe(['apps/gateway/app/Example.php']);

    expect(fn (): array => $classifier->classify(consumers: [
        'apps/gateway/app/Conflicting.php' => <<<'PHP'
            <?php
            // @orbit-ssh-lane transitional-ssh
            // @orbit-ssh-lane provisioning-ssh
            PHP,
    ]))
        ->toThrow(
            RuntimeException::class,
            'SSH consumer apps/gateway/app/Conflicting.php declares both execution-lane markers.',
        );
});

it('treats every public transport selector shape as an SSH consumer', function (string $selector): void {
    $finder = app(TransitionalSshConsumerFinder::class);

    expect($finder->isConsumer('apps/gateway/app/Example.php', $selector))
        ->toBeTrue();
})->with([
    'preference enum' => 'NodeTransportPreference',
    'forwarding helper' => 'withNodeTransportPreference',
    'HTTP header' => 'X-Orbit-Node-Transport-Preference',
    'PHP server header' => 'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE',
]);

it('treats either CLI selector spelling as an SSH consumer', function (string $selector): void {
    $finder = app(TransitionalSshConsumerFinder::class);

    expect($finder->isConsumer('apps/cli/app/Commands/ExampleCommand.php', $selector))
        ->toBeTrue();
})->with([
    'long option' => '--node-transport',
    'source spelling' => 'node-transport',
]);

it('passes the transitional SSH inventory freshness command', function (): void {
    $exitCode = Artisan::call('orbit:transitional-ssh-inventory', ['--check' => true]);

    expect($exitCode)->toBe(0)->and(Artisan::output())->toContain('Orbit transitional SSH inventory is up to date.');
});
