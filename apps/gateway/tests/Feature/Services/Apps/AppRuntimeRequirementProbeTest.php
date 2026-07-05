<?php

declare(strict_types=1);

use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Models\App;
use App\Models\AppInstance;
use App\Models\Node;
use App\Services\Apps\AppRuntimeRequirementProbe;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    request()->headers->set(ExplicitRemoteShellFallback::HEADER, NodeTransportPreference::AgentPush->value);
});

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

it('reports missing required PHP extensions with stable issue codes', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.51:9477/v1/commands' => Http::response(app_runtime_extensions_probe_response(
            exitCode: 0,
            stdout: "redis\npdo\n",
        )),
    ]);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.51',
        ]);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'runtime_requirements' => new AppInstanceRuntimeRequirementsData(
            php_extensions: ['redis', 'intl'],
        ),
    ]);

    $issues = app(AppRuntimeRequirementProbe::class)->drift($instance);

    expect($issues)
        ->toHaveCount(1)
        ->and($issues[0]->family)
        ->toBe('app')
        ->and($issues[0]->key)
        ->toBe('app.runtime_extension_missing')
        ->and($issues[0]->summary)
        ->toContain('intl');

    Http::assertSent(fn (Request $request): bool => app_runtime_extensions_probe_was_sent(
        request: $request,
        address: '10.6.0.51',
        container: 'orbit-app-billing',
    ));
});

it('reports unverifiable PHP extension state when the runtime cannot be queried', function (): void {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.6.0.52:9477/v1/commands' => Http::response(app_runtime_extensions_probe_response(
            exitCode: 1,
            stdout: '',
            stderr: 'container not running',
        )),
    ]);
    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.6.0.52',
        ]);
    $app = App::factory()->for($node, 'node')->create(['name' => 'billing']);
    $instance = AppInstance::factory()->for($app)->create([
        'name' => 'development',
        'runtime_requirements' => new AppInstanceRuntimeRequirementsData(
            php_extensions: ['redis'],
        ),
    ]);

    $issues = app(AppRuntimeRequirementProbe::class)->drift($instance);

    expect($issues)->toHaveCount(1)->and($issues[0]->key)->toBe('app.runtime_extensions_unverifiable');
});

/**
 * @return array<string, mixed>
 */
function app_runtime_extensions_probe_response(int $exitCode, string $stdout, string $stderr = ''): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'app-runtime-extensions.probe',
        'binary' => 'orbit',
        'status' => 'succeeded',
        'exit_code' => 0,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'exit_code' => $exitCode,
                            'stdout' => $stdout,
                            'stderr' => $stderr,
                        ],
                        'meta' => [],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ];
}

function app_runtime_extensions_probe_was_sent(Request $request, string $address, string $container): bool
{
    return (
        $request->url() === "http://{$address}:9477/v1/commands"
        && $request['binary'] === 'orbit'
        && $request['argv'][0] === 'internal:app-runtime-extensions:probe'
        && $request['argv'][1] === $container
    );
}
