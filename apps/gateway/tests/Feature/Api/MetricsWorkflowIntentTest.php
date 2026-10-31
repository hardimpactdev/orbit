<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetCapture;
use App\Domain\Metrics\MetricsFleetWorkflowIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Tests\Support\MetricsExporterPreferenceFixture;

beforeEach(function (): void {
    $this->fixture = new MetricsExporterPreferenceFixture;
    config(['swamp_workers.nodes' => [1, 9, 13, 18]]);
    $this->plan = app(MetricsFleetCapture::class)->capture();
});
afterEach(function (): void {
    $this->fixture->close();
});
it('captures the complete public fleet including both logical proxy participants', function (): void {
    $intent = app(MetricsFleetWorkflowIntent::class)->capture($this->plan);
    expect($intent['participant_node_ids'])->toBe([1, 9, 13, 18])
        ->and($intent['metrics_fleet'])->toBe($this->plan)
        ->and($intent)->not->toHaveKey('execution_secrets')
        ->and($intent)->not->toHaveKey('fleet_runtime')
        ->and($intent['metrics_fleet']['runtime_files'])->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password');
});
it('refuses a missing exact worker without filtering it from the fleet', function (): void {
    $plan = $this->plan;
    $plan['fleet'][0]['worker'] = false;
    unset($plan['digest']);
    $plan['digest'] = MetricsFleetCapture::digest($plan);
    expect(fn () => app(MetricsFleetWorkflowIntent::class)->capture($plan))->toThrow(ResourceOperationException::class);
});
it('returns only immutable public intent to StartSwampRun without reading current credentials or publications', function (): void {
    $input = app(MetricsFleetWorkflowIntent::class)->capture($this->plan);
    $operation = new Operation(['kind' => 'metrics.reconcile', 'input' => [...$input,
        'execution_secrets' => ['grafana_password' => ['vault' => 'orbit-control-inputs', 'key' => 'metrics-grafana-node-9-active']]]]);
    $this->fixture->metrics->update(['name' => 'changed-after-admission']);
    expect(app(MetricsFleetWorkflowIntent::class)->fromInput($operation->input))->toBe($input);
});
it('refuses changed public intent before submission', function (): void {
    $plan = $this->plan;
    $plan['runtime_files']['/etc/orbit/metrics/prometheus.yml'] .= "\nchanged";
    expect(fn () => app(MetricsFleetWorkflowIntent::class)->capture($plan))->toThrow(ResourceOperationException::class);
});
