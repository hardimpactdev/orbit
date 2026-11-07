<?php

declare(strict_types=1);

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
use App\Domain\AgentView\AgentViewSubscriberHealth;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Logs\LogRedactor;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamBroadcast;
use App\Domain\Logs\LogStreamRecordType;
use App\Domain\Logs\LogStreamSource;
use App\Domain\Logs\LogStreamStore;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Logs\CacheLogStreamStore;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Event::fake([LogStreamBroadcast::class]);
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'logs-host', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.8', 'public_ssh_host' => '10.44.0.8', 'user' => 'orbit',
    ]));
    $this->node->accessibleNodes()->attach($this->node->id);
    $this->schedule = Schedule::query()->create([
        'target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'cleanup', 'calendar' => 'hourly', 'command' => 'true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled', 'status' => 'active',
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    app()->instance(LogStreamStore::class, new CacheLogStreamStore(Cache::store('array')));
    $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
    app()->forgetInstance(RealtimeConnection::class);
    $view = $this->mock(AgentStateView::class);
    $view->shouldReceive('node')->andReturn(new AgentNodeView($this->node->id, AgentViewFreshness::Fresh, logs: true));
    $view->shouldReceive('subscriber')->andReturn(new AgentViewSubscriberHealth(microtime(true), true, true, 1));
    $this->path = '/api/v1/schedules/'.$this->schedule->id.'/log-streams';
});

it('opens activates and closes the viewer lease without changing the Schedule UUID', function (): void {
    $response = $this->postJson($this->path, ['socket_id' => '123.456', 'lines' => 250])->assertCreated();
    $id = $response->json('data.id');
    $store = app(LogStreamStore::class);
    $stream = $store->find($id);
    expect($stream->recordId)->toBe($this->schedule->id)
        ->and($stream->recordType)->toBe(LogStreamRecordType::Schedule)
        ->and($stream->source->unit)->toBe('orbit-schedule-'.$this->schedule->id.'.service')
        ->and($stream->active)->toBeFalse()
        ->and(LogStream::fromArray($stream->toArray())?->recordId)->toBe($this->schedule->id);
    $this->putJson($this->path.'/'.$id)->assertOk();
    expect($store->find($id)?->active)->toBeTrue();
    $this->deleteJson($this->path.'/'.$id)->assertOk();
    expect($store->find($id))->toBeNull();
});

it('refuses another viewer before renewing or closing the lease', function (): void {
    $id = $this->postJson($this->path, ['socket_id' => '123.456'])->assertCreated()->json('data.id');
    $other = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'other-viewer', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip]);
    $this->putJson($this->path.'/'.$id)->assertForbidden();
    $other->accessibleNodes()->attach($this->node->id);
    $this->putJson($this->path.'/'.$id)->assertNotFound()->assertJsonPath('error.code', 'logs.stream_not_found');
    $this->deleteJson($this->path.'/'.$id)->assertNotFound();
    expect(app(LogStreamStore::class)->find($id))->not->toBeNull();
});

it('validates the socket and line bounds and refuses an inactive Schedule', function (): void {
    $this->postJson($this->path, ['socket_id' => 'invalid', 'lines' => 1001])->assertUnprocessable();
    $this->schedule->update(['status' => 'failed']);
    $this->postJson($this->path, ['socket_id' => '123.456'])->assertStatus(409)->assertJsonPath('error.code', 'schedule.state_invalid');
});

it('keeps the Schedule journal source strict', function (string $unit): void {
    expect(fn () => LogStreamSource::journal($unit))->toThrow(InvalidArgumentException::class);
})->with(['ssh.service', 'orbit-schedule-../x.service', 'orbit-schedule-123e4567-e89b-42d3-a456-426614174000.timer']);

it('redacts the owning Instance environment from Schedule output', function (): void {
    $instance = new Instance;
    $instance->setRelation('environmentValues', new Collection([
        new InstanceEnvironmentValue(['env_key' => 'API_TOKEN', 'env_value' => 'schedule-secret-sentinel']),
    ]));
    $schedule = new Schedule;
    $schedule->setRelation('target', $instance);
    $redactor = app(LogRedactor::class);
    expect($redactor->redact('output schedule-secret-sentinel', $redactor->valuesFor($schedule)))->toBe('output [REDACTED]');
});

it('redacts unlabeled stored Instance values from the snapshot used during polling fallback', function (): void {
    $project = Project::query()->create([
        'name' => 'Schedule logs', 'slug' => 'schedule-logs',
        'repository_url' => 'https://github.com/example/schedule-logs.git',
    ]);
    $instance = Instance::withoutEvents(fn (): Instance => Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $this->node->id,
        'name' => 'schedule-logs', 'checkout_path' => '/srv/schedule-logs', 'status' => 'active',
    ]));
    $instance->environmentValues()->create(['env_key' => 'CUSTOM_VALUE', 'env_value' => 'synthetic-schedule-value']);
    $this->schedule->update(['target_type' => Instance::class, 'target_id' => $instance->id]);
    $this->mock(ScheduleRuntimeManager::class)->shouldReceive('logs')->once()
        ->withArgs(fn (Schedule $schedule, int $lines): bool => $schedule->id === $this->schedule->id && $lines === 2)
        ->andReturn(new ScheduleLogsData("worker printed synthetic-schedule-value\nnormal output\n", true));

    $this->getJson('/api/v1/schedules/'.$this->schedule->id.'/logs?lines=2')
        ->assertOk()
        ->assertJsonPath('data.output', "worker printed [REDACTED]\nnormal output\n")
        ->assertJsonPath('data.truncated', true)
        ->assertDontSee('synthetic-schedule-value');
});
