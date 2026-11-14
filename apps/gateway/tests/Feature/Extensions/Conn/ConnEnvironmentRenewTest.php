<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Actions\AdmitConnRenewalAction;
use Orbit\Extensions\Conn\Actions\IssueConnPairingAction;
use Orbit\Extensions\Conn\Data\ConnEnvironmentData;
use Orbit\Extensions\Conn\Domain\ConnRenewalProjection;
use Orbit\Extensions\Conn\Domain\ConnRenewalSweep;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\SwampNodeFixture;

use function Pest\Laravel\freezeTime;

uses(LazilyRefreshDatabase::class);

const RenewRun = '0f6b6c1e-6c1a-4e1f-9d0e-2a7b0c4d5e61';
const RenewKey = 'nckrtl-instance-t3-00000000-0000-4000-8000-000000000001-renew-session-gateway-%d-token';

beforeEach(function (): void {
    Queue::fake();
    freezeTime();
    app(ExtensionStore::class)->set('conn', true);
    SwampNodeFixture::ensure()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/fixture-ca', 'swamp.run_token' => 'fixture-token']);
    // Swamp's vault: puts succeed, the renewed session reads back, deletes are recorded.
    $this->vault = new class implements WebSocketClient
    {
        public array $sent = [];

        public string $session = 'renewed-session-sentinel';

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
                default => [['id' => $this->last['id'], 'type' => 'error', 'error' => ['code' => 'not_found']]],
            };
        }

        public function close(): void {}

        public function isConnected(): bool
        {
            return true;
        }
    };
    app()->instance(WebSocketClient::class, $this->vault);
    $this->project = Project::query()->create(['name' => 'Tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://gitlab.com/example/tool.git',
        'default_branch' => 'main', 'root' => '.', 'source_access' => ProjectSourceAccess::GitHubApp]);
});

function renewNode(int $index): Node
{
    $ip = '10.44.1.'.$index;

    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'host-'.$index, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $node->forceFill(['instance_bridge' => '10.253.'.$index.'.0/24'])->saveQuietly();

    return $node;
}

/** A clone with its own container and T3 server, whose Gateway session ends after `$days`. */
function renewServer(Project $project, Node $node, string $name, float $days, bool $t3 = true): ConnEnvironment
{
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/srv/'.$name, 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['node_id' => $node->id, 'power_state' => 'running', 'runtime_version' => 'test', 'agent_version' => 'test', 'instance_id' => $instance->id,
        'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id, 'bridge_address' => preg_replace('~0/24\z~', '', (string) $node->instance_bridge).($instance->id % 250 + 2),
        't3_port' => $t3 ? 3800 + $instance->id : null]);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-'.$name, 'label' => 'tool / '.$name, 'url' => 'http://'.$node->wireguard_ip.':'.(3800 + $instance->id),
        'node_id' => $node->id, 'admin_session' => 'old-session-'.$name, 'admin_session_expires_at' => now()->addMinutes((int) round($days * 1440)), 'registered_at' => now()->subDays(23)]);
    $environment->forceFill(['instance_id' => $instance->id])->save();

    return $environment;
}

function renewOperation(ConnEnvironment $environment): Operation
{
    $operation = app(AdmitConnRenewalAction::class)->execute($environment);
    $operation->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => RenewRun]])->save();

    return $operation;
}

/** @param array<string,mixed> $report */
function renewEnd(Operation $operation, string $status, array $report): Operation
{
    return app(ConnRenewalProjection::class)->apply($operation, ['run_id' => RenewRun, 'status' => $status,
        'started_at' => now()->subSeconds(5)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'error_code' => $status === 'succeeded' ? null : ($report['error_code'] ?? 'operation.native_failed'), 'result' => $report]);
}

function renewReport(ConnEnvironment $environment, string $expires = '2027-01-12T10:00:00.000Z'): array
{
    return ['outcome' => 'succeeded', 'environment_id' => $environment->environment_id, 'server_version' => '0.1.7', 'expires_at' => $expires,
        'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => sprintf(RenewKey, $environment->instance_id)]];
}

it('renews a session in time: within the seven-day margin, never earlier', function (): void {
    $due = renewServer($this->project, renewNode(1), 'due', 6.9);
    renewServer($this->project, renewNode(2), 'early', 7.1);

    $admitted = app(ConnRenewalSweep::class)->run();

    expect(Operation::query()->whereKey($admitted)->pluck('target_id')->all())->toBe([$due->id]);
});

it('renews a session that ended while the container was stopped, with no old session in its intent', function (): void {
    $ended = renewServer($this->project, renewNode(1), 'woken', -12);

    $operation = Operation::query()->findOrFail(app(ConnRenewalSweep::class)->run()[0]);

    expect($operation->kind)->toBe('conn.environment_renew')
        ->and($operation->triggered_by)->toBe('schedule')
        ->and(json_encode($operation->input))->not->toContain('old-session-woken')
        ->and($operation->input['intent'])->toMatchArray(['instance_id' => $ended->instance_id, 'environment_id' => 'env-woken', 'url' => $ended->url,
            't3' => '/opt/orbit-agent/current/agent/bin/t3', 'container' => ['project' => 'orbit-instances', 'name' => 'instance-'.$ended->instance_id]]);
});

it('admits at most five per tick, the earliest end first and one per Node, when thirty fall due at once', function (): void {
    $nodes = array_map(renewNode(...), range(1, 10));
    foreach (range(0, 29) as $index) {
        renewServer($this->project, $nodes[$index % 10], 'c'.$index, 1 + $index / 100);
    }

    $first = Operation::query()->whereKey(app(ConnRenewalSweep::class)->run())->orderBy('id')->get();
    $names = $first->map(fn (Operation $operation): string => ConnEnvironment::query()->findOrFail($operation->target_id)->environment_id)->all();

    expect($names)->toBe(['env-c0', 'env-c1', 'env-c2', 'env-c3', 'env-c4'])
        ->and(app(ConnRenewalSweep::class)->run())->toHaveCount(5)
        ->and(Operation::query()->where('kind', 'conn.environment_renew')->count())->toBe(10);
    // Every Node now runs one renewal, so the third tick admits none until one ends.
    expect(app(ConnRenewalSweep::class)->run())->toBe([]);
    $first[0]->forceFill(['status' => OperationStatus::Failed])->save();
    expect(app(ConnRenewalSweep::class)->run())->toHaveCount(1);
});

it('records a renewal refusal on the server and keeps its session', function (string $code): void {
    $environment = renewServer($this->project, renewNode(1), 'refused', 2);

    $projected = renewEnd(renewOperation($environment), 'failed', ['outcome' => 'failed', 'error_code' => $code]);
    $environment->refresh();

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toBe(['error_code' => $code])
        ->and($environment->renewal_error_code)->toBe($code)
        ->and($environment->renewal_checked_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($environment->admin_session)->toBe('old-session-refused')
        ->and($environment->cleanup_version)->toBe(0);
})->with(['conn.environment_stopped', 'conn.server_unreachable', 'conn.session_rejected', 'conn.environment_mismatch', 'conn.node_unreachable']);

it('names a failure without a conn code conn.renewal_failed', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'unknown', 2);

    $projected = renewEnd(renewOperation($environment), 'failed', ['error_code' => 'operation.native_failed']);

    expect($projected->error_code)->toBe('conn.renewal_failed')
        ->and($environment->refresh()->renewal_error_code)->toBe('conn.renewal_failed');
});

it('tries again after a failure on the next tick, but a stopped container only after six hours', function (): void {
    $failed = renewServer($this->project, renewNode(1), 'failed', 2);
    $failed->forceFill(['renewal_error_code' => 'conn.server_unreachable', 'renewal_checked_at' => now()->subHour()])->save();
    $stopped = renewServer($this->project, renewNode(2), 'stopped', -3);
    $stopped->forceFill(['renewal_error_code' => 'conn.environment_stopped', 'renewal_checked_at' => now()->subHours(5)])->save();

    expect(Operation::query()->whereKey(app(ConnRenewalSweep::class)->run())->pluck('target_id')->all())->toBe([$failed->id]);

    $this->travel(61)->minutes();
    expect(Operation::query()->whereKey(app(ConnRenewalSweep::class)->run())->pluck('target_id')->all())->toBe([$stopped->id]);
});

it('stores the proven session, clears the last refusal, and only then admits the cleanup of older Gateway sessions', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'renewed', 3);
    $environment->forceFill(['renewal_error_code' => 'conn.server_unreachable', 'renewal_checked_at' => now()->subHour()])->save();
    $operation = renewOperation($environment);

    $projected = renewEnd($operation, 'succeeded', renewReport($environment));
    $environment->refresh();
    $cleanup = Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->sole();

    expect($projected->status)->toBe(OperationStatus::Succeeded)
        ->and($projected->result)->toBe(['environment_id' => 'env-renewed', 'admin_session_expires_at' => '2027-01-12T10:00:00+00:00'])
        ->and($environment->admin_session)->toBe('renewed-session-sentinel')
        ->and($environment->admin_session_expires_at->toIso8601String())->toBe('2027-01-12T10:00:00+00:00')
        ->and($environment->renewal_error_code)->toBeNull()
        ->and($environment->cleanup_version)->toBe(1)
        ->and($cleanup->input['cleanup_version'])->toBe(1)
        ->and($cleanup->input['intent']['labels'])->toBe(['Orbit Gateway'])
        ->and($cleanup->id)->toBeGreaterThan($projected->id);
    // The renewed session never enters an Operation input or result, and its vault copy is removed.
    foreach (Operation::query()->get() as $record) {
        expect(json_encode([$record->input, $record->result, $record->state]))->not->toContain('renewed-session-sentinel');
    }
    expect(collect($this->vault->sent)->where('type', 'vault.delete')->pluck('payload.key')->all())->toBe([sprintf(RenewKey, $environment->instance_id)]);
});

it('refuses a report that names another environment or another Instance key, and stores nothing', function (array $change): void {
    $environment = renewServer($this->project, renewNode(1), 'clone-a', 3);
    $operation = renewOperation($environment);

    expect(fn () => renewEnd($operation, 'succeeded', [...renewReport($environment), ...$change]))
        ->toThrow(ResourceOperationException::class, 'The renewal report does not match its intent.');
    expect($environment->refresh()->admin_session)->toBe('old-session-clone-a');
})->with([
    'another environment' => [['environment_id' => 'env-default']],
    'another Instance key' => [['gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => sprintf(RenewKey, 999)]]],
]);

it('renews each clone in its own container, never another Instance, and never a container that runs no T3', function (): void {
    $node = renewNode(1);
    $default = renewServer($this->project, $node, 'default', 1, t3: false);
    $first = renewServer($this->project, $node, 'first', 1);
    $second = renewServer($this->project, renewNode(2), 'second', 1);

    $intents = Operation::query()->whereKey(app(ConnRenewalSweep::class)->run())->get()
        ->mapWithKeys(fn (Operation $operation): array => [$operation->target_id => $operation->input['intent']]);

    expect($intents->keys()->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($intents[$first->id]['container']['name'])->toBe('instance-'.$first->instance_id)
        ->and($intents[$first->id]['environment_id'])->toBe('env-first')
        ->and($intents[$second->id]['container']['name'])->toBe('instance-'.$second->instance_id)
        ->and(fn () => app(AdmitConnRenewalAction::class)->execute($default))->toThrow(ResourceOperationException::class);
});

it('defers the cleanup while a pairing that carries the old session runs, and the next tick admits it', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'paired', 3);
    $pairing = Operation::query()->create(['kind' => 'conn.pairing_create', 'target_type' => 'conn_pairing', 'target_id' => 1, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'input' => ['conn_environment_id' => $environment->id]]);

    renewEnd(renewOperation($environment), 'succeeded', renewReport($environment));

    expect($environment->refresh()->admin_session)->toBe('renewed-session-sentinel')
        ->and(Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->exists())->toBeFalse()
        ->and(fn () => app(AdmitConnCleanupAction::class)->execute($environment, renewNode(9)))->toThrow(ResourceOperationException::class, 'still runs with the earlier session');

    $pairing->forceFill(['status' => OperationStatus::Succeeded])->save();
    app(ConnRenewalSweep::class)->run();

    expect(Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->sole()->input['cleanup_version'])->toBe(1);
});

it('defers the cleanup while a held session hold that carries the old session runs', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'held', 3);
    Operation::query()->create(['kind' => 'conn.session_hold', 'target_type' => 'conn_pairing', 'target_id' => 1, 'node_id' => SwampNodeFixture::Id,
        'status' => OperationStatus::Running, 'input' => ['conn_environment_id' => $environment->id]]);

    renewEnd(renewOperation($environment), 'succeeded', renewReport($environment));

    expect(Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->exists())->toBeFalse()
        ->and(fn () => app(AdmitConnCleanupAction::class)->execute($environment, renewNode(9)))->toThrow(ResourceOperationException::class, 'still runs with the earlier session');
});

it('renews the due held sessions of a server once its renewed Gateway session is recorded', function (): void {
    $host = renewNode(1);
    $environment = renewServer($this->project, $host, 'held', 3);
    $phone = renewNode(2);
    NodeAccess::query()->create(['consumer_node_id' => $phone->id, 'serving_node_id' => $host->id]);
    $due = ConnPairing::query()->create(['node_id' => $phone->id, 'conn_environment_id' => $environment->id, 'client_label' => 'host-2 via Orbit due00000', 'held' => true,
        'session' => 'held-session', 'session_expires_at' => now()->addDays(3), 'pairing_link_id' => 'link-due', 'expires_at' => now()->subDays(27)]);

    renewEnd(renewOperation($environment), 'succeeded', renewReport($environment));

    $hold = Operation::query()->where('kind', 'conn.session_hold')->sole();
    expect($hold->input['renews_pairing_id'])->toBe($due->id)->and($hold->input['caller_node_id'])->toBe($phone->id);
});

it('admits a renewal through the route at once, past the wait for a stopped container, and refuses a second while it runs', function (): void {
    $node = renewNode(1);
    $environment = renewServer($this->project, $node, 'asked', 20);
    $environment->forceFill(['renewal_error_code' => 'conn.environment_stopped', 'renewal_checked_at' => now()])->save();
    $phone = renewNode(7);
    $phone->accessibleNodes()->attach($node->id);
    $this->withServerVariables(['REMOTE_ADDR' => $phone->wireguard_ip]);
    $url = '/api/v1/conn/environments/env-asked/renew';

    $response = $this->postJson($url, [], ['Idempotency-Key' => 'renew-1'])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));

    expect($operation->kind)->toBe('conn.environment_renew')
        ->and($operation->callerNodeId())->toBe($phone->id);
    $this->postJson($url, [], ['Idempotency-Key' => 'renew-2'])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertOk()->assertJsonPath('data.kind', 'conn.environment_renew');
});

it('refuses the route without node access to the server, and for a host server', function (): void {
    $node = renewNode(1);
    renewServer($this->project, $node, 'private', 20);
    $stranger = renewNode(8);
    $this->withServerVariables(['REMOTE_ADDR' => $stranger->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/env-private/renew', [], ['Idempotency-Key' => 'renew-3'])->assertForbidden();

    ConnEnvironment::query()->create(['environment_id' => 'env-host', 'label' => 'host', 'url' => 'http://10.44.1.1:3773', 'node_id' => $node->id,
        'admin_session' => 'host-session', 'admin_session_expires_at' => now()->addDays(3), 'registered_at' => now()]);
    $stranger->accessibleNodes()->attach($node->id);
    $this->postJson('/api/v1/conn/environments/env-host/renew', [], ['Idempotency-Key' => 'renew-4'])
        ->assertConflict()->assertJsonPath('error.code', 'conn.environment_not_instance');
    expect(app(ConnRenewalSweep::class)->run())->toBe([]);
});

it('never lets a server that keeps failing hold its Node slot from a due server on the same Node', function (): void {
    $node = renewNode(1);
    $broken = renewServer($this->project, $node, 'broken', -5);
    $broken->forceFill(['renewal_error_code' => 'conn.server_unreachable', 'renewal_checked_at' => now()->subHour()])->save();
    $healthy = renewServer($this->project, $node, 'healthy', 2);

    expect(Operation::query()->whereKey(app(ConnRenewalSweep::class)->run())->pluck('target_id')->all())->toBe([$healthy->id]);
});

it('admits a pairing with the session the server row holds now, not the one its request read before a renewal', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'switch', -1);
    $stale = ConnEnvironment::query()->findOrFail($environment->id);
    ConnEnvironment::query()->whereKey($environment->id)->update(['admin_session' => encrypt('renewed-session-sentinel', false), 'admin_session_expires_at' => now()->addDays(30)]);
    $phone = renewNode(7);

    $operation = app(IssueConnPairingAction::class)->admit($phone, $stale);

    expect($operation->kind)->toBe('conn.pairing_create')
        ->and(collect($this->vault->sent)->where('type', 'vault.put')->pluck('payload.value')->all())->toBe(['renewed-session-sentinel']);
});

it('hands the native run its intent and an empty secrets map, never a credential', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'dispatch', 2);
    $operation = app(AdmitConnRenewalAction::class)->execute($environment);

    $inputs = app(DirectOperation::class)->workflowInputs($operation);

    expect($inputs['kind'])->toBe('conn.environment_renew')
        ->and($inputs['execution_secrets'])->toEqual((object) [])
        ->and(json_encode($inputs))->toContain('"execution_secrets":{}')
        ->and(json_encode($inputs))->not->toContain('old-session-dispatch')
        ->and($inputs['intent']['container']['name'])->toBe('instance-'.$environment->instance_id);
});

it('hands the cleanup run after a renewal its vault reference to the new session, never the session', function (): void {
    $environment = renewServer($this->project, renewNode(1), 'cleaned', 3);
    renewEnd(renewOperation($environment), 'succeeded', renewReport($environment));
    $cleanup = Operation::query()->where('kind', 'conn.gateway_sessions_revoke')->sole();

    $inputs = app(DirectOperation::class)->workflowInputs($cleanup);

    expect($inputs['kind'])->toBe('conn.gateway_sessions_revoke')
        ->and($inputs['execution_secrets']['admin_session']['vault'])->toBe('orbit-control-inputs')
        ->and($inputs['execution_secrets']['admin_session']['key'])->toStartWith('conn-pairing-revoke-')
        ->and(json_encode($inputs))->not->toContain('renewed-session-sentinel');
});

/** A wake's terminal update, as Swamp reports it, for an Instance whose container slept. */
function renewWake(ConnEnvironment $environment, string $status): Operation
{
    $operation = Operation::query()->create(['kind' => InstanceWake::Kind, 'target_type' => 'instance', 'target_id' => $environment->instance_id,
        'node_id' => $environment->node_id, 'status' => 'running', 'input' => [], 'state' => ['swamp_run_id' => 'wake-proof']]);

    return app(InstanceWake::class)->apply($operation, ['status' => $status, 'run_id' => 'wake-proof', 'started_at' => now()->toIso8601String(),
        'finished_at' => now()->toIso8601String(), 'result' => $status === 'succeeded' ? ['wake' => 'resumed'] : ['error_code' => 'instance.web_unready']]);
}

it('skips a sleeping Instance, whose wake renews its session at once, also one that ended while it slept, with no re-pair', function (): void {
    $asleep = renewServer($this->project, renewNode(1), 'asleep', -40);
    InstanceRuntime::query()->where('instance_id', $asleep->instance_id)->update(['power_state' => 'stopped']);

    expect(app(ConnRenewalSweep::class)->run())->toBe([])
        ->and($asleep->refresh()->renewal_error_code)->toBeNull();

    // A failed wake renews nothing; a woken one admits the renewal without waiting for the hourly sweep.
    renewWake($asleep, 'failed');
    expect(Operation::query()->where('kind', AdmitConnRenewalAction::Kind)->count())->toBe(0);
    renewWake($asleep, 'succeeded');
    $renewal = Operation::query()->where('kind', AdmitConnRenewalAction::Kind)->sole();
    expect($renewal->target_id)->toBe($asleep->id)
        ->and(json_encode($renewal->input))->not->toContain('old-session-asleep');

    // The run issued a new session without the ended one: the server mints pairings again, so a device renews its own pairing.
    $renewal->forceFill(['status' => OperationStatus::Running, 'state' => ['swamp_run_id' => RenewRun]])->save();
    renewEnd($renewal->refresh(), 'succeeded', renewReport($asleep));
    expect($asleep->refresh()->admin_session)->toBe('renewed-session-sentinel')
        ->and($asleep->admin_session_expires_at->isFuture())->toBeTrue()
        ->and(ConnEnvironmentData::fromModel($asleep->load('instance.runtime'))->status)->toBe('registered');
});

it('renews nothing at a wake whose session is not due, nor while Conn is off', function (): void {
    $fresh = renewServer($this->project, renewNode(1), 'fresh', 20);
    $due = renewServer($this->project, renewNode(2), 'due', 3);

    renewWake($fresh, 'succeeded');
    app(ExtensionStore::class)->set('conn', false);
    renewWake($due, 'succeeded');

    expect(Operation::query()->where('kind', AdmitConnRenewalAction::Kind)->count())->toBe(0);
});
