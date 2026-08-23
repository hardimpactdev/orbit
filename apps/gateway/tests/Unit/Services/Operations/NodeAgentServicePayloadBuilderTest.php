<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Ca\OrbitCaService;
use App\Services\Operations\NodeAgentServicePayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps ordinary Agent service payloads on Agent-intent eligibility', function (): void {
    app()->instance(OrbitCaService::class, new readonly class extends OrbitCaService {
        #[Override]
        public function rootCert(): string
        {
            return "-----BEGIN CERTIFICATE-----\ndGVzdA==\n-----END CERTIFICATE-----\n";
        }
    });

    $gateway = Node::factory()
        ->gateway()
        ->create([
            'name' => 'gateway-1',
            'wireguard_address' => '10.6.0.2',
        ]);
    $node = Node::factory()
        ->operator()
        ->create([
            'name' => 'operator-linux',
            'managed' => false,
            'platform' => 'ubuntu_24-04',
            'wireguard_address' => '10.6.0.97',
        ]);
    $builder = app(NodeAgentServicePayloadBuilder::class);

    expect($node->isAgentEligible())->toBeFalse();
    expect($node->isFleetUpdateEligible())->toBeTrue();
    expect($builder->forNode($node, $gateway))->toBeNull();
    expect($builder->forFleetUpdateNode($node, $gateway))
        ->toMatchArray([
            'unit_name' => 'orbit-agent',
            'http_bind' => '10.6.0.97:9477',
            'user' => 'orbit',
        ]);
});
