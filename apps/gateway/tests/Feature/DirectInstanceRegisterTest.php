<?php

declare(strict_types=1);

use App\Actions\Instances\RegisterInstanceAction;
use App\Data\Instances\RegisterInstanceData;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Register proof', 'slug' => 'register-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'register-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'settings' => ['apps' => ['path' => '/srv/apps']]]);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/register-proof/proof', 'source_layout' => 'checkout', 'status' => 'reserved']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.register', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['checkout' => $this->instance->checkout_path, 'repository' => $this->project->repository_url, 'repository_identity' => $this->project->repository_identity]]);
    $this->result = ['registered' => true, 'branch' => 'feature', 'commit' => str_repeat('a', 40), 'detached' => false, 'digest' => str_repeat('b', 64), 'common' => $this->instance->checkout_path.'/.git', 'default_branch' => 'main', 'inferred_slug' => 'proof'];
});

it('projects native adoption and source facts into the reserved Instance', function (): void {
    $hook = app(InstanceRegisterResult::class);
    $hook->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => $this->result]);
    expect($this->instance->refresh()->status->value)->toBe('active')
        ->and($this->instance->branch)->toBe('feature')->and($this->instance->starting_commit)->toBe(str_repeat('a', 40))
        ->and($this->instance->registration_relocation_state)->toBe('relocated')->and($this->instance->registration_completed_at)->not->toBeNull();
});

it('keeps failed adoption reserved for a later retry', function (): void {
    app(InstanceRegisterResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => 'instance.source_invalid', 'result' => ['error_code' => 'instance.source_invalid']]);
    expect($this->instance->refresh()->status->value)->toBe('reserved')->and($this->instance->registration_completed_at)->toBeNull();
});

it('refuses unsupported registration before another row or Operation is reserved', function (array $change): void {
    $values = [...['sourcePath' => '/srv/apps/register-proof/other', 'includeWorktrees' => false, 'projectId' => $this->project->id, 'instanceName' => 'other', 'root' => null, 'domain' => null, 'runSetup' => false], ...$change];
    expect(fn () => app(RegisterInstanceAction::class)->execute($this->node, new RegisterInstanceData(...$values)))->toThrow(ResourceOperationException::class, 'Native registration currently');
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
})->with([
    'worktrees' => [['includeWorktrees' => true]], 'setup' => [['runSetup' => true]],
    'project inference' => [['projectId' => null]], 'domain' => [['domain' => 'proof.test']],
    'relocation' => [['sourcePath' => '/work/proof']],
]);

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRegisterResult::class)->input($this->operation))->toBe($this->operation->input);
});
