<?php

declare(strict_types=1);

use App\Actions\Observation\StartObservationMaintenance;
use App\Domain\Observation\ObservationMaintenanceProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'maintenance-gateway', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture.run', 'swamp.read_token' => 'fixture.read', 'queue.failed.driver' => 'database-uuids']);
    $this->projection = app(ObservationMaintenanceProjection::class);
    request()->setUserResolver(fn () => $this->gateway);
});
function observation_running(string $status = 'running'): int
{
    return DB::table('activity_log')->insertGetId(['log_name' => 'commands', 'description' => 'fixture', 'event' => 'command', 'request_id' => (string) Str::uuid(), 'command' => 'process:restart', 'status' => $status, 'duration_ms' => 50, 'exit_code' => 7, 'properties' => json_encode(['value' => 'private.activity.payload']), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
}
function observation_result($operation, array $result, string $status = 'succeeded'): array
{
    return ['run_id' => $operation->state['swamp_run_id'], 'status' => $status, 'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => $result, 'error_code' => $status === 'failed' ? 'operation.native_failed' : null];
}
it('submits immutable safe metadata and stored SSH pins as full native inputs without private reads', function (): void {
    $id = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $admitted = $operation->input;
    Activity::query()->findOrFail($id)->update(['status' => 'succeeded']);
    observation_running();
    $input = $this->projection->input($operation);

    expect($input)->toBe(['intent' => $admitted]);
    expect($input['intent']['activities'])->toHaveCount(1)->and($input['intent']['activities'][0]['id'])->toBe($id)
        ->and($input['intent']['ssh']['host_keys'])->toBe([$this->gateway->ssh_host_key_type.' '.$this->gateway->ssh_host_key]);
    expect(json_encode($input))->not->toContain('private.activity.payload', 'queue_redis', '$secret');
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run']);
    expect($this->socket->sent[0]['payload']['inputs']['intent'])->toBe($admitted);
});
it('projects observations without finalizing Activities or applying legacy selected IDs', function (): void {
    $selected = observation_running();
    $settled = observation_running('succeeded');
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $result = $this->projection->apply($operation, observation_result($operation, ['activity_ids' => [$selected, $settled], 'eligible' => ['activities' => 1, 'batches' => 0, 'failed' => 0], 'uptime_seconds' => 100]));

    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->result)->toBe(['eligible' => ['activities' => 1, 'batches' => 0, 'failed' => 0], 'uptime_seconds' => 100]);
    expect(Activity::query()->findOrFail($selected))->status->toBe('running')->duration_ms->toBe(50);
    expect(Activity::query()->findOrFail($settled)->status)->toBe('succeeded');
    expect(DB::table('activity_log')->where('id', $selected)->value('exit_code'))->toBe(7);
    expect((string) DB::table('activity_log')->where('id', $selected)->value('properties'))->toContain('private.activity.payload');
    expect($this->projection->apply($result, observation_result($result, $result->result))->result)->toBe($result->result);
    expect(fn () => $this->projection->apply($result, observation_result($result, ['eligible' => ['activities' => 99]])))->toThrow(ResourceOperationException::class);
});
it('does not change Activity state on terminal native failure', function (): void {
    $id = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $result = $this->projection->apply($operation, observation_result($operation, ['activity_ids' => [$id]], 'failed'));

    expect($result->status)->toBe(OperationStatus::Failed)->and(Activity::query()->findOrFail($id)->status)->toBe('running');
});
it('observes retention metadata with Tasks disabled and never deletes batching or failed-job rows', function (): void {
    app(TaskExtensionState::class)->disable();
    DB::table('job_batches')->insert(['id' => 'batch-private', 'name' => 'fixture', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => 'private.batch.options', 'created_at' => 1]);
    $failed = DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'fixture', 'queue' => 'fixture', 'payload' => 'private.job.payload', 'exception' => 'private.job.exception', 'failed_at' => now()->subYear()]);
    $this->gateway->update(['ssh_host_key' => null]);
    $operation = app(StartObservationMaintenance::class)->execute('retention');
    $input = $this->projection->input($operation)['intent'];

    expect($input['batches'])->toBe([])->and($input['failed'][0]['id'])->toBe($failed)->and($input['ssh'])->toBeNull();
    expect(json_encode($input))->not->toContain('private.job', 'private.batch');
    $result = $this->projection->apply($operation, observation_result($operation, ['failed_ids' => [$failed], 'batch_ids' => ['batch-private'], 'eligible' => ['activities' => 0, 'batches' => 0, 'failed' => 1]]));
    expect($result->result['eligible']['failed'])->toBe(1);
    $this->assertDatabaseHas('failed_jobs', ['id' => $failed]);
    $this->assertDatabaseHas('job_batches', ['id' => 'batch-private']);
});
it('refuses unavailable SSH pins before admitting host observations', function (): void {
    $this->gateway->update(['ssh_host_key' => null]);

    expect(fn () => app(StartObservationMaintenance::class)->execute('maintenance'))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
});
it('refuses unsupported failed-job stores before retention admission', function (): void {
    config(['queue.failed.driver' => 'file']);

    expect(fn () => app(StartObservationMaintenance::class)->execute('retention'))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBe([]);
});
it('admits only configured control-plane requests and rejects caller state selections', function (): void {
    $caller = Node::query()->create(['name' => 'maintenance-control', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.209']);
    $caller->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $caller->id]);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => $caller->wireguard_ip];

    $this->call('POST', '/api/v1/observation/maintenance/maintenance', [], [], [], $server, '{}')->assertAccepted()->assertJsonPath('data.kind', 'observation.maintain');
    $this->call('POST', '/api/v1/observation/maintenance/maintenance', [], [], [], $server, '{"activity_ids":[1]}')->assertUnprocessable()->assertJsonPath('error.code', 'observation.input_invalid');
    config(['dependency_detection.services_node_id' => $this->gateway->id]);
    $this->call('POST', '/api/v1/observation/maintenance/maintenance', [], [], [], $server, '{}')->assertForbidden();
});
it('projects one fixed native result through the shared history reader', function (): void {
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $runId = $operation->state['swamp_run_id'];
    $public = ['eligible' => ['activities' => 0, 'batches' => 0, 'failed' => 0], 'uptime_seconds' => 12.5, 'load' => [0.2, 0.3, 0.4], 'horizon_metrics' => null, 'unavailable' => []];
    $history = ['id' => $runId, 'workflowName' => 'operation-observation-maintain', 'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind], 'status' => 'succeeded', 'startedAt' => now()->subSecond()->toISOString(), 'completedAt' => now()->toISOString(), 'jobs' => [['name' => 'report', 'status' => 'succeeded', 'steps' => [['name' => 'result', 'status' => 'succeeded', 'outputs' => ['stdout' => json_encode($public), 'exitCode' => 0]]]]]];
    $this->socket->reply = static fn (array $request): array => [['id' => $request['id'], 'type' => 'workflow.history.get', 'payload' => ['data' => $history]]];

    $result = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId, 'status' => 'succeeded'], ['job' => 'report', 'step' => 'result']);

    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->result)->toBe($public);
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.run', 'workflow.history.get']);
});
