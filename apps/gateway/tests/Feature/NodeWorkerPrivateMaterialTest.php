<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolIntentProjection;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Activity;
use App\Models\Operation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(FleetFixtures::node('worker-private-gateway'));
    $this->node = FleetFixtures::node('worker-private-target');
    $this->node->accessibleNodes()->attach($this->gateway);
    config(['swamp_workers.nodes' => [$this->node->id], 'operation_executor.worker_node_ids' => [$this->node->id, $this->gateway->id], 'dependency_detection.services_node_id' => $this->gateway->id]);
    $this->credentials = ['enrollment_token' => 'orbit-node-'.$this->node->id.'.privateEnrollmentSentinel', 'server_token' => 'orbit-node-'.$this->node->id.'-access.privateServerSentinel'];
    $this->source = app(NodeWorkerEnrollment::class);
    $this->reference = $this->source->stage($this->node, $this->credentials);
    $this->run = '6eb3e06c-8497-4d27-86e9-d3610aacb410';
    $this->operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['worker_test_writer' => 'direct', 'swamp_run_id' => $this->run],
        'input' => ['_worker_enrollment' => $this->reference, 'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $this->node->id]]]);
    // The owning converge conversion supplies this declaration; this fixture exercises its shared transport.
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, ['node.converge' => ['workflow' => 'operation-worker-private-test', 'writer_config' => 'swamp.direct_writers', 'writer_state' => 'worker_test_writer', 'projection' => ToolIntentProjection::class,
        'private_material' => ['worker_credentials' => 'node.worker_credentials']]]);
    app()->instance(DirectOperation::class, $direct);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id.'/private-material?run_id='.$this->run;
});

it('reads staged enrollment only through the bound Node private callback without durable secrets', function (): void {
    $before = $this->operation->fresh()->getAttributes();
    $this->getJson($this->path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.worker_credentials', $this->credentials);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode([$this->operation->fresh()->toArray(), $this->operation->steps()->get()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
    expect(Cache::get('nodes:worker-enrollment:'.$this->reference))->not->toContain('privateEnrollmentSentinel', 'privateServerSentinel');
    Http::assertNothingSent();
});

it('refuses foreign caller and run bindings before private hydration', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->getJson($this->path)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson(str_replace($this->run, '72c94457-ddf7-48e9-82e7-161581aa272e', $this->path))->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    $this->operation->update(['state' => ['worker_test_writer' => 'direct']]);
    $this->getJson($this->path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    expect(fn () => $this->source->resolve($this->operation, $this->node->id, '', 'worker_credentials'))->toThrow(ResourceOperationException::class);
});

it('refuses terminal Node enrollment reads', function (OperationStatus $status): void {
    $this->operation->update(['status' => $status]);
    $this->getJson($this->path)->assertConflict()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('error.code', 'operation.finished');
    expect(fn () => $this->source->resolve($this->operation, $this->node->id, '', 'worker_credentials'))->toThrow(ResourceOperationException::class);
})->with([OperationStatus::Succeeded, OperationStatus::Failed, OperationStatus::Cancelled]);

it('refuses foreign staged owners and wrong field or transport keys', function (): void {
    foreach (['footprint-agent', 'foreign-step'] as $key) {
        expect(fn () => $this->source->resolve($this->operation, $this->node->id, $key, 'worker_credentials'))->toThrow(ResourceOperationException::class);
    }
    expect(fn () => $this->source->resolve($this->operation, $this->node->id, '', 'foreign_credentials'))->toThrow(ResourceOperationException::class);
    $this->operation->update(['target_id' => $this->gateway->id]);
    $this->getJson($this->path)->assertUnprocessable();
    $this->operation->update(['target_id' => $this->node->id, 'input' => [...$this->operation->input, 'worker_credentials' => ['$secret' => 'node.worker_credentials', 'id' => $this->gateway->id]]]);
    $this->getJson($this->path)->assertUnprocessable();
});

it('fails closed for expired staging and permits null only when no enrollment was admitted', function (): void {
    $this->travel(31)->minutes();
    $this->getJson($this->path)->assertConflict()->assertJsonPath('error.code', 'swamp.node_worker_not_ready');
    $input = $this->operation->input;
    unset($input['_worker_enrollment']);
    $this->operation->update(['input' => $input]);
    $this->getJson($this->path)->assertOk()->assertJsonPath('data.worker_credentials', null);
});

it('retains only the unfinished old parent footprint key', function (): void {
    $this->operation->update(['state' => []]);
    expect($this->source->resolve($this->operation, $this->node->id, 'footprint-agent', 'worker_credentials')->value)->toBe($this->credentials);
    expect(fn () => $this->source->resolve($this->operation, $this->node->id, '', 'worker_credentials'))->toThrow(ResourceOperationException::class);
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    expect(fn () => $this->source->resolve($this->operation, $this->node->id, 'footprint-agent', 'worker_credentials'))->toThrow(ResourceOperationException::class);
});

it('refuses another Nodes staged material and damaged ciphertext without disclosing credentials', function (): void {
    $other = FleetFixtures::node('worker-private-other');
    config(['swamp_workers.nodes' => [$this->node->id, $other->id]]);
    $foreign = $this->source->stage($other, ['enrollment_token' => 'orbit-node-'.$other->id.'.privateOtherEnrollment', 'server_token' => 'orbit-node-'.$other->id.'-access.privateOtherServer']);
    $this->operation->update(['input' => [...$this->operation->input, '_worker_enrollment' => $foreign]]);
    $this->getJson($this->path)->assertConflict()->assertJsonPath('error.code', 'swamp.node_worker_not_ready')->assertDontSee('privateOtherEnrollment')->assertDontSee('privateOtherServer');
    Cache::put('nodes:worker-enrollment:'.$foreign, 'damaged ciphertext');
    $this->getJson($this->path)->assertConflict()->assertJsonPath('error.code', 'swamp.node_worker_not_ready');
});
