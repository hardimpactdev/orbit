<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorCollectionReads;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Doctor\ProcessInspectionData;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Doctor\ProcessStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\ProblemFingerprint;
use App\Models\Process;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

const OBSERVATION_COLLECTION = '123e4567-e89b-42d3-a456-426614174099';

function observationPeer(string $name, string $ip): Node
{
    return Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'architecture' => 'amd64', 'user' => 'orbit',
        'public_ssh_host' => '192.0.2.28', 'wireguard_ip' => $ip, 'ssh_host_fingerprint' => 'SHA256:managed']);
}

function observationServices($test): Node
{
    $gateway = observationPeer('gateway', '10.44.0.1');
    NodeRole::query()->create(['node_id' => $gateway->id, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $services = observationPeer('services', '10.44.0.2');
    $services->accessibleNodes()->attach($gateway);
    config(['dependency_detection.services_node_id' => $services->id]);
    $test->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
    app(TaskExtensionState::class)->enable();

    return $services;
}

it('requires an active Services identity with Gateway access', function (): void {
    $this->postJson('/api/v1/observation/collections/'.OBSERVATION_COLLECTION.'/plan')->assertForbidden();
    $services = observationServices($this);
    config(['dependency_detection.services_node_id' => null]);
    $this->postJson('/api/v1/observation/collections/'.OBSERVATION_COLLECTION.'/plan')
        ->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    config(['dependency_detection.services_node_id' => $services->id]);
    $services->accessibleNodes()->detach();
    $this->postJson('/api/v1/observation/collections/'.OBSERVATION_COLLECTION.'/plan')->assertForbidden();
});

it('returns an immutable resource plan and refuses supplied execution input', function (): void {
    observationServices($this);
    Queue::fake();
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION.'/plan';
    $first = $this->postJson($path)->assertOk()->json('data');
    observationPeer('later', '10.44.0.3');

    $this->postJson($path)->assertOk()->assertJsonPath('data', $first);
    $this->postJson($path, ['node_id' => 999, 'command' => 'unlisted', 'password' => 'fixture-secret'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'observation.input_invalid');
    Queue::assertNothingPushed();
});

it('binds Node and group reads to the stored plan', function (): void {
    observationServices($this);
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $this->postJson($path.'/plan')->assertOk();
    $other = observationPeer('outside', '10.44.0.3');

    $this->postJson($path.'/nodes/'.$other->id)->assertUnprocessable()->assertJsonPath('error.code', 'observation.target_invalid');
    $this->postJson($path.'/probes/999999')->assertUnprocessable()->assertJsonPath('error.code', 'observation.probe_invalid');
    $this->postJson('/api/v1/observation/collections/123e4567-e89b-42d3-a456-426614174098/nodes/'.$other->id)
        ->assertConflict()->assertJsonPath('error.code', 'observation.collection_expired');
});

it('keeps Node snapshots private and reuses them after a lost reply', function (): void {
    $node = observationServices($this);
    $clock = (object) ['now' => 0.0];
    $deadline = new CommandDeadline(static fn (): float => $clock->now);
    app()->instance(CommandDeadline::class, $deadline);
    $inspector = new class($clock, $deadline) implements NodeStateInspector
    {
        public int $calls = 0;

        public function __construct(private stdClass $clock, private CommandDeadline $deadline) {}

        public function inspect(Node $node): NodeInspectionData
        {
            $this->calls++;
            expect($this->deadline->cap(30))->toBe(12.0);
            $this->clock->now += 10.2;

            return new NodeInspectionData(true, 'linux', 'amd64', true, agentSecretChecksum: 'fixture-secret');
        }
    };
    app()->instance(NodeStateInspector::class, $inspector);
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $this->postJson($path.'/plan')->assertOk();

    $this->postJson($path.'/nodes/'.$node->id)->assertOk()->assertJsonPath('data', ['status' => 'observed'])->assertDontSee('fixture-secret');
    $this->postJson($path.'/nodes/'.$node->id)->assertOk()->assertJsonPath('data', ['status' => 'observed']);
    expect($inspector->calls)->toBe(1);
    expect(Cache::get(DoctorCollectionReads::contextKey(OBSERVATION_COLLECTION, $node->id))['inspection']['reachable'])->toBeTrue();
});

it('records grouped Process drift once using the shared twelve second deadline', function (): void {
    $node = observationServices($this);
    $processes = [];
    foreach (['drift', 'healthy', 'other'] as $name) {
        $processes[] = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name,
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    }
    Cache::put(DoctorCollectionReads::contextKey(OBSERVATION_COLLECTION, $node->id), ['inspection' => (new NodeInspectionData(true, 'linux', 'amd64', true))->toArray(), 'failed' => false, 'observed_at' => now()->toISOString()]);
    $clock = (object) ['now' => 0.0];
    $deadline = new CommandDeadline(static fn (): float => $clock->now);
    app()->instance(CommandDeadline::class, $deadline);
    $inspector = new class($clock, $deadline) implements ProcessStateInspector
    {
        public array $budgets = [];

        public function __construct(private stdClass $clock, private CommandDeadline $deadline) {}

        public function inspect(Process $process): ProcessInspectionData
        {
            $this->budgets[] = $this->deadline->cap(30);
            $this->clock->now += 2.5;

            return new ProcessInspectionData(true, $process->name === 'drift' ? ProcessInspectionStatus::Inactive : ProcessInspectionStatus::Active);
        }
    };
    app()->instance(ProcessStateInspector::class, $inspector);
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $plan = $this->postJson($path.'/plan')->assertOk()->json('data');
    $index = array_find_key($plan['probes'], static fn (array $probe): bool => $probe['node_id'] === $node->id && $probe['family'] === 'process');

    $this->postJson($path.'/probes/'.$index)->assertOk()->assertJsonPath('data.status', 'recorded');
    $this->postJson($path.'/probes/'.$index)->assertOk();

    expect($inspector->budgets)->toBe([12.0, 9.5, 7.0]);
    expect(ProblemFingerprint::query()->sole()->fingerprint)->toBe('doctor|process.state_mismatch|process|'.$processes[0]->id);
    expect(ProblemFingerprint::query()->sole()->task_group_id)->toBeNull();
});

it('records missing Node observations as unverifiable and skips removed Nodes', function (): void {
    $node = observationServices($this);
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $plan = $this->postJson($path.'/plan')->assertOk()->json('data');
    $index = array_find_key($plan['probes'], static fn (array $probe): bool => $probe['node_id'] === $node->id && $probe['family'] === 'node');

    $this->postJson($path.'/probes/'.$index)->assertOk();
    expect(ProblemFingerprint::query()->where('fingerprint', 'doctor|node.inspection_failed|node|'.$node->id)->exists())->toBeTrue();
    $extra = observationPeer('deleted', '10.44.0.3');
    $second = '/api/v1/observation/collections/123e4567-e89b-42d3-a456-426614174098';
    $this->postJson($second.'/plan')->assertOk();
    $extra->delete();
    $this->postJson($second.'/nodes/'.$extra->id)->assertOk()->assertJsonPath('data.status', 'removed');
});

it('stops an admitted collection when Tasks is disabled', function (): void {
    $node = observationServices($this);
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $this->postJson($path.'/plan')->assertOk();
    app(TaskExtensionState::class)->disable();

    $this->postJson($path.'/nodes/'.$node->id)->assertOk()->assertJsonPath('data.status', 'disabled');
    expect(Cache::has(DoctorCollectionReads::contextKey(OBSERVATION_COLLECTION, $node->id)))->toBeFalse();
});

it('finalizes stale Activities once per maintenance receipt and leaves live requests alone', function (): void {
    observationServices($this);
    $this->freezeTime();
    foreach ([901, 899] as $age) {
        DB::table('activity_log')->insert(['log_name' => 'commands', 'description' => 'probe', 'command' => 'probe',
            'request_id' => 'probe-'.$age, 'status' => 'running', 'properties' => '{}', 'created_at' => now()->subSeconds($age), 'updated_at' => now()->subSeconds($age)]);
    }
    $path = '/api/v1/observation/maintenance/activities';

    $this->postJson($path, ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.finalized', 1);
    $this->postJson($path, ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.finalized', 1);
    expect(DB::table('activity_log')->where('request_id', 'probe-901')->value('error_code'))->toBe('activity.interrupted');
    expect(DB::table('activity_log')->where('request_id', 'probe-899')->value('status'))->toBe('running');
});

it('skips queue housekeeping without a queue and validates maintenance input', function (): void {
    observationServices($this);
    config(['orbit.gateway_queue.connection' => '']);
    foreach (['operations', 'metrics'] as $routine) {
        $this->postJson('/api/v1/observation/maintenance/'.$routine, ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.status', 'skipped');
    }
    $this->postJson('/api/v1/observation/maintenance/activities')->assertUnprocessable();
    $this->postJson('/api/v1/observation/maintenance/unknown', ['run_id' => OBSERVATION_COLLECTION])->assertNotFound();
});

it('prunes failed jobs by configured retention while Tasks is disabled and replays its counts', function (): void {
    observationServices($this);
    app(TaskExtensionState::class)->disable();
    $this->freezeTime();
    config(['retention.failed_jobs_hours' => 48]);
    foreach ([49, 47] as $hours) {
        DB::table('failed_jobs')->insert(['uuid' => 'failed-'.$hours, 'connection' => 'gateway', 'queue' => 'problems', 'payload' => '{}', 'exception' => 'fixture', 'failed_at' => now()->subHours($hours)]);
    }

    $this->postJson('/api/v1/observation/maintenance/failed', ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.pruned', 1);
    $this->postJson('/api/v1/observation/maintenance/failed', ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.pruned', 1);
    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe(['failed-47']);
});

it('prunes finished unfinished and cancelled batch metadata with one configured cutoff', function (): void {
    observationServices($this);
    $this->freezeTime();
    config(['retention.job_batch_hours' => 48]);
    foreach (['finished', 'unfinished', 'cancelled', 'recent'] as $name) {
        $stamp = now()->subHours($name === 'recent' ? 47 : 49)->timestamp;
        DB::table('job_batches')->insert(['id' => $name, 'name' => $name, 'total_jobs' => 1, 'pending_jobs' => $name === 'finished' ? 0 : 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => '', 'created_at' => $stamp, 'finished_at' => $name === 'finished' ? $stamp : null, 'cancelled_at' => $name === 'cancelled' ? $stamp : null]);
    }

    $this->postJson('/api/v1/observation/maintenance/batches', ['run_id' => OBSERVATION_COLLECTION])->assertOk()->assertJsonPath('data.pruned', 3);
    expect(DB::table('job_batches')->pluck('id')->all())->toBe(['recent']);
});

it('records every selected Process as unverifiable when its grouped read throws', function (): void {
    $node = observationServices($this);
    $ids = [];
    foreach (['first', 'second', 'third'] as $name) {
        $ids[] = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name,
            'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active])->id;
    }
    Cache::put(DoctorCollectionReads::contextKey(OBSERVATION_COLLECTION, $node->id), ['inspection' => (new NodeInspectionData(true, 'linux', 'amd64', true))->toArray(), 'failed' => false, 'observed_at' => now()->toISOString()]);
    app()->instance(ProcessStateInspector::class, new class implements ProcessStateInspector
    {
        public function inspect(Process $process): ProcessInspectionData
        {
            throw new RuntimeException('fixture-token=never-return-this');
        }
    });
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $plan = $this->postJson($path.'/plan')->assertOk()->json('data');
    $index = array_find_key($plan['probes'], static fn (array $probe): bool => $probe['node_id'] === $node->id && $probe['family'] === 'process');

    $this->postJson($path.'/probes/'.$index)->assertOk()->assertJsonPath('data', ['status' => 'recorded'])->assertDontSee('never-return-this');
    expect(ProblemFingerprint::query()->orderBy('fingerprint')->pluck('fingerprint')->all())->toBe(array_map(static fn (int $id): string => 'doctor|process.inspection_failed|process|'.$id, $ids));
});

it('collects signals after reads without filing a problem or dispatching jobs', function (): void {
    observationServices($this);
    Queue::fake();
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $this->postJson($path.'/plan')->assertOk();

    $this->postJson($path.'/signals')->assertOk()->assertJsonPath('data', ['status' => 'recorded']);
    $this->postJson($path.'/signals')->assertOk();
    expect(ProblemFingerprint::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('does not let an old cached healthy observation clear a newer failure', function (): void {
    $node = observationServices($this);
    $this->freezeTime();
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['php']], 'desired_state' => 'running', 'status' => LifecycleStatus::Active]);
    Cache::put(DoctorCollectionReads::contextKey(OBSERVATION_COLLECTION, $node->id), ['inspection' => (new NodeInspectionData(true, 'linux', 'amd64', true))->toArray(), 'failed' => false, 'observed_at' => now()->toISOString()]);
    $this->travel(1)->minutes();
    $resources = new DoctorResources(DoctorResourceKind::Rows, [$process->id]);
    app(ProblemCollector::class)->recordDoctor(DoctorReportData::fromNodes([
        DoctorNodeReportData::fromFamilies($node->id, $node->name, [
            DoctorFamilyReportData::fromIssues(DoctorFamily::Process, 0, $resources->failures(DoctorFamily::Process, $node->id)),
        ]),
    ]), now());
    $row = ProblemFingerprint::query()->sole();
    $this->travel(1)->minutes();
    app()->instance(ProcessStateInspector::class, new class implements ProcessStateInspector
    {
        public function inspect(Process $process): ProcessInspectionData
        {
            return new ProcessInspectionData(true, ProcessInspectionStatus::Active);
        }
    });
    $path = '/api/v1/observation/collections/'.OBSERVATION_COLLECTION;
    $plan = $this->postJson($path.'/plan')->assertOk()->json('data');
    $index = array_find_key($plan['probes'], static fn (array $probe): bool => $probe['node_id'] === $node->id && $probe['family'] === 'process');

    $this->postJson($path.'/probes/'.$index)->assertOk();
    expect($row->refresh()->evidence)->not->toHaveKey('resolved_at');
});
