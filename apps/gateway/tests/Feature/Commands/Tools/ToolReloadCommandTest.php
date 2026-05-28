<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\ReloadToolRequest;
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

function createToolReloadLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1']);
}

describe('tool:reload command contract', function (): void {
    it('reloads a managed tool without changing gateway intent', function (): void {
        createToolReloadLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running',
            'expected_version' => '4.2.5']);
        $shell = new ToolReloadRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reload', ['tool' => 'supervisor', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($tool->expected_version)->toBe('4.2.5')
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'supervisor',
                'node' => 'app-1',
                'expected_state' => 'running',
                'version' => '4.2.5'])
            ->and($shell->scripts)->toBe(['sudo supervisorctl reread']);
    });

    it('rejects tools without a reload action before remote work', function (): void {
        createToolReloadLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running']);
        $shell = new ToolReloadRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:reload', ['tool' => 'gh', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'reload'])
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($shell->scripts)->toBe([]);
    });

    it('requires an explicit tool in json mode', function (): void {
        createToolReloadLocalNode('gateway');

        $exitCode = Artisan::call('tool:reload', ['--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray([
                'field' => 'tool']);
    });

    it('prompts for a reload-capable tool when omitted in interactive mode', function (): void {
        createToolReloadLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'supervisor',
            'expected_state' => 'running']);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running']);
        $shell = new ToolReloadRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $this->artisan('tool:reload --node=app-1')
            ->expectsQuestion('Which tool should be reloaded?', 'supervisor on app-1')
            ->expectsOutput('Reloaded supervisor on app-1.')
            ->assertExitCode(0);

        expect($shell->scripts)->toBe(['sudo supervisorctl reread']);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolReloadLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null'])->save();

        MockClient::global([
            ReloadToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'supervisor',
                            'node' => 'app-1',
                            'expected_state' => 'running',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => []]],
                    'meta' => []]], 200)]);

        $exitCode = Artisan::call('tool:reload', ['tool' => 'supervisor', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['expected_state'])->toBe('running');
    });
});

final class ToolReloadRecordingShell implements RemoteShell
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
