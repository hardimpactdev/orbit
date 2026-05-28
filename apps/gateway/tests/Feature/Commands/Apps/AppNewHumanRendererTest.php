<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Tools\CaddyTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

it('renders the documented progress tree and completion summary', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewHumanRecordingRemoteShell);

    $this->artisan('app:new docs --node=app-1')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain('┌  Creating App')
        ->expectsOutputToContain('○  Create app source')
        ->expectsOutputToContain('●  Created app source')
        ->expectsOutputToContain('●  Applied and verified app registration')
        ->expectsOutputToContain('●  Applied runtime container configuration')
        ->expectsOutputToContain('●  Applied proxy routes')
        ->expectsOutputToContain("└  App 'docs' created")
        ->expectsOutputToContain("App 'docs' created successfully on node 'app-1'.")
        ->expectsOutputToContain('URL: https://docs.test')
        ->assertExitCode(0);
});

it('renders validation failures without a progress tree', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
    ]);

    $this->artisan('app:new Invalid --node=app-1')
        ->doesntExpectOutputToContain('┌ Creating App')
        ->expectsOutputToContain('App name must be a slug of 40 characters or fewer.')
        ->assertExitCode(1);
});

it('renders decorated progress tree glyphs and colors', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewHumanRecordingRemoteShell);

    $output = new BufferedOutput(decorated: true);
    $exitCode = Artisan::call('app:new', [
        'name' => 'docs',
        '--node' => 'app-1',
        '--no-interaction' => true,
    ], $output);
    $buffer = $output->fetch();
    $plainBuffer = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $buffer) ?? $buffer;

    expect($exitCode)->toBe(0)
        ->and($plainBuffer)->toContain('┌  Creating App')
        ->and($plainBuffer)->toContain('○  Create app source')
        ->and($plainBuffer)->toContain('●  Created app source')
        ->and($plainBuffer)->toContain("└  App 'docs' created")
        ->and($buffer)->toContain("\e[36m○\e[39m")
        ->and($buffer)->toContain("\e[32m●\e[39m")
        ->and($buffer)->toContain("\e[97mApp 'docs' created")
        ->and($buffer)->not->toContain("\e[38;5;242mApp 'docs' created");
});

it('renders warning retry hints in human output', function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',
    ]);

    createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    app()->instance(RemoteShell::class, new AppNewHumanSequencedRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'caddy reload failed', durationMs: 1),
    ]));

    $this->artisan('app:new docs --node=app-1')
        ->expectsConfirmation('Clone from a git repository?', 'no')
        ->expectsOutputToContain('Warnings:')
        ->expectsOutputToContain("Proxy route 'docs.test' was recorded, but backend enactment failed.")
        ->expectsOutputToContain('Retry with: orbit doctor --family=proxy --restore')
        ->assertExitCode(0);
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

final class AppNewHumanSequencedRemoteShell implements RemoteShell
{
    public function __construct(array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, CaddyTool::reloadCommand())) {
            return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'caddy reload failed', durationMs: 1);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
