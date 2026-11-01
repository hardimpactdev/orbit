<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampRunSubscriber;
use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Cache;
use Psr\Log\NullLogger;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\FakeSwampEventSocket;

final readonly class ScheduledObservationProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return [];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        if (($data['result']['check_id'] ?? null) !== $operation->input['check_id']) {
            throw new ResourceOperationException('operation.result_invalid', 'The check identity changed.', 422);
        }
        if ($operation->cancel_requested_at !== null) {
            $data['status'] = 'cancelled';
            $data['error_code'] = null;
        } elseif (($data['result']['pending'] ?? false) === true) {
            $data['status'] = 'running';
        }

        return app(DirectOperation::class)->project($operation, $data, $data['result']);
    }
}

function scheduledObservationDeclaration(): array
{
    return ['workflow' => 'task-check-ci', 'workflow_id' => '4a2d9dd5-9f83-41d4-b8df-8e46367ed230',
        'terminal_result' => ['job' => 'checks', 'step' => 'result'], 'rows' => 'checks'];
}

function scheduledObservationDirect(array $scheduled): DirectOperation
{
    return new DirectOperation([new class($scheduled) implements DirectOperationContribution
    {
        public function __construct(private readonly array $scheduled) {}

        public function definitions(): array
        {
            return ['test.scheduled_observation' => ['workflow' => 'operation-test-check', 'projection' => ScheduledObservationProjection::class,
                'terminal_result' => ['job' => 'check', 'step' => 'result'], 'scheduled_result' => $this->scheduled]];
        }
    }]);
}

beforeEach(function (): void {
    app()->instance(DirectOperation::class, scheduledObservationDirect(scheduledObservationDeclaration()));
    $node = Node::query()->create(['name' => 'scheduled-observation', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.209']);
    $this->original = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->scheduled = 'b075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'test.scheduled_observation', 'node_id' => $node->id,
        'target_type' => 'node', 'target_id' => $node->id, 'status' => OperationStatus::Running, 'sequence' => 1,
        'lock_key' => 'scheduled-observation-'.$node->id, 'input' => ['check_id' => 17],
        'result' => ['check_id' => 17, 'pending' => true], 'state' => ['swamp_run_id' => $this->original], 'started_at' => now()]);
    $this->row = ['operation_id' => $this->operation->id, 'node_id' => $node->id, 'kind' => $this->operation->kind,
        'root_run_id' => $this->original, 'status' => 'succeeded', 'result' => ['check_id' => 17, 'pending' => true, 'ci' => ['state' => 'pending']]];
    $this->history = ['id' => $this->scheduled, 'workflowId' => scheduledObservationDeclaration()['workflow_id'], 'workflowName' => 'task-check-ci',
        'status' => 'succeeded', 'startedAt' => '2026-11-01T00:00:00Z', 'completedAt' => '2026-11-01T00:00:01Z'];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $request): array {
        $run = [...$this->history, 'jobs' => [['name' => 'checks', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode(['checks' => [$this->row]])]]]]]];

        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.read_token' => 'fixture.read', 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
});

it('declares one fixed scheduled workflow and public row selector', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[scheduledObservationDeclaration()['workflow_id']])->toBe(scheduledObservationDeclaration());
});

it('projects pending then terminal observations without replacing the original binding', function (): void {
    $step = app(SwampCommandStep::class);
    $step->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    $operation = $this->operation->fresh();
    expect($operation->status)->toBe(OperationStatus::Running);
    expect($operation->lock_key)->not->toBeNull();
    expect($operation->state['swamp_run_id'])->toBe($this->original);
    expect($operation->state['swamp_scheduled_result']['run_id'])->toBe($this->scheduled);
    $sequence = $operation->sequence;
    $step->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    expect($operation->fresh()->sequence)->toBe($sequence);
    $this->scheduled = 'c075eb23-7ac4-4487-80d1-57a038a92676';
    $this->history['id'] = $this->scheduled;
    $this->history['startedAt'] = '2026-11-01T00:01:00Z';
    $this->history['completedAt'] = '2026-11-01T00:01:01Z';
    $this->row['result'] = ['check_id' => 17, 'pending' => false, 'ci' => ['state' => 'passed']];
    $step->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->original);
});

it('lets the family acknowledge cancellation on a pending observation', function (): void {
    $this->operation->update(['cancel_requested_at' => now()]);
    app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Cancelled);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($this->original);
    expect($this->operation->fresh()->state['swamp_scheduled_result']['native_status'])->toBe('succeeded');
});

it('refuses foreign observation identity before changing a pending operation', function (string $field, mixed $value): void {
    $this->row[$field] = $value;
    expect(fn () => app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->sequence)->toBe(1);
    expect($this->operation->fresh()->state)->toBe(['swamp_run_id' => $this->original]);
})->with([['node_id', 99999], ['kind', 'tasks.other'], ['root_run_id', 'd075eb23-7ac4-4487-80d1-57a038a92676'], ['status', 'running'], ['result', ['check_id' => 18]]]);

it('does not reopen a terminal operation from a pending observation', function (): void {
    $this->operation->update(['status' => OperationStatus::Cancelled, 'lock_key' => null]);
    app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Cancelled);
    expect($this->operation->fresh()->sequence)->toBe(1);
});

it('rejects a scheduled declaration without a fixed identity and selector', function (string $field, mixed $value): void {
    expect(fn () => scheduledObservationDirect([...scheduledObservationDeclaration(), $field => $value]))->toThrow(LogicException::class);
})->with([
    ['workflow', '${{ inputs.workflow }}'], ['workflow_id', 'task-check-ci'], ['rows', 'checks.*'],
    ['terminal_result', ['job' => 'checks']], ['unknown', 'field'],
]);

it('rejects another workflow or a nonterminal history before projecting a row', function (string $field, mixed $value): void {
    $this->history[$field] = $value;
    expect(fn () => app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->sequence)->toBe(1);
})->with([
    ['workflowId', 'd075eb23-7ac4-4487-80d1-57a038a92676'], ['workflowName', 'task-check-other'], ['status', 'running'],
    ['parentRun', ['runId' => 'd075eb23-7ac4-4487-80d1-57a038a92676']],
]);

it('does not read history for a nonterminal notice', function (): void {
    expect(fn () => app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'running']))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBeEmpty();
});

it('ignores an older observation without replacing a newer pending result', function (): void {
    $this->operation->update(['state' => ['swamp_run_id' => $this->original, 'swamp_scheduled_result' => [
        'run_id' => 'c075eb23-7ac4-4487-80d1-57a038a92676', 'started_at' => '2026-11-01T00:01:00Z',
    ]]]);
    app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled, 'status' => 'succeeded']);
    expect($this->operation->fresh()->sequence)->toBe(1);
    expect($this->operation->fresh()->state['swamp_scheduled_result']['run_id'])->toBe('c075eb23-7ac4-4487-80d1-57a038a92676');
});

it('rejects invalid observation timestamps before saving provenance', function (string $field, string $value): void {
    $this->history[$field] = $value;
    expect(fn () => app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled]))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->state)->toBe(['swamp_run_id' => $this->original]);
})->with([['startedAt', 'tomorrow'], ['completedAt', ''], ['completedAt', '2026-10-31T23:59:59Z']]);

it('rolls back the whole observation batch when another row has a foreign identity', function (bool $duplicate): void {
    $other = $this->operation->replicate();
    $other->forceFill(['lock_key' => 'other-check', 'input' => ['check_id' => 18]])->save();
    $rows = [$this->row, [...$this->row, 'operation_id' => $duplicate ? $this->operation->id : $other->id,
        'result' => ['check_id' => 99999, 'pending' => false]]];
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        ...$this->history, 'jobs' => [['name' => 'checks', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode(['checks' => $rows])]]]]]],
    ]]];

    expect(fn () => app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled]))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->sequence)->toBe(1);
    expect($this->operation->fresh()->state)->toBe(['swamp_run_id' => $this->original]);
    expect($other->fresh()->sequence)->toBe(1);
})->with([false, true]);

it('projects a failed native observation through the family without changing the original binding', function (): void {
    $this->row['status'] = 'failed';
    $this->row['result'] = ['check_id' => 17, 'pending' => false, 'error_code' => 'tasks.ci_unavailable'];
    app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled]);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Failed);
    expect($this->operation->fresh()->error_code)->toBe('tasks.ci_unavailable');
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($this->original);
});

it('projects a discovered native scheduled result end to end through the existing observer', function (): void {
    config(['swamp.run_token' => 'fixture.events', 'queue.connections.gateway.driver' => 'sync']);
    Cache::flush();
    $events = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber(new FakeSwampEventSocket, new NullLogger, scheduled: new SwampScheduledResults($events, new NullLogger));
    $subscriber->pass();
    $replies = [];
    foreach ($events->sent as $read) {
        $id = $read['payload']['workflowIdOrName'];
        $replies[] = ['type' => 'workflow.get', 'id' => $read['id'], 'payload' => ['data' => [
            'id' => $id, 'name' => app(DirectOperation::class)->scheduledResultDefinitions()[$id]['workflow'],
        ]]];
    }
    $events->push(...$replies);
    $subscriber->pass();
    $feed = array_last($events->sent);
    $this->row['result']['pending'] = false;
    $events->push(['type' => 'commits.subscribe', 'id' => $feed['id'], 'payload' => ['cursor' => 4, 'head' => 4, 'done' => false,
        'changes' => [['cursor' => 4, 'commit' => ['ops' => [['intent' => ['kind' => 'run.update', 'run' => [
            'workflowId' => scheduledObservationDeclaration()['workflow_id'], 'runId' => $this->scheduled,
        ]]]]]]]]]);
    $subscriber->pass();

    expect($this->operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($this->original);
    expect($this->operation->fresh()->state['swamp_scheduled_result']['run_id'])->toBe($this->scheduled);
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.history.get']);
});

it('keeps a newer scheduled pending observation when startup replays the original admission result', function (): void {
    $this->row['result']['ci']['observed'] = 'newer-observation';
    $step = app(SwampCommandStep::class);
    $step->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled]);
    $sequence = $this->operation->fresh()->sequence;
    $newerResult = $this->operation->fresh()->result;
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $this->original, 'workflowName' => 'operation-test-check', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->operation->node_id, 'kind' => $this->operation->kind],
        'startedAt' => '2026-10-31T23:59:00Z', 'completedAt' => '2026-10-31T23:59:01Z',
        'jobs' => [['name' => 'check', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode(['check_id' => 17, 'pending' => true])]]]]],
    ]]]];
    config(['swamp.run_token' => 'fixture.events', 'queue.connections.gateway.driver' => 'sync']);
    $events = new FakeSwampEventSocket;
    $subscriber = new SwampRunSubscriber($events, new NullLogger);
    $subscriber->pass();
    $attach = array_values(array_filter($events->sent, fn (array $message): bool => $message['type'] === 'run.attach' && $message['payload']['runId'] === $this->original))[0];
    $events->push(['type' => 'error', 'id' => $attach['id'], 'error' => ['code' => 'not_found']]);
    $subscriber->pass();
    expect($this->operation->fresh()->result)->toBe($newerResult);
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->operation->fresh()->state['swamp_scheduled_result']['run_id'])->toBe($this->scheduled);
});

it('retains nonterminal native hints until a later native commit supplies terminal history', function (string $status): void {
    $this->history['status'] = $status;
    $this->history['completedAt'] = null;
    expect(app(SwampCommandStep::class)->projectScheduledTerminal(scheduledObservationDeclaration()['workflow_id'], ['id' => $this->scheduled]))->toBeFalse();
    expect($this->operation->fresh()->sequence)->toBe(1);
    expect($this->operation->fresh()->state)->toBe(['swamp_run_id' => $this->original]);
})->with(['pending', 'running', 'suspended']);
