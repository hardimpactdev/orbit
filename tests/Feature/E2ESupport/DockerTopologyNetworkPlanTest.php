<?php

declare(strict_types=1);

use App\E2E\Support\DockerTopologyNetworkPlan;

it('keeps non-run docker topology networks outside the orbit WireGuard subnet', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.90.224.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.224.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.224.3')
            ->and($plan->ipForRole('control'))->toBe('10.90.224.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.224.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.224.5')
            ->and($plan->ipForRole('agent'))->toBe('10.90.224.6')
            ->and($plan->ipForRole('ingress'))->toBe('10.90.224.7');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a run-scoped docker subnet outside parallel workers', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123');

        expect($plan->subnet())->toBe('10.90.166.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.166.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.166.3')
            ->and($plan->ipForRole('control'))->toBe('10.90.166.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.166.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.166.5');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a distinct docker subnet for each parallel worker token', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment();

        expect($plan->subnet())->toBe('10.90.226.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.226.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.226.3')
            ->and($plan->ipForRole('control'))->toBe('10.90.226.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.226.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.226.5');
    } finally {
        restoreTestToken($previous);
    }
});

it('allocates a run-scoped docker subnet for parallel topology leases', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123');

        expect($plan->subnet())->toBe('10.90.22.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.22.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.22.3')
            ->and($plan->ipForRole('control'))->toBe('10.90.22.3')
            ->and($plan->ipForRole('dev'))->toBe('10.90.22.4')
            ->and($plan->ipForRole('prod'))->toBe('10.90.22.5');
    } finally {
        restoreTestToken($previous);
    }
});

it('can advance to the next run-scoped docker subnet after an overlap', function (): void {
    $previous = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN=2');

    try {
        $plan = DockerTopologyNetworkPlan::fromEnvironment('run123', attempt: 1);

        expect($plan->subnet())->toBe('10.90.23.0/24')
            ->and($plan->ipForRole('gateway'))->toBe('10.90.23.2')
            ->and($plan->ipForRole('operator'))->toBe('10.90.23.3');
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
