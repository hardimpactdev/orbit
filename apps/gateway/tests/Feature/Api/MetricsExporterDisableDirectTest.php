<?php

declare(strict_types=1);

use App\Domain\Metrics\AdmitMetricsExporterPreferenceAction;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Tests\Support\MetricsExporterPreferenceFixture;

beforeEach(function (): void {
    $this->fixture = new MetricsExporterPreferenceFixture;
    app(ExporterPreferenceRepository::class)->put(13, ExporterPreference::Enabled);
});
afterEach(function (): void {
    $this->fixture->close();
});

it('records disabling the exporter in one complete native fleet intent', function (): void {
    $operation = app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Disabled);
    expect($operation->kind)->toBe('metrics.exporter_disable')->and($operation->node_id)->toBe(9)
        ->and($operation->target_id)->toBe(9)->and($operation->input['exporter_node_id'])->toBe(13)
        ->and($this->fixture->preference())->toBe(ExporterPreference::Disabled)
        ->and(array_column($operation->input['metrics_fleet']['fleet'], 'node_id'))->toBe([1, 9, 13, 18])
        ->and(array_column($operation->input['metrics_fleet']['fleet'], 'selected', 'node_id')[13])->toBeFalse()
        ->and(Operation::query()->count())->toBe(1);
});

it('never disables the Metrics host exporter', function (): void {
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(9, ExporterPreference::Disabled))
        ->toThrow(RoleAssignmentException::class, 'The metrics node exporter cannot be disabled.');
    expect(app(ExporterPreferenceRepository::class)->get(9))->toBeNull()->and(Operation::query()->count())->toBe(0);
});

it('leaves an enabled preference intact when no Metrics owner can reconcile', function (): void {
    $this->fixture->metrics->roles()->delete();
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Disabled))
        ->toThrow(ResourceOperationException::class);
    expect($this->fixture->preference())->toBe(ExporterPreference::Enabled)->and(Operation::query()->count())->toBe(0);
});

it('returns 202 with the parent Operation from the existing exporter disable endpoint', function (): void {
    $this->markAsGateway($this->fixture->metrics);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->metrics->wireguard_ip])
        ->deleteJson('/api/v1/metrics/exporters/13')->assertStatus(202)->assertJsonPath('data.kind', 'metrics.exporter_disable');
    expect(Operation::query()->findOrFail($response->json('data.id'))->input['exporter_node_id'])->toBe(13);
});
