<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Services\Nodes\NodeRegistryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bind_dnsmasq_reconciler_test_double();
});

it('rejects active identity writes without a valid non-reserved tld', function (?string $tld): void {
    expect(fn (): Node => app(NodeRegistryWriter::class)->writeNodeIdentity(
        name: 'app-1',
        tld: $tld,
        platform: 'ubuntu',
        host: '10.6.0.4',
        wireguardAddress: '10.6.0.4',
        gatewayEndpoint: '10.6.0.2',
        user: 'orbit',
        orbitPath: '/home/orbit/orbit',
    ))
        ->toThrow(RuntimeException::class, 'Active nodes require a valid non-reserved TLD.');

    expect(Node::query()->where('name', 'app-1')->exists())->toBeFalse();
})->with([
    'missing' => null,
    'invalid' => 'Invalid_TLD!',
    'reserved' => 'orbit',
]);

it('refuses to activate a legacy node with a reserved tld', function (): void {
    $node = Node::factory()->create([
        'name' => 'legacy',
        'tld' => 'orbit',
        'status' => NodeStatus::Provisioning,
    ]);

    expect(fn () => app(NodeRegistryWriter::class)->markActive($node))
        ->toThrow(RuntimeException::class, 'Active nodes require a valid non-reserved TLD.');

    expect($node->fresh()?->status)->toBe(NodeStatus::Provisioning);
});
