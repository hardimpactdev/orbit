<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Jobs\Operations\ProjectSwampScheduleFire;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

function nativeFireFeed(array $request, int $cursor, string $workflow): array
{
    return ['type' => 'commits.subscribe', 'id' => $request['id'], 'payload' => ['cursor' => $cursor, 'head' => $cursor, 'done' => false,
        'changes' => [['cursor' => $cursor, 'commit' => ['ops' => [['kind' => 'put', 'intent' => ['kind' => 'run.create',
            'run' => ['workflowId' => $workflow, 'runId' => 'b075eb23-7ac4-4487-80d1-57a038a92676']]]]]]]]];
}

function nativeFireSubscribe(SwampScheduledResults $reader, FakeSwampEventSocket $socket): void
{
    $reader->pass();
    $declarations = app(DirectOperation::class)->scheduledResultDefinitions();
    $socket->push(...array_map(static fn (array $request): array => ['type' => 'workflow.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $request['payload']['workflowIdOrName'], 'name' => $declarations[$request['payload']['workflowIdOrName']]['workflow'],
    ]]], array_slice($socket->sent, -count($declarations))));
    $reader->pass();
}

beforeEach(function (): void {
    config(['swamp.read_token' => 'fixture.reader', 'swamp.run_token' => 'fixture.run', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    Cache::flush();
    $this->node = Node::query()->create(['name' => 'fire-target', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.12', 'public_ssh_host' => '192.0.2.12']);
    $this->workflowId = '6b2f0c3e-7a2b-4c9d-8e6f-0a1b2c3d4e5f';
    $this->schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly', 'calendar' => '0 3 * * *', 'command' => 'printf fire', 'timeout_seconds' => 60, 'desired_timer_state' => 'enabled',
        'status' => 'active', 'trigger' => 'swamp', 'swamp_workflow_id' => $this->workflowId]);
    $this->scheduleId = $this->schedule->id;
    $this->runId = 'c175eb23-7ac4-4487-80d1-57a038a92676';
});

function nativeScheduleFireHistory(object $test, string $status, ?string $code = null): void
{
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $test->runId, 'workflowId' => $test->workflowId, 'workflowName' => 'schedule-'.$test->scheduleId, 'status' => $status,
        'tags' => ['orbit_schedule_id' => $test->scheduleId, 'orbit_node_id' => (string) $test->node->id,
            'orbit_target_type' => 'node_schedules', 'orbit_target_id' => (string) $test->node->id],
        'startedAt' => '2026-11-02T03:00:00Z', 'completedAt' => $status === 'running' ? null : '2026-11-02T03:00:04Z',
        'jobs' => $code === null ? [] : [['name' => 'fire', 'steps' => [['name' => 'Run the Schedule', 'status' => 'failed',
            'outputs' => ['Report the result' => ['stdout' => json_encode(['error_code' => $code])]]]]]],
    ]]]];
    app()->instance(WebSocketClient::class, $socket);
}

it('follows a root run of a Schedule workflow from the shared commit feed', function (): void {
    Bus::fake([ProjectSwampScheduleFire::class]);
    $socket = new FakeSwampEventSocket;
    $reader = new SwampScheduledResults($socket, new NullLogger);
    nativeFireSubscribe($reader, $socket);
    $socket->push(nativeFireFeed(array_last($socket->sent), 3, 'd075eb23-7ac4-4487-80d1-57a038a92676'));
    $reader->pass();
    Bus::assertNotDispatched(ProjectSwampScheduleFire::class);
    $socket->push(nativeFireFeed(array_last($socket->sent), 5, $this->workflowId));
    $reader->pass();
    Bus::assertDispatched(ProjectSwampScheduleFire::class, fn (ProjectSwampScheduleFire $job): bool => $job->workflowId === $this->workflowId
        && $job->runId === 'b075eb23-7ac4-4487-80d1-57a038a92676');
});

it('records a finished fire once as a task with trigger schedule and sets the last run', function (string $native, OperationStatus $status, string $last): void {
    nativeScheduleFireHistory($this, $native);
    expect(app(SwampCommandStep::class)->projectScheduleFire($this->workflowId, $this->runId))->toBeTrue()
        ->and(app(SwampCommandStep::class)->projectScheduleFire($this->workflowId, $this->runId))->toBeTrue();
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('schedule.run')
        ->and($operation->triggered_by)->toBe('schedule')
        ->and($operation->status)->toBe($status)
        ->and($operation->target_type)->toBe('node_schedules')
        ->and($operation->input['id'])->toBe($this->schedule->id)
        ->and($operation->state['swamp_fire']['run_id'])->toBe($this->runId)
        ->and($this->schedule->fresh()->last_run_status?->value)->toBe($last);
})->with([['succeeded', OperationStatus::Succeeded, 'success'], ['failed', OperationStatus::Failed, 'error']]);

it('records a fire as a running task when it starts and gives the same task its result after its Schedule is gone', function (): void {
    nativeScheduleFireHistory($this, 'running');
    expect(app(SwampCommandStep::class)->projectScheduleFire($this->workflowId, $this->runId))->toBeFalse();
    $running = Operation::query()->sole();
    expect($running->status)->toBe(OperationStatus::Running)
        ->and($running->triggered_by)->toBe('schedule')
        ->and($running->input['run_budget_seconds'])->toBe(60 + 120);
    $this->schedule->delete();
    nativeScheduleFireHistory($this, 'failed', 'schedule.target_unavailable');
    expect(app(SwampCommandStep::class)->projectScheduleFire($this->workflowId, $this->runId))->toBeTrue();
    $ended = Operation::query()->sole();
    expect($ended->id)->toBe($running->id)
        ->and($ended->status)->toBe(OperationStatus::Failed)
        ->and($ended->error_code)->toBe('schedule.target_unavailable')
        ->and($ended->node_id)->toBe($this->node->id);
});
