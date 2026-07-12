<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use App\Services\Nodes\DevelopmentDnsMappingProbe;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Vpn\ArrayVpnBackend;
use App\Services\Vpn\VpnBackend;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Testing\ParallelRunner;
use Orbit\Core\Enums\InternalCommand;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Sdk\Laravel\Requests\Gateway\ShowGatewayIdentityRequest;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Testing\GatewayPendingRequest;
use Tests\TestCase;

(static function (): void {
    if (getenv('ORBIT_E2E') === '1') {
        return;
    }

    $home = __DIR__.'/../storage/framework/testing/home';
    $configRoot = $home.'/.config/orbit';

    if (! is_dir($configRoot) && ! @mkdir($configRoot, 0777, true) && ! is_dir($configRoot)) {
        throw new RuntimeException("Unable to create test Orbit config directory [{$configRoot}].");
    }

    $envPath = $configRoot.'/.env';
    $appKey = getenv('APP_KEY');

    if (! is_string($appKey) || trim($appKey) === '') {
        $appKey = 'base64:'.base64_encode(str_repeat('0', 32));
    }

    if (! is_file($envPath)) {
        file_put_contents($envPath, implode(PHP_EOL, [
            'APP_NAME=Orbit',
            'APP_ENV=testing',
            "APP_KEY={$appKey}",
            '',
        ]));
    }

    putenv('HOME='.$home);
    $_ENV['HOME'] = $home;
    $_SERVER['HOME'] = $home;
})();

require_once __DIR__.'/E2E/Support/Pest.php';

ParallelRunner::resolveApplicationUsing(function (): Application {
    $app = require __DIR__.'/../bootstrap/app.php';

    $app->make(Kernel::class)->bootstrap();

    return $app;
});

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

pest()
    ->extend(TestCase::class)
    ->beforeEach(function (): void {
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

pest()
    ->extend(TestCase::class)
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
): void {
    GatewayMockClient::destroyGlobal();

    GatewayMockClient::global([
        ShowGatewayIdentityRequest::class => GatewayMockResponse::make(
            $body ?? gatewayIdentityEnvelope(),
            $status,
        ),
        'http://10.6.0.2/api/ca/root' => GatewayMockResponse::make(
            $rootCaBody ?? gatewayCaEnvelope(),
            $rootCaStatus,
        ),
    ]);
}

function bind_tool_script_dispatcher_to_remote_shell(): void
{
    app()->bind(
        RunsInternalCommands::class,
        fn (): RunsInternalCommands => new ToolScriptDispatcherRemoteShellExecutor(app(RemoteShell::class)),
    );
}

function bind_unavailable_tool_script_dispatcher(): void
{
    app()->instance(RunsInternalCommands::class, new ToolScriptUnavailableInternalExecutor);
}

final readonly class ToolScriptDispatcherRemoteShellExecutor implements RunsInternalCommands
{
    public function __construct(
        private RemoteShell $remoteShell,
    ) {}

    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($commandName !== InternalCommand::ToolRunScript->value) {
            return $this->remoteShell->run(
                $node,
                implode(' ', [$commandName, ...array_map(escapeshellarg(...), array_map(strval(...), $arguments))]),
                $transportOptions,
            );
        }

        $payload = json_decode(
            (string) ($transportOptions['input'] ?? ''),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($payload) || ! is_string($payload['script'] ?? null)) {
            return new RemoteShellResult(
                exitCode: 1,
                stdout: json_encode(JsonEnvelope::success([
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => 'Tool run payload is invalid.',
                    'duration_ms' => 1,
                ]), JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 1,
            );
        }

        $result = $this->remoteShell->run(
            $node,
            $payload['script'],
            ['throw' => (bool) ($transportOptions['throw'] ?? false)],
        );

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode(JsonEnvelope::success([
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
                'duration_ms' => $result->durationMs,
            ]), JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: $result->durationMs,
        );
    }
}

final readonly class ToolScriptUnavailableInternalExecutor implements RunsInternalCommands
{
    /**
     * @param  array<int|string, mixed>  $arguments
     * @param  array<int|string, mixed>  $commandOptions
     * @param  array<string, mixed>  $transportOptions
     */
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        throw new RemoteLocalExecutorTransportFailed(
            'Remote local executor transport failed: agent-push transport is unavailable',
        );
    }
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

function fakeGatewayCaRootThroughLaravelHttp(): void
{
    GatewayMockClient::destroyGlobal();

    GatewayMockClient::global([
        'http://10.6.0.2/api/ca/root' => function (GatewayPendingRequest $request): GatewayMockResponse {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->acceptJson()
                ->get($request->url());

            return GatewayMockResponse::make(
                $response->body(),
                $response->status(),
                $response->headers(),
            );
        },
        'https://10.6.0.2/api/ca/root' => function (GatewayPendingRequest $request): GatewayMockResponse {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->withoutVerifying()
                ->acceptJson()
                ->get($request->url());

            return GatewayMockResponse::make(
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
function createTestAppHostNode(
    array $attributes = [],
    string $role = 'app-dev',
    array $settings = [],
): Node {
    $tld = $settings['tld'] ?? null;
    unset($settings['tld']);

    $node = Node::factory()->create([
        'status' => 'active',
        ...(is_string($tld) && $tld !== '' ? ['tld' => $tld] : []),
        ...$attributes,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-dev' ? $settings : [],
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createTestGatewayNode(array $attributes = []): Node
{
    $node = Node::factory()->create([
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

function markNodeSecurityBaselineClean(Node $node): Node
{
    $node->forceFill([
        'user' => 'orbit',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIMockEd25519KeyForOrbitTests',
        'host_key_fingerprint' => 'SHA256:test',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ])->save();

    foreach (['v4', 'v6'] as $addressFamily) {
        FirewallRule::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => "orbit-public-ssh-deny-{$addressFamily}",
            ],
            [
                'direction' => 'incoming',
                'action' => 'deny',
                'source' => $addressFamily === 'v4' ? '0.0.0.0/0' : '::/0',
                'destination' => null,
                'port' => '22',
                'protocol' => 'tcp',
                'reason' => 'Orbit node security baseline denies public SSH after bootstrap.',
                'source_hash' => hash('sha256', "{$node->id}:public-ssh-deny:{$addressFamily}"),
                'address_family' => $addressFamily,
                'interface' => 'public',
                'owner' => 'node-security',
                'protected' => true,
            ],
        );
    }

    return $node->refresh();
}

function createPhpLocalNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $config
 */
function createPhpTool(Node $node, array $config = []): NodeTool
{
    $catalog = new PhpRuntimeCatalog;
    $versions = array_values(array_filter(
        $config['versions'] ?? ['8.5', '8.4'],
        fn (mixed $version): bool => is_string($version) && $catalog->supports($version),
    ));

    return NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'php',
        'expected_state' => 'installed',
        'config' => array_merge([
            'versions' => $versions,
            'images' => array_map($catalog->imageFor(...), $versions),
            'cli_version' => '8.5',
        ], $config),
    ]);
}

function vpnLocalNode(string $role): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.2',
        'wireguard_address' => '10.6.0.2',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
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

    return ! str_ends_with($filename, 'apps/gateway/tests/E2E/Ephemeral/AgentNodeProvisioningTest.php');
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
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.8'],
                    ...$self,
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'roles' => [['role' => 'gateway', 'status' => 'active', 'settings' => []]],
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
