<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use App\Models\Workspace;
use App\Services\Processes\SystemdUnitRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeEnsureRuntimeUnitsAction(
    RemoteShell $remoteShell,
    SiteCertificateInstaller $certificates,
): EnsureAppProcessRuntimeUnits {
    app()->instance(RemoteShell::class, $remoteShell);
    app()->instance(SiteCertificateInstaller::class, $certificates);

    return app(EnsureAppProcessRuntimeUnits::class);
}

it('renders and enacts systemd units for app process definitions', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'runtime' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    $process = OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 1,
        ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => false,
                'hash' => null,
                'enabled' => false,
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    $certificates = new ProcessRuntimeRecordingSiteCertificateInstaller;
    $unitContent = app(SystemdUnitRenderer::class)->render($node, $app, $process);

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, $certificates)->handle($app);

    expect($warnings)
        ->toBe([])
        ->and($remoteShell->scripts)
        ->toHaveCount(1)
        ->and($remoteShell->scripts[0])
        ->toContain("internal:process-systemd-service 'apply' 'orbit_docs_development_main_vite.service'")
        ->and($certificates->hosts)
        ->toBe(['docs.test'])
        ->and($remoteShell->scripts[0])
        ->toContain('--json');
});

it('reports process family warnings when systemd unit enactment fails after intent exists', function (): void {
    $node = createTestAppHostNode([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);

    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'runtime' => AppRuntimeKind::Static,
    ]);
    $app->setRelation('node', $node);

    OrbitProcess::factory()
        ->forOwner($app)
        ->create([
            'name' => 'worker',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'always',
            'crash_notification' => 'none',
            'runtime' => ProcessRuntime::Systemd,
            'sort_order' => 1,
        ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell([
        new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'exists' => false,
                'hash' => null,
                'enabled' => false,
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        ),
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'systemctl failed', durationMs: 1),
    ]);

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle(
        $app,
    );

    expect($warnings)
        ->toHaveCount(1)
        ->and($warnings[0])
        ->toMatchArray([
            'code' => 'process.runtime_unit_missing',
            'family' => 'process',
            'next_command' => 'doctor --family=process --restore',
        ])
        ->and($remoteShell->scripts)
        ->toHaveCount(1);
});

it(
    'reports process.tls_certificate_missing when the site certificate installer throws and still continues to the next workspace context',
    function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'runtime' => AppRuntimeKind::Static,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'name' => 'watch',
                'command' => './watch.sh',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime' => ProcessRuntime::Systemd,
                'sort_order' => 1,
            ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell;

        $warnings = makeEnsureRuntimeUnitsAction(
            $remoteShell,
            new ProcessRuntimeThrowingSiteCertificateInstaller,
        )->handle($app);

        // Per the documented warning shape (warning_codes.php registry), the
        // tls_certificate_missing code must carry the process family and the
        // doctor next-command pointer. The installer threw on the main context,
        // so per-process install scripts must not have been issued for that
        // context.
        expect($warnings)
            ->not
            ->toBeEmpty()
            ->and($warnings[0])
            ->toMatchArray([
                'code' => 'process.tls_certificate_missing',
                'family' => 'process',
                'next_command' => 'doctor --family=process --restore',
            ])
            ->and($remoteShell->scripts)
            ->toBe([]);
    },
);

it('does not enact runtime units when an app has no process definitions', function (): void {
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
    $appInstance = AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
    ]);

    $remoteShell = new ProcessRuntimeRecordingRemoteShell;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, new ProcessRuntimeRecordingSiteCertificateInstaller)->handle(
        $app,
        $appInstance,
    );

    expect($warnings)->toBe([])->and($remoteShell->scripts)->toBe([]);
});

it('does not reenact workspace runtime units for app-prod targets', function (): void {
    $node = Node::factory()
        ->appProd()
        ->create([
            'name' => 'app-prod-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
        'runtime' => AppRuntimeKind::Static,
    ]);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
    ]);
    Workspace::factory()->for($app)->create([
        'app_instance_id' => $instance->id,
        'name' => 'legacy-workspace',
    ]);
    OrbitProcess::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'vite',
            'runtime' => ProcessRuntime::Systemd,
        ]);
    $remoteShell = new ProcessRuntimeRecordingRemoteShell;
    $certificates = new ProcessRuntimeRecordingSiteCertificateInstaller;

    $warnings = makeEnsureRuntimeUnitsAction($remoteShell, $certificates)->handle($app, $instance);

    expect($warnings)
        ->toBe([])
        ->and($remoteShell->scripts)
        ->toHaveCount(1)
        ->and($remoteShell->scripts[0])
        ->toContain('orbit_docs_production_main_vite.service')
        ->not
        ->toContain('legacy-workspace')
        ->and($certificates->hosts)
        ->toHaveCount(1);
});

describe('runtime dispatcher', function (): void {
    it('does not install systemd units for a docker-runtime process and instead renders the Docker container', function (): void {
        $node = createTestAppHostNode([
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
            'runtime' => AppRuntimeKind::Php,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime' => ProcessRuntime::Docker,
                'sort_order' => 1,
            ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            // docker network inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
            // docker network create
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            // docker container inspect → missing
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container', durationMs: 1),
            // docker run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction(
            $remoteShell,
            new ProcessRuntimeRecordingSiteCertificateInstaller,
        )->handle($app);

        expect($warnings)
            ->toBe([])
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $s): bool => str_contains($s, 'systemctl enable')))
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)
                ->contains(
                    fn (string $s): bool => str_contains(
                        $s,
                        '/etc/systemd/system/orbit_docs_development_main_queue.service',
                    ),
                ))
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $s): bool => str_contains($s, 'internal:process-docker-container')))
            ->toBeTrue()
            ->and(collect($remoteShell->scripts)
                ->contains(fn (string $s): bool => str_contains($s, '--json')))
            ->toBeTrue();
    });

    it('installs systemd units for a systemd-runtime process on a static app', function (): void {
        $node = createTestAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);

        $app = App::factory()->create([
            'name' => 'marketing',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/marketing',
            'runtime' => AppRuntimeKind::Static,
        ]);
        $app->setRelation('node', $node);

        OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'name' => 'watch',
                'command' => './watch.sh',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime' => ProcessRuntime::Systemd,
                'sort_order' => 1,
            ]);

        $remoteShell = new ProcessRuntimeRecordingRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'exists' => false,
                    'hash' => null,
                    'enabled' => false,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);

        $warnings = makeEnsureRuntimeUnitsAction(
            $remoteShell,
            new ProcessRuntimeRecordingSiteCertificateInstaller,
        )->handle($app);

        expect($warnings)
            ->toBe([])
            ->and(
                collect($remoteShell->scripts)
                    ->contains(
                        fn (string $s): bool => str_contains($s, 'docker run -d') || str_contains($s, 'docker create'),
                    ),
            )
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)->contains(fn (string $s): bool => str_contains($s, 'docker network')))
            ->toBeFalse()
            ->and(collect($remoteShell->scripts)
                ->contains(
                    fn (string $s): bool => str_contains(
                        $s,
                        "internal:process-systemd-service 'apply' 'orbit_marketing_development_main_watch.service'",
                    ),
                ))
            ->toBeTrue();
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

        if (str_contains($script, 'internal:process-systemd-service')) {
            return $this->internalProcessResult([
                'status' => 'ok',
                'summary' => 'Applied systemd service.',
            ]);
        }

        if (str_contains($script, 'internal:process-docker-container')) {
            return $this->internalSuccessResult([
                'outcome' => 'created',
            ]);
        }

        return (
            array_shift($this->results) ?? new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            )
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function internalProcessResult(array $data): RemoteShellResult
    {
        foreach ($this->results as $index => $result) {
            if ($result->exitCode === 0) {
                continue;
            }

            array_splice($this->results, 0, $index + 1);

            return $result;
        }

        if ($this->results !== []) {
            array_shift($this->results);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR)
                ."\n",
            stderr: '',
            durationMs: 1,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function internalSuccessResult(array $data): RemoteShellResult
    {
        if ($this->results !== []) {
            array_shift($this->results);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => $data,
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR)
                ."\n",
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
