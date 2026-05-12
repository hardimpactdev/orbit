<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders and enacts supervisor programs for app process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'ssh_user' => 'orbit',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::query()->create([
        'app_id' => $app->id,
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'on_failure',
        'crash_notification' => 'none',
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '/usr/bin/supervisorctl', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    $certificates = new ProcessRuntimeRecordingSiteCertificateInstaller;

    $warnings = (new EnsureAppProcessRuntimeUnits(
        remoteShell: $remoteShell,
        renderer: new SupervisorProgramRenderer,
        runtimeBackendProbe: new RuntimeBackendProbe($remoteShell),
        siteCertificateInstaller: $certificates,
    ))->handle($app);

    $program = base64_decode((string) str($remoteShell->scripts[1])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toHaveCount(2)
        ->and($remoteShell->scripts[0])->toBe('command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1')
        ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_vite.conf')
        ->and($program)->toContain('[program:orbit_docs_main_vite]')
        ->and($program)->toContain('directory=/home/orbit/apps/docs')
        ->and($program)->toContain("command=/bin/bash -lc 'npm run dev -- --host=0.0.0.0'")
        ->and($program)->toContain('autorestart=unexpected')
        ->and($program)->toContain('APP_URL="https://docs.test"')
        ->and($program)->toContain('VITE_VALET_HOST="docs.test"')
        ->and($program)->toContain('VITE_DEV_SERVER_KEY="/home/orbit/.config/orbit/certs/docs.test.key"')
        ->and($program)->toContain('VITE_DEV_SERVER_CERT="/home/orbit/.config/orbit/certs/docs.test.crt"')
        ->and($certificates->hosts)->toBe(['docs.test'])
        ->and($remoteShell->scripts[1])->toContain('sudo supervisorctl update');
});

it('reports process family warnings when supervisor is unavailable after intent exists', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::query()->create([
        'app_id' => $app->id,
        'name' => 'worker',
        'command' => 'php artisan queue:work',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
    ]);

    $warnings = (new EnsureAppProcessRuntimeUnits(
        remoteShell: $remoteShell,
        renderer: new SupervisorProgramRenderer,
        runtimeBackendProbe: new RuntimeBackendProbe($remoteShell),
        siteCertificateInstaller: new ProcessRuntimeRecordingSiteCertificateInstaller,
    ))->handle($app);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toMatchArray([
            'code' => 'process.runtime_backend_unavailable',
            'family' => 'process',
            'next_command' => 'doctor --fix --family=process --restore',
        ])
        ->and($remoteShell->scripts)->toHaveCount(1);
});

it('does not probe supervisor when an app has no process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'role' => 'app',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);
    $app->setRelation('node', $node);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell;

    $warnings = (new EnsureAppProcessRuntimeUnits(
        remoteShell: $remoteShell,
        renderer: new SupervisorProgramRenderer,
        runtimeBackendProbe: new RuntimeBackendProbe($remoteShell),
        siteCertificateInstaller: new ProcessRuntimeRecordingSiteCertificateInstaller,
    ))->handle($app);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toBe([]);
});

final class ProcessRuntimeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

final class ProcessRuntimeRecordingSiteCertificateInstaller implements SiteCertificateInstaller
{
    /**
     * @var list<string>
     */
    public array $hosts = [];

    public function ensureFor(Node $node, string $host): array
    {
        $this->hosts[] = $host;

        return $this->expectedPathsFor($node, $host);
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/{$node->ssh_user}/.config/orbit/certs/{$host}.crt",
            'key' => "/home/{$node->ssh_user}/.config/orbit/certs/{$host}.key",
        ];
    }
}
