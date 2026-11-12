<?php

declare(strict_types=1);

use App\Actions\Operations\FailNativeOperationAction;
use App\Actions\Operations\ReadOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Orbit\Extensions\AiRouter\Domain\AiRouterDirectOperations;
use Orbit\Extensions\Conn\Domain\ConnDirectOperations;
use Orbit\Extensions\Vault\Domain\VaultDirectOperations;

uses(LazilyRefreshDatabase::class);

/** Observe the shared reader's complete input, without replacing its result selection or persistence. */
final class DeclaredNativeResultProjection implements DirectOperationProjection
{
    public array $calls = [];

    public ?Closure $duringProjection = null;

    public function terminal(Operation $operation, Operation $before): void {}

    public function input(Operation $operation): array
    {
        return [];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $this->calls[] = $data;
        $callback = $this->duringProjection;
        $this->duringProjection = null;
        $callback?->__invoke();

        return app(DirectOperation::class)->project($operation, $data, $data['result']);
    }
}

/** The same immutable native history answers the poll probe and the full terminal reader. */
final class DeclaredNativeResultSocket implements WebSocketClient
{
    public array $runs = [];

    public array $artifacts = [];

    public array $sent = [];

    private array $pending = [];

    private bool $open = false;

    public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
    {
        $this->open = true;
    }

    public function send(array $message): void
    {
        $this->sent[] = $message;
        $this->pending[] = $message;
    }

    public function receive(float $timeoutSeconds): array
    {
        $replies = [];
        foreach ($this->pending as $frame) {
            $payload = match ($frame['type']) {
                'workflow.history.get' => ['data' => $this->runs[$frame['payload']['workflowIdOrName']]],
                'data.get' => ['data' => $this->artifacts[$frame['payload']['modelIdOrName'].':'.$frame['payload']['dataName'].':'.$frame['payload']['version']] ?? []],
                'workflow.run.search' => ['data' => ['results' => []]],
                default => throw new LogicException('Unexpected native result request: '.$frame['type']),
            };
            $replies[] = ['id' => $frame['id'], 'type' => $frame['type'], 'payload' => $payload];
        }
        $this->pending = [];

        return $replies;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isConnected(): bool
    {
        return $this->open;
    }
}

function declaredNativeResultRegistry(): DirectOperation
{
    return new DirectOperation([new AiRouterDirectOperations, new ConnDirectOperations, new VaultDirectOperations]);
}

function declaredNativeResultOperation(string $kind, Node $node, int $targetId = 0, array $input = []): Operation
{
    return Operation::query()->create([
        'kind' => $kind, 'target_type' => $targetId === 0 ? 'node' : 'instance', 'target_id' => $targetId === 0 ? $node->id : $targetId,
        'node_id' => $node->id, 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1,
        'started_at' => now()->subSecond(), 'input' => $input, 'state' => ['swamp_run_id' => '10518fad-cd53-45df-b072-72d0e6c72179'],
    ]);
}

function declaredNativeResultRun(Operation $operation, string $status = 'failed', ?array $public = null): array
{
    $definition = app(DirectOperation::class)->definition($operation->kind);
    $selector = $definition['terminal_result'] ?? ['job' => 'fleet', 'step' => 'not-declared'];
    $jobs = [];
    $put = static function (string $job, string $name, array $outputs) use (&$jobs): void {
        $outputs = [...($jobs[$job]['steps'][$name]['outputs'] ?? []), ...$outputs];
        $jobs[$job]['name'] = $job;
        $jobs[$job]['steps'][$name] = ['name' => $name, 'status' => 'succeeded',
            'startedAt' => now()->subSecond()->toISOString(), 'completedAt' => now()->toISOString(), 'outputs' => $outputs];
    };
    $public ??= ['error_code' => 'fixture.refused', 'message' => 'The declared refusal stays intact.', 'kind' => $operation->kind];
    $put($selector['job'], $selector['step'], ['stdout' => json_encode($public)]);
    foreach ($selector['captures'] ?? [] as $capture) {
        $field = $capture['field'] ?? 'stdout';
        $put($capture['job'], $capture['step'], [$field => json_encode($public)]);
    }
    if ($status === 'failed' || $status === 'interrupted') {
        $jobs['failure'] = ['name' => 'failure', 'steps' => [['name' => 'failed-phase', 'status' => 'failed',
            'completedAt' => now()->toISOString(), 'error' => 'fixture.refused',
            'outputs' => ['stderr' => 'The native phase refused the operation.', 'exitCode' => 1]]]];
    }
    foreach ($jobs as &$job) {
        $job['steps'] = array_values($job['steps']);
    }
    unset($job);

    return ['id' => $operation->state['swamp_run_id'], 'workflowName' => $definition['workflow'], 'status' => $status,
        'startedAt' => now()->subSecond()->toISOString(), 'completedAt' => now()->toISOString(),
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'jobs' => array_values($jobs)];
}

beforeEach(function (): void {
    $this->freezeTime();
    Queue::fake();
    Http::preventStrayRequests();
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.read_token' => 'fixture-read-token']);
    RateLimiter::clear('swamp-read');
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'result-node', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.77',
        'public_ssh_host' => '10.44.0.77', 'user' => 'orbit',
    ]));
    $this->socket = new DeclaredNativeResultSocket;
    app()->instance(WebSocketClient::class, $this->socket);
});

afterEach(fn () => app(SwampConnection::class)->release());

$declaredNativeResultKinds = array_keys(declaredNativeResultRegistry()->definitions());
sort($declaredNativeResultKinds);

it('passes the same complete result to the family and renders it for a poll over the whole registry', function (string $kind) use ($declaredNativeResultKinds): void {
    $direct = app(DirectOperation::class);
    $registeredKinds = array_keys($direct->definitions());
    sort($registeredKinds);
    expect($registeredKinds)->toBe($declaredNativeResultKinds);
    $definition = $direct->definition($kind);
    $terminal = declaredNativeResultOperation($kind, $this->node);
    $this->socket->runs[$terminal->state['swamp_run_id']] = declaredNativeResultRun($terminal);
    if (isset($definition['terminal_result'])) {
        $probe = new DeclaredNativeResultProjection;
        app()->instance($definition['projection'], $probe);
        app(SwampCommandStep::class)->projectTerminal($terminal, ['id' => $terminal->state['swamp_run_id']], $definition['terminal_result']);
        $terminalData = $probe->calls[0];
    } else {
        app(FailNativeOperationAction::class)->execute($terminal, $terminal->state['swamp_run_id']);
    }
    $expected = OperationData::summary($terminal->refresh(), null)->toArray();

    $polled = declaredNativeResultOperation($kind, $this->node);
    $this->socket->runs[$polled->state['swamp_run_id']] = declaredNativeResultRun($polled);
    app(ReadOperationAction::class)->execute($polled);
    $rendered = OperationData::summary($polled->refresh(), null)->toArray();

    expect($rendered['status'])->toBe($expected['status'])
        ->and($rendered['result'])->toBe($expected['result'])
        ->and($rendered['error_code'])->toBe($expected['error_code']);
    if (isset($definition['terminal_result'])) {
        if (isset($terminalData['native_run'])) {
            $terminalData['native_run']['inputs']['operation_id'] = $polled->id;
        }
        expect($probe->calls)->toHaveCount(2)->and($probe->calls[1])->toBe($terminalData);
    } else {
        expect($rendered['result'])->toBe(['error_code' => 'operation.native_failed'])
            ->and($rendered['error_code'])->toBe('operation.native_failed')
            ->and($direct->usesNativeTerminal($polled))->toBeFalse();
    }
})->with(array_combine($declaredNativeResultKinds, $declaredNativeResultKinds));

it('leaves a running or succeeded native run open until its existing terminal handler closes it', function (string $status): void {
    $operation = declaredNativeResultOperation('instance.mail_update', $this->node);
    $definition = app(DirectOperation::class)->definition($operation->kind);
    $probe = new DeclaredNativeResultProjection;
    app()->instance($definition['projection'], $probe);
    $this->socket->runs[$operation->state['swamp_run_id']] = declaredNativeResultRun($operation, $status);
    $before = $operation->refresh()->getAttributes();

    app(ReadOperationAction::class)->execute($operation);

    expect($operation->refresh()->getAttributes())->toBe($before)
        ->and($probe->calls)->toBe([])
        ->and(array_column($this->socket->sent, 'type'))->toBe(['workflow.history.get']);
})->with(['running', 'succeeded']);

it('does not read again or backfill an already closed Operation', function (string $status, ?string $code, array $result): void {
    $operation = declaredNativeResultOperation('instance.mail_update', $this->node);
    $operation->update(['status' => $status, 'error_code' => $code, 'result' => $result, 'finished_at' => now()]);
    $before = $operation->refresh()->getAttributes();

    app(ReadOperationAction::class)->execute($operation);
    app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $operation->state['swamp_run_id']],
        app(DirectOperation::class)->definition($operation->kind)['terminal_result']);

    expect($operation->refresh()->getAttributes())->toBe($before)->and($this->socket->sent)->toBe([]);
})->with([
    'generic failure' => ['failed', 'operation.native_failed', ['error_code' => 'operation.native_failed']],
    'success' => ['succeeded', null, ['ready' => true]],
]);

it('closes once when a second poll overlaps projection and later polls read the saved result', function (bool $bothAdmitted): void {
    $operation = declaredNativeResultOperation('instance.mail_update', $this->node);
    $definition = app(DirectOperation::class)->definition($operation->kind);
    $probe = new DeclaredNativeResultProjection;
    app()->instance($definition['projection'], $probe);
    $this->socket->runs[$operation->state['swamp_run_id']] = declaredNativeResultRun($operation);
    $probe->duringProjection = function () use ($operation, $bothAdmitted): void {
        if ($bothAdmitted) {
            // Model two requests that both passed the read budget before either hit it.
            RateLimiter::clear('swamp-read:operation:'.$operation->id);
        }
        expect(app(ReadOperationAction::class)->execute($operation->fresh())->status)
            ->toBe($bothAdmitted ? OperationStatus::Failed : OperationStatus::Running);
    };

    app(ReadOperationAction::class)->execute($operation);
    $requests = count($this->socket->sent);
    $sequence = $operation->refresh()->sequence;
    app(ReadOperationAction::class)->execute($operation->fresh());

    expect($probe->calls)->toHaveCount($bothAdmitted ? 2 : 1)
        ->and($sequence)->toBe(2)
        ->and($operation->refresh()->sequence)->toBe($sequence)
        ->and($operation->status)->toBe(OperationStatus::Failed)
        ->and(count($this->socket->sent))->toBe($requests)
        ->and(array_count_values(array_column($this->socket->sent, 'type')))->toBe([
            'workflow.history.get' => $bothAdmitted ? 4 : 2,
            'workflow.run.search' => $bothAdmitted ? 2 : 1,
        ]);
})->with(['second poll budgeted' => false, 'both polls already admitted' => true]);

$declaredInstanceResultArtifacts = [
    'instance.create' => ['ic-finish', 'create'],
    'instance.clone' => ['icl-36', 'result'],
    'instance.migrate' => ['im-finish', 'migrate'],
    'instance.mail_update' => ['instance-mail-result', 'output'],
    'instance.node_service_detach' => ['instance-node-service-result', 'output'],
];

it('retries a succeeded Instance result step with absent or empty stdout and reads its declared artifact', function (string $kind, string $behavior, string $artifact, ?string $inline): void {
    $operation = declaredNativeResultOperation($kind, $this->node);
    $definition = app(DirectOperation::class)->definition($kind);
    $probe = new DeclaredNativeResultProjection;
    app()->instance($definition['projection'], $probe);
    $run = declaredNativeResultRun($operation);
    foreach ($run['jobs'] as &$job) {
        if ($job['name'] !== $definition['terminal_result']['job']) {
            continue;
        }
        foreach ($job['steps'] as &$step) {
            if ($step['name'] === $definition['terminal_result']['step']) {
                $step['outputs'] = $inline === null ? [] : ['stdout' => $inline];
                $step['dataArtifacts'] = [['dataId' => '8b526205-12b0-4fb5-95aa-beaa11894418', 'name' => $artifact, 'version' => 7]];
            }
        }
        unset($step);
    }
    unset($job);
    $this->socket->runs[$operation->state['swamp_run_id']] = $run;

    app(ReadOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Running)
        ->and($operation->result)->toBeNull()->and($probe->calls)->toBe([]);
    $public = ['error_code' => 'fixture.refused', 'message' => 'The declared refusal stays intact.', 'kind' => $kind];
    $this->socket->artifacts[$behavior.':'.$artifact.':7'] = ['content' => json_encode(['stdout' => json_encode($public)])];
    RateLimiter::clear('swamp-read:operation:'.$operation->id);
    app(ReadOperationAction::class)->execute($operation);

    expect($operation->refresh()->result)->toBe($public)
        ->and($probe->calls)->toHaveCount(1);
    $requests = count($this->socket->sent);
    app(ReadOperationAction::class)->execute($operation->fresh());
    expect(count($this->socket->sent))->toBe($requests);
})->with(function () use ($declaredInstanceResultArtifacts): array {
    $cases = [];
    foreach ($declaredInstanceResultArtifacts as $kind => [$behavior, $artifact]) {
        foreach (['absent' => null, 'empty' => '', 'whitespace' => " \n"] as $name => $inline) {
            $cases[$kind.' '.$name] = [$kind, $behavior, $artifact, $inline];
        }
    }

    return $cases;
});

it('requires a deliberate decision if a third kind omits its terminal result', function (): void {
    $kinds = [];
    foreach (app(DirectOperation::class)->definitions() as $kind => $definition) {
        if (! isset($definition['terminal_result'])) {
            $kinds[] = $kind;
        }
    }
    sort($kinds);

    expect($kinds)->toBe(['fleet.cli_converge', 'fleet.rollout']);
});

function declaredNativeResultInstance(Node $node): Instance
{
    $project = Project::query()->create(['name' => 'Result project', 'slug' => 'result-project', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/result.git', 'default_branch' => 'main', 'root' => '.']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'feature',
        'checkout_path' => '/tmp/result-workspace', 'source_prepare_id' => '46363057-0333-405f-87fc-ed53ab30f4ec',
        'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
}

it('keeps the failed create receipt and source-resolved Instance state whichever reader wins', function (): void {
    $instance = declaredNativeResultInstance($this->node);
    $input = ['instance_id' => $instance->id, 'project_id' => $instance->project_id, 'node_id' => $this->node->id,
        'name' => $instance->name, 'checkout' => $instance->checkout_path, 'prepare_id' => $instance->source_prepare_id,
        'members' => [], 'direct_provisioning' => ['container' => ['clone' => [], 'processes' => []], 'resolve' => ['branch' => 'feature']]];
    $public = ['instance_id' => $instance->id, 'ready' => false, 'commit' => str_repeat('a', 40),
        'failed_step' => 'register', 'error_code' => 'instance.container_process_inactive',
        'process_failure' => ['unit' => 'fixture.service', 'ActiveState' => 'failed', 'SubState' => 'failed',
            'Result' => 'exit-code', 'ExecMainStatus' => 1, 'NRestarts' => 3]];
    $selector = app(DirectOperation::class)->definition('instance.create')['terminal_result'];
    $terminal = declaredNativeResultOperation('instance.create', $this->node, $instance->id, $input);
    $this->socket->runs[$terminal->state['swamp_run_id']] = declaredNativeResultRun($terminal, public: $public);
    app(SwampCommandStep::class)->projectTerminal($terminal, ['id' => $terminal->state['swamp_run_id']], $selector);
    expect($instance->refresh()->status)->toBe(InstanceState::SourceResolved);
    $expected = $terminal->refresh()->result;
    $instance->update(['status' => InstanceState::Reserved, 'starting_commit' => null, 'branch' => null, 'failed_step' => null, 'error_code' => null]);
    $polled = declaredNativeResultOperation('instance.create', $this->node, $instance->id, $input);
    $this->socket->runs[$polled->state['swamp_run_id']] = declaredNativeResultRun($polled, public: $public);

    app(ReadOperationAction::class)->execute($polled);

    expect($polled->refresh()->error_code)->toBe('instance.container_process_inactive')
        ->and($polled->result)->toBe($expected)->toMatchArray($public)
        ->and($instance->refresh()->status)->toBe(InstanceState::SourceResolved)
        ->and($instance->starting_commit)->toBe(str_repeat('a', 40));
    $before = $polled->getAttributes();
    $requests = count($this->socket->sent);
    app(SwampCommandStep::class)->projectTerminal($polled, ['id' => $polled->state['swamp_run_id']], $selector);
    expect($polled->refresh()->getAttributes())->toBe($before)
        ->and(count($this->socket->sent))->toBe($requests);
});

it('keeps a failed migrate refusal code and message when a poll wins', function (): void {
    $instance = declaredNativeResultInstance($this->node);
    $operation = declaredNativeResultOperation('instance.migrate', $this->node, $instance->id,
        ['instance_id' => $instance->id, 'container' => [], 'migrate' => ['schedules' => []]]);
    $public = ['instance_id' => $instance->id, 'ready' => false, 'moved' => false, 'restored' => true,
        'preflight_refused' => true, 'failed_step' => 'quiesce', 'error_code' => 'instance.migrate_stashes_present',
        'error_message' => 'Cannot retire checkout "/fixture". Stashes: 2.'];
    $this->socket->runs[$operation->state['swamp_run_id']] = declaredNativeResultRun($operation, public: $public);

    app(ReadOperationAction::class)->execute($operation);

    expect($operation->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('instance.migrate_stashes_present')
        ->and($operation->result)->toBe($public)
        ->and($instance->refresh()->status)->toBe(InstanceState::Reserved);
});

it('refuses an invalid terminal artifact declaration', function (array $selector): void {
    $contribution = new class($selector) implements DirectOperationContribution
    {
        public function __construct(private readonly array $selector) {}

        public function definitions(): array
        {
            return ['fixture.result' => ['workflow' => 'fixture-result', 'projection' => DeclaredNativeResultProjection::class,
                'terminal_result' => $this->selector]];
        }
    };

    expect(fn () => new DirectOperation([$contribution]))->toThrow(LogicException::class);
})->with([
    'no data behavior' => [['job' => 'result', 'step' => 'result', 'artifact' => 'create']],
    'invalid name' => [['job' => 'result', 'step' => 'result', 'data' => 'fixture', 'artifact' => '../create']],
    'wrong type' => [['job' => 'result', 'step' => 'result', 'data' => 'fixture', 'artifact' => []]],
]);
