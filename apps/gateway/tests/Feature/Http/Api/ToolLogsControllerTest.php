<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\call;

uses(RefreshDatabase::class);

const TOOL_LOGS_CONTROLLER_CALLER_WG_IP = '10.6.0.96';

function createToolLogsControllerCaller(): Node
{
    return Node::factory()->create([
        'name' => 'logs-controller-caller',

        'host' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP,
        'wireguard_address' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP,
    ]);
}

function grantToolLogsControllerAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createToolLogsControllerTarget(string $name): Node
{
    $node = createTestAppHostNode([
        'name' => $name,
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'supervisor',
        'expected_state' => 'running',
    ]);

    return $node;
}

describe('ToolLogsController target resolution', function (): void {
    it('requires an explicit target when multiple app nodes are visible', function (): void {
        $caller = createToolLogsControllerCaller();
        grantToolLogsControllerAccess($caller, createToolLogsControllerTarget('visible-logs-1'));
        grantToolLogsControllerAccess($caller, createToolLogsControllerTarget('visible-logs-2'));
        $shell = new ToolLogsControllerRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = call(
            'GET',
            '/api/tools/supervisor/logs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.fields', ['target']);

        expect($shell->scripts)->toBe([]);
    });

    it('does not use the only visible app node as an implicit target', function (): void {
        $caller = createToolLogsControllerCaller();
        grantToolLogsControllerAccess($caller, createToolLogsControllerTarget('visible-logs-only'));
        $shell = new ToolLogsControllerRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = call(
            'GET',
            '/api/tools/supervisor/logs',
            [],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.fields', ['target']);

        expect($shell->scripts)->toBe([]);
    });
});

final class ToolLogsControllerRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
