<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Exceptions\ProcessDockerContainerApplyException;
use App\Models\Node;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Processes\ProcessDockerContainer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\RemoteShell\LocalExecutorCommandBuilder;
use App\Services\RemoteShell\RemoteExecutor;
use App\Services\RemoteShell\RemoteLocalExecutor;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('converges a missing docker process container through the agent-push local executor', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'process.docker.apply',
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'success' => [
                            'data' => [
                                'action' => 'apply',
                                'container' => 'orbit_docs_main_queue',
                                'outcome' => 'created',
                                'had_existing_container' => false,
                                'changed' => true,
                                'summary' => 'Applied Docker process container orbit_docs_main_queue.',
                            ],
                            'meta' => [],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'beast',
            'wireguard_address' => '10.44.0.72',
        ]);
    $container = processDockerRuntimeManagerContainer();

    $outcome = new ProcessDockerRuntimeManager(process_docker_runtime_manager_executor())
        ->apply($node, $container);

    expect($outcome)->toBe(ProcessDockerContainerApplyOutcome::Created);

    Http::assertSent(function (Request $request) use ($container): bool {
        $input = json_decode((string) $request['input'], associative: true);
        $spec = is_array($input) ? $input['spec'] ?? null : null;

        return (
            $request->url() === 'http://10.44.0.72:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:process-docker-container'
            && str_starts_with((string) $request['argv'][1], '--operation-token=')
            && $request['argv'][2] === '--json'
            && $request['timeout_seconds'] === 120
            && $request['stream'] === true
            && $request['operation_id'] === 'process.docker.apply'
            && is_array($spec)
            && $input['action'] === 'apply'
            && $spec['name'] === 'orbit_docs_main_queue'
            && $spec['network'] === 'orbit-network'
            && $spec['expected_hash'] === $container->specHash()
        );
    });
});

it('wraps docker process container agent apply failures with the existing had-existing flag', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => 'process.docker.apply',
            'binary' => 'orbit',
            'status' => 'failed',
            'exit_code' => 1,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => json_encode([
                        'error' => [
                            'code' => 'docker_container.apply_failed',
                            'message' => 'Failed to remove drifted orbit_docs_main_queue container: permission denied',
                            'meta' => [
                                'action' => 'apply',
                                'container' => 'orbit_docs_main_queue',
                                'had_existing_container' => true,
                                'exit_code' => 1,
                                'stderr' => 'permission denied',
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'exit',
                    'message' => '1',
                ],
            ],
        ]),
    ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'beast',
            'wireguard_address' => '10.44.0.72',
        ]);
    $container = processDockerRuntimeManagerContainer();

    try {
        new ProcessDockerRuntimeManager(process_docker_runtime_manager_executor())
            ->apply($node, $container);
    } catch (ProcessDockerContainerApplyException $exception) {
        expect($exception->hadExistingContainer)
            ->toBeTrue()
            ->and($exception->getMessage())
            ->toBe('Failed to remove drifted orbit_docs_main_queue container: permission denied');

        return;
    }

    $this->fail('Expected process Docker container apply exception was not thrown.');
});

it('runs docker container lifecycle actions through the agent-push local executor', function (string $action): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::response([
            'transport' => 'agent-push',
            'operation_id' => "process.docker.{$action}",
            'binary' => 'orbit',
            'status' => 'succeeded',
            'exit_code' => 0,
            'frames' => [
                [
                    'type' => 'stdout',
                    'message' => "{\"success\":{\"data\":{\"ok\":true},\"meta\":[]}}\n",
                ],
                [
                    'type' => 'exit',
                    'message' => '0',
                ],
            ],
        ]),
    ]);
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'beast',
            'wireguard_address' => '10.44.0.72',
        ]);
    $manager = new ProcessDockerRuntimeManager(
        process_docker_runtime_manager_executor(),
    );

    $result = match ($action) {
        'remove' => $manager->remove($node, 'orbit_docs_main_queue'),
        'restart' => $manager->restart($node, 'orbit_docs_main_queue'),
        'start' => $manager->start($node, 'orbit_docs_main_queue'),
        'stop' => $manager->stop($node, 'orbit_docs_main_queue'),
    };

    expect($result)->toBeTrue();

    Http::assertSent(function (Request $request) use ($action): bool {
        $input = json_decode((string) $request['input'], associative: true);

        return (
            $request->url() === 'http://10.44.0.72:9477/v1/commands'
            && $request['binary'] === 'orbit'
            && $request['argv'][0] === 'internal:process-docker-container'
            && str_starts_with((string) $request['argv'][1], '--operation-token=')
            && $request['argv'][2] === '--json'
            && $request['timeout_seconds'] === 120
            && $request['stream'] === true
            && $request['operation_id'] === "process.docker.{$action}"
            && $input === [
                'action' => $action,
                'container' => 'orbit_docs_main_queue',
            ]
        );
    });
})->with(['remove', 'restart', 'start', 'stop']);

function processDockerRuntimeManagerContainer(): ProcessDockerContainer
{
    return new ProcessDockerContainer(
        name: 'orbit_docs_main_queue',
        image: 'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm',
        network: 'orbit-network',
        restartPolicy: 'always',
        appSlug: 'docs',
        workspaceSlug: null,
        processSlug: 'queue',
        workingDirectory: ProcessDockerContainer::SourceTarget,
        command: 'php artisan queue:work',
        environment: [
            'APP_URL' => 'https://docs.orbit.test',
            'ORBIT_APP' => 'docs',
        ],
        mounts: [
            [
                'source' => '/srv/docs',
                'target' => ProcessDockerContainer::SourceTarget,
                'read_only' => false,
            ],
        ],
        networkAliases: ['orbit_docs_main_queue'],
    );
}

function process_docker_runtime_manager_executor(): RemoteLocalExecutor
{
    return new RemoteLocalExecutor(
        commands: new LocalExecutorCommandBuilder,
        operationTokens: new OperationTokenFactory(
            signer: new OperationTokenSigner,
            secret: process_docker_runtime_manager_operation_secret(),
            ttlSeconds: 120,
            clock: static fn (): int => 1_798_105_200,
        ),
        activityLogger: new ActivityLogger(new ActivityLogCorrelation),
        operationRuns: app(OperationRunRecorder::class),
        applicationKey: process_docker_runtime_manager_operation_secret(),
    );
}

function process_docker_runtime_manager_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

final class ProcessDockerRuntimeManagerUnusedTransport implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for process Docker runtime manager actions.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process Docker lifecycle tests do not start long-running transports.');
    }
}
