<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolInstaller;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolScriptDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function bindToolInstallerPostWriteFailureDispatcher(ToolInstallerPostWriteFailureInternalExecutor $executor): void
{
    app()->instance(RunsInternalCommands::class, $executor);
    app()->instance(ToolScriptDispatcher::class, new ToolScriptDispatcher($executor));
}

function toolInstallerPostWriteTransportFailureDispatcher(): ToolInstallerPostWriteFailureInternalExecutor
{
    return new ToolInstallerPostWriteFailureInternalExecutor(
        transportFailuresByAction: [
            'install' => new RemoteLocalExecutorTransportFailed('agent push unavailable'),
        ],
    );
}

function toolInstallerPostWriteScriptFailureDispatcher(): ToolInstallerPostWriteFailureInternalExecutor
{
    return new ToolInstallerPostWriteFailureInternalExecutor(
        scriptExitCodeByAction: [
            'install' => 17,
        ],
        scriptStderrByAction: [
            'install' => 'install script failed',
        ],
    );
}

function toolInstallerPostWriteFailureNode(string $name): Node
{
    return Node::factory()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
    ]);
}

/**
 * @return array{expected_version: string|null, expected_state: string}
 */
function toolInstallerObservedExpectedIntent(Node $node, string $tool): array
{
    $row = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', $tool)
        ->first();

    expect($row)->toBeInstanceOf(NodeTool::class);

    return [
        'expected_version' => $row->expected_version,
        'expected_state' => $row->expected_state,
    ];
}

it('preserves a new expected-intent row after a transport-style install failure', function (): void {
    $node = toolInstallerPostWriteFailureNode('tool-intent-new-transport');
    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteTransportFailureDispatcher());

    $result = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
        version: '2.43.0',
    );

    $intent = toolInstallerObservedExpectedIntent($node, 'git');

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node.agent_unreachable')
        ->and($intent)
        ->toBe([
            'expected_version' => '2.43.0',
            'expected_state' => 'installed',
        ]);
});

it('preserves a pre-existing expected-intent row after a transport-style install failure', function (): void {
    $node = toolInstallerPostWriteFailureNode('tool-intent-existing-transport');
    NodeTool::factory()->for($node)->create([
        'name' => 'git',
        'expected_version' => '2.40.0',
        'expected_state' => 'installed',
        'config' => null,
    ]);
    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteTransportFailureDispatcher());

    $result = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
    );

    $intent = toolInstallerObservedExpectedIntent($node, 'git');

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node.agent_unreachable')
        ->and($intent)
        ->toBe([
            'expected_version' => '2.40.0',
            'expected_state' => 'installed',
        ]);
});

it('preserves a new expected-intent row after a script nonzero-exit install failure', function (): void {
    $node = toolInstallerPostWriteFailureNode('tool-intent-new-script');
    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteScriptFailureDispatcher());

    $result = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
        version: '2.43.0',
    );

    $intent = toolInstallerObservedExpectedIntent($node, 'git');

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($result->meta['exit_code'] ?? null)
        ->toBe(17)
        ->and($intent)
        ->toBe([
            'expected_version' => '2.43.0',
            'expected_state' => 'installed',
        ]);
});

it('preserves a pre-existing expected-intent row after a script nonzero-exit install failure', function (): void {
    $node = toolInstallerPostWriteFailureNode('tool-intent-existing-script');
    NodeTool::factory()->for($node)->create([
        'name' => 'git',
        'expected_version' => '2.40.0',
        'expected_state' => 'installed',
        'config' => null,
    ]);
    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteScriptFailureDispatcher());

    $result = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
    );

    $intent = toolInstallerObservedExpectedIntent($node, 'git');

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('tool.remote_action_failed')
        ->and($intent)
        ->toBe([
            'expected_version' => '2.40.0',
            'expected_state' => 'installed',
        ]);
});

it('later reconciliation observes identical expected intent after both post-write failure classes', function (): void {
    $node = toolInstallerPostWriteFailureNode('tool-intent-reconcile');
    NodeTool::factory()->for($node)->create([
        'name' => 'git',
        'expected_version' => '2.40.0',
        'expected_state' => 'installed',
        'config' => null,
    ]);

    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteTransportFailureDispatcher());
    $transportResult = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
    );
    $afterTransport = toolInstallerObservedExpectedIntent($node, 'git');

    bindToolInstallerPostWriteFailureDispatcher(toolInstallerPostWriteScriptFailureDispatcher());
    $scriptResult = app(ToolInstaller::class)->install(
        tool: 'git',
        node: $node->name,
    );
    $afterScript = toolInstallerObservedExpectedIntent($node, 'git');

    expect($transportResult)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($transportResult->code)
        ->toBe('node.agent_unreachable')
        ->and($scriptResult)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($scriptResult->code)
        ->toBe('tool.remote_action_failed')
        ->and($afterTransport)
        ->toBe([
            'expected_version' => '2.40.0',
            'expected_state' => 'installed',
        ])
        ->and($afterScript)
        ->toBe($afterTransport);
});

/**
 * @param  array<string, int>  $scriptExitCodeByAction
 * @param  array<string, string>  $scriptStderrByAction
 * @param  array<string, RemoteLocalExecutorTransportFailed>  $transportFailuresByAction
 */
final class ToolInstallerPostWriteFailureInternalExecutor implements RunsInternalCommands
{
    /**
     * @param  array<string, int>  $scriptExitCodeByAction
     * @param  array<string, string>  $scriptStderrByAction
     * @param  array<string, RemoteLocalExecutorTransportFailed>  $transportFailuresByAction
     */
    public function __construct(
        private readonly array $scriptExitCodeByAction = [],
        private readonly array $scriptStderrByAction = [],
        private readonly array $transportFailuresByAction = [],
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $action = actionFromToolInstallerPostWriteTransportOptions($transportOptions);

        if ($action !== null && array_key_exists($action, $this->transportFailuresByAction)) {
            throw $this->transportFailuresByAction[$action];
        }

        $exitCode = $action !== null && array_key_exists($action, $this->scriptExitCodeByAction)
            ? $this->scriptExitCodeByAction[$action]
            : 0;
        $stderr = $action !== null && array_key_exists($action, $this->scriptStderrByAction)
            ? $this->scriptStderrByAction[$action]
            : '';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $exitCode,
                'stdout' => '',
                'stderr' => $stderr,
                'duration_ms' => 1,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

/**
 * @param  array<string, mixed>  $transportOptions
 */
function actionFromToolInstallerPostWriteTransportOptions(array $transportOptions): ?string
{
    /** @var mixed $payload */
    $payload = json_decode((string) ($transportOptions['input'] ?? ''), associative: true);

    if (! is_array($payload)) {
        return null;
    }

    $action = $payload['action'] ?? null;

    return is_string($action) ? $action : null;
}
