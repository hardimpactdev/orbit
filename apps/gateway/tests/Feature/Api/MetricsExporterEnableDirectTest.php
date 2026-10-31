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

it('refuses unported exporter enabling without changing preference or starting a legacy root', function (): void {
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Enabled))
        ->toThrow(ResourceOperationException::class, 'This kind has no native Swamp workflow.');
    expect($this->fixture->preference())->toBe(null)
        ->and(Operation::query()->count())->toBe(0)
        ->and($this->fixture->socket->sent)->toBe([]);
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

it('returns kind_unavailable from the exporter endpoint until its new shape lands', function (): void {
    $this->markAsGateway($this->fixture->metrics);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->metrics->wireguard_ip])
        ->putJson('/api/v1/metrics/exporters/13')->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect($this->fixture->preference())->toBe(null)->and(Operation::query()->count())->toBe(0);
});
