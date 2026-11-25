<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(SwampDispatch::class, new class
    {
        public function start(Operation $operation): void {}
    });
    SwampNodeFixture::ensure();
    $this->node = Node::withoutEvents(function (): Node {
        $node = Node::query()->create([
            'name' => 'agent-vps', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
            'wireguard_public_key' => 'fixture-wireguard-key',
            'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        ]);
        $node->forceFill(['agent_secret_hash' => hash('sha256', 'fixture-agent-secret')])->save();

        return $node;
    });
});

it('leaves a managed Node that holds only the agent role out of the fleet rollout and peer DNS, and keeps its public SSH recovery', function (): void {
    $this->node->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $node = $this->node->refresh()->load('roles');
    $baseline = static fn (Node $node): array => array_map(static fn ($rule): array => $rule->arguments, app(NodeFirewallRuleCatalog::class)->desiredBaseline($node));
    $recovery = app(NodeFirewallRuleCatalog::class)->publicSshRecovery($node)->arguments;

    expect(app(FleetRolloutMembership::class)->exclusion($node))->toBe('roleless')
        ->and(array_map(static fn (Node $member): int => $member->id, app(FleetRolloutMembership::class)->members()))->not->toContain($node->id)
        ->and(app(PeerDnsPolicy::class)->manages($node))->toBeFalse()
        ->and($baseline($node))->toContain($recovery)
        ->and($baseline($node->unsetRelation('roles')))->toContain($recovery);
});

it('restores public SSH when the last host role leaves, even while the agent role stays', function (): void {
    $this->node->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);
    $appDev = $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Removing]);
    $capture = app(NativeNodeRoleRemovalInput::class);

    $plan = $capture->capture($this->node, $appDev, $capture->publications($this->node, RoleName::AppDev));

    expect($plan['public_ssh'])->toHaveCount(1)
        ->and($plan['public_ssh'][0]['input']['argv'][0])->toBe('ufw');
});

it('refuses an explicit converge of the record-only agent role before any Operation is recorded', function (): void {
    $this->node->roles()->create(['role' => RoleName::Agent, 'status' => LifecycleStatus::Active]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Agent, converge: true))
        ->toThrow(fn (ResourceOperationException $exception) => expect([$exception->errorCode, $exception->status])->toBe(['validation.failed', 422]));

    expect(Operation::query()->where('kind', 'node.role_converge')->exists())->toBeFalse();
});
