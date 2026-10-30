<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = Node::query()->create(['name' => 'gateway', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->markAsGateway($this->gateway);
    $this->metrics = Node::query()->create(['name' => 'metrics', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->assignment = $this->metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $this->exporter = Node::query()->create(['name' => 'exporter', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.18', 'public_ssh_host' => '192.0.2.18', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
});

it('returns 202 with captured exporter intent and replays the same admission', function (bool $enable): void {
    $method = $enable ? 'putJson' : 'deleteJson';
    $path = '/api/v1/metrics/exporters/'.$this->exporter->id;
    $response = $this->withHeader('Idempotency-Key', 'exporter-intent-1')->$method($path);
    $response->assertAccepted()->assertJsonPath('data.kind', $enable ? 'metrics.exporter_enable' : 'metrics.exporter_disable')->assertJsonPath('data.status', 'queued');
    $id = $response->json('data.id');
    $this->withHeader('Idempotency-Key', 'exporter-intent-1')->$method($path)->assertAccepted()->assertJsonPath('data.id', $id);
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['exporter_intent'])->toBe(['node_id' => $this->exporter->id, 'desired' => $enable]);
    expect(app(ExporterPreferenceRepository::class)->get($this->exporter->id))->toBe($enable ? ExporterPreference::Enabled : ExporterPreference::Disabled);
    expect(Operation::query()->count())->toBe(1);
    Http::assertNothingSent();
})->with([true, false]);

it('records desired intent without runtime work when Metrics is disabled', function (): void {
    $this->assignment->delete();
    $this->putJson('/api/v1/metrics/exporters/'.$this->exporter->id)->assertAccepted()->assertJsonPath('data.target_type', 'metrics_preference');
    expect(app(ExporterPreferenceRepository::class)->get($this->exporter->id))->toBe(ExporterPreference::Enabled);
    $operation = Operation::query()->sole();
    $execution = app(MetricsFleetExecution::class);
    expect($execution->childContext($operation)['children'])->toBe([]);
    expect($execution->context($operation)['descriptors'][0]['input'])->toBe(['backend' => 'recorded']);
    $execution->assertCompletion($operation, OperationOutcome::succeeded(['backend' => 'recorded']));
    expect(fn () => $execution->assertCompletion($operation, OperationOutcome::succeeded(['backend' => 'active'])))->toThrow(ResourceOperationException::class);
});

it('captures managed Ingress cleanup when no scrape host remains or its exporter is disabled', function (bool $enable): void {
    $cluster = Cluster::query()->create(['name' => 'exporter-ingress']);
    $this->exporter->update(['cluster_id' => $cluster->id]);
    $this->exporter->roles()->create(['role' => 'ingress', 'status' => 'active', 'cluster_id' => $cluster->id]);
    $method = $enable ? 'putJson' : 'deleteJson';

    $response = $this->$method('/api/v1/metrics/exporters/'.$this->exporter->id)->assertAccepted();

    $fleet = Operation::query()->findOrFail($response->json('data.id'))->input['fleet'];
    $candidate = collect($fleet)->firstWhere('node_id', $this->exporter->id);
    expect($candidate)->toMatchArray(['selected' => $enable, 'caddy' => false, 'caddy_cleanup' => true, 'hosts' => []]);
    expect(collect($fleet)->firstWhere('node_id', $this->gateway->id)['caddy_cleanup'])->toBeFalse();
    Http::assertNothingSent();
})->with([true, false]);

it('makes internal role and Node hooks record intent without executing or waiting', function (): void {
    app(MetricsFleetReconciler::class)->reconcile();
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('metrics.reconcile')->and($operation->status->value)->toBe('queued')->and($operation->attempts)->toBe(0);
    Http::assertNothingSent();
});

it('refuses disabling the Metrics serving Node before changing intent', function (): void {
    $this->deleteJson('/api/v1/metrics/exporters/'.$this->metrics->id)->assertUnprocessable();
    expect(app(ExporterPreferenceRepository::class)->get($this->metrics->id))->toBeNull();
    expect(Operation::query()->count())->toBe(0);
});

it('rolls back the preference when another reconcile already holds admission', function (): void {
    app(MetricsFleetReconciler::class)->reconcile();
    $this->putJson('/api/v1/metrics/exporters/'.$this->exporter->id)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(app(ExporterPreferenceRepository::class)->get($this->exporter->id))->toBeNull();
    expect(Operation::query()->count())->toBe(1);
});

it('keeps opposite exporter commands distinct while preserving the admitted desired state', function (): void {
    $path = '/api/v1/metrics/exporters/'.$this->exporter->id;
    $this->withHeader('Idempotency-Key', 'exporter-same-key')->putJson($path)->assertAccepted();
    $this->withHeader('Idempotency-Key', 'exporter-same-key')->deleteJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect(app(ExporterPreferenceRepository::class)->get($this->exporter->id))->toBe(ExporterPreference::Enabled);
    expect(Operation::query()->count())->toBe(1);
});

it('completes a preference-only Operation through the normal executor without any agent work', function (): void {
    $this->assignment->delete();
    $response = $this->putJson('/api/v1/metrics/exporters/'.$this->exporter->id)->assertAccepted();
    $id = $response->json('data.id');
    config(['dependency_detection.services_node_id' => $this->gateway->id]);
    $runner = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'metrics.exporter_enable', 'worker' => 'preference-canary', 'operation_id' => $id])->assertOk();
    $path = '/api/v1/operation-executor/'.$id;
    $this->postJson($path.'/run', ['worker' => 'preference-canary', 'key' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt', 'input' => ['backend' => 'recorded']])->assertOk()->assertJsonPath('data.backend', 'recorded');
    $this->postJson($path.'/step', ['worker' => 'preference-canary', 'key' => 'receipt-finish', 'name' => 'integration-receipt', 'status' => 'succeeded', 'result' => ['backend' => 'recorded']])->assertOk();
    $this->postJson($path.'/complete', ['worker' => 'preference-canary', 'status' => 'succeeded', 'result' => ['backend' => 'active']])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    $this->postJson($path.'/complete', ['worker' => 'preference-canary', 'status' => 'succeeded', 'result' => ['backend' => 'recorded']])->assertOk();
    expect(Operation::query()->findOrFail($id)->status->value)->toBe('succeeded');
    expect($runner->commands())->toBe([]);
});
