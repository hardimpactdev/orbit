<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Tools\StopToolRequest;
use App\Models\App;
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

function createToolStopLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1']);
}

function createToolStopTarget(string $nodeName, ?string $tld = null): Node
{
    return createTestAppHostNode([
        'name' => $nodeName,
        'status' => 'active',
        'tld' => $tld]);
}

function createToolStopManagedTool(Node $node, string $tool = 'caddy'): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => $tool,
        'expected_state' => 'running']);
}

function toolStopLastOutputLine(): string
{
    $lines = array_values(array_filter(
        array_map(
            fn (string $line): string => trim((string) preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $line)),
            explode(PHP_EOL, Artisan::output()),
        ),
        fn (string $line): bool => $line !== '',
    ));

    return (string) end($lines);
}

describe('tool:stop command contract', function (): void {
    it('updates gateway intent and stops the managed tool on the selected node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'expected_state' => 'running']);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($tool->refresh()->expected_state)->toBe('installed')
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'caddy',
                'node' => 'app-1',
                'expected_state' => 'installed'])
            ->and($shell->scripts)->toHaveCount(1)
            ->and($shell->scripts[0])->toContain('docker stop')
            ->and($shell->scripts[0])->toContain('orbit-caddy')
            ->and($shell->scripts[0])->not->toContain('systemctl');
    });

    it('rejects tools without a stop action before changing intent', function (): void {
        createToolStopLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
        $tool = NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'gh',
            'expected_state' => 'running']);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'gh', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('tool.unsupported_action')
            ->and($payload['error']['meta'])->toMatchArray([
                'tool' => 'gh',
                'action' => 'stop'])
            ->and($tool->refresh()->expected_state)->toBe('running')
            ->and($shell->scripts)->toBe([]);
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolStopLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null'])->save();

        MockClient::global([
            StopToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'caddy',
                            'node' => 'app-1',
                            'expected_state' => 'installed',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => []]],
                    'meta' => []]], 200)]);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--node' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['expected_state'])->toBe('installed');
    });

    it('resolves app slug selectors to the owning app node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-slug-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolStopManagedTool($node);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--app' => 'docs', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-stop-slug-1')
            ->and($shell->scripts)->toHaveCount(1);
    });

    it('resolves app domain selectors to the owning app node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-domain-1');
        App::factory()->create(['name' => 'docs', 'domain' => 'docs.example.com', 'node_id' => $node->id]);
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--app' => 'docs.example.com', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-stop-domain-1');
    });

    it('resolves app slug plus node tld selectors to the owning app node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-tld-1', 'dev1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--app' => 'docs.dev1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-stop-tld-1');
    });

    it('allows app and node selectors when they resolve to the same node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-match-1');
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell);

        $exitCode = Artisan::call('tool:stop', [
            'tool' => 'caddy',
            '--app' => 'docs',
            '--node' => 'app-stop-match-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-stop-match-1');
    });

    it('rejects app and node selectors when they resolve to different nodes', function (): void {
        createToolStopLocalNode('gateway');
        $appNode = createToolStopTarget('app-stop-mismatch-1');
        createToolStopTarget('app-stop-mismatch-2');
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        createToolStopManagedTool($appNode);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', [
            'tool' => 'caddy',
            '--app' => 'docs',
            '--node' => 'app-stop-mismatch-2',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'app',
                'value' => 'docs',
                'node' => 'app-stop-mismatch-2',
                'resolved_node' => 'app-stop-mismatch-1',
                'reason' => 'target_mismatch'])
            ->and($shell->scripts)->toBe([]);
    });

    it('uses node selectors directly', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-node-1');
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--node' => 'app-stop-node-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('app-stop-node-1');
    });

    it('requires an explicit target when no target is provided', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-default-1');
        createToolStopManagedTool($node);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($payload['error']['meta'])->toBe(['field' => 'node'])
            ->and($shell->scripts)->toBe([]);
    });

    it('uses gateway-known caller identity as self fallback when no explicit target exists', function (): void {
        config(['orbit.is_gateway' => false]);

        createToolStopLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null'])->save();

        $mock = MockClient::global([
            ShowGatewayIdentityRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'self' => [
                            'name' => 'control-self']]]], 200),
            StopToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'caddy',
                            'node' => 'control-self',
                            'expected_state' => 'installed',
                            'observed_state' => null,
                            'version' => null,
                            'managed' => true,
                            'endpoints' => []]],
                    'meta' => []]], 200)]);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['node'])->toBe('control-self');

        $mock->assertSent(fn (StopToolRequest $request): bool => $request->body()->all() === [
            'node' => 'control-self']);
    });

    it('does not infer a target from one visible app node', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-only-visible-1');
        createToolStopManagedTool($node);
        $shell = new ToolStopRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($payload['error']['meta'])->toBe(['field' => 'node'])
            ->and($shell->scripts)->toBe([]);
    });

    it('prints concise human success output without lifecycle metadata', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-stop-human-success-1');
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell);

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--node' => 'app-stop-human-success-1']);

        expect($exitCode)->toBe(0)
            ->and(toolStopLastOutputLine())->toBe('Stopped caddy on app-stop-human-success-1.')
            ->and(Artisan::output())->not->toContain('expected_state')
            ->and(Artisan::output())->not->toContain('observed_state')
            ->and(Artisan::output())->not->toContain('managed')
            ->and(Artisan::output())->not->toContain('version');
    });

    it('prints remote action diagnostics and recovery guidance for human failures', function (): void {
        createToolStopLocalNode('gateway');
        $node = createToolStopTarget('app-failing-caddy');
        createToolStopManagedTool($node);
        app()->instance(RemoteShell::class, new ToolStopRecordingShell(exitCode: 23, stderr: 'unit refused to stop'));

        $exitCode = Artisan::call('tool:stop', ['tool' => 'caddy', '--node' => 'app-failing-caddy']);

        $output = Artisan::output();

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Exit code: 23')
            ->and($output)->toContain('stderr: unit refused to stop')
            ->and($output)->toContain('orbit tool:logs caddy --node=app-failing-caddy')
            ->and($output)->toContain('orbit doctor --family=tool --restore');
    });
});

final class ToolStopRecordingShell implements RemoteShell
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
