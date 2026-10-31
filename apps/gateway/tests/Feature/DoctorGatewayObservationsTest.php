<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorObservations;
use App\Domain\Doctor\DoctorProjection;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Models\Operation;

it('reads fresh Node state from the Gateway cache and never contacts an agent', function (): void {
    $node = agent_view_node('doctor-cache');
    seed_agent_view($node->id, ['systemd:orbit-process-42-web' => 'active']);
    $observation = app(DoctorObservations::class)->forNode($node->id);
    expect($observation['node_id'])->toBe($node->id)->and($observation['freshness'])->toBe('fresh')
        ->and((array) $observation['units'])->toBe(['systemd:orbit-process-42-web' => 'active'])
        ->and($observation['docker'])->toBe('available');
});

it('does not expose stale or missing state as a current observation', function (): void {
    $node = agent_view_node('doctor-stale');
    seed_agent_view($node->id, ['docker:orbit-process-42-web' => 'running'], ageSeconds: 20);
    $observation = app(DoctorObservations::class)->forNode($node->id);
    expect($observation['freshness'])->toBe('stale')->and((array) $observation['units'])->toBe([])
        ->and($observation['docker'])->toBeNull()->and($observation['workspaces'])->toBe([]);
    app(CacheAgentStateView::class)->forgetNode($node->id);
    expect(app(DoctorObservations::class)->forNode($node->id)['freshness'])->toBe('missing');
});

it('reads current Gateway observations at each Operation input read without changing admitted intent', function (): void {
    $node = agent_view_node('doctor-refresh');
    $operation = new Operation(['kind' => 'doctor.inspect', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => ['nodes' => [['id' => $node->id, 'resources' => []]], 'families' => ['node'], 'collect_problems' => false]]);
    $projection = app(DoctorProjection::class);
    expect($projection->input($operation)['nodes'][0]['observations']['freshness'])->toBe('missing');
    seed_agent_view($node->id, ['systemd:orbit-process-42-web' => 'active']);
    expect($projection->input($operation)['nodes'][0]['observations']['freshness'])->toBe('fresh')
        ->and($operation->input['nodes'][0])->not->toHaveKey('observations');
});

it('keeps another Nodes observations out of a participants public input', function (): void {
    $root = agent_view_node('doctor-root', '10.44.0.8');
    $worker = agent_view_node('doctor-worker', '10.44.0.9');
    seed_agent_view($root->id, ['systemd:orbit-process-40-root' => 'active']);
    seed_agent_view($worker->id, ['systemd:orbit-process-41-worker' => 'active']);
    $operation = new Operation(['kind' => 'doctor.inspect', 'node_id' => $root->id, 'target_type' => 'node', 'target_id' => $root->id,
        'input' => ['participant_node_ids' => [$root->id, $worker->id], 'nodes' => [['id' => $root->id, 'resources' => []], ['id' => $worker->id, 'resources' => []]]]]);
    $view = app(DoctorProjection::class)->publicInput($operation, $worker->id);
    expect($view['nodes'][0])->not->toHaveKey('observations')
        ->and((array) $view['nodes'][1]['observations']['units'])->toBe(['systemd:orbit-process-41-worker' => 'active']);
});
