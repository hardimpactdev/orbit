<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\LocalNodeDefault;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

it('prompts for missing name and target app node', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewInteractiveRecordingRemoteShell);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:new')
        ->expectsQuestion('App name (slug)', 'docs')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-1'.")
        ->assertExitCode(0);

    expect(App::query()->where('name', 'docs')->exists())->toBeTrue();
});

it('preselects the local default app node when prompting for the target node', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
        'environment' => 'development',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $defaultNode = createTestAppHostNode([
        'name' => 'app-2',
        'environment' => 'development',
        'tld' => 'test',
        'status' => 'active',
    ]);

    LocalNodeDefault::query()->create([
        'default_node_name' => 'app-2',
    ]);

    app()->instance(RemoteShell::class, new AppNewInteractiveRecordingRemoteShell);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:new')
        ->expectsQuestion('App name (slug)', 'docs')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-2'.")
        ->assertExitCode(0);

    expect(App::query()->where('name', 'docs')->value('node_id'))->toBe($defaultNode->id);
});

it('validates prompted app name availability before asking for repository input', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    $targetNode = createTestAppHostNode([
        'name' => 'app-1',
        'status' => 'active',
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $targetNode->id,
    ]);

    $remoteShell = new AppNewInteractiveRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('app:new')
        ->expectsQuestion('App name (slug)', 'docs')
        ->expectsOutputToContain("App name 'docs' is already registered in the gateway app registry on node 'app-1'.")
        ->assertExitCode(1);

    expect($remoteShell->scripts)->toBe([]);
});

it('prompts for an optional repository and canonicalizes github shorthand', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
        'role' => 'gateway',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
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
        ->and($remoteShell->scripts[0])->toContain("gh repo clone 'acme/docs'");
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
