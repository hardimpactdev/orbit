<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\Nodes\NodeRemovalHook;
use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\AdmitConnSessionHoldAction;
use Orbit\Extensions\Conn\Actions\RevokeConnPairingsAction;
use Orbit\Extensions\Conn\Domain\ConnHeldSessions;
use Orbit\Extensions\Conn\Domain\ConnHeldSessionSweep;
use Orbit\Extensions\Conn\Domain\ConnInstanceLifecycle;
use Orbit\Extensions\Conn\Domain\ConnNodeAccessRemoval;
use Orbit\Extensions\Conn\Domain\ConnNodeRemoval;
use Orbit\Extensions\Conn\Domain\ConnRevocationProjection;
use Orbit\Extensions\Conn\Domain\ConnSessionHoldProjection;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\SwampNodeFixture;

use function Pest\Laravel\freezeTime;

uses(LazilyRefreshDatabase::class);

const HeldRun = '3a1f9c2e-7b4d-4e8a-9c61-5d2e8f0a7b13';
const HeldModel = '00000000-0000-4000-8000-000000000009';

beforeEach(function (): void {
    Queue::fake();
    freezeTime();
    app(ExtensionStore::class)->set('conn', true);
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/fixture-ca', 'swamp.run_token' => 'fixture-token']);
    // Swamp's vault: puts succeed, the held session reads back, deletes are recorded.
    $this->vault = new class implements WebSocketClient
    {
        public array $sent = [];

        public string $session = 'held-session-sentinel';

        private array $last = [];

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

        public function send(array $message): void
        {
            $this->sent[] = $this->last = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            return match ($this->last['type']) {
                'vault.put' => [['id' => $this->last['id'], 'type' => 'vault.put', 'payload' => ['data' => [
                    'vaultName' => $this->last['payload']['vaultName'], 'secretKey' => $this->last['payload']['key'], 'vaultType' => 'local_encryption']]]],
                'vault.read-secret' => [['id' => $this->last['id'], 'type' => 'vault.read-secret', 'payload' => ['data' => [
                    'vaultName' => $this->last['payload']['vaultName'], 'secretKey' => $this->last['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => $this->session]]]],
                default => [['id' => $this->last['id'], 'type' => $this->last['type'], 'payload' => ['data' => ['deleted' => true]]]],
            };
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->vault);
    $this->host = heldNode('host', '10.44.1.1');
    $this->host->forceFill(['instance_bridge' => '10.253.1.0/24'])->saveQuietly();
    $this->phone = heldNode('phone', '10.44.0.77');
    NodeAccess::query()->create(['consumer_node_id' => $this->phone->id, 'serving_node_id' => $this->host->id]);
    $this->project = Project::query()->create(['name' => 'Tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://gitlab.com/example/tool.git',
        'default_branch' => 'main', 'root' => '.', 'source_access' => ProjectSourceAccess::GitHubApp]);
    $this->environment = heldServer($this->project, $this->host, 'fix-login');
});

function heldNode(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
}

function heldServer(Project $project, Node $node, string $name, string $power = 'running'): ConnEnvironment
{
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/srv/'.$name, 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => $power, 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.1.'.($instance->id % 250 + 2), 't3_port' => 3800 + $instance->id]);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-'.$name, 'label' => 'tool / '.$name, 'url' => 'http://'.$node->wireguard_ip.':'.(3800 + $instance->id),
        'node_id' => $node->id, 'admin_session' => 'private-admin-'.$name, 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $environment->forceFill(['instance_id' => $instance->id])->save();

    return $environment;
}

function readHeld(Node $caller, ConnEnvironment $environment): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson("/api/v1/conn/environments/{$environment->environment_id}/session");
}

/** @return array<string, mixed> */
function heldReceipt(Operation $operation): array
{
    $pairing = $operation->target_id;

    return ['backend' => 'held', 'pairing_id' => $pairing, 'pairing_link_id' => 'link-'.$pairing, 'link_expires_at' => now()->addMinutes(5)->toJSON(),
        'session_expires_at' => now()->addDays(30)->toJSON(),
        'session_ref' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-conn-session-'.HeldModel.'-hold-held-pairing-'.$pairing.'-access_token'],
        'link_ref' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-conn-session-'.HeldModel.'-link-link-pairing-'.$pairing.'-credential']];
}

function endHold(Operation $operation, string $status = 'succeeded', ?string $detail = null): Operation
{
    $operation->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => HeldRun]])->save();

    return app(ConnSessionHoldProjection::class)->apply($operation->fresh(), ['run_id' => HeldRun, 'status' => $status,
        'started_at' => now()->subSeconds(2)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['link_key' => heldReceipt($operation)['link_ref']['key'], ...$status === 'succeeded' ? ['held_receipt' => json_encode(heldReceipt($operation), JSON_THROW_ON_ERROR)] : []],
        'failure' => ['detail' => $detail]]);
}

function holdOperation(Node $node, ConnEnvironment $environment): Operation
{
    return Operation::query()->where('kind', 'conn.session_hold')->where('input->caller_node_id', $node->id)
        ->where('input->conn_environment_id', $environment->id)->latest('id')->firstOrFail();
}

/** Ends a revoke as T3 answers it: every selected session revoked. */
function endRevoke(Operation $operation, string $status = 'succeeded', ?string $detail = null): Operation
{
    $operation->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => HeldRun]])->save();

    return app(ConnRevocationProjection::class)->apply($operation->fresh(), ['run_id' => HeldRun, 'status' => $status,
        'started_at' => now()->subSeconds(2)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['session_receipt' => json_encode($status === 'succeeded' ? ['valid' => true, 'revoked' => 1] : ['error_code' => $detail], JSON_THROW_ON_ERROR),
            'link_receipt' => '{"valid":true,"completed":[0],"revoked":1}'],
        'failure' => ['detail' => $detail]]);
}

function revokes(): array
{
    return Operation::query()->where('kind', 'conn.pairing_revoke')->orderBy('id')->get()->all();
}

/**
 * Serve refuses a run whose input breaks its workflow's schema, so the Gateway's input must fit it: required keys present, closed
 * objects without extra keys, list items alike.
 *
 * @param  array<string, mixed>  $schema
 */
function assertFitsWorkflowSchema(mixed $value, array $schema, string $path = 'inputs'): void
{
    if (($schema['type'] ?? null) === 'object') {
        expect($value)->toBeArray("{$path} is an object");
        foreach ($schema['required'] ?? [] as $key) {
            expect(array_key_exists($key, $value))->toBeTrue("{$path}.{$key} is required");
        }
        foreach ($value as $key => $item) {
            $child = $schema['properties'][$key] ?? null;
            expect($child !== null || ($schema['additionalProperties'] ?? true) !== false)->toBeTrue("{$path}.{$key} is not in the workflow schema");
            if (is_array($child)) {
                assertFitsWorkflowSchema($item, $child, "{$path}.{$key}");
            }
        }
    }
    if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
        foreach ($value as $index => $item) {
            assertFitsWorkflowSchema($item, $schema['items'], "{$path}[{$index}]");
        }
    }
}

/** @return array<string, mixed> */
function workflowInputs(string $workflow): array
{
    return Yaml::parseFile(base_path("../../swamp/workflows/workflow-operation-{$workflow}.yaml"))['inputs'];
}

describe('the session handle', function (): void {
    it('makes the held session with one Operation, then answers it with no Operation', function (): void {
        $first = readHeld($this->phone, $this->environment)->assertStatus(202)
            ->assertJsonPath('data.kind', 'conn.session_hold')->assertJsonPath('data.target_type', 'conn_pairing');
        // A concurrent first read finds the open Operation.
        readHeld($this->phone, $this->environment)->assertStatus(202)->assertJsonPath('data.id', $first->json('data.id'));
        expect(Operation::query()->where('kind', 'conn.session_hold')->count())->toBe(1);

        $operation = holdOperation($this->phone, $this->environment);
        assertFitsWorkflowSchema(['operation_id' => $operation->id, 'node_id' => 1, 'kind' => $operation->kind, ...Arr::only($operation->input, ['intent', 'execution_secrets'])], workflowInputs('conn-session-hold'));
        // Neither the admin session nor any later secret is in the Operation's input.
        expect(json_encode($operation->input))->not->toContain('private-admin')
            ->and($operation->input['execution_secrets']['admin_session']['key'])->toStartWith('conn-session-hold-');
        $ended = endHold($operation);
        expect($ended->status)->toBe(OperationStatus::Succeeded)
            ->and(json_encode($ended->result))->not->toContain('held-session-sentinel')
            ->and(collect($this->vault->sent)->where('type', 'vault.delete')->pluck('payload.key')->all())->toHaveCount(3)
            ->toContain($operation->input['execution_secrets']['admin_session']['key']);

        $read = readHeld($this->phone, $this->environment)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.environment_id', 'env-fix-login')->assertJsonPath('data.url', $this->environment->url)
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.access_token', 'held-session-sentinel')
            ->assertJsonPath('data.pairing_id', $operation->target_id);
        readHeld($this->phone, $this->environment)->assertOk()->assertJsonPath('data.access_token', 'held-session-sentinel');
        expect(Operation::query()->where('kind', 'conn.session_hold')->count())->toBe(1)
            ->and($read->json('data.expires_at'))->toBe(now()->addDays(30)->toIso8601String());
        // The session is stored encrypted, never in plain text.
        $raw = DB::table('conn_pairings')->where('id', $operation->target_id)->value('session');
        expect($raw)->not->toBeNull()->not->toContain('held-session-sentinel');
    });

    it('answers only its own Node; a Node without node access gets 403 and starts nothing', function (): void {
        $other = heldNode('laptop', '10.44.0.78');
        NodeAccess::query()->create(['consumer_node_id' => $other->id, 'serving_node_id' => $this->host->id]);
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        readHeld($other, $this->environment)->assertStatus(202);
        expect(holdOperation($other, $this->environment)->input['caller_node_id'])->toBe($other->id);

        $stranger = heldNode('stranger', '10.44.0.79');
        NodeAccess::query()->create(['consumer_node_id' => $stranger->id, 'serving_node_id' => $stranger->id]);
        readHeld($stranger, $this->environment)->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
        expect(Operation::query()->where('kind', 'conn.session_hold')->where('input->caller_node_id', $stranger->id)->exists())->toBeFalse();
    });

    it('refuses a host server and an asleep server without a held session, and answers a held one while asleep', function (): void {
        $hostServer = ConnEnvironment::query()->create(['environment_id' => 'env-host', 'label' => 'host', 'url' => 'http://10.44.1.1:3773',
            'node_id' => $this->host->id, 'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
        readHeld($this->phone, $hostServer)->assertStatus(409)->assertJsonPath('error.code', 'conn.environment_not_instance');

        $asleep = heldServer($this->project, $this->host, 'asleep', 'stopped');
        readHeld($this->phone, $asleep)->assertStatus(409)->assertJsonPath('error.code', 'conn.environment_asleep');

        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        InstanceRuntime::query()->where('instance_id', $this->environment->instance_id)->update(['power_state' => 'stopped']);
        readHeld($this->phone, $this->environment)->assertOk();
        expect(Operation::query()->where('kind', 'conn.session_hold')->count())->toBe(1);
    });

    it('keeps the outcome of a refused hold final and revokes one of an unknown outcome by its label', function (): void {
        readHeld($this->phone, $this->environment)->assertStatus(202);
        $refused = endHold(holdOperation($this->phone, $this->environment), 'failed', 'conn.redeem_refused');
        expect($refused->error_code)->toBe('conn.redeem_refused')
            ->and(ConnPairing::query()->findOrFail($refused->target_id))->withdrawn_at->toBeNull()->revoked_at->not->toBeNull()
            ->and(revokes())->toBe([])
            // The minted link's key goes on a refused path too, with the admin copy.
            ->and(collect($this->vault->sent)->where('type', 'vault.delete')->pluck('payload.key')->all())
            ->toContain(heldReceipt($refused)['link_ref']['key'], $refused->input['execution_secrets']['admin_session']['key']);

        readHeld($this->phone, $this->environment)->assertStatus(202);
        $unknown = endHold(holdOperation($this->phone, $this->environment), 'failed', 'conn.pairing_outcome_unknown');
        expect($unknown->error_code)->toBe('conn.session_outcome_unknown')
            ->and(ConnPairing::query()->findOrFail($unknown->target_id)->withdrawn_at)->not->toBeNull();
        $revoke = revokes()[0];
        assertFitsWorkflowSchema(['operation_id' => $revoke->id, 'node_id' => 1, 'kind' => $revoke->kind, ...Arr::only($revoke->input, ['intent', 'execution_secrets'])], workflowInputs('conn-pairing-revoke'));
        expect($revoke->input['pairing_ids'])->toBe([$unknown->target_id])
            ->and($revoke->input['intent']['pairings'][0])->toMatchArray(['id' => $unknown->target_id, 'held' => true]);
        // Nothing of it was on the server: the unmatched revoke settles it.
        endRevoke($revoke, 'failed', 'conn.revocation_unmatched');
        expect(ConnPairing::query()->findOrFail($unknown->target_id))->withdrawn_at->toBeNull()->revoked_at->not->toBeNull();
    });
});

describe('the session handle refusals and polling', function (): void {
    it('refuses a hold when the Gateway admin session ended or the Instance is being removed', function (): void {
        $this->environment->forceFill(['admin_session_expires_at' => now()->subMinute()])->save();
        readHeld($this->phone, $this->environment)->assertStatus(409)->assertJsonPath('error.code', 'conn.session_expired');

        $this->environment->forceFill(['admin_session_expires_at' => now()->addDays(20)])->save();
        // The removal's own records are not the subject; the refusal reads only the status.
        DB::unprepared('DROP TRIGGER instances_removal_status_update');
        $instance = Instance::query()->findOrFail($this->environment->instance_id);
        $instance->update(['status' => InstanceState::Removing]);
        readHeld($this->phone, $this->environment)->assertStatus(409)->assertJsonPath('error.code', 'instance.removal_conflict');
        expect(Operation::query()->where('kind', 'conn.session_hold')->exists())->toBeFalse();
    });

    it('lets its Node follow the hold on the Conn Operation read, and no other Node', function (): void {
        $id = readHeld($this->phone, $this->environment)->assertStatus(202)->json('data.id');
        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])->getJson("/api/v1/conn/operations/{$id}")
            ->assertOk()->assertJsonPath('data.kind', 'conn.session_hold')->assertJsonPath('data.id', $id);

        $other = heldNode('laptop', '10.44.0.78');
        NodeAccess::query()->create(['consumer_node_id' => $other->id, 'serving_node_id' => $this->host->id]);
        test()->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])->getJson("/api/v1/conn/operations/{$id}")->assertNotFound();
    });
});

describe('installed clients', function (): void {
    it('keeps held pairings out of the default pairing list; include_held adds them', function (): void {
        ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit device00',
            'pairing_link_id' => 'device-link', 'expires_at' => now()->addMinutes(5)]);
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $list = fn (string $query = '') => test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->getJson("/api/v1/conn/environments/env-fix-login/pairings{$query}");

        $default = $list()->assertOk()->json('data');
        expect($default)->toHaveCount(1)->and($default[0]['client_label'])->toBe('phone via Orbit device00')->and($default[0])->not->toHaveKey('held');
        expect(collect($list('?include_held=1')->assertOk()->json('data'))->pluck('held')->all())->toBe([true, false]);
        $list('?include_held=yes')->assertStatus(422);
    });

    it('lets a device renew its own pairing when only a held pairing of its Node was revoked', function (): void {
        ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit device00',
            'pairing_link_id' => 'device-link', 'expires_at' => now()->subDays(25)]);
        ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit held0000',
            'held' => true, 'pairing_link_id' => 'held-link', 'expires_at' => now()->subDay(), 'revoked_at' => now()]);

        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/conn/environments/env-fix-login/pairings', ['renew' => true])->assertAccepted()->assertJsonPath('data.kind', 'conn.pairing_create');
    });
});

describe('renewal', function (): void {
    it('renews a held session within seven days of its end with no device online, then revokes the old one', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $old = ConnPairing::query()->where('held', true)->sole();
        expect(app(ConnHeldSessionSweep::class)->run())->toBe([]);

        $this->travel(24)->days();
        $this->environment->forceFill(['admin_session_expires_at' => now()->addDays(20)])->save();
        $admitted = app(ConnHeldSessionSweep::class)->run();
        $renewal = holdOperation($this->phone, $this->environment);
        expect($admitted)->toBe([$renewal->id])->and($renewal->input['renews_pairing_id'])->toBe($old->id)
            ->and($renewal->triggered_by)->toBe('schedule');
        // The old session serves until the new one is recorded.
        readHeld($this->phone, $this->environment)->assertOk()->assertJsonPath('data.pairing_id', $old->id);

        $this->vault->session = 'renewed-held-sentinel';
        endHold($renewal);
        readHeld($this->phone, $this->environment)->assertOk()->assertJsonPath('data.access_token', 'renewed-held-sentinel')
            ->assertJsonPath('data.pairing_id', $renewal->target_id);
        expect($old->fresh())->session->toBeNull()->withdrawn_at->not->toBeNull();
        $revoke = revokes()[0];
        expect($revoke->input['pairing_ids'])->toBe([$old->id])
            ->and($revoke->input['intent']['pairings'][0])->toMatchArray(['label' => $old->client_label, 'held' => true]);

        endRevoke($revoke);
        expect($old->fresh())->withdrawn_at->toBeNull()->revoked_at->not->toBeNull();
    });

    it('skips a sleeping Instance and renews its held sessions at its wake', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        InstanceRuntime::query()->where('instance_id', $this->environment->instance_id)->update(['power_state' => 'stopped']);
        $this->travel(29)->days();
        $this->environment->forceFill(['admin_session_expires_at' => now()->addDays(20)])->save();
        expect(app(ConnHeldSessionSweep::class)->run())->toBe([]);

        InstanceRuntime::query()->where('instance_id', $this->environment->instance_id)->update(['power_state' => 'running']);
        app(ConnInstanceLifecycle::class)->projectWake(Instance::query()->findOrFail($this->environment->instance_id));
        expect(holdOperation($this->phone, $this->environment)->input['renews_pairing_id'])->not->toBeNull();
    });

    it('runs the held sweep in the hourly renewal tick', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $old = ConnPairing::query()->where('held', true)->sole();
        $this->travel(24)->days();
        $this->environment->forceFill(['admin_session_expires_at' => now()->addDays(20)])->save();

        $this->artisan('conn:environments:renew')->assertSuccessful();

        expect(holdOperation($this->phone, $this->environment)->input['renews_pairing_id'])->toBe($old->id);
    });

    it('withdraws and revokes a held session whose Node lost its access another way', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $held = ConnPairing::query()->where('held', true)->sole();
        NodeAccess::query()->where('consumer_node_id', $this->phone->id)->delete();

        app(ConnHeldSessionSweep::class)->run();

        expect($held->fresh())->session->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes()[0]->input['pairing_ids'])->toBe([$held->id]);
    });

    it('admits an owed revoke again while the server could not take it', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $pairing = ConnPairing::query()->where('held', true)->sole();
        $pairing->forceFill(['session' => null, 'withdrawn_at' => now()])->save();

        $admitted = app(ConnHeldSessionSweep::class)->run();
        expect($admitted)->toHaveCount(1)->and(revokes()[0]->input['pairing_ids'])->toBe([$pairing->id]);
    });
});

describe('revoke and removal', function (): void {
    it('revokes a held pairing by its id, also when a device pairing of the Node was issued within the same five minutes', function (): void {
        ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit device00',
            'pairing_link_id' => 'device-link', 'issue_started_at' => now(), 'expires_at' => now()->addMinutes(5)]);
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $held = ConnPairing::query()->where('held', true)->sole();

        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/conn/environments/env-fix-login/pairings/revoke', ['pairing_id' => $held->id], ['Idempotency-Key' => 'revoke-held'])
            ->assertAccepted()->assertJsonPath('data.kind', 'conn.pairing_revoke');
        endRevoke(revokes()[0]);
        expect($held->fresh()->revoked_at)->not->toBeNull();
        // The Node keeps its grant, so its next read makes a new held session.
        readHeld($this->phone, $this->environment)->assertStatus(202);
    });

    it('withdraws a session whose revoke by id ended before its first hold, and revokes it again by its id (issue 3540)', function (): void {
        readHeld($this->phone, $this->environment)->assertStatus(202);
        $hold = holdOperation($this->phone, $this->environment);
        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/conn/environments/env-fix-login/pairings/revoke', ['pairing_id' => $hold->target_id], ['Idempotency-Key' => 'revoke-new'])->assertAccepted();
        $revoke = revokes()[0];
        expect($revoke->input['pairing_ids'])->toBe([$hold->target_id])
            ->and($revoke->input['holding_pairing_ids'])->toBe([$hold->target_id])
            ->and($revoke->input['intent']['pairings'][0])->toMatchArray(['id' => $hold->target_id, 'held' => true]);

        // The revoke read the server before the session existed and ends first; its unmatched answer proves nothing yet.
        endRevoke($revoke, 'failed', 'conn.revocation_unmatched');
        expect(ConnPairing::query()->findOrFail($hold->target_id))->revoked_at->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes())->toHaveCount(1);

        endHold($hold);
        $pairing = ConnPairing::query()->findOrFail($hold->target_id);
        expect($pairing->session)->toBeNull()->and($pairing->withdrawn_at)->not->toBeNull();
        $again = collect(revokes())->last();
        expect(revokes())->toHaveCount(2)->and($again->input['pairing_ids'])->toBe([$hold->target_id])->and($again->input['holding_pairing_ids'])->toBe([]);
        readHeld($this->phone, $this->environment)->assertStatus(202);
        expect(holdOperation($this->phone, $this->environment)->target_id)->not->toBe($hold->target_id);

        // That revoke ran after the hold: its unmatched answer now settles the pairing.
        endRevoke($again, 'failed', 'conn.revocation_unmatched');
        expect(ConnPairing::query()->findOrFail($hold->target_id))->withdrawn_at->toBeNull()->revoked_at->not->toBeNull();
    });

    it('withdraws a session whose first hold ended before its revoke by id, and revokes it again once that revoke ends', function (): void {
        readHeld($this->phone, $this->environment)->assertStatus(202);
        $hold = holdOperation($this->phone, $this->environment);
        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/conn/environments/env-fix-login/pairings/revoke', ['pairing_id' => $hold->target_id], ['Idempotency-Key' => 'revoke-new'])->assertAccepted();
        $revoke = revokes()[0];

        // The hold ends while the revoke runs: the handle never serves the session, and its own revoke waits for the server.
        endHold($hold);
        readHeld($this->phone, $this->environment)->assertStatus(202);
        expect(ConnPairing::query()->findOrFail($hold->target_id))->session->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes())->toHaveCount(1);

        endRevoke($revoke, 'failed', 'conn.revocation_unmatched');
        expect(ConnPairing::query()->findOrFail($hold->target_id))->revoked_at->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes())->toHaveCount(2)->and(revokes()[1]->input['pairing_ids'])->toBe([$hold->target_id]);
    });

    it('settles a pairing a revoke withdrew during its hold when T3 refused the hold', function (): void {
        readHeld($this->phone, $this->environment)->assertStatus(202);
        $hold = holdOperation($this->phone, $this->environment);
        app(RevokeConnPairingsAction::class)->execute($this->phone, $this->environment, $this->phone, $hold->target_id);
        endRevoke(revokes()[0], 'failed', 'conn.revocation_unmatched');

        endHold($hold, 'failed', 'conn.redeem_refused');
        expect(ConnPairing::query()->findOrFail($hold->target_id))->withdrawn_at->toBeNull()->revoked_at->not->toBeNull()
            ->and(app(ConnHeldSessions::class)->revokeNext($this->environment->id))->toBeNull();
    });

    it('withdraws the session of a hold whose pairing was revoked while it ran', function (): void {
        readHeld($this->phone, $this->environment)->assertStatus(202);
        $hold = holdOperation($this->phone, $this->environment);
        ConnPairing::query()->whereKey($hold->target_id)->update(['revoked_at' => now()]);

        endHold($hold);
        readHeld($this->phone, $this->environment)->assertStatus(202);
        expect(ConnPairing::query()->findOrFail($hold->target_id))->session->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes()[0]->input['pairing_ids'])->toBe([$hold->target_id]);
    });

    it('keeps revoke-all to the device pairings of the Node, also beside a withdrawn held pairing', function (): void {
        $device = ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit device00',
            'pairing_link_id' => 'device-link', 'issue_started_at' => now()->subDay(), 'expires_at' => now()->subDay()->addMinutes(5)]);
        $held = ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id, 'client_label' => 'phone via Orbit held0000',
            'held' => true, 'withdrawn_at' => now()]);

        test()->withServerVariables(['REMOTE_ADDR' => $this->phone->wireguard_ip])
            ->postJson('/api/v1/conn/environments/env-fix-login/pairings/revoke', [], ['Idempotency-Key' => 'revoke-all'])->assertAccepted();

        $revoke = revokes()[0];
        expect($revoke->input['pairing_ids'])->toBe([$device->id])
            ->and(collect($revoke->input['intent']['pairings'])->pluck('id')->all())->toBe([$device->id]);
        endRevoke($revoke);
        expect($device->fresh()->revoked_at)->not->toBeNull()->and($held->fresh()->withdrawn_at)->not->toBeNull();
    });

    it('settles a held session that an unmatched revoke by its id found gone, so the handle stops answering it', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $held = ConnPairing::query()->where('held', true)->sole();
        $revoke = app(RevokeConnPairingsAction::class)->execute($this->phone, $this->environment, $this->phone, $held->id);

        endRevoke($revoke, 'failed', 'conn.revocation_unmatched');

        expect($held->fresh())->revoked_at->not->toBeNull()->session->toBeNull();
        readHeld($this->phone, $this->environment)->assertStatus(202);
    });

    it('starts the next owed revoke of a server when one ends, and settles a withdrawn session past its end', function (): void {
        $make = fn (string $label, array $attributes = []) => ConnPairing::query()->create(['node_id' => $this->phone->id, 'conn_environment_id' => $this->environment->id,
            'client_label' => $label, 'held' => true, 'pairing_link_id' => 'link-'.$label, 'issue_started_at' => now(), 'expires_at' => now()->addMinutes(5),
            'session_expires_at' => now()->addDays(20), 'withdrawn_at' => now(), ...$attributes]);
        $first = $make('phone via Orbit first000');
        $second = $make('phone via Orbit second00');
        $ended = $make('phone via Orbit ended000', ['session_expires_at' => now()->subMinute()]);

        app(ConnHeldSessionSweep::class)->run();
        expect(revokes())->toHaveCount(1)->and(revokes()[0]->input['pairing_ids'])->toBe([$first->id])
            ->and($ended->fresh())->withdrawn_at->toBeNull()->revoked_at->not->toBeNull();

        endRevoke(revokes()[0]);
        expect(revokes())->toHaveCount(2)->and(revokes()[1]->input['pairing_ids'])->toBe([$second->id]);
    });

    it('withdraws and revokes the held sessions of a Node whose grant is removed', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));
        $held = ConnPairing::query()->where('held', true)->sole();

        app(RemoveNodeAccessAction::class)->execute($this->phone, $this->host, $this->phone);

        expect($held->fresh())->session->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(revokes()[0]->input['pairing_ids'])->toBe([$held->id]);
        readHeld($this->phone, $this->environment)->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    });

    it('contributes the grant-removal hook', function (): void {
        expect(collect(app()->tagged(NodeAccessRemovalHook::class))->map(fn (object $hook): string => $hook::class)->all())
            ->toContain(ConnNodeAccessRemoval::class);
    });

    it('keeps the held sessions of a deleted Node until their revoke by pairing id ran, also one whose hold still runs', function (): void {
        $laptop = heldNode('laptop', '10.44.0.78');
        NodeAccess::query()->create(['consumer_node_id' => $laptop->id, 'serving_node_id' => $this->host->id]);
        $second = heldServer($this->project, $this->host, 'second');
        endHold(app(AdmitConnSessionHoldAction::class)->hold($laptop, $this->environment));
        $live = ConnPairing::query()->where('held', true)->sole();
        $running = app(AdmitConnSessionHoldAction::class)->hold($laptop, $second);
        $gateway = SwampNodeFixture::ensure();

        app(NodeRemovalRecords::class)->remove(['node_id' => $laptop->id, 'name' => 'laptop', 'task_vm_id' => null, 'route_ids' => []], [], ['routes' => []]);

        expect(Node::query()->find($laptop->id))->toBeNull()
            ->and($live->fresh())->node_id->toBe($gateway->id)->session->toBeNull()->withdrawn_at->not->toBeNull()
            ->and(ConnPairing::query()->findOrFail($running->target_id))->node_id->toBe($gateway->id)->withdrawn_at->not->toBeNull();
        // One revoke per server: the live session's now; the running hold's at its end.
        expect(collect(revokes())->map(fn (Operation $revoke): array => $revoke->input['pairing_ids'])->all())->toBe([[$live->id]]);
        endHold($running);
        expect(collect(revokes())->map(fn (Operation $revoke): array => $revoke->input['pairing_ids'])->all())->toBe([[$live->id], [$running->target_id]])
            ->and(ConnPairing::query()->findOrFail($running->target_id)->session)->toBeNull();

        endRevoke(revokes()[0]);
        expect($live->fresh())->withdrawn_at->toBeNull()->revoked_at->not->toBeNull();
    });

    it('contributes the Node removal hook', function (): void {
        expect(collect(app()->tagged(NodeRemovalHook::class))->map(fn (object $hook): string => $hook::class)->all())
            ->toContain(ConnNodeRemoval::class);
    });

    it('removes the held sessions with their Instance', function (): void {
        endHold(app(AdmitConnSessionHoldAction::class)->hold($this->phone, $this->environment));

        DB::transaction(fn () => app(ConnInstanceLifecycle::class)->projectDestroy(Instance::query()->findOrFail($this->environment->instance_id)));

        expect(ConnPairing::query()->where('held', true)->exists())->toBeFalse();
    });
});

it('pre-mints held sessions for the creating profile\'s Nodes that reach a new clone\'s server while the switch is on, and none while it is off', function (bool $on): void {
    config(['orbit.conn.held_session_premint' => $on]);
    $desk = heldNode('desk', '10.44.0.80');
    $away = heldNode('away', '10.44.0.81');
    NodeAccess::query()->create(['consumer_node_id' => $desk->id, 'serving_node_id' => $this->host->id]);
    $profile = ConnProfile::query()->create(['name' => 'conn-lane-test', 'settings' => ['workspaces' => []], 'settings_version' => 1]);
    foreach ([$this->phone, $desk, $away] as $node) {
        ConnProfileBinding::query()->create(['node_id' => $node->id, 'conn_profile_id' => $profile->id]);
    }
    $instance = Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $this->host->id, 'name' => 'new-clone', 'checkout_path' => '/srv/new-clone',
        'status' => InstanceState::SourceResolved]);
    Operation::query()->create(['kind' => 'instance.create', 'target_type' => 'instance', 'target_id' => $instance->id, 'node_id' => $this->host->id,
        'status' => OperationStatus::Running, 'triggered_by' => 'node:'.$this->phone->id, 'input' => []]);

    DB::transaction(fn () => app(ConnInstanceLifecycle::class)->projectCreate($instance, ['environment_id' => 'env-new-clone', 'label' => 'tool / new-clone',
        'url' => 'http://10.44.1.1:3999', 'server_version' => '0.1.7', 'admin_session' => 'private-admin-new', 'expires_at' => now()->addDays(30)->toJSON()]));

    $environment = ConnEnvironment::query()->where('environment_id', 'env-new-clone')->sole();
    expect(Operation::query()->where('kind', 'conn.session_hold')->where('input->conn_environment_id', $environment->id)->pluck('input')->pluck('caller_node_id')->sort()->values()->all())
        ->toBe($on ? [$this->phone->id, $desk->id] : []);
})->with(['on' => true, 'off' => false]);
