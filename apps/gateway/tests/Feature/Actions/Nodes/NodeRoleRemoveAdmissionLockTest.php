<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Nodes\StartNodeConvergeAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeRoleRemoveInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

const REMOVE_LOCK_DIGESTS = ['agent' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    SwampNodeFixture::ensure();
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'remove-lock', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
            'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        ]);
        $node->forceFill(['agent_secret_hash' => hash('sha256', 'fixture-agent-secret')])->save();

        return $node;
    });
    Node::query()->whereKey($this->node->id)->update(['converge_digests' => json_encode(REMOVE_LOCK_DIGESTS)]);
});

function removeLockAssignment(Node $node, string $status = 'active', int $age = 0, bool $untouched = false): NodeRole
{
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $status,
        'failed_step' => $status === 'failed' ? 'converge:Require supported Ubuntu' : null, 'node_untouched' => $untouched]);
    NodeRole::query()->whereKey($assignment->id)->update(['updated_at' => now()->subSeconds($age)]);

    return $assignment->refresh();
}

function removeLockHeld(Node $node, string $kind): Operation
{
    return Operation::query()->create(['kind' => $kind, 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'lock_key' => 'node:'.$node->id, 'input' => [], 'status' => 'queued', 'sequence' => 1, 'attempts' => 0]);
}

/** @return array<string,mixed>|null */
function removeLockDigests(Node $node): ?array
{
    return $node->refresh()->converge_digests;
}

/** A node converge admission under the Node lock, as StartNodeConvergeAction takes it, without its run or its capture. */
function removeLockConverge(Node $node): Operation
{
    return app(StartOperationAction::class)->execute('node.converge', 'node', $node->id, $node->id, [], 'operator', null,
        lockKey: OperationStore::lockKey('node', $node->id), start: false);
}

function removeLockRemove(Node $node): Operation
{
    return app(AdmitNodeRoleOperationAction::class)->remove($node->refresh(), RoleName::Database, force: true);
}

it('refuses a role remove while another Operation holds the Node, and changes nothing', function (string $kind, bool $untouched): void {
    $assignment = removeLockAssignment($this->node, $untouched ? 'failed' : 'active', untouched: $untouched);
    $before = $assignment->getRawOriginal();
    $held = removeLockHeld($this->node, $kind);

    expect(fn () => removeLockRemove($this->node))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy')
            ->and($error->status)->toBe(409));

    expect(Operation::query()->pluck('id')->all())->toBe([$held->id])
        ->and($held->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($assignment->refresh()->getRawOriginal())->toBe($before)
        ->and(removeLockDigests($this->node))->toBe(REMOVE_LOCK_DIGESTS);
    Http::assertNothingSent();
})->with([
    'node.converge, run path' => ['node.converge', false],
    'node.role_add, run path' => ['node.role_add', false],
    'node.converge, record-only path' => ['node.converge', true],
    'node.role_add, record-only path' => ['node.role_add', true],
]);

it('refuses role add and node converge while a role remove holds the Node', function (): void {
    Queue::fake();
    removeLockAssignment($this->node);
    $remove = removeLockRemove($this->node);

    foreach ([
        fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node->refresh(), RoleName::WebSocket),
        fn () => app(StartNodeConvergeAction::class)->execute($this->node->refresh(), base: true),
    ] as $admit) {
        expect($admit)->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.target_busy'));
    }

    expect(Operation::query()->pluck('id')->all())->toBe([$remove->id]);
});

it('refuses a fresh provisioning or removing claim with its own code and changes nothing', function (string $status, int $age): void {
    $this->freezeTime();
    $assignment = removeLockAssignment($this->node, $status, $age);
    $before = $assignment->getRawOriginal();

    expect(fn () => removeLockRemove($this->node))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node_role.claim_busy')
            ->and($error->status)->toBe(409)
            ->and($error->details)->toMatchArray(['reason' => 'claim_active', 'status' => $status, 'role' => 'database']));

    expect($assignment->refresh()->getRawOriginal())->toBe($before)
        ->and(Operation::query()->count())->toBe(0)
        ->and(removeLockDigests($this->node))->toBe(REMOVE_LOCK_DIGESTS);
    Http::assertNothingSent();
})->with([
    'fresh provisioning' => ['provisioning', 0],
    'provisioning just before the threshold' => ['provisioning', NodeRole::StaleClaimSeconds - 1],
    'fresh removing' => ['removing', 0],
    'removing just before the threshold' => ['removing', NodeRole::StaleClaimSeconds - 1],
]);

it('takes over a stale provisioning or removing claim', function (string $status): void {
    $this->freezeTime();
    $assignment = removeLockAssignment($this->node, $status, NodeRole::StaleClaimSeconds);

    $operation = removeLockRemove($this->node);

    expect($operation->kind)->toBe('node.role_remove')
        ->and($operation->lock_key)->toBe('node:'.$this->node->id)
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($assignment->isStaleClaim())->toBeFalse();
})->with(['provisioning', 'removing']);

it('takes the Node lock and clears the converge digests on the run path', function (): void {
    removeLockAssignment($this->node);

    $operation = removeLockRemove($this->node);

    expect($operation->status)->toBe(OperationStatus::Queued)
        ->and($operation->refresh()->lock_key)->toBe('node:'.$this->node->id)
        ->and(removeLockDigests($this->node))->toBeNull();
});

it('takes and releases the Node lock and clears the converge digests on the record-only path', function (): void {
    $assignment = removeLockAssignment($this->node, 'failed', untouched: true);

    $operation = removeLockRemove($this->node);

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->refresh()->lock_key)->toBeNull()
        ->and(NodeRole::query()->whereKey($assignment->id)->exists())->toBeFalse()
        ->and(removeLockDigests($this->node))->toBeNull();
    expect(removeLockConverge($this->node)->lock_key)->toBe('node:'.$this->node->id);
});

it('releases the Node lock when the removal run ends, so a converge is admitted', function (string $status): void {
    Queue::fake();
    removeLockAssignment($this->node);
    $operation = removeLockRemove($this->node);
    expect($operation->lock_key)->toBe('node:'.$this->node->id);
    $operation->forceFill(['status' => 'running', 'state' => [...($operation->state ?? []), 'swamp_run_id' => 'fixture-run']])->save();

    app(NodeRoleRemovalProjection::class)->apply($operation, ['run_id' => 'fixture-run', 'status' => $status,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => $status === 'succeeded'
            ? ['steps' => ['Confirm role removal' => ['status' => 'succeeded']], 'summary' => ['failed_steps' => []]]
            : ['error_code' => 'node.removal_incomplete', 'failed_step' => 'Remove the role',
                'steps' => ['Remove the role' => ['status' => 'failed']], 'summary' => ['failed_steps' => ['Remove the role']]]]);

    expect($operation->refresh()->status->value)->toBe($status)
        ->and($operation->lock_key)->toBeNull();
    expect(removeLockConverge($this->node)->lock_key)->toBe('node:'.$this->node->id);
})->with(['succeeded', 'failed']);

it('releases the Node lock when the removal is cancelled before its run', function (): void {
    removeLockAssignment($this->node);
    $operation = removeLockRemove($this->node);
    expect($operation->lock_key)->toBe('node:'.$this->node->id);

    app(CancelOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($operation->lock_key)->toBeNull();
    expect(removeLockConverge($this->node)->lock_key)->toBe('node:'.$this->node->id);
});

it('leaves no Operation and no lock when admission throws after taking the lock', function (): void {
    $assignment = removeLockAssignment($this->node);
    app()->instance(NodeRoleRemoveInput::class, new class
    {
        public function capture(): never
        {
            throw new RuntimeException('capture failed');
        }
    });

    expect(fn () => removeLockRemove($this->node))->toThrow(RuntimeException::class, 'capture failed');

    expect(Operation::query()->count())->toBe(0)
        ->and($assignment->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(removeLockDigests($this->node))->toBe(REMOVE_LOCK_DIGESTS);
    app()->forgetInstance(NodeRoleRemoveInput::class);
    expect(removeLockRemove($this->node)->lock_key)->toBe('node:'.$this->node->id);
});

it('returns the claim refusal over the API', function (): void {
    Queue::fake();
    removeLockAssignment($this->node, 'removing');
    $caller = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'operator', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.50', 'public_ssh_host' => '10.44.0.50', 'user' => 'orbit']));
    $caller->accessibleNodes()->attach([$this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);

    $this->deleteJson('/api/v1/nodes/'.$this->node->id.'/roles/database', ['force' => true])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'node_role.claim_busy')
        ->assertJsonPath('error.details.reason', 'claim_active')
        ->assertJsonPath('error.details.status', 'removing');

    expect(Operation::query()->count())->toBe(0);
});

it('withdraws the wake directory boot rule when the app-dev role leaves', function (): void {
    $assignment = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Removing]);
    $capture = app(NativeNodeRoleRemovalInput::class);
    $plan = $capture->capture($this->node, $assignment, $capture->publications($this->node, RoleName::AppDev));

    expect(array_column($plan['files'], 'path'))->toContain(TmpfilesFootprintArtifact::Path);
});
