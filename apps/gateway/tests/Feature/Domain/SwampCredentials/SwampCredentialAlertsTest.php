<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\SwampCredentials\SwampCredentialAlerts;
use App\Models\Activity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-alert',
        'orbit.releases.alert_webhook_secret' => 'fixture-signing-key']);
    Http::fake(['https://hooks.example.test/credential-alert' => Http::response('', 200)]);
});

/** Fixture runs start in this order, one hour apart. */
function credentialRun(string $status = 'succeeded', string $id = '0e0c6b55-3a6e-4f57-9b5c-2b8f0f6b8d11'): array
{
    $order = ['0e0c6b55-3a6e-4f57-9b5c-2b8f0f6b8d11', '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01',
        '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c', 'c3d4e5f6-0a1b-4c2d-8e3f-4a5b6c7d8e9f'];

    return ['id' => $id, 'status' => $status,
        'startedAt' => sprintf('2026-11-02T%02d:07:00.000Z', 6 + array_search($id, $order, true))];
}

function credentialAlert(string $name, string $reason, string $expiresAt = '2026-11-02T12:46:21.024000+00:00'): array
{
    return ['kind' => 'access', 'name' => $name, 'reason' => $reason, 'expires_at' => $expiresAt, 'hours_left' => 5.6];
}

it('declares the native schedule as one standalone scheduled result', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[SwampCredentialAlerts::Workflow])->toBe([
        'workflow' => 'swamp-credential-monitor', 'terminal_result' => ['job' => 'monitor', 'step' => 'check'],
        'projection' => SwampCredentialAlerts::class, 'workflow_id' => SwampCredentialAlerts::Workflow,
    ]);
});

it('sends one signed alert per credential expiry and reason', function (): void {
    $result = ['alerts' => [credentialAlert('orbit-node-13-access', 'within_72h'), ['reason' => 'invented', 'kind' => 'x', 'name' => 'y']],
        'credentials' => [['name' => 'never-forwarded']]];
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), $result);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Orbit-Timestamp')[0];

        return $request['event'] === 'swamp.credential_expiring'
            && $request['credentials'] === [credentialAlert('orbit-node-13-access', 'within_72h')]
            && ! str_contains($request->body(), 'never-forwarded') && ! str_contains($request->body(), 'invented')
            && $request->header('X-Orbit-Signature') === ['sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'fixture-signing-key')];
    });
    expect(Activity::query()->where('command', 'swamp:credential-alert')->sole()->status)->toBe('succeeded');

    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'),
        ['alerts' => [credentialAlert('orbit-node-13-access', 'within_6h')]]);
    Http::assertSentCount(2);
});

it('sends an undelivered alert again on the next run', function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-retry']);
    Http::fake(['https://hooks.example.test/credential-retry' => Http::sequence()->push('', 500)->push('', 200)]);
    $result = ['alerts' => [credentialAlert('orbit-node-9-access', 'within_72h')]];
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), $result);

    Http::assertSentCount(2);
    expect(Activity::query()->where('command', 'swamp:credential-alert')->pluck('status')->all())->toBe(['failed', 'succeeded']);
});

it('stays silent when no credential needs attention', function (): void {
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), ['alerts' => []]);

    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'swamp:credential-alert')->exists())->toBeFalse();
});

it('alerts once per failure streak and once on recovery', function (string $status): void {
    $alerts = app(SwampCredentialAlerts::class);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun($status), []);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun($status), []);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun($status, '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), []);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'swamp.credential_monitor_failed'
        && $request['status'] === $status && $request['root_run_id'] === credentialRun()['id']);

    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'), ['alerts' => []]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: 'c3d4e5f6-0a1b-4c2d-8e3f-4a5b6c7d8e9f'), ['alerts' => []]);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'swamp.credential_monitor_recovered');
})->with(['failed', 'cancelled', 'interrupted']);

it('retries an undelivered failure alert on the next failed run', function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-retry']);
    Http::fake(['https://hooks.example.test/credential-retry' => Http::sequence()->push('', 500)->push('', 200)]);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun('failed'), []);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun('failed', '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), []);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun('failed', '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'), []);

    Http::assertSentCount(2);
});

it('forwards a missing expected credential', function (): void {
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(),
        ['alerts' => [['kind' => 'enrollment', 'name' => 'orbit-node-209', 'reason' => 'missing', 'expires_at' => null, 'hours_left' => null]]]);

    Http::assertSent(fn (Request $request): bool => $request['event'] === 'swamp.credential_expiring'
        && $request['credentials'] === [['kind' => 'enrollment', 'name' => 'orbit-node-209', 'reason' => 'missing', 'expires_at' => null, 'hours_left' => null]]);
});

it('projects a replayed run once', function (): void {
    $alerts = app(SwampCredentialAlerts::class);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), ['alerts' => [credentialAlert('orbit-node-9-access', 'within_72h')]]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), ['alerts' => [credentialAlert('orbit-node-13-access', 'within_72h')]]);

    Http::assertSentCount(1);
});

it('retries an undelivered recovery alert on the next run', function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-recovery']);
    Http::fake(['https://hooks.example.test/credential-recovery' => Http::sequence()->push('', 200)->push('', 500)->push('', 200)]);
    $alerts = app(SwampCredentialAlerts::class);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun('failed'), []);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), ['alerts' => []]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'), ['alerts' => []]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: 'c3d4e5f6-0a1b-4c2d-8e3f-4a5b6c7d8e9f'), ['alerts' => []]);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request['event'] === 'swamp.credential_monitor_recovered');
});

it('keeps retrying while the webhook is not configured', function (): void {
    config(['orbit.releases.alert_webhook_url' => null, 'orbit.releases.alert_webhook_secret' => null]);
    $result = ['alerts' => [credentialAlert('orbit-node-9-access', 'within_72h')]];
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-alert',
        'orbit.releases.alert_webhook_secret' => 'fixture-signing-key']);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), $result);

    Http::assertSentCount(1);
});

it('sends nothing for a replayed or older run after a reader reset and a time jump', function (): void {
    $alerts = app(SwampCredentialAlerts::class);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'),
        ['alerts' => [credentialAlert('orbit-node-9-access', 'within_6h')]]);
    Http::assertSentCount(1);

    $this->travel(31)->days();
    Cache::flush();
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'),
        ['alerts' => [credentialAlert('orbit-node-9-access', 'within_6h')]]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'),
        ['alerts' => [credentialAlert('orbit-node-13-access', 'within_6h')]]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun('failed'), []);

    Http::assertSentCount(1);
});

it('alerts a condition again after it cleared', function (): void {
    $missing = ['alerts' => [['kind' => 'enrollment', 'name' => 'orbit-node-209', 'reason' => 'missing', 'expires_at' => null, 'hours_left' => null]]];
    $alerts = app(SwampCredentialAlerts::class);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $missing);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '6a1d2a1e-0f4b-4c55-8d0e-3b1f8f2c9a01'), $missing);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: '9b7e4f0c-2d3a-4e1b-a6c5-7f8e9d0a1b2c'), ['alerts' => []]);
    $alerts->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(id: 'c3d4e5f6-0a1b-4c2d-8e3f-4a5b6c7d8e9f'), $missing);

    Http::assertSentCount(2);
});
