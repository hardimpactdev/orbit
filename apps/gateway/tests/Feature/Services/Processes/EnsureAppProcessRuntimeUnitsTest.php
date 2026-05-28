<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEnsureRuntimeUnitsAction(RemoteShell $remoteShell, SiteCertificateInstaller $certificates): EnsureAppProcessRuntimeUnits
{
    return new EnsureAppProcessRuntimeUnits(
        remoteShell: $remoteShell,
        renderer: new SupervisorProgramRenderer,
        runtimeBackendProbe: new RuntimeBackendProbe($remoteShell),
        siteCertificateInstaller: $certificates,
        dockerRenderer: new ProcessDockerContainerRenderer(
            new PhpRuntimePolicy(new PhpRuntimeCatalog),
            new OrbitContainerNames,
        ),
        dockerManager: new ProcessDockerRuntimeManager(
            $remoteShell,
            new DockerCommandBuilder,
        ),
    );
}

it('renders and enacts supervisor programs for app process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::query()->create([
        'app_id' => $app->id,
        'name' => 'vite',
        'command' => 'npm run dev -- --host=0.0.0.0',
        'restart_policy' => 'on_failure',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Supervisor,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '/usr/bin/supervisorctl', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    $certificates = new ProcessRuntimeRecordingSiteCertificateInstaller;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, $certificates)->handle($app);

    $program = base64_decode((string) str($remoteShell->scripts[1])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toHaveCount(2)
        ->and($remoteShell->scripts[0])->toBe('command -v supervisorctl >/dev/null 2>&1 && sudo supervisorctl version >/dev/null 2>&1')
        ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_vite.conf')
        ->and($remoteShell->scripts[1])->toContain("docker rm -f 'orbit_docs_main_vite' 2>/dev/null || true")
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
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::query()->create([
        'app_id' => $app->id,
        'name' => 'worker',
        'command' => 'php artisan queue:work',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Supervisor,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(exitCode: 127, stdout: '', stderr: 'missing supervisorctl', durationMs: 1),
    ]);

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toMatchArray([
            'code' => 'process.runtime_backend_unavailable',
            'family' => 'process',
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and($remoteShell->scripts)->toHaveCount(1);
});

it('reports process.tls_certificate_missing when the site certificate installer throws and still continues to the next workspace context', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'runtime_kind' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::query()->create([
        'app_id' => $app->id,
        'name' => 'watch',
        'command' => './watch.sh',
        'restart_policy' => 'always',
        'crash_notification' => 'none',
        'runtime' => ProcessRuntime::Supervisor,
        'sort_order' => 1,
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        // supervisor probe
        new RemoteShellResult(exitCode: 0, stdout: '/usr/bin/supervisorctl', stderr: '', durationMs: 1),
    ]);

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeThrowingSiteCertificateInstaller)->handle($app);

    // Per the documented warning shape (warning_codes.php registry), the
    // tls_certificate_missing code must carry the process family and the
    // doctor next-command pointer. The installer threw on the main context,
    // so per-process install scripts must not have been issued for that
    // context.
    expect($warnings)->not->toBeEmpty()
        ->and($warnings[0])->toMatchArray([
            'code' => 'process.tls_certificate_missing',
            'family' => 'process',
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and(collect($remoteShell->scripts)->contains(fn (string $script): bool => str_contains($script, '/etc/supervisor/conf.d/orbit_docs_main_watch.conf')))->toBeFalse();
});

it('does not probe supervisor when an app has no process definitions', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
    ]);
    $app->setRelation('node', $node);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

    expect($warnings)->toBe([])
        ->and($remoteShell->scripts)->toBe([]);
});

describe('runtime dispatcher', function (): void {
    it('does not install Supervisor config for a docker-runtime process and instead renders the Docker container', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'user' => 'orbit',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::query()->create([
            'app_id' => $app->id,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            // proactive supervisor cleanup before docker apply
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            // docker network inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
            // docker network create
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            // docker container inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
            // docker run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'supervisorctl version')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, '/etc/supervisor/conf.d/orbit_docs_main_queue.conf') && str_contains($s, 'printf %s')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker create')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'orbit_docs_main_queue')))->toBeTrue()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, "--entrypoint 'sh'")))->toBeTrue();
    });

    it('still installs Supervisor config for a supervisor-runtime process on a static app', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'runtime_kind' => AppRuntimeKind::Static,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::query()->create([
            'app_id' => $app->id,
            'name' => 'watch',
            'command' => './watch.sh',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Supervisor,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/bin/supervisorctl', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker run -d') || str_contains($s, 'docker create')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker network')))->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, '/etc/supervisor/conf.d/orbit_marketing_main_watch.conf')))->toBeTrue();
    });

    it('tears down the old runtime artifact when a process flips runtimes (docker -> supervisor)', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $app->setRelation('node', $node);

        // Process is currently marked supervisor (the flipped-to runtime).
        // Convergence must remove the prior Docker container by the shared
        // unit identity before installing the Supervisor program.
        OrbitProcess::query()->create([
            'app_id' => $app->id,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Supervisor,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/bin/supervisorctl', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and($remoteShell->scripts[1])->toContain("docker rm -f 'orbit_docs_main_queue' 2>/dev/null || true")
            ->and($remoteShell->scripts[1])->toContain('/etc/supervisor/conf.d/orbit_docs_main_queue.conf');
    });

    it('tears down the old supervisor program when a process flips runtimes (supervisor -> docker)', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
            'user' => 'orbit',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime_kind' => AppRuntimeKind::Php,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::query()->create([
            'app_id' => $app->id,
            'name' => 'queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Docker,
            'sort_order' => 1,
        ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            // supervisor cleanup (proactive)
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            // docker network inspect → present
            new RemoteShellResult(exitCode: 0, stdout: '[]', stderr: '', durationMs: 1),
            // docker container inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
            // docker run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle($app);

        expect($warnings)->toBe([])
            ->and($remoteShell->scripts[0])->toContain('sudo rm -f /etc/supervisor/conf.d/orbit_docs_main_queue.conf')
            ->and($remoteShell->scripts[0])->toContain("sudo supervisorctl remove 'orbit_docs_main_queue'")
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker create')))->toBeTrue();
    });
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
            'cert' => "/home/{$node->user}/.config/orbit/certs/{$host}.crt",
            'key' => "/home/{$node->user}/.config/orbit/certs/{$host}.key",
        ];
    }
}

final class ProcessRuntimeThrowingSiteCertificateInstaller implements SiteCertificateInstaller
{
    public function ensureFor(Node $node, string $host): array
    {
        throw new RuntimeException("Refused to install TLS certificate for {$host}.");
    }

    public function expectedPathsFor(Node $node, string $host): array
    {
        return [
            'cert' => "/home/{$node->user}/.config/orbit/certs/{$host}.crt",
            'key' => "/home/{$node->user}/.config/orbit/certs/{$host}.key",
        ];
    }
}
