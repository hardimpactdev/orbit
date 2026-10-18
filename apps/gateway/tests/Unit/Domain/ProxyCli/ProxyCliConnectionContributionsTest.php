<?php

declare(strict_types=1);

use App\Actions\ProxyCli\ReconvergeProxyCliConnectionAction;
use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\ProxyCli\ProxyCliConnectionOwnership;
use Tests\TestCase;

uses(TestCase::class);

it('tags the collector reconvergence as a connection update hook', function (): void {
    $hooks = iterator_to_array(app()->tagged(DatabaseConnectionUpdateHook::class));

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0])->toBeInstanceOf(ReconvergeProxyCliConnectionAction::class);
});

it('tags cache ownership as a connection removal guard', function (): void {
    $guards = iterator_to_array(app()->tagged(DatabaseConnectionRemovalGuard::class));

    expect($guards)->toHaveCount(1)
        ->and($guards[0])->toBeInstanceOf(ProxyCliConnectionOwnership::class);
});
