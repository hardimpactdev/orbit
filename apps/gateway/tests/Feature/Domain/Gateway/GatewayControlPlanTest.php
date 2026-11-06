<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayControlPlan;
use App\Domain\Nodes\SwampRoleUnits;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

it('renders the serve and worker units for the checkout of the machine, with the release grants and no secret', function (): void {
    $node = SwampNodeFixture::ensure();
    $checkout = realpath(base_path('../../swamp'));

    $plan = app(GatewayControlPlan::class)->make($checkout);

    expect($plan['node_id'])->toBe($node->id)
        ->and($plan['user'])->toBe('orbit')
        ->and($plan['units']['serve'])->toContain("--repo-dir {$checkout} ")->toContain("WorkingDirectory={$checkout}\n")
        ->and($plan['units']['worker'])->toContain("--label node={$node->id}")
        ->and(array_keys($plan['grants']))->toBe(['grants/orbit-gateway.yaml', 'grants/orbit-serve.yaml'])
        ->and(json_encode($plan))->not->toMatch('/[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/');
});

it('keeps the production checkout of the swamp Node when no repository is named', function (): void {
    $units = SwampRoleUnits::for('orbit', 209);

    expect($units['serve'])->toContain('--repo-dir /home/orbit/apps/orbit-monorepo-staging/checkout/swamp ');
});
