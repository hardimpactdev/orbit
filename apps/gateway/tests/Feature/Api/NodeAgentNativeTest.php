<?php

declare(strict_types=1);

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Actions\Nodes\UpdateNodeSettingsAction;
use App\Data\Nodes\NodeStorageAppsData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsPatch;
use App\Domain\Operations\DirectOperation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Schedules\NativeScheduleReplies;

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
        ->and(app(DirectOperation::class)->definition('node.converge'))->not->toHaveKeys(['private_material', 'secret_outputs'])
        ->and($inputs['intent'])->not->toHaveKey('payloads')
        ->and($inputs['intent']['roles'])->toMatchArray(['user' => $this->node->user, 'home' => '/home/'.$this->node->user])
        ->and($inputs['intent']['roles']['php_versions'])->toBe(array_keys((array) $inputs['intent']['roles']['php']));
});

it('shapes the role parts for their cfgmgmt steps: the pinned Caddy package and a private DNS release under its folder', function (): void {
    $roles = app(NodeConvergeInput::class)->roles($this->node, ['caddy-package' => [], 'private-dns' => [
        'listener' => ['release_id' => 'abc', 'service' => 'service', 'socket' => 'socket',
            'files' => [['path' => 'serve.php', 'contents' => '<?php', 'sha256' => 'x'], ['path' => 'app/Domain/AppDev/A.php', 'contents' => '<?php // a', 'sha256' => 'y']]],
        'publication' => ['records' => 'records', 'catalog' => "{}\n"]], 'tmpfiles' => ['rule' => 'e /tmp/orbit-* - - - 1d']]);
    $release = '/var/lib/orbit/private-dns/releases/abc';

    expect($roles['caddy_package'])->toBe(AgentCaddyPackage::nativeInput())
        ->and($roles['caddy_package']['sha256'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($roles['caddy'])->toBeNull()
        ->and($roles['dns'])->toMatchArray(['release' => $release, 'directories' => [$release, $release.'/app/Domain/AppDev'],
            'files' => [['path' => $release.'/serve.php', 'content' => '<?php'], ['path' => $release.'/app/Domain/AppDev/A.php', 'content' => '<?php // a']],
            'address' => $this->node->wireguard_ip, 'catalog_sha256' => hash('sha256', "{}\n")])
        ->and($roles['tmpfiles'])->toBe('e /tmp/orbit-* - - - 1d')
        ->and($roles['residue'])->toBeNull();
});

it('prepares settings from a public SSH intent and stores them only at terminal success', function (): void {
    $op = app(UpdateNodeSettingsAction::class)->start($this->node, new NodeSettingsPatch(true, new NodeStorageAppsData('/srv/new')));
    expect($op)->toBeInstanceOf(Operation::class)
        ->and($this->node->fresh()->settings['apps']['path'] ?? null)->not->toBe('/srv/new')
        ->and($this->socket->sent[0]['payload']['inputs']['intent']['ssh']['control_node_id'])->toBe(209)
        ->and($this->socket->sent[0]['payload']['inputs']['intent']['storage'])->toBe(['root' => '/srv/new', 'user' => $this->node->user, 'group' => $this->node->user])
        ->and($this->agents->calls)->toBe([]);
    app(NodeSettingsProjection::class)->apply($op, ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['steps' => ['Prove the apps folder' => ['status' => 'succeeded']]],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null]);
    expect($this->node->fresh()->settings['apps']['path'])->toBe('/srv/new');
});

function agentRekeyOperation(string $run, int $nodeId): Operation
{
    return Operation::query()->create(['kind' => 'node.agent_rekey', 'target_type' => 'node', 'target_id' => $nodeId, 'node_id' => $nodeId,
        'status' => 'running', 'state' => ['swamp_run_id' => $run], 'input' => ['_agent_rekey' => ['node_id' => $nodeId, 'verifier_hash' => str_repeat('b', 64),
            'agent_key' => ['vault' => 'orbit-control-inputs', 'key' => 'node-agent-rekey-'.$nodeId.'-1']]]]);
}

it('records the admitted verifier on terminal success, deletes the vault copy and ignores duplicate delivery', function (): void {
    $this->socket->reply = fn (array $m): ?array => NativeScheduleReplies::for($m);
    $op = agentRekeyOperation($this->run, $this->node->id);
    expect(app(NodeAgentRekeyProjection::class)->input($op))->toBe(['intent' => ['node_id' => $this->node->id, 'verifier_hash' => str_repeat('b', 64)],
        'execution_secrets' => ['agent_key' => ['vault' => 'orbit-control-inputs', 'key' => 'node-agent-rekey-'.$this->node->id.'-1']]]);
    $data = ['run_id' => $this->run, 'status' => 'succeeded', 'result' => ['steps' => ['Prove the new key' => ['status' => 'succeeded']]],
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'error_code' => null];
    app(NodeAgentRekeyProjection::class)->apply($op, $data);
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('b', 64))
        ->and($op->fresh()->result)->toBe(['rekeyed' => true, 'restarted' => true])
        ->and($this->socket->sent[0]['type'])->toBe('vault.delete')
        ->and($this->socket->sent[0]['payload']['key'])->toBe('node-agent-rekey-'.$this->node->id.'-1');
    $this->node->forceFill(['agent_secret_hash' => str_repeat('c', 64)])->save();
    app(NodeAgentRekeyProjection::class)->apply($op, $data);
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('c', 64));
});

it('keeps the old verifier when the run failed and still deletes the vault copy', function (): void {
    $this->socket->reply = fn (array $m): ?array => NativeScheduleReplies::for($m);
    $op = agentRekeyOperation($this->run, $this->node->id);
    app(NodeAgentRekeyProjection::class)->apply($op, ['run_id' => $this->run, 'status' => 'failed', 'result' => ['steps' => ['Restart the Node agent' => ['status' => 'failed']]],
        'error_code' => 'operation.native_failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);
    expect($this->node->fresh()->agent_secret_hash)->toBe(str_repeat('a', 64))
        ->and($op->fresh()->result)->toBe(['rekeyed' => false, 'error_code' => 'operation.native_failed'])
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.delete']);
});
