<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\WebSockets\WebSocketRuntimeContainer;
use App\Services\WebSockets\WebSocketRuntimeSourceInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('installs the dedicated Reverb runtime source through a Docker-first script', function (): void {
    $node = Node::factory()->create(['name' => 'ws-1']);
    $shell = new WebSocketRuntimeSourceInstallerTestShell;

    (new WebSocketRuntimeSourceInstaller($shell))->install($node);

    $script = $shell->scripts[0];

    expect($shell->nodes[0]->is($node))->toBeTrue()
        ->and($shell->options[0])->toMatchArray([
            'throw' => true,
            'metadata' => [
                'lane' => 'remote-host',
                'operation' => 'websocket-runtime-source-install',
            ],
        ])
        ->and($script)->toContain('release_dir="${runtime_root}/releases/')
        ->and($script)->toContain('sudo install -d -m 0755 "$release_dir"')
        ->and($script)->toContain("sudo ln -sfn \"\$release_dir\" '".WebSocketRuntimeContainer::SourceHostPath."'")
        ->and($script)->toContain("'orbit-runtime:current'")
        ->and($script)->toContain("'composer' 'install' '--no-dev' '--no-interaction' '--prefer-dist' '--optimize-autoloader' '--no-progress'")
        ->and($script)->toContain('vendor/autoload.php')
        ->and($script)->toContain('app_key="base64:$(head -c 32 /dev/urandom | base64')
        ->and($script)->toContain("printf 'APP_KEY=%s\\n'")
        ->and($script)->toContain(WebSocketRuntimeSourceInstaller::AppsConfigPath)
        ->and($script)->not->toContain("\ncomposer install")
        ->and($script)->not->toContain("\nphp artisan")
        ->and($script)->not->toContain('reverb:install')
        ->and($script)->not->toContain('install:broadcasting');
});

it('ships a bootable Laravel Reverb source artifact without committed vendor files', function (): void {
    $sourcePath = resource_path('websocket-runtime');

    expect("{$sourcePath}/artisan")->toBeFile()
        ->and("{$sourcePath}/bootstrap/app.php")->toBeFile()
        ->and("{$sourcePath}/composer.json")->toBeFile()
        ->and("{$sourcePath}/composer.lock")->toBeFile()
        ->and("{$sourcePath}/config/reverb.php")->toBeFile()
        ->and("{$sourcePath}/vendor")->not->toBeDirectory();

    expect(file_get_contents("{$sourcePath}/config/reverb.php"))->toContain('ORBIT_WEBSOCKET_APPS_CONFIG');

    $composer = json_decode(file_get_contents("{$sourcePath}/composer.json") ?: '', true, flags: JSON_THROW_ON_ERROR);

    expect($composer['require'])->toMatchArray([
        'php' => '^8.5',
        'laravel/framework' => '13.7.0',
        'laravel/reverb' => '^1.10',
    ]);
});

final class WebSocketRuntimeSourceInstallerTestShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $options = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
