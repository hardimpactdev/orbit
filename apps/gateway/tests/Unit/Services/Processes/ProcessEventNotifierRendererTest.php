<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Processes\ProcessEventNotifierRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('uses configured local gateway URL when present', function (): void {
    LocalGatewaySettings::current()->fill(['gateway_url' => 'https://10.6.0.2/'])->save();

    expect(app(ProcessEventNotifierRenderer::class)->expectedGatewayEndpoint())
        ->toBe('https://10.6.0.2');
});

it('falls back to the active gateway role endpoint when local settings are unset', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'host' => 'gateway',
    ]);
    NodeRoleAssignment::factory()->for($gateway)->create([
        'role' => 'gateway',
    ]);

    expect(app(ProcessEventNotifierRenderer::class)->expectedGatewayEndpoint())
        ->toBe('https://gateway');
});
