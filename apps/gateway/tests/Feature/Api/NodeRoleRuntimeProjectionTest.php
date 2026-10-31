<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRuntimeProjection;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    $this->node = FleetFixtures::node('runtime-projection-analytics');
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id,
        'name' => 'plausible', 'runtime' => 'docker', 'runtime_config' => ['image' => 'ghcr.io/plausible/community-edition:v3.2.1',
            'command' => ['/entrypoint.sh', 'run'], 'environment' => ['SECRET_KEY_BASE' => 'existing-private-fixture']],
        'restart_policy' => 'unless-stopped', 'desired_state' => 'stopped', 'status' => 'provisioning',
        'failed_step' => 'old-installation', 'error_code' => 'process.old_error']);
    $this->snapshot = ['node_id' => $this->node->id, 'role' => 'analytics', 'runtime' => ['role' => 'analytics',
        'node_id' => $this->node->id, 'installation' => ['process_id' => $this->process->id, 'runtime' => 'docker',
            'name' => 'orbit-process-'.$this->process->id.'-plausible', 'spec' => str_repeat('a', 64), 'desired' => 'running']]];
    $this->statuses = new class implements ProcessRuntimeStatusIndex
    {
        public array $remembered = [];

        public function statuses(Collection $processes): array
        {
            throw new RuntimeException('Projection must never inspect runtime state.');
        }

        public function remember(Process $process, string $status): void
        {
            $this->remembered[] = [$process->id, $status];
        }
    };
    app()->instance(ProcessRuntimeStatusIndex::class, $this->statuses);
    Event::fake([RecordBroadcast::class]);
});

it('records confirmed Plausible runtime completion without replacing its admitted Docker definition', function (): void {
    $definition = $this->process->runtime_config;
    app(NodeRoleRuntimeProjection::class)->record($this->snapshot);

    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->process->error_code)->toBeNull()->and($this->process->failed_step)->toBeNull()
        ->and($this->process->retired_runtime)->toBeNull()->and($this->process->runtime_config)->toBe($definition)
        ->and($this->statuses->remembered)->toBe([[$this->process->id, 'running']]);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessStatus
        && $event->id === $this->process->id && ! str_contains(json_encode($event->data), 'existing-private-fixture'));
});

it('does not repeat database cache or broadcast writes on completion replay', function (): void {
    $projection = app(NodeRoleRuntimeProjection::class);
    $projection->record($this->snapshot);
    $this->process->refresh()->forceFill(['updated_at' => '2020-01-01 00:00:00'])->save();
    DB::enableQueryLog();
    $projection->record($this->snapshot);
    $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update'));
    DB::disableQueryLog();

    expect($writes)->toBe([])->and($this->process->refresh()->updated_at->toDateString())->toBe('2020-01-01')
        ->and($this->statuses->remembered)->toBe([[$this->process->id, 'running']]);
    Event::assertDispatchedTimes(RecordBroadcast::class, 1);
});

it('does not project a Process for another role', function (): void {
    app(NodeRoleRuntimeProjection::class)->record(['role' => 'metrics']);
    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($this->process->error_code)->toBe('process.old_error')->and($this->statuses->remembered)->toBe([]);
    Event::assertNotDispatched(RecordBroadcast::class);
});

it('refuses a foreign Node Process or different runtime before recording completion', function (string $change): void {
    if ($change === 'owner') {
        $foreign = FleetFixtures::node('runtime-projection-foreign');
        $this->process->update(['owner_id' => $foreign->id]);
    } elseif ($change === 'name') {
        $this->process->update(['name' => 'another-process']);
    } else {
        $this->process->update(['runtime' => 'systemd']);
    }
    expect(fn () => app(NodeRoleRuntimeProjection::class)->record($this->snapshot))->toThrow(ModelNotFoundException::class);
    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($this->statuses->remembered)->toBe([]);
})->with(['owner', 'name', 'runtime']);

it('refuses an admitted runtime bound to a different Node before touching a Process', function (): void {
    $this->snapshot['runtime']['node_id']++;
    expect(fn () => app(NodeRoleRuntimeProjection::class)->record($this->snapshot))->toThrow(ResourceOperationException::class);
    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Provisioning);
});

it('preserves confirmed Analytics runtime through later role publication failure and skips unconfirmed callbacks', function (): void {
    $assignment = $this->node->roles()->create(['role' => 'analytics', 'status' => 'provisioning']);
    $snapshot = [...$this->snapshot, 'assignment_id' => $assignment->id, 'created' => true,
        'footprint' => ['node_id' => $this->node->id, 'artifacts' => []],
        'certificates' => ['leaves' => [], 'withdrawals' => []]];
    $operation = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => 'running', 'sequence' => 1,
        'input' => ['role' => 'analytics', '_role' => $snapshot],
        'state' => ['swamp_run_id' => '485f1681-e39f-459f-ab5d-89a6f43f9227']]);
    $running = ['run_id' => '485f1681-e39f-459f-ab5d-89a6f43f9227', 'status' => 'running',
        'error_code' => null, 'started_at' => '2026-10-31T09:00:00Z', 'result' => ['runtime_completed' => false]];
    $projection = app(NodeRoleDirectProjection::class);
    $projection->apply($operation, $running);
    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($this->statuses->remembered)->toBe([]);

    $running['result']['runtime_completed'] = true;
    $projection->apply($operation, $running);
    expect($this->process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->process->error_code)->toBeNull();

    $projection->apply($operation, [...$running, 'status' => 'failed', 'error_code' => 'certificate.publish_failed',
        'finished_at' => '2026-10-31T09:00:01Z', 'result' => ['failed_step' => 'certificate-publication',
            'error_code' => 'certificate.publish_failed']]);
    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($this->process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($this->statuses->remembered)->toBe([[$this->process->id, 'running']]);
});
