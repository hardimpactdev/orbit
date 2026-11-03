<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\NodeRoleRelocateProjection;
use App\Domain\Nodes\NodeRoleRuntimeInput;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Fleet\FleetFixtures;

beforeEach(function (): void {
    FleetFixtures::bind();
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('Admission must never sign a certificate.');
        }

        public function rootCertificate(): string
        {
            return 'role-public-root';
        }
    });
    FleetFixtures::node('relocation-gateway', [RoleName::Gateway]);
    $this->source = FleetFixtures::node('relocation-source', [RoleName::WebSocket]);
    $this->target = FleetFixtures::node('relocation-target');
    app(NodeRoleRuntimeInput::class)->prepare($this->source, $this->source->roles()->sole());
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    DB::beginTransaction();
});
afterEach(function (): void {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

/** @return array<string,mixed> */
function relocationTerminal(Operation $operation, string $status, array $steps): array
{
    $run = '405c68f5-e089-46db-bbeb-03a458b1c6c4';
    $operation->update(['state' => [...$operation->state ?? [], 'swamp_run_id' => $run]]);
    $failed = array_keys(array_filter($steps, static fn (string $step): bool => $step === 'failed'));

    return ['run_id' => $run, 'status' => $status, 'started_at' => '2026-11-03T10:30:00Z', 'finished_at' => '2026-11-03T10:31:00Z',
        'error_code' => $status === 'succeeded' ? null : 'operation.native_failed',
        'result' => ['steps' => array_map(static fn (string $step): array => ['status' => $step], $steps),
            'summary' => ['status' => $status, 'succeeded' => 0, 'failed' => count($failed), 'skipped' => 0, 'failed_steps' => $failed]]];
}

it('copies source credentials without replacing an existing destination credential', function (?string $existing): void {
    $settings = app(SettingRepository::class);
    $key = 'websocket.reverb.laravel_app_key';
    $source = new SettingScope(SettingScopeType::Node, $this->source->id);
    $target = new SettingScope(SettingScopeType::Node, $this->target->id);
    $settings->put($source, $key, 'source-private', SettingValueProtection::Secret);
    if ($existing !== null) {
        $settings->put($target, $key, $existing, SettingValueProtection::Secret);
    }
    $assignmentId = $this->source->roles()->sole()->id;

    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);

    expect($this->source->roles()->count())->toBe(0)
        ->and($this->target->roles()->sole()->id)->toBe($assignmentId)
        ->and($settings->get($source, $key))->toBe('source-private')
        ->and($settings->get($target, $key))->toBe($existing ?? 'source-private');
})->with([null, 'existing-target-private']);

it('captures the target runtime, its certificate and the source removal as one native intent', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);
    $intent = $operation->input['intent'];

    expect($intent['role'])->toBe('websocket')
        ->and($intent['target_node_id'])->toBe($this->target->id)
        ->and($intent['source_node_id'])->toBe($this->source->id)
        ->and($intent['ssh']['target']['host'])->toBe($this->target->wireguard_ip)
        ->and($intent['ssh']['target']['host_keys'])->toBe(['ssh-ed25519 ssh-ed25519 AAAArelocation-target'])
        ->and($intent['websocket']['release'])->toStartWith('/opt/orbit/websocket.releases/release.')
        ->and($intent['websocket']['unit']['content'])->toContain('User=orbit', '--port='.config('orbit.websocket.port'))
        ->and($intent['metrics'])->toBeNull()
        ->and($intent['gateway'])->toBeNull()
        ->and(array_column($intent['certificates']['leaves'], 'node_id'))->toBe([$this->target->id])
        ->and($intent['certificates']['leaves'][0]['domain'])->toBe('reverb.orbit')
        ->and($intent['certificates']['leaves'][0]['ssh']['hosts'][0]['address'])->toBe($this->target->wireguard_ip)
        ->and(array_column($intent['publications'], 'node_id'))->not->toContain($this->source->id)
        ->and($intent['source']['node_id'])->toBe($this->source->id)
        ->and(array_column($intent['source']['stop_units'], 'input'))->toBe([['unit' => 'orbit-websocket.service']])
        ->and($intent['source']['stop_units'][0]['ssh']['target']['host'])->toBe($this->source->wireguard_ip)
        ->and($operation->input['_relocate'])->toBe(['assignment_id' => $this->target->roles()->sole()->id, 'role' => 'websocket',
            'source_node_id' => $this->source->id, 'target_node_id' => $this->target->id]);
});

it('sends Reverb\'s environment to the vault and only its key name to the run', function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => '2d2deaf0-e03d-49fb-a6e1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture-run', 'swamp.read_token' => 'fixture-read']);
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Node, $this->source->id), 'websocket.reverb.laravel_app_key', 'base64:fixture-private-app-key', SettingValueProtection::Secret);
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);
    $key = 'node-role-relocate-operation-'.$operation->id;

    $inputs = app(DirectOperation::class)->workflowInputs($operation);
    // Execution copies leave after commit; reopen the transaction the hooks roll back.
    DB::commit();
    DB::beginTransaction();

    expect($inputs['execution_secrets'])->toBe(['websocket_env' => ['vault' => 'orbit-control-inputs', 'key' => $key]])
        ->and($inputs['kind'])->toBe('node.role_relocate')
        ->and($inputs['intent'])->toBe($operation->input['intent'])
        ->and(json_encode($inputs))->not->toContain('fixture-private-app-key')
        ->and($socket->sent[0]['type'])->toBe('vault.put')
        ->and($socket->sent[0]['payload']['key'])->toBe($key)
        ->and($socket->sent[0]['payload']['value'])->toContain('fixture-private-app-key');
});

it('activates the target and forgets the source credentials only after the native end check', function (bool $completed): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);
    app(NodeRoleRelocateProjection::class)->apply($operation, relocationTerminal($operation, $completed ? 'succeeded' : 'failed',
        $completed ? ['Remove from the source' => 'succeeded', 'Confirm the move' => 'succeeded'] : ['Remove from the source' => 'failed']));
    $settings = app(SettingRepository::class);
    $key = 'websocket.reverb.laravel_app_key';
    $assignment = $this->target->roles()->sole();

    expect($settings->get(new SettingScope(SettingScopeType::Node, $this->source->id), $key) === null)->toBe($completed)
        ->and($settings->get(new SettingScope(SettingScopeType::Node, $this->target->id), $key))->toBeString()
        ->and($assignment->status->value)->toBe($completed ? 'active' : 'failed')
        ->and($assignment->failed_step)->toBe($completed ? null : 'remove:Remove from the source')
        ->and($operation->fresh()->result)->toBe($completed
            ? ['node_id' => $this->target->id, 'source_node_id' => $this->source->id, 'role' => 'websocket', 'relocated' => true]
            : ['failed_step' => 'Remove from the source', 'error_code' => 'operation.native_failed']);
})->with([false, true]);

it('names a failed target step as a convergence step', function (): void {
    $operation = app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true);
    app(NodeRoleRelocateProjection::class)->apply($operation, relocationTerminal($operation, 'failed', ['Install Reverb packages' => 'failed']));

    expect($this->target->roles()->sole()->failed_step)->toBe('converge:Install Reverb packages');
});

it('refuses the swamp role with its own code before it records anything', function (): void {
    $swamp = FleetFixtures::node('relocation-swamp', [RoleName::Swamp]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->remove($swamp, RoleName::Swamp, true))
        ->toThrow(NodeRoleValidationException::class, "Role [swamp] runs this removal's Swamp run, so it can't remove itself.");
    try {
        app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::Swamp, true, $swamp);
        $this->fail('The swamp role was relocated.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.role_relocate_swamp_held')
            ->and($exception->status)->toBe(422)
            ->and($exception->details)->toBe(['role' => 'swamp']);
    }
    expect(Operation::query()->whereIn('kind', ['node.role_remove', 'node.role_relocate'])->exists())->toBeFalse()
        ->and($swamp->roles()->sole()->node_id)->toBe($swamp->id);
});

it('refuses a source or target without recorded SSH management', function (): void {
    $this->target->update(['ssh_host_fingerprint' => null]);

    expect(fn () => app(AdmitNodeRoleOperationAction::class)->relocate($this->target, RoleName::WebSocket, true))
        ->toThrow(ResourceOperationException::class, 'Role relocation requires two active Linux Nodes with recorded SSH management.');
    expect($this->source->roles()->sole()->role)->toBe(RoleName::WebSocket);
});
