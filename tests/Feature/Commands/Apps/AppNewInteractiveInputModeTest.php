<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prompts for missing name and target app node', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewInteractiveRecordingRemoteShell);

    $this->artisan('app:new')
        ->expectsQuestion('App name (slug)', 'docs')
        ->expectsQuestion('Select target app node', 'app-1')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-1'.")
        ->assertExitCode(0);

    expect(App::query()->where('name', 'docs')->exists())->toBeTrue();
});

it('prompts for an optional repository and canonicalizes github shorthand', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'status' => 'active',
    ]);

    $remoteShell = new AppNewInteractiveRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    $this->artisan('app:new docs --node=app-1')
        ->expectsConfirmation('Clone from a git repository?', 'yes')
        ->expectsQuestion('Repository URL (or GitHub owner/repo)', 'acme/docs')
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-1'.")
        ->assertExitCode(0);

    expect(App::query()->where('name', 'docs')->value('repository'))->toBe('git@github.com:acme/docs.git')
        ->and($remoteShell->scripts[0])->toContain("git clone 'git@github.com:acme/docs.git'");
});

final class AppNewInteractiveRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
