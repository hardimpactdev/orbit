<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const PROXY_ROUTE_MUTATION_CALLER_WG_IP = '10.6.0.92';

function createProxyRouteMutationCallerNode(array $overrides = [], ?string $role = null): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'host' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        'wireguard_address' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
    ], $overrides);

    return match ($role) {
        'app-dev' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProxyRouteMutationAccess(Node $caller, Node $servingNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProxyRoute mutation API', function (): void {
    beforeEach(function (): void {
        $shell = new class implements RemoteShell {
            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                if (str_contains($script, "internal:managed-file 'probe'")) {
                    return new RemoteShellResult(
                        exitCode: 0,
                        stdout: json_encode([
                            'success' => [
                                'data' => [
                                    'exists' => false,
                                    'hash' => null,
                                    'mode' => null,
                                ],
                            ],
                        ], JSON_THROW_ON_ERROR),
                        stderr: '',
                        durationMs: 1,
                    );
                }

                return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
            }
        };
        app()->instance(RemoteShell::class, $shell);
        app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
        app()->instance(ProxyRouteFixer::class, new ProxyRouteFixer(
            renderer: new ProxyRouteRenderer,
            ca: new ProxyMutationFakeCa,
            siteCertificateInstaller: new SiteCertificateInstallerFake,
        ));
    });

    it('stores custom upstream route intent for authorized callers', function (): void {
        $caller = createProxyRouteMutationCallerNode();
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        grantProxyRouteMutationAccess($caller, $servingNode);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->postJson('/api/proxy-routes', [
            'domain' => 'vite.docs.test',
            'node' => 'app-1',
            'upstream' => 'http://127.0.0.1:5173',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.route.domain', 'vite.docs.test')
            ->assertJsonPath('success.meta.action', 'created')
            ->assertJsonPath('success.data.route.status', 'converged');
        $codes = collect($response->json('success.meta.warnings') ?? [])->pluck('code')->all();
        expect($codes)->not->toContain('proxy.enactment_deferred');
    });

    it('denies domain conflicts for non-custom routes', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);

        ProxyRoute::factory()
            ->forApp($app)
            ->create([
                'node_id' => $servingNode->id,
                'app_id' => $app->id,
                'domain' => 'docs.test',
                'owner_type' => 'app',
                'kind' => 'app',
            ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->postJson('/api/proxy-routes', [
            'domain' => 'docs.test',
            'node' => 'app-1',
            'upstream' => 'http://127.0.0.1:5173',
            'force' => true,
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.owner_type', 'instance');
    });

    it('removes custom route intent with destructive consent', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'domain' => 'old.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 302],
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->deleteJson('/api/proxy-routes/old.test', [
            'destructive_consent' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.route.domain', 'old.test')
            ->assertJsonPath('success.data.route.status', 'removed')
            ->assertJsonPath('success.meta.backend_removed', true);

        expect(ProxyRoute::query()->where('domain', 'old.test')->exists())->toBeFalse();
    });

    it('requires destructive consent before removing intent', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');

        $response = $this->withServerVariables(['REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP])->deleteJson(
            '/api/proxy-routes/old.test',
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'force')
            ->assertJsonPath('error.meta.reason', 'destructive_consent_required');
    });

    it('denies force removal of a living workspace-owned route', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $workspace = Workspace::factory()->for($app)->create(['name' => 'feature']);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'app_id' => $app->id,
            'instance_id' => $workspace->instance_id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->deleteJson('/api/proxy-routes/feature.docs.test', [
            'destructive_consent' => true,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('error.code', 'proxy.owned_route_denied')
            ->assertJsonPath('error.meta.owner_type', 'workspace');

        expect(ProxyRoute::query()->where('domain', 'feature.docs.test')->exists())->toBeTrue();
    });

    it('force-removes an orphaned workspace-owned route and reports why it was safe', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'app-1']);
        $app = App::factory()->create(['name' => 'docs']);
        $instance = Instance::factory()->for($app)->create();

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'app_id' => $app->id,
            'instance_id' => $instance->id,
            'workspace_id' => null,
            'domain' => 'auth.craft-starterkit-react.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->deleteJson('/api/proxy-routes/auth.craft-starterkit-react.test', [
            'destructive_consent' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.route.domain', 'auth.craft-starterkit-react.test')
            ->assertJsonPath('success.meta.removal_reason', 'orphan_owner')
            ->assertJsonPath('success.meta.owner_type', 'workspace')
            ->assertJsonPath('success.meta.backend_removed', true);

        expect(ProxyRoute::query()->where('domain', 'auth.craft-starterkit-react.test')->exists())->toBeFalse();
    });

    it('force-removes an orphaned tool-owned route when the NodeTool is gone', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'agent-1', 'tld' => 'agent']);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
            ],
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->deleteJson('/api/proxy-routes/hermes.agent', [
            'destructive_consent' => true,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success.data.route.domain', 'hermes.agent')
            ->assertJsonPath('success.meta.removal_reason', 'orphan_owner')
            ->assertJsonPath('success.meta.owner_type', 'tool')
            ->assertJsonPath('success.meta.backend_removed', true);

        expect(ProxyRoute::query()->where('domain', 'hermes.agent')->exists())->toBeFalse();
    });

    it('denies force-remove of a living tool-owned route', function (): void {
        createProxyRouteMutationCallerNode(role: 'gateway');
        $servingNode = createTestAppHostNode(['name' => 'agent-1', 'tld' => 'agent']);
        \App\Models\NodeTool::factory()->create([
            'node_id' => $servingNode->id,
            'name' => 'hermes',
            'expected_state' => 'installed',
        ]);

        ProxyRoute::factory()->create([
            'node_id' => $servingNode->id,
            'domain' => 'hermes.agent',
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'hermes',
                'upstream' => 'http://host.docker.internal:8080',
                'target' => ['type' => 'upstream', 'value' => 'http://host.docker.internal:8080'],
            ],
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => PROXY_ROUTE_MUTATION_CALLER_WG_IP,
        ])->deleteJson('/api/proxy-routes/hermes.agent', [
            'destructive_consent' => true,
        ]);

        $response
            ->assertConflict()
            ->assertJsonPath('error.code', 'proxy.owned_route_denied')
            ->assertJsonPath('error.meta.owner_type', 'tool');

        expect(ProxyRoute::query()->where('domain', 'hermes.agent')->exists())->toBeTrue();
    });
});

final readonly class ProxyMutationFakeCa extends OrbitCaService
{
    #[\Override]
    public function rootCert(): string
    {
        return 'fake-root-ca';
    }

    /**
     * @return array{cert: string, key: string}
     */
    #[\Override]
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-proxy-mutation-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";
        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}
