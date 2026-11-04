<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('claims convergence without taking an active role out of service', function (string $before, LifecycleStatus $after): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'claim', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.5',
        'wireguard_ip' => '10.44.0.5', 'user' => 'orbit',
    ]));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => $before,
        'failed_step' => $before === 'failed' ? 'converge:Earlier step' : null, 'error_code' => $before === 'failed' ? 'earlier' : null]);

    $role->claimConvergence();
    $role->markConvergenceFailed('Check the role', 'node_role.convergence_failed');

    expect($role->refresh()->status)->toBe($after);
    expect($role->failed_step)->toBe($after === LifecycleStatus::Active ? null : 'converge:Check the role');
})->with([
    'active stays active' => ['active', LifecycleStatus::Active],
    'failed role fails again' => ['failed', LifecycleStatus::Failed],
]);
