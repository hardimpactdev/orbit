<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Tools\ToolReconfigurer;
use App\Services\Tools\ToolUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('dispatches reconfigure tool scripts through internal tool run without transitional fallback', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-reconfigure-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'config' => [
            'port' => 9876,
        ],
    ]);
    $executor = new ToolTransportRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolReconfigurer::class)->reconfigure(
        tool: 'polyscope-server',
        node: 'tool-reconfigure-node',
        config: [
            'port' => 4321,
        ],
    );

    $payload = $executor->payloads()[0];

    expect($result)
        ->toMatchArray([
            'name' => 'polyscope-server',
            'node' => 'tool-reconfigure-node',
            'action' => 'reconfigured',
        ])
        ->and($tool->fresh()->config)
        ->toBe([
            'port' => 4321,
        ])
        ->and($executor->commands)
        ->toBe([InternalCommand::ToolRunScript->value])
        ->and($executor->transportOptions[0]['transport'] ?? null)
        ->toBe(NodeTransportPreference::AgentPush)
        ->and($executor->transportOptions[0]['bind_input'] ?? null)
        ->toBeTrue()
        ->and($executor->transportOptions[0]['strict'] ?? null)
        ->toBeFalse()
        ->and($executor->transportOptions[0]['metadata']['ORBIT_OPERATION_ID'] ?? null)
        ->toBe('tool.reconfigure')
        ->and($payload['tool'] ?? null)
        ->toBe('polyscope-server')
        ->and($payload['action'] ?? null)
        ->toBe('reconfigure')
        ->and($payload['script'] ?? null)
        ->toContain('orbit reconfigure polyscope-server');
});

it('dispatches bulk tool update scripts through internal tool run without transitional fallback', function (): void {
    $node = Node::factory()->appDev()->create(['name' => 'tool-update-node']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'node-exporter',
        'expected_version' => 'old',
    ]);
    $executor = new ToolTransportRecordingInternalExecutor;
    app()->instance(RunsInternalCommands::class, $executor);

    $result = app(ToolUpdater::class)->updateAll(node: 'tool-update-node');

    $payload = $executor->payloads()[0];

    expect($result['updated'])
        ->toBe([
            [
                'tool' => 'node-exporter',
                'node' => 'tool-update-node',
            ],
        ])
        ->and($result['skipped'])
        ->toBe([])
        ->and($result['failed'])
        ->toBe([])
        ->and($tool->fresh()->expected_version)
        ->not
        ->toBe('old')
        ->and($executor->commands)
        ->toBe([InternalCommand::ToolRunScript->value])
        ->and($executor->transportOptions[0]['metadata']['ORBIT_OPERATION_ID'] ?? null)
        ->toBe('tool.update')
        ->and($payload['tool'] ?? null)
        ->toBe('node-exporter')
        ->and($payload['action'] ?? null)
        ->toBe('update');
});

final class ToolTransportRecordingInternalExecutor implements RunsInternalCommands
{
    /**
     * @var list<string>
     */
    public array $nodes = [];

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
        $this->nodes[] = $node->name;
        $this->commands[] = $commandName;
        $this->transportOptions[] = $transportOptions;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => 0,
                'stdout' => '',
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
