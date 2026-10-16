<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Deployments\CreateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\DeployInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\RollbackInstanceRequest;
use Orbit\Sdk\Requests\Deployments\UpdateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentReleasesResponse;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('deployment requests', function (): void {
    it('uses the exact Gateway methods and paths', function (): void {
        $cases = [
            [new DeployInstanceRequest(17), Method::POST, '/api/v1/instances/17/deploy'],
            [new RollbackInstanceRequest(17, 'release-20260911'), Method::POST, '/api/v1/instances/17/rollback'],
            [new ListInstanceReleasesRequest(17), Method::GET, '/api/v1/instances/17/releases'],
            [new ListInstanceDeployStepsRequest(17), Method::GET, '/api/v1/instances/17/deploy-steps'],
            [new CreateInstanceDeployStepRequest(17, 'migrate', 'php artisan migrate'), Method::POST, '/api/v1/instances/17/deploy-steps'],
            [new UpdateInstanceDeployStepRequest(17, 'migrate', hasCommand: true, command: 'php artisan migrate --force'), Method::PATCH, '/api/v1/instances/17/deploy-steps/migrate'],
            [new DestroyInstanceDeployStepRequest(17, 'migrate'), Method::DELETE, '/api/v1/instances/17/deploy-steps/migrate'],
            [new UpdateInstanceRequest(17, 'release'), Method::PATCH, '/api/v1/instances/17'],
        ];

        foreach ($cases as [$request, $method, $path]) {
            expect($request->getMethod())->toBe($method)
                ->and($request->resolveEndpoint())->toBe($path);
        }
    });

    it('preserves omitted shared paths explicit lists and the default reset', function (): void {
        expect(new UpdateInstanceRequest(17, 'release')->body()->all())->toBe(['branch' => 'release']);
        expect(new UpdateInstanceRequest(17, sharedPaths: ['storage'], sharedPathsProvided: true)->body()->all())->toBe(['shared_paths' => ['storage']]);
        expect(new UpdateInstanceRequest(17, sharedPaths: [], sharedPathsProvided: true)->body()->all())->toBe(['shared_paths' => []]);
        expect(new UpdateInstanceRequest(17, sharedPathsProvided: true)->body()->all())->toBe(['shared_paths' => null]);
        expect(new UpdateInstanceRequest(17, 'release', [], true)->body()->all())->toBe(['branch' => 'release', 'shared_paths' => []]);
    });

    it('sends only the Instance fields it changes', function (): void {
        expect(new UpdateInstanceRequest(17, 'release')->body()->all())->toBe(['branch' => 'release'])
            ->and(new UpdateInstanceRequest(17, autoDeploy: false)->body()->all())->toBe(['auto_deploy' => false])
            ->and(new UpdateInstanceRequest(17, 'release', autoDeploy: true)->body()->all())->toBe(['branch' => 'release', 'auto_deploy' => true]);
    });

    it('carries no deployment-config or deployment-layout request', function (): void {
        $paths = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 4).'/src/Requests'),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        expect(implode("\n", $paths))
            ->not->toContain('DeploymentConfig')
            ->not->toContain('DeploymentLayout')
            ->and($paths)
            ->not->toContain(dirname(__DIR__, 4).'/src/Requests/Deployments/ShowInstanceDeploymentConfigRequest.php')
            ->not->toContain(dirname(__DIR__, 4).'/src/Requests/Deployments/UpdateInstanceDeploymentConfigRequest.php')
            ->not->toContain(dirname(__DIR__, 4).'/src/Requests/Instances/InstanceDeploymentLayoutRequest.php');
    });

    it('preserves deployment payload omission and explicit values', function (): void {
        $omitted = new CreateInstanceDeployStepRequest(17, 'migrate', 'secret-command-one');
        $explicit = new CreateInstanceDeployStepRequest(17, 'restart', 'secret-command-two', 'after_activation', 0);
        $deploy = new DeployInstanceRequest(17);
        $rollback = new RollbackInstanceRequest(17, '');

        expect($omitted->body()->all())->toBe([
            'name' => 'migrate',
            'command' => 'secret-command-one',
        ])->and($explicit->body()->all())->toBe([
            'name' => 'restart',
            'command' => 'secret-command-two',
            'phase' => 'after_activation',
            'timeout_seconds' => 0,
        ])->and((string) $deploy->body())->toBe('{}')
            ->and($rollback->body()->all())->toBe(['release' => '']);
    });

    it('maps retained releases through typed correlated responses', function (): void {
        $requestId = deployment_request_id();
        $mock = new MockClient([
            ListInstanceReleasesRequest::class => MockResponse::make([
                'data' => ['releases' => ['release-b', 'release-a'], 'selected_release' => 'release-b'],
                'meta' => ['request_id' => $requestId],
            ]),
        ]);
        $connector = deployment_connector($mock);

        $releases = $connector->send(new ListInstanceReleasesRequest(17))->dto();

        expect($releases)->toBeInstanceOf(DeploymentReleasesResponse::class)
            ->and($releases->releases)->toBe(['release-b', 'release-a'])
            ->and($releases->selectedRelease)->toBe('release-b')
            ->and($releases->requestId)->toBe($requestId);
    });

    it('rejects invalid typed retained-release responses', function (): void {
        $requestId = deployment_request_id();

        expect(fn (): DeploymentReleasesResponse => DeploymentReleasesResponse::fromGatewayData([
            'releases' => ['release-a', 'release-a'],
            'selected_release' => 'release-a',
        ], $requestId))->toThrow(
            GatewayApiException::class,
            'Gateway response contains invalid retained release data.',
        )->and(fn (): DeploymentReleasesResponse => DeploymentReleasesResponse::fromGatewayData([
            'releases' => ['release-a'],
            'selected_release' => 'release-b',
        ], $requestId))->toThrow(
            GatewayApiException::class,
            'Gateway response contains invalid retained release data.',
        );
    });

    it('sends a deploy as an empty JSON object and a rollback as its release', function (): void {
        expect((string) new DeployInstanceRequest(17)->body())->toBe('{}')
            ->and((string) new RollbackInstanceRequest(17, 'release-a')->body())->toBe('{"release":"release-a"}');
    });

    it('returns a started deploy as its queued operation', function (): void {
        $requestId = deployment_request_id();
        $mock = new MockClient([
            DeployInstanceRequest::class => MockResponse::make([
                'data' => [
                    'id' => 42, 'kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => 17, 'input' => [],
                    'status' => 'queued', 'sequence' => 1, 'current_step' => null, 'failed_step' => null, 'error_code' => null,
                    'result' => null, 'cancel_requested' => false, 'attempts' => 0, 'triggered_by' => 'laptop',
                    'created_at' => '2026-10-15T10:00:00+00:00', 'started_at' => null, 'finished_at' => null, 'poll_after_seconds' => 2,
                ],
                'meta' => ['request_id' => $requestId],
            ], 202, ['X-Orbit-Request-Id' => $requestId]),
        ]);

        $operation = deployment_connector($mock)->send(new DeployInstanceRequest(17))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->id)->toBe(42)
            ->and($operation->kind)->toBe('instance.deploy')
            ->and($operation->status)->toBe('queued')
            ->and($operation->terminal())->toBeFalse()
            ->and($operation->pollAfterSeconds)->toBe(2)
            ->and($operation->requestId)->toBe($requestId)
            ->and($mock->getLastPendingRequest()?->headers()->get('Accept'))->toBe('application/json');
    });

    it('returns a started rollback as its queued operation', function (): void {
        $requestId = deployment_request_id();
        $mock = new MockClient([
            RollbackInstanceRequest::class => MockResponse::make([
                'data' => [
                    'id' => 43, 'kind' => 'instance.rollback', 'target_type' => 'instance', 'target_id' => 17, 'input' => ['release' => 'release-a'],
                    'status' => 'queued', 'sequence' => 1, 'current_step' => null, 'failed_step' => null, 'error_code' => null,
                    'result' => null, 'cancel_requested' => false, 'attempts' => 0, 'triggered_by' => 'laptop',
                    'created_at' => '2026-10-16T10:00:00+00:00', 'started_at' => null, 'finished_at' => null, 'poll_after_seconds' => 2,
                ],
                'meta' => ['request_id' => $requestId],
            ], 202, ['X-Orbit-Request-Id' => $requestId]),
        ]);

        $operation = deployment_connector($mock)->send(new RollbackInstanceRequest(17, 'release-a'))->dto();

        expect($operation)->toBeInstanceOf(OperationResponse::class)
            ->and($operation->id)->toBe(43)
            ->and($operation->kind)->toBe('instance.rollback')
            ->and($operation->input)->toBe(['release' => 'release-a'])
            ->and($operation->status)->toBe('queued')
            ->and($operation->requestId)->toBe($requestId)
            ->and($mock->getLastPendingRequest()?->body()?->all())->toBe(['release' => 'release-a'])
            ->and($mock->getLastPendingRequest()?->headers()->get('Accept'))->toBe('application/json');
    });
});

function deployment_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

function deployment_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a846';
}
