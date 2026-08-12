<?php

declare(strict_types=1);

use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorProxyRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->node = Node::factory()->create(['name' => 'proxy-1']);
    $this->restorer = app(DoctorProxyRestorer::class);
    $this->issueFactory = app(DoctorIssueFactory::class);
});

it('does not mutate any proxy-owned issue in verify mode', function (string $key): void {
    $issue = $this->issueFactory->fromArray([
        'family' => 'proxy',
        'node' => $this->node->name,
        'key' => $key,
        'kind' => DriftKind::Divergent->value,
        'summary' => "Observed {$key}.",
        'detail' => [],
    ]);

    expect($this->restorer->apply($this->node, 'verify', $key, [], $issue))->toBeNull();
})->with([
    'agent tool missing' => 'proxy.agent_tool_route_missing',
    'agent tool mismatch' => 'proxy.agent_tool_route_mismatch',
    'analytics router missing' => 'proxy.analytics.router_route_missing',
    'analytics router orphaned' => 'proxy.analytics.router_route_orphaned',
    'analytics public missing' => 'proxy.analytics.public_route_missing',
    'Caddy container missing' => 'proxy.caddy_container_missing',
    'Caddy container down' => 'proxy.caddy_container_down',
    'Caddy container detached' => 'proxy.caddy_container_detached',
    'global config missing' => 'proxy.global_config_missing',
    'global config mismatch' => 'proxy.global_config_mismatch',
    'WebSocket router missing' => 'proxy.websocket.router_route_missing',
    'WebSocket public missing' => 'proxy.websocket.public_route_missing',
    'S3 router missing' => 'proxy.s3.router_route_missing',
    'S3 router backend invalid' => 'proxy.s3.router_backend_invalid',
    'S3 public missing' => 'proxy.s3.public_route_missing',
]);

it('keeps custom route lookup read-only in verify mode', function (): void {
    $route = ProxyRoute::factory()->create([
        'node_id' => $this->node->id,
        'domain' => 'custom.test',
    ]);
    $issue = $this->issueFactory->fromArray([
        'family' => 'proxy',
        'node' => $this->node->name,
        'key' => 'proxy.route_mismatch',
        'kind' => DriftKind::Divergent->value,
        'summary' => 'Observed route mismatch.',
        'detail' => ['domain' => $route->domain],
    ]);

    expect($this->restorer->apply(
        $this->node,
        'verify',
        $issue->key,
        $issue->detail,
        $issue,
    ))->toBeNull();
});

it('identifies workspace-owned proxy issues by their stored route', function (): void {
    ProxyRoute::factory()->create([
        'node_id' => $this->node->id,
        'domain' => 'workspace.test',
        'owner_type' => 'workspace',
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $this->node->id,
        'domain' => 'custom.test',
        'owner_type' => 'custom',
    ]);

    expect($this->restorer->issueTargetsWorkspace(['domain' => 'workspace.test']))
        ->toBeTrue()
        ->and($this->restorer->issueTargetsWorkspace(['domain' => 'custom.test']))
        ->toBeFalse()
        ->and($this->restorer->issueTargetsWorkspace(['domain' => 'missing.test']))
        ->toBeFalse()
        ->and($this->restorer->issueTargetsWorkspace([]))
        ->toBeFalse();
});
