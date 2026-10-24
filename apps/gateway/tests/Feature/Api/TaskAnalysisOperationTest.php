<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Tasks\StopTaskSubtaskAction;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\OperationSweeper;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskAnalysisRunner;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskTurnOperation;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Models\AgentThread;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Tasks\AnalyzeTaskGroupRequest;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Tests\Support\FakeAgentDriver;
use Tests\Support\FakeTaskAnalysisRunner;
use Tests\Support\FakeTaskCheckRunner;
use Tests\Support\FakeTaskTurnReceipts;

beforeEach(function (): void {
    enable_operation_queue();
    tick_bind_defaults();
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskAnalysisRunner::class, new FakeTaskAnalysisRunner);
    $this->travelTo('2026-10-22 12:00:00');
    Str::createUuidsUsing(static fn (): UuidInterface => Uuid::fromString('33333333-3333-4333-8333-333333333333'));
});

afterEach(function (): void {
    Str::createUuidsNormally();
});

function analysis_operation_group(): Task
{
    $group = tick_group();
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => TaskOrchestration::External->value]);
    $group->refresh();
    $group->tasks()->update(['implementer_agent_thread_id' => null]);
    AgentThread::query()->where('task_group_id', $group->id)->delete();
    test()->markAsGateway($group->taskable->node);
    test()->withServerVariables(['REMOTE_ADDR' => $group->taskable->node->wireguard_ip]);
    Operation::query()->create(['kind' => 'tasks.provision', 'target_type' => 'task_group', 'target_id' => $group->id, 'status' => 'succeeded', 'input' => [], 'state' => ['analysis_base' => ['base' => str_repeat('a', 40), 'instance_id' => $group->taskable_id, 'repository' => $group->project->repository_url, 'branch' => $group->taskable->branch]]]);

    return $group->refresh();
}

/** @return array<string, mixed> */
function analysis_operation_input(Task $group): array
{
    // ASCII keys are already in canonical order; this fixture fixes its exact digest.
    $canonical = '{"asserted_by":"orchestrator","complete":true,"id":"feature-2068","paths":[{"change":"add","path":"apps/gateway/new.php"}],"revision":1,"source":{"brief_id":"project:46/brief:2","revision_sha256":"'.str_repeat('d', 64).'"}}';

    return ['subtask' => $group->tasks()->firstOrFail()->id, 'plan' => json_decode($canonical, true, flags: JSON_THROW_ON_ERROR), 'plan_sha256' => hash('sha256', $canonical), 'base' => str_repeat('a', 40), 'head' => str_repeat('a', 40), 'tree' => str_repeat('b', 40)];
}

function analysis_operation_start(Task $group, string $key = 'analysis-one'): Operation
{
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/analyze', analysis_operation_input($group), ['Idempotency-Key' => $key])->assertAccepted()->assertJsonPath('data.kind', 'tasks.analyze');

    return Operation::query()->findOrFail($response->json('data.id'));
}

function analysis_operation_finish(Operation $operation): void
{
    app(OperationRunner::class)->run($operation->id);
    test()->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $operation->refresh();
}

it('retains the complete exact report and native provenance without a check, agent, or board transition', function (string $status): void {
    $group = analysis_operation_group();
    $before = $group->status;
    $runner = app(TaskAnalysisRunner::class);
    $runner->status = $status;
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result['outcome'])->toBe($status)
        ->and($operation->result['artifact']['bytes'])->toBe(strlen($operation->result['artifact']['content']))
        ->and($operation->result['artifact']['sha256'])->toBe(hash('sha256', $operation->result['artifact']['content']))
        ->and($operation->result['provenance']['mechanical_llm_calls'])->toBe(0)
        ->and($operation->result['provenance']['execution_id'])->toBe($runner->input['execution_id'])
        ->and($operation->steps()->orderBy('position')->pluck('name')->all())->toBe(['workspace_ready', 'analysis', 'report'])
        ->and($runner->starts)->toBe(1)
        ->and(TaskCheck::query()->count())->toBe(0)
        ->and(AgentThread::query()->count())->toBe(0)
        ->and($group->fresh()->status)->toBe($before)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
})->with(['ready', 'action_required', 'blocked']);

it('records native queued analysis and refusal fixtures', function (): void {
    $group = analysis_operation_group();
    $response = $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', analysis_operation_input($group), ['Idempotency-Key' => 'analysis.c1'])->assertAccepted();
    record_fixture($response, 'tasks/tasks-analyze/queued', AnalyzeTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/analyze');
    // Replay has its own permanent key; a refused native request does not execute anything.
    Task::topLevel()->whereKey($group->id)->update(['orchestration' => 'tick']);
    record_fixture($this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', analysis_operation_input($group), ['Idempotency-Key' => 'analysis.refusal'])->assertConflict(),
        'tasks/tasks-analyze/not-orchestrated', AnalyzeTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/analyze');
});

it('records complete native terminal analysis evidence', function (string $outcome): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    if ($outcome === 'changed') {
        $runner->changedTree = str_repeat('c', 40);
    } else {
        $runner->status = $outcome;
    }
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    record_fixture($this->getJson('/api/v1/operations/'.$operation->id)->assertOk()->assertJsonPath('data.result.outcome', $outcome),
        'tasks/tasks-analyze/'.$outcome, ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
})->with(['ready', 'action_required', 'blocked', 'changed']);

it('requires a key and refuses unknown input and invalid canonical manifest identity', function (): void {
    $group = analysis_operation_group();
    $url = '/api/v1/task-groups/'.$group->id.'/analyze';
    $input = analysis_operation_input($group);
    $this->postJson($url, $input)->assertUnprocessable();
    $this->postJson($url, [...$input, 'command' => 'true'], ['Idempotency-Key' => 'bad-field'])->assertUnprocessable();
    $this->postJson($url, [...$input, 'plan_sha256' => str_repeat('e', 64)], ['Idempotency-Key' => 'bad-digest'])->assertUnprocessable();
    expect(Operation::query()->where('kind', 'tasks.analyze')->count())->toBe(0)->and(app(TaskAnalysisRunner::class)->starts)->toBe(0);
});

it('replays before mutable admission but never normalizes duplicate nested keys into that replay', function (): void {
    $group = analysis_operation_group();
    $operation = analysis_operation_start($group);
    $group->update(['status' => 'cancelled']);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/analyze', analysis_operation_input($group), ['Idempotency-Key' => 'analysis-one'])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    $json = json_encode(analysis_operation_input($group), JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/analyze', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'analysis-one'], $json.str_repeat(' ', 49152))->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    $json = str_replace('"complete":true', '"complete":false,"complete":true', $json);
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/analyze', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'analysis-one'], $json)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
});

it('shares the target lock with ordinary turns and checks rather than admitting competing work', function (): void {
    $group = analysis_operation_group();
    $holder = analysis_operation_start($group);
    $url = '/api/v1/task-groups/'.$group->id;
    foreach ([['/analyze', analysis_operation_input($group)], ['/turn', ['subtask' => $group->tasks()->sole()->id, 'role' => 'implementer', 'prompt' => 'Continue the assigned task.', 'thread' => 'new']], ['/check', ['subtask' => $group->tasks()->sole()->id, 'phase' => 'baseline']]] as [$endpoint, $input]) {
        $this->postJson($url.$endpoint, $input, ['Idempotency-Key' => 'competing'.ltrim($endpoint, '/')])->assertConflict()
            ->assertJsonPath('error.code', 'operation.target_busy')->assertJsonPath('error.details.operation_id', $holder->id);
    }
    expect(app(TaskAnalysisRunner::class)->starts)->toBe(0);
});

it('never dispatches a cancelled queued analysis even when its old job runs later', function (): void {
    $group = analysis_operation_group();
    $operation = analysis_operation_start($group);
    app(CancelOperationAction::class)->execute($operation);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(app(TaskAnalysisRunner::class)->starts)->toBe(0)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->exists())->toBeFalse();
});

it('adopts the same run after a lost start reply without a second dispatch', function (): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    $runner->loseStartReply = true;
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    expect($operation->status)->toBe(OperationStatus::Succeeded)->and($runner->starts)->toBe(1)->and($runner->recovers)->toBe(1);
});

it('rejects stale whole-worktree identity before dispatch and releases its pre-dispatch lease', function (): void {
    $group = analysis_operation_group();
    $operation = analysis_operation_start($group);
    $runner = app(TaskAnalysisRunner::class);
    $runner->tree = str_repeat('e', 40);
    app(OperationRunner::class)->run($operation->id);
    expect($operation->refresh()->error_code)->toBe('tasks.analysis_stale')->and($runner->starts)->toBe(0)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
});

it('refuses malformed and oversized complete evidence without retaining a partial artifact', function (string $bytes, string $code): void {
    $group = analysis_operation_group();
    app(TaskAnalysisRunner::class)->bytes = $bytes;
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe($code)
        ->and($operation->result)->toBeNull()->and($operation->state['analysis_terminal']['reading'])->toBeNull()
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
})->with(['malformed' => ['{"schema":', 'tasks.analysis_report_invalid'], 'oversized' => [str_repeat('x', 65537), 'tasks.analysis_report_too_large']]);

it('keeps uncertain descendants fenced and stops the exact owned run on cancellation recovery', function (): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    $runner->terminal = false;
    $operation = analysis_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $runner->uncertain = true;
    app(CancelOperationAction::class)->execute($operation->refresh());
    expect($operation->refresh()->status)->not->toBe(OperationStatus::Cancelled)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('held');
    $runner->uncertain = false;
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($runner->starts)->toBe(1)->and($runner->cancels)->toBe(1)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
});

it('never recovers or cancels its reserved process on a different owning Node', function (): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    $runner->terminal = false;
    $operation = analysis_operation_start($group);
    app(OperationRunner::class)->run($operation->id);
    $instance = $group->taskable;
    $node = $instance->node_id;
    $instance->update(['node_id' => Node::query()->create(['name' => 'different-analysis-node', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.192', 'public_ssh_host' => '192.0.2.192'])->id]);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    app(CancelOperationAction::class)->execute($operation->refresh());
    expect($runner->recovers)->toBe(0)->and($runner->cancels)->toBe(0)
        ->and($operation->refresh()->status)->not->toBe(OperationStatus::Cancelled)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('held');
    $instance->update(['node_id' => $node]);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($operation->refresh()->status)->toBe(OperationStatus::Cancelled)->and($runner->cancels)->toBe(1);
});

/** @return array{Operation, FakeAgentDriver} */
function analysis_bound_turn(Task $group, Operation $analysis, string $prompt = 'Implement the plan.', array $overrides = []): array
{
    $driver = new FakeAgentDriver('pi');
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner);
    app()->instance(TaskTurnReceipts::class, new FakeTaskTurnReceipts);
    $response = test()->postJson('/api/v1/task-groups/'.$group->id.'/turn', [
        'subtask' => $analysis->input['subtask'], 'role' => 'implementer', 'thread' => 'new', 'prompt' => $prompt,
        'analysis' => ['operation' => $analysis->id, 'plan_sha256' => $analysis->input['plan_sha256']],
        ...$overrides,
    ], ['Idempotency-Key' => 'analysis-bound-turn'])->assertAccepted();

    return [Operation::query()->findOrFail($response->json('data.id')), $driver];
}

it('binds full untrusted analysis to an ordinary turn and preserves its original key and bytes on dispatched recovery', function (): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $crash = true;
    OperationStep::updating(function (OperationStep $step) use (&$crash): void {
        if ($crash && $step->name === 'send_turn' && $step->status === OperationStepStatus::Succeeded) {
            throw new RuntimeException('Injected loss after send before its completion checkpoint.');
        }
    });
    $store = app(OperationStore::class);
    $store->claim($turn, 'gone-host:4242:1');
    expect(fn () => app(TaskTurnOperation::class)->run($turn, new OperationRun($turn, $store)))
        ->toThrow(RuntimeException::class, 'Injected loss after send before its completion checkpoint.');
    $crash = false;
    $turn->refresh();
    expect($turn->current_step)->toBe('send_turn')->and($turn->state['steps'] ?? [])->not->toHaveKey('send_turn');
    $sends = array_values(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'));
    expect($sends)->toHaveCount(1)->and($sends[0]['key'])->toBe('analysis-bound-turn')
        ->and($sends[0]['message'])->toContain('untrusted project data', 'not privileged instructions or approval')
        ->and($turn->state['analysis_binding']['prompt'])->toBe($sends[0]['message'])
        ->and($turn->state['analysis_binding']['sha256'])->toBe(hash('sha256', $sends[0]['message']));
    $payload = json_decode(explode("approval):\n", $sends[0]['message'])[1], true, flags: JSON_THROW_ON_ERROR);
    expect($payload)->toBe(['manifest' => $analysis->input['plan'], 'artifact' => $analysis->result['artifact'], 'provenance' => $analysis->result['provenance']]);
    // Recover the actual unfinished persistence boundary through the normal worker sweeper.
    expect(app(OperationSweeper::class)->sweep())->toBe(1);
    $driver->observation = new AgentObservation(state: AgentThreadState::Working, turnId: 'analysis-bound-turn');
    app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
    app(OperationRunner::class)->run($turn->id);
    $sends = array_values(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'));
    expect($sends)->toHaveCount(2)->and($sends[1]['key'])->toBe($sends[0]['key'])->and($sends[1]['message'])->toBe($sends[0]['message']);
});

it('waits for an uncertain Pi send and recovers its accepted key without refreshing edited analysis', function (bool $completed): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn] = analysis_bound_turn($group, $analysis);
    $driver = new class('pi') extends FakeAgentDriver
    {
        public int $acceptedTurns = 0;

        public ?string $acceptedKey = null;

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            parent::send($thread, $message, $key);
            if ($key !== $this->acceptedKey) {
                $this->acceptedKey = $key;
                $this->acceptedTurns++;
                throw new AgentDriverException('Injected lost reply after Pi accepted the turn.');
            }
        }
    };
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));

    app(OperationRunner::class)->run($turn->id);
    $turn->refresh();
    $prompt = $turn->state['analysis_binding']['prompt'];
    expect($turn->status)->toBe(OperationStatus::Waiting)->and($turn->current_step)->toBe('send_turn')
        ->and($turn->error_code)->toBeNull()->and($driver->acceptedTurns)->toBe(1);
    app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);
    expect($turn->fresh()->status)->toBe(OperationStatus::Waiting)->and($driver->calls)->toHaveCount(2);

    $driver->observation = new AgentObservation(state: $completed ? AgentThreadState::Done : AgentThreadState::Working, turnId: 'analysis-bound-turn');
    if ($completed) {
        app(TaskWorkspaceLease::class)->reconcile($group);
        expect(app(TaskWorkspaceLease::class)->completedSend(AgentThread::query()->sole(), 'analysis-bound-turn'))->toBeTrue();
    }
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);
    $sends = array_values(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'));
    expect($turn->refresh()->status)->toBe($completed ? OperationStatus::Succeeded : OperationStatus::Waiting)
        ->and($turn->state['steps'])->toHaveKey('send_turn')->and($driver->acceptedTurns)->toBe(1)
        ->and($sends)->toHaveCount($completed ? 1 : 2);
    foreach ($sends as $send) {
        expect($send['key'])->toBe('analysis-bound-turn')->and($send['message'])->toBe($prompt);
    }
})->with(['held accepted turn' => false, 'confirmed released turn' => true]);

it('does not treat a local dispatch journal as proof of a first Pi send', function (bool $changed, bool $otherTurnWorking, bool $acceptedDuringRead): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $driver->observation = new AgentObservation(state: AgentThreadState::Idle, turnId: 'prior-turn');
    $driver->beforeTurn = function (string $prompt): void {
        if ($prompt !== '') {
            throw new RuntimeException('Injected worker loss after local admission before Pi received the send.');
        }
    };
    $crash = true;
    OperationStep::updating(function (OperationStep $step) use (&$crash): void {
        if ($crash && $step->name === 'send_turn' && $step->status === OperationStepStatus::Failed) {
            throw new RuntimeException('Injected worker loss after local admission before Pi received the send.');
        }
    });
    $store = app(OperationStore::class);
    $store->claim($turn, 'gone-host:4242:1');
    expect(fn () => app(TaskTurnOperation::class)->run($turn, new OperationRun($turn, $store)))
        ->toThrow(RuntimeException::class, 'Injected worker loss after local admission before Pi received the send.');
    $crash = false;
    expect($turn->refresh()->current_step)->toBe('send_turn')
        ->and($turn->steps()->where('name', 'send_turn')->sole()->status)->toBe(OperationStepStatus::Running);
    expect(app(TaskWorkspaceLease::class)->pendingSend(AgentThread::query()->sole(), 'analysis-bound-turn', true))->not->toBeNull();
    expect(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'))->toBe([]);
    $binding = $turn->state['analysis_binding'];
    $driver->beforeTurn = null;
    $driver->observation = new AgentObservation(state: $otherTurnWorking ? AgentThreadState::Working : AgentThreadState::Idle, turnId: 'prior-turn');
    if ($acceptedDuringRead) {
        app(TaskAnalysisRunner::class)->beforeInspect = function () use ($driver): void {
            app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
            $driver->observation = new AgentObservation(state: AgentThreadState::Working, turnId: 'analysis-bound-turn');
        };
    } elseif ($changed) {
        app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
    }

    expect(app(OperationSweeper::class)->sweep())->toBe(1);
    app(OperationRunner::class)->run($turn->id);

    $sends = array_values(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'));
    expect($turn->refresh()->error_code)->toBeNull()
        ->and($turn->status)->toBe(OperationStatus::Waiting)
        ->and($turn->state['analysis_binding'])->toBe($binding)
        ->and($sends)->toHaveCount(! $changed || $acceptedDuringRead ? 1 : 0);
    if ($sends !== []) {
        expect($sends[0]['key'])->toBe('analysis-bound-turn')
            ->and($sends[0]['message'])->toBe($turn->state['analysis_binding']['prompt']);
    }
})->with([
    'stale before first send' => [true, false, false],
    'fresh before first send' => [false, false, false],
    'unconfirmed working turn' => [true, true, false],
    'Pi accepts the in-flight request while freshness is read' => [true, false, true],
]);

it('retains the original admission when Pi accepts after the last negative freshness observation', function (bool $priorKnown): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn] = analysis_bound_turn($group, $analysis);
    $driver = new class('pi') extends FakeAgentDriver
    {
        public bool $priorKnown = true;

        /** @var array{key: string, message: string}|null */
        public ?array $pending = null;

        public int $negativeReadsUntilAcceptance = 0;

        public int $acceptedTurns = 0;

        public function send(AgentThread $thread, string $message, ?string $key = null): void
        {
            parent::send($thread, $message, $key);
            if ($this->pending === null) {
                $this->pending = ['key' => $key ?? '', 'message' => $message];
                throw new AgentDriverException('Injected delayed request and lost reply before Pi admission.');
            }
            expect($key)->toBe($this->pending['key']);
            expect($message)->toBe($this->pending['message']);
        }

        public function observe(AgentThread $thread): AgentObservation
        {
            if (! $this->priorKnown && $this->pending === null) {
                throw new AgentDriverException('Injected unavailable prior turn.');
            }
            $answer = $this->observation ?? new AgentObservation(state: AgentThreadState::Idle, turnId: 'prior-turn');
            if ($this->negativeReadsUntilAcceptance > 0 && --$this->negativeReadsUntilAcceptance === 0) {
                $this->acceptedTurns++;
                $this->observation = new AgentObservation(state: AgentThreadState::Working, turnId: $this->pending['key'] ?? '');
            }

            return $answer;
        }
    };
    $driver->priorKnown = $priorKnown;
    $driver->supportsInterruption = true;
    app()->instance(AgentDriverRegistry::class, new AgentDriverRegistry([$driver]));
    app(OperationRunner::class)->run($turn->id);
    expect($turn->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($turn->current_step)->toBe('send_turn');
    $binding = $turn->state['analysis_binding'];
    $lease = TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole();
    app(TaskWorkspaceLease::class)->reconcile($group);
    expect($lease->refresh()->status)->toBe('held');
    expect($lease->getAttribute('dispatch_completed'))->toBeFalse();
    expect(fn () => app(StopTaskSubtaskAction::class)->executeExternal($group, $group->tasks()->findOrFail($analysis->input['subtask'])))
        ->toThrow(ResourceOperationException::class, 'A workspace actor still holds the subtask lease.');
    expect($lease->refresh()->status)->toBe('held');

    app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
    $driver->negativeReadsUntilAcceptance = 2;
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);

    expect($turn->refresh()->status)->toBe(OperationStatus::Waiting);
    expect($turn->error_code)->toBeNull();
    expect($turn->current_step)->toBe('send_turn');
    expect($turn->state['analysis_binding'])->toBe($binding);
    expect($lease->refresh()->status)->toBe('held');
    expect($driver->acceptedTurns)->toBe(1);
    expect(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'))->toHaveCount(1);
    expect(fn () => app(TaskWorkspaceLease::class)->acquire($group, 'check', 'competing-check', queue: false))->toThrow(TaskWorkspaceWaiting::class);

    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);
    expect($turn->refresh()->current_step)->toBe('turn');
    expect($turn->state['analysis_binding'])->toBe($binding);
    expect($lease->refresh()->status)->toBe('held');
    $driver->observation = new AgentObservation(state: AgentThreadState::Done, turnId: 'analysis-bound-turn');
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);
    expect($turn->refresh()->status)->toBe(OperationStatus::Succeeded);
    expect($lease->refresh()->status)->toBe('released');
    expect($driver->acceptedTurns)->toBe(1);
    $sends = array_values(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'));
    expect($sends)->toHaveCount(2);
    expect($sends[1])->toBe($sends[0]);
})->with(['known previous turn' => [true], 'unavailable previous turn' => [false]]);

it('refuses corrupt retained analysis bindings without resending or releasing the original dispatch', function (string $field, mixed $value): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $driver->beforeTurn = static function (string $prompt): void {
        if ($prompt !== '') {
            throw new AgentDriverException('Injected lost reply before the send checkpoint.');
        }
    };
    app(OperationRunner::class)->run($turn->id);
    expect($turn->refresh()->status)->toBe(OperationStatus::Waiting);
    $state = $turn->state;
    $state['analysis_binding'][$field] = $value;
    $turn->update(['state' => $state]);
    $driver->beforeTurn = null;
    $driver->observation = new AgentObservation(state: AgentThreadState::Working, turnId: 'analysis-bound-turn');
    app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);

    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);

    expect($turn->refresh()->status)->toBe(OperationStatus::Failed);
    expect($turn->error_code)->toBe('operation.failed');
    expect($turn->state['analysis_binding'])->toBe($state['analysis_binding']);
    expect(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->dispatch_key)->toBe('analysis-bound-turn');
    expect(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'))->toBe([]);
})->with([
    'changed prompt bytes' => ['prompt', 'A substituted prompt.'],
    'changed hash' => ['sha256', 'wrong'],
    'missing hash' => ['sha256', null],
    'foreign analysis reference' => ['analysis', ['operation' => 999999, 'plan_sha256' => str_repeat('d', 64)]],
    'unknown binding member' => ['extra', true],
]);

it('keeps an explicit analysis-bound Pi rejection terminal rather than retrying it', function (): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $driver->failNextSend = true;

    app(OperationRunner::class)->run($turn->id);

    expect($turn->refresh()->status)->toBe(OperationStatus::Failed)->and($turn->error_code)->toBe('tasks.turn_failed')
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->count())->toBe(0)
        ->and(app(TaskWorkspaceLease::class)->pendingSend(AgentThread::query()->sole(), 'analysis-bound-turn', true))->toBeNull();
});

it('rejects duplicate analysis reference fields before keyed turn replay', function (): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn] = analysis_bound_turn($group, $analysis);
    $input = ['subtask' => $analysis->input['subtask'], 'role' => 'implementer', 'thread' => 'new', 'prompt' => 'Implement the plan.',
        'analysis' => ['operation' => $analysis->id, 'plan_sha256' => $analysis->input['plan_sha256']]];
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => 'analysis-bound-turn'];
    $json = json_encode($input, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/turn', [], [], [], $headers, $json)->assertAccepted()->assertJsonPath('data.id', $turn->id);
    $duplicate = str_replace('"operation":'.$analysis->id, '"operation":0,"operation":'.$analysis->id, $json);
    $this->call('POST', '/api/v1/task-groups/'.$group->id.'/turn', [], [], [], $headers, $duplicate)->assertConflict()->assertJsonPath('error.code', 'operation.idempotency_conflict');
    expect(Operation::query()->where('kind', 'tasks.turn')->count())->toBe(1);
});

it('revalidates a held preparation and saved prompt when no first send was dispatched', function (): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $driver->beforeTurn = fn () => throw new TaskWorkspaceWaiting('Injected preparation loss before the first send.');
    app(OperationRunner::class)->run($turn->id);
    $turn->refresh();
    $thread = AgentThread::query()->sole();
    expect($turn->status)->toBe(OperationStatus::Waiting)->and($turn->state)->toHaveKey('analysis_binding')
        ->and(app(TaskWorkspaceLease::class)->pendingSend($thread, 'analysis-bound-turn', true))->toBeNull()
        ->and(TaskWorkspaceLeaseRecord::query()->where('status', 'held')->sole()->dispatch_started_at)->toBeNull()
        ->and($driver->calls)->toBe([]);
    app(TaskAnalysisRunner::class)->tree = str_repeat('e', 40);
    $driver->beforeTurn = null;
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($turn->id);
    expect($turn->fresh()->status)->toBe(OperationStatus::Failed)->and($turn->fresh()->error_code)->toBe('tasks.analysis_stale')
        ->and($driver->calls)->toBe([]);
});

it('binds reviewer evidence to the existing eligible handoff tree rather than replacing its check', function (bool $matches): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    $task = $group->tasks()->firstOrFail();
    $check = $task->checks()->create(['kind' => 'handoff', 'status' => 'passed', 'commit_sha' => str_repeat('a', 40),
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat($matches ? 'b' : 'c', 40),
        'pid' => 10, 'process_started' => 'start', 'started_at' => now()]);
    task_checked_operation($check);
    [$turn, $driver] = analysis_bound_turn($group, $analysis, overrides: ['role' => 'reviewer', 'check' => $check->id]);
    app(OperationRunner::class)->run($turn->id);
    $turn->refresh();
    expect($turn->error_code)->toBe($matches ? null : 'tasks.analysis_stale')
        ->and(array_filter($driver->calls, fn (array $call): bool => $call['operation'] === 'send'))->toHaveCount($matches ? 1 : 0)
        ->and($check->fresh()->status->value)->toBe('passed')->and($turn->input['check'])->toBe($check->id);
})->with([false, true]);

it('refuses stale referenced evidence before sending or creating any agent', function (string $identity): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis);
    $runner = app(TaskAnalysisRunner::class);
    match ($identity) {
        'head' => $runner->head = str_repeat('e', 40),
        'whole tree' => $runner->tree = str_repeat('e', 40),
        'entrypoint' => $runner->entrypoint = str_repeat('e', 64),
        'repository' => $group->project->update(['repository_url' => 'https://example.test/different.git']),
        'branch' => $group->taskable->update(['branch' => 'other']),
    };
    app(OperationRunner::class)->run($turn->id);
    $turn->refresh();
    expect($turn->status)->toBe(OperationStatus::Failed)->and($turn->error_code)->toBe('tasks.analysis_stale')->and($driver->calls)->toBe([]);
})->with(['head', 'whole tree', 'entrypoint', 'repository', 'branch']);

it('refuses an oversized combined analysis prompt rather than dropping evidence or opening an agent', function (): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    [$turn, $driver] = analysis_bound_turn($group, $analysis, str_repeat('x', 100000));
    app(OperationRunner::class)->run($turn->id);
    $turn->refresh();
    expect($turn->status)->toBe(OperationStatus::Failed)->and($turn->error_code)->toBe('tasks.analysis_prompt_too_large')->and($driver->calls)->toBe([]);
});

it('refuses unusable analysis references at admission', function (string $reason): void {
    $group = analysis_operation_group();
    $analysis = analysis_operation_start($group);
    analysis_operation_finish($analysis);
    $result = $analysis->result;
    if ($reason === 'blocked') {
        $result['outcome'] = 'blocked';
    } elseif ($reason === 'integrity') {
        $result['artifact']['content'] .= ' ';
    }
    $analysis->update(['result' => $result, ...match ($reason) {
        'not terminal' => ['status' => 'running'],
        'other group' => ['target_id' => $group->id + 100],
        default => [],
    }]);
    $this->postJson('/api/v1/task-groups/'.$group->id.'/turn', [
        'subtask' => $analysis->input['subtask'], 'role' => 'implementer', 'thread' => 'new', 'prompt' => 'Use evidence.',
        'analysis' => ['operation' => $analysis->id, 'plan_sha256' => $analysis->input['plan_sha256']],
    ])->assertUnprocessable()->assertJsonPath('error.code', 'tasks.analysis_not_usable');
    expect(Operation::query()->where('kind', 'tasks.turn')->count())->toBe(0)->and(AgentThread::query()->count())->toBe(0);
})->with(['blocked', 'integrity', 'not terminal', 'other group']);

function analysis_resume_before_terminal_checkpoint(Operation $operation): void
{
    $state = $operation->state;
    unset($state['steps']['analysis'], $state['steps']['report']);
    $operation->update(['status' => 'waiting', 'state' => $state, 'current_step' => 'analysis', 'result' => null, 'error_code' => null, 'finished_at' => null, 'worker' => null, 'next_read_at' => now(), 'lock_key' => 'task_group:'.$operation->target_id]);
    $operation->steps()->where('name', 'analysis')->update(['status' => 'waiting', 'error_code' => null, 'finished_at' => null]);
    $operation->steps()->where('name', 'report')->update(['status' => 'pending', 'error_code' => null, 'finished_at' => null]);
}

it('adopts the cached terminal outcome after release without rereading or rerunning evidence', function (bool $invalid): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    if ($invalid) {
        $runner->bytes = '{"malformed":';
    }
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    $status = $operation->status;
    $result = $operation->result;
    $error = $operation->error_code;
    expect($operation->state)->toHaveKey('analysis_terminal')->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
    analysis_resume_before_terminal_checkpoint($operation);
    $recovers = $runner->recovers;
    $runner->uncertain = true;
    $runner->bytes = 'different unavailable report';
    app(OperationRunner::class)->read($operation->id);
    $operation->refresh();
    expect($operation->status)->toBe($status)->and($operation->result)->toBe($result)->and($operation->error_code)->toBe($error)
        ->and($runner->starts)->toBe(1)->and($runner->recovers)->toBe($recovers)
        ->and(json_encode($operation->state, JSON_THROW_ON_ERROR))->not->toContain('{"malformed":');
})->with([false, true]);

it('tombstones an orphan reservation and caches its refusal before release even without an execution checkpoint', function (): void {
    $group = analysis_operation_group();
    $operation = analysis_operation_start($group);
    $leases = app(TaskWorkspaceLease::class);
    $id = str_repeat('f', 32);
    $leases->run($group, 'analysis', 'operation:'.$operation->id, function () use ($leases, $id): void {
        $leases->currentScope()->update(['pid' => 0, 'process_started' => '', 'check_run_id' => $id]);
    }, taskId: $operation->input['subtask']);
    expect($operation->fresh()->state ?? [])->not->toHaveKey('execution');
    app(OperationRunner::class)->run($operation->id);
    $operation->refresh();
    $runner = app(TaskAnalysisRunner::class);
    expect($operation->status)->toBe(OperationStatus::Failed)->and($operation->error_code)->toBe('tasks.analysis_failed')
        ->and($runner->starts)->toBe(0)->and($runner->tombstones)->toHaveKey($id)
        ->and($operation->state['analysis_terminal']['lease_id'])->toBe(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->id);
    analysis_resume_before_terminal_checkpoint($operation);
    $recovers = $runner->recovers;
    app(OperationRunner::class)->read($operation->id);
    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)->and($runner->starts)->toBe(0)->and($runner->recovers)->toBe($recovers);
});

it('recovers the cached safe terminal outcome when release fails before its checkpoint', function (bool $invalid): void {
    $group = analysis_operation_group();
    $runner = app(TaskAnalysisRunner::class);
    if ($invalid) {
        $runner->completion['head_after'] = 'invalid-completion-identity';
    }
    $operation = analysis_operation_start($group);
    $blockRelease = true;
    TaskWorkspaceLeaseRecord::updating(function (TaskWorkspaceLeaseRecord $lease) use ($operation, &$blockRelease): void {
        if ($blockRelease && $lease->kind === 'analysis' && $lease->status === 'released') {
            expect($operation->fresh()->state)->toHaveKey('analysis_terminal');
            throw new TaskWorkspaceWaiting('Injected failure before release checkpoint.');
        }
    });
    analysis_operation_finish($operation);
    expect($operation->status)->toBe(OperationStatus::Waiting)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('held');
    $terminal = $operation->state['analysis_terminal'];
    expect($terminal['after']['head'])->not->toBe('invalid-completion-identity');
    $recovers = $runner->recovers;
    $runner->uncertain = true;
    $runner->bytes = 'not available after terminal persistence';
    $blockRelease = false;
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    $operation->refresh();
    expect($operation->status)->toBe($invalid ? OperationStatus::Failed : OperationStatus::Succeeded)
        ->and($operation->error_code)->toBe($invalid ? 'tasks.analysis_report_invalid' : null)
        ->and($runner->starts)->toBe(1)->and($runner->recovers)->toBe($recovers)
        ->and($operation->state['analysis_terminal'])->toBe($terminal)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
})->with([false, true]);

it('refuses an absent initial base and never substitutes the first analysis base', function (bool $missing): void {
    $group = analysis_operation_group();
    $provision = Operation::query()->where('kind', 'tasks.provision')->sole();
    $state = $provision->state;
    if ($missing) {
        unset($state['analysis_base']);
    } else {
        $state['analysis_base']['base'] = str_repeat('e', 40);
    }
    $provision->update(['state' => $state]);
    $operation = analysis_operation_start($group);
    analysis_operation_finish($operation);
    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe($missing ? 'tasks.analysis_unavailable' : 'tasks.analysis_stale')
        ->and(app(TaskAnalysisRunner::class)->starts)->toBe(0)->and(app(TaskAnalysisRunner::class)->baseReads)->toBe(0)
        ->and($provision->fresh()->state)->toBe($state);
})->with([false, true]);

it('cancels by the held reservation when the execution checkpoint is absent', function (): void {
    $group = analysis_operation_group();
    $operation = analysis_operation_start($group);
    $runner = app(TaskAnalysisRunner::class);
    $runner->terminal = false;
    app(OperationRunner::class)->run($operation->id);
    $operation->refresh();
    $state = $operation->state;
    unset($state['execution']);
    $operation->update(['state' => $state]);
    app(CancelOperationAction::class)->execute($operation->refresh());
    $this->travel(10)->seconds();
    app(OperationRunner::class)->read($operation->id);
    expect($runner->cancels)->toBe(1)->and($runner->starts)->toBe(1)->and($operation->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(TaskWorkspaceLeaseRecord::query()->where('kind', 'analysis')->sole()->status)->toBe('released');
});
