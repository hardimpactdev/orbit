<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\NodeFootprint;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use Tests\Support\Fleet\FakeFootprintArtifact;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.1', 'status' => LifecycleStatus::Active]);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->worker = Node::query()->create(['name' => 'worker', 'public_ssh_host' => '192.0.2.17', 'user' => 'orbit', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.17', 'status' => LifecycleStatus::Active]);
    $this->worker->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->worker->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->worker->wireguard_ip]);
    $this->node = Node::query()->create(['ssh_host_fingerprint' => 'SHA256:test', 'name' => 'canary', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']);
    config(['swamp_workers.nodes' => [$this->node->id]]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->snapshot = ['schema_version' => 1, 'node_id' => $this->node->id, 'agent_sha256' => str_repeat('a', 64),
        'artifacts' => [['name' => 'agent', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false],
            ['name' => 'tmpfiles', 'digest' => str_repeat('b', 64), 'required' => true, 'repair' => false]],
        'payloads' => [], 'network' => null, 'refusal' => null, 'force_artifacts' => false, 'first_visit' => false];
    $this->operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'input' => ['_converge' => $this->snapshot, 'secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => $this->node->id]],
        'state' => ['node_converge_writer' => 'direct', 'swamp_run_id' => $this->run]]);
    $this->body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => null, 'result' => ['footprint_proven' => true, 'artifacts' => [
            ['name' => 'agent', 'status' => 'unchanged'], ['name' => 'tmpfiles', 'status' => 'applied'],
        ]]];
});

it('projects bounded artifact digests once without changing roles or the agent hash', function (): void {
    $this->node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['other' => 'retained']]);
    app(NodeConvergeProjection::class)->apply($this->operation, $this->body);
    $record = NodeFootprint::query()->where('node_id', $this->node->id)->firstOrFail();
    expect($record->artifacts)->toBe(['agent' => str_repeat('a', 64), 'tmpfiles' => str_repeat('b', 64)]);
    $before = $record->updated_at;
    $this->travel(1)->minutes();
    app(NodeConvergeProjection::class)->apply($this->operation, $this->body);
    expect($record->fresh()->updated_at->equalTo($before))->toBeTrue();
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
    expect($this->node->roles()->sole()->role)->toBe(RoleName::AppDev);
});

it('retains unreached artifacts and removes only a known failed digest', function (): void {
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['agent' => 'old-agent', 'tmpfiles' => 'old-tmpfiles', 'other' => 'retained']]);
    $body = [...$this->body, 'status' => 'failed', 'error_code' => 'agent.secret_mismatch', 'result' => [
        'footprint_proven' => false, 'error_code' => 'agent.secret_mismatch', 'failed_artifact' => 'agent', 'artifacts' => [],
    ]];
    app(NodeConvergeProjection::class)->apply($this->operation, $body);
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts)->toBe(['other' => 'retained', 'tmpfiles' => 'old-tmpfiles']);
});

it('projects residue cleanup only onto unchanged admitted rows', function (): void {
    $first = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91001, 'domain' => 'first.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    $changed = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91002, 'domain' => 'changed.orbit', 'steps' => ['php'], 'attempts' => 0]);
    $snapshot = [...$this->snapshot, 'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false]],
        'payloads' => ['route-residue' => ['rows' => [$first->only(['id', 'route_id', 'domain', 'steps', 'attempts']), $changed->only(['id', 'route_id', 'domain', 'steps', 'attempts'])]]]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $changed->increment('attempts');
    $later = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91003, 'domain' => 'later.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    app(NodeConvergeProjection::class)->apply($this->operation, [...$this->body, 'result' => ['footprint_proven' => true, 'artifacts' => [['name' => 'route-residue', 'status' => 'applied']]]]);
    expect($first->fresh())->toBeNull();
    expect($changed->fresh()->attempts)->toBe(1);
    expect($later->fresh())->not->toBeNull();
});

it('counts a known residue skip once and retains its digest for the next repair', function (): void {
    $row = RouteRemovalResidue::query()->create(['node_id' => $this->node->id, 'route_id' => 91001, 'domain' => 'pending.orbit', 'steps' => ['caddy'], 'attempts' => 0]);
    $snapshot = [...$this->snapshot, 'artifacts' => [['name' => 'route-residue', 'digest' => str_repeat('a', 64), 'required' => true, 'repair' => false]],
        'payloads' => ['route-residue' => ['rows' => [$row->only(['id', 'route_id', 'domain', 'steps', 'attempts'])]]]];
    $this->operation->update(['input' => ['_converge' => $snapshot]]);
    $body = [...$this->body, 'result' => ['footprint_proven' => true, 'artifacts' => [['name' => 'route-residue', 'status' => 'skipped', 'reason' => 'route_residue_cleanup_failed']]]];
    app(NodeConvergeProjection::class)->apply($this->operation, $body);
    app(NodeConvergeProjection::class)->apply($this->operation, $body);
    expect($row->fresh()->attempts)->toBe(1);
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts)->toBe(['route-residue' => str_repeat('a', 64)]);
});

it('captures the active VPN listener even when the Gateway and VPN share one Node', function (): void {
    $this->gateway->forceFill(['architecture' => 'x86_64', 'ssh_host_fingerprint' => 'SHA256:test'])->save();
    $this->gateway->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    $agents->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $agents);
    $snapshot = app(NodeConvergeInput::class)->capture($this->gateway->fresh(), true, false);
    expect(array_column($snapshot['artifacts'], 'name'))->toContain('private-dns');
    expect($snapshot['payloads']['private-dns']['listener']['service'])->toContain($this->gateway->wireguard_ip);
    expect(array_keys($snapshot['payloads']['private-dns']['publication']))->toBe(['records', 'catalog']);
});

it('refuses an unsupported contribution before effects even when its digest matches', function (): void {
    $artifact = new FakeFootprintArtifact('unsupported-extension', 'v1');
    $delivery = app(AgentDelivery::class);
    $footprint = new App\Domain\Fleet\NodeFootprint($delivery, [$artifact]);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => $footprint->expected($this->node)->artifacts]);

    $snapshot = (new NodeConvergeInput($footprint, $delivery, [$artifact]))->capture($this->node, false, false);

    expect($snapshot['refusal'])->toBe('operation.kind_unavailable');
    expect($artifact->applied)->toBe([]);
});

it('clears only the failed agent pin proven by this native result', function (bool $matching): void {
    $failedPin = str_repeat($matching ? 'a' : 'b', 64);
    $this->node->forceFill(['agent_failed_pin' => $failedPin])->save();
    app(NodeConvergeProjection::class)->apply($this->operation, $this->body);
    expect($this->node->fresh()->agent_failed_pin)->toBe($matching ? null : $failedPin);
})->with([true, false]);
