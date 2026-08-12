<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Doctor\DoctorNodeFamilyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('limits firewall checks to active Ubuntu nodes with an active role', function (): void {
    $eligible = Node::factory()
        ->agent()
        ->create([
            'name' => 'eligible-firewall-node',
            'platform' => 'ubuntu_24-04',
            'status' => 'active',
        ]);
    $macos = Node::factory()
        ->agent()
        ->create([
            'name' => 'macos-firewall-node',
            'platform' => 'macos_15',
            'status' => 'active',
        ]);
    $inactive = Node::factory()
        ->agent()
        ->create([
            'name' => 'inactive-firewall-node',
            'platform' => 'ubuntu_24-04',
            'status' => 'inactive',
        ]);
    $roleless = Node::factory()->create([
        'name' => 'roleless-firewall-node',
        'platform' => 'ubuntu_24-04',
        'status' => 'active',
    ]);

    $resolver = app(DoctorNodeFamilyResolver::class);

    expect($resolver->categoriesForNode($eligible))
        ->toContain('firewall_rule')
        ->and($resolver->categoriesForNode($macos))
        ->not->toContain('firewall_rule')->and($resolver->categoriesForNode($inactive))
        ->not->toContain('firewall_rule')->and($resolver->categoriesForNode($roleless))
        ->not->toContain('firewall_rule');
});
