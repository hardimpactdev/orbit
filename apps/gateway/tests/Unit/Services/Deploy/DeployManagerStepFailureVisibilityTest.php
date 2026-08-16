<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\DeploymentRun;
use App\Models\DeployStep;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Deploy\DeployManager;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

/**
 * Answers every `internal:deploy:run-step` dispatch with a canned envelope so
 * the manager's handling of node-side failures can be asserted directly.
 */
final class DeployManagerEnvelopeShell implements RunsInternalCommands
{
    public function __construct(
        private readonly RemoteShellResult $response,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        return $this->response;
    }
}

function createDeployFailureVisibilityApp(): App
{
    $node = Node::factory()->appProd()->create(['name' => 'app-prod-visibility']);

    $app = App::factory()->create([
        'name' => 'visibility',
        'php_version' => '8.5',
        'runtime' => AppRuntimeKind::Php,
    ]);

    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/home/visibility/app',
            document_root: 'public',
            domain: "{$app->name}.example.com",
        ),
    ]);

    DeployStep::query()->create([
        'instance_id' => $instance->id,
        'title' => 'Configure production environment',
        'command' => 'true',
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);

    return $app;
}

function deployRunStepErrorEnvelope(string $code, string $message): RemoteShellResult
{
    return new RemoteShellResult(
        exitCode: 1,
        stdout: json_encode(JsonEnvelope::failure($code, $message), JSON_THROW_ON_ERROR),
        stderr: '',
        durationMs: 189,
    );
}

it('records the node-side failure message when a deploy step returns an error envelope', function (): void {
    createDeployFailureVisibilityApp();
    app()->instance(RunsInternalCommands::class, new DeployManagerEnvelopeShell(
        deployRunStepErrorEnvelope(
            'deploy_run_step_failed',
            'The provided cwd "/home/visibility/app" does not exist.',
        ),
    ));

    expect(fn (): array => app(DeployManager::class)->run('visibility.production'))
        ->toThrow(GatewayApiException::class);

    $step = DeploymentRun::query()->sole()->steps()->sole();

    expect($step->status)
        ->toBe('failed')
        ->and($step->stderr)
        ->toContain('The provided cwd "/home/visibility/app" does not exist.')
        ->and($step->stderr)
        ->toContain('deploy_run_step_failed')
        ->and($step->stderr)
        ->not->toBe('Deploy run step response is invalid.');
});

it('keeps node stderr when a deploy step answers with an unparsable envelope', function (): void {
    createDeployFailureVisibilityApp();
    app()->instance(RunsInternalCommands::class, new DeployManagerEnvelopeShell(
        new RemoteShellResult(
            exitCode: 127,
            stdout: 'not json',
            stderr: 'orbit: command not found',
            durationMs: 12,
        ),
    ));

    expect(fn (): array => app(DeployManager::class)->run('visibility.production'))
        ->toThrow(GatewayApiException::class);

    $step = DeploymentRun::query()->sole()->steps()->sole();

    expect($step->exit_code)
        ->toBe(127)
        ->and($step->stderr)
        ->toContain('orbit: command not found');
});

it('falls back to the protocol message when the node returns no usable failure detail', function (): void {
    createDeployFailureVisibilityApp();
    app()->instance(RunsInternalCommands::class, new DeployManagerEnvelopeShell(
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 5),
    ));

    expect(fn (): array => app(DeployManager::class)->run('visibility.production'))
        ->toThrow(GatewayApiException::class);

    $step = DeploymentRun::query()->sole()->steps()->sole();

    expect($step->exit_code)
        ->toBe(1)
        ->and($step->stderr)
        ->toBe('Deploy run step response is invalid.');
});
