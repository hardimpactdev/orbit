<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\RemoteShellFailed;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Models\SchedulerState;
use App\Models\WireGuardPeer;
use App\Models\Workspace;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Dns\DnsmasqConfigBuilder;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Updates\UnattendedUpgradesAptConfig;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, ExplicitRemoteShellFallback::REQUIRED);

    $developmentDnsConfigDir = storage_path('framework/testing/doctor-runner-dns/'.bin2hex(random_bytes(6)));
    $developmentDnsMappingEnactor = new DevelopmentDnsMappingEnactor($developmentDnsConfigDir);

    app()->instance(DevelopmentDnsMappingEnactor::class, $developmentDnsMappingEnactor);
    app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($developmentDnsMappingEnactor));
    app()->bind(
        RemoteLocalExecutor::class,
        fn (): RemoteLocalExecutor => doctorRunnerLocalExecutor(app(RemoteShell::class)),
    );
    app()->bind(
        \App\Services\Apps\AppRuntimeContainerManager::class,
        fn (): \App\Services\Apps\AppRuntimeContainerManager => new \App\Services\Apps\AppRuntimeContainerManager(
            app(RemoteShell::class),
            app(\App\Services\Runtime\DockerCommandBuilder::class),
            doctor_runner_fake_ca(),
        ),
    );
    app()->bind(
        \App\Services\Workspaces\WorkspaceRuntimeContainerManager::class,
        fn (): \App\Services\Workspaces\WorkspaceRuntimeContainerManager => new \App\Services\Workspaces\WorkspaceRuntimeContainerManager(
            app(RemoteShell::class),
            app(\App\Services\Runtime\DockerCommandBuilder::class),
            doctor_runner_fake_ca(),
        ),
    );

    Http::fake(function (Request $request): mixed {
        if (! str_ends_with($request->url(), ':9477/v1/commands')) {
            return Http::response([], 404);
        }

        $commandName = doctorRunnerAgentPushCommandName($request);
        if (doctorRunnerSynthesizesInternalCommand($commandName)) {
            return doctorRunnerAgentPushResponse($request, new RemoteShellResult(
                exitCode: 0,
                stdout: '',
                stderr: '',
                durationMs: 1,
            ));
        }

        $node = doctorRunnerNodeForAgentRequest($request);
        $result = app(RemoteShell::class)->run($node, doctorRunnerAgentPushScript($request));

        if ($commandName === 'internal:app-runtime-container') {
            return doctorRunnerAgentPushResponse(
                $request,
                doctor_runner_app_runtime_container_result($request, $result),
            );
        }

        return doctorRunnerAgentPushResponse($request, $result);
    });
});

afterEach(function (): void {
    File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());
});

function createDoctorRunnerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'status' => 'active',
        'orbit_agent_capable' => true,
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function markDoctorRunnerNodeSecurityBaselineClean(Node $node): void
{
    $node->forceFill([
        'user' => 'orbit',
        'host_key_type' => 'ed25519',
        'host_key_public' => 'ssh-ed25519 AAAATEST',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ])->save();

    foreach (['v4', 'v6'] as $addressFamily) {
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => "orbit-public-ssh-deny-{$addressFamily}",
            'direction' => 'incoming',
            'action' => 'deny',
            'source' => 'any',
            'port' => '22',
            'protocol' => 'tcp',
            'source_hash' => hash('sha256', "orbit-public-ssh-deny-{$node->id}-{$addressFamily}"),
            'address_family' => $addressFamily,
            'interface' => 'public',
            'owner' => 'node-security',
            'protected' => true,
        ]);
    }
}

function createDoctorRunnerUpdateGateway(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'updates-gateway',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'user' => 'orbit',
        'orbit_agent_capable' => true,
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'status' => 'active',
        'settings' => [],
    ]);

    markDoctorRunnerNodeSecurityBaselineClean($node);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function doctorRunnerUpdateProbeResult(array $overrides = []): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'installed' => true,
            'auto_exists' => true,
            'unattended_exists' => true,
            'auto_hash_ok' => true,
            'unattended_hash_ok' => true,
            'dry_run_exit' => 0,
            'last_run_status' => 'completed',
            'reboot_required' => false,
            'reboot_required_packages' => [],
            ...$overrides,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

function doctorRunnerManagedFileProbeResult(bool $exists, ?string $hash = null, ?string $mode = null): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'exists' => $exists,
            'hash' => $hash,
            'mode' => $mode,
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 1,
    );
}

function fakeDoctorRunnerSchedulerSwarmService(string $replicas = '1/1', ?string $image = null): void
{
    $image ??= 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    Process::preventStrayProcesses();
    Process::fake([
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
            output: "{$image}\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(
            output: "{$replicas}\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);
}

describe('DoctorReportRunner app family extra container detection', function (): void {
    it(
        'emits app.runtime_container_extra when the node has an orbit-owned app runtime container without a matching active app record',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n",
                    stderr: '',
                    durationMs: 1,
                ),
                new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            expect($report['healthy'])
                ->toBeFalse()
                ->and(collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra'))
                ->toMatchArray([
                    'family' => 'app',
                    'node' => 'app-1',
                    'key' => 'app.runtime_container_extra',
                    'kind' => 'extra',
                ])
                ->and(collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra')['detail'])
                ->toMatchArray([
                    'app' => 'orphan-docs',
                    'container' => 'orbit-app-orphan-docs',
                ]);
        },
    );

    it('ignores containers whose orbit.app slug maps to an active app record on the node', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "docs\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t1\t0\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-docs\tdocs\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())->not->toContain('app.runtime_container_extra');
    });

    it('removes the orphan app runtime container under restore mode via the apps fixer', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $inspectPayload = json_encode([
            'State' => ['Running' => true],
            'Config' => ['Labels' => []],
        ], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_container_extra',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($report['actions'][0]['details'])
            ->toMatchArray([
                'app' => 'orphan-docs',
                'container' => 'orbit-app-orphan-docs',
                'outcome' => 'removed',
            ])
            ->and(collect($shell->scripts)
                ->contains(fn (string $s): bool => str_contains($s, "docker rm -f 'orbit-app-orphan-docs'")))
            ->toBeTrue();
    });

    it('records a failure action when removal of the extra app runtime container fails', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $inspectPayload = json_encode([
            'State' => ['Running' => true],
            'Config' => ['Labels' => []],
        ], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'container in use', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        expect($report['healthy'])
            ->toBeFalse()
            ->and(collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra'))
            ->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_container_extra',
                'mode' => 'restore',
                'status' => 'failed',
            ]);
    });

    it('emits app.runtime_config_extra when an orphan user config runtime ini exists without an active app record', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:present\n/home/orbit/.config/orbit/apps/orphan-docs.ini\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_extra');
        expect($issue)
            ->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_config_extra',
                'kind' => 'extra',
            ])
            ->and($issue['detail'])
            ->toMatchArray([
                'app' => 'orphan-docs',
                'path' => '/home/orbit/.config/orbit/apps/orphan-docs.ini',
            ]);
    });

    it('removes the orphan managed runtime config under restore mode via the apps fixer', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:present\n/home/orbit/.config/orbit/apps/orphan-docs.ini\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-config-probe:present\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-config-probe:absent\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_extra');
        expect($action)
            ->toMatchArray([
                'family' => 'app',
                'node' => 'app-1',
                'key' => 'app.runtime_config_extra',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($action['details'])
            ->toMatchArray([
                'app' => 'orphan-docs',
                'path' => '/home/orbit/.config/orbit/apps/orphan-docs.ini',
                'outcome' => 'removed',
            ])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $s): bool => str_contains($s, "rm -f '/home/orbit/.config/orbit/apps/orphan-docs.ini'"),
                ))
            ->toBeTrue();
    });

    it(
        'records a failed action when the runtime config probe fails for an unknown reason during app.runtime_config_extra restore',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-config-dir:present\n/home/orbit/.config/orbit/apps/orphan-docs.ini\n",
                    stderr: '',
                    durationMs: 1,
                ),
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-container-config-probe:error\n",
                    stderr: 'sudo: no tty present',
                    durationMs: 1,
                ),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

            $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_extra');

            expect($action)
                ->not
                ->toBeNull()
                ->and($action['status'])
                ->toBe('failed')
                ->and($action['details']['app'])
                ->toBe('orphan-docs');
        },
    );

    it(
        'records a failed action when the docker inspect probe fails for an unknown reason during app.runtime_container_extra restore',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-container-scan:present\norbit-app-orphan-docs\torphan-docs\n",
                    stderr: '',
                    durationMs: 1,
                ),
                new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
                new RemoteShellResult(
                    exitCode: 1,
                    stdout: '',
                    stderr: 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock.',
                    durationMs: 1,
                ),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

            $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra');

            expect($action)
                ->not
                ->toBeNull()
                ->and($action['status'])
                ->toBe('failed')
                ->and($action['details']['app'])
                ->toBe('orphan-docs');
        },
    );

    it('emits app.runtime_container_missing when an active PHP app has a stopped FrankenPHP runtime container', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // Per-app introspect output: path/root present, docker available,
        // container exists + matches spec, container_running=false, runtime
        // config present and matches.
        $expectedSpecHash = hash('sha256', '');

        $perAppStdout = "docs\t1\t1\t1\t1\t1\t1\t0\t1\t1\t1\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);
        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_missing');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['kind'])
            ->toBe('missing')
            ->and($issue['detail']['reason'] ?? null)
            ->toBe('container_stopped')
            ->and($issue['detail']['expected'] ?? null)
            ->toBe('orbit-app-docs');
    });

    it('restarts a stopped runtime container via restore mode for an active PHP app', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        $perAppStdout = "docs\t1\t1\t1\t1\t1\t1\t0\t1\t1\t1\t1\t1\t0\n";

        $stoppedInspect = json_encode([
            'State' => ['Running' => false],
            'Config' => ['Labels' => []],
        ], JSON_THROW_ON_ERROR);

        $shell = new DoctorReportRunnerRemoteShell([
            // probe: per-app introspect, then node-level docker ls + ini find
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: manager.apply() network inspect → container inspect → image inspect → rm + run
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $stoppedInspect, stderr: '', durationMs: 1),
            // image inspect ok
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            // manager runs docker rm because labels don't match the expected spec hash (Labels=[])
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_missing');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('completed')
            ->and(
                collect($shell->scripts)
                    ->contains(
                        fn (string $script): bool => doctor_runner_script_creates_trusted_runtime(
                            script: $script,
                            containerName: 'orbit-app-docs',
                        ),
                    ),
            )
            ->toBeTrue();
    });

    it('reports app.runtime_container_extra for an active static app whose stale FrankenPHP container still exists', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()
            ->static()
            ->create([
                'name' => 'marketing',
                'node_id' => $node->id,
                'path' => '/home/orbit/apps/marketing',
                'document_root' => 'public',
            ]);
        // Per-app probe runs against the static app, returns benign snapshot
        // (no PHP-app checks fire). Node-level container ls returns the stale
        // orbit-app-marketing container.
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-marketing\tmarketing\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_extra');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['detail'])
            ->toMatchArray([
                'app' => 'marketing',
                'container' => 'orbit-app-marketing',
            ]);
    });

    it('reports app.runtime_config_extra for an active static app whose stale managed runtime config still exists', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()
            ->static()
            ->create([
                'name' => 'marketing',
                'node_id' => $node->id,
                'path' => '/home/orbit/apps/marketing',
                'document_root' => 'public',
            ]);
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:present\n/home/orbit/.config/orbit/apps/marketing.ini\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_extra');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['detail'])
            ->toMatchArray([
                'app' => 'marketing',
                'path' => '/home/orbit/.config/orbit/apps/marketing.ini',
            ]);
    });

    it('removes a static-app stale FrankenPHP container under restore mode', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()
            ->static()
            ->create([
                'name' => 'marketing',
                'node_id' => $node->id,
                'path' => '/home/orbit/apps/marketing',
                'document_root' => 'public',
            ]);
        $perAppStdout = "marketing\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t1\t1\t0\n";
        $inspectPayload = json_encode([
            'State' => ['Running' => true],
            'Config' => ['Labels' => []],
        ], JSON_THROW_ON_ERROR);

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-marketing\tmarketing\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // fixer removeExtra: inspect succeeds, then docker rm
            new RemoteShellResult(exitCode: 0, stdout: $inspectPayload, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_extra');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('completed')
            ->and($action['details']['outcome'])
            ->toBe('removed')
            ->and(collect($shell->scripts)
                ->contains(fn (string $s): bool => str_contains($s, "docker rm -f 'orbit-app-marketing'")))
            ->toBeTrue();
    });

    it('emits app.php_version_unavailable when the selected FrankenPHP image is missing on the owning node', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // path=1, root=1, root_inside_path=1, docker_available=1,
        // container_exists=0, container_spec_matches=0, container_running=0,
        // system_user_exists=0, fs_permissions_ok=0,
        // runtime_config_exists=0, runtime_config_matches=0,
        // runtime_image_available=0 (the new failing signal)
        $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.php_version_unavailable');

        expect($issue)
            ->not->toBeNull()->and($issue['detail']['php_version'])->toBe('8.5')->and(
                $issue['detail']['expected_image'],
            )->toBe('ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm')->and(
                collect($report['issues'])->pluck('key')->all(),
            )
            ->not->toContain('app.runtime_container_missing')->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_container_mismatch');
    });

    it('does not mark app.php_version_unavailable as restorable in doctor restore mode', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.php_version_unavailable');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('skipped')
            ->and($action['details']['reason'] ?? null)
            ->toBe('mode_not_supported');
    });

    it(
        'maps unknown image-probe failure with no container to documented app.runtime_container_missing (NOT app.php_version_unavailable, NOT a new probe-failed key)',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            App::factory()->create([
                'name' => 'docs',
                'node_id' => $node->id,
                'path' => '/home/orbit/apps/docs',
                'document_root' => 'public',
                'php_version' => '8.5',
            ]);
            // runtime_image_available=0, runtime_image_probe_failed=1, container_exists=0:
            // probe column 14 (probe_failed) is 1 — must surface as the documented
            // restorable `app.runtime_container_missing`, NOT a new undocumented
            // `app.runtime_image_probe_failed` key.
            $perAppStdout = "docs\t1\t1\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t1\n";

            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
                new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_missing');

            expect($issue)
                ->not->toBeNull()->and($issue['kind'])->toBe('missing')->and(
                    $issue['restorable'] ?? false,
                )->toBeTrue()->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.php_version_unavailable')->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.runtime_image_probe_failed')->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.runtime_container_mismatch');
        },
    );

    it('maps unknown image-probe failure WITH an existing mismatched container to documented app.runtime_container_mismatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        // runtime_image_available=0, runtime_image_probe_failed=1, container_exists=1, container_spec_matches=0, container_running=1:
        // probe-failed must surface as the documented `app.runtime_container_mismatch`
        // so doctor restore can re-attempt apply via the manager.
        $perAppStdout = "docs\t1\t1\t1\t1\t1\t0\t1\t0\t0\t0\t0\t0\t1\n";

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $perAppStdout, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_mismatch');

        expect($issue)
            ->not->toBeNull()->and($issue['kind'])->toBe('divergent')->and(
                $issue['restorable'] ?? false,
            )->toBeTrue()->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.php_version_unavailable')->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_image_probe_failed')->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_container_missing');
    });

    it('restores mismatched app instance runtime containers on the selected instance node', function (): void {
        $beast = createDoctorRunnerAppHostNode(['name' => 'beast']);
        $nmbp = createDoctorRunnerAppHostNode([
            'name' => 'nmbp',
            'platform' => 'darwin',
            'user' => 'nckrtl',
            'tld' => 'nmbp',
        ]);
        $app = App::factory()->for($beast, 'node')->create([
            'name' => 'hauser',
            'path' => '/home/nckrtl/apps/hauser',
            'document_root' => 'public',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver' => AppInstanceDriver::Orbit,
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $nmbp->id,
                node: 'nmbp',
                path: '/Users/nckrtl/apps/hauser',
                document_root: 'public',
                domain: 'hauser.nmbp',
            ),
        ]);
        $staleContainer = json_encode([
            'State' => ['Running' => true, 'Status' => 'running'],
            'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => 'stale']],
        ], JSON_THROW_ON_ERROR);

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "hauser.nmbp\t1\t1\t1\t1\t1\t0\t1\t0\t0\t1\t1\t1\t0\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $staleContainer, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($nmbp, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_mismatch');

        expect($action)
            ->not
            ->toBeNull()
            ->toMatchArray([
                'family' => 'app',
                'node' => 'nmbp',
                'key' => 'app.runtime_container_mismatch',
                'status' => 'completed',
                'details' => [
                    'app' => 'hauser',
                    'app_instance' => 'nmbp',
                    'target' => 'hauser.nmbp',
                    'container' => 'orbit-app-hauser-nmbp',
                ],
            ])
            ->and(collect($shell->scripts)
                ->contains(fn (string $script): bool => str_contains($script, "'orbit-app-hauser-nmbp'")))
            ->toBeTrue();
    });

    it(
        'emits app.runtime_config_probe_failed when the runtime config directory probe fails for an unknown reason (does not silently hide orphan configs)',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            // No App rows — orphan scan would normally walk the directory. The
            // probe sentinel reports an unknown error, so doctor MUST surface a
            // dedicated probe-failed drift rather than treating it as a clean
            // empty snapshot.
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-config-dir:error sudo: a terminal is required to read the password\n",
                    stderr: '',
                    durationMs: 1,
                ),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_probe_failed');

            expect($issue)
                ->not->toBeNull()->and($issue['kind'])->toBe('unverifiable')->and($issue['detail']['path'])->toBe(
                    '/home/orbit/.config/orbit/apps',
                )->and($issue['detail']['error'])->toContain('terminal')->and($issue['restorable'] ?? false)->toBeTrue()
                // Must NOT silently absorb the error as a clean empty list.
                ->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.runtime_config_extra');
        },
    );

    it('does NOT emit app.runtime_config_probe_failed when the directory is proven absent (clean empty snapshot)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_config_probe_failed')->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_config_extra');
    });

    it('clears app.runtime_config_probe_failed under restore mode when re-probe succeeds', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: introspectNode absent (no docker orphan extras possible),
            // introspectNodeRuntimeConfigs error
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:error sudo: not allowed\n",
                stderr: '',
                durationMs: 1,
            ),
            // restore: re-probe now succeeds (absent — clean recovery)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('completed')
            ->and($action['details']['status'] ?? null)
            ->toBe('absent');
    });

    it(
        'emits app.runtime_config_probe_failed (NOT raises) when the runtime config directory probe shell returns a non-zero exit without a sentinel — SSH/transport flake must not abort the doctor run',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
                // Non-zero exit without a sentinel — mirrors SSH/transport flakes
                // and remote-shell construction errors that the previous
                // throw=>true path would have raised out of doctor.
                new RemoteShellResult(
                    exitCode: 255,
                    stdout: '',
                    stderr: 'ssh: connect to host: connection refused',
                    durationMs: 1,
                ),
            ]);
            app()->instance(RemoteShell::class, $shell);

            // Must not throw.
            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_config_probe_failed');

            expect($issue)
                ->not->toBeNull()->and($issue['kind'])->toBe('unverifiable')->and($issue['detail']['error'])->toContain(
                    'connection refused',
                )->and($issue['restorable'] ?? false)->toBeTrue()
                // Must NOT silently absorb the error as a clean empty list.
                ->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.runtime_config_extra');
        },
    );

    it('clears app.runtime_config_probe_failed on restore when an earlier non-zero remote shell recovers to absent', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: container scan absent, config scan throws (non-zero exit)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 255, stdout: '', stderr: 'remote shell pipeline broke', durationMs: 1),
            // restore: re-probe now succeeds with proven-absent directory.
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('completed')
            ->and($action['details']['status'] ?? null)
            ->toBe('absent');
    });

    it(
        'emits app.runtime_container_probe_failed (NOT raises) when the node-wide docker container scan fails for an unknown reason — does NOT abort the doctor run and does NOT hide stale extras',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            // No App rows. Container scan fails with daemon-down stderr; doctor
            // MUST surface a dedicated probe-failed drift rather than throwing
            // out of the run or treating the error as a clean empty snapshot.
            $shell = new DoctorReportRunnerRemoteShell([
                new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-container-scan:error Cannot connect to the Docker daemon at unix:///var/run/docker.sock\n",
                    stderr: '',
                    durationMs: 1,
                ),
                new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            ]);
            app()->instance(RemoteShell::class, $shell);

            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_probe_failed');

            expect($issue)
                ->not->toBeNull()->and($issue['kind'])->toBe('unverifiable')->and($issue['detail']['error'])->toContain(
                    'Cannot connect to the Docker daemon',
                )->and($issue['restorable'] ?? false)->toBeTrue()
                // Must NOT silently absorb the error as a clean empty list.
                ->and(collect($report['issues'])->pluck('key')->all())
                ->not->toContain('app.runtime_container_extra');
        },
    );

    it(
        'emits app.runtime_container_probe_failed when the remote shell call itself fails (SSH/transport error must not abort doctor)',
        function (): void {
            $node = createDoctorRunnerAppHostNode();
            $shell = new DoctorReportRunnerRemoteShell([
                // Non-zero exit without a sentinel — mirrors SSH transport flakes.
                new RemoteShellResult(
                    exitCode: 255,
                    stdout: '',
                    stderr: 'ssh: connect to host: connection refused',
                    durationMs: 1,
                ),
                new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            ]);
            app()->instance(RemoteShell::class, $shell);

            // Must not raise.
            $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

            $issue = collect($report['issues'])->firstWhere('key', 'app.runtime_container_probe_failed');

            expect($issue)
                ->not
                ->toBeNull()
                ->and($issue['kind'])
                ->toBe('unverifiable')
                ->and($issue['detail']['error'])
                ->toContain('connection refused')
                ->and($issue['restorable'] ?? false)
                ->toBeTrue();
        },
    );

    it('does NOT emit app.runtime_container_probe_failed when docker is proven absent on the node (clean empty snapshot)', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->probe($node, families: ['app']);

        expect(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_container_probe_failed')->and(collect($report['issues'])->pluck('key')->all())
            ->not->toContain('app.runtime_container_extra');
    });

    it('clears app.runtime_container_probe_failed under restore mode when the docker scan succeeds again', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            // probe: introspectNode error, introspectNodeRuntimeConfigs absent
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:error docker daemon flake\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: re-probe now succeeds (absent — no docker on node, clean recovery)
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('completed')
            ->and($action['details']['status'] ?? null)
            ->toBe('absent');
    });

    it('records a failed action when the runtime container scan still fails on restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:error daemon flake\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
            // restore: re-probe ALSO fails
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:error still flaking\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_container_probe_failed');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('failed')
            ->and($action['details']['error'] ?? '')
            ->toContain('still flaking');
    });

    it('records a failed action when the runtime config directory probe still fails on restore', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "orbit-container-scan:absent\n", stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:error sudo: not allowed\n",
                stderr: '',
                durationMs: 1,
            ),
            // restore: re-probe ALSO fails
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-config-dir:error sudo: still not allowed\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);

        $action = collect($report['actions'])->firstWhere('key', 'app.runtime_config_probe_failed');

        expect($action)
            ->not
            ->toBeNull()
            ->and($action['status'])
            ->toBe('failed')
            ->and($action['details']['error'] ?? '')
            ->toContain('not allowed');
    });
});

describe('DoctorReportRunner', function (): void {
    it('does not probe or fix workspace PHP-FPM pools for PHP apps because workspaces use Docker containers', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = App::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'name' => 'feature',
            'path' => '/home/orbit/apps/docs/.worktrees/feature',
        ]);
        $expectedHash = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace)->specHash();
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "feature\t1\t1\t1\t1\t1\t1\t0\t1\t1\t{$expectedHash}\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['workspace']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 0,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('key')->all())
            ->not->toContain('workspace.fpm_config_mismatch');
    });

    it('suppresses resolved issues when a supported restore completes', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        fakeDoctorRunnerSchedulerSwarmService(replicas: '0/1');

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'failed' => 0,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toBe([]);

        Process::assertRan(
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'",
        );
        Process::assertRan("docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'");
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('suppresses resolved scheduler image drift when restore updates the Swarm service image', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        $desiredImage = 'ghcr.io/hardimpactdev/orbit-gateway:1.2.4@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        app()->instance(RemoteShell::class, $shell);
        config()->set('orbit.updates.gateway_image', $desiredImage);
        Process::preventStrayProcesses();
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
                output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
            ),
            "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(
                output: "1/1\n",
            ),
            "docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'" =>
                Process::result(),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
        ]);

        SchedulerState::factory()->create([
            'node_id' => $gateway->id,
            'heartbeat_at' => now(),
            'registry_synced_at' => now(),
        ]);

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'failed' => 0,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_image_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toBe([]);

        Process::assertRan(
            "docker service update --detach=true --image '{$desiredImage}' --update-order 'stop-first' --update-failure-action rollback --update-monitor 60s 'orbit_orbit-scheduler'",
        );
        Process::assertRan("docker service scale --detach=true 'orbit_orbit-scheduler=1'");
    });

    it('installs missing tools through restore mode family dispatch', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $decoyNode = Node::factory()->appDev()->create(['name' => 'decoy-app']);
        NodeTool::factory()->create(['node_id' => $decoyNode->id, 'name' => 'composer']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create(['node_id' => $node->id, 'name' => 'composer']);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'name' => 'composer',
                    'installed' => true,
                    'path' => '/usr/local/bin/composer',
                    'version' => 'Composer version 2.8.0',
                    'state' => 'unknown',
                    'config_exists' => null,
                    'config_hash' => null,
                    'secret_exists' => null,
                    'secret_hash' => null,
                    'container_exists' => null,
                    'container_state' => null,
                    'container_spec_hash' => null,
                ], JSON_THROW_ON_ERROR)
                    ."\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.capability_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts)
            ->toHaveCount(3)
            ->and($shell->nodeNames[1])
            ->toBe('app-1')
            ->and($shell->scripts[1])
            ->toContain('composer-setup.php');
    });

    it('restores duplicate-name firewall rules on the scoped node', function (): void {
        $decoyNode = Node::factory()->appDev()->create(['name' => 'decoy-app', 'platform' => 'ubuntu']);
        $node = Node::factory()->appDev()->create(['name' => 'target-app', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create([
            'node_id' => $decoyNode->id,
            'name' => 'local-https',
        ]);
        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-https',
        ]);

        $missingFirewallStatus = <<<'TXT'
            Status: active

            To                         Action      From
            --                         ------      ----
            TXT;
        $restoredFirewallStatus = <<<'TXT'
            Status: active

            To                         Action      From
            --                         ------      ----
            [ 1] 443/tcp                    ALLOW IN    Anywhere                   # test firewall rule
            TXT;

        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $missingFirewallStatus, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $restoredFirewallStatus, stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['firewall_rule']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'firewall_rule',
                'node' => 'target-app',
                'key' => 'firewall_rule.rule_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->nodeNames[1])
            ->toBe('target-app');
    });

    it('restores agent tool proxy routes through restore mode family dispatch', function (): void {
        $node = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.6.0.11',
            'wireguard_address' => '10.6.0.11',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'agent',
            'status' => 'active',
            'settings' => ['tld' => 'agent'],
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'openclaw',
            'expected_state' => 'installed',
            'credentials' => ['fields' => ['url' => 'https://openclaw.agent']],
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'openclaw.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'source_hash' => str_repeat('b', 64),
            'config' => [
                'target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:9999'],
                'upstream' => 'http://127.0.0.1:9999',
                'owner_name' => 'openclaw',
            ],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "/usr/local/bin/openclaw\tOpenClaw 1.0\trunning\t\t\t\t\t\t\t\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);
        $route = ProxyRoute::query()->where('domain', 'openclaw.agent')->firstOrFail();

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'agent-1',
                'key' => 'tool.agent_route_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($route->config['upstream'])
            ->toBe('http://host.docker.internal:8080');
    });

    it('restores missing process runtime units through restore mode family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        \App\Models\Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'vite',
                'command' => 'npm run dev -- --host=0.0.0.0',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_main_vite\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
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
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[2])
            ->toContain('internal:process-systemd-service')
            ->and($shell->scripts[2])
            ->toContain('orbit_docs_main_vite.service');
    });

    it('restores missing process runtime units for the app named in the runtime unit', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $docs = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        $blog = App::factory()->for($node, 'node')->create([
            'name' => 'blog',
            'path' => '/home/orbit/apps/blog',
        ]);
        \App\Models\Process::factory()
            ->forOwner($docs)
            ->create([
                'name' => 'vp-dev',
                'command' => 'npm run docs',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        \App\Models\Process::factory()
            ->forOwner($blog)
            ->create([
                'name' => 'vp-dev',
                'command' => 'npm run blog',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_main_vp-dev\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_blog_main_vp-dev\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
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
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => ['app' => 'blog', 'process' => 'vp-dev'],
            ])
            ->and($shell->scripts[4])
            ->toContain('internal:process-systemd-service')
            ->and($shell->scripts[4])
            ->toContain('orbit_blog_main_vp-dev.service');
    });

    it('refreshes stale managed FrankenPHP app process intent during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $expectedHash = app(AppRuntimeContainerRenderer::class)->render($app)->specHash();
        $process = \App\Models\Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs',
                    'container_spec_hash' => 'stale',
                    'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                ],
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => $expectedHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['outcome' => 'unchanged'], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => $expectedHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            \App\Services\Apps\AppRuntimeContainerManager::class,
            new \App\Services\Apps\AppRuntimeContainerManager(
                app(RemoteShell::class),
                app(\App\Services\Runtime\DockerCommandBuilder::class),
                doctor_runner_fake_ca(),
            ),
        );

        doctor_runner_expect_app_runtime_outcomes('unchanged');

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'docs',
                    'process' => 'frankenphp-docs',
                    'container' => 'orbit-app-docs',
                    'outcome' => 'unchanged',
                ],
            ])
            ->and($report['healthy'])
            ->toBeTrue()
            ->and($process->refresh()->runtime_config)
            ->toMatchArray([
                'container_name' => 'orbit-app-docs',
                'container_spec_hash' => $expectedHash,
                'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                'php_ini_path' => '/home/orbit/.config/orbit/apps/docs.ini',
            ]);
    });

    it('reapplies stale managed FrankenPHP app runtime containers during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $expectedHash = app(AppRuntimeContainerRenderer::class)->render($app)->specHash();
        \App\Models\Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs',
                    'container_spec_hash' => $expectedHash,
                    'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                ],
            ]);
        $staleContainer = json_encode([
            'State' => ['Running' => true, 'Status' => 'running'],
            'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => 'stale']],
        ], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $staleContainer, stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['outcome' => 'recreated'], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $staleContainer, stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            \App\Services\Apps\AppRuntimeContainerManager::class,
            new \App\Services\Apps\AppRuntimeContainerManager(
                app(RemoteShell::class),
                app(\App\Services\Runtime\DockerCommandBuilder::class),
                doctor_runner_fake_ca(),
            ),
        );

        doctor_runner_expect_app_runtime_outcomes('recreated');

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'docs',
                    'process' => 'frankenphp-docs',
                    'container' => 'orbit-app-docs',
                    'outcome' => 'recreated',
                ],
            ])
            ->and($report['healthy'])
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => doctor_runner_script_creates_runtime_container(
                        $script,
                        'orbit-app-docs',
                    ),
                ))
            ->toBeTrue()
            ->and(
                App::query()
                    ->where('name', 'docs')
                    ->first()
                    ?->processes()
                    ->first()
                    ?->runtime_config,
            )
            ->toMatchArray([
                'container_spec_hash' => $expectedHash,
            ]);
    });

    it('refreshes stale managed FrankenPHP workspace process intent during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $workspace = Workspace::factory()->for($app, 'app')->create([
            'name' => 'feature-a',
            'path' => '/home/orbit/apps/docs/.worktrees/feature-a',
            'php_version' => '8.5',
            'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        ]);
        $expectedHash = app(WorkspaceRuntimeContainerRenderer::class)->render($workspace)->specHash();
        $process = \App\Models\Process::factory()
            ->forOwner($workspace)
            ->create([
                'name' => 'frankenphp-docs-feature-a',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-ws-docs-feature-a',
                    'container_spec_hash' => 'stale',
                    'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                ],
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [WorkspaceRuntimeContainer::SpecHashLabel => $expectedHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['outcome' => 'unchanged'], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [WorkspaceRuntimeContainer::SpecHashLabel => $expectedHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            \App\Services\Workspaces\WorkspaceRuntimeContainerManager::class,
            new \App\Services\Workspaces\WorkspaceRuntimeContainerManager(
                app(RemoteShell::class),
                app(\App\Services\Runtime\DockerCommandBuilder::class),
                doctor_runner_fake_ca(),
            ),
        );

        doctor_runner_expect_app_runtime_outcomes('unchanged');

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'docs',
                    'workspace' => 'feature-a',
                    'process' => 'frankenphp-docs-feature-a',
                    'container' => 'orbit-ws-docs-feature-a',
                    'outcome' => 'unchanged',
                ],
            ])
            ->and($process->refresh()->runtime_config)
            ->toMatchArray([
                'container_name' => 'orbit-ws-docs-feature-a',
                'container_spec_hash' => $expectedHash,
                'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                'php_ini_path' => '/home/orbit/.config/orbit/workspaces/docs-feature-a.ini',
            ]);
    });

    it('restores divergent process event notifier material', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        \App\Models\Process::factory()
            ->forOwner($app)
            ->create([
                'name' => 'vite',
                'command' => 'npm run dev -- --host=0.0.0.0',
                'runtime' => ProcessRuntime::Systemd,
                'crash_notification' => 'agent_ide',
            ]);
        LocalGatewaySettings::query()->create([
            'gateway_url' => 'https://gateway.test',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_main_vite\t1\t1\t1\t1\n__notifier\t1\t1\t0\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.event_notifier_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'script' => '/usr/local/bin/orbit-notify-exit',
                    'gateway_endpoint' => '/etc/orbit/gateway-endpoint',
                ],
            ])
            ->and($shell->scripts[2])
            ->toContain('orbit-notify-exit')
            ->toContain('https://gateway.test');
    });

    it('restores missing PHP workspace runtime containers through workspace restore mode', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = App::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $workspace = Workspace::factory()->for($app, 'app')->create([
            'name' => 'feature-a',
            'path' => '/home/orbit/apps/docs/.worktrees/feature-a',
            'php_version' => '8.5',
            'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "feature-a\t1\t1\t1\t1\t1\t1\t0\t0\t0\t\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Error: No such container: orbit-ws-docs-feature-a',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'orbit-ws-docs-feature-a', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());

        doctor_runner_expect_app_runtime_outcomes('created');

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['workspace']);
        $action = collect($report['actions'])->first();

        expect($report['healthy'])
            ->toBeTrue()
            ->and($action)
            ->toMatchArray([
                'family' => 'workspace',
                'node' => 'app-1',
                'key' => 'workspace.runtime_container_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'docs',
                    'workspace' => 'feature-a',
                    'container' => 'orbit-ws-docs-feature-a',
                    'outcome' => 'created',
                ],
            ])
            ->and($workspace->processes()->where('name', 'frankenphp-docs-feature-a')->exists())
            ->toBeTrue()
            ->and(
                collect($shell->scripts)
                    ->contains(
                        fn (string $script): bool => doctor_runner_script_creates_runtime_container(
                            $script,
                            'orbit-ws-docs-feature-a',
                        ),
                    ),
            )
            ->toBeTrue();
    });

    it('restores missing PHP workspace runtime containers on the selected app instance node', function (): void {
        $canonicalNode = createDoctorRunnerAppHostNode([
            'name' => 'beast',
            'platform' => 'ubuntu_24-04',
        ]);
        $instanceNode = createDoctorRunnerAppHostNode([
            'name' => 'NMBP',
            'platform' => 'darwin',
            'user' => 'nckrtl',
        ]);
        $app = App::factory()->for($canonicalNode, 'node')->create([
            'name' => 'happie',
            'path' => '/home/nckrtl/apps/happie',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $instanceNode->id,
                node: 'NMBP',
                path: '/Users/nckrtl/apps/happie',
                domain: 'happie.nmbp',
            ),
        ]);
        $workspace = Workspace::factory()->for($app, 'app')->create([
            'app_instance_id' => $instance->id,
            'name' => 'recipes',
            'path' => '/Users/nckrtl/.codex/worktrees/a59f/happie',
            'php_version' => '8.5',
            'lifecycle_status' => WorkspaceLifecycleStatus::Active,
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "recipes\t1\t1\t1\t1\t1\t1\t0\t0\t0\t\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 1,
                stdout: '',
                stderr: 'Error: No such container: orbit-ws-happie-recipes',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'orbit-ws-happie-recipes', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(\App\Services\Ca\OrbitCaService::class, doctor_runner_fake_ca());

        doctor_runner_expect_app_runtime_outcomes('created');

        $report = app(DoctorReportRunner::class)->run($instanceNode, mode: 'restore', families: ['workspace']);
        $action = collect($report['actions'])->first();

        expect($report['healthy'])
            ->toBeTrue()
            ->and($action)
            ->toMatchArray([
                'family' => 'workspace',
                'node' => 'NMBP',
                'key' => 'workspace.runtime_container_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'happie',
                    'workspace' => 'recipes',
                    'container' => 'orbit-ws-happie-recipes',
                    'outcome' => 'created',
                ],
            ])
            ->and($workspace->processes()->where('name', 'frankenphp-happie-recipes')->exists())
            ->toBeTrue();

        expect($shell->nodeNames)->each->toBe('NMBP');

        expect(
            collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => doctor_runner_script_creates_runtime_container(
                        $script,
                        'orbit-ws-happie-recipes',
                    ),
                ),
        )
            ->toBeTrue();
    });

    it('restores missing node-owned process runtime units through restore mode family dispatch', function (): void {
        $node = Node::factory()
            ->database()
            ->create([
                'name' => 'metrics-worker-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'user' => 'orbit',
                'orbit_agent_capable' => true,
            ]);
        \App\Models\Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'node-exporter',
                'runtime' => ProcessRuntime::Systemd,
                'command' => 'node_exporter --web.listen-address=0.0.0.0:9100',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "node-exporter\t0\t0\t0\t0\n",
                stderr: '',
                durationMs: 1,
            ),
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
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'metrics-worker-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'metrics-worker-1',
                    'process' => 'node-exporter',
                    'runtime_unit' => 'node-exporter',
                ],
            ])
            ->and($shell->scripts[2])
            ->toContain('internal:process-systemd-service')
            ->and($shell->scripts[2])
            ->toContain('node-exporter.service');
    });

    it('restores missing node-owned docker swarm process runtime units through restore mode family dispatch', function (): void {
        $node = Node::factory()
            ->database()
            ->create([
                'name' => 'metrics-worker-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'user' => 'orbit',
            ]);
        \App\Models\Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'grafana',
                'runtime' => ProcessRuntime::DockerSwarm,
                'command' => 'grafana server --homepath=/usr/share/grafana',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime_config' => [
                    'service_name' => 'orbit-grafana',
                    'image' => 'grafana/grafana:12.0.1',
                    'labels' => [
                        'orbit.managed' => 'true',
                        'orbit.process' => 'grafana',
                        'orbit.process.spec_hash' => str_repeat('a', 64),
                    ],
                ],
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'no such service: orbit-grafana', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'metrics-worker-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'metrics-worker-1',
                    'process' => 'grafana',
                    'runtime_unit' => 'orbit-grafana',
                ],
            ])
            ->and($shell->scripts[2])
            ->toContain('internal:process-docker-swarm-service')
            ->and($shell->scripts[2])
            ->toContain('orbit-grafana');
    });

    it('rehydrates managed service runtime config for unrenderable node-owned Docker process units', function (): void {
        $node = Node::factory()
            ->database()
            ->create([
                'name' => 'database-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'user' => 'orbit',
                'wireguard_address' => '10.6.0.7',
            ]);
        $process = \App\Models\Process::factory()
            ->forOwner($node)
            ->create([
                'name' => 'redis',
                'runtime' => ProcessRuntime::Docker,
                'command' => 'redis-server --appendonly yes',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime_config' => [
                    'service' => 'redis',
                    'version_family' => '7',
                    'version' => '7.2',
                ],
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container: redis', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $probe = app(DoctorReportRunner::class)->probe($node, families: ['process']);

        expect($probe['issues'][0])->toMatchArray([
            'family' => 'process',
            'node' => 'database-1',
            'key' => 'process.runtime_unit_unrenderable',
            'restorable' => true,
        ]);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);
        $process->refresh();

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'database-1',
                'key' => 'process.runtime_unit_unrenderable',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'database-1',
                    'process' => 'redis',
                    'service' => 'redis',
                    'version' => '7.2',
                    'runtime' => 'docker',
                    'runtime_unit' => 'redis',
                ],
            ])
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'redis',
                'version_family' => '7',
                'version' => '7.2',
                'image' => 'redis:7.2',
                'service_name' => 'orbit-redis',
                'endpoint' => [
                    'name' => 'redis',
                    'kind' => 'tcp',
                    'host' => '10.6.0.7',
                    'port' => 6379,
                ],
            ]);

        $create = collect($shell->scripts)
            ->first(
                fn (string $script): bool => str_contains($script, 'internal:process-docker-container'),
            );

        expect($shell->scripts)
            ->toContain($create)
            ->and($create)
            ->toContain('internal:process-docker-container')
            ->and($create)
            ->not
            ->toContain('type=bind,source=/var/lib/orbit/processes/redis,target=/data')
            ->and($process->runtime_config)
            ->toHaveKey('service_name', 'orbit-redis');

        expect($shell->scripts)->not->toContain("sudo mkdir -p '/var/lib/orbit/processes/redis'");
    });

    it('restores missing and stopped orbit-caddy containers through restore mode family dispatch', function (
        string $issueKey,
        string $state,
        string $containerExists,
    ): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        $container = OrbitCaddyContainer::forPrivateNode('10.6.0.50');
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'caddy',
            'config' => ['container' => $container->spec()],
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "/usr/bin/docker\tDocker version 27.0.0\t{$state}\t\t\t\t\t{$containerExists}\t{$state}\t{$container->specHash()}\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "/usr/bin/docker\tDocker version 27.0.0\trunning\t\t\t\t\t1\trunning\t{$container->specHash()}\n",
                stderr: '',
                durationMs: 1,
            ),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => $issueKey,
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])
            ->toContain('internal:caddy-config')
            ->and($shell->scripts[1])
            ->toContain('apply-container')
            ->and($shell->scripts[1])
            ->toContain('--json');
    })->with([
        'missing container' => ['tool.container_missing', 'missing', '0'],
        'stopped container' => ['tool.container_not_running', 'stopped', '1'],
    ]);

    it('does not require gh on gateway-only no-source nodes', function (): void {
        $gateway = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-no-source',
                'status' => 'active',
            ]);

        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($gateway, families: ['tool']);

        $toolNames = collect($report['issues'])
            ->map(fn (array $issue): mixed => data_get($issue, 'detail.tool'))
            ->filter()
            ->values()
            ->all();

        expect($toolNames)->not->toContain('gh');
    });

    it('dispatches vpn dns client drift through gateway tool doctor scope', function (): void {
        $root = storage_path('framework/testing/doctor-runner-vpn-dns/'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root);
        config()->set('orbit.paths.config_root', $root);

        try {
            $gateway = Node::factory()
                ->gateway()
                ->create([
                    'name' => 'gateway-vpn-dns',
                    'status' => 'active',
                    'tld' => 'gateway',
                    'wireguard_address' => '10.6.0.2',
                ]);
            NodeRoleAssignment::factory()->create([
                'node_id' => $gateway->id,
                'role' => 'vpn',
                'status' => 'active',
                'settings' => [
                    'public_endpoint' => '203.0.113.10',
                    'dns_ip' => '10.6.0.1',
                ],
            ]);
            File::put($root.'/dnsmasq.conf', new DnsmasqConfigBuilder()->buildGatewayState());
            createDoctorRunnerWgEasyDnsDatabase($root.'/wg-easy/wg-easy.db', '["10.6.0.1"]', [
                ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
            ]);

            Process::fake(function ($process) {
                $command = (string) $process->command;

                if (str_contains($command, 'docker ps')) {
                    return Process::result('orbit-dns-id');
                }

                if (str_contains($command, 'docker exec')) {
                    return Process::result('udp 0 0 :::53 :::* LISTEN');
                }

                return Process::result();
            });

            $report = app(DoctorReportRunner::class)->probe($gateway, families: ['tool']);

            $issue = collect($report['issues'])
                ->first(fn (array $issue): bool => ($issue['key'] ?? null) === 'dns.client_dns_drift');

            expect($issue)
                ->not
                ->toBeNull()
                ->and($issue['family'])
                ->toBe('tool')
                ->and($issue['restorable'])
                ->toBeTrue()
                ->and($issue['detail']['expected_dns'])
                ->toBe('10.6.0.1');
        } finally {
            File::deleteDirectory($root);
        }
    });

    it('restores vpn dns client drift through gateway tool doctor scope', function (): void {
        $root = storage_path('framework/testing/doctor-runner-vpn-dns/'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($root);
        config()->set('orbit.paths.config_root', $root);

        try {
            $gateway = Node::factory()
                ->gateway()
                ->create([
                    'name' => 'gateway-vpn-dns-restore',
                    'status' => 'active',
                    'tld' => 'gateway',
                    'wireguard_address' => '10.6.0.2',
                ]);
            NodeRoleAssignment::factory()->create([
                'node_id' => $gateway->id,
                'role' => 'vpn',
                'status' => 'active',
                'settings' => [
                    'public_endpoint' => '203.0.113.10',
                    'dns_ip' => '10.6.0.1',
                ],
            ]);
            File::put($root.'/dnsmasq.conf', new DnsmasqConfigBuilder()->buildGatewayState());
            createDoctorRunnerWgEasyDnsDatabase($root.'/wg-easy/wg-easy.db', '["10.6.0.1","1.1.1.1"]', [
                ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
            ]);

            Process::fake(function ($process) {
                $command = (string) $process->command;

                if (str_contains($command, 'docker ps')) {
                    return Process::result('orbit-dns-id');
                }

                if (str_contains($command, 'docker exec')) {
                    return Process::result('udp 0 0 :::53 :::* LISTEN');
                }

                return Process::result();
            });

            $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['tool']);

            expect($report['healthy'])
                ->toBeTrue()
                ->and($report['summary'])
                ->toMatchArray([
                    'issues' => 0,
                    'fixed' => 1,
                    'skipped' => 0,
                ])
                ->and($report['actions'][0])
                ->toMatchArray([
                    'family' => 'tool',
                    'node' => 'gateway-vpn-dns-restore',
                    'key' => 'dns.client_dns_drift',
                    'mode' => 'restore',
                    'status' => 'completed',
                ])
                ->and(readDoctorRunnerWgEasyDnsRows($root.'/wg-easy/wg-easy.db'))
                ->toBe([
                    'default' => '["10.6.0.1"]',
                    'operator' => '["10.6.0.1"]',
                ]);
        } finally {
            File::deleteDirectory($root);
        }
    });

    it('suppresses resolved tool version issues when a safe update restore completes', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $node = createDoctorRunnerAppHostNode();
        NodeTool::factory()->create([
            'node_id' => $node->id,
            'name' => 'composer',
            'expected_version' => '3.0',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "/usr/local/bin/composer\tComposer version 2.8.0\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['tool']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'tool',
                'node' => 'app-1',
                'key' => 'tool.version_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($shell->scripts[1])
            ->toContain('composer self-update');
    });

    it('keeps the issue visible and records a failed action when Swarm scheduler restore fails', function (): void {
        $gateway = Node::factory()->gateway()->create(['name' => 'gateway-1', 'status' => 'active']);
        $shell = new DoctorReportRunnerRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);
        Process::preventStrayProcesses();
        Process::fake([
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-scheduler'" => Process::result(
                output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
            ),
            "docker service ls --filter 'name=orbit_orbit-scheduler' --format '{{.Replicas}}'" => Process::result(
                output: "0/1\n",
            ),
            "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(
                exitCode: 1,
                errorOutput: "scheduler scale failed\n",
            ),
        ]);

        $report = app(DoctorReportRunner::class)->run($gateway, mode: 'restore', families: ['schedule']);

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'failed' => 1,
                'skipped' => 0,
            ])
            ->and($report['issues'][0])
            ->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'schedule',
                'node' => 'gateway-1',
                'key' => 'schedule.scheduler_stopped',
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($report['actions'][0]['details']['error'])
            ->toContain('scheduler scale failed')
            ->and($shell->scripts)
            ->toBe([]);
    });

    it('restores supported node role baseline drift through the node converger', function (): void {
        File::deleteDirectory(app(DevelopmentDnsMappingEnactor::class)->configDir());

        $node = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => ['tld' => 'test'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_baseline_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and(app(DevelopmentDnsMappingEnactor::class)->configDir().'/test.conf')
            ->toBeFile();
    });

    it('skips unsupported node role drift during restore', function (): void {
        $node = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
        ]);
        WireGuardPeer::factory()->create([
            'node_id' => $node->id,
            'allowed_ips' => '10.6.0.5/32',
        ]);
        markDoctorRunnerNodeSecurityBaselineClean($node);

        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'app-dev',
            'status' => 'active',
            'settings' => [],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['node']);

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 1,
                'fixed' => 0,
                'skipped' => 1,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'app-1',
                'key' => 'node.role_settings_invalid',
                'mode' => 'restore',
                'status' => 'skipped',
            ]);
    });

    it('supports the database connection family on app nodes but not database-only nodes', function (): void {
        $appNode = createDoctorRunnerAppHostNode();
        $databaseNode = Node::factory()->database()->create(['status' => 'active']);

        $runner = app(DoctorReportRunner::class);

        expect($runner->supportedFamilies())
            ->toContain('database_connection')
            ->and($runner->categoriesForNode($appNode))
            ->toContain('database_connection')
            ->and($runner->categoriesForNode($databaseNode))
            ->not
            ->toContain('database_connection')
            ->and($runner->categoriesForNode($databaseNode))
            ->toContain('process');
    });

    it('supports the process family on every node with an active role assignment', function (string $role): void {
        $node = Node::factory()->create(['status' => 'active']);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $categories = app(DoctorReportRunner::class)->categoriesForNode($node);

        expect($categories)->toContain('process');
    })->with([
        'gateway' => ['gateway'],
        'vpn' => ['vpn'],
        'router' => ['router'],
        'app-dev' => ['app-dev'],
        'app-prod' => ['app-prod'],
        'database' => ['database'],
        'agent' => ['agent'],
        'ingress' => ['ingress'],
        'websocket' => ['websocket'],
        's3' => ['s3'],
        'metrics' => ['metrics'],
        'analytics' => ['analytics'],
    ]);

    it('does not support the process family on nodes without an active role assignment', function (): void {
        $node = Node::factory()->create(['status' => 'active']);

        $categories = app(DoctorReportRunner::class)->categoriesForNode($node);

        expect($categories)->toBe(['node']);
    });

    it('allows explicit process doctor scope on role-bearing nodes', function (): void {
        $node = Node::factory()->agent()->create(['status' => 'active']);

        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['process'],
            runner: app(DoctorReportRunner::class),
            target: $node,
        );

        expect($failure)->toBeNull();
    });

    it('does not mark database connection unverifiable issues as adoptable', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-unverifiable');
        File::ensureDirectoryExists($path);

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forApp($app)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'missing env', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($report['issues'])->firstWhere('key', 'database_connection.unverifiable');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['adoptable'] ?? null)
            ->toBeFalse();
    });

    it('restores database connection env drift through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forApp($app)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'fixed' => 2,
                'skipped' => 0,
            ])
            ->and(collect($report['actions'])->pluck('family')->unique()->all())
            ->toBe(['database_connection'])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains($script, 'internal:env-file'),
                ))
            ->toBeTrue();
    });

    it('restores missing database connection target mappings through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-target-missing');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                stderr: '',
                durationMs: 1,
            ),
        ]));

        $probe = app(DoctorReportRunner::class)->probe($node, ['database_connection']);
        $issue = collect($probe['issues'])->firstWhere('key', 'database_connection.target_missing');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['restorable'] ?? null)
            ->toBeTrue();

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and(
                DatabaseConnectionTarget::query()
                    ->where('database_connection_id', $connection->id)
                    ->where('app_id', $app->id)
                    ->where('env_prefix', 'DB')
                    ->exists(),
            )
            ->toBeTrue();
    });

    it('adopts database connection env state for registered apps through family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
                stderr: '',
                durationMs: 1,
            ),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'adopt',
            ])
            ->and(DatabaseConnection::query()->where('slug', 'docs')->exists())
            ->toBeTrue();
    });

    it('adopt mode updates gateway database connections from mismatched env without restoring env files', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-adopt-mismatch');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n",
        );

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'stored-host',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'stored-user',
            'credentials' => ['password' => 'stored-secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forApp($app)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        $original = File::get($path.'/.env');
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "DB_CONNECTION=mysql\nDB_HOST=observed-host\nDB_PORT=3306\nDB_DATABASE=docs_v2\nDB_USERNAME=observed-user\nDB_PASSWORD=observed-secret\n",
                stderr: '',
                durationMs: 1,
            ),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['database_connection']);

        $connection->refresh();

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['summary'])
            ->toMatchArray([
                'issues' => 0,
                'adopted' => 1,
                'skipped' => 0,
            ])
            ->and(File::get($path.'/.env'))
            ->toBe($original)
            ->and($connection)
            ->toMatchArray([
                'driver' => 'mysql',
                'host' => 'observed-host',
                'port' => 3306,
                'database' => 'docs_v2',
                'username' => 'observed-user',
            ])
            ->and($connection->credentials)
            ->toMatchArray(['password' => 'observed-secret']);
    });

    it('returns a failed action when database connection restore throws', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $path = storage_path('framework/testing/doctor-database-restore-failure');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $connection = DatabaseConnection::factory()->create([
            'slug' => 'docs',
            'driver' => 'pgsql',
            'host' => 'db.internal',
            'port' => 5432,
            'database' => 'docs',
            'username' => 'orbit',
            'credentials' => ['password' => 'secret'],
        ]);
        DatabaseConnectionTarget::factory()
            ->forApp($app)
            ->create([
                'database_connection_id' => $connection->id,
                'env_prefix' => 'DB',
            ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: "DB_CONNECTION=mysql\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'permission denied', durationMs: 1),
        ]));

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['database_connection']);
        $failedAction = collect($report['actions'])->firstWhere('status', 'failed');

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['summary']['failed'])
            ->toBeGreaterThanOrEqual(1)
            ->and($failedAction)
            ->toMatchArray([
                'family' => 'database_connection',
                'node' => 'app-1',
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['key'])
            ->toBeIn(['database_connection.env_missing', 'database_connection.env_mismatch'])
            ->and($failedAction)
            ->toMatchArray([
                'mode' => 'restore',
                'status' => 'failed',
            ])
            ->and($failedAction['details']['error'] ?? null)
            ->toContain('permission denied');
    });

    it('reports updates with the shared node updates key and specific issue code', function (): void {
        $node = createDoctorRunnerUpdateGateway();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            doctorRunnerUpdateProbeResult(['auto_hash_ok' => false]),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, ['node'], 'node.updates');

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['issues'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'restorable' => true,
            ]);
    });

    it('keeps updates reboot drift after restore re-probes a completed config action', function (): void {
        $node = createDoctorRunnerUpdateGateway();
        $updateConfig = new UnattendedUpgradesAptConfig;
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            doctorRunnerUpdateProbeResult(['auto_hash_ok' => false]),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            doctorRunnerManagedFileProbeResult(exists: true, hash: str_repeat('b', 64), mode: '0644'),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            doctorRunnerManagedFileProbeResult(
                exists: true,
                hash: $updateConfig->unattendedUpgradesSha256(),
                mode: '0644',
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'completed', stderr: '', durationMs: 1),
            doctorRunnerUpdateProbeResult(['reboot_required' => true]),
        ]));

        $report = app(DoctorReportRunner::class)->run(
            $node,
            mode: 'restore',
            families: ['node'],
            request: new DoctorRunRequest(key: 'node.updates'),
        );

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_config_mismatch',
                'mode' => 'restore',
                'status' => 'completed',
            ])
            ->and($report['issues'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'updates-gateway',
                'key' => 'node.updates',
                'code' => 'node.updates_reboot_required',
                'restorable' => false,
            ]);
    });
});

describe('DoctorReportRunner firewall categories', function (): void {
    it('includes firewall rules for active Ubuntu agent nodes', function (): void {
        $node = Node::factory()
            ->agent()
            ->create([
                'name' => 'agent-firewall-cat',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'wireguard_address' => '10.6.0.42',
            ]);

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)
            ->toContain('node')
            ->and($categories)
            ->toContain('tool')
            ->and($categories)
            ->toContain('process')
            ->and($categories)
            ->toContain('firewall_rule');
    });
});

// ---------------------------------------------------------------------------
// S3 role: category mapping + s3 probe dispatch
// ---------------------------------------------------------------------------

describe('DoctorReportRunner s3 role categories', function (): void {
    it('resolves s3 role to node, tool, and proxy categories', function (): void {
        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForRole('s3');

        expect($categories)->toBe(['node', 'tool', 'proxy']);
    });

    it('resolves s3 node to node, tool, and proxy categories when it has an active s3 role', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-node-cat',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.30',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)
            ->toContain('node')
            ->and($categories)
            ->toContain('tool')
            ->and($categories)
            ->toContain('proxy');
    });

    it('dispatches tool.seaweedfs.row_missing when an s3 node has no seaweedfs tool row', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.31',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        // No seaweedfs NodeTool row
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['tool']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('tool.seaweedfs.row_missing');
    });

    it('dispatches node.s3.wireguard_missing when an s3 node has no wireguard address', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-wg',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => null,
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => '/srv/orbit/s3/data'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['node']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('node.s3.wireguard_missing');
    });

    it('dispatches node.s3_data_path_invalid when an s3 node has a relative data_path setting', function (): void {
        $node = Node::factory()->create([
            'name' => 's3-disp-dp',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.32',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 's3',
            'status' => 'active',
            'settings' => ['data_path' => 'relative/invalid/path'],
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['node']);

        $keys = collect($report['issues'])->pluck('key')->all();
        expect($keys)->toContain('node.s3_data_path_invalid');
    });
});

describe('DoctorReportRunner metrics role categories', function (): void {
    it('resolves metrics role to node, tool, process, and proxy categories', function (): void {
        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForRole('metrics');

        expect($categories)->toBe(['node', 'tool', 'process', 'proxy']);
    });

    it('resolves a dedicated metrics node to node, tool, process, and proxy categories', function (): void {
        $node = Node::factory()->create([
            'name' => 'metrics-node-cat',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.60',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)
            ->toContain('node')
            ->and($categories)
            ->toContain('tool')
            ->and($categories)
            ->toContain('process')
            ->and($categories)
            ->toContain('proxy');
    });

    it('includes metrics categories when the metrics role is co-located with gateway', function (): void {
        $node = Node::factory()->create([
            'name' => 'gateway-metrics-node-cat',
            'status' => 'active',
            'platform' => 'debian_12',
            'wireguard_address' => '10.6.0.61',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'metrics',
            'status' => 'active',
        ]);
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);

        expect($categories)
            ->toContain('node')
            ->and($categories)
            ->toContain('schedule')
            ->and($categories)
            ->toContain('tool')
            ->and($categories)
            ->toContain('process')
            ->and($categories)
            ->toContain('proxy');
    });

    it('marks proxy.node_probe_failed as diagnostic-only without restore or adopt metadata', function (): void {
        $node = createDoctorRunnerAppHostNode(['name' => 'app-prod-1', 'platform' => 'ubuntu']);

        app()->instance(RemoteShell::class, new DoctorReportRunnerThrowingRemoteShell(
            failingNodeName: 'app-prod-1',
            failingScriptNeedle: '/etc/caddy/sites/*.caddy',
        ));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['proxy']);
        $issue = collect($report['issues'])->firstWhere('key', 'proxy.node_probe_failed');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['kind'])
            ->toBe('unverifiable')
            ->and($issue['restorable'] ?? null)
            ->toBeFalse()
            ->and($issue['adoptable'] ?? null)
            ->toBeFalse();
    });

    it('marks node.local_executor_probe_failed as diagnostic-only in fleet probe fallback', function (): void {
        request()->headers->remove(ExplicitRemoteShellFallback::HEADER);

        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-prod-1',
            'platform' => 'ubuntu',
            'wireguard_address' => null,
        ]);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'allow-https']);

        $report = app(DoctorReportRunner::class)->probeFleet(families: ['firewall_rule']);
        $issue = collect($report['issues'])->firstWhere('key', 'node.local_executor_probe_failed');

        expect($issue)
            ->not
            ->toBeNull()
            ->and($issue['kind'])
            ->toBe('unverifiable')
            ->and($issue['restorable'] ?? null)
            ->toBeFalse()
            ->and($issue['adoptable'] ?? null)
            ->toBeFalse();
    });
});

describe('DoctorReportRunner fleet probe batching', function (): void {
    it('preserves fleet node ordering in the final doctor envelope', function (): void {
        foreach (range(1, 3) as $index) {
            createDoctorRunnerAppHostNode(['name' => sprintf('fleet-order-%02d', $index)]);
        }

        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([]));

        $report = app(DoctorReportRunner::class)->probeFleet(families: ['node']);

        expect(collect($report['nodes'] ?? [])->pluck('node')->all())
            ->toBe(['fleet-order-01', 'fleet-order-02', 'fleet-order-03']);
    });

    it('runs fleet node probes sequentially when process workers are unavailable', function (): void {
        if (doctorRunnerFleetProbeProcessWorkersAvailable()) {
            test()->markTestSkipped(
                'This fallback contract is validated only when fleet process workers are unavailable.',
            );
        }

        foreach (range(1, 3) as $index) {
            createDoctorRunnerAppHostNode(['name' => "fleet-fallback-{$index}"]);
        }

        app()->instance(RemoteShell::class, new FleetProbeBatchDelayRemoteShell(delayMicroseconds: 10_000));

        $maxConcurrent = 0;
        $running = [];

        app(DoctorReportRunner::class)->probeFleet(
            families: ['node'],
            onNodeProgress: function (Node $node, string $phase) use (&$maxConcurrent, &$running): void {
                if ($phase === 'running') {
                    $running[$node->name] = true;
                    $maxConcurrent = max($maxConcurrent, count($running));

                    return;
                }

                unset($running[$node->name]);
            },
        );

        expect($maxConcurrent)->toBe(1);
    });
});

function doctorRunnerFleetProbeProcessWorkersAvailable(): bool
{
    if (! function_exists('proc_open')) {
        return false;
    }

    $database = config('database.connections.sqlite.database');

    return is_string($database) && $database !== '' && $database !== ':memory:';
}

final readonly class FleetProbeBatchDelayRemoteShell implements RemoteShell
{
    public function __construct(
        private int $delayMicroseconds = 10_000,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        usleep($this->delayMicroseconds);

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class DoctorReportRunnerThrowingRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $failingNodeName,
        private readonly string $failingScriptNeedle,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (
            $node->name === $this->failingNodeName
            && str_contains($script, $this->failingScriptNeedle)
            && ($options['throw'] ?? false) === true
        ) {
            $result = new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);

            throw new RemoteShellFailed($node, $script, $result);
        }

        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class DoctorReportRunnerRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @var list<string>
     */
    public array $nodeNames = [];

    /**
     * @param  list<RemoteShellResult|Throwable>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;
        $this->nodeNames[] = $node->name;
        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

function doctorRunnerLocalExecutor(RemoteShell $remoteShell): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        transport: new DoctorReportRunnerRemoteExecutor($remoteShell),
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: 'gateway-secret',
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: 'gateway-secret',
        defaultTransportPreference: NodeTransportPreference::AgentPush,
    );
}

final readonly class DoctorReportRunnerRemoteExecutor implements RemoteExecutor
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $commandName = doctorRunnerInternalCommandNameFromScript($script);

        if (doctorRunnerSynthesizesInternalCommand($commandName)) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: doctorRunnerInternalCommandStdout($commandName, [], new RemoteShellResult(
                    exitCode: 0,
                    stdout: '',
                    stderr: '',
                    durationMs: 1,
                )),
                stderr: '',
                durationMs: 1,
            );
        }

        $result = $this->remoteShell->run($node, $script, $options);

        if ($commandName === 'internal:app-runtime-container') {
            return doctor_runner_app_runtime_container_result_from_input(
                input: is_string($options['input'] ?? null) ? doctorRunnerDecodeInput($options['input']) : [],
                result: $result,
            );
        }

        if (! $result->successful() || $commandName === '') {
            return $result;
        }

        $input = is_string($options['input'] ?? null)
            ? doctorRunnerDecodeInput($options['input'])
            : [];

        return new RemoteShellResult(
            exitCode: $result->exitCode,
            stdout: doctorRunnerInternalCommandStdout($commandName, $input, $result),
            stderr: $result->stderr,
            durationMs: $result->durationMs,
        );
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('DoctorReportRunnerRemoteExecutor does not support start().');
    }
}

function doctorRunnerNodeForAgentRequest(Request $request): Node
{
    $host = parse_url($request->url(), PHP_URL_HOST);

    if (is_string($host)) {
        $node = Node::query()->where('wireguard_address', $host)->first();

        if ($node instanceof Node) {
            return $node;
        }
    }

    return new Node([
        'name' => is_string($host) ? $host : 'agent-target',
        'host' => is_string($host) ? $host : 'agent-target',
    ]);
}

function doctorRunnerAgentPushScript(Request $request): string
{
    if (doctorRunnerAgentPushCommandName($request) === 'internal:tool:run-script') {
        $input = doctorRunnerAgentPushInput($request);
        $script = $input['script'] ?? null;

        if (is_string($script)) {
            return $script;
        }
    }

    /** @var mixed $argv */
    $argv = $request['argv'] ?? [];

    if (! is_array($argv)) {
        return '/usr/local/bin/orbit';
    }

    return collect($argv)
        ->filter(static fn (mixed $argument): bool => is_string($argument))
        ->map(static fn (string $argument): string => escapeshellarg($argument))
        ->prepend('/usr/local/bin/orbit')
        ->implode(' ');
}

function doctor_runner_app_runtime_container_result(Request $request, RemoteShellResult $result): RemoteShellResult
{
    return doctor_runner_app_runtime_container_result_from_input(doctorRunnerAgentPushInput($request), $result);
}

/**
 * @param  array<string, mixed>  $input
 */
function doctor_runner_app_runtime_container_result_from_input(
    array $input,
    RemoteShellResult $result,
): RemoteShellResult {
    $spec = $input['spec'] ?? [];

    if (! is_array($spec)) {
        $spec = [];
    }

    $outcome = doctor_runner_app_runtime_container_outcome($spec, $result);

    return new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'outcome' => $outcome,
            'changed' => $outcome !== 'unchanged',
        ], JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: $result->durationMs,
    );
}

/**
 * @param  array<array-key, mixed>  $spec
 */
function doctor_runner_app_runtime_container_outcome(array $spec, RemoteShellResult $result): string
{
    $queuedOutcome = doctor_runner_next_app_runtime_outcome();

    if ($queuedOutcome !== null) {
        return $queuedOutcome;
    }

    if (! $result->successful()) {
        $output = $result->stderr.' '.$result->stdout;

        return preg_match('/No such (object|container)/i', $output) === 1 ? 'created' : 'created';
    }

    /** @var mixed $decoded */
    $decoded = json_decode(trim($result->stdout), associative: true);

    if (! is_array($decoded)) {
        return 'created';
    }

    $explicitOutcome = $decoded['outcome'] ?? null;

    if (is_string($explicitOutcome)) {
        return $explicitOutcome;
    }

    $hashLabel = ($spec['kind'] ?? null) === 'workspace'
        ? WorkspaceRuntimeContainer::SpecHashLabel
        : AppRuntimeContainer::SpecHashLabel;
    $expectedHash = $spec['expected_hash'] ?? null;
    $labels = data_get(target: $decoded, key: 'Config.Labels');
    $running = data_get(target: $decoded, key: 'State.Running') === true;

    if (! is_array($labels) || ! is_string($expectedHash)) {
        return 'created';
    }

    if (($labels[$hashLabel] ?? null) !== $expectedHash) {
        return 'recreated';
    }

    return $running ? 'unchanged' : 'started';
}

function doctor_runner_expect_app_runtime_outcomes(string ...$outcomes): void
{
    app()->instance('doctor-runner.app-runtime-outcomes', $outcomes);
}

function doctor_runner_next_app_runtime_outcome(): ?string
{
    if (! app()->bound('doctor-runner.app-runtime-outcomes')) {
        return null;
    }

    /** @var list<string> $outcomes */
    $outcomes = app('doctor-runner.app-runtime-outcomes');
    $outcome = array_shift($outcomes);
    app()->instance('doctor-runner.app-runtime-outcomes', $outcomes);

    return is_string($outcome) ? $outcome : null;
}

function doctorRunnerAgentPushResponse(Request $request, RemoteShellResult $result): mixed
{
    $stdout = $result->successful()
        ? doctorRunnerAgentPushStdout($request, $result)
        : $result->stdout;

    return Http::response([
        'transport' => 'agent-push',
        'operation_id' => is_string($request['operation_id'] ?? null) ? $request['operation_id'] : 'doctor-test',
        'binary' => 'orbit',
        'status' => $result->successful() ? 'succeeded' : 'failed',
        'exit_code' => $result->exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => $stdout,
            ],
            [
                'type' => 'stderr',
                'message' => $result->stderr,
            ],
            [
                'type' => 'exit',
                'message' => (string) $result->exitCode,
            ],
        ],
    ]);
}

function doctorRunnerAgentPushStdout(Request $request, RemoteShellResult $result): string
{
    $commandName = doctorRunnerAgentPushCommandName($request);

    if (! str_starts_with($commandName, 'internal:')) {
        return $result->stdout;
    }

    return doctorRunnerInternalCommandStdout($commandName, doctorRunnerAgentPushInput($request), $result);
}

/**
 * @param  array<string, mixed>  $input
 */
function doctorRunnerInternalCommandStdout(string $commandName, array $input, RemoteShellResult $result): string
{
    return json_encode([
        'success' => [
            'data' => doctorRunnerInternalCommandSuccessData($commandName, $input, $result),
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function doctorRunnerInternalCommandSuccessData(string $commandName, array $input, RemoteShellResult $result): array
{
    if ($commandName === 'internal:env-file') {
        return (
            ($input['action'] ?? null) === 'read'
                ? ['contents' => $result->stdout]
                : ['status' => 'ok']
        );
    }

    if ($commandName === 'internal:process-docker-container' && ($input['action'] ?? null) === 'apply') {
        return ['outcome' => 'created'];
    }

    if ($commandName === 'internal:process-systemd-service') {
        return ['status' => 'changed'];
    }

    if ($commandName === 'internal:agent-runtime:probe') {
        return [
            'runtime_user' => true,
            'orbit_cli' => true,
        ];
    }

    if ($commandName === 'internal:app-introspect:probe') {
        return [
            'snapshot' => doctor_runner_app_introspect_snapshot($result->stdout),
        ];
    }

    if ($commandName === 'internal:node-security-posture:probe') {
        return [
            'runtime_user' => true,
            'sshd_config' => true,
            'sshd_listen' => true,
            'sysctl' => true,
            'home_perms' => true,
        ];
    }

    if ($commandName === 'internal:tool:run-script') {
        return [
            'exit_code' => $result->exitCode,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'duration_ms' => $result->durationMs,
        ];
    }

    /** @var mixed $decoded */
    $decoded = json_decode(trim($result->stdout), associative: true);

    if (is_array($decoded)) {
        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                return ['stdout' => $result->stdout];
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    return ['stdout' => $result->stdout];
}

/**
 * @return array<string, mixed>
 */
function doctor_runner_app_introspect_snapshot(string $stdout): array
{
    $columns = explode("\t", trim($stdout));

    if (count($columns) !== 14) {
        return [];
    }

    return [
        'name' => $columns[0],
        'path_exists' => $columns[1] === '1',
        'root_exists' => $columns[2] === '1',
        'root_inside_path' => $columns[3] === '1',
        'docker_available' => $columns[4] === '1',
        'container_exists' => $columns[5] === '1',
        'container_spec_matches' => $columns[6] === '1',
        'container_running' => $columns[7] === '1',
        'system_user_exists' => $columns[8] === '1',
        'fs_permissions_ok' => $columns[9] === '1',
        'runtime_config_exists' => $columns[10] === '1',
        'runtime_config_matches' => $columns[11] === '1',
        'runtime_image_available' => $columns[12] === '1',
        'runtime_image_probe_failed' => $columns[13] === '1',
    ];
}

function doctorRunnerInternalCommandNameFromScript(string $script): string
{
    if (preg_match('/(?:^|\\s)\'?(internal:[a-z0-9:-]+)\'?(?:\\s|$)/', $script, $matches) !== 1) {
        return '';
    }

    return $matches[1];
}

function doctorRunnerSynthesizesInternalCommand(string $commandName): bool
{
    return in_array(
        $commandName,
        [
            'internal:agent-runtime:probe',
            'internal:node-security-posture:probe',
        ],
        true,
    );
}

function doctorRunnerAgentPushCommandName(Request $request): string
{
    /** @var mixed $argv */
    $argv = $request['argv'] ?? [];

    if (! is_array($argv)) {
        return '';
    }

    foreach ($argv as $argument) {
        if (is_string($argument) && str_starts_with($argument, 'internal:')) {
            return $argument;
        }
    }

    return '';
}

/**
 * @return array<string, mixed>
 */
function doctorRunnerAgentPushInput(Request $request): array
{
    $input = $request['input'] ?? null;

    if (! is_string($input) || trim($input) === '') {
        return [];
    }

    return doctorRunnerDecodeInput($input);
}

/**
 * @return array<string, mixed>
 */
function doctorRunnerDecodeInput(string $input): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($input, associative: true);

    if (! is_array($decoded)) {
        return [];
    }

    foreach (array_keys($decoded) as $key) {
        if (! is_string($key)) {
            return [];
        }
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

function doctor_runner_fake_ca(): \App\Services\Ca\OrbitCaService
{
    return new readonly class extends \App\Services\Ca\OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    };
}

function doctor_runner_script_creates_trusted_runtime(string $script, string $containerName): bool
{
    return (
        str_contains($script, "> '/home/orbit/.config/orbit/ca/root.crt'")
        && str_contains($script, 'docker run')
        && str_contains($script, "'{$containerName}'")
    );
}

function doctor_runner_script_creates_runtime_container(string $script, string $containerName): bool
{
    return str_contains($script, 'docker run') && str_contains($script, "'{$containerName}'");
}

/**
 * @param  list<array{name: string, ipv4_address: string, dns: string}>  $clients
 */
function createDoctorRunnerWgEasyDnsDatabase(string $path, string $defaultDns, array $clients): void
{
    File::ensureDirectoryExists(dirname($path));

    $database = new PDO("sqlite:{$path}");
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('create table user_configs_table (default_dns text not null)');
    $database->exec(<<<'SQL'
        create table clients_table (
            name text not null,
            ipv4_address text not null,
            dns text not null,
            enabled integer not null
        )
        SQL);
    $database
        ->prepare('insert into user_configs_table (default_dns) values (:default_dns)')
        ->execute(['default_dns' => $defaultDns]);

    $statement = $database->prepare(
        'insert into clients_table (name, ipv4_address, dns, enabled) values (:name, :ipv4_address, :dns, 1)',
    );

    foreach ($clients as $client) {
        $statement->execute($client);
    }
}

/**
 * @return array<string, string>
 */
function readDoctorRunnerWgEasyDnsRows(string $path): array
{
    $database = new PDO("sqlite:{$path}");
    $rows = [
        'default' => $database->query('select default_dns from user_configs_table limit 1')->fetchColumn(),
    ];

    foreach ($database
        ->query('select name, dns from clients_table order by name')
        ->fetchAll(PDO::FETCH_KEY_PAIR) as $name => $dns) {
        $rows[(string) $name] = (string) $dns;
    }

    return array_map(static fn (mixed $value): string => (string) $value, $rows);
}
