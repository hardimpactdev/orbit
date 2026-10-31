<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceCloneAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\Operations\DirectOperationPrivateBindings;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'native-clone-fixture']);
    $this->project = Project::query()->create(['name' => 'Native clone', 'slug' => 'native-clone', 'type' => 'monorepo',
        'repository_url' => 'https://github.com/example/native-clone.git', 'root' => '.', 'default_branch' => 'main']);
    $this->source = Node::query()->create(['name' => 'clone-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->destination = Node::query()->create(['name' => 'clone-prod', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10']);
    orbit_test_set_app_placement_role($this->source, false);
    orbit_test_set_app_placement_role($this->destination, true);
    $this->candidate = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->source->id, 'name' => 'default',
        'status' => 'active', 'provisioning_step' => 'active', 'checkout_path' => '/srv/apps/native-clone/default',
        'source_layout' => 'checkout', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'source_is_laravel' => false]);
    $this->data = new CloneInstanceData($this->destination->id, 'preview', 'preview', null, null);
});

it('records both placements without reading Git or inventing an observed commit', function (): void {
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $target = Instance::query()->findOrFail($operation->target_id);
    expect($operation->kind)->toBe('instance.clone')->and($operation->node_id)->toBe($this->destination->id);
    expect($operation->input['candidate'])->toBe(['node_id' => $this->source->id, 'environment' => 'development',
        'base' => $this->candidate->checkout_path, 'user' => 'orbit', 'repository' => $this->project->repository_url,
        'configured_branch' => 'main', 'target_branch' => 'main', 'expected_source' => $this->candidate->checkout_path]);
    expect($operation->input['candidate_commit'])->toBeNull()->and($target->clone_candidate_commit)->toBeNull();
    expect($target->production_user)->toBe('orbit-app-'.$this->project->id)->and($target->status->value)->toBe('reserved');
    expect($operation->input['participant_node_ids'])->toBe([$this->source->id, $this->destination->id]);
    expect($operation->input['private_bindings']['git_read'])->toBe([
        'owner_node_id' => $this->destination->id, 'read_node_ids' => [$this->source->id, $this->destination->id], 'write_node_ids' => [],
    ]);
    expect(app(DirectOperationPrivateBindings::class)->ownerNode($operation, 'git_read'))->toBe($this->destination->id);
});

it('reserves no target for an inactive candidate', function (): void {
    $this->candidate->update(['status' => 'reserved']);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data))->toThrow(ResourceOperationException::class, 'not active');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
});

it('leaves production release selection to the candidate worker', function (): void {
    $this->source->roles()->delete();
    orbit_test_set_app_placement_role($this->source, true);
    $user = 'orbit-app-'.$this->project->id;
    $this->candidate->update(['production_user' => $user, 'production_home' => '/home/'.$user,
        'checkout_path' => '/home/'.$user.'/releases/current-fixture', 'deployment_branch' => 'release']);
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    expect($operation->input['candidate']['environment'])->toBe('production')
        ->and($operation->input['candidate']['base'])->toBe('/home/'.$user)
        ->and($operation->input['candidate']['user'])->toBe($user)
        ->and($operation->input['candidate']['configured_branch'])->toBe('release')
        ->and($operation->input['candidate']['expected_source'])->toBe('/home/'.$user.'/releases/current-fixture');
});

it('refuses changed clone input without admitting another target', function (): void {
    $first = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $first->update(['status' => 'failed', 'lock_key' => null]);
    $changed = new CloneInstanceData($this->destination->id, 'preview', 'different', null, null);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $changed))->toThrow(ResourceOperationException::class, 'different immutable');
    expect(Instance::query()->where('clone_candidate_id', $this->candidate->id)->count())->toBe(1);
});

it('gives the candidate a read-only source view and only its declared Git material', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'clone-gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->source->accessibleNodes()->attach($gateway);
    $this->destination->accessibleNodes()->attach($gateway);
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $run = '?run_id=a075eb23-7ac4-4487-80d1-57a038a92676';
    $url = '/api/v1/direct-operations/'.$operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->source->wireguard_ip]);
    $this->getJson($url.$run)->assertOk()->assertJsonPath('data.input.direct_provisioning.candidate.node_id', $this->source->id)
        ->assertJsonMissingPath('data.input.environment_material');
    $private = $this->getJson($url.'/private-material'.$run)->assertOk();
    expect(array_keys($private->json('data')))->toBe(['git_read']);
    $body = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded',
        'started_at' => now()->format('Y-m-d\TH:i:s.v\Z'), 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'result' => ['instance_id' => $operation->target_id, 'ready' => true]];
    $this->postJson($url.'/result', $body)->assertForbidden();
    $other = Node::query()->create(['name' => 'clone-other', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.11', 'wireguard_ip' => '10.44.0.11']);
    $other->accessibleNodes()->attach($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->getJson($url.$run)->assertForbidden();
    $this->getJson($url.'/private-material'.$run)->assertForbidden();
});

it('projects native source and completion through the destination without child proofs', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create(['name' => 'clone-gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->destination->accessibleNodes()->attach($gateway);
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $this->withServerVariables(['REMOTE_ADDR' => $this->destination->wireguard_ip]);
    $url = '/api/v1/direct-operations/'.$operation->id.'/result';
    $base = ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'started_at' => now()->format('Y-m-d\TH:i:s.v\Z')];
    $this->postJson($url, [...$base, 'status' => 'running', 'result' => ['phase' => 'source', 'branch' => 'main',
        'commit' => str_repeat('c', 40), 'candidate_commit' => str_repeat('c', 40), 'php_version' => null, 'laravel' => false]])->assertOk();
    $target = Instance::query()->findOrFail($operation->target_id);
    expect($target->status->value)->toBe('source_resolved')->and($target->clone_candidate_commit)->toBe(str_repeat('c', 40));
    $done = [...$base, 'status' => 'succeeded', 'finished_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
        'result' => ['instance_id' => $target->id, 'ready' => true]];
    $this->postJson($url, $done)->assertOk()->assertJsonPath('data.status', 'succeeded');
    $completed = $target->refresh()->clone_completed_at;
    $this->postJson($url, $done)->assertOk();
    expect($target->refresh()->status->value)->toBe('active')->and($target->clone_completed_at)->toEqual($completed);
    expect($operation->refresh()->lock_key)->toBeNull();
});
