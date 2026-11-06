<?php

declare(strict_types=1);

use App\Domain\Instances\NativeDevelopmentInstanceInput;

it('hands the create run the artifacts and the restore side of the captured Route publication', function (): void {
    $captured = ['artifacts' => ['caddy' => [['version' => 'a']], 'php' => [], 'dns' => []], 'forward' => ['routes' => [1]], 'restore' => ['routes' => [1], 'publications' => [], 'cleanup' => []]];

    expect(NativeDevelopmentInstanceInput::routeWithdrawal(['route_lifecycle' => ['route_mutations' => $captured]]))
        ->toBe(['artifacts' => $captured['artifacts'], 'restore' => $captured['restore']]);
});

it('hands nothing to a create whose Project serves no web pages', function (): void {
    expect(NativeDevelopmentInstanceInput::routeWithdrawal([]))->toBeNull()
        ->and(NativeDevelopmentInstanceInput::routeWithdrawal(['route_lifecycle' => ['route_mutations' => null]]))->toBeNull();
});
