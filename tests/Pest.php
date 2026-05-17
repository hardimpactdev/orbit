<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
    })
    ->in('Feature');

pest()->extend(TestCase::class, RefreshDatabase::class)
    ->in('Unit/Services/WireGuard');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        if (env('ORBIT_E2E') !== '1') {
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
