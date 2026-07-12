<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Processes\ProcessDockerContainerApplyOutcome;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use App\Services\Convergence\ProcessDockerContainerResource;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationTokenFactory;
use App\Services\Processes\ProcessDockerContainer;
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

it('plans ok when the docker process container already matches gateway intent', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => process_docker_resource_agent_response(
            operationId: 'process.docker.probe',
            stdout: process_docker_resource_success_stdout([
                'action' => 'probe',
                'container' => 'orbit_docs_main_queue',
                'exists' => true,
                'spec_hash' => processDockerContainerResourceContainer()->specHash(),
                'inspection' => [
                    'Config' => [
                        'Labels' => [
                            ProcessDockerContainer::SpecHashLabel =>
                                processDockerContainerResourceContainer()->specHash(),
                        ],
                    ],
                ],
            ]),
        ),
    ]);
    $node = process_docker_resource_node();
    $container = processDockerContainerResourceContainer();
    $resource = process_docker_resource($container);
    $shell = new ProcessDockerContainerResourceUnusedShell;

    $probe = $resource->probe($node);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $plan);

    expect($probe->exists)
        ->toBeTrue()
        ->and($probe->specHash)
        ->toBe($container->specHash())
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($plan->outcome)
        ->toBe(ProcessDockerContainerApplyOutcome::Unchanged)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())
        ->toBeFalse();

    process_docker_resource_assert_action_request(
        action: 'probe',
        operationId: 'process.docker.probe',
        container: $container,
    );
});

it('ensures the docker network before creating a missing idle process container', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::sequence()
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.ensure-network',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'ensure-network',
                    'network' => 'orbit-network',
                    'changed' => true,
                ]),
            ))
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.probe',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'probe',
                    'container' => 'orbit_docs_main_queue',
                    'exists' => false,
                    'spec_hash' => null,
                    'inspection' => null,
                ]),
            ))
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.apply',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'apply',
                    'container' => 'orbit_docs_main_queue',
                    'outcome' => 'created',
                    'had_existing_container' => false,
                    'changed' => true,
                    'summary' => 'Applied Docker process container orbit_docs_main_queue.',
                ]),
            )),
    ]);
    $node = process_docker_resource_node();
    $container = processDockerContainerResourceContainer();
    $resource = process_docker_resource($container);
    $shell = new ProcessDockerContainerResourceUnusedShell;

    $resource->ensureNetwork($node, $shell);
    $probe = $resource->probe($node);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $plan);

    expect($probe->exists)
        ->toBeFalse()
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($plan->outcome)
        ->toBe(ProcessDockerContainerApplyOutcome::Created)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->details['outcome'])
        ->toBe('created');

    process_docker_resource_assert_recorded_actions(
        [
            'ensure-network',
            'probe',
            'apply',
        ],
        $container,
    );
});

it('removes and recreates a docker process container when the spec hash drifts', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::sequence()
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.probe',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'probe',
                    'container' => 'orbit_docs_main_queue',
                    'exists' => true,
                    'spec_hash' => 'old-hash',
                    'inspection' => [
                        'Config' => [
                            'Labels' => [
                                ProcessDockerContainer::SpecHashLabel => 'old-hash',
                            ],
                        ],
                    ],
                ]),
            ))
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.apply',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'apply',
                    'container' => 'orbit_docs_main_queue',
                    'outcome' => 'recreated',
                    'had_existing_container' => true,
                    'changed' => true,
                    'summary' => 'Applied Docker process container orbit_docs_main_queue.',
                ]),
            )),
    ]);
    $node = process_docker_resource_node();
    $container = processDockerContainerResourceContainer();
    $resource = process_docker_resource($container);
    $shell = new ProcessDockerContainerResourceUnusedShell;

    $probe = $resource->probe($node);
    $plan = $resource->plan($probe);
    $result = $resource->apply($node, $plan);

    expect($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($plan->outcome)
        ->toBe(ProcessDockerContainerApplyOutcome::Recreated)
        ->and($plan->details['observed_hash'])
        ->toBe('old-hash')
        ->and($result->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->details['outcome'])
        ->toBe('recreated');

    process_docker_resource_assert_recorded_actions(['probe', 'apply'], $container);
});

it('returns a failed apply result when applying the docker process container fails', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.72:9477/v1/commands' => Http::sequence()
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.probe',
                stdout: process_docker_resource_success_stdout([
                    'action' => 'probe',
                    'container' => 'orbit_docs_main_queue',
                    'exists' => false,
                    'spec_hash' => null,
                    'inspection' => null,
                ]),
            ))
            ->push(process_docker_resource_agent_payload(
                operationId: 'process.docker.apply',
                status: 'failed',
                exitCode: 9,
                stdout: json_encode([
                    'error' => [
                        'code' => 'docker_container.apply_failed',
                        'message' => 'Failed to create orbit_docs_main_queue container: image missing',
                        'meta' => [
                            'action' => 'apply',
                            'container' => 'orbit_docs_main_queue',
                            'had_existing_container' => false,
                            'exit_code' => 9,
                            'stderr' => 'image missing',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            )),
    ]);
    $node = process_docker_resource_node();
    $container = processDockerContainerResourceContainer();
    $resource = process_docker_resource($container);
    $shell = new ProcessDockerContainerResourceUnusedShell;

    $result = $resource->apply($node, $resource->plan($resource->probe($node)));

    expect($result->status)
        ->toBe(ConvergenceStatus::Failed)
        ->and($result->summary)
        ->toBe(
            'Failed to apply orbit_docs_main_queue container on app-dev-1: Failed to create orbit_docs_main_queue container: image missing',
        )
        ->and($result->successful())
        ->toBeFalse()
        ->and($result->details)
        ->toMatchArray([
            'container' => 'orbit_docs_main_queue',
            'network' => 'orbit-network',
            'outcome' => 'created',
            'exit_code' => 9,
        ]);
});

function processDockerContainerResourceContainer(): ProcessDockerContainer
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

function process_docker_resource(ProcessDockerContainer $container): ProcessDockerContainerResource
{
    return new ProcessDockerContainerResource(
        container: $container,
        localExecutor: new RemoteLocalExecutor(
            commands: new LocalExecutorCommandBuilder,
            operationTokens: new OperationTokenFactory(
                signer: new OperationTokenSigner,
                secret: process_docker_resource_operation_secret(),
                ttlSeconds: 120,
                clock: static fn (): int => 1_798_105_200,
            ),
            activityLogger: new ActivityLogger(new ActivityLogCorrelation),
            operationRuns: app(OperationRunRecorder::class),
            applicationKey: process_docker_resource_operation_secret(),
        ),
    );
}

function process_docker_resource_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'app-dev-1',
        'tld' => 'test',
        'wireguard_address' => '10.44.0.72',
        'managed' => true,
    ]);

    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a node model.');
    }

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => NodeRoleStatus::Active,
        'settings' => ['tld' => 'test'],
    ]);

    return $node;
}

function process_docker_resource_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $data
 */
function process_docker_resource_success_stdout(array $data): string
{
    return json_encode([
        'success' => [
            'data' => $data,
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);
}

function process_docker_resource_agent_response(
    string $operationId,
    string $stdout,
    string $status = 'succeeded',
    int $exitCode = 0,
): mixed {
    return Http::response(process_docker_resource_agent_payload($operationId, $stdout, $status, $exitCode));
}

/**
 * @return array<string, mixed>
 */
function process_docker_resource_agent_payload(
    string $operationId,
    string $stdout,
    string $status = 'succeeded',
    int $exitCode = 0,
): array {
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $status,
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => $stdout,
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

function process_docker_resource_assert_action_request(
    string $action,
    string $operationId,
    ProcessDockerContainer $container,
): void {
    Http::assertSent(function (Request $request) use ($action, $operationId, $container): bool {
        $argv = $request->data()['argv'] ?? null;
        $inputPayload = $request->data()['input'] ?? null;
        $input = is_string($inputPayload)
            ? json_decode($inputPayload, associative: true)
            : null;
        $spec = is_array($input) ? $input['spec'] ?? null : null;

        return (
            $request->url() === 'http://10.44.0.72:9477/v1/commands'
            && $request->data()['binary'] === 'orbit'
            && is_array($argv)
            && ($argv[0] ?? null) === 'internal:process-docker-container'
            && is_string($argv[1] ?? null)
            && str_starts_with($argv[1], '--operation-token=')
            && ($argv[2] ?? null) === '--json'
            && $request->data()['timeout_seconds'] === 120
            && $request->data()['stream'] === true
            && $request->data()['operation_id'] === $operationId
            && is_array($input)
            && $input['action'] === $action
            && is_array($spec)
            && $spec['name'] === 'orbit_docs_main_queue'
            && $spec['network'] === 'orbit-network'
            && $spec['expected_hash'] === $container->specHash()
        );
    });
}

/**
 * @param  list<string>  $actions
 */
function process_docker_resource_assert_recorded_actions(array $actions, ProcessDockerContainer $container): void
{
    expect(Http::recorded())->toHaveCount(count($actions));

    foreach ($actions as $action) {
        process_docker_resource_assert_action_request(
            action: $action,
            operationId: "process.docker.{$action}",
            container: $container,
        );
    }
}

final class ProcessDockerContainerResourceUnusedShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('Remote shell should not be called for process Docker container resources.');
    }
}

final class ProcessDockerContainerResourceUnusedTransport implements RemoteExecutor
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        throw new RuntimeException('SSH transport should not be called for process Docker container resources.');
    }

    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        throw new RuntimeException('Process Docker container resource tests do not start long-running transports.');
    }
}
