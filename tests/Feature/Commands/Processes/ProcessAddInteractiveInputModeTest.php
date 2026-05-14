<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

function createProcessAddInteractiveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for app, name, and command when all are absent', function (): void {
    createProcessAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);

    $remoteShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    $this->artisan('process:add')
        ->expectsChoice('App', 'docs', ['docs'])
        ->expectsQuestion('Process name', 'web')
        ->expectsQuestion('Command', 'php artisan serve')
        ->assertSuccessful();
});

it('does not prompt for app when --app is supplied', function (): void {
    createProcessAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);

    $remoteShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    $this->artisan('process:add --app=docs')
        ->doesntExpectOutput('App')
        ->expectsQuestion('Process name', 'web')
        ->expectsQuestion('Command', 'php artisan serve')
        ->assertSuccessful();
});

it('does not prompt when all required args are supplied', function (): void {
    createProcessAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);

    $remoteShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    $this->artisan('process:add web "php artisan serve" --app=docs')
        ->doesntExpectOutput('App')
        ->doesntExpectOutput('Process name')
        ->doesntExpectOutput('Command')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when app is missing', function (): void {
    createProcessAddInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:add', [
        'name' => 'web',
        'processCommand' => 'php artisan serve',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createProcessAddInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:add', [
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});
