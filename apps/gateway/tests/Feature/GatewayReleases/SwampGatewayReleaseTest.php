<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdmitSwampGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\GatewayReleasePipeline;
use Tests\Support\WebArtifactFixture;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-host-key', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'public_ssh_host' => '192.0.2.209', 'wireguard_ip' => '10.44.0.15',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'swamp-host-key', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    // Production wiring of the recorder and actions over the release fakes; this fixture's layout is adopted.
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->pipeline->bindLeaves();
    $this->previous = $this->pipeline->fixture->currentId();
    app()->instance(GitHubArtifactWebBuild::class, new WebArtifactFixture($this->pipeline->fixture->layout)->build());
    app()->instance(GatewayReleaseAgent::class, new class implements GatewayReleaseAgent
    {
        public function fetch(string $revision, Node $gateway): array
        {
            return ['sha' => $revision, 'manifest' => '{"pin":"verified"}'];
        }

        public function install(array $delivery, GatewayReleaseLayout $layout, Node $gateway): void {}
    });
    GitHubTestSupport::storeApp();
    $this->sha = WebArtifactFixture::Sha;
    $listing = WebArtifactFixture::recorded('artifacts.json');
    // Artifact selection gets an Actions-only token; the run start gets one with contents and Actions read.
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/installation') => Http::response(['id' => 7]),
        str_ends_with($request->url(), '/access_tokens') => ($request->data()['permissions'] ?? []) === ['actions' => 'read']
            ? Http::response(['token' => 'actions-token', 'permissions' => ['actions' => 'read']], 201)
            : Http::response(['token' => 'release-token-sentinel', 'expires_at' => now()->addHour()->format('Y-m-d\TH:i:s\Z'),
                'permissions' => ['contents' => 'read', 'actions' => 'read', 'metadata' => 'read']], 201),
        str_contains($request->url(), '/installation/repositories') => Http::response(['total_count' => 1, 'repositories' => [['full_name' => 'hardimpactdev/orbit']]]),
        str_contains($request->url(), '/actions/artifacts?') => Http::response($listing),
        str_contains($request->url(), '/actions/runs/') => Http::response(WebArtifactFixture::recorded('run.json')),
        default => Http::response('unexpected '.$request->url(), 500),
    });
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $m): array => $m['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $m['id'], 'payload' => ['data' => ['vaultName' => $m['payload']['vaultName'], 'secretKey' => $m['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $m['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-gateway-release', 'runId' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f']]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'fake.token', 'orbit.gateway_releases.keep' => 3]);
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

/** Ends the admitted run, as the shared observer does with its terminal. */
function swamp_release_end(Operation $operation, string $status, array $extra = []): GatewayRelease
{
    $data = ['run_id' => 'b3c9b6a2-0c1d-4f7e-9a51-2a1f3c4d5e6f', 'status' => $status, 'started_at' => '2026-11-03T18:00:00.000Z', 'finished_at' => '2026-11-03T18:05:00.000Z', ...$extra];
    app(DirectOperation::class)->projection($operation)->apply($operation, $data);

    return GatewayRelease::query()->findOrFail($operation->target_id);
}

it('admits one run with the frozen intent and mints the read token only at run start', function (): void {
    $record = app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $operation = Operation::query()->sole();
    $start = collect($this->socket->sent)->firstWhere('type', 'workflow.run')['payload'] ?? $this->socket->sent[1]['payload'];

    expect($record->outcome)->toBe(GatewayRelease::Running)
        ->and($record->phases['run'])->toBe(['operation_id' => $operation->id])
        ->and($operation->only(['kind', 'target_type', 'target_id', 'node_id', 'lock_key']))->toBe([
            'kind' => 'gateway.release', 'target_type' => 'gateway_release', 'target_id' => $record->id, 'node_id' => 209, 'lock_key' => 'gateway-release'])
        ->and($operation->input)->toMatchArray(['sha' => $this->sha, 'id' => substr($this->sha, 0, 12), 'force' => false, 'previous' => $this->previous,
            'repository' => 'hardimpactdev/orbit', 'manifest' => '{"pin":"verified"}', 'horizon_unit' => '', 'keep' => 3,
            'web' => ['artifact_id' => 11494149862, 'size' => 453355, 'digest' => 'sha256:05aca30ba3c859643737e6c81f2e874abda8312772633b60e9c0a43a4c2a88ad']])
        ->and($operation->input['ssh']['target']['host'])->toBe('10.44.0.2')
        ->and($operation->input['swamp_ssh']['target']['host'])->toBe('10.44.0.15')
        ->and(json_encode($operation->input))->not->toContain('release-token-sentinel')
        ->and($this->socket->sent[0]['payload'])->toMatchArray(['vaultName' => 'orbit-control-inputs', 'key' => 'gateway-release-'.$operation->id, 'value' => 'release-token-sentinel'])
        ->and($start['workflowIdOrName'])->toBe('operation-gateway-release')
        ->and($start['inputs']['execution_secrets'])->toBe(['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'gateway-release-'.$operation->id]])
        ->and(json_encode($start))->not->toContain('release-token-sentinel');
});

it('ends the record when the run does not start, so the release unit can run again', function (): void {
    $this->socket->reply = fn (array $m): array => [['type' => 'error', 'id' => $m['id'], 'error' => ['code' => 'vault_write_denied', 'message' => 'denied']]];
    try {
        app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    } catch (Throwable) {
        // Admission reports the start failure; the record must not stay running.
    }
    $record = GatewayRelease::query()->sole();

    expect($record->outcome)->toBe('failed')
        ->and($record->retryable)->toBeTrue()
        ->and(app(GatewayReleaseRecorder::class)->swampRunActive())->toBeFalse();
});

it('refuses a short commit and a second release while one runs', function (): void {
    expect(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute(substr($this->sha, 0, 12)))
        ->toThrow(fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_commit_invalid'));
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);

    expect(fn () => app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha))
        ->toThrow(fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_in_progress'));
});

it('keeps the running record from settling and refuses every other release path while the run is active', function (): void {
    $record = app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);

    expect(app(GatewayReleaseRecorder::class)->settleDead())->toBe([])
        ->and($record->refresh()->outcome)->toBe(GatewayRelease::Running)
        ->and(fn () => app(DeployGatewayReleaseAction::class)->deploy($this->sha, false, 'deploy'))
        ->toThrow(fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_in_progress'))
        // Rollback and adoption begin their records through the same recorder.
        ->and(fn () => app(GatewayReleaseRecorder::class)->begin('rollback', $this->previous))
        ->toThrow(fn (GatewayReleaseException $e) => expect($e->errorCode)->toBe('gateway.release_in_progress'))
        ->and(GatewayRelease::query()->count())->toBe(1);
});

it('admits beside an automatic record that waits for its publication and supersedes it', function (): void {
    $waiting = GatewayRelease::query()->create(['requested' => str_repeat('b', 40), 'trigger' => 'auto', 'outcome' => GatewayRelease::Queued,
        'force' => false, 'duration_ms' => 0, 'phases' => ['publication' => ['outcome' => 'waiting', 'since' => now()->toISOString(), 'alerted' => false]]]);
    $record = app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);

    expect($record->outcome)->toBe(GatewayRelease::Running)
        ->and($waiting->refresh()->outcome)->not->toBe(GatewayRelease::Queued);
});

it('keeps automatic releases busy while the run is active', function (): void {
    app(GatewayReleaseAutomation::class)->enable();
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $tick = app(RunAutomaticGatewayReleaseAction::class)->execute();

    expect($tick['result'])->toBe('busy')
        ->and($tick['error_code'])->toBe('gateway.release_in_progress')
        ->and(GatewayRelease::query()->count())->toBe(1);
});

it('records a succeeded run as verified with the version the fleet rollout reads', function (): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $record = swamp_release_end(Operation::query()->sole(), 'succeeded',
        ['result' => ['sha' => $this->sha, 'previous' => $this->previous, 'snapshot' => '/home/orbit/.orbit/backups/pre-x.sqlite']]);

    expect($record->outcome)->toBe('verified')
        ->and($record->previous_release_id)->toBe($this->previous)
        ->and($record->snapshot_path)->toBe('/home/orbit/.orbit/backups/pre-x.sqlite')
        ->and($record->phases['verify'])->toBe(['outcome' => 'passed', 'status' => 'ok', 'version' => $this->sha])
        ->and(Operation::query()->sole()->status)->toBe(OperationStatus::Succeeded);
});

it('fails a run that stopped before the migration and pauses one that stopped after it', function (string $step, string $outcome, string $code): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $record = swamp_release_end(Operation::query()->sole(), 'failed', ['error_code' => 'operation.native_failed', 'failure' => ['failed_step' => $step]]);

    expect($record->outcome)->toBe($outcome)
        ->and($record->error_code)->toBe($code)
        ->and($record->migrations_ran)->toBe($outcome === 'paused');
})->with([
    'build' => ['Build the release', 'failed', 'gateway.release_prepare_failed'],
    'web build' => ['Install the web build', 'failed', 'gateway.release_web_install_failed'],
    'migration' => ['Migrate', 'paused', 'gateway.release_migrate_failed'],
    'handover' => ['Hand over the release', 'paused', 'gateway.release_handoff_failed'],
    'unknown step' => ['Something else', 'paused', 'gateway.release_run_failed'],
]);

it('names the refusal of the failed step', function (): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $record = swamp_release_end(Operation::query()->sole(), 'failed', ['error_code' => 'operation.native_failed',
        'failure' => ['failed_step' => 'Build the release', 'detail' => "gateway.release_downgrade: the commit is older than the current release\n"]]);

    expect($record->outcome)->toBe('failed')
        ->and($record->error_code)->toBe('gateway.release_downgrade');
});

it('names the snapshot from before the migration on a pause', function (): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $record = swamp_release_end(Operation::query()->sole(), 'failed', ['error_code' => 'operation.native_failed',
        'failure' => ['failed_step' => 'Migrate'], 'result' => ['snapshot' => "/home/orbit/.orbit/backups/pre-x.sqlite\n"]]);

    expect($record->outcome)->toBe('paused')
        ->and($record->snapshot_path)->toBe('/home/orbit/.orbit/backups/pre-x.sqlite');
});

it('pauses a cancelled or interrupted run, which may have migrated', function (string $status): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $record = swamp_release_end(Operation::query()->sole(), $status);

    expect($record->outcome)->toBe('paused')
        ->and($record->error_code)->toBe('gateway.release_run_failed')
        ->and($record->migrations_ran)->toBeTrue()
        ->and(app(GatewayReleaseRecorder::class)->swampRunActive())->toBeFalse();
})->with(['cancelled', 'interrupted']);

it('pauses the release when the run outlives its budget', function (): void {
    app(AdmitSwampGatewayReleaseAction::class)->execute($this->sha);
    $operation = Operation::query()->sole();
    $operation->forceFill(['started_at' => now()->subSeconds(3001)])->save();
    app(ExpireOperationAction::class)->execute($operation);
    $record = GatewayRelease::query()->sole();

    expect($operation->refresh()->error_code)->toBe('operation.timed_out')
        ->and($record->outcome)->toBe('paused')
        ->and($record->error_code)->toBe('gateway.release_run_timed_out')
        ->and($record->phases['run'])->toMatchArray(['operation_id' => $operation->id, 'status' => 'timed_out']);
});
