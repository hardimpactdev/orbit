<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Actions\Nodes\UpdateNodeSettingsAction;
use App\Data\Nodes\NodeStorageAppsData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeAgentRekeyInput;
use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-fixture-ca';
        }
    });
    FleetFixtures::bind([app(AgentFootprintArtifact::class)]);
    $agents = new FakeAgentCommandRunner;
    FleetFixtures::recipeDelivery($agents);
    app()->instance(AgentCommandRunner::class, $agents);
    $this->agents = $agents;
    FleetFixtures::node('agent-native-gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('agent-native-target', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('a', 64)])->save();
    $this->socket = new FakeRoutePublicationSocket;
    $this->run = 'ac4a6bac-f943-47b6-96ac-9e555b5c2420';
    $this->socket->reply = fn (array $m): array => [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started', 'workflowName' => $m['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fixture', 'swamp_workers.nodes' => []]);
});

it('admits a full converge without a target worker, private material or Node calls', function (): void {
    $op = app(StartNodeConvergeAction::class)->execute($this->node, true);
    $inputs = $this->socket->sent[0]['payload']['inputs'];
    expect($this->agents->calls)->toBe([])
        ->and($inputs['intent']['node_id'])->toBe($this->node->id)
        ->and($inputs['intent']['ssh']['control_node_id'])->toBe(209)
        ->and($inputs['intent']['ssh']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key])
        ->and($inputs['intent']['verifier_hash'])->toBe(str_repeat('a', 64))
        ->and($op->lock_key)->toBe('node-converge:node:'.$this->node->id)
        ->and(app(DirectOperation::class)->definition('node.converge'))->not->toHaveKeys(['private_material', 'secret_outputs']);
});

it('prepares settings from a public SSH intent and stores them only at terminal success', function (): void {
    $op = app(UpdateNodeSettingsAction::class)->start($this->node, new NodeSettingsPatch(true, new NodeStorageAppsData('/srv/new')));
    expect($op)->toBeInstanceOf(Operation::class)
        ->and($this->node->fresh()->settings['apps']['path'] ?? null)->not->toBe('/srv/new')
        ->and($this->socket->sent[0]['payload']['inputs']['intent']['ssh']['control_node_id'])->toBe(209)
        ->and($this->agents->calls)->toBe([]);
    app(NodeSettingsProjection::class)->apply($op, ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['prepared' => true],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null]);
    expect($this->node->fresh()->settings['apps']['path'])->toBe('/srv/new');
});

it('captures recovery from its exact unfinished native source without worker credentials', function (): void {
    $source = app(StartNodeConvergeAction::class)->execute($this->node, true);
    expect($source->input['_converge']['payloads']['agent']['user'] ?? $source->input['_converge'])->toBe($this->node->user);
    $snapshot = $source->input;
    $snapshot['_converge']['agent_sha256'] = str_repeat('b', 64);
    $source->update(['input' => $snapshot]);
    $input = app(NodeAgentRekeyInput::class)->capture($this->node, $source->id);
    expect($input['configure_operation_id'])->toBe($source->id)
        ->and($input['agent_sha256'])->toBe(str_repeat('b', 64))
        ->and($input['ssh']['control_node_id'])->toBe(209)
        ->and($input)->not->toHaveKeys(['secret_hash', 'worker_credentials'])
        ->and($this->agents->calls)->toBe([]);
    $source->update(['status' => OperationStatus::Failed]);
    expect(fn () => app(NodeAgentRekeyInput::class)->capture($this->node, $source->id))->toThrow(ResourceOperationException::class);
});

it('refuses foreign, unbound and missing-config sources without changing their outcomes', function (string $case): void {
    $source = app(StartNodeConvergeAction::class)->execute($this->node, true);
    if ($case === 'foreign') {
        $other = FleetFixtures::node('agent-native-foreign');
        $source->update(['node_id' => $other->id]);
    } elseif ($case === 'unbound') {
        $source->update(['state' => []]);
    } else {
        $source->update(['input' => []]);
    }
    expect(fn () => app(NodeAgentRekeyInput::class)->capture($this->node, $source->id))->toThrow(ResourceOperationException::class);
    expect($source->fresh()->status)->toBe(OperationStatus::Running);
})->with(['foreign', 'unbound', 'missing-config']);

it('records only the public verifier on bound terminal success and ignores duplicate delivery', function (): void {
    $op = Operation::query()->create(['kind' => 'node.agent_rekey', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => [], 'state' => ['swamp_run_id' => $this->run]]);
    $data = ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['rekeyed' => true, 'verifier_hash' => str_repeat('b', 64)],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null];
    app(NodeAgentRekeyProjection::class)->apply($op, $data);
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('b', 64))
        ->and($op->fresh()->result)->toBe(['rekeyed' => true, 'restarted' => true]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    app(NodeAgentRekeyProjection::class)->apply($op, $data);
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
});

it('never records a verifier from a failed or malformed terminal result', function (bool $failed): void {
    $op = Operation::query()->create(['kind' => 'node.agent_rekey', 'target_type' => 'node', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => [], 'state' => ['swamp_run_id' => $this->run]]);
    $data = ['run_id' => $this->run, 'status' => $failed ? 'failed' : 'succeeded',
        'result' => $failed ? ['error_code' => 'operation.native_failed', 'verifier_hash' => str_repeat('b', 64)] : ['rekeyed' => true],
        'error_code' => $failed ? 'operation.native_failed' : null, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()];
    if ($failed) {
        app(NodeAgentRekeyProjection::class)->apply($op, $data);
    } else {
        expect(fn () => app(NodeAgentRekeyProjection::class)->apply($op, $data))->toThrow(ResourceOperationException::class);
    }
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64));
})->with([true, false]);
