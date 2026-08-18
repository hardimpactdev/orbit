<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Apps\AppSetupStepLocalExecutor;
use App\Services\Nodes\NodeIdentityArtifactProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\RemoteLaunchdService;
use App\Services\Processes\RemoteRuntimeDependencies;
use App\Services\Processes\RemoteRuntimeHibernation;
use App\Services\Processes\RuntimeHibernationScope;
use App\Services\Proxy\RemoteCaddyConfig;
use App\Services\RemoteShell\RemoteSecretFile;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\RuntimeBackend\GatewayRuntimeBackendProbe;
use App\Services\Schedules\ScheduleDispatcher;
use App\Services\Schedules\ScheduleInstanceResolver;
use App\Services\Tools\ToolScriptDispatcher;
use App\Services\Workspaces\WorkspaceSetupStepLocalExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * @return array<string, string>
 */
function remote_envelope_fail_closed_stdout(): array
{
    return [
        'empty output' => '',
        'malformed JSON' => '{"success":',
        'missing success.data' => '{"success":{"meta":[]}}',
        'invalid success.data' => '{"success":{"data":"invalid","meta":[]}}',
    ];
}

function remote_envelope_fail_closed_result(string $stdout): RemoteShellResult
{
    return new RemoteShellResult(0, $stdout, '', 1);
}

function remote_envelope_fail_closed_node(): Node
{
    return Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-envelope',
            'wireguard_address' => '10.9.0.21',
        ]);
}

final class RemoteEnvelopeFailClosedExecutor implements RunsInternalCommands
{
    public int $calls = 0;

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $this->calls++;

        return $this->result;
    }
}

it('treats a malformed tool script envelope as an invalid halt, not a successful script', function (string $stdout): void {
    $executor = new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout));
    $result = new ToolScriptDispatcher($executor)->run(
        remote_envelope_fail_closed_node(),
        'php',
        'install',
        'true',
    );

    expect($result->successful())
        ->toBeFalse()
        ->and($result->stderr)
        ->toBe('Tool run response is invalid.');
})->with(remote_envelope_fail_closed_stdout());

it('does not stage a remote secret after a malformed staging envelope', function (string $stdout): void {
    $executor = new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout));
    $called = false;

    expect(fn () => new RemoteSecretFile($executor)->stage(
        remote_envelope_fail_closed_node(),
        'secret',
        function () use (&$called): never {
            $called = true;

            throw new RuntimeException('callback must not run');
        },
    ))
        ->toThrow(RuntimeException::class, 'Remote secret file staging returned an invalid success envelope.')
        ->and($called)
        ->toBeFalse()
        ->and($executor->calls)
        ->toBe(1);
})->with(remote_envelope_fail_closed_stdout());

it('treats a malformed app setup-step envelope as a failed step', function (string $stdout): void {
    $result = new AppSetupStepLocalExecutor(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    )->run(remote_envelope_fail_closed_node(), 'true', '/tmp', 30, []);

    expect($result->successful())
        ->toBeFalse()
        ->and($result->stderr)
        ->toBe('App setup step response is invalid.');
})->with(remote_envelope_fail_closed_stdout());

it('treats a malformed workspace setup-step envelope as a failed step', function (string $stdout): void {
    $result = new WorkspaceSetupStepLocalExecutor(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    )->run(remote_envelope_fail_closed_node(), 'true', '/tmp', 30, []);

    expect($result->successful())
        ->toBeFalse()
        ->and($result->stderr)
        ->toBe('Workspace setup step response is invalid.');
})->with(remote_envelope_fail_closed_stdout());

it('does not report a launchd apply as changed after a malformed envelope', function (string $stdout): void {
    $result = new RemoteLaunchdService(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    )->apply(remote_envelope_fail_closed_node(), 'dev.hardimpact.orbit.queue', '<plist></plist>');

    expect($result->status)
        ->toBe(ConvergenceStatus::Failed)
        ->and($result->details['error'] ?? null)
        ->toStartWith('Apply returned an invalid success envelope:');
})->with(remote_envelope_fail_closed_stdout());

it('does not treat a malformed hibernation envelope as empty observed state', function (string $stdout): void {
    $states = new RemoteRuntimeHibernation(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    )->states(remote_envelope_fail_closed_node(), ['app-instance-1']);

    expect($states)->toBeNull();
})->with(remote_envelope_fail_closed_stdout());

it('does not restore runtime dependencies after a malformed inspect envelope', function (string $stdout): void {
    $node = remote_envelope_fail_closed_node();
    $app = App::factory()->create([
        'name' => 'docs',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);
    $instance = Instance::factory()->for($app, 'app')->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/orbit/apps/docs',
            document_root: 'public',
        ),
    ]);
    $scope = new RuntimeHibernationScope(
        type: 'app-instance',
        id: $instance->id,
        node: $node,
        context: ProcessOwnerContext::forInstance($node, $instance),
    );
    $executor = new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout));
    $dependencies = new RemoteRuntimeDependencies($executor);

    expect($dependencies->inspect($scope))
        ->toBeNull()
        ->and($dependencies->restoreIfMissing($scope, 'composer'))
        ->toBeFalse()
        ->and($executor->calls)
        ->toBe(2);
})->with(remote_envelope_fail_closed_stdout());

it('treats a malformed schedule run envelope as a failed dispatch', function (string $stdout): void {
    $node = remote_envelope_fail_closed_node();
    $schedule = Schedule::factory()->forNode($node)->create();
    $result = new ScheduleDispatcher(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
        new NodeRoleAssignments,
        app(ScheduleInstanceResolver::class),
    )->run($schedule);

    expect($result->successful())
        ->toBeFalse()
        ->and($result->run->stderr)
        ->toBe('Schedule run response is invalid.');
})->with(remote_envelope_fail_closed_stdout());

it('does not treat a malformed global Caddy envelope as readable config', function (string $stdout): void {
    expect(
        new RemoteCaddyConfig(
            new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
        )->readGlobal(remote_envelope_fail_closed_node()),
    )->toBeNull();
})->with(remote_envelope_fail_closed_stdout());

it('does not treat a malformed identity envelope as a WireGuard public key', function (string $stdout): void {
    expect(fn () => new NodeIdentityArtifactProbe(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    )->read(remote_envelope_fail_closed_node()))
        ->toThrow(RuntimeException::class, 'invalid success envelope');
})->with(remote_envelope_fail_closed_stdout());

it('does not treat a malformed gateway runtime envelope as a missing container', function (string $stdout): void {
    $node = remote_envelope_fail_closed_node();
    $probe = new GatewayRuntimeBackendProbe(
        new RemoteEnvelopeFailClosedExecutor(remote_envelope_fail_closed_result($stdout)),
    );
    $result = $probe->check($node);

    expect($result->runtimeStatus)
        ->toBe('unverifiable')
        ->and($result->output)
        ->toStartWith('Probe returned an invalid success envelope:')
        ->and($probe->diff($node, new ProbeSnapshot([
            'orbit-gateway' => [
                'runtime_status' => $result->runtimeStatus,
                'container_exists' => $result->containerExists,
                'container_running' => $result->containerRunning,
            ],
        ]))[0]->key)
        ->toBe('node.remote_shell_probe_failed')
        ->and($probe->diff($node, new ProbeSnapshot([
            'orbit-gateway' => [
                'runtime_status' => $result->runtimeStatus,
                'container_exists' => $result->containerExists,
                'container_running' => $result->containerRunning,
            ],
        ]))[0]->kind->value)
        ->toBe('unverifiable');
})->with(remote_envelope_fail_closed_stdout());
