<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorProxyRouteRestorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps saved proxy routes read-only in verify mode', function (): void {
    $node = Node::factory()->create(['name' => 'proxy-1']);
    $route = ProxyRoute::factory()->create([
        'node_id' => $node->id,
        'domain' => 'custom.test',
    ]);
    $issue = app(DoctorIssueFactory::class)->fromArray([
        'family' => 'proxy',
        'node' => $node->name,
        'key' => 'proxy.route_mismatch',
        'kind' => DriftKind::Divergent->value,
        'summary' => 'Observed route mismatch.',
        'detail' => ['domain' => $route->domain],
    ]);

    expect(app(DoctorProxyRouteRestorer::class)->applyStored(
        'verify',
        $issue->detail,
        new DriftEntry(
            family: $issue->family,
            key: $issue->key,
            kind: $issue->kind,
            summary: $issue->summary,
            detail: $issue->detail,
        ),
    ))->toBeNull();
});
