<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentStateView;
use App\Domain\Gateway\GatewayServingHost;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;

uses(LazilyRefreshDatabase::class);

it('records list and show fixtures from the shared Node projection', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-11-02T18:00:00Z'));
    Cluster::query()->forceCreate(['id' => 1, 'name' => 'development']);
    $gateway = Node::withoutEvents(static fn (): Node => Node::query()->forceCreate([
        'id' => 1, 'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.2', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => '10.44.0.1',
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
    ]));
    $node = Node::withoutEvents(static fn (): Node => Node::query()->forceCreate([
        'id' => 2, 'cluster_id' => 1, 'name' => 'app-dev', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'tld' => 'app-dev.test', 'public_ssh_host' => '94.237.40.75', 'public_ssh_port' => 22, 'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3', 'lan_ip' => '10.0.0.3', 'wireguard_public_key' => 'app-dev-public-key',
        'wireguard_endpoint_override' => '10.0.0.2:51820', 'dns_server_override' => '10.0.0.2',
        'ssh_host_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
    ]));
    $gateway->roles()->forceCreate(['id' => 1, 'role' => 'gateway', 'status' => 'active']);
    $node->roles()->forceCreate(['id' => 2, 'role' => 'app-dev', 'status' => 'active']);
    app(GatewayServingHost::class)->remember($gateway);
    $view = new CacheAgentStateView(new Repository(new ArrayStore));
    $this->app->instance(AgentStateView::class, $view);
    $view->putSubscriber(true, true, 2);
    $view->putNode($node->id, [], null, 1, CacheAgentStateView::now(), null);
    $view->putNode($gateway->id, [], null, 1, (float) strtotime('2026-11-02T17:55:00Z'), null);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

    foreach ([
        ['nodes/node-list/default', '/api/v1/nodes', ListNodesRequest::class, 'GET /api/v1/nodes'],
        ['nodes/node-show/default', '/api/v1/nodes/2', ShowNodeRequest::class, 'GET /api/v1/nodes/{node}'],
    ] as [$name, $url, $request, $route]) {
        $body = $this->getJson($url)->assertOk()->json();
        // Keep the random request ID stable in the recorded response.
        $body['meta']['request_id'] = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $fixture = ['schema' => 1, 'request' => $request, 'route' => $route, 'status' => 200, 'body' => $body];
        $path = base_path('../../packages/php-sdk/fixtures/'.$name.'.json');
        if (getenv('ORBIT_FIXTURES') === 'record') {
            file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        expect($fixture)->toBe(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR));
    }
});
