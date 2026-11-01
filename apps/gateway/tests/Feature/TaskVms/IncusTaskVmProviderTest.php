<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\TaskVms\GuestBootstrap;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);
    $host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
    ]);
    $this->vm = TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'name' => 'tvm-1',
        'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'pi-secret',
    ]);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id, ['maxVms' => 4, 'pool' => 'tank'])]]);

    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $incus = new IncusHost($this->agents);
    $this->guestAgents = new FakeGuestAgentCommandRunner;
    $this->provider = new IncusTaskVmProvider($incus, $settings, new GuestBootstrap($this->guestAgents));
    $this->host = $host;
    $this->hostSettings = $settings->host($host->id);
});

it('returns the typed native observation', function (?array $state, ?VmObservation $expected): void {
    $this->agents->answer('incus.vm-state', ['observation' => $state]);

    expect($this->provider->observe($this->vm))->toEqual($expected);
    expect($this->agents->calls)->toBe([['node' => 'beast', 'command' => 'incus.vm-state', 'input' => ['project' => 'orbit-tasks', 'name' => 'tvm-1', 'cidr' => '10.251.77.0/24']]]);
})->with([
    [null, null],
    [['running' => true, 'address' => '10.251.77.20', 'image_fingerprint' => str_repeat('c', 64), 'settled' => true], new VmObservation(true, '10.251.77.20', str_repeat('c', 64))],
    [['running' => false, 'address' => null, 'image_fingerprint' => null, 'settled' => true], new VmObservation(false, null)],
]);

it('rejects incomplete native observations', function (array $output): void {
    $this->agents->answerMalformed('incus.vm-state', $output);

    expect(fn () => $this->provider->observe($this->vm))->toThrow(TaskVmException::class);
})->with([[[]], [['observation' => []]], [['observation' => ['running' => true, 'address' => null, 'image_fingerprint' => 42]]]]);

it('reads typed readiness and fingerprint through the trusted guest target', function (bool $ready): void {
    $pin = 'SHA256:'.str_repeat('a', 43);
    $this->guestAgents->answer = static fn ($guest, string $command): array => $command === 'guest.cloud-init' ? ['ready' => $ready] : ['fingerprint' => $pin];

    expect($this->provider->bootstrapReady($this->vm))->toBe($ready)
        ->and($this->provider->sshHostFingerprint($this->vm))->toBe($pin);
    expect(array_column($this->guestAgents->calls, 'command'))->toBe(['guest.cloud-init', 'guest.fingerprint']);
    expect($this->guestAgents->calls[0]['guest']->name)->toBe('tvm-1')
        ->and($this->guestAgents->calls[0]['guest']->host->is($this->host))->toBeTrue();
})->with([true, false]);

it('owns the clones of VMs and only the topology seeds of images the host keeps', function (): void {
    $kept = [str_repeat('c', 64)];

    expect(TaskVmVolumes::owns('tvm-7-topology-seed', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('tvm-7-workspace', $kept))->toBeFalse()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-cccccccccccc', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-cccccccccccc-new', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-dddddddddddd', $kept))->toBeFalse()
        ->and(TaskVmVolumes::owns('tvm-template-1-20261011040000', $kept))->toBeFalse()
        ->and(TaskVmVolumes::seed(str_repeat('c', 64)))->toBe('orbit-topology-seed-cccccccccccc')
        ->and(TaskVmVolumes::newSeed(str_repeat('c', 64)))->toBe('orbit-topology-seed-cccccccccccc-new');
});
