<?php

declare(strict_types=1);

use App\Domain\Logs\LogRedactor;
use App\Domain\Metrics\MetricsCredentialResetMaterial;
use App\Domain\Metrics\MetricsCredentialResetProjection;
use App\Domain\Metrics\StartMetricsCredentialResetAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\DirectOperationPrivateMaterial;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationSecrets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Setting;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'reset-metrics', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.17', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => '10.44.0.17']);
    $this->assignment = $this->node->roles()->create(['role' => 'metrics', 'status' => 'active']);
    $this->scope = new SettingScope(SettingScopeType::Node, $this->node->id);
    $this->settings = app(SettingRepository::class);
    $this->settings->put($this->scope, NativeMetricsCredentialManager::ActivePasswordKey, 'active-private-reset-sentinel', SettingValueProtection::Secret);
    $this->settings->put($this->scope, NativeMetricsCredentialManager::PendingPasswordKey, 'pending-private-reset-sentinel', SettingValueProtection::Secret);
    $this->material = app(MetricsCredentialResetMaterial::class);
    $revision = $this->material->prepare($this->node);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'metrics.credentials_reset', 'target_type' => 'metrics', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => OperationStatus::Queued, 'sequence' => 1, 'state' => ['swamp_run_id' => $this->run],
        'input' => ['assignment_id' => $this->assignment->id, 'reset_revision' => $revision, 'address' => $this->node->wireguard_ip,
            'credential' => ['$secret' => 'metrics.credentials_reset', 'id' => $this->node->id]]]);
    $direct = new DirectOperation;
    $this->direct = $direct->definition('metrics.credentials_reset') === null ? new DirectOperation([new class implements DirectOperationContribution
    {
        public function definitions(): array
        {
            return ['metrics.credentials_reset' => [
                'workflow' => 'operation-metrics-credentials-reset', 'projection' => MetricsCredentialResetProjection::class,
                'private_material' => ['credential' => 'metrics.credentials_reset'],
            ]];
        }
    }]) : $direct;
    app()->instance(DirectOperation::class, $this->direct);
    $sources = new OperationSecrets([$this->material], app(LogRedactor::class));
    app()->instance(OperationSecrets::class, $sources);
    $this->reader = app(DirectOperationPrivateMaterial::class);
    $this->projection = new MetricsCredentialResetProjection($this->direct, $this->material);
    $this->completion = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-31T06:00:00Z', 'finished_at' => '2026-10-31T06:00:01Z', 'error_code' => null, 'result' => ['reset' => true]];
});
it('keeps existing encrypted passwords behind the real shared private reader', function (): void {
    $before = $this->operation->fresh()->getAttributes();
    $material = $this->reader->read($this->operation);

    expect($material)->toBe(['credential' => ['active_password' => 'active-private-reset-sentinel', 'pending_password' => 'pending-private-reset-sentinel']]);
    expect($this->operation->fresh()->getAttributes())->toBe($before);
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray(), Setting::query()->get()->toArray()], JSON_THROW_ON_ERROR))
        ->not->toContain('active-private-reset-sentinel', 'pending-private-reset-sentinel');
    expect($this->projection->input($this->operation))->not->toHaveKey('credential');
});

it('refuses stale or foreign private credential reads without changing settings', function (string $change): void {
    $before = Setting::query()->get()->toArray();
    match ($change) {
        'owner' => $this->operation->update(['target_id' => $this->node->id + 1]),
        'unbound' => $this->operation->update(['state' => []]),
        'terminal' => $this->operation->update(['status' => OperationStatus::Failed]),
        'address' => $this->node->update(['wireguard_ip' => '10.44.0.18']),
        'assignment' => $this->assignment->delete(),
        'revision' => $this->settings->put($this->scope, MetricsCredentialResetMaterial::RevisionKey, 'another-revision'),
    };
    $before = $change === 'revision' ? Setting::query()->get()->toArray() : $before;

    expect(fn () => $this->reader->read($this->operation))->toThrow(ResourceOperationException::class);
    expect(Setting::query()->get()->toArray())->toBe($before);
})->with(['owner', 'unbound', 'terminal', 'address', 'assignment', 'revision']);

it('promotes once on native success without reconstructing runtime proof', function (): void {
    // Live role state can change after the effect; the admitted reset revision still owns promotion.
    $this->assignment->delete();
    $done = $this->projection->apply($this->operation, $this->completion);
    $sequence = $done->sequence;
    $settings = Setting::query()->get()->toArray();

    expect($done->status)->toBe(OperationStatus::Succeeded);
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe('pending-private-reset-sentinel');
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBeNull();
    expect($this->settings->get($this->scope, MetricsCredentialResetMaterial::RevisionKey))->toBeNull();
    expect($this->projection->apply($done, $this->completion)->sequence)->toBe($sequence);
    expect(Setting::query()->get()->toArray())->toBe($settings);
});

it('keeps pending private material recoverable after native failure or cancellation', function (string $status): void {
    $before = Setting::query()->get()->toArray();
    $data = [...$this->completion, 'status' => $status, 'result' => [], 'error_code' => $status === 'failed' ? 'metrics.credentials_reset_unverified' : null];

    $done = $this->projection->apply($this->operation, $data);

    expect($done->status->value)->toBe($status);
    expect(Setting::query()->get()->toArray())->toBe($before);
})->with(['failed', 'cancelled']);

it('preserves pending material when a native start cannot be recorded', function (string $kind): void {
    $this->operation->update(['kind' => $kind]);
    $active = $this->settings->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $pending = $this->settings->get($this->scope, NativeMetricsCredentialManager::PendingPasswordKey);

    expect(fn () => app(StartMetricsCredentialResetAction::class)->execute())->toThrow(ResourceOperationException::class, 'The native workflow start is not configured.');
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe($active);
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBe($pending);
    $failed = Operation::query()->latest('id')->firstOrFail();
    expect($failed->status)->toBe(OperationStatus::Failed)->and($failed->error_code)->toBe('swamp.start_unavailable');
    expect($failed->input['reset_revision'])->toBe($this->settings->get($this->scope, MetricsCredentialResetMaterial::RevisionKey));
})->with(['metrics.credentials_reset', 'metrics.reconcile', 'metrics.exporter_enable', 'metrics.exporter_disable']);

it('records one direct reset intent and keeps private pending material until native completion', function (): void {
    $this->operation->update(['status' => OperationStatus::Cancelled]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-metrics-credentials-reset', 'runId' => $this->run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'scoped-fixture']);
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'metrics.credentials_reset';
    $identity->key = 'reset-fixture-key';
    $identity->fingerprint = 'reset-fixture-input';

    $operation = app(StartMetricsCredentialResetAction::class)->execute();
    $replayed = app(StartMetricsCredentialResetAction::class)->execute();

    expect($operation->status)->toBe(OperationStatus::Running);
    expect($operation->state['swamp_run_id'])->toBe($this->run);
    expect($replayed->id)->toBe($operation->id);
    expect($operation->input['credential'])->toBe(['$secret' => 'metrics.credentials_reset', 'id' => $this->node->id]);
    expect($socket->sent)->toHaveCount(1);
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::ActivePasswordKey))->toBe('active-private-reset-sentinel');
    expect($this->settings->get($this->scope, NativeMetricsCredentialManager::PendingPasswordKey))->toBe('pending-private-reset-sentinel');
    expect($operation->input['reset_revision'])->toBe($this->settings->get($this->scope, MetricsCredentialResetMaterial::RevisionKey));
    expect(json_encode($operation->fresh()->toArray(), JSON_THROW_ON_ERROR))->not->toContain('private-reset-sentinel');
});
