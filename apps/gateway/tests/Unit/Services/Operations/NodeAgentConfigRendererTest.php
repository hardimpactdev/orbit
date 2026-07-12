<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\NodeAgentConfigRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders the complete managed Agent listener identity contract', function (): void {
    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'tld' => 'gateway',
            'wireguard_address' => '10.6.0.2',
        ]);
    $node = Node::factory()
        ->database()
        ->create([
            'name' => 'database-1',
            'tld' => 'database',
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.44',
        ]);

    $config = app(NodeAgentConfigRenderer::class)->render($node, $gateway);

    expect($config)
        ->toContain('gateway_url = "https://10.6.0.2"')
        ->toContain('node_id = "'.(string) $node->id.'"')
        ->toContain('node_name = "database-1"')
        ->toContain('gateway_name = "gateway-1"')
        ->toContain('platform = "ubuntu_24-04"')
        ->toContain('managed = true')
        ->toContain('wireguard_address = "10.6.0.44"');
});
