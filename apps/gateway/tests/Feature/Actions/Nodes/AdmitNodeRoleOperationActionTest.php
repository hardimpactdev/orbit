<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('refuses a held Node without changing the assignment or starting another Operation', function (bool $converge): void {
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'held', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    if ($converge) {
        $node->roles()->create(['role' => RoleName::Database, 'status' => 'active']);
    }
    $held = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'lock_key' => 'node:'.$node->id, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Database, $converge))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));

    expect(Operation::query()->pluck('id')->all())->toBe([$held->id]);
    expect($node->roles()->pluck('status')->all())->toBe($converge ? [LifecycleStatus::Active] : []);
    Http::assertNothingSent();
})->with(['addition' => false, 'convergence' => true]);
