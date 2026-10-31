<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final class PublicParticipantFixture implements DirectOperationProjection, DirectOperationPublicView
{
    public string $content = 'first-public-plan';

    public function input(Operation $operation): array
    {
        return ['root_only' => true];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        throw new LogicException('Foreign participants cannot project.');
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (($operation->input['publication_owner_id'] ?? null) !== $callerNodeId) {
            throw new ResourceOperationException('operation.executor_forbidden', 'This Node does not own this publication.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        return ['participant_node_id' => $callerNodeId, 'content' => $this->content];
    }
}

beforeEach(function (): void {
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14']));
    $this->root = Node::query()->create(['name' => 'root', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.17']);
    $this->participant = Node::query()->create(['name' => 'participant', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.9']);
    $this->participant->accessibleNodes()->attach($this->gateway);
    $this->root->accessibleNodes()->attach($this->gateway);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'analytics.enable', 'target_type' => 'node', 'target_id' => $this->root->id, 'node_id' => $this->root->id,
        'status' => OperationStatus::Running, 'sequence' => 1, 'state' => ['fixture_writer' => 'direct', 'swamp_run_id' => $this->run],
        'input' => ['participant_node_ids' => [$this->root->id, $this->participant->id], 'publication_owner_id' => $this->participant->id],
        'result' => ['root_result' => 'do-not-share']]);
    $reflection = new ReflectionClass(DirectOperation::class);
    $direct = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('definitions')->setValue($direct, ['analytics.enable' => ['workflow' => 'operation-public-fixture', 'writer_config' => 'swamp.direct_writers', 'writer_state' => 'fixture_writer', 'projection' => PublicParticipantFixture::class]]);
    app()->instance(DirectOperation::class, $direct);
    $this->projection = new PublicParticipantFixture;
    app()->instance(PublicParticipantFixture::class, $this->projection);
    config(['dependency_detection.services_node_id' => $this->participant->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->participant->wireguard_ip]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
});

it('reads fresh caller public input while preserving the parent identity and hiding its result', function (): void {
    $before = $this->operation->fresh()->getAttributes();
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.node_id', $this->root->id)->assertJsonPath('data.input.participant_node_id', $this->participant->id)
        ->assertJsonPath('data.input.content', 'first-public-plan')->assertJsonMissingPath('data.result')->assertJsonMissingPath('data.input.root_only');
    $this->projection->content = 'fresh-public-plan';
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.content', 'fresh-public-plan');
    expect($this->operation->fresh()->getAttributes())->toBe($before);
});

it('requires both immutable admission and current family ownership', function (): void {
    $this->operation->update(['input' => [...$this->operation->input, 'participant_node_ids' => [$this->root->id]]]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertForbidden();
    $this->operation->update(['input' => ['participant_node_ids' => [$this->root->id, $this->participant->id], 'publication_owner_id' => $this->root->id]]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertForbidden();
});

it('requires the bound native root run and an unfinished parent', function (): void {
    $this->getJson($this->path.'?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
    $state = $this->operation->state;
    $this->operation->update(['state' => ['fixture_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->operation->update(['state' => $state, 'status' => OperationStatus::Succeeded]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
});

it('does not grant Services or a public participant mutation or private access', function (): void {
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => '2026-10-31T07:00:00.000Z'])->assertForbidden();
    $this->postJson($this->path.'/primitive', ['run_id' => $this->run])->assertForbidden();
    $this->getJson($this->path.'/private-material?run_id='.$this->run)->assertForbidden();
    $this->postJson($this->path.'/secret-output', ['run_id' => $this->run, 'outputs' => ['foreign' => 'refused']])->assertForbidden();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
});

it('keeps the root normal input and does not require the optional participant hook', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->root->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input.root_only', true)->assertJsonPath('data.node_id', $this->root->id);
});
