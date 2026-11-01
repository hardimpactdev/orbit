<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-test-root');
    FleetFixtures::node('transfer-gateway', [RoleName::Gateway]);
    $this->source = FleetFixtures::node('transfer-metrics', [RoleName::Metrics]);
    $this->target = FleetFixtures::node('transfer-target', [RoleName::Database]);
    $this->member = FleetFixtures::node('transfer-member', [RoleName::Database]);
    $this->assignment = $this->source->roles()->sole();
    $this->input = app(NativeNodeRoleRemovalInput::class);
    $this->before = $this->input->publications($this->source, RoleName::Metrics);
    $this->assignment->claimRemoval();
});

it('keeps shared exporters and their footprints when the same Metrics assignment moves', function (): void {
    NodeRole::query()->whereKey($this->assignment->id)->update(['node_id' => $this->target->id, 'status' => LifecycleStatus::Provisioning]);
    $desired = NodeRole::query()->findOrFail($this->assignment->id);
    $intent = $this->input->capture($this->source, $this->assignment, $this->before, desiredAssignment: $desired);

    expect($intent['stop_units'])->toBe([])
        ->and($intent['remove_footprints'])->toBe([])
        ->and($intent['reload_units'])->toBe([])
        ->and(array_column(array_column($intent['stop_containers'], 'input'), 'name'))->toBe(['orbit-metrics-prometheus', 'orbit-metrics-grafana'])
        ->and(array_column(array_column($intent['remove_containers'], 'input'), 'name'))->toBe(['orbit-metrics-prometheus', 'orbit-metrics-grafana'])
        ->and($intent['certificates'])->toBe([]);
    $rules = array_column(array_column($intent['firewall'], 'input'), 'argv');
    expect(array_filter($rules, fn (array $rule): bool => in_array($this->source->wireguard_ip, $rule, true)))->not->toBeEmpty()
        ->and(NodeRole::query()->findOrFail($desired->id)->node_id)->toBe($this->target->id)
        ->and(Operation::query()->count())->toBe(0);
});

it('still withdraws all shared exporters when Metrics leaves the fleet', function (): void {
    $intent = $this->input->capture($this->source, $this->assignment, $this->before);
    expect(array_column(array_column($intent['stop_units'], 'input'), 'unit'))->toContain('prometheus-node-exporter.service', 'orbit-cadvisor.service', 'orbit-fpm-exporter.service')
        ->and(array_column(array_column($intent['remove_footprints'], 'input'), 'group'))->each->toBe('exporter');
});

it('refuses an unrelated or stale replacement instead of suppressing exporter withdrawal', function (string $invalid): void {
    NodeRole::query()->whereKey($this->assignment->id)->update(['node_id' => $this->target->id, 'status' => LifecycleStatus::Provisioning]);
    $desired = NodeRole::query()->findOrFail($this->assignment->id);
    match ($invalid) {
        'id' => $desired->id++,
        'node' => $desired->node_id = $this->source->id,
        'role' => $desired->role = RoleName::Database,
        'unpersisted' => $desired->exists = false,
        'removing' => $desired->update(['status' => LifecycleStatus::Removing]),
    };
    expect(fn () => $this->input->capture($this->source, $this->assignment, $this->before, desiredAssignment: $desired))->toThrow(ResourceOperationException::class);
})->with(['id', 'node', 'role', 'unpersisted', 'removing']);
