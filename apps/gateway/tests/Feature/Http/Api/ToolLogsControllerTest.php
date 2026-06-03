<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
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

function createToolLogsControllerProcessBackedTarget(string $name): Node
{
    $node = createTestAppHostNode([
        'name' => $name,
        'status' => 'active',
    ]);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'running',
    ]);

    Process::factory()->forOwner($node)->create([
        'name' => 'opencode-server',
        'tool' => 'opencode',
        'runtime' => ProcessRuntime::Systemd,
        'command' => 'opencode serve -a',
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

    it('reads logs through the related process runtime backend', function (): void {
        $caller = createToolLogsControllerCaller();
        grantToolLogsControllerAccess($caller, createToolLogsControllerProcessBackedTarget('visible-process-logs'));
        $shell = new ToolLogsControllerRecordingShell(stdout: "first line\nsecond line\n");
        app()->instance(RemoteShell::class, $shell);

        $response = call(
            'GET',
            '/api/tools/opencode-server/logs',
            ['node' => 'visible-process-logs', 'lines' => 5],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP],
        );

        $response->assertOk()
            ->assertJsonPath('success.data.logs.tool', 'opencode-server')
            ->assertJsonPath('success.data.logs.node', 'visible-process-logs')
            ->assertJsonPath('success.data.logs.process', 'opencode-server')
            ->assertJsonPath('success.data.logs.runtime_unit', 'opencode-server')
            ->assertJsonPath('success.data.logs.lines.0.message', 'first line');

        expect($shell->scripts)->toBe(["sudo journalctl -u 'opencode-server.service' -n 5 --no-pager 2>&1"]);
    });

    it('fails explicitly when logs have no related process', function (): void {
        $caller = createToolLogsControllerCaller();
        $node = createTestAppHostNode([
            'name' => 'visible-process-missing',
            'status' => 'active',
        ]);
        grantToolLogsControllerAccess($caller, $node);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'opencode-server',
            'expected_state' => 'running',
        ]);
        $shell = new ToolLogsControllerRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $response = call(
            'GET',
            '/api/tools/opencode-server/logs',
            ['node' => 'visible-process-missing'],
            [],
            [],
            ['REMOTE_ADDR' => TOOL_LOGS_CONTROLLER_CALLER_WG_IP],
        );

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'tool.process_missing')
            ->assertJsonPath('error.meta.tool', 'opencode-server')
            ->assertJsonPath('error.meta.node', 'visible-process-missing');

        expect($shell->scripts)->toBe([]);
    });
});

final class ToolLogsControllerRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private string $stdout = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}
