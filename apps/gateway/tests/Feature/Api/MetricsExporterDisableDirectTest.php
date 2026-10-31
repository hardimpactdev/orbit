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

it('refuses unported exporter disabling without changing preference or starting a legacy root', function (): void {
    expect(fn () => app(AdmitMetricsExporterPreferenceAction::class)->execute(13, ExporterPreference::Disabled))
        ->toThrow(ResourceOperationException::class, 'This kind has no native Swamp workflow.');
    expect($this->fixture->preference())->toBe(ExporterPreference::Enabled)
        ->and(Operation::query()->count())->toBe(0)
        ->and($this->fixture->socket->sent)->toBe([]);
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

it('returns kind_unavailable from the exporter endpoint until its new shape lands', function (): void {
    $this->markAsGateway($this->fixture->metrics);
    $this->withServerVariables(['REMOTE_ADDR' => $this->fixture->metrics->wireguard_ip])
        ->deleteJson('/api/v1/metrics/exporters/13')->assertStatus(503)->assertJsonPath('error.code', 'operation.kind_unavailable');
    expect($this->fixture->preference())->toBe(ExporterPreference::Enabled)->and(Operation::query()->count())->toBe(0);
});
