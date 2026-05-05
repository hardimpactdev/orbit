<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the documented progress tree and completion summary', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewHumanRecordingRemoteShell);

    $this->artisan('app:new docs --node=app-1')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain('┌ Creating App')
        ->expectsOutputToContain('○ Create app source')
        ->expectsOutputToContain('○ Apply and verify app registration')
        ->expectsOutputToContain('○ Apply PHP-FPM configuration')
        ->expectsOutputToContain('○ Apply proxy routes')
        ->expectsOutputToContain("└ App 'docs' created")
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-1'.")
        ->expectsOutputToContain('Environment: development')
        ->expectsOutputToContain('URL: https://docs.test')
        ->assertExitCode(0);
});

it('renders validation failures without a progress tree', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $this->artisan('app:new Invalid --node=app-1')
        ->doesntExpectOutputToContain('┌ Creating App')
        ->expectsOutputToContain('App name must be a slug of 40 characters or fewer.')
        ->assertExitCode(1);
});

final class AppNewHumanRecordingRemoteShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
