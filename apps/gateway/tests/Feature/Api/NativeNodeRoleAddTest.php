<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Models\NodeFootprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind([app(CaddyPackageFootprintArtifact::class), app(PhpRuntimeFootprintArtifact::class), app(TmpfilesFootprintArtifact::class)]);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('public fixture certificate');
    app()->instance(LeafCertificateSigner::class, $certificates);
    FleetFixtures::node('gateway', [RoleName::Gateway]);
    $this->node = FleetFixtures::node('role-target');
    $this->node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    config(['swamp_workers.nodes' => []]);
    DB::beginTransaction();
});

afterEach(function (): void {
    DB::rollBack();
});

it('admits a database role through the control worker with its complete public runtime plan', function (): void {
    $this->node->forceFill(['agent_secret_hash' => null])->save();
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($operation->kind)->toBe('node.role_add');
    expect($input['intent']['ssh']['control_node_id'])->toBe(209);
    expect($input['intent']['packages'])->toContain('docker.io');
    expect(array_column($input['intent']['services'], 'name'))->toContain('docker.service');
    expect($input['intent'])->not->toHaveKeys(['agent', 'verifier_hash', 'cli', 'secret_hash']);
    expect((array) $input['execution_secrets'])->toBe([]);
    expect($this->node->roles()->where('role', RoleName::Database)->sole()->status->value)->toBe('provisioning');
});

it('captures application role setup without a target worker or observer work', function (RoleName $role): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, $role);
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($input['intent']['role'])->toBe($role->value);
    expect($input['intent']['ssh']['control_node_id'])->toBe(209);
    expect(array_column($input['intent']['managers'], 'manager'))->toBe(['vp', 'composer']);
    expect($input['intent']['caddy_package']['sha256'])->toHaveLength(64);
    expect($input['intent'])->not->toHaveKeys(['agent', 'verifier_hash', 'cli', 'secret_hash']);
})->with([RoleName::AppDev, RoleName::AppProd]);

it('refuses a protected application storage root before host work', function (string $path): void {
    $this->node->forceFill(['settings' => ['apps' => ['path' => $path]]])->save();
    expect(fn () => app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev))
        ->toThrow(function (ResourceOperationException $error) use ($path): void {
            expect($error->errorCode)->toBe($path === '/' ? 'node.settings_path_invalid' : 'node.settings_path_protected');
        });
    expect($this->node->roles()->count())->toBe(0);
})->with(['/', '/home', '/home/orbit', '/etc', '/usr']);

it('captures the complete role resources when the Node footprint payloads are cached away', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev);
    $snapshot = $operation->input['_role'];
    $snapshot['footprint']['payloads'] = [];
    $capture = app(NativeNodeRoleAddInput::class)->capture($this->node, $this->node->roles()->sole(), $snapshot, [], $operation);
    expect($capture['intent']['php'])->not->toBeEmpty()
        ->and($capture['intent']['php_packages'])->not->toBeEmpty()
        ->and(array_column($capture['intent']['files'], 'path'))->toContain('/etc/tmpfiles.d/orbit.conf');
});

it('vaults complete private role files and exposes only their descriptors', function (RoleName $role): void {
    config(['swamp.run_token' => 'fixture.token']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $sent): array => $sent['type'] === 'vault.put' ? [['type' => 'vault.put', 'id' => $sent['id'], 'payload' => ['data' => [
        'vaultName' => $sent['payload']['vaultName'], 'secretKey' => $sent['payload']['key'], 'vaultType' => 'local_encryption',
    ]]]] : [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => $sent['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, $role);
    $input = app(DirectOperation::class)->workflowInputs($operation);
    $file = $input['intent']['runtime']['secret_files'][0];
    $descriptor = $input['execution_secrets'][$file['secret']];
    expect($file['mode'])->toBe('0600')
        ->and($descriptor['vault'])->toBe('orbit-control-inputs')
        ->and($descriptor['key'])->toStartWith('node-role-operation-'.$operation->id.'-');
    DB::commit();
    $copies = array_values(array_filter($socket->sent, static fn (array $sent): bool => $sent['type'] === 'vault.put'));
    expect($copies)->not->toBeEmpty();
    $public = json_encode($input, JSON_THROW_ON_ERROR);
    foreach ($copies as $sent) {
        expect($public)->not->toContain($sent['payload']['value']);
    }
    expect($file['owner'])->toBe($role === RoleName::Metrics ? 'orbit-grafana' : $this->node->user);
    if ($role === RoleName::Metrics) {
        $gateway = collect($input['intent']['publications'])->first(fn (array $publication): bool => $publication['after']['caddy'] !== null);
        expect($gateway['after']['caddy']['content'])->toContain('/etc/caddy/orbit-metrics-cert-current/');
        foreach ($input['intent']['exporters'] as $exporter) {
            $publication = collect($input['intent']['publications'])->firstWhere('node_id', $exporter['exporter_node_id']);
            if (($publication['after']['caddy'] ?? null) !== null) {
                expect($exporter['service']['caddy'])->toBeNull();
            }
            if (($publication['after']['php'] ?? null) !== null) {
                expect($exporter['service']['pools'])->toBe([]);
            }
        }
    }
})->with([RoleName::WebSocket, RoleName::Metrics]);

it('records only the role-owned footprint from a successful native end check', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::AppDev);
    $operation->forceFill(['state' => [...$operation->state, 'swamp_run_id' => 'fixture-native-role']])->save();
    app(NodeRoleDirectProjection::class)->apply($operation, [
        'run_id' => 'fixture-native-role', 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Check the role' => ['status' => 'succeeded']]],
    ]);
    expect($this->node->roles()->sole()->status->value)->toBe('active');
    $artifacts = NodeFootprint::query()->where('node_id', $this->node->id)->sole()->artifacts;
    expect($artifacts)->toBe($operation->input['native_role']['artifact_digests'])
        ->toHaveKeys(['caddy-package', 'php-runtime', 'tmpfiles'])
        ->not->toHaveKeys(['agent', 'cli', 'caddy']);
});

it('retains the supported-OS refusal and failed assignment from the native summary', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
    $operation->forceFill(['state' => [...$operation->state, 'swamp_run_id' => 'fixture-native-role']])->save();
    app(NodeRoleDirectProjection::class)->apply($operation, [
        'run_id' => 'fixture-native-role', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['summary' => ['failed_steps' => ['Require supported Ubuntu']]],
    ]);
    expect($operation->refresh()->error_code)->toBe('role.os_unsupported')
        ->and($this->node->roles()->sole()->status->value)->toBe('failed')
        ->and(NodeFootprint::query()->where('node_id', $this->node->id)->exists())->toBeFalse();
});

it('retains a published certificate when a later native publication fails', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database);
    $input = $operation->input;
    $input['_role']['certificates']['leaves'] = [['node_id' => $this->node->id, 'scope' => 'role-websocket']];
    $operation->forceFill(['input' => $input, 'state' => [...$operation->state, 'swamp_run_id' => 'fixture-native-role']])->save();
    app(NodeRoleDirectProjection::class)->apply($operation, [
        'run_id' => 'fixture-native-role', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Sign role certificates-0' => ['status' => 'succeeded']],
            'summary' => ['failed_steps' => ['Publish Caddy sites-0']]],
    ]);
    expect(app(CaddySiteCertificates::class)->published($this->node->id, 'websocket'))->toBeTrue()
        ->and($this->node->roles()->sole()->status->value)->toBe('failed');
});

it('removes the native Gateway DNS unit along with the retired resolver drop-in', function (): void {
    $assignment = $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $capture = app(NativeNodeRoleRemovalInput::class);
    $before = $capture->publications($this->node, RoleName::Gateway);
    $assignment->update(['status' => 'removing']);
    $plan = $capture->capture($this->node, $assignment, $before);
    expect(array_column(array_column($plan['stop_units'], 'input'), 'unit'))->toContain('orbit-gateway-dns.service')
        ->and(array_column($plan['files'], 'path'))->toContain('/etc/systemd/system/orbit-gateway-dns.service',
            '/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf');
});


it('converges a role on a Node with other roles without an observer identity or a target worker', function (): void {
    $this->node->roles()->create(['role' => RoleName::Database, 'status' => 'active']);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->node->forceFill(['agent_secret_hash' => null])->save();
    $operation = app(AdmitNodeRoleOperationAction::class)->add($this->node, RoleName::Database, true);
    $input = app(DirectOperation::class)->workflowInputs($operation);

    expect($operation->kind)->toBe('node.role_converge')
        ->and($input['intent']['role'])->toBe('database')
        ->and($input['intent']['ssh']['control_node_id'])->toBe(209)
        ->and($input['intent'])->not->toHaveKeys(['agent', 'verifier_hash', 'cli', 'secret_hash'])
        ->and($this->node->roles()->where('role', RoleName::AppDev)->sole()->status->value)->toBe('active');
});
