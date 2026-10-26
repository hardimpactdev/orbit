<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayServiceStartHook;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Ssh\SshExecutor;
use Orbit\Extensions\Documents\Infrastructure\DocumentsServiceStartHook;

it('contributes the compiled cleanup command and configured Gateway paths even while disabled', function (): void {
    config(['extensions.documents.enabled' => false]);
    $hooks = [...app()->tagged(GatewayServiceStartHook::class)];
    $hook = collect($hooks)->sole(fn ($hook): bool => $hook instanceof DocumentsServiceStartHook);

    expect($hook->command())->toBe('documents.prepare-cleanup')
        ->and($hook->input('/home/orbit/a checkout', '/home/orbit/.orbit'))->toBe([
            'checkout' => '/home/orbit/a checkout', 'orbit_home' => '/home/orbit/.orbit',
        ]);
});

arch('Documents startup contributes intent without a Node execution path')
    ->expect(DocumentsServiceStartHook::class)
    ->not->toUse([
        ProcessRunner::class,
        SshExecutor::class,
        RemoteProgramCatalog::class,
        RemoteProgramRunner::class,
    ]);
