<?php

declare(strict_types=1);

use App\Domain\Metrics\AdmitMetricsExporterPreferenceAction;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Tests\Support\MetricsExporterPreferenceFixture;

beforeEach(function (): void {
    $this->fixture = new MetricsExporterPreferenceFixture;
});
afterEach(function (): void {
    $this->fixture->close();
});

it('records enabling the exporter and the complete desired fleet as one root', function (): void {
    $operation = app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Enabled);
    expect($operation->kind)->toBe('metrics.exporter_enable')->and($operation->node_id)->toBe(9)
        ->and($operation->target_id)->toBe(9)->and($operation->input['exporter_node_id'])->toBe(13)
        ->and($this->fixture->preference())->toBe(ExporterPreference::Enabled)
        ->and(array_column($operation->input['metrics_fleet']['fleet'], 'node_id'))->toBe([1, 9, 13, 18])
        ->and(array_column($operation->input['metrics_fleet']['fleet'], 'selected', 'node_id')[13])->toBeTrue()
        ->and(Operation::query()->count())->toBe(1);
});

it('refuses a missing Metrics assignment before recording an exporter preference', function (): void {
    $this->fixture->metrics->roles()->delete();
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Enabled))
        ->toThrow(ResourceOperationException::class);
    expect($this->fixture->preference())->toBeNull()->and(Operation::query()->count())->toBe(0)
        ->and($this->fixture->socket->sent)->toBe([]);
});

it('refuses inactive unsupported or unmanaged exporter Nodes before preference mutation', function (array $changes): void {
    $this->fixture->exporter->update($changes);
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Enabled))
        ->toThrow(ResourceOperationException::class);
    expect($this->fixture->preference())->toBeNull()->and(Operation::query()->count())->toBe(0);
})->with([[['status' => 'failed']], [['platform' => 'macos']], [['ssh_host_fingerprint' => null]]]);

it('rolls the preference back when the complete capture cannot be recorded', function (): void {
    $this->fixture->metrics->update(['architecture' => 'aarch64']);
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Enabled))->toThrow(RuntimeException::class);
    expect($this->fixture->preference())->toBeNull()->and(Operation::query()->count())->toBe(0);
});

it('returns 202 with the parent Operation from the existing exporter enable endpoint', function (): void {
    $this->markAsGateway($this->fixture->metrics);
    $response = $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->metrics->wireguard_ip])
        ->putJson('/api/v1/metrics/exporters/13')->assertStatus(202)->assertJsonPath('data.kind', 'metrics.exporter_enable');
    expect(Operation::query()->findOrFail($response->json('data.id'))->input['exporter_node_id'])->toBe(13);
});
