<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceCloneAction;
use App\Actions\Operations\CancelOperationAction;
use App\Data\Instances\CloneInstanceData;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\InstanceCloneProjection;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $agent = Mockery::mock(AgentCommandRunner::class);
    $agent->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agent);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $sent): array => $sent['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-instance-clone', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'native-clone-fixture']);
    $this->project = Project::query()->create(['name' => 'Native clone', 'slug' => 'native-clone', 'type' => 'monorepo',
        'repository_url' => 'https://github.com/example/native-clone.git', 'root' => '.', 'default_branch' => 'main']);
    $this->source = Node::query()->create(['name' => 'clone-dev', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    $this->destination = Node::query()->create(['name' => 'clone-prod', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10']);
    orbit_test_set_app_placement_role($this->source, false);
    orbit_test_set_app_placement_role($this->destination, false);
    foreach ([$this->source, $this->destination] as $node) {
        $node->update(['settings' => ['apps' => ['path' => '/srv/apps']], 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    }
    $this->candidate = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->source->id, 'name' => 'default',
        'status' => 'active', 'provisioning_step' => 'active', 'checkout_path' => '/srv/apps/native-clone/default',
        'source_layout' => 'checkout', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'source_is_laravel' => false]);
    $this->data = new CloneInstanceData($this->destination->id, 'preview', 'preview', null, null);
});

it('captures development placement and independent environment without reading the candidate', function (): void {
    InstanceEnvironmentValue::query()->create(['instance_id' => $this->candidate->id, 'env_key' => 'APP_KEY', 'env_value' => 'private-clone-key']);
    InstanceEnvironmentValue::query()->create(['instance_id' => $this->candidate->id, 'env_key' => 'DB_PASSWORD', 'env_value' => 'excluded-database-key']);
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $target = Instance::query()->findOrFail($operation->target_id);
    expect($operation->kind)->toBe('instance.clone')->and($operation->node_id)->toBe($this->destination->id)
        ->and($operation->input['candidate'])->toBe(['node_id' => $this->source->id, 'checkout' => $this->candidate->checkout_path,
            'branch' => 'main', 'repository_identity' => $this->project->repository_identity])
        ->and($target->status->value)->toBe('reserved')->and($target->production_user)->toBeNull()
        ->and($target->checkout_path)->toBe('/srv/apps/native-clone/preview')->and($target->clone_candidate_commit)->toBeNull();
    expect(app(InstanceEnvironmentStore::class)->values($target))->toBe(['APP_KEY' => 'private-clone-key'])
        ->and(json_encode($operation->input))->not->toContain('private-clone-key')->not->toContain('excluded-database-key')
        ->and($operation->input['direct_provisioning']['resolve']['require_branch'])->toBeTrue();
});

it('reserves no target for an inactive or unclassified candidate', function (array $change, string $message): void {
    $this->candidate->update($change);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data))->toThrow(ResourceOperationException::class, $message);
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
})->with([[['status' => 'reserved'], 'must be active'], [['source_is_laravel' => null], 'no recorded source profile']]);

it('refuses production and unsupported SQLite variants before target reservation', function (bool $production): void {
    if ($production) {
        $this->destination->roles()->delete();
        orbit_test_set_app_placement_role($this->destination, true);
    }
    $data = new CloneInstanceData($this->destination->id, 'preview', 'preview', null, $production ? null : '/srv/source.sqlite');
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $data))->toThrow(ResourceOperationException::class);
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0);
})->with([true, false]);

it('refuses changed immutable clone input without admitting another target', function (): void {
    $first = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $first->update(['status' => 'failed', 'lock_key' => null]);
    $changed = new CloneInstanceData($this->destination->id, 'preview', 'different', null, null);
    expect(fn () => app(StartInstanceCloneAction::class)->execute($this->candidate, $changed))->toThrow(ResourceOperationException::class, 'different immutable');
    expect(Instance::query()->where('clone_candidate_id', $this->candidate->id)->count())->toBe(1);
});

it('supplies full public clone intent and only named private execution copies', function (): void {
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $input = app(InstanceCloneProjection::class)->workflowInput($operation);
    expect($input['intent']['candidate']['checkout'])->toBe($this->candidate->checkout_path)
        ->and($input['intent']['candidate']['ssh_execution']['target']['host_keys'])->toBe([$this->source->ssh_host_key_type.' '.$this->source->ssh_host_key])
        ->and($input['execution_secrets'])->toBe(['git_read' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-git-operation-'.$operation->id],
            'environment' => ['vault' => 'orbit-control-inputs', 'key' => 'instance-clone-environment-operation-'.$operation->id]])
        ->and($input)->not->toHaveKeys(['private_bindings', 'private_material'])
        ->and(app(DirectOperation::class)->definition('instance.clone')['terminal_result'])->toBe(['job' => 'clone', 'step' => 'result']);
});

it('projects only the native terminal clone result and preserves the candidate', function (): void {
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => ['swamp_run_id' => $run]]);
    $target = Instance::query()->findOrFail($operation->target_id);
    $done = ['run_id' => $run, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_id' => $target->id, 'ready' => true, 'branch' => 'main', 'commit' => str_repeat('c', 40),
            'candidate_commit' => str_repeat('c', 40), 'php_version' => null, 'laravel' => false, 'environment_keys' => ['APP_KEY']]];
    app(InstanceCloneProjection::class)->apply($operation, $done);
    $completed = $target->refresh()->clone_completed_at;
    app(InstanceCloneProjection::class)->apply($operation, $done);
    expect($target->refresh()->status->value)->toBe('active')->and($target->starting_commit)->toBe(str_repeat('c', 40))
        ->and($target->clone_candidate_commit)->toBe(str_repeat('c', 40))->and($target->clone_completed_at)->toEqual($completed)
        ->and($target->environment_owned_keys)->toBe(['APP_KEY'])->and($operation->refresh()->lock_key)->toBeNull()
        ->and($this->candidate->refresh()->starting_commit)->toBe(str_repeat('a', 40));
});

it('leaves a failed clone reserved for a guarded retry', function (): void {
    $operation = app(StartInstanceCloneAction::class)->execute($this->candidate, $this->data);
    $run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $operation->update(['state' => ['swamp_run_id' => $run]]);
    app(InstanceCloneProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.branch_not_found', 'result' => []]);
    expect(Instance::query()->findOrFail($operation->target_id)->status->value)->toBe('reserved')
        ->and($operation->refresh()->status->value)->toBe('failed');
});

it('sends API cancellation to each converted native family while retaining native terminal authority', function (string $kind): void {
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/fixture/public-ca.pem']);
    Http::fake(['https://swamp.orbit/api/v1/cancel/workflow-run/*' => Http::response(['status' => 'cancellation_requested'])]);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $this->candidate->id,
        'node_id' => $this->source->id, 'status' => 'running', 'attempts' => 1,
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'input' => []]);
    app(CancelOperationAction::class)->execute($operation);
    Http::assertSentCount(1);
    expect($operation->refresh()->status->value)->toBe('running')->and($operation->cancel_requested_at)->not->toBeNull();
})->with(['instance.clone', 'instance.register', 'instance.rename', 'instance.transfer']);
