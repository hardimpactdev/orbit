<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\RestartToolRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolRestartJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-restart-json-{$role}",
        'host' => '10.11.0.1',
        'wireguard_address' => '10.11.0.1']);
}

function configureToolRestartJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolRestartJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.11.0.1',
        'ca_pem_path' => '/dev/null'])->save();
}

describe('tool:restart JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits the canonical tool entity', function (): void {
        createToolRestartJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-restart-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running',
            'expected_version' => '2.10.2',
            'config' => ['endpoints' => [['name' => 'http', 'url' => 'https://example.test']]]]);
        app()->instance(RemoteShell::class, new ToolRestartJsonRecordingShell);

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'caddy',
            '--node' => 'app-json-restart-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and(array_keys($payload['success']['data']['tool']))->toBe([
                'name',
                'node',
                'expected_state',
                'observed_state',
                'version',
                'managed',
                'endpoints'])
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'caddy',
                'node' => 'app-json-restart-1',
                'expected_state' => 'running',
                'observed_state' => null,
                'version' => '2.10.2',
                'managed' => true]);
    });

    it('renders missing tool input as validation_failed', function (): void {
        createToolRestartJsonLocalNode('gateway');

        $exitCode = Artisan::call('tool:restart', ['--node' => 'app-json-restart-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe(['field' => 'tool']);
    });

    it('renders invalid tool names as validation_failed before side effects', function (): void {
        createToolRestartJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-restart-1', 'status' => 'active']);
        $shell = new ToolRestartJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'not-a-tool',
            '--node' => 'app-json-restart-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'tool',
                'value' => 'not-a-tool',
                'reason' => 'unknown_tool'])
            ->and($shell->scripts)->toBe([]);
    });

    it('requires a target source in non-interactive JSON mode before side effects', function (): void {
        createToolRestartJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-restart-1', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);
        $shell = new ToolRestartJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:restart', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($payload['error']['meta'])->toBe(['field' => 'node'])
            ->and($shell->scripts)->toBe([]);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolRestartJsonControlGateway();

        MockClient::global([
            RestartToolRequest::class => MockResponse::make(['error' => $error], $status)]);

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'caddy',
            '--node' => 'app-json-restart-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to manage tools.',
            'meta' => ['reason' => 'missing_permission', 'missing_permission' => 'tool:restart']], 403],
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'caddy' was not found on node 'app-json-restart-1'.",
            'meta' => ['tool' => 'caddy', 'node' => 'app-json-restart-1']], 404],
        'tool.unsupported_action' => [[
            'code' => 'tool.unsupported_action',
            'message' => "Tool 'gh' does not support restart.",
            'meta' => ['tool' => 'gh', 'action' => 'restart']], 400],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'caddy' restart failed on node 'app-json-restart-1'.",
            'meta' => [
                'tool' => 'caddy',
                'node' => 'app-json-restart-1',
                'action' => 'restart',
                'exit_code' => 7,
                'stderr' => 'docker restart orbit-caddy failed']], 502]]);

    it('preserves remote action exit code and stderr meta from local lifecycle failures', function (): void {
        createToolRestartJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-restart-1', 'status' => 'active']);
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'caddy']);
        app()->instance(RemoteShell::class, new ToolRestartJsonRecordingShell(exitCode: 7, stderr: 'docker restart orbit-caddy failed'));

        $exitCode = Artisan::call('tool:restart', [
            'tool' => 'caddy',
            '--node' => 'app-json-restart-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.remote_action_failed')
            ->and($payload['error']['meta'])->toHaveKeys(['exit_code', 'stderr'])
            ->and($payload['error']['meta']['exit_code'])->toBe(7)
            ->and($payload['error']['meta']['stderr'])->toBe('docker restart orbit-caddy failed');
    });
});

final class ToolRestartJsonRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function __construct(
        private readonly int $exitCode = 0,
        private readonly string $stderr = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: $this->exitCode, stdout: '', stderr: $this->stderr, durationMs: 1);
    }
}
