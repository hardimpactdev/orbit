<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ProcessToolLifecycleRunner;
use App\Services\Tools\ToolLogReader;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('includes platform on process-lifecycle Agent-unreachable failures', function (): void {
    [$node, $target] = agent_unreachable_platform_process_target();
    app()->instance(RunsInternalCommands::class, new AgentUnreachablePlatformInternalExecutor);

    $result = app(ProcessToolLifecycleRunner::class)->run($target, 'start');

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node.agent_unreachable')
        ->and($result->meta['reason'] ?? null)
        ->toBe('agent_push_unavailable')
        ->and($result->meta['node'] ?? null)
        ->toBe($node->name)
        ->and($result->meta['platform'] ?? null)
        ->toBe('macos_15-4');
});

it('includes platform on process-log Agent-unreachable failures', function (): void {
    [$node, $target] = agent_unreachable_platform_process_target();
    app()->instance(RunsInternalCommands::class, new AgentUnreachablePlatformInternalExecutor);

    $method = new ReflectionMethod(ToolLogReader::class, 'readTarget');
    $result = $method->invoke(app(ToolLogReader::class), $target, 100);

    expect($result)
        ->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($result->code)
        ->toBe('node.agent_unreachable')
        ->and($result->meta['reason'] ?? null)
        ->toBe('agent_push_unavailable')
        ->and($result->meta['node'] ?? null)
        ->toBe($node->name)
        ->and($result->meta['platform'] ?? null)
        ->toBe('macos_15-4');
});

/**
 * @return array{0: Node, 1: ToolRuntimeTarget}
 */
function agent_unreachable_platform_process_target(): array
{
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'mac-1',
            'platform' => 'macos_15-4',
            'status' => 'active',
        ]);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'mysql',
    ]);
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'mysql',
            'tool' => 'mysql',
            'runtime' => ProcessRuntime::Docker,
        ]);

    return [$node, new ToolRuntimeTarget($tool, $node, $process)];
}

final class AgentUnreachablePlatformInternalExecutor implements RunsInternalCommands
{
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
        throw new RemoteLocalExecutorTransportFailed('agent-push transport is unavailable');
    }
}
