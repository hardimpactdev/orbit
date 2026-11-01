<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind([new FakeFootprintArtifact('agent', str_repeat('a', 64)), app(PhpRuntimeFootprintArtifact::class), new TmpfilesFootprintArtifact]);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('public fixture certificate');
    app()->instance(LeafCertificateSigner::class, $certificates);
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('native-development', [RoleName::AppDev]);
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64), 'settings' => ['apps' => ['path' => '/srv/development/apps']]])->save();
    config(['swamp_workers.nodes' => [], 'swamp.run_token' => 'fake.token']);
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

it('admits AppDev convergence from 209 without a target worker and preserves its actual identity', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    $intent = $input['intent'];

    expect($intent)->toMatchArray(['node_id' => $this->node->id, 'role' => 'app-dev', 'created' => false])
        ->and($intent['ssh']['control_node_id'])->toBe(209)
        ->and($intent['ssh']['target']['host_keys'])->toBe([$this->node->ssh_host_key_type.' '.$this->node->ssh_host_key])
        ->and($intent['storage'])->toMatchArray(['root' => '/srv/development/apps', 'user' => $this->node->user, 'create_missing' => true])
        ->and($intent['prerequisites']['apps_directory'])->toBeTrue()
        ->and($intent['php']['versions'])->not->toBeEmpty()
        ->and($intent['php']['fpm'])->toBe('shared')
        ->and($intent['php']['extras'])->toBe('keep')
        ->and($intent['php']['pcov'])->toBeTrue()
        ->and($intent['prerequisites']['packages'])->not->toContain('php-curl', 'php-xml')
        ->and($intent['javascript'])->not->toBeNull()
        ->and($this->socket->sent[0]['payload']['value'])->toBe(str_repeat('e', 64))
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64))
        ->and(NodeRoleDirectInput::ownerId($operation))->toBeNull()
        ->and($this->node->refresh()->agent_secret_hash)->toBe(str_repeat('e', 64));
});

it('captures post-convergence PHP and tmpfiles duties while the real assignment remains claimed', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true);
    $intent = app(DirectOperation::class)->workflowInputs($operation)['intent'];

    expect($this->node->roles()->sole()->status->value)->toBe('provisioning')
        ->and($intent['footprint']['payloads']['tmpfiles'])->toBe(['rule' => TmpfilesFootprintArtifact::Rule])
        ->and($intent['footprint']['payloads']['php-runtime']['configurations'])->not->toBeEmpty()
        ->and(array_column($intent['footprint']['artifacts'], 'name'))->toContain('php-runtime', 'tmpfiles')
        ->and($operation->input['_role']['footprint'])->toBe($intent['footprint']);
});

it('freezes all private DNS publisher connections and snapshots at admission', function (): void {
    $vpn = FleetFixtures::node('private-dns', [RoleName::Vpn]);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true);
    $intent = app(DirectOperation::class)->workflowInputs($operation)['intent'];
    $dns = array_find($intent['publications'], static fn (array $row): bool => $row['node_id'] === $vpn->id);

    expect($dns)->not->toBeNull()
        ->and($dns['control_node_id'])->toBe(209)
        ->and($dns['host_keys'])->toBe([$vpn->ssh_host_key_type.' '.$vpn->ssh_host_key])
        ->and($dns['after']['dns']['catalog'])->toBeString()
        ->and($operation->input['participant_node_ids'])->toContain($vpn->id, 209);
});

it('refuses a missing observer identity before recording an operation or copying secrets', function (): void {
    $this->node->forceFill(['agent_secret_hash' => null])->save();
    $count = Operation::query()->count();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true))
        ->toThrow(ResourceOperationException::class, 'The role needs the existing recorded Node agent identity.');
    expect(Operation::query()->count())->toBe($count)
        ->and($this->socket->sent)->toBeEmpty()
        ->and($this->node->refresh()->agent_secret_hash)->toBeNull();
});

it('refuses protected configured storage before any vault write', function (): void {
    $this->node->forceFill(['settings' => ['apps' => ['path' => '/etc/orbit/apps']]])->save();

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev, true))
        ->toThrow(ResourceOperationException::class, 'The apps storage path is protected.');
    expect($this->socket->sent)->toBeEmpty()
        ->and($this->node->roles()->sole()->status->value)->toBe('active');
});
