<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

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

it('refuses ineligible convergence claims before changing recorded intent', function (string $status, ?string $step, int $age): void {
    Http::preventStrayRequests();
    $this->freezeTime();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'claim', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $status, 'failed_step' => $step]);
    $assignment->forceFill(['updated_at' => now()->subSeconds($age)])->save();
    $before = $assignment->refresh()->getRawOriginal();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Database, true))
        ->toThrow(RoleAssignmentException::class);

    expect($assignment->refresh()->getRawOriginal())->toBe($before)
        ->and(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'recent provisioning' => ['provisioning', 'prerequisites', 0],
    'just before stale threshold' => ['provisioning', 'prerequisites', 659],
    'failed initial add' => ['failed', 'prerequisites', 700],
    'removing' => ['removing', null, 700],
]);

it('admits eligible convergence with one Operation and no host contact', function (string $status, ?string $step, int $age): void {
    app()->instance(SwampDispatch::class, $dispatch = new class
    {
        /** @var list<string> */
        public array $started = [];

        public function start(Operation $operation): void
        {
            $this->started[] = $operation->id;
        }
    });
    Http::preventStrayRequests();
    $this->freezeTime();
    SwampNodeFixture::ensure();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'claim', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $status, 'failed_step' => $step]);
    $assignment->forceFill(['updated_at' => now()->subSeconds($age)])->save();

    $operation = app(AdmitNodeRoleOperationAction::class)->add($node, RoleName::Database, true);

    expect($operation->kind)->toBe('node.role_converge')
        ->and(Operation::query()->count())->toBe(1)
        ->and($operation->input['_role']['assignment_id'])->toBe($assignment->id)
        ->and($operation->input['_role']['created'])->toBeFalse()
        ->and($assignment->refresh()->status)->toBe($status === 'active' ? LifecycleStatus::Active : LifecycleStatus::Provisioning)
        ->and($assignment->failed_step)->toBeNull()
        ->and($dispatch->started)->toBe([$operation->id]);
    Http::assertNothingSent();
})->with([
    'active' => ['active', null, 0],
    'failed convergence' => ['failed', 'converge:Check the role', 0],
    'at the stale threshold' => ['provisioning', 'prerequisites', 660],
]);

it('refuses role removal while the Node Operation lock is held', function (): void {
    Http::preventStrayRequests();
    $node = roleRemovalAdmissionNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => 'active']);
    $held = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'lock_key' => 'node:'.$node->id, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
    $before = $assignment->refresh()->getRawOriginal();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, true, offline: true))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    expect(Operation::query()->pluck('id')->all())->toBe([$held->id])
        ->and($assignment->refresh()->getRawOriginal())->toBe($before);
    Http::assertNothingSent();
});

it('refuses live removal claims without force bypass or mutation', function (string $status): void {
    Http::preventStrayRequests();
    $node = roleRemovalAdmissionNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $status]);
    $before = $assignment->refresh()->getRawOriginal();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, true, true, true))
        ->toThrow(NodeRoleValidationException::class);
    expect($assignment->refresh()->getRawOriginal())->toBe($before)->and(Operation::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with(['provisioning', 'removing']);

it('admits active failed and stale removal assignments with offline consent and the Node lock', function (string $status, int $age): void {
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    Http::preventStrayRequests();
    $this->freezeTime();
    SwampNodeFixture::ensure();
    $node = roleRemovalAdmissionNode();
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $status]);
    $assignment->forceFill(['updated_at' => now()->subSeconds($age)])->save();

    $operation = app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, true, offline: true);

    expect($operation)->not->toBeNull()
        ->and($operation->lock_key)->toBe('node:'.$node->id)
        ->and($operation->input['intent']['offline'])->toBeTrue()
        ->and($operation->input['_role_remove']['assignment_id'])->toBe($assignment->id)
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Removing);
    Http::assertNothingSent();
})->with([['active', 0], ['failed', 0], ['provisioning', 660], ['removing', 660]]);

it('returns an absent ingress without creating an Operation and returns 404 for other missing assignments', function (): void {
    Http::preventStrayRequests();
    $node = roleRemovalAdmissionNode();
    expect(app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Ingress, true))->toBeNull();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($node, RoleName::Database, true))
        ->toThrow(ModelNotFoundException::class);
    expect(Operation::query()->count())->toBe(0)->and($node->roles()->count())->toBe(0);
    Http::assertNothingSent();
});

function roleRemovalAdmissionNode(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'remove-target', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.22', 'public_ssh_host' => '10.44.0.22', 'public_ssh_port' => 22, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
}
