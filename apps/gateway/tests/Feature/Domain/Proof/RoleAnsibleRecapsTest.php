<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    require_once base_path('../../tools/swamp-proof-guest/ansible-recaps.php');
    config(['swamp.read_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->requests = [];
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $request): void {
        $this->requests[] = $request;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function (): array {
        $request = end($this->requests);
        $data = $request['type'] === 'workflow.history.get' ? $this->history : [
            'results' => [],
            'projected' => ['shape' => 'map', 'columns' => ['id', 'name', 'version', 'content'], 'rows' => [$this->artifact]],
            'total' => 1, 'limited' => false,
        ];

        return [['type' => $request['type'], 'id' => $request['id'], 'payload' => ['data' => $data]]];
    });
    app()->instance(WebSocketClient::class, $socket);
});

afterEach(fn () => app(SwampConnection::class)->release());

it('reports the resource error code and message with the failing reader location', function (): void {
    $this->history = ['jobs' => [['steps' => [
        ['name' => 'Check the role', 'status' => 'succeeded', 'dataArtifacts' => [['name' => 'log']]],
    ]]]];

    try {
        roleAnsibleRecaps(app(SwampCommandStep::class), 'role-run', ['Check the role']);
        $this->fail('The missing result must fail the recap read.');
    } catch (ResourceOperationException $error) {
        expect(roleAnsibleRecapFailure($error))
            ->toContain('[swamp.terminal_result_unavailable]: The native terminal result is unavailable.', 'caller SwampCommandStep.php:')
            ->not->toContain('role-run');
    }
});

it('reads the command shell result artifact by identity rather than its text log or history summary', function (): void {
    $artifactId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $recap = "PLAY RECAP *********************************************************************\nn7 : ok=12 changed=0 unreachable=0 failed=0 skipped=4 rescued=0 ignored=0\n";
    $this->history = ['jobs' => [['steps' => [
        ['name' => 'Apply the role on the Node', 'status' => 'succeeded', 'outputs' => ['stdout' => $recap]],
        ['name' => 'Set up Tool Managers', 'status' => 'succeeded', 'outputs' => ['stdout' => 'truncated history'],
            'dataArtifacts' => [
                ['name' => 'result', 'dataId' => $artifactId, 'version' => 3],
                ['name' => 'log', 'dataId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845', 'version' => 3],
            ]],
        ['name' => 'Other phase', 'status' => 'failed', 'outputs' => ['stdout' => 'secret-output']],
    ]]]];
    // command/shell writes JSON resource `result` and text file `log`; data.query parses the JSON content.
    $this->artifact = ['id' => $artifactId, 'name' => 'result', 'version' => 3,
        'content' => ['exitCode' => 0, 'stdout' => str_repeat('private-output', 400)."\n".$recap, 'stderr' => '']];

    $result = roleAnsibleRecaps(app(SwampCommandStep::class), 'role-run', ['Apply the role on the Node', 'Set up Tool Managers']);

    expect(array_keys($result))->toBe(['Apply the role on the Node', 'Set up Tool Managers']);
    foreach ($result as $phase) {
        expect($phase)->toBe(['status' => 'succeeded', 'hosts' => [
            ['host' => 'n7', 'ok' => 12, 'changed' => 0, 'unreachable' => 0, 'failed' => 0],
        ]]);
    }
    expect(array_column($this->requests, 'type'))->toBe(['workflow.history.get', 'data.query'])
        ->and($this->requests[1]['payload']['predicate'])->toBe('id == "'.$artifactId.'" && version == 3')
        ->and(json_encode($result))->not->toContain('private-output', 'secret-output');
});

it('never accepts missing recaps as proof of an unchanged phase', function (): void {
    $this->history = ['jobs' => [['steps' => [
        ['name' => 'Set up Tool Managers', 'status' => 'succeeded', 'outputs' => ['stdout' => 'no recap']],
    ]]]];

    expect(roleAnsibleRecaps(app(SwampCommandStep::class), 'role-run', ['Set up Tool Managers']))
        ->toBe(['Set up Tool Managers' => ['status' => 'succeeded', 'hosts' => []]]);
});

it('reports a safe nonzero error instead of Laravel console output for a failed recap read', function (): void {
    $process = new Process([PHP_BINARY, base_path('../../tools/swamp-proof-guest/ansible-recaps.php')]);
    $process->setInput('{"secret-input":');
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain('JsonException at ansible-recaps.php:')
        ->not->toContain('secret-input');
});

it('reads a declared shell report or PR receipt from its recorded result artifact', function (string $kind, string $capture): void {
    $selector = app(DirectOperation::class)->definition($kind)['terminal_result'];
    $source = $selector['captures'][$capture];
    $dataId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $stdout = str_repeat('complete public result ', 400);
    $jobs = [];
    $jobs[$selector['job']][$selector['step']] = ['name' => $selector['step'], 'status' => 'succeeded'];
    $jobs[$source['job']][$source['step']] = ['name' => $source['step'], 'status' => 'succeeded', 'dataArtifacts' => [
        ['name' => 'result', 'dataId' => $dataId, 'version' => 7],
        ['name' => 'log', 'dataId' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845', 'version' => 7],
    ]];
    $run = ['status' => 'succeeded', 'jobs' => []];
    foreach ($jobs as $name => $steps) {
        $run['jobs'][] = ['name' => $name, 'steps' => array_values($steps)];
    }
    $this->artifact = ['id' => $dataId, 'name' => 'result', 'version' => 7,
        'content' => ['exitCode' => 0, 'stdout' => $stdout, 'stderr' => '']];

    $result = (new ReflectionMethod(SwampCommandStep::class, 'terminalOutput'))
        ->invoke(app(SwampCommandStep::class), $run, $selector);

    expect($result[$capture])->toBe($stdout);
    expect(array_column($this->requests, 'type'))->toBe(['data.query']);
    expect($this->requests[0]['payload']['predicate'])->toBe('id == "'.$dataId.'" && version == 7');
})->with([
    'proof report' => ['proof.command', 'report'],
    'ready receipt' => ['tasks.pr_ready', 'pr_receipt'],
    'merge receipt' => ['tasks.pr_merge', 'pr_receipt'],
]);
