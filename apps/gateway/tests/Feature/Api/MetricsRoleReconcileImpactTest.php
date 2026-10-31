<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsRoleReconcileImpact;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Instances\ProductionPhpRuntimeConfigRenderer;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->metrics = FleetFixtures::node('metrics-owner', [RoleName::Metrics]);
    $this->node = FleetFixtures::node('role-node', [RoleName::AppDev]);
    $this->impact = app(MetricsRoleReconcileImpact::class);
});

it('captures only the affected Node selection without binary delivery or rendering PHP pools', function (): void {
    app()->bind(AgentDelivery::class, static fn () => throw new RuntimeException('Unexpected binary delivery lookup'));
    app()->bind(ProductionPhpRuntimeConfigRenderer::class, static fn () => throw new RuntimeException('Unexpected pool renderer'));
    expect($this->impact->capture($this->node, RoleName::Database))->toBe([
        'metrics_node_id' => $this->metrics->id, 'selected' => true, 'caddy' => false, 'fpm' => false,
    ]);
});

it('proves CI and database intent changes leave the existing Node monitoring unchanged', function (RoleName $role): void {
    $before = $this->impact->capture($this->node, $role);
    $this->node->roles()->create(['role' => $role, 'status' => 'provisioning']);
    $after = $this->impact->capture($this->node->refresh(), $role);
    expect($this->impact->compare($before, $after)['required'])->toBeFalse();
})->with([RoleName::CiRunner, RoleName::Database]);

it('requires reconciliation when the affected Node exporter selection changes', function (): void {
    $before = $this->impact->capture($this->node, RoleName::Database);
    app(ExporterPreferenceRepository::class)->put($this->node->id, ExporterPreference::Disabled);
    expect($this->impact->compare($before, $this->impact->capture($this->node, RoleName::Database))['required'])->toBeTrue();
});

it('refuses an unported role rather than using the narrow proof for unrelated effects', function (): void {
    expect(fn () => $this->impact->capture($this->node, RoleName::AppDev))->toThrow(ResourceOperationException::class);
});

it('distinguishes an absent Metrics owner from an owner that appeared during admission', function (): void {
    $after = $this->impact->capture($this->node, RoleName::Database);
    expect($this->impact->compare(null, $after)['required'])->toBeTrue();
    $this->metrics->roles()->delete();
    expect($this->impact->capture($this->node, RoleName::Database))->toBeNull()
        ->and($this->impact->compare(null, null)['required'])->toBeFalse();
});

it('refuses competing Metrics owners', function (): void {
    $foreign = FleetFixtures::node('competing-metrics');
    $foreign->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    expect(fn () => $this->impact->capture($this->node, RoleName::Database))->toThrow(ResourceOperationException::class);
});
