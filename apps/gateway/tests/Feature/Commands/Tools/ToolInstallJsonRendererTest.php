<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Http\Gateway\Requests\Tools\InstallToolRequest;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Exception\InvalidOptionException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolInstallJsonLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "tool-install-json-{$role}",
        'host' => '10.9.0.1',
        'wireguard_address' => '10.9.0.1']);
}

function configureToolInstallJsonControlGateway(): void
{
    config(['orbit.is_gateway' => false]);

    createToolInstallJsonLocalNode('control');

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.9.0.1',
        'ca_pem_path' => '/dev/null'])->save();
}

describe('tool:install JSON renderer', function (): void {
    it('selects the JSON envelope renderer and emits the documented tool entity', function (): void {
        createToolInstallJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-install-1', 'status' => 'active']);
        app()->instance(RemoteShell::class, new ToolInstallJsonRecordingShell);

        $exitCode = Artisan::call('tool:install', [
            'tool' => 'redis',
            '--node' => 'app-json-install-1',
            '--status' => 'running',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toHaveKey('success')
            ->and($payload)->not->toHaveKey('error')
            ->and($payload['success']['meta'])->toBe([])
            ->and(array_keys($payload['success']['data']['tool']))->toBe([
                'name',
                'node',
                'state',
                'version'])
            ->and($payload['success']['data']['tool'])->toMatchArray([
                'name' => 'redis',
                'node' => 'app-json-install-1',
                'state' => 'running',
                'version' => null]);
    });

    it('renders unsupported status as validation_failed before side effects', function (): void {
        createToolInstallJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-install-1', 'status' => 'active']);
        $shell = new ToolInstallJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', [
            'tool' => 'redis',
            '--node' => 'app-json-install-1',
            '--status' => 'foo',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toBe([
                'field' => 'status',
                'value' => 'foo',
                'reason' => 'unsupported_value'])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('rejects expected-version as an unknown option before side effects', function (): void {
        createToolInstallJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-install-1', 'status' => 'active']);
        $shell = new ToolInstallJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        expect(fn () => Artisan::call('tool:install', [
            'tool' => 'redis',
            '--node' => 'app-json-install-1',
            '--expected-version' => '1.0.0',
            '--json' => true]))->toThrow(InvalidOptionException::class, 'The "--expected-version" option does not exist.');

        expect(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('requires a target source in non-interactive JSON mode before side effects', function (): void {
        createToolInstallJsonLocalNode('gateway');
        createTestAppHostNode(['name' => 'app-json-install-1', 'status' => 'active']);
        $shell = new ToolInstallJsonRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        $exitCode = Artisan::call('tool:install', ['tool' => 'redis', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node_target_required')
            ->and($payload['error']['meta'])->toBe(['field' => 'node'])
            ->and(NodeTool::query()->count())->toBe(0)
            ->and($shell->scripts)->toBe([]);
    });

    it('forwards install requests without install-time version intent', function (): void {
        configureToolInstallJsonControlGateway();

        $mock = MockClient::global([
            InstallToolRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'tool' => [
                            'name' => 'redis',
                            'node' => 'app-json-install-1',
                            'state' => 'installed',
                            'version' => null]],
                    'meta' => []]], 200)]);

        $exitCode = Artisan::call('tool:install', [
            'tool' => 'redis',
            '--node' => 'app-json-install-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['tool']['name'])->toBe('redis');

        $mock->assertSent(fn (InstallToolRequest $request): bool => $request->body()->all() === [
            'node' => 'app-json-install-1',
            'status' => 'installed']);
    });

    it('preserves structured gateway errors in the JSON envelope', function (array $error, int $status): void {
        configureToolInstallJsonControlGateway();

        MockClient::global([
            InstallToolRequest::class => MockResponse::make(['error' => $error], $status)]);

        $exitCode = Artisan::call('tool:install', [
            'tool' => 'redis',
            '--node' => 'app-json-install-1',
            '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe($error['code'])
            ->and($payload['error']['message'])->toBe($error['message'])
            ->and($payload['error']['meta'])->toBe($error['meta']);
    })->with([
        'tool.not_found' => [[
            'code' => 'tool.not_found',
            'message' => "Tool 'redis' was not found.",
            'meta' => ['tool' => 'redis']], 404],
        'tool.unsupported_action' => [[
            'code' => 'tool.unsupported_action',
            'message' => "Tool 'redis' does not support install.",
            'meta' => ['tool' => 'redis', 'action' => 'install']], 400],
        'tool.remote_action_failed' => [[
            'code' => 'tool.remote_action_failed',
            'message' => "Tool 'redis' install failed on node 'app-json-install-1'.",
            'meta' => ['tool' => 'redis', 'node' => 'app-json-install-1', 'action' => 'install']], 502],
        'authorization_failed' => [[
            'code' => 'authorization_failed',
            'message' => 'This node is not authorized to manage tools.',
            'meta' => ['reason' => 'missing_permission', 'missing_permission' => 'tool:install']], 403],
        'gateway_unavailable' => [[
            'code' => 'gateway_unavailable',
            'message' => 'Gateway cannot reach the tool registry.',
            'meta' => ['reason' => 'connect_timeout']], 503]]);
});

final class ToolInstallJsonRecordingShell implements RemoteShell
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
