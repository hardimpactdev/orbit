<?php

declare(strict_types=1);

use App\Services\Apps\AppRegistrationResultAction;

it('does not report convergence when proxy enactment failed', function (): void {
    $action = new AppRegistrationResultAction()->afterEnactment('converged', [[
        'code' => 'proxy.enactment_failed',
        'family' => 'proxy',
        'node' => 'gateway-router',
        'operation' => 'caddy.router.install',
        'message' => 'failed',
        'next_command' => 'doctor --family=proxy --restore',
    ]]);

    expect($action)->toBe('partial');
});

it('preserves convergence when proxy enactment succeeded', function (): void {
    expect(new AppRegistrationResultAction()->afterEnactment('converged', []))
        ->toBe('converged');
});
