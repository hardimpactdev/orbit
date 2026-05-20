<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Tests\TestCase;

require_once __DIR__.'/E2E/Support/Pest.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        config(['orbit.is_gateway' => true]);

        if (orbitIsDnsCommandTest($this)) {
            $storagePath = orbitDnsTestStoragePath();

            File::deleteDirectory($storagePath);
            File::ensureDirectoryExists($storagePath);

            app()->useStoragePath($storagePath);
        }
    })
    ->afterEach(function (): void {
        if (orbitIsDnsCommandTest($this)) {
            $storagePath = storage_path();

            app()->useStoragePath(base_path('storage'));

            if (str_starts_with($storagePath, base_path('storage/framework/testing/dns/'))) {
                File::deleteDirectory($storagePath);
            }
        }
    })
    ->in('Feature');

pest()->extend(TestCase::class, RefreshDatabase::class)
    ->in('Unit/Services/WireGuard');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        if (env('ORBIT_E2E') !== '1' && orbitE2eRequiresEnvironment($this)) {
            $this->markTestSkipped('Set ORBIT_E2E=1 to run ephemeral E2E tests.');
        }
    })
    ->group('e2e')
    ->in('E2E');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * @param  array<string, mixed>|string|null  $body
 * @param  array<string, mixed>|string|null  $rootCaBody
 */
function fakeGatewayIdentity(
    array|string|null $body = null,
    int $status = 200,
    array|string|null $rootCaBody = null,
    int $rootCaStatus = 200,
): MockClient {
    MockClient::destroyGlobal();

    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(
            $body ?? gatewayIdentityEnvelope(),
            $status,
        ),
        'http://10.6.0.2/api/ca/root' => MockResponse::make(
            $rootCaBody ?? gatewayCaEnvelope(),
            $rootCaStatus,
        ),
    ]);
}

/**
 * @return array<string, mixed>
 */
function gatewayCaEnvelope(string $pem = "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----"): array
{
    return [
        'success' => [
            'data' => [
                'root_ca' => $pem,
            ],
        ],
    ];
}

function fakeGatewayCaRootThroughLaravelHttp(): MockClient
{
    MockClient::destroyGlobal();

    return MockClient::global([
        'http://10.6.0.2/api/ca/root' => function (PendingRequest $request): MockResponse {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->get($request->getUrl());

            return MockResponse::make(
                $response->body(),
                $response->status(),
                $response->headers(),
            );
        },
        'https://10.6.0.2/api/ca/root' => function (PendingRequest $request): MockResponse {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withoutVerifying()
                ->acceptJson()
                ->get($request->getUrl());

            return MockResponse::make(
                $response->body(),
                $response->status(),
                $response->headers(),
            );
        },
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $settings
 */
function createTestAppHostNode(array $attributes = [], string $role = 'app-development', array $settings = ['tld' => 'test']): Node
{
    $environment = $role === 'app-production' ? 'production' : 'development';

    $node = Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'environment' => $environment,
        'tld' => $settings['tld'] ?? null,
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-development' ? $settings : [],
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createTestGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
        'role' => 'gateway',
        'status' => 'active',
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function createPhpLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

/**
 * @param  array<string, mixed>  $config
 */
function createPhpTool(Node $node, array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'expected_state' => 'running',
        'config' => array_merge([
            'versions' => ['8.5', '8.4'],
            'cli_version' => '8.5',
        ], $config),
    ]);
}

function vpnLocalNode(string $role): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);
}

function bindVpnBackend(ArrayVpnBackend $backend): void
{
    app()->instance(VpnBackend::class, $backend);
}

function bindDevelopmentDnsMappingTestDoubles(string $scope): DevelopmentDnsMappingEnactor
{
    $safeScope = preg_replace('/[^a-z0-9-]+/i', '-', $scope) ?: 'development-dns';
    $configDir = storage_path("framework/testing/{$safeScope}/".bin2hex(random_bytes(6)));
    $enactor = new DevelopmentDnsMappingEnactor($configDir);

    app()->instance(DevelopmentDnsMappingEnactor::class, $enactor);
    app()->instance(DevelopmentDnsMappingProbe::class, new DevelopmentDnsMappingProbe($enactor));

    return $enactor;
}

function orbitDnsTestStoragePath(): string
{
    $token = ParallelTesting::token();
    $suffix = $token === false ? 'single' : "parallel-{$token}";

    return base_path("storage/framework/testing/dns/{$suffix}");
}

function orbitIsDnsCommandTest(object $testCase): bool
{
    return str_contains(orbitPestTestFilename($testCase), 'tests/Feature/Commands/Dns/');
}

function fakeHomebrewPrefix(): string
{
    $prefix = storage_path('framework/testing/homebrew');

    File::ensureDirectoryExists("{$prefix}/bin");
    File::ensureDirectoryExists("{$prefix}/etc");

    return $prefix;
}

function orbitE2eRequiresEnvironment(object $testCase): bool
{
    $filename = orbitPestTestFilename($testCase);

    return ! str_ends_with($filename, 'tests/E2E/Ephemeral/AgentNodeProvisioningTest.php');
}

function orbitPestTestFilename(object $testCase): string
{
    try {
        $property = new ReflectionProperty($testCase::class, '__filename');
        $property->setAccessible(true);
        $filename = $property->getValue();
    } catch (ReflectionException) {
        return '';
    }

    return is_string($filename) ? str_replace('\\', '/', $filename) : '';
}

/**
 * @param  array<string, mixed>  $self
 * @param  array<string, mixed>  $gateway
 * @return array<string, mixed>
 */
function gatewayIdentityEnvelope(array $self = [], array $gateway = []): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                    'role' => 'control',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.8'],
                    ...$self,
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'role' => 'gateway',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.2'],
                    ...$gateway,
                ],
            ],
        ],
    ];
}

final class PruneAppActionTestAdapter implements AgentIdeMessageAdapter
{
    public function activeSession(array $target, string $adapter): ?array
    {
        return null;
    }

    public function deliver(array $target, string $adapter, array $session, string $message): array
    {
        return ['status' => 'failed'];
    }

    public function workspaces(array $target, string $adapter): array
    {
        return ['active-ws'];
    }
}
