<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return array{0: Node, 1: NodeTool}
 */
function phpCliUpdaterTool(string $role, string $staleVariant, string $nodeName): array
{
    $node = Node::factory()->create([
        'name' => $nodeName,
        'status' => NodeStatus::Active,
        'platform' => 'ubuntu',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => NodeRoleStatus::Active->value,
        'settings' => [],
    ]);

    $tool = NodeTool::query()->create([
        'node_id' => $node->id,
        'name' => 'php-cli',
        'expected_state' => 'installed',
        'expected_version' => 'old',
        'config' => ['variant' => $staleVariant],
    ]);

    return [$node->fresh(), $tool->fresh()];
}

it('update corrects stale app-prod coverage to standard and persists it', function (): void {
    [, $tool] = phpCliUpdaterTool(
        NodeRoleName::AppProduction->value,
        'coverage',
        'phpcli-upd-prod',
    );
    $executor = new PhpCliUpdaterRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolUpdater::class)->update('php-cli', node: 'phpcli-upd-prod');

    $tool->refresh();
    $payload = $executor->payloads()[0] ?? [];

    expect($result)
        ->toMatchArray([
            'name' => 'php-cli',
            'node' => 'phpcli-upd-prod',
        ])
        ->and($tool->config['variant'] ?? null)
        ->toBe('standard')
        ->and($executor->commands)
        ->toBe([InternalCommand::ToolRunScript->value])
        ->and($payload['tool'] ?? null)
        ->toBe('php-cli')
        ->and($payload['action'] ?? null)
        ->toBe('update')
        ->and($payload['script'] ?? null)
        ->toBeString()
        ->and($payload['script'])
        ->not
        ->toContain('php-8.5.8-cli-coverage-')
        ->and($payload['script'])
        ->toContain('/opt/orbit/php');
});

it('update corrects stale app-dev standard to coverage and persists it', function (): void {
    [, $tool] = phpCliUpdaterTool(
        NodeRoleName::AppDevelopment->value,
        'standard',
        'phpcli-upd-dev',
    );
    $executor = new PhpCliUpdaterRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolUpdater::class)->update('php-cli', node: 'phpcli-upd-dev');

    $tool->refresh();
    $payload = $executor->payloads()[0] ?? [];

    expect($result)
        ->toMatchArray([
            'name' => 'php-cli',
            'node' => 'phpcli-upd-dev',
        ])
        ->and($tool->config['variant'] ?? null)
        ->toBe('coverage')
        ->and($payload['tool'] ?? null)
        ->toBe('php-cli')
        ->and($payload['script'] ?? null)
        ->toBeString();
});

it('updateAll corrects stale role-owned php-cli variants before dispatch', function (): void {
    [$prodNode, $prodTool] = phpCliUpdaterTool(
        NodeRoleName::AppProduction->value,
        'coverage',
        'phpcli-updall-prod',
    );
    [$devNode, $devTool] = phpCliUpdaterTool(
        NodeRoleName::AppDevelopment->value,
        'standard',
        'phpcli-updall-dev',
    );
    $executor = new PhpCliUpdaterRecordingExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $prodResult = app(ToolUpdater::class)->updateAll($prodNode);
    $devResult = app(ToolUpdater::class)->updateAll($devNode);

    $prodTool->refresh();
    $devTool->refresh();

    expect($prodResult['failed'])
        ->toBeEmpty()
        ->and($devResult['failed'])
        ->toBeEmpty()
        ->and(collect($prodResult['updated'])->pluck('tool')->all())
        ->toContain('php-cli')
        ->and(collect($devResult['updated'])->pluck('tool')->all())
        ->toContain('php-cli')
        ->and($prodTool->config['variant'] ?? null)
        ->toBe('standard')
        ->and($devTool->config['variant'] ?? null)
        ->toBe('coverage')
        ->and($prodTool->expected_version)
        ->not->toBe('old')->and($devTool->expected_version)
        ->not->toBe('old');
});

final class PhpCliUpdaterRecordingExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $commands = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $transportOptions = [];

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
        $this->commands[] = $commandName;
        $this->transportOptions[] = $transportOptions;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => 0,
                'stdout' => "updated\n",
                'stderr' => '',
                'duration_ms' => 1,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payloads(): array
    {
        return array_map(
            static function (array $options): array {
                /** @var mixed $payload */
                $payload = json_decode(
                    (string) ($options['input'] ?? ''),
                    associative: true,
                    flags: JSON_THROW_ON_ERROR,
                );

                if (! is_array($payload)) {
                    return [];
                }

                /** @var array<string, mixed> $payload */
                return $payload;
            },
            $this->transportOptions,
        );
    }
}
