<?php

declare(strict_types=1);

use Tests\E2E\Support\DockerTopologyNetworkPlan;

it('keeps the canonical docker subnet outside parallel workers', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.6.0.0/16')
            ->and($plan->ipForRole('gateway'))->toBe('10.6.0.2')
            ->and($plan->ipForRole('control'))->toBe('10.6.0.3')
            ->and($plan->ipForRole('dev'))->toBe('10.6.0.4')
            ->and($plan->ipForRole('prod'))->toBe('10.6.0.5');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a distinct docker subnet for each parallel worker token', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.62.0.0/16')
            ->and($plan->ipForRole('gateway'))->toBe('10.62.0.2')
            ->and($plan->ipForRole('control'))->toBe('10.62.0.3')
            ->and($plan->ipForRole('dev'))->toBe('10.62.0.4')
            ->and($plan->ipForRole('prod'))->toBe('10.62.0.5');
    } finally {
        restoreTestToken($previous);
    }
});

function restoreTestToken(string|false $previous): void
{
    if ($previous === false) {
        putenv('TEST_TOKEN');

        return;
    }

    putenv("TEST_TOKEN={$previous}");
}
