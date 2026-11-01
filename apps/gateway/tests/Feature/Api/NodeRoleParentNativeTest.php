<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeRoleParentProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodeRoleRuntimeSource;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind([new FakeFootprintArtifact('agent', str_repeat('a', 64))]);
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public fixture root');
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('target');
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => [], 'swamp.run_token' => 'fake.run']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $sent): array => [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => ['vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $this->socket);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

it('admits a newly assigned Database through209 without a per-Node worker or private callback', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
    $inputs = app(DirectOperation::class)->workflowInputs($operation);
    expect($inputs['intent']['target']['created'])->toBeTrue()
        ->and($inputs['intent']['target']['assignment_id'])->toBe($this->node->roles()->sole()->id)
        ->and($inputs['intent']['target']['ssh']['control_node_id'])->toBe(209)
        ->and($inputs['intent']['target']['ssh']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key])
        ->and($inputs['execution_secrets']['observer']['key'])->toBe('node-role-'.$operation->id.'-observer')
        ->and(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64), 'runtime_material')
        ->and(app(DirectOperation::class)->definition('node.role_add'))->not->toHaveKey('private_material');
});

it('captures a real pre-transfer WebSocket source while preserving credentials until source success', function (): void {
    $source = FleetFixtures::node('source', [RoleName::WebSocket]);
    $assignment = $source->roles()->sole();
    app(NodeRoleRuntimeInput::class)->prepare($source, $assignment);
    $key = 'websocket.reverb.laravel_app_key';
    $settings = app(SettingRepository::class);
    $sourceScope = new SettingScope(SettingScopeType::Node, $source->id);
    $private = $settings->get($sourceScope, $key);
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->node, RoleName::WebSocket, true);
    $inputs = app(DirectOperation::class)->workflowInputs($operation);
    expect($source->roles()->count())->toBe(0)
        ->and($this->node->roles()->sole()->id)->toBe($assignment->id)
        ->and($inputs['intent']['target']['created'])->toBeFalse()
        ->and($inputs['intent']['source']['node_id'])->toBe($source->id)
        ->and($inputs['intent']['source']['assignment_id'])->toBe($assignment->id)
        ->and($inputs['intent']['withdrawal_grace_seconds'])->toBe(31)
        ->and($inputs['execution_secrets']['runtime_material']['key'])->toBe('node-role-'.$operation->id.'-runtime_material')
        ->and($settings->get($sourceScope, $key))->toBe($private);
    $material = app(NodeRoleRuntimeSource::class)->resolveRoleMaterial($operation->input['_role'], $this->node->id);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($material->value['websocket_environment']);
});

it('projects ready target and source-cleanup failure only at terminal and retains source credentials', function (): void {
    $source = FleetFixtures::node('source', [RoleName::WebSocket]);
    app(NodeRoleRuntimeInput::class)->prepare($source, $source->roles()->sole());
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->node, RoleName::WebSocket, true);
    $run = '405c68f5-e089-46db-bbeb-03a458b1c6c4';
    $operation->update(['state' => [...$operation->state, 'swamp_run_id' => $run]]);
    $target = ['role_proven' => true, 'runtime_proven' => true, 'certificates_proven' => true, 'publication_proven' => true,
        'footprint' => ['footprint_proven' => true, 'artifacts' => [['name' => 'agent', 'status' => 'applied']]],
        'cli' => ['outcome' => 'skipped']];
    app(NodeRoleParentProjection::class)->apply($operation, ['run_id' => $run, 'status' => 'failed',
        'started_at' => '2026-11-01T10:00:00Z', 'finished_at' => '2026-11-01T10:00:01Z', 'error_code' => 'node_role.source_cleanup_failed',
        'result' => ['target' => $target, 'target_completed' => true, 'source_completed' => false, 'error_code' => 'node_role.source_cleanup_failed', 'failed_step' => 'source']]);
    expect($this->node->roles()->sole()->status->value)->toBe('active')
        ->and(app(SettingRepository::class)->get(new SettingScope(SettingScopeType::Node, $source->id), 'websocket.reverb.laravel_app_key'))->toBeString()
        ->and($operation->fresh()->status->value)->toBe('failed');
});

it('refuses unsupported native roles before recording any assignment or vault effect', function (): void {
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and($this->node->roles()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});
