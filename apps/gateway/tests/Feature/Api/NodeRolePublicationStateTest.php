<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRolePublicationState;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use Tests\Support\Fleet\FleetFixtures;

it('records certificate before rendering Caddy and switches WebSocket DNS only after native Caddy succeeds', function (): void {
    $source = FleetFixtures::node('state-reverb-source');
    $target = FleetFixtures::node('state-reverb-target', [RoleName::WebSocket]);
    $certificates = app(CaddySiteCertificates::class);
    $dns = app(WebSocketDnsTarget::class);
    $state = app(NodeRolePublicationState::class);
    $certificates->record($source->id, 'websocket');
    $dns->markServing($source->id);
    $plan = ['leaves' => [['node_id' => $target->id, 'scope' => 'role-websocket']],
        'withdrawals' => [['node_id' => $source->id, 'scope' => 'role-websocket']]];

    $state->record($plan, 'certificates_published', $target->id);

    expect($certificates->published($target->id, 'websocket'))->toBeTrue();
    expect(app(WebSocketCaddySiteSource::class)->sites($target))->toHaveCount(1);
    expect($dns->node($target)?->id)->toBe($source->id);
    expect($dns->isServing($target->id))->toBeFalse();

    $state->record($plan, 'caddy_published', $target->id);

    expect($dns->node($target)?->id)->toBe($target->id);
    expect($dns->isServing($source->id))->toBeTrue();
});

it('preserves the ready WebSocket target and source serving mark through failed source withdrawal', function (): void {
    $source = FleetFixtures::node('state-withdraw-source');
    $target = FleetFixtures::node('state-withdraw-target', [RoleName::WebSocket]);
    $unrelated = FleetFixtures::node('state-withdraw-unrelated');
    $certificates = app(CaddySiteCertificates::class);
    $dns = app(WebSocketDnsTarget::class);
    $state = app(NodeRolePublicationState::class);
    foreach ([$source, $target, $unrelated] as $node) {
        $certificates->record($node->id, 'websocket');
        $dns->markServing($node->id);
    }
    $plan = ['leaves' => [['node_id' => $target->id, 'scope' => 'role-websocket']],
        'withdrawals' => [['node_id' => $source->id, 'scope' => 'role-websocket']]];

    $state->record($plan, 'withdrawal_started', $source->id);

    expect($certificates->published($source->id, 'websocket'))->toBeFalse();
    expect($dns->isServing($source->id))->toBeTrue();
    expect($certificates->published($target->id, 'websocket'))->toBeTrue();
    expect($dns->isServing($target->id))->toBeTrue();
    expect($certificates->published($unrelated->id, 'websocket'))->toBeTrue();
    expect($dns->isServing($unrelated->id))->toBeTrue();

    $state->record($plan, 'withdrawal_completed', $source->id);

    expect($dns->isServing($source->id))->toBeFalse();
    expect($dns->isServing($target->id))->toBeTrue();
    expect($dns->isServing($unrelated->id))->toBeTrue();
});

it('maps trusted native Metrics phases solely through the admitted certificate plan', function (): void {
    $gateway = FleetFixtures::node('state-metrics-gateway', [RoleName::Gateway]);
    $metrics = FleetFixtures::node('state-metrics-host', [RoleName::Metrics]);
    $certificates = app(CaddySiteCertificates::class);
    $state = app(NodeRolePublicationState::class);
    $plan = ['leaves' => [['node_id' => $gateway->id, 'scope' => 'role-metrics']], 'withdrawals' => []];

    $state->record($plan, 'certificates_published', $metrics->id);
    expect($certificates->published($metrics->id, 'metrics'))->toBeFalse();

    $state->record($plan, 'certificates_published', $gateway->id);
    expect($certificates->published($gateway->id, 'metrics'))->toBeTrue();
    expect(app(WebSocketDnsTarget::class)->isServing($gateway->id))->toBeFalse();

    $state->record(['leaves' => [], 'withdrawals' => [['node_id' => $gateway->id, 'scope' => 'role-metrics']]],
        'withdrawal_started', $gateway->id);
    expect($certificates->published($gateway->id, 'metrics'))->toBeFalse();
});

it('records Analytics publication and withdraws its source without clearing the ready target or another site', function (): void {
    $source = FleetFixtures::node('state-analytics-source');
    $target = FleetFixtures::node('state-analytics-target', [RoleName::Analytics]);
    $certificates = app(CaddySiteCertificates::class);
    $state = app(NodeRolePublicationState::class);
    $certificates->record($source->id, 'analytics');
    $certificates->record($source->id, 'websocket');
    $plan = ['leaves' => [['node_id' => $target->id, 'scope' => 'role-analytics']],
        'withdrawals' => [['node_id' => $source->id, 'scope' => 'role-analytics']]];

    $state->record($plan, 'certificates_published', $target->id);
    $state->record($plan, 'caddy_published', $target->id);
    $state->record($plan, 'withdrawal_started', $source->id);
    $state->record($plan, 'withdrawal_completed', $source->id);

    expect($certificates->published($target->id, 'analytics'))->toBeTrue();
    expect($certificates->published($source->id, 'analytics'))->toBeFalse();
    expect($certificates->published($source->id, 'websocket'))->toBeTrue();
    expect(app(WebSocketDnsTarget::class)->isServing($target->id))->toBeFalse();
});
