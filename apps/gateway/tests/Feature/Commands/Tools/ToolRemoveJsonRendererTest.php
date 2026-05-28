<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\RemoveToolRequest;
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

function createToolRemoveJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-remove-json-{$role}",
        'host' => '10.10.0.1',
        'wireguard_address' => '10.10.0.1']);
}

function configureToolRemoveJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolRemoveJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.10.0.1',
        'ca_pem_path' => '/dev/null'])->save();
}

describe('tool:remove JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits the documented tool entity', function (): void {
        createToolRemoveJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-remove-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running']);
        app()->instance(RemoteShell::class, new ToolRemoveJsonRecordingShell);

        $exitCode = Artisan::call('tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-json-remove-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and($payload['success']['data']['tool'])->toBe([
                'name' => 'redis',
                'node' => 'app-json-remove-1'])
            ->and(NodeTool::find($tool->id))->toBeNull();
    });

    it('renders missing destructive consent as validation_failed for non-json non-interactive mode', function (): void {
        createToolRemoveJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-remove-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running']);
        $shell = new ToolRemoveJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        test()->artisan('tool:remove redis --node=app-json-remove-1 --no-interaction')
            ->expectsOutputToContain('Use --force or --json to remove this tool.')
            ->assertFailed();

        expect(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('renders missing target as node_target_required with node field metadata', function (): void {
        createToolRemoveJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-remove-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running']);
        $shell = new ToolRemoveJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', [
            'tool' => 'redis',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($payload['error']['meta'])->toBe(['field' => 'node'])
            ->and(NodeTool::find($tool->id))->not->toBeNull()
            ->and($shell->scripts)->toBe([]);
    });

    it('proceeds without force because json implies destructive consent', function (): void {
        createToolRemoveJsonLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-json-remove-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'redis',
            'expected_state' => 'running']);
        $shell = new ToolRemoveJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-json-remove-1',
            '--json' => true]);

        expect($exitCode)->toBe(0)
            ->and(NodeTool::find($tool->id))->toBeNull()
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolRemoveJsonControlGateway();

        MockClient::global([
            RemoveToolRequest::class => MockResponse::make(['error' => $error], $status)]);

        $exitCode = Artisan::call('tool:remove', [
            'tool' => 'redis',
            '--node' => 'app-json-remove-1',
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
            'meta' => ['reason' => 'missing_permission', 'missing_permission' => 'tool:remove']], 403],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot reach the tool registry.',
            'meta' => ['reason' => 'connect_timeout']], 503],
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'redis' was not found on node 'app-json-remove-1'.",
            'meta' => ['tool' => 'redis', 'node' => 'app-json-remove-1']], 404],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'redis' remove failed on node 'app-json-remove-1'.",
            'meta' => ['tool' => 'redis', 'node' => 'app-json-remove-1', 'action' => 'remove']], 502]]);
});

final class ToolRemoveJsonRecordingShell implements RemoteShell
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
