<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Laravel\Requests\Dashboard\ShowRuntimeInventoryRequest;
use Orbit\Sdk\Laravel\Requests\Database\DetachDatabaseConnectionTargetRequest;
use Orbit\Sdk\Laravel\Requests\Database\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Laravel\Requests\Deploy\ListDeployHistoryRequest;
use Orbit\Sdk\Laravel\Requests\Deploy\ListDeployStepsRequest;
use Orbit\Sdk\Laravel\Requests\Deploy\RemoveDeployStepRequest;
use Orbit\Sdk\Laravel\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Laravel\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Laravel\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Laravel\Requests\Php\ShowPhpRuntimeRequest;
use Orbit\Sdk\Laravel\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Laravel\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Laravel\Requests\Workspaces\RemoveWorkspaceRequest;
use Orbit\Sdk\Laravel\Tests\TestCase;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;

uses(TestCase::class);

it('aligns core dashboard sdk request shapes with the gateway contract', function (): void {
    foreach (core_dashboard_request_contracts() as $contract) {
        $request = make_contract_request($contract['request'], $contract['arguments']);

        Assert::assertSame($contract['endpoint'], $request->resolveEndpoint(), "{$contract['name']} endpoint drifted.");
        Assert::assertSame($contract['method'], $request->getMethod(), "{$contract['name']} method drifted.");
        Assert::assertSame($contract['query'], $request->query()->all(), "{$contract['name']} query drifted.");

        if (! $request instanceof HasBody) {
            Assert::assertSame([], $contract['body'], "{$contract['name']} expected no request body.");

            continue;
        }

        Assert::assertSame($contract['body'], $request->body()->all(), "{$contract['name']} body drifted.");
    }
});

/**
 * @return list<array{
 *     name: string,
 *     request: class-string<GatewayRequest>,
 *     arguments: array<string, mixed>,
 *     method: Method,
 *     endpoint: string,
 *     query: array<string, mixed>,
 *     body: array<string, mixed>,
 * }>
 *
 * @mago-expect lint:halstead
 */
function core_dashboard_request_contracts(): array
{
    return [
        [
            'name' => 'GET /apps',
            'request' => ListAppsRequest::class,
            'arguments' => ['environment' => 'production'],
            'method' => Method::GET,
            'endpoint' => '/api/apps',
            'query' => ['environment' => 'production'],
            'body' => [],
        ],
        [
            'name' => 'GET /database-connections',
            'request' => ListDatabaseConnectionsRequest::class,
            'arguments' => ['app' => 'docs', 'workspace' => 'feature-docs', 'node' => 'app-1'],
            'method' => Method::GET,
            'endpoint' => '/api/database-connections',
            'query' => ['app' => 'docs', 'workspace' => 'feature-docs', 'node' => 'app-1'],
            'body' => [],
        ],
        [
            'name' => 'DELETE /database-connections/{connection}/targets',
            'request' => DetachDatabaseConnectionTargetRequest::class,
            'arguments' => [
                'connection' => 'main',
                'payload' => [
                    'app' => 'docs',
                    'instance' => 'development',
                    'env_prefix' => 'APP_DB',
                ],
            ],
            'method' => Method::DELETE,
            'endpoint' => '/api/database-connections/main/targets',
            'query' => [],
            'body' => ['app' => 'docs', 'instance' => 'development', 'env_prefix' => 'APP_DB'],
        ],
        [
            'name' => 'GET /dashboard/runtime-inventory',
            'request' => ShowRuntimeInventoryRequest::class,
            'arguments' => [],
            'method' => Method::GET,
            'endpoint' => '/api/dashboard/runtime-inventory',
            'query' => [],
            'body' => [],
        ],
        [
            'name' => 'GET /deploy/history',
            'request' => ListDeployHistoryRequest::class,
            'arguments' => ['app' => 'docs', 'limit' => 25],
            'method' => Method::GET,
            'endpoint' => '/api/deploy/history',
            'query' => ['app' => 'docs', 'limit' => 25],
            'body' => [],
        ],
        [
            'name' => 'GET /deploy/steps',
            'request' => ListDeployStepsRequest::class,
            'arguments' => ['app' => 'docs'],
            'method' => Method::GET,
            'endpoint' => '/api/deploy/steps',
            'query' => ['app' => 'docs'],
            'body' => [],
        ],
        [
            'name' => 'DELETE /deploy/steps/{step}',
            'request' => RemoveDeployStepRequest::class,
            'arguments' => ['app' => 'docs', 'step' => 'build'],
            'method' => Method::DELETE,
            'endpoint' => '/api/deploy/steps/build',
            'query' => ['app' => 'docs', 'destructive_consent' => true],
            'body' => [],
        ],
        [
            'name' => 'DELETE /firewall-rules/{name}',
            'request' => RemoveFirewallRuleRequest::class,
            'arguments' => ['name' => 'allow-http', 'node' => 'router-1'],
            'method' => Method::DELETE,
            'endpoint' => '/api/firewall-rules/allow-http',
            'query' => [],
            'body' => ['node' => 'router-1', 'destructive_consent' => true],
        ],
        [
            'name' => 'GET /nodes',
            'request' => ListNodesRequest::class,
            'arguments' => ['role' => 'app-prod', 'doctor' => true],
            'method' => Method::GET,
            'endpoint' => '/api/nodes',
            'query' => ['role' => 'app-prod', 'doctor' => true],
            'body' => [],
        ],
        [
            'name' => 'DELETE /nodes/{name}',
            'request' => RemoveNodeRequest::class,
            'arguments' => ['name' => 'app-1', 'destructiveConsentSource' => 'flag'],
            'method' => Method::DELETE,
            'endpoint' => '/api/nodes/app-1',
            'query' => [],
            'body' => ['destructive_consent' => true, 'destructive_consent_source' => 'flag'],
        ],
        [
            'name' => 'GET /php/runtime',
            'request' => ShowPhpRuntimeRequest::class,
            'arguments' => ['app' => 'docs', 'workspace' => 'feature-docs', 'node' => 'app-1', 'live' => true],
            'method' => Method::GET,
            'endpoint' => '/api/php/runtime',
            'query' => ['app' => 'docs', 'workspace' => 'feature-docs', 'node' => 'app-1', 'live' => true],
            'body' => [],
        ],
        [
            'name' => 'GET /processes',
            'request' => ListProcessesRequest::class,
            'arguments' => ['node' => 'app-1', 'app' => 'docs', 'workspace' => 'feature-docs'],
            'method' => Method::GET,
            'endpoint' => '/api/processes',
            'query' => ['node' => 'app-1', 'app' => 'docs', 'workspace' => 'feature-docs'],
            'body' => [],
        ],
        [
            'name' => 'GET /tools',
            'request' => ListToolsRequest::class,
            'arguments' => ['app' => 'docs', 'node' => 'app-1', 'self' => true],
            'method' => Method::GET,
            'endpoint' => '/api/tools',
            'query' => ['app' => 'docs', 'node' => 'app-1', 'self' => true],
            'body' => [],
        ],
        [
            'name' => 'DELETE /workspaces/{name}',
            'request' => RemoveWorkspaceRequest::class,
            'arguments' => ['name' => 'feature-docs', 'app' => 'docs', 'keepFiles' => true],
            'method' => Method::DELETE,
            'endpoint' => '/api/workspaces/feature-docs',
            'query' => ['app' => 'docs'],
            'body' => ['keep_files' => true, 'destructive_consent' => true, 'destructive_consent_source' => 'force'],
        ],
    ];
}

/**
 * @param  class-string<GatewayRequest>  $request
 * @param  array<string, mixed>  $arguments
 */
function make_contract_request(string $request, array $arguments): GatewayRequest
{
    $reflection = new ReflectionClass($request);
    $constructor = $reflection->getConstructor();

    if ($constructor === null) {
        Assert::assertSame([], $arguments, "{$request} does not accept constructor arguments.");

        return $reflection->newInstance();
    }

    $parameters = [];

    foreach ($constructor->getParameters() as $parameter) {
        $parameters[] = $parameter->getName();
    }

    Assert::assertSame(
        [],
        array_values(array_diff(array_keys($arguments), $parameters)),
        "{$request} is missing expected constructor arguments.",
    );

    $orderedArguments = [];

    foreach ($parameters as $parameter) {
        if (! array_key_exists($parameter, $arguments)) {
            continue;
        }

        $orderedArguments[] = $arguments[$parameter];
    }

    $instance = $reflection->newInstanceArgs($orderedArguments);

    Assert::assertInstanceOf(GatewayRequest::class, $instance);

    return $instance;
}
