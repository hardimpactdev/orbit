<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

it('compares only registered applicable artifact digests without executing them', function (): void {
    $node = FleetFixtures::node('dev', [RoleName::AppDev]);
    $agent = new FakeFootprintArtifact('agent', 'agent-intent');
    $absent = new FakeFootprintArtifact('ai-router', 'collector-intent', applies: false);
    $footprint = new NodeFootprint(app(AgentDelivery::class), [$agent, $absent]);
    $expected = $footprint->expected($node);
    expect($expected->artifacts)->toBe(['agent' => hash('sha256', app(AgentDelivery::class)->pin($node)."\0agent-intent")]);
    expect($footprint->recorded($node))->toBeNull();
    expect($footprint->drifted($node))->toBeTrue();
    NodeFootprintRecord::query()->create(['node_id' => $node->id, 'digest' => $expected->digest(), 'artifacts' => $expected->artifacts]);
    expect($footprint->drifted($node))->toBeFalse();
    $agent->digest = 'changed-intent';
    expect($footprint->drifted($node))->toBeTrue();
    expect($agent->applied)->toBe([]);
    expect($absent->applied)->toBe([]);
});
