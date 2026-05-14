<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createToolInstallInteractiveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

function fakeInstallShell(): void
{
    app()->instance(RemoteShell::class, new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });
}

describe('tool:install interactive input mode', function (): void {
    it('prompts for tool name when omitted in interactive mode', function (): void {
        createToolInstallInteractiveLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        fakeInstallShell();

        $this->artisan('tool:install --node=app-1')
            ->expectsSearch('Tool name', 'redis', 'redis', ['redis'])
            ->assertSuccessful();
    });

    it('does not prompt when tool argument is supplied', function (): void {
        createToolInstallInteractiveLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'status' => 'active']);
        fakeInstallShell();

        $this->artisan('tool:install', ['tool' => 'redis', '--node' => 'app-1', '--json' => true])
            ->doesntExpectOutputToContain('Tool name')
            ->assertSuccessful();
    });

    it('fails with validation_failed when tool is missing in non-interactive mode', function (): void {
        createToolInstallInteractiveLocalNode('gateway');

        $exitCode = Artisan::call('tool:install', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta'])->toMatchArray(['field' => 'tool']);
    });
});
