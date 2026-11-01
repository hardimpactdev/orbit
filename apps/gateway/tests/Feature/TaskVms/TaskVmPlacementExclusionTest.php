<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskWait;
use App\Domain\TaskVms\TaskVmPlacementPolicy;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://github.com/acme/proof.git', 'default_branch' => 'main']);
    $this->group = Task::topLevel()->create(['project_id' => $this->project->id, 'title' => 'Proof', 'brief' => 'Work', 'status' => TaskGroupStatus::Reserved, 'orchestration' => TaskOrchestration::External]);
    $this->beast = Node::query()->create(['name' => 'beast', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '192.0.2.7', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $this->shark = Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.11', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64']);
    TaskVmSettingsBuilder::bind(['devClusterId' => null, 'hosts' => [
        TaskVmSettingsBuilder::host($this->beast->id, ['maxVms' => 6]),
        TaskVmSettingsBuilder::host($this->shark->id, ['maxVms' => 2, 'cidr' => '10.251.78.0/24']),
    ]]);
    TaskVmRecipeImages::ready($this->beast->id);
    TaskVmRecipeImages::ready($this->shark->id);
});

it('allocates on shark when the Project excludes beast despite its six free slots', function (): void {
    ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->beast->id]);

    $vm = app(AllocateTaskVmAction::class)->capture($this->group);

    expect($vm->host_node_id)->toBe($this->shark->id)
        ->and($vm->provider)->toBe(TaskVmProviderKind::Incus)
        ->and(Operation::query()->count())->toBe(0);
});

it('waits for capacity when every Incus host is excluded without waiting for their images', function (bool $imagesReady): void {
    foreach ([$this->beast, $this->shark] as $host) {
        ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $host->id]);
    }
    if (! $imagesReady) {
        TaskVmImageBuild::query()->delete();
    }

    expect(fn () => app(AllocateTaskVmAction::class)->capture($this->group))
        ->toThrow(fn (TaskCapacityException $exception) => expect($exception->wait)->toBe(TaskWait::Capacity)
            ->and($exception->getMessage())->toBe(TaskVmPlacementPolicy::NoCapacity));
    expect(TaskVm::query()->count())->toBe(0)->and(Operation::query()->count())->toBe(0);
})->with([true, false]);

it('only applies exclusions for the group Project', function (): void {
    $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git', 'default_branch' => 'main']);
    ProjectNodeExclusion::query()->create(['project_id' => $other->id, 'node_id' => $this->beast->id]);

    expect(app(AllocateTaskVmAction::class)->capture($this->group)->host_node_id)->toBe($this->beast->id);
});

it('retains eligible UpCloud overflow when the Project excludes every Incus host', function (): void {
    foreach ([$this->beast, $this->shark] as $host) {
        ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $host->id]);
    }
    $settings = TaskVmSettingsBuilder::make(['upcloud' => new TaskVmUpCloud(true, [$this->project->id], '/fixture/token', 2, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001')]);

    expect((new TaskVmPlacementPolicy($settings))->choose(TaskVmSize::Normal, $this->group, [$this->beast->id => 6, $this->shark->id => 2])->provider)
        ->toBe(TaskVmProviderKind::UpCloud);
});
