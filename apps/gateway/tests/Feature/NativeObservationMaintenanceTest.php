<?php

declare(strict_types=1);

use App\Actions\Observation\StartObservationMaintenance;
use App\Domain\Observation\ObservationMaintenanceProjection;
use App\Domain\Observation\ObservationQueueRedisSource;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Activity;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->gateway = Node::query()->create(['name' => 'maintenance-gateway', 'platform' => 'linux', 'user' => 'orbit', 'status' => 'active', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']);
    $this->gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'fixture.run', 'orbit.gateway_queue.connection' => '', 'queue.failed.driver' => 'database-uuids']);
    $this->projection = app(ObservationMaintenanceProjection::class);
    request()->setUserResolver(fn () => $this->gateway);
});
function observation_running(string $status = 'running'): int
{
    return DB::table('activity_log')->insertGetId(['log_name' => 'commands', 'description' => 'fixture', 'event' => 'command', 'request_id' => (string) Str::uuid(), 'command' => 'process:restart', 'status' => $status, 'duration_ms' => 50, 'exit_code' => 7, 'properties' => json_encode(['value' => 'private.activity.payload']), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()]);
}
function observation_result($operation, array $result, string $status = 'succeeded'): array
{
    return ['run_id' => $operation->state['swamp_run_id'], 'status' => $status, 'started_at' => now()->subSecond()->toISOString(), 'finished_at' => now()->toISOString(), 'result' => $result, 'error_code' => $status === 'failed' ? 'observation.metrics_unavailable' : null];
}
it('admits one native parent and reads only safe state metadata without performing maintenance', function (): void {
    $id = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $input = $this->projection->input($operation)['intent'];
    expect($operation->kind)->toBe('observation.maintain')->and($operation->node_id)->toBe($this->gateway->id)
        ->and($input['activities'])->toHaveCount(1)->and($input['activities'][0]['id'])->toBe($id)
        ->and(json_encode($input))->not->toContain('private.activity.payload')
        ->and(Activity::query()->findOrFail($id)->status)->toBe('running');
});
it('keeps admitted record metadata immutable when the Gateway state changes before native execution', function (): void {
    $admitted = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    observation_running();

    $input = $this->projection->input($operation);

    expect(array_column($input['intent']['activities'], 'id'))->toBe([$admitted]);
    expect($input['execution_secrets'])->toBe([]);
    expect($input['intent'])->toBe($operation->input);
});
it('applies selected Activity outcomes conditionally without changing saved properties duration or exit code', function (): void {
    $selected = observation_running();
    $settled = observation_running('succeeded');
    $untouched = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $result = $this->projection->apply($operation, observation_result($operation, ['activity_ids' => [$selected, $settled], 'snapshot_jobs' => 3, 'snapshot_queues' => 1]));
    expect($result->status)->toBe(OperationStatus::Succeeded)->and($result->result['finalized'])->toBe(1)
        ->and(Activity::query()->findOrFail($selected)->error_code)->toBe('activity.interrupted')
        ->and(Activity::query()->findOrFail($selected)->duration_ms)->toBe(50)
        ->and(Activity::query()->findOrFail($settled)->status)->toBe('succeeded')
        ->and(Activity::query()->findOrFail($untouched)->status)->toBe('running')
        ->and(DB::table('activity_log')->where('id', $selected)->value('exit_code'))->toBe(7)
        ->and((string) DB::table('activity_log')->where('id', $selected)->value('properties'))->toContain('private.activity.payload')
        ->and($result->result)->not->toHaveKeys(['activity_ids', 'batch_ids', 'failed_ids']);
    expect($this->projection->apply($result, observation_result($result, ['activity_ids' => [$selected]]))->result)->toBe($result->result);
});
it('records independent Activity work when the native metrics step reports failure', function (): void {
    $id = observation_running();
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    $result = $this->projection->apply($operation, observation_result($operation, ['activity_ids' => [$id]], 'failed'));
    expect($result->status)->toBe(OperationStatus::Failed)->and($result->result['finalized'])->toBe(1)
        ->and(Activity::query()->findOrFail($id)->status)->toBe('failed');
});
it('records only Swamp-selected batch and failed-job deletions while Tasks is disabled', function (): void {
    app(TaskExtensionState::class)->disable();
    DB::table('job_batches')->insert(['id' => 'batch-private', 'name' => 'fixture', 'total_jobs' => 1, 'pending_jobs' => 1, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => 'private.batch.options', 'created_at' => 1]);
    $failed = DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'fixture', 'queue' => 'fixture', 'payload' => 'private.job.payload', 'exception' => 'private.job.exception', 'failed_at' => now()->subYear()]);
    $operation = app(StartObservationMaintenance::class)->execute('retention');
    $input = $this->projection->input($operation)['intent'];
    expect($input['batches'])->toBe([])->and($input['failed'][0]['id'])->toBe($failed)
        ->and(json_encode($input))->not->toContain('private.job', 'private.batch');
    $result = $this->projection->apply($operation, observation_result($operation, ['failed_ids' => [$failed]]));
    expect($result->result['pruned_failed'])->toBe(1)->and(DB::table('job_batches')->count())->toBe(1);
});
it('exposes existing Redis login only through private material and refuses changed connection custody', function (): void {
    $connection = DatabaseConnection::query()->create(['slug' => 'maintenance-queue', 'driver' => 'redis', 'host' => '127.0.0.1', 'port' => 6379, 'username' => 'gateway', 'password' => 'private.redis.password']);
    config(['orbit.gateway_queue.connection' => $connection->slug]);
    $operation = app(StartObservationMaintenance::class)->execute('maintenance');
    expect(json_encode($operation->input))->not->toContain('private.redis.password')->and($this->projection->input($operation))->not->toHaveKey('queue_redis');
    $source = app(ObservationQueueRedisSource::class);
    expect($source->resolve($operation, $this->gateway->id, '', 'queue_redis')->value)->toBe(['username' => 'gateway', 'password' => 'private.redis.password'])
        ->and(json_encode($source->resolve($operation, $this->gateway->id, '', 'queue_redis')))->toBe('"[REDACTED]"');
    request()->setUserResolver(fn () => null);
    expect(fn () => $source->resolve($operation, $this->gateway->id, '', 'queue_redis'))->toThrow(ResourceOperationException::class);
    request()->setUserResolver(fn () => $this->gateway);
    $connection->update(['host' => '192.0.2.1']);
    expect(fn () => $source->resolve($operation, $this->gateway->id, '', 'queue_redis'))->toThrow(ResourceOperationException::class);
});
it('refuses a failed-job backend that has no native retention implementation before admission', function (): void {
    config(['queue.failed.driver' => 'file']);
    expect(fn () => app(StartObservationMaintenance::class)->execute('retention'))->toThrow(ResourceOperationException::class);
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
