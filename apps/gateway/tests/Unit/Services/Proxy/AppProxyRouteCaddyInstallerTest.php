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

it('applies caddy container updates on the typed agent path without fallback', function (): void {
    appProxyRouteCaddyInstallerUseAgentPush();

    $node = Node::factory()
        ->appDev()
        ->managed()
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
            ]))
            ->push(app_proxy_installer_agent_response('caddy-config.apply-container', [
                'container' => 'orbit-caddy',
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
        ->and(app_proxy_installer_agent_requests('10.47.0.41'))
        ->toHaveCount(3)
        ->and(app_proxy_installer_agent_actions('10.47.0.41'))
        ->toBe([
            'write-site',
            'apply-container',
            'reload',
        ]);
});

it('keeps route config writes and reloads on the typed agent path when no caddy tool update is needed', function (): void {
    appProxyRouteCaddyInstallerUseAgentPush();

    $node = Node::factory()
        ->appDev()
        ->managed()
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

/**
 * @return list<string>
 */
function app_proxy_installer_agent_actions(string $wireguardAddress): array
{
    return array_values(array_filter(
        array_map(
            static function (Request $request): ?string {
                /** @var mixed $payload */
                $payload = $request->data();

                if (! is_array($payload)) {
                    return null;
                }

                /** @var mixed $argv */
                $argv = $payload['argv'] ?? null;

                if (! is_array($argv)) {
                    return null;
                }

                $action = $argv[1] ?? null;

                return is_string($action) ? $action : null;
            },
            app_proxy_installer_agent_requests($wireguardAddress),
        ),
        is_string(...),
    ));
}
