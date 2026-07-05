<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\NodeCommandTransport\NodeTransportPreference;
use App\Services\Proxy\AppProxyRouteCaddyInstaller;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

afterEach(function (): void {
    request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
});

function appProxyRouteCaddyInstallerUseAgentPush(): void
{
    request()->headers->set(
        ExplicitRemoteShellFallback::HEADER,
        NodeTransportPreference::AgentPush->value,
    );
}

it('skips caddy container update shell when transitional fallback is not explicit', function (): void {
    appProxyRouteCaddyInstallerUseAgentPush();

    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create(['wireguard_address' => '10.47.0.41']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'caddy',
        'config' => ['container' => []],
    ]);

    $shell = new AppProxyRouteInstallerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.41:9477/v1/commands' => Http::sequence()
            ->push(app_proxy_installer_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/docs.test.caddy',
            ])),
    ]);

    $result = app(AppProxyRouteCaddyInstaller::class)->installRouteConfig(
        node: $node,
        domain: 'docs.test',
        content: 'docs.test { respond "ok" }',
    );

    expect($result->successful())
        ->toBeFalse()
        ->and($result->stderr)
        ->toBe(
            'proxy caddy container update still uses RemoteShell and requires explicit --node-transport=transitional-ssh-fallback until it is migrated to agent-push.',
        )
        ->and($shell->scripts)
        ->toBe([])
        ->and(app_proxy_installer_agent_requests('10.47.0.41'))
        ->toHaveCount(1);
});

it('keeps route config writes and reloads on the typed agent path when no caddy tool update is needed', function (): void {
    appProxyRouteCaddyInstallerUseAgentPush();

    $node = Node::factory()
        ->appDev()
        ->orbitAgentCapable()
        ->create(['wireguard_address' => '10.47.0.42']);

    $shell = new AppProxyRouteInstallerRecordingShell;
    app()->instance(RemoteShell::class, $shell);

    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.42:9477/v1/commands' => Http::sequence()
            ->push(app_proxy_installer_agent_response('caddy-config.write-site', [
                'path' => '/etc/caddy/sites/docs.test.caddy',
            ]))
            ->push(app_proxy_installer_agent_response('caddy-config.reload', [
                'container' => 'orbit-caddy',
            ])),
    ]);

    $result = app(AppProxyRouteCaddyInstaller::class)->installRouteConfig(
        node: $node,
        domain: 'docs.test',
        content: 'docs.test { respond "ok" }',
    );

    expect($result->successful())
        ->toBeTrue()
        ->and($shell->scripts)
        ->toBe([])
        ->and(app_proxy_installer_agent_requests('10.47.0.42'))
        ->toHaveCount(2);
});

final class AppProxyRouteInstallerRecordingShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function app_proxy_installer_agent_response(string $operationId, array $data, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => $data,
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function app_proxy_installer_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}
