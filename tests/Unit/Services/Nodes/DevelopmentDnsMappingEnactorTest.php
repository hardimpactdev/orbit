<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Nodes;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Nodes\DevelopmentDnsMappingEnactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->configDir = storage_path('framework/testing/node-development-dns');

    File::deleteDirectory($this->configDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->configDir);
});

it('converges a gateway-owned development dns mapping from active app node intent', function (): void {
    $node = developmentDnsMappingNode();
    assignDevelopmentDnsMappingRole($node);
    $enactor = new DevelopmentDnsMappingEnactor($this->configDir);

    $result = $enactor->converge($node);

    expect($result)->toMatchArray([
        'status' => 'configured',
        'changed' => true,
        'domain' => '*.test',
        'target' => '10.6.0.7',
    ]);

    expect(File::exists("{$this->configDir}/test.conf"))->toBeTrue();
    expect(File::get("{$this->configDir}/test.conf"))
        ->toContain('orbit-managed=node-development-dns')
        ->toContain('node=app-1')
        ->toContain('address=/.test/10.6.0.7');
});

it('does not create mappings for production app nodes', function (): void {
    $node = developmentDnsMappingNode([
        'environment' => 'production',
        'tld' => null,
    ]);
    assignDevelopmentDnsMappingRole($node, 'app-production');
    $enactor = new DevelopmentDnsMappingEnactor($this->configDir);

    $result = $enactor->converge($node);

    expect($result)->toMatchArray([
        'status' => 'not_applicable',
        'changed' => false,
    ]);
    expect(File::isDirectory($this->configDir))->toBeFalse();
});

it('removes the derived mapping for a development app node', function (): void {
    $node = developmentDnsMappingNode();
    assignDevelopmentDnsMappingRole($node);
    $enactor = new DevelopmentDnsMappingEnactor($this->configDir);
    $enactor->converge($node);

    $result = $enactor->remove($node);

    expect($result)->toMatchArray([
        'status' => 'removed',
        'changed' => true,
        'domain' => '*.test',
        'target' => '10.6.0.7',
    ]);
    expect(File::exists("{$this->configDir}/test.conf"))->toBeFalse();
});

it('uses the app-development role settings as the development dns tld', function (): void {
    $node = developmentDnsMappingNode(['tld' => 'legacy']);
    assignDevelopmentDnsMappingRole($node, settings: ['tld' => 'assigned']);
    $enactor = new DevelopmentDnsMappingEnactor($this->configDir);

    $result = $enactor->converge($node);

    expect($result)->toMatchArray([
        'status' => 'configured',
        'domain' => '*.assigned',
    ]);
    expect(File::exists("{$this->configDir}/assigned.conf"))->toBeTrue();
    expect(File::exists("{$this->configDir}/legacy.conf"))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function developmentDnsMappingNode(array $overrides = []): Node
{
    return Node::create(array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'environment' => 'development',
        'tld' => 'test',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'orbit',
        'orbit_path' => '/home/orbit/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignDevelopmentDnsMappingRole(Node $node, string $role = 'app-development', array $settings = ['tld' => 'test']): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $role === 'app-development' ? $settings : [],
    ]);
}
