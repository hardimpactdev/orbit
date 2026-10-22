<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use Orbit\Extensions\AiRouter\Actions\ReconvergeAiRouterConnectionAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterConnectionOwnership;
use Tests\TestCase;

uses(TestCase::class);

it('tags the collector reconvergence as a connection update hook', function (): void {
    $hooks = iterator_to_array(app()->tagged(DatabaseConnectionUpdateHook::class));

    expect(array_map(static fn (DatabaseConnectionUpdateHook $hook): string => $hook::class, $hooks))
        ->toContain(ReconvergeAiRouterConnectionAction::class);
});

it('tags cache ownership as a connection removal guard', function (): void {
    $guards = iterator_to_array(app()->tagged(DatabaseConnectionRemovalGuard::class));

    expect(array_map(static fn (DatabaseConnectionRemovalGuard $guard): string => $guard::class, $guards))
        ->toContain(AiRouterConnectionOwnership::class);
});
