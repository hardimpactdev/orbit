<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\Doctor\DoctorRunRequest;
use App\Data\Doctor\DoctorTargetScope;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\WorkspaceLifecycleStatus;
use App\Exceptions\RemoteShellFailed;
use App\Models\AppInstance;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\Process as OrbitProcess;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Models\WireGuardPeer;
use App\Models\Workspace;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Apps\AppRuntimeContainer;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Ca\OrbitCaService;
use App\Services\Dns\DnsmasqBaseConfigBuilder;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScopeValidator;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessEventNotifierRenderer;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Runtime\OrbitCaddyContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainer;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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
    app()->bind(
        RemoteLocalExecutor::class,
        fn (): RemoteLocalExecutor => doctorRunnerLocalExecutor(app(RemoteShell::class)),
    );
    app()->bind(
        RunsInternalCommands::class,
        fn (): RunsInternalCommands => app(RemoteLocalExecutor::class),
    );
    app()->bind(
        AppRuntimeContainerManager::class,
        fn (): AppRuntimeContainerManager => new AppRuntimeContainerManager(
            app(DockerCommandBuilder::class),
            doctor_runner_fake_ca(),
        ),
    );
    app()->bind(
        WorkspaceRuntimeContainerManager::class,
        fn (): WorkspaceRuntimeContainerManager => new WorkspaceRuntimeContainerManager(
            app(DockerCommandBuilder::class),
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

function createDoctorRunnerAppHostNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'name' => 'app-1',
        'status' => 'active',
        'managed' => true,
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
        'managed' => true,
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
        "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-runtime-hibernator'" => Process::result(
            output: "{$image}\n",
        ),
        "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'" => Process::result(
            output: "1/1\n",
        ),
        "docker service scale --detach=true 'orbit_orbit-scheduler=1'" => Process::result(),
    ]);
}

/** @mago-expect lint:cyclomatic-complexity */
describe('DoctorReportRunner', function (): void {
    it('leaves concrete app runtime-unit drift to the process family', function (): void {
        $node = createDoctorRunnerAppHostNode();
        Project::factory()->create([
            'name' => 'docs',
            'node_id' => $node->id,
            'path' => '/home/orbit/apps/docs',
            'document_root' => 'public',
            'php_version' => '8.5',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "docs\t1\t1\t1\t1\t0\t0\t0\t1\t1\t1\t1\t1\t0\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['app']);
        $runtimeKeys = collect($report['issues'])
            ->concat($report['actions'])
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'app.runtime_container_'));

        expect($runtimeKeys)->toBeEmpty();
    });

    it('does not probe or fix workspace PHP-FPM pools for PHP apps because workspaces use Docker containers', function (): void {
        $node = createDoctorRunnerAppHostNode();
        $app = Project::factory()->create([
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
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-runtime-hibernator'" => Process::result(
                output: "{$desiredImage}\n",
            ),
            "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'" => Process::result(
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

    it('restores a deleted agent tool proxy route through proxy-family dispatch', function (): void {
        $node = Node::factory()->create([
            'name' => 'agent-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.6.0.11',
            'wireguard_address' => '10.6.0.11',
            'tld' => 'agent',
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
        app()->instance(RemoteShell::class, new DoctorReportRunnerAgentToolProxyRemoteShell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(OrbitCaService::class, doctor_runner_agent_tool_proxy_fake_ca());

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['proxy']);
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
                'family' => 'proxy',
                'node' => 'agent-1',
                'key' => 'proxy.agent_tool_route_missing',
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
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        OrbitProcess::factory()
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
                stdout: "orbit_docs_development_main_vite\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
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
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-systemd-service')
                        && str_contains($script, 'orbit_docs_development_main_vite.service')
                    ),
                ))
            ->toBeTrue();
    });

    it('starts a stopped always-on Docker process runtime through restore mode family dispatch', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
        ]);
        $process = OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'name' => 'queue',
                'command' => 'php artisan queue:work',
                'runtime' => ProcessRuntime::Docker,
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        $container = app(ProcessDockerContainerRenderer::class)->render($app, $process);
        $inspection = static fn (string $state): string => json_encode([
            'State' => ['Status' => $state],
            'Config' => ['Labels' => [ProcessDockerContainer::SpecHashLabel => $container->specHash()]],
        ], JSON_THROW_ON_ERROR);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: $inspection('exited'), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $container->name()."\n", stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $inspection('running'), stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: $container->name()."\n", stderr: '', durationMs: 1),
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
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_down',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'node' => 'app-1',
                    'process' => 'queue',
                    'runtime_unit' => $container->name(),
                ],
            ])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => str_contains($script, 'internal:process-docker-container'),
                ))
            ->toBeTrue();
    });

    it('restores missing process runtime units for the app named in the runtime unit', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $docs = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        $blog = Project::factory()->for($node, 'node')->create([
            'name' => 'blog',
            'path' => '/home/orbit/apps/blog',
        ]);
        OrbitProcess::factory()
            ->forOwner($docs)
            ->create([
                'name' => 'vp-dev',
                'command' => 'npm run docs',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        OrbitProcess::factory()
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
                stdout: "orbit_docs_development_main_vp-dev\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_blog_development_main_vp-dev\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
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
                'details' => [
                    'app' => 'blog',
                    'app_instance' => 'development',
                    'process' => 'vp-dev',
                ],
            ])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-systemd-service')
                        && str_contains($script, 'orbit_blog_development_main_vp-dev.service')
                    ),
                ))
            ->toBeTrue();
    });

    it('restores only the concrete app instance named by process drift', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
        ]);
        $development = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: '/home/orbit/apps/docs-development',
            ),
        ]);
        $production = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: '/home/orbit/apps/docs-production',
            ),
        ]);
        OrbitProcess::factory()
            ->forOwner($app, $node)
            ->create([
                'app_instance_id' => $development->id,
                'name' => 'vp-dev',
                'command' => 'npm run development',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        OrbitProcess::factory()
            ->forOwner($app, $node)
            ->create([
                'app_instance_id' => $production->id,
                'name' => 'vp-dev',
                'command' => 'npm run production',
                'restart_policy' => 'on_failure',
                'crash_notification' => 'none',
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_development_main_vp-dev\t1\t1\t1\t1\n__notifier\t1\t1\t1\t1\t1\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: 'systemd OK', stderr: '', durationMs: 1),
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit_docs_production_main_vp-dev\t0\t0\t0\t0\n__notifier\t1\t1\t1\t1\t1\n",
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
            ->and($report['actions'])
            ->toHaveCount(1)
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'docs',
                    'app_instance' => 'production',
                    'process' => 'vp-dev',
                ],
            ])
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-systemd-service')
                        && str_contains($script, 'orbit_docs_production_main_vp-dev.service')
                    ),
                ))
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-systemd-service')
                        && str_contains($script, 'orbit_docs_development_main_vp-dev.service')
                    ),
                ))
            ->toBeFalse();
    });

    it('refreshes stale managed FrankenPHP app process intent during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: $app->path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);
        $expectedHash = app(AppRuntimeContainerRenderer::class)->renderForInstance($app, $instance)->specHash();
        $process = OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs-development',
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
        app()->instance(OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            AppRuntimeContainerManager::class,
            new AppRuntimeContainerManager(
                app(DockerCommandBuilder::class),
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
                    'app_instance' => 'development',
                    'process' => 'frankenphp-docs',
                    'container' => 'orbit-app-docs-development',
                    'outcome' => 'unchanged',
                ],
            ])
            ->and($report['healthy'])
            ->toBeTrue()
            ->and($process->refresh()->runtime_config)
            ->toMatchArray([
                'container_name' => 'orbit-app-docs-development',
                'container_spec_hash' => $expectedHash,
                'container_spec_hash_label' => AppRuntimeContainer::SpecHashLabel,
                'php_ini_path' => '/home/orbit/.config/orbit/apps/docs-development.ini',
            ]);
    });

    it('reapplies stale managed FrankenPHP app runtime containers during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: $app->path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);
        $expectedHash = app(AppRuntimeContainerRenderer::class)->renderForInstance($app, $instance)->specHash();
        OrbitProcess::factory()
            ->forOwner($app)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'frankenphp-docs',
                'command' => 'frankenphp',
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'container_name' => 'orbit-app-docs-development',
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
        app()->instance(OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            AppRuntimeContainerManager::class,
            new AppRuntimeContainerManager(
                app(DockerCommandBuilder::class),
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
                    'app_instance' => 'development',
                    'process' => 'frankenphp-docs',
                    'container' => 'orbit-app-docs-development',
                    'outcome' => 'recreated',
                ],
            ])
            ->and($report['healthy'])
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:app-runtime-container')
                        && str_contains($script, 'container:apply')
                    ),
                ))
            ->toBeTrue()
            ->and(
                Project::query()
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

    it('reports a missing FrankenPHP process for a secondary app instance that owns runtime intent', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'nckrtl',
            'tld' => 'nmbp',
            'platform' => 'macos_14',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/Users/nckrtl/apps/nckrtl',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $development = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $app->path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);
        AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/Users/nckrtl/.config/orbit/worktrees/nckrtl-nmbp',
                document_root: $app->document_root,
                domain: 'nckrtl.nmbp',
            ),
        ]);
        $process = app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $development);
        $expectedHash = $process->runtime_config['container_spec_hash'];
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => $expectedHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
        ]));

        $report = app(DoctorReportRunner::class)->probe($node, families: ['process']);
        $issue = collect($report['issues'])
            ->first(
                fn (array $issue): bool => ($issue['detail']['reason'] ?? null) === 'runtime_process_missing',
            );

        expect($issue)
            ->toMatchArray([
                'family' => 'process',
                'node' => 'nckrtl',
                'key' => 'process.runtime_unit_missing',
                'restorable' => true,
                'detail' => [
                    'app' => 'nckrtl',
                    'app_instance' => 'nmbp',
                    'process' => 'frankenphp-nckrtl',
                    'runtime_unit' => 'orbit-app-nckrtl-nmbp',
                    'reason' => 'runtime_process_missing',
                ],
            ])
            ->and(OrbitProcess::query()->where('app_instance_id', $development->id)->count())
            ->toBe(1)
            ->and(OrbitProcess::query()->count())
            ->toBe(1);
    });

    it('restores the missing secondary app-instance FrankenPHP process and container', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'nckrtl',
            'tld' => 'nmbp',
            'platform' => 'macos_14',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'nckrtl',
            'path' => '/Users/nckrtl/apps/nckrtl',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        $development = AppInstance::factory()->for($app)->create([
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $app->path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);
        $nmbp = AppInstance::factory()->for($app)->create([
            'name' => 'nmbp',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/Users/nckrtl/.config/orbit/worktrees/nckrtl-nmbp',
                document_root: $app->document_root,
                domain: 'nckrtl.nmbp',
            ),
        ]);
        $developmentProcess = app(EnsureFrankenPhpRuntimeProcess::class)->forApp($app, $development);
        $developmentHash = $developmentProcess->runtime_config['container_spec_hash'];
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode([
                    'State' => ['Running' => true, 'Status' => 'running'],
                    'Config' => ['Labels' => [AppRuntimeContainer::SpecHashLabel => $developmentHash]],
                ], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(['outcome' => 'created'], JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '[{"Id":"sha256:abc"}]', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(OrbitCaService::class, doctor_runner_fake_ca());
        doctor_runner_expect_app_runtime_outcomes('created');

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);
        $action = collect($report['actions'])
            ->first(
                fn (array $action): bool => ($action['details']['app_instance'] ?? null) === 'nmbp',
            );

        expect($report['healthy'])
            ->toBeTrue()
            ->and($action)
            ->toMatchArray([
                'family' => 'process',
                'node' => 'nckrtl',
                'key' => 'process.runtime_unit_missing',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'app' => 'nckrtl',
                    'app_instance' => 'nmbp',
                    'process' => 'frankenphp-nckrtl',
                    'container' => 'orbit-app-nckrtl-nmbp',
                    'outcome' => 'created',
                ],
            ])
            ->and(OrbitProcess::query()->where('app_instance_id', $nmbp->id)->exists())
            ->toBeTrue()
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:app-runtime-container')
                        && str_contains($script, 'container:apply')
                    ),
                ))
            ->toBeTrue();
    });

    it('refreshes stale managed FrankenPHP workspace process intent during process restore', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'tld' => 'test',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
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
        $process = OrbitProcess::factory()
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
        app()->instance(OrbitCaService::class, doctor_runner_fake_ca());
        app()->instance(
            WorkspaceRuntimeContainerManager::class,
            new WorkspaceRuntimeContainerManager(
                app(DockerCommandBuilder::class),
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
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'path' => '/home/orbit/apps/docs',
            'php_version' => '8.5',
            'runtime' => AppRuntimeKind::Php,
        ]);
        OrbitProcess::factory()
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
                stdout: "orbit_docs_development_main_vite\t1\t1\t1\t1\n__notifier\t1\t1\t0\t1\t1\n",
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
            ->and(
                collect(Http::recorded())
                    ->map(static fn (array $exchange): Request => $exchange[0])
                    ->filter(
                        static fn (Request $request): bool => (
                            doctorRunnerAgentPushCommandName($request) === 'internal:managed-file'
                        ),
                    )
                    ->map(static fn (Request $request): array => doctorRunnerAgentPushInput($request))
                    ->values()
                    ->all(),
            )
            ->toBe([
                [
                    'path' => '/usr/local/bin/orbit-notify-exit',
                    'content' => app(ProcessEventNotifierRenderer::class)->content(),
                    'mode' => '0755',
                    'directory_mode' => '0755',
                ],
                [
                    'path' => '/etc/orbit/gateway-endpoint',
                    'content' => "https://gateway.test\n",
                    'mode' => '0644',
                    'directory_mode' => '0755',
                ],
            ])
            ->and(collect($shell->scripts)->contains('orbit-notify-exit'))
            ->toBeFalse();
    });

    it('leaves concrete workspace runtime-unit drift to the process family', function (): void {
        $node = createDoctorRunnerAppHostNode([
            'name' => 'app-1',
            'platform' => 'ubuntu_24-04',
        ]);
        $app = Project::factory()->for($node, 'node')->create([
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
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['workspace']);
        $runtimeKeys = collect($report['issues'])
            ->concat($report['actions'])
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'workspace.runtime_container_'));

        expect($runtimeKeys)
            ->toBeEmpty()
            ->and($workspace->processes()->exists())
            ->toBeFalse();
    });

    it('restores missing node-owned process runtime units through restore mode family dispatch', function (): void {
        $node = Node::factory()
            ->database()
            ->create([
                'name' => 'metrics-worker-1',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'user' => 'orbit',
                'managed' => true,
            ]);
        OrbitProcess::factory()
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
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-systemd-service')
                        && str_contains($script, 'node-exporter.service')
                    ),
                ))
            ->toBeTrue();
    });

    it('reports and removes orphaned managed app containers through the process family', function (): void {
        $node = Node::factory()
            ->appDev()
            ->create([
                'name' => 'app-1',
                'tld' => 'app-one',
                'status' => 'active',
                'platform' => 'ubuntu_24-04',
                'user' => 'orbit',
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(
                exitCode: 0,
                stdout: "orbit-container-scan:present\norbit-app-removed\tremoved\n",
                stderr: '',
                durationMs: 1,
            ),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'restore', families: ['process']);

        expect($report['healthy'])
            ->toBeTrue()
            ->and($report['issues'])
            ->toBe([])
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'process',
                'node' => 'app-1',
                'key' => 'process.runtime_unit_extra',
                'mode' => 'restore',
                'status' => 'completed',
                'details' => [
                    'runtime_unit' => 'orbit-app-removed',
                    'app' => 'removed',
                    'reason' => 'orphaned_managed_app_runtime',
                ],
            ])
            ->and($shell->scripts[1] ?? '')
            ->toContain('internal:process-docker-container')
            ->and($report['actions'][0]['summary'] ?? '')
            ->toContain('orbit-app-removed');
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
        OrbitProcess::factory()
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
            ->and(collect($shell->scripts)
                ->contains(
                    fn (string $script): bool => (
                        str_contains($script, 'internal:process-docker-swarm-service')
                        && str_contains($script, 'orbit-grafana')
                    ),
                ))
            ->toBeTrue();
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
        $process = OrbitProcess::factory()
            ->forOwner($node)
            ->create([
                'name' => 'valkey',
                'runtime' => ProcessRuntime::Docker,
                'command' => 'valkey-server --appendonly yes',
                'restart_policy' => 'always',
                'crash_notification' => 'none',
                'runtime_config' => [
                    'service' => 'valkey',
                    'version_family' => '8',
                    'version' => '8.1',
                ],
                'sort_order' => 1,
            ]);
        $shell = new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such network', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'No such container: valkey', durationMs: 1),
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
                    'process' => 'valkey',
                    'service' => 'valkey',
                    'version' => '8.1',
                    'runtime' => 'docker',
                    'runtime_unit' => 'valkey',
                ],
            ])
            ->and($process->runtime_config)
            ->toMatchArray([
                'service' => 'valkey',
                'version_family' => '8',
                'version' => '8.1',
                'image' => 'valkey/valkey:8.1',
                'service_name' => 'orbit-valkey',
                'endpoint' => [
                    'name' => 'valkey',
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
            ->toContain('type=bind,source=/var/lib/orbit/processes/valkey,target=/data')
            ->and($process->runtime_config)
            ->toHaveKey('service_name', 'orbit-valkey');

        expect($shell->scripts)->not->toContain("sudo mkdir -p '/var/lib/orbit/processes/valkey'");
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
            File::put($root.'/dnsmasq.conf', new DnsmasqBaseConfigBuilder()->build());
            write_current_node_dns_projection();
            write_current_proxy_dns_projection();
            createDoctorRunnerWgEasyDnsDatabase($root.'/wg-easy/wg-easy.db', '["10.6.0.1"]', [
                ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
            ]);

            Process::fake(function ($process) use ($root) {
                $command = (string) $process->command;

                if (str_contains($command, 'docker ps')) {
                    return Process::result('orbit-dns-id');
                }

                if (str_contains($command, 'docker exec')) {
                    return Process::result('udp 0 0 :::53 :::* LISTEN');
                }

                if (str_starts_with($command, 'docker inspect --format')) {
                    return Process::result(json_encode([[
                        'Type' => 'bind',
                        'Source' => $root.'/dnsmasq.d',
                        'Destination' => '/etc/dnsmasq.d',
                        'RW' => false,
                    ]], JSON_THROW_ON_ERROR));
                }

                return Process::result();
            });

            $report = app(DoctorReportRunner::class)->probe($gateway, families: ['tool']);

            $issue = collect($report['issues'])
                ->first(fn (array $issue): bool => ($issue['key'] ?? null) === 'tool.dns_client_dns_drift');

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
            File::put($root.'/dnsmasq.conf', new DnsmasqBaseConfigBuilder()->build());
            write_current_node_dns_projection();
            write_current_proxy_dns_projection();
            createDoctorRunnerWgEasyDnsDatabase($root.'/wg-easy/wg-easy.db', '["10.6.0.1","1.1.1.1"]', [
                ['name' => 'operator', 'ipv4_address' => '10.6.0.3', 'dns' => '["10.6.0.1","1.1.1.1"]'],
            ]);

            Process::fake(function ($process) use ($root) {
                $command = (string) $process->command;

                if (str_contains($command, 'docker ps')) {
                    return Process::result('orbit-dns-id');
                }

                if (str_contains($command, 'docker exec')) {
                    return Process::result('udp 0 0 :::53 :::* LISTEN');
                }

                if (str_starts_with($command, 'docker inspect --format')) {
                    return Process::result(json_encode([[
                        'Type' => 'bind',
                        'Source' => $root.'/dnsmasq.d',
                        'Destination' => '/etc/dnsmasq.d',
                        'RW' => false,
                    ]], JSON_THROW_ON_ERROR));
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
                    'key' => 'tool.dns_client_dns_drift',
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
            "docker service inspect --format '{{.Spec.TaskTemplate.ContainerSpec.Image}}' 'orbit_orbit-runtime-hibernator'" => Process::result(
                output: "ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n",
            ),
            "docker service ls --filter 'name=orbit_orbit-runtime-hibernator' --format '{{.Replicas}}'" => Process::result(
                output: "1/1\n",
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
        $node = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.1',
            'wireguard_address' => '10.6.0.5',
            'tld' => 'test',
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
        write_current_node_dns_projection();
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
            ->and(
                NodeTool::query()
                    ->where('node_id', $node->id)
                    ->where('name', 'caddy')
                    ->exists(),
            )
            ->toBeTrue();
    });

    it('marks websocket role baseline drift as restorable', function (): void {
        $node = Node::factory()->create([
            'name' => 'realtime-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.44',
            'wireguard_address' => '10.6.0.44',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'websocket',
            'status' => 'active',
            'settings' => [],
        ]);
        app()->instance(RemoteShell::class, new class implements RemoteShell {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\nexists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\npublished_bindings=[]\n",
                    stderr: '',
                    durationMs: 1,
                );
            }

            public function start(Node $node, string $script, array $options = []): InvokedProcess
            {
                throw new RuntimeException('not used');
            }
        });

        $report = app(DoctorReportRunner::class)->probe(
            $node,
            families: ['node'],
            key: 'node.websocket.bind_public_interface',
        );

        expect($report['issues'])
            ->toHaveCount(1)
            ->and($report['issues'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'realtime-1',
                'key' => 'node.websocket.bind_public_interface',
                'restorable' => true,
            ]);
    });

    it('keeps persistent websocket drift visible after restore verification', function (): void {
        $node = Node::factory()->create([
            'name' => 'realtime-1',
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'host' => '10.0.0.44',
            'wireguard_address' => '10.6.0.44',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'websocket',
            'status' => 'active',
            'settings' => [],
        ]);
        app()->instance(RemoteShell::class, new class implements RemoteShell {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "cert_exists=1\nkey_exists=1\ncert_matches=1\nexists=1\nrunning=true\nenv_host=10.6.0.44\ncmd_host=10.6.0.44\npublished_bindings=[]\n",
                    stderr: '',
                    durationMs: 1,
                );
            }

            public function start(Node $node, string $script, array $options = []): InvokedProcess
            {
                throw new RuntimeException('not used');
            }
        });

        $runner = app(DoctorReportRunner::class);
        $probe = $runner->probe(
            $node,
            families: ['node'],
            key: 'node.websocket.bind_public_interface',
        );

        expect($runner->restoreRequiresVerification(
            'restore',
            'node.websocket.bind_public_interface',
            $probe,
        ))->toBeTrue();

        $report = $runner->finalizeRestore(
            $node,
            ['node'],
            'node.websocket.bind_public_interface',
            DoctorTargetScope::none(),
            [[
                'family' => 'node',
                'node' => 'realtime-1',
                'key' => 'node.websocket.bind_public_interface',
                'mode' => 'restore',
                'status' => 'completed',
                'summary' => 'Re-converged the WebSocket role baseline.',
                'details' => [],
            ]],
        );

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['issues'])
            ->toHaveCount(1)
            ->and($report['issues'][0]['key'])
            ->toBe('node.websocket.bind_public_interface')
            ->and($report['actions'][0])
            ->toMatchArray([
                'family' => 'node',
                'node' => 'realtime-1',
                'key' => 'node.websocket.bind_public_interface',
                'mode' => 'restore',
                'status' => 'failed',
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

        $app = Project::factory()->create([
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
            ->forAppInstance(doctorRunnerDatabaseAppInstance($app))
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

        $app = Project::factory()->create([
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
            ->forAppInstance(doctorRunnerDatabaseAppInstance($app))
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
        $logicalAppNode = createDoctorRunnerAppHostNode(['name' => 'logical-app-node']);
        $node = createDoctorRunnerAppHostNode(['name' => 'instance-node']);
        $path = storage_path('framework/testing/doctor-database-target-missing');
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/.env',
            "DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=5432\nDB_DATABASE=docs\nDB_USERNAME=orbit\nDB_PASSWORD=secret\n",
        );

        $app = Project::factory()->create([
            'node_id' => $logicalAppNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: $path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
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
        $action = collect($report['actions'])->firstWhere('key', 'database_connection.target_missing');

        expect($report['healthy'])
            ->toBeTrue()
            ->and($action)
            ->toMatchArray([
                'family' => 'database_connection',
                'node' => 'instance-node',
                'status' => 'completed',
            ])
            ->and(
                DatabaseConnectionTarget::query()
                    ->where('database_connection_id', $connection->id)
                    ->where('app_instance_id', $instance->id)
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

        $app = Project::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        doctorRunnerDatabaseAppInstance($app);
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
            ->and(DatabaseConnection::query()->where('slug', 'docs-development')->exists())
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

        $app = Project::factory()->create([
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
            ->forAppInstance(doctorRunnerDatabaseAppInstance($app))
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
        $logicalAppNode = createDoctorRunnerAppHostNode(['name' => 'logical-app-node']);
        $node = createDoctorRunnerAppHostNode(['name' => 'instance-node']);
        $path = storage_path('framework/testing/doctor-database-restore-failure');
        File::ensureDirectoryExists($path);
        File::put($path.'/.env', "DB_CONNECTION=mysql\n");

        $app = Project::factory()->create([
            'node_id' => $logicalAppNode->id,
            'name' => 'docs',
            'path' => $path,
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                path: $path,
                document_root: $app->document_root,
                domain: $app->domain,
            ),
        ]);
        $workspace = Workspace::factory()->for($app, 'app')->create([
            'app_instance_id' => $instance->id,
            'name' => 'feature-db',
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
            ->forWorkspace($workspace)
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

        $report = app(DoctorReportRunner::class)->run(
            $node,
            mode: 'restore',
            families: ['database_connection'],
            request: new DoctorRunRequest(
                scope: DoctorTargetScope::from(app: 'docs', workspace: 'feature-db'),
            ),
        );
        $failedAction = collect($report['actions'])->firstWhere('status', 'failed');

        expect($report['healthy'])
            ->toBeFalse()
            ->and($report['summary']['failed'])
            ->toBeGreaterThanOrEqual(1)
            ->and($failedAction)
            ->toMatchArray([
                'family' => 'database_connection',
                'node' => 'instance-node',
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

function doctorRunnerDatabaseAppInstance(Project $app): AppInstance
{
    $instance = $app->instances()->first();

    if ($instance instanceof AppInstance) {
        return $instance;
    }

    return AppInstance::factory()->for($app)->create([
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $app->node_id,
            path: $app->path,
            document_root: $app->document_root,
            domain: $app->domain,
        ),
    ]);
}

describe('DoctorReportRunner fact-derived categories', function (): void {
    it('does not include schedule for unscheduled app hosts merely because of role', function (string $role): void {
        $node = Node::factory()->create([
            'name' => "unscheduled-{$role}",
            'status' => 'active',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['schedule'],
            runner: $runner,
            target: $node,
        );

        expect($runner->categoriesForNode($node))
            ->not
            ->toContain('schedule')
            ->and($failure?->code)
            ->toBe('family_not_in_node_scope');
    })->with([
        'app development' => ['app-dev'],
        'app production' => ['app-prod'],
    ]);

    it('keeps gateway scheduler eligibility without an expected schedule target', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-scheduler-category',
                'status' => 'active',
            ]);

        expect(app(DoctorReportRunner::class)->categoriesForNode($node))
            ->toContain('schedule');
    });

    it('includes schedule for every enabled expected schedule target regardless of role', function (?string $role): void {
        $node = Node::factory()->create([
            'name' => 'scheduled-'.($role ?? 'roleless'),
            'status' => 'active',
        ]);

        if (is_string($role)) {
            NodeRoleAssignment::factory()->create([
                'node_id' => $node->id,
                'role' => $role,
                'status' => 'active',
            ]);
        }

        Schedule::factory()->forNode($node)->create();

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['schedule'],
            runner: $runner,
            target: $node,
        );

        expect($runner->categoriesForNode($node))
            ->toContain('schedule')
            ->and($failure)
            ->toBeNull();
    })->with([
        'roleless' => [null],
        'database' => ['database'],
        'agent' => ['agent'],
        'metrics' => ['metrics'],
        'ingress' => ['ingress'],
    ]);

    it('includes tool for an active gateway with the vpn capability', function (): void {
        $node = Node::factory()
            ->gateway()
            ->create([
                'name' => 'gateway-vpn-categories',
                'status' => 'active',
            ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'vpn',
            'status' => 'active',
        ]);

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['tool'],
            runner: $runner,
            target: $node,
        );

        expect($runner->categoriesForNode($node))
            ->toContain('tool')
            ->and($failure)
            ->toBeNull();
    });

    it('includes tool for a roleless node that owns a tool record', function (): void {
        $node = Node::factory()->create([
            'name' => 'roleless-tool-owner',
            'status' => 'active',
        ]);
        NodeTool::factory()->create([
            'node_id' => $node->id,
        ]);

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['tool'],
            runner: $runner,
            target: $node,
        );

        expect($runner->categoriesForNode($node))
            ->toContain('tool')
            ->and($failure)
            ->toBeNull();
    });

    it('keeps gateway scheduler singleton probes off non-gateway schedule targets', function (): void {
        $node = Node::factory()
            ->database()
            ->create([
                'name' => 'scheduled-database',
                'status' => 'active',
            ]);
        Schedule::factory()->forNode($node)->create();
        app()->instance(RemoteShell::class, new DoctorReportRunnerRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));
        Process::preventStrayProcesses();
        Process::fake();

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['schedule'],
            runner: $runner,
            target: $node,
        );

        expect($failure)->toBeNull();

        $report = $runner->probe($node, families: ['schedule']);

        expect($report['scope']['families'])->toBe(['schedule']);
        Process::assertNothingRan();
    });
});

describe('DoctorReportRunner firewall categories', function (): void {
    it('includes firewall rules through the fact overlay for eligible active Ubuntu nodes', function (string $role): void {
        $node = Node::factory()->create([
            'name' => "{$role}-firewall-cat",
            'status' => 'active',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.42',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $runner = app(DoctorReportRunner::class);

        $categories = $runner->categoriesForNode($node);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['firewall_rule'],
            runner: $runner,
            target: $node,
        );

        expect($categories)
            ->toContain('node')
            ->and($categories)
            ->toContain('tool')
            ->and($categories)
            ->toContain('process')
            ->and($categories)
            ->toContain('firewall_rule')
            ->and($failure)
            ->toBeNull();
    })->with([
        'agent' => ['agent'],
        'app development' => ['app-dev'],
        'app production' => ['app-prod'],
        'ingress' => ['ingress'],
    ]);

    it('does not include firewall rules for macOS app or ingress nodes merely because of role', function (
        string $role,
    ): void {
        $node = Node::factory()->create([
            'name' => "{$role}-macos-firewall-cat",
            'status' => 'active',
            'platform' => 'macos_15',
        ]);
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => $role,
            'status' => 'active',
        ]);

        $runner = app(DoctorReportRunner::class);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['firewall_rule'],
            runner: $runner,
            target: $node,
        );

        expect($runner->categoriesForNode($node))
            ->not
            ->toContain('firewall_rule')
            ->and($failure?->code)
            ->toBe('family_not_in_node_scope');
    })->with([
        'app development' => ['app-dev'],
        'app production' => ['app-prod'],
        'ingress' => ['ingress'],
    ]);

    it('excludes firewall rules on macOS even when the node role is otherwise eligible', function (): void {
        $node = Node::factory()
            ->agent()
            ->create([
                'name' => 'agent-macos-firewall-cat',
                'status' => 'active',
                'platform' => 'macos_15',
            ]);

        $runner = app(DoctorReportRunner::class);
        $categories = $runner->categoriesForNode($node);
        $failure = app(DoctorScopeValidator::class)->validate(
            families: ['firewall_rule'],
            runner: $runner,
            target: $node,
        );

        expect($categories)
            ->not->toContain('firewall_rule')->and($failure)
            ->not->toBeNull()->and($failure?->code)->toBe('family_not_in_node_scope')->and($failure?->message)->toBe(
                "Doctor family 'firewall_rule' is not available for node 'agent-macos-firewall-cat'.",
            )->and($failure?->meta)->toBe([
                'family' => 'firewall_rule',
                'target_node' => 'agent-macos-firewall-cat',
                'allowed_families' => ['node', 'tool', 'proxy', 'process'],
            ]);
    });
});

// ---------------------------------------------------------------------------
// S3 role: category mapping + s3 probe dispatch
// ---------------------------------------------------------------------------

it('includes proxy family ownership in the agent role doctor categories', function (): void {
    expect(app(DoctorReportRunner::class)->categoriesForRole('agent'))
        ->toBe(['node', 'tool', 'proxy']);
});

it('rejects workspace doctor family and scope on app production nodes', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-prod-workspace-doctor',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-prod',
        'status' => 'active',
    ]);
    $runner = app(DoctorReportRunner::class);
    $validator = app(DoctorScopeValidator::class);

    $familyFailure = $validator->validate(
        families: ['workspace'],
        runner: $runner,
        target: $node,
    );
    $scopeFailure = $validator->validate(
        families: ['app'],
        runner: $runner,
        target: $node,
        scope: DoctorTargetScope::from('docs', 'feature', appInstance: 'production'),
    );

    expect($runner->categoriesForRole('app-prod'))
        ->not
        ->toContain('workspace')
        ->and($familyFailure?->code)
        ->toBe('family_not_in_node_scope')
        ->and($scopeFailure?->code)
        ->toBe('family_not_in_node_scope')
        ->and($scopeFailure?->meta['family'] ?? null)
        ->toBe('workspace');
});

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

    it('limits an app-scoped ingress proxy probe to the selected app route', function (): void {
        $ingress = Node::factory()->create([
            'name' => 'ingress-1',
            'status' => 'active',
            'managed' => true,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.10',
        ]);

        foreach (['gateway', 'ingress'] as $role) {
            NodeRoleAssignment::factory()->create([
                'node_id' => $ingress->id,
                'role' => $role,
                'status' => 'active',
            ]);
        }

        $hauzer = Project::factory()->create(['node_id' => $ingress->id, 'name' => 'hauzer-production']);
        $mealou = Project::factory()->create(['node_id' => $ingress->id, 'name' => 'mealou-production']);

        foreach ([
            [$hauzer, 'hauzer.app'],
            [$mealou, 'mealou.app'],
        ] as [$app, $domain]) {
            ProxyRoute::factory()->create([
                'node_id' => $ingress->id,
                'app_id' => $app->id,
                'domain' => $domain,
                'owner_type' => 'app',
                'kind' => 'app',
                'source_hash' => str_repeat('a', times: 64),
                'config' => [],
            ]);
        }

        $adoptSnapshot =
            collect([
                'hauzer.app',
                'mealou.app',
                'unrelated.example',
            ])->map(static function (string $domain): string {
                $body = "{$domain} {\n    reverse_proxy localhost:8080\n}\n";

                return implode("\t", [$domain, hash('sha256', $body), base64_encode($body)]);
            })->implode("\n")."\n";

        app()->instance(RunsInternalCommands::class, new class($adoptSnapshot) implements RunsInternalCommands {
            public function __construct(
                private readonly string $adoptSnapshot,
            ) {}

            public function runInternal(
                Node $node,
                string $commandName,
                array $arguments = [],
                array $commandOptions = [],
                array $transportOptions = [],
            ): RemoteShellResult {
                $input = json_decode((string) ($transportOptions['input'] ?? ''), associative: true);
                $script = is_array($input) && is_string($input['script'] ?? null) ? $input['script'] : '';
                $stdout = str_contains($script, 'body_b64=')
                    ? $this->adoptSnapshot
                    : '';
                $result = new RemoteShellResult(exitCode: 0, stdout: $stdout, stderr: '', durationMs: 1);

                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: doctorRunnerInternalCommandStdout($commandName, [], $result),
                    stderr: '',
                    durationMs: 1,
                );
            }
        });

        $report = app(DoctorReportRunner::class)->probe(
            $ingress,
            families: ['proxy'],
            scope: DoctorTargetScope::from(app: 'hauzer-production', workspace: null),
        );
        $routeDomains = collect($report['issues'])
            ->pluck('detail.domain')
            ->filter(static fn (mixed $domain): bool => is_string($domain))
            ->unique()
            ->values()
            ->all();

        expect($routeDomains)
            ->toBe(['hauzer.app']);

        $restore = app(DoctorReportRunner::class)->run(
            $ingress,
            mode: 'restore',
            families: ['proxy'],
            request: new DoctorRunRequest(
                dryRun: true,
                scope: DoctorTargetScope::from(app: 'hauzer-production', workspace: null),
            ),
        );
        $actionDomains = collect($restore['actions'])
            ->pluck('details.domain')
            ->filter(static fn (mixed $domain): bool => is_string($domain))
            ->unique()
            ->values()
            ->all();

        expect($actionDomains)
            ->toBe(['hauzer.app']);

        $adopt = app(DoctorReportRunner::class)->run(
            $ingress,
            mode: 'adopt',
            families: ['proxy'],
            request: new DoctorRunRequest(
                scope: DoctorTargetScope::from(app: 'hauzer-production', workspace: null),
            ),
        );

        expect(collect($adopt['actions'])->pluck('key')->all())
            ->toBe(['hauzer.app'])
            ->and(ProxyRoute::query()->where('domain', 'unrelated.example')->exists())
            ->toBeFalse();
    });

    it('excludes persisted workspace process and proxy inventory from production app nodes', function (): void {
        $node = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-inventory',
                'status' => 'active',
                'managed' => true,
                'tld' => 'test',
            ]);
        $app = Project::factory()
            ->static()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'environment' => 'production',
                'path' => '/srv/docs',
            ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $app->path,
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        OrbitProcess::factory()
            ->forOwner($app, $node)
            ->create([
                'name' => 'app-queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        OrbitProcess::factory()
            ->forOwner($node)
            ->create([
                'name' => 'node-agent',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        OrbitProcess::factory()
            ->forOwner($workspace, $node)
            ->create([
                'name' => 'workspace-queue',
                'runtime' => ProcessRuntime::Systemd,
            ]);
        DB::table('processes')->insert([
            'node_id' => $node->id,
            'owner_type' => 'workspace',
            'owner_id' => $workspace->id,
            'app_instance_id' => $instance->id,
            'name' => 'legacy-workspace-queue',
            'command' => 'php artisan queue:work',
            'restart_policy' => 'never',
            'crash_notification' => 'none',
            'runtime' => 'systemd',
            'tool' => null,
            'runtime_config' => '[]',
            'credentials' => null,
            'sort_order' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'docs.example.test',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'node.example.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'feature.docs.example.test',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'legacy-owner.docs.example.test',
            'owner_type' => 'workspace',
            'kind' => 'proxy',
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'legacy-kind.docs.example.test',
            'owner_type' => 'custom',
            'kind' => 'workspace',
        ]);
        $observedProxyDomains = [
            'docs.example.test',
            'node.example.test',
            'feature.docs.example.test',
            'legacy-owner.docs.example.test',
            'legacy-kind.docs.example.test',
            'unknown.example.test',
        ];
        $shell = new DoctorProductionProxyObservedRemoteShell($observedProxyDomains);
        app()->instance(RemoteShell::class, $shell);

        $runner = app(DoctorReportRunner::class);
        $processReport = $runner->probe($node, ['process']);
        $proxyReport = $runner->probe($node, ['proxy']);
        $scripts = implode("\n", $shell->scripts);
        $processNames = collect($processReport['issues'])
            ->where('family', 'process')
            ->pluck('detail.process')
            ->filter()
            ->values()
            ->all();
        $proxyDomains = collect($proxyReport['issues'])
            ->where('family', 'proxy')
            ->pluck('detail.domain')
            ->filter()
            ->values()
            ->all();

        expect($scripts)
            ->toContain('app-queue')
            ->toContain('node-agent')
            ->not->toContain('workspace-queue')
            ->not->toContain('legacy-workspace-queue')
            ->not->toContain('/srv/docs/.worktrees/feature')->toContain('docs.example.test')->toContain(
                'node.example.test',
            )
            ->not->toContain('feature.docs.example.test')
            ->not->toContain('legacy-owner.docs.example.test')
            ->not->toContain('legacy-kind.docs.example.test')->and($processNames)
            ->not->toContain('workspace-queue')
            ->not->toContain('legacy-workspace-queue')->and($proxyDomains)
            ->not->toContain('feature.docs.example.test')
            ->not->toContain('legacy-owner.docs.example.test')
            ->not->toContain('legacy-kind.docs.example.test')->toContain('unknown.example.test');
    });

    it('ignores forged selected workspace issues on production app nodes without side effects', function (): void {
        $node = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-forged-issues',
                'status' => 'active',
                'managed' => true,
            ]);
        $app = Project::factory()->for($node, 'node')->create([
            'name' => 'docs',
            'environment' => 'production',
            'path' => '/srv/docs',
        ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: $app->path,
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => 'feature',
            'path' => '/srv/docs/.worktrees/feature',
        ]);
        OrbitProcess::factory()
            ->forOwner($workspace, $node)
            ->create([
                'name' => 'workspace-runtime',
                'runtime_config' => [
                    'container_spec_hash_label' => WorkspaceRuntimeContainer::SpecHashLabel,
                ],
            ]);
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'feature.docs.example.test',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $connection = DatabaseConnection::factory()->create([
            'node_id' => $node->id,
            'slug' => 'feature-docs',
            'driver' => 'sqlite',
            'path' => '/srv/docs/.worktrees/feature/database/database.sqlite',
        ]);
        $shell = new DoctorReportRunnerRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        $actions = app(DoctorReportRunner::class)->apply($node, 'restore', [
            [
                'family' => 'process',
                'key' => 'process.runtime_unit_missing',
                'restorable' => true,
                'detail' => [
                    'process' => 'workspace-runtime',
                    'app' => $app->name,
                    'app_instance' => $instance->name,
                ],
            ],
            [
                'family' => 'proxy',
                'key' => 'proxy.route_missing',
                'restorable' => true,
                'detail' => ['domain' => 'feature.docs.example.test'],
            ],
            [
                'family' => 'database_connection',
                'key' => 'database_connection.target_missing',
                'restorable' => true,
                'detail' => [
                    'target_type' => 'workspace',
                    'target_id' => $workspace->id,
                    'env_prefix' => 'DB',
                    'database_connection_id' => $connection->id,
                ],
            ],
        ]);

        expect($actions)
            ->toBe([])
            ->and(DatabaseConnectionTarget::query()->where('workspace_id', $workspace->id)->exists())
            ->toBeFalse()
            ->and($shell->scripts)
            ->toBe([]);
        Http::assertNothingSent();
    });

    it('does not pass production workspace proxy routes to adoption', function (): void {
        $node = Node::factory()
            ->appProd()
            ->create([
                'name' => 'app-prod-proxy-adopt',
                'status' => 'active',
                'managed' => true,
            ]);
        $app = Project::factory()
            ->static()
            ->for($node, 'node')
            ->create([
                'name' => 'docs',
                'environment' => 'production',
            ]);
        $instance = AppInstance::factory()->for($app)->create([
            'name' => 'production',
            'driver_config' => new OrbitAppInstanceDriverConfigData(
                node_id: $node->id,
                node: $node->name,
                path: '/srv/docs',
            ),
        ]);
        $workspace = Workspace::factory()->create([
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'name' => 'feature',
        ]);
        $routes = [
            ['docs.example.test', 'app', 'app', $app->id, null],
            ['node.example.test', 'custom', 'proxy', null, null],
            ['feature.docs.example.test', 'workspace', 'workspace', $app->id, $workspace->id],
            ['legacy-owner.docs.example.test', 'workspace', 'proxy', null, null],
            ['legacy-kind.docs.example.test', 'custom', 'workspace', null, null],
        ];

        foreach ($routes as [$domain, $ownerType, $kind, $appId, $workspaceId]) {
            ProxyRoute::factory()->create([
                'node_id' => $node->id,
                'domain' => $domain,
                'app_id' => $appId,
                'workspace_id' => $workspaceId,
                'owner_type' => $ownerType,
                'kind' => $kind,
            ]);
        }

        $shell = new DoctorProductionProxyAdoptRemoteShell(array_column($routes, 0));
        app()->instance(RemoteShell::class, $shell);

        $report = app(DoctorReportRunner::class)->run($node, mode: 'adopt', families: ['proxy']);
        $actionKeys = collect($report['actions'])->pluck('key')->all();

        expect($actionKeys)
            ->toContain('docs.example.test')
            ->toContain('node.example.test')
            ->not->toContain('feature.docs.example.test')
            ->not->toContain('legacy-owner.docs.example.test')
            ->not->toContain('legacy-kind.docs.example.test');
    });

    it('marks node.local_executor_probe_failed as diagnostic-only in fleet probe fallback', function (): void {
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
        ) {
            $result = new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);

            throw new RemoteShellFailed($node, $script, $result);
        }

        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: "available\ttrue\ttrue\ttrue\n",
                stderr: '',
                durationMs: 1,
            );
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

        if (str_contains($script, 'internal:app-runtime-containers:probe')) {
            $nextResult = $this->results[0] ?? null;

            if (
                ! $nextResult instanceof RemoteShellResult
                || ! str_contains($nextResult->stdout, 'orbit-container-scan:')
            ) {
                return new RemoteShellResult(
                    exitCode: 0,
                    stdout: "orbit-container-scan:absent\n",
                    stderr: '',
                    durationMs: 1,
                );
            }
        }

        $result = array_shift($this->results);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/** @mago-expect lint:single-class-per-file */
final class DoctorProductionProxyObservedRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        private readonly array $domains,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(0, "available\ttrue\ttrue\ttrue\n", '', 1);
        }

        if (str_contains($script, 'for f in /etc/caddy/sites/*.caddy')) {
            $rows = array_map(
                static fn (string $domain): string => implode("\t", [
                    $domain,
                    hash('sha256', $domain),
                    '',
                    '',
                    '0',
                    '0',
                ]),
                $this->domains,
            );

            return new RemoteShellResult(0, implode("\n", $rows)."\n", '', 1);
        }

        if (str_contains($script, 'path="/etc/caddy/Caddyfile"')) {
            $content = collect($this->domains)
                ->map(static fn (string $domain): string => "{$domain} {\n    reverse_proxy http://127.0.0.1:8080\n}")
                ->implode("\n\n");

            return new RemoteShellResult(0, '1'."\t".base64_encode($content)."\n", '', 1);
        }

        return new RemoteShellResult(0, '', '', 1);
    }
}

/** @mago-expect lint:single-class-per-file */
final class DoctorProductionProxyAdoptRemoteShell implements RemoteShell
{
    /**
     * @param  list<string>  $domains
     */
    public function __construct(
        private readonly array $domains,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'body_b64')) {
            $rows = array_map(
                static fn (string $domain): string => implode("\t", [
                    $domain,
                    hash('sha256', $domain),
                    base64_encode("reverse_proxy 127.0.0.1:8080\n"),
                ]),
                $this->domains,
            );

            return new RemoteShellResult(0, implode("\n", $rows)."\n", '', 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(0, "available\ttrue\ttrue\ttrue\n", '', 1);
        }

        if (str_contains($script, 'path="/etc/caddy/Caddyfile"')) {
            return new RemoteShellResult(0, "0\t\n", '', 1);
        }

        return new RemoteShellResult(0, '', '', 1);
    }
}

/** @mago-expect lint:single-class-per-file */
final class DoctorReportRunnerAgentToolProxyRemoteShell implements RemoteShell
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return $this->success("available\ttrue\ttrue\ttrue\n");
        }

        if (str_contains($script, 'export ORBIT_PROXY_DOMAIN=')) {
            $route = ProxyRoute::query()->where('node_id', $node->id)->first();

            if (! $route instanceof ProxyRoute) {
                return $this->success();
            }

            return $this->success(implode("\t", [
                '1',
                $route->source_hash,
                "/etc/orbit/certs/{$route->domain}.crt",
                "/etc/orbit/certs/{$route->domain}.key",
                '1',
                '1',
                '',
                '',
                '0',
                '',
            ])
                ."\n");
        }

        if (str_contains($script, 'for f in /etc/caddy/sites/*.caddy')) {
            $rows = ProxyRoute::query()
                ->where('node_id', $node->id)
                ->get()
                ->map(static fn (ProxyRoute $route): string => implode("\t", [
                    $route->domain,
                    $route->source_hash,
                    "/etc/orbit/certs/{$route->domain}.crt",
                    "/etc/orbit/certs/{$route->domain}.key",
                    '1',
                    '1',
                ]))
                ->implode("\n");

            return $this->success($rows === '' ? '' : "{$rows}\n");
        }

        if (str_contains($script, 'path="/etc/caddy/Caddyfile"')) {
            $content = new CaddyGlobalConfig()->fresh();

            return $this->success('1'."\t".base64_encode($content)."\n");
        }

        return $this->success();
    }

    private function success(string $stdout = ''): RemoteShellResult
    {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: $stdout,
            stderr: '',
            durationMs: 1,
        );
    }
}

function doctorRunnerLocalExecutor(RemoteShell $remoteShell): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
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

function doctor_runner_fake_ca(): OrbitCaService
{
    return new readonly class extends OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }
    };
}

function doctor_runner_agent_tool_proxy_fake_ca(): OrbitCaService
{
    return new readonly class extends OrbitCaService {
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ntest-root-cert\n-----END CERTIFICATE-----\n";
        }

        /** @return array{cert: string, key: string} */
        public function issueLeaf(string $host, array $additionalSans = []): array
        {
            $directory = sys_get_temp_dir().'/orbit-doctor-agent-tool-proxy';
            File::ensureDirectoryExists($directory);
            $certificate = "{$directory}/{$host}.crt";
            $key = "{$directory}/{$host}.key";
            file_put_contents($certificate, "fake-cert-for-{$host}");
            file_put_contents($key, "fake-key-for-{$host}");

            return ['cert' => $certificate, 'key' => $key];
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
