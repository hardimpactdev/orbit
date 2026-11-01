<?php

declare(strict_types=1);

use App\Data\Operations\OperationData;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationResultHook;
use App\Domain\Operations\DirectOperationResultProjection;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'command-target', 'user' => 'prior', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.209']);
    $this->runId = '2a9c5e84-746a-487b-8621-4f6e1bb7d356';
    $this->operation = Operation::query()->create([
        'kind' => 'node.bootstrap_probe', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'input' => [], 'state' => ['swamp_run_id' => $this->runId], 'status' => OperationStatus::Running,
        'started_at' => '2026-10-31T12:00:00Z', 'sequence' => 1,
    ]);
    $this->history = ['id' => $this->runId, 'workflowName' => 'operation-node-bootstrap-probe', 'status' => 'succeeded',
        'inputs' => ['operation_id' => $this->operation->id, 'node_id' => $this->node->id, 'kind' => 'node.bootstrap_probe'],
        'startedAt' => '2026-10-31T12:00:00Z', 'completedAt' => '2026-10-31T12:00:01Z',
        'jobs' => [['name' => 'bootstrap', 'steps' => [
            ['name' => 'scan-host-key', 'outputs' => ['stdout' => '{"architecture":"discard"}']],
            ['name' => 'probe-host', 'outputs' => ['exitCode' => 0, 'stdout' => '{"probed":true,"architecture":"x86_64","public_key":"public-fixture"}']],
        ]]],
    ];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $this->history]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'synthetic-gateway-token', 'swamp.read_token' => 'synthetic-native-read-token', 'swamp.ca_path' => '/public/ca.pem']);
    $this->step = app(SwampCommandStep::class);
    $this->selector = ['job' => 'bootstrap', 'step' => 'probe-host'];
});

it('reads history once after a native terminal event and projects only the fixed step stdout', function (): void {
    $terminal = ['id' => $this->runId, 'status' => 'succeeded'];
    $result = $this->step->projectTerminal($this->operation, $terminal, $this->selector);

    expect($result->status)->toBe(OperationStatus::Succeeded);
    expect($result->result)->toBe(['probed' => true, 'architecture' => 'x86_64', 'public_key' => 'public-fixture']);
    expect($this->node->fresh()->architecture)->toBe('x86_64')->and($this->node->fresh()->user)->toBe('prior');
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0])->toMatchArray(['type' => 'workflow.history.get', 'payload' => ['workflowIdOrName' => $this->runId]]);
    expect($this->socket->closed)->toBeTrue();
    $this->step->projectTerminal($result, $terminal, $this->selector);
    expect($this->socket->sent)->toHaveCount(1);
});

it('projects terminal history with the read credential when the run credential is absent', function (): void {
    config(['swamp.run_token' => null]);

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $this->selector);

    expect($result->status)->toBe(OperationStatus::Succeeded);
    expect($this->socket->endpoint?->bearerToken)->toBe('synthetic-native-read-token');
    expect($this->socket->sent)->toHaveCount(1);
});

it('leaves the accepted run bound when the read credential is missing', function (): void {
    config(['swamp.read_token' => '']);

    expect(fn () => $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $this->selector))
        ->toThrow(ResourceOperationException::class);

    expect($this->socket->connections)->toBeEmpty();
    expect($this->socket->sent)->toBeEmpty();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

it('projects native failure cancellation and interruption when the selected step was skipped', function (string $native, OperationStatus $expected, ?string $code, array $public): void {
    $this->history['status'] = $native;
    $this->history['jobs'][0]['steps'][1] = ['name' => 'probe-host', 'status' => 'skipped'];
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => $native], $this->selector);

    expect($result->status)->toBe($expected)->and($result->error_code)->toBe($code)->and($result->result)->toBe($public);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with([
    ['failed', OperationStatus::Failed, 'operation.native_failed', ['error_code' => 'operation.native_failed']],
    ['cancelled', OperationStatus::Cancelled, null, []],
    ['interrupted', OperationStatus::Interrupted, 'operation.native_failed', ['error_code' => 'operation.native_failed']],
]);

it('settles an empty failed native result through the generic projector without losing its failure code', function (string $status): void {
    $hook = new class implements DirectOperationResultHook
    {
        public array $completions = [];

        public function input(Operation $operation): array
        {
            return [];
        }

        public function validate(Operation $operation, array $result, OperationStatus $status): array
        {
            return array_intersect_key($result, array_flip(['error_code']));
        }

        public function complete(Operation $operation, OperationOutcome $outcome): void
        {
            $this->completions[] = $outcome;
        }
    };
    app()->instance($hook::class, $hook);
    app()->instance(DirectOperation::class, new DirectOperation([new class($hook::class) implements DirectOperationContribution
    {
        public function __construct(private readonly string $hook) {}

        public function definitions(): array
        {
            return ['test.native_failure' => ['workflow' => 'operation-test-native-failure',
                'projection' => DirectOperationResultProjection::class, 'result_hook' => $this->hook,
                'terminal_result' => ['job' => 'bootstrap', 'step' => 'probe-host']]];
        }
    }]));
    $this->operation->update(['kind' => 'test.native_failure', 'lock_key' => 'test-native-failure-'.$this->operation->id]);
    $this->history['workflowName'] = 'operation-test-native-failure';
    $this->history['inputs']['kind'] = 'test.native_failure';
    $this->history['status'] = $status;
    $this->history['jobs'][0]['steps'][1] = ['name' => 'probe-host', 'status' => 'failed'];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => $status], $this->selector);

    expect($result->status)->toBe(OperationStatus::from($status));
    expect($hook->completions[0]->status)->toBe(OperationStatus::from($status));
    expect($result->error_code)->toBe('operation.native_failed');
    expect($result->result)->toBe(['error_code' => 'operation.native_failed']);
    expect($result->lock_key)->toBeNull();
    expect($result->state['swamp_run_id'])->toBe($this->runId);
    expect($hook->completions)->toHaveCount(1);
    expect($hook->completions[0]->errorCode)->toBe('operation.native_failed');
    $this->step->projectTerminal($result, ['id' => $this->runId, 'status' => $status], $this->selector);
    expect($hook->completions)->toHaveCount(1);
    expect(array_column($this->socket->sent, 'type'))->toBe(['workflow.history.get']);
})->with(['failed', 'interrupted']);

it('preserves a failed script public error from its stdout', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = '{"error_code":"node.platform_mismatch"}';
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);
    expect($result->status)->toBe(OperationStatus::Failed)->and($result->error_code)->toBe('node.platform_mismatch');
});

it('refuses a nonterminal or foreign native event without a history request', function (array $terminal): void {
    expect(fn () => $this->step->projectTerminal($this->operation, $terminal, $this->selector))->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toBeEmpty();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
})->with([
    [['id' => 'foreign-run', 'status' => 'succeeded']],
    [['id' => '2a9c5e84-746a-487b-8621-4f6e1bb7d356', 'status' => 'running']],
]);

it('refuses mismatched history or unavailable public JSON without projecting', function (string $change): void {
    match ($change) {
        'run' => $this->history['id'] = 'foreign-run',
        'workflow' => $this->history['workflowName'] = 'unrelated',
        'owner' => $this->history['inputs']['node_id'] = $this->node->id + 1,
        'status' => $this->history['status'] = 'running',
        'missing' => $this->history['jobs'][0]['steps'][1]['outputs'] = [],
        'array' => $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = '[]',
        'json' => $this->history['jobs'][0]['steps'][1]['outputs']['stdout'] = 'synthetic-private-diagnostic',
    };
    try {
        $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'succeeded'], $this->selector);
        test()->fail('An unavailable native result must not project.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.terminal_result_unavailable');
        expect((string) $error)->not->toContain('synthetic-private-diagnostic');
        expect($error->getPrevious())->toBeNull();
    }
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with(['run', 'workflow', 'owner', 'status', 'missing', 'array', 'json']);

it('recovers a late attachment from one terminal history read without inventing status', function (string $native, OperationStatus $expected): void {
    $this->history['status'] = $native;
    if ($native !== 'succeeded') {
        $this->history['jobs'][0]['steps'][1] = ['name' => 'probe-host', 'status' => 'skipped'];
    }
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId], $this->selector);

    expect($result->status)->toBe($expected);
    expect($this->socket->sent)->toHaveCount(1);
})->with([
    ['succeeded', OperationStatus::Succeeded], ['failed', OperationStatus::Failed],
    ['cancelled', OperationStatus::Cancelled], ['interrupted', OperationStatus::Interrupted],
]);

it('leaves late attachment unchanged when history is active missing forbidden or unreachable', function (string $condition): void {
    if ($condition === 'active') {
        $this->history['status'] = 'running';
    } elseif ($condition === 'transport') {
        $this->socket->reply = static fn (): never => throw new RuntimeException('synthetic-private-diagnostic');
    } else {
        $this->socket->reply = fn (array $request): array => [['type' => 'error', 'id' => $request['id'],
            'code' => $condition === 'missing' ? 'not_found' : 'forbidden', 'message' => 'synthetic-private-diagnostic']];
    }
    expect(fn () => $this->step->projectTerminal($this->operation, ['id' => $this->runId], $this->selector))
        ->toThrow(ResourceOperationException::class);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->node->fresh()->ssh_host_key)->toBeNull();
})->with(['active', 'missing', 'auth', 'transport']);

it('preserves a terminal result recorded while history is in flight', function (): void {
    $this->socket->reply = function (array $request): array {
        Operation::query()->whereKey($this->operation->id)->update([
            'status' => OperationStatus::Cancelled, 'result' => ['cancelled_elsewhere' => true],
        ]);

        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $this->history]]];
    };

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId], $this->selector);

    expect($result->status)->toBe(OperationStatus::Cancelled);
    expect($result->result)->toBe(['cancelled_elsewhere' => true]);
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->node->fresh()->architecture)->toBeNull();
});

it('refuses a binding replaced while history is in flight', function (): void {
    $this->socket->reply = function (array $request): array {
        Operation::query()->whereKey($this->operation->id)->update(['state' => ['swamp_run_id' => 'replacement-run']]);

        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $this->history]]];
    };

    expect(fn () => $this->step->projectTerminal($this->operation, ['id' => $this->runId], $this->selector))
        ->toThrow(ResourceOperationException::class, 'This status belongs to another Swamp run.');
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    expect($this->operation->fresh()->state['swamp_run_id'])->toBe('replacement-run');
    expect($this->node->fresh()->architecture)->toBeNull();
});

it('preserves declared successful public captures when cancellation skips the terminal step', function (): void {
    $hook = new class implements DirectOperationResultHook
    {
        public function input(Operation $operation): array
        {
            return [];
        }

        public function validate(Operation $operation, array $result, OperationStatus $status): array
        {
            return $result;
        }

        public function complete(Operation $operation, OperationOutcome $outcome): void {}
    };
    app()->instance($hook::class, $hook);
    $selector = ['job' => 'bootstrap', 'step' => 'probe-host', 'captures' => [
        'source' => ['job' => 'bootstrap', 'step' => 'captured'],
        'keys' => ['job' => 'bootstrap', 'step' => 'seeded', 'field' => 'keys'],
        'unconfirmed' => ['job' => 'bootstrap', 'step' => 'unconfirmed'],
    ]];
    app()->instance(DirectOperation::class, new DirectOperation([new class($hook::class, $selector) implements DirectOperationContribution
    {
        public function __construct(private readonly string $hook, private readonly array $selector) {}

        public function definitions(): array
        {
            return ['test.native_capture' => ['workflow' => 'operation-test-native-capture', 'projection' => DirectOperationResultProjection::class,
                'result_hook' => $this->hook, 'terminal_result' => $this->selector]];
        }
    }]));
    $this->operation->update(['kind' => 'test.native_capture']);
    $this->history['workflowName'] = 'operation-test-native-capture';
    $this->history['inputs']['kind'] = 'test.native_capture';
    $this->history['status'] = 'cancelled';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'captured', 'status' => 'succeeded', 'outputs' => ['instance_id' => 17]],
        ['name' => 'seeded', 'status' => 'succeeded', 'outputs' => ['keys' => ['APP_KEY'], 'reference' => ['vault' => 'orbit-control-inputs', 'key' => 'named-output']]],
        ['name' => 'unconfirmed', 'status' => 'failed', 'outputs' => ['ready' => true]],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];
    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'cancelled'], $selector);
    expect($result->status)->toBe(OperationStatus::Cancelled)
        ->and($result->result)->toBe(['source' => ['instance_id' => 17], 'keys' => ['APP_KEY']])
        ->and($this->socket->sent)->toHaveCount(1);
});

it('projects the first native failure with its redacted bounded stderr independently of family result fields', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'install-runtime', 'status' => 'failed', 'outputs' => ['stderr' => str_repeat('earlier ', 1000)."\npassword=private-password-value\nFailed to install runtime.\n"], 'error' => 'generic exit 1'],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect($result->failed_step)->toBe('install-runtime');
    $public = OperationData::fromModel($result)->toArray();
    expect($public['error']['detail'])->toEndWith("password=[REDACTED]\nFailed to install runtime.\n");
    expect(strlen($public['error']['detail']))->toBeLessThanOrEqual(4096);
    expect(json_encode($result->state))->not->toContain('private-password-value');
    expect($result->result)->toBe(['error_code' => 'operation.native_failed']);
    expect($this->socket->sent)->toHaveCount(1);
});

it('projects a native validation failure without stderr and ignores later cascade errors', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'prepare-source', 'status' => 'failed', 'error' => 'Permission denied for Authorization: Bearer private-native-token'],
        ['name' => 'probe-host', 'status' => 'failed', 'error' => 'No such key: prepare-source'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect($result->failed_step)->toBe('prepare-source');
    $public = OperationData::fromModel($result)->toArray();
    expect($public['error']['detail'])->toBe('Permission denied for Authorization: [REDACTED]');
    expect(json_encode($result->state))->not->toContain('private-native-token');
});

it('keeps the native error when stderr was recorded but empty', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'validate', 'status' => 'failed', 'outputs' => ['stderr' => ''], 'error' => 'Worker disconnected before output.'],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect(OperationData::fromModel($result)->toArray()['error'])->toBe([
        'code' => 'operation.native_failed', 'detail' => 'Worker disconnected before output.', 'stderr_recorded' => true,
    ]);
});

it('chooses the earliest native failure rather than the workflow declaration order', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'] = [
        ['name' => 'cleanup', 'steps' => [['name' => 'cleanup', 'status' => 'failed', 'completedAt' => '2026-10-31T12:00:00.900Z', 'error' => 'Later cleanup error.']]],
        ['name' => 'bootstrap', 'steps' => [
            ['name' => 'intent', 'status' => 'failed', 'completedAt' => '2026-10-31T12:00:00.100Z', 'error' => 'First admission error.'],
            ['name' => 'probe-host', 'status' => 'skipped'],
        ]],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect($result->failed_step)->toBe('intent');
    expect(OperationData::fromModel($result)->toArray()['error']['detail'])->toBe('First admission error.');
});

it('exposes the 6650 worker disconnect and validate step when no stderr was recorded', function (): void {
    $error = "Worker 'orbit-node-209' disconnected after step 'validate' had written data — failing run (write-then-drop).";
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'validate', 'status' => 'failed', 'outputs' => [], 'error' => $error],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);
    $public = OperationData::fromModel($result)->toArray();

    expect($public['failed_step'])->toBe('validate');
    expect($public['error'])->toBe(['code' => 'operation.native_failed', 'detail' => $error, 'stderr_recorded' => false]);
    expect($public['result'])->toBe(['error_code' => 'operation.native_failed']);
});

it('enriches a live first failure with recorded terminal stderr without replacing its causal step', function (): void {
    $this->operation->update(['state' => ['swamp_run_id' => $this->runId, 'native_failure' => [
        'failed_step' => 'validate', 'detail' => 'Generic native failure.', 'stderr_recorded' => false,
    ]]]);
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'validate', 'status' => 'failed', 'outputs' => ['stderr' => 'Actual safe diagnostic.']],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect($result->failed_step)->toBe('validate');
    expect(OperationData::fromModel($result)->toArray()['error'])->toBe([
        'code' => 'operation.native_failed', 'detail' => 'Actual safe diagnostic.', 'stderr_recorded' => true,
    ]);
});

it('drops a partial first stderr line before projecting the complete diagnostic lines', function (): void {
    $this->history['status'] = 'failed';
    $this->history['jobs'][0]['steps'] = [
        ['name' => 'validate', 'status' => 'failed', 'outputs' => [
            'stderr' => str_repeat('unbounded-prefix-', 400)."private-suffix\nReadable failure.\n", 'stdout' => 'never-expose-stdout']],
        ['name' => 'probe-host', 'status' => 'skipped'],
    ];

    $result = $this->step->projectTerminal($this->operation, ['id' => $this->runId, 'status' => 'failed'], $this->selector);

    expect(OperationData::fromModel($result)->toArray()['error']['detail'])->toBe("Readable failure.\n");
    expect(json_encode($result->state))->not->toContain('private-suffix');
    expect(json_encode($result->state))->not->toContain('never-expose-stdout');
});
