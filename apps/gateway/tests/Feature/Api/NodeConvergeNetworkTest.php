<?php

declare(strict_types=1);

use App\Actions\Fleet\AdmitNodeRecipeAction;
use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeNetworkConvergeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Fleet\Footprint\NetworkFootprintArtifact;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Cluster;
use App\Models\NodeFootprint;
use App\Models\Operation;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->home = sys_get_temp_dir().'/orbit-network-'.bin2hex(random_bytes(5));
    mkdir($this->home.'/wireguard', 0700, true);
    $this->hubKey = str_repeat('B', 43).'=';
    $this->peerKey = str_repeat('A', 43).'=';
    file_put_contents($this->home.'/wireguard/public.key', $this->hubKey);
    config(['orbit.home' => $this->home]);
    $this->hub = FleetFixtures::node('network-hub', [RoleName::Vpn]);
    $this->node = FleetFixtures::node('network-peer');
    $this->node->update(['wireguard_public_key' => $this->peerKey]);
    FleetFixtures::bind([new NetworkFootprintArtifact]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-node-converge-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fake.token']);
});

afterEach(function (): void {
    File::deleteDirectory($this->home);
});

it('captures one digest and checks the live network even when that digest matches', function (): void {
    $input = app(NodeConvergeInput::class)->captureNative($this->node, false, false);
    expect($input['network_mode'])->toBe('peer')
        ->and(array_column($input['artifacts'], 'name'))->toBe(['network'])
        ->and($input['network'])->toMatchArray(['mode' => 'peer', 'hub_id' => $this->hub->id, 'required' => true])
        ->and($input['network']['observations'][1]['peers'][0]['public_key'])->toBe($this->peerKey);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['network' => $input['artifacts'][0]['digest']]]);
    expect(app(NodeConvergeInput::class)->captureNative($this->node, false, false)['network']['required'])->toBeFalse();
    $this->node->update(['wireguard_endpoint_override' => '192.0.2.99:51999']);
    expect(app(NodeConvergeInput::class)->captureNative($this->node->fresh(), false, false)['network']['required'])->toBeTrue();
});

it('captures a safe child selection when no VPN hub exists', function (): void {
    $this->hub->roles()->delete();
    $input = app(NodeConvergeInput::class)->captureNative($this->node, false, false);
    expect($input['network'])->toBeNull()->and($input['network_mode'])->toBe('peer');
});

it('changes the network digest when a recorded private namespace changes', function (): void {
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $this->node->refresh()->load('roles');
    $artifact = app(NetworkFootprintArtifact::class);
    $before = $artifact->digest($this->node);
    Cluster::query()->create(['name' => 'private-network', 'tld' => 'private.test']);
    expect($artifact->digest($this->node))->not->toBe($before)
        ->and(array_map(static fn ($entry): string => $entry->name(), [...app()->tagged(NodeFootprintArtifact::class)]))->toContain('network');
});

it('captures the VPN hub complete peer list instead of a peer bootstrap', function (): void {
    $input = app(NodeNetworkConvergeInput::class)->converge($this->hub);
    expect(app(NodeConvergeInput::class)->captureNative($this->hub, false, false)['network_mode'])->toBe('hub');
    expect($input['mode'])->toBe('hub')->and($input['apply']['peer_keys'])->toBe([$this->peerKey])
        ->and($input['observations'][0]['exact'])->toBeTrue()
        ->and($input['observations'][0]['peers'][0]['allowed_ips'])->toBe([$this->node->wireguard_ip.'/32']);
});

it('reserves the Node and shared hub for the whole native converge', function (): void {
    $operation = app(StartNodeConvergeAction::class)->execute($this->node);
    expect($operation->lock_key)->toBe('node-converge:node:'.$this->node->id)
        ->and($operation->input['participant_node_ids'])->toContain($this->node->id, $this->hub->id);
    expect(fn () => app(StartNodeConvergeAction::class)->execute(FleetFixtures::node('other-peer')))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
});

it('honors a recipe parent holding the same shared hub', function (): void {
    Operation::query()->create(['kind' => 'fleet.recipe', 'target_type' => 'node', 'target_id' => $this->hub->id,
        'node_id' => $this->hub->id, 'input' => ['footprint' => ['network' => ['hub_id' => $this->hub->id]]], 'status' => 'running', 'sequence' => 1]);
    expect(fn () => app(StartNodeConvergeAction::class)->execute($this->node))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
});

it('refuses recipe admission while converge holds the shared hub', function (): void {
    app(StartNodeConvergeAction::class)->execute($this->node);
    expect(fn () => app(AdmitNodeRecipeAction::class)->execute(
        FleetFixtures::node('recipe-other-peer'), new RecipeLoader()->load()))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
});

it('holds a timed-out native network writer until it settles', function (): void {
    $expired = Operation::query()->create(['kind' => 'fleet.recipe', 'target_type' => 'node', 'target_id' => $this->hub->id,
        'node_id' => $this->hub->id, 'input' => ['footprint' => ['network' => ['hub_id' => $this->hub->id]]],
        'status' => 'failed', 'error_code' => 'operation.timed_out', 'lock_key' => 'recipe:node:'.$this->hub->id, 'sequence' => 1]);
    expect(fn () => app(StartNodeConvergeAction::class)->execute($this->node))
        ->toThrow(ResourceOperationException::class, 'Another Operation owns the Node or VPN hub.');
    $expired->update(['lock_key' => null]);
    expect(app(StartNodeConvergeAction::class)->execute($this->node)->wasRecentlyCreated)->toBeTrue();
});

it('projects a no-drift skip and retains the previous digest on failed handshake', function (bool $failed): void {
    $snapshot = app(NodeConvergeInput::class)->captureNative($this->node, false, false);
    NodeFootprint::query()->create(['node_id' => $this->node->id, 'artifacts' => ['network' => 'previous-digest']]);
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'input' => ['_converge' => $snapshot], 'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
    $projected = app(NodeConvergeProjection::class)->apply($operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'status' => $failed ? 'failed' : 'succeeded', 'result' => ['steps' => [
        'Decide the network change' => ['status' => 'succeeded'], 'Apply recorded network' => ['status' => $failed ? 'succeeded' : 'skipped'],
        'Check the handshake' => ['status' => $failed ? 'failed' : 'skipped'],
    ]]]);
    expect(NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts['network'])->toBe($failed ? 'previous-digest' : $snapshot['artifacts'][0]['digest']);
    if (! $failed) {
        expect($projected->result['artifacts'])->toBe([['name' => 'network', 'status' => 'unchanged']]);
    }
})->with([true, false]);

it('removes both independent command routes and kinds', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->hub->wireguard_ip]);
    $this->postJson('/api/v1/nodes/'.$this->node->id.'/network/converge')->assertNotFound();
    $this->postJson('/api/v1/nodes/'.$this->hub->id.'/wireguard-hub/converge')->assertNotFound();
    expect(app(DirectOperation::class)->definition('node.network_converge'))->toBeNull()
        ->and(app(DirectOperation::class)->definition('node.wireguard_hub_converge'))->toBeNull();
});
