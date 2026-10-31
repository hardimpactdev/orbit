<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\Instances\InstanceRenameResult;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Rename proof', 'slug' => 'rename-proof', 'repository_url' => 'https://github.com/example/proof.git', 'type' => 'node-package', 'root' => '.']);
    $this->node = Node::query()->create(['name' => 'rename-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9']);
    orbit_test_set_app_placement_role($this->node, false);
    $this->instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->node->id, 'name' => 'proof', 'checkout_path' => '/srv/apps/rename-proof/proof', 'source_layout' => 'checkout', 'status' => 'active', 'branch' => 'old']);
    $this->operation = Operation::query()->create(['status' => 'queued', 'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'], 'kind' => 'instance.rename', 'target_type' => 'instance', 'target_id' => $this->instance->id, 'node_id' => $this->node->id, 'input' => ['branch' => 'feature']]);
});

it('projects a successful native branch result without running a Node command', function (): void {
    app(InstanceRenameResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => ['branch' => 'feature']]);
    expect($this->instance->refresh()->branch)->toBe('feature')->and($this->instance->branch_override)->toBe('feature');
});

it('keeps the recorded branch when the native action fails', function (): void {
    app(InstanceRenameResult::class)->apply($this->operation, ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676', 'status' => 'failed', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => 'instance.branch_not_checked_out', 'result' => ['error_code' => 'instance.branch_not_checked_out']]);
    expect($this->instance->refresh()->branch)->toBe('old');
});

it('rejects domain replacement before admission while its native Route parent is unavailable', function (): void {
    expect(fn () => app(RenameInstanceAction::class)->execute($this->instance, new RenameInstanceData('feature', 'new.example.test')))
        ->toThrow(ResourceOperationException::class, 'Domain replacement requires');
    expect(Operation::query()->count())->toBe(1)->and($this->instance->refresh()->branch)->toBe('old');
});

it('returns only recorded public intent to the native reader', function (): void {
    expect(app(InstanceRenameResult::class)->input($this->operation))->toBe($this->operation->input);
});
