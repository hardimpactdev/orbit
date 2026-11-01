<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\SwampCredentials\SwampCredentialAlerts;
use App\Models\Activity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['orbit.releases.alert_webhook_url' => 'https://hooks.example.test/credential-alert',
        'orbit.releases.alert_webhook_secret' => 'fixture-signing-key']);
    Http::fake(['https://hooks.example.test/credential-alert' => Http::response('', 200)]);
});

function credentialRun(string $status = 'succeeded'): array
{
    return ['id' => '0e0c6b55-3a6e-4f57-9b5c-2b8f0f6b8d11', 'status' => $status];
}

function credentialAlert(string $name, string $reason, string $expiresAt = '2026-11-02T12:46:21.024000+00:00'): array
{
    return ['kind' => 'access', 'name' => $name, 'reason' => $reason, 'expires_at' => $expiresAt, 'hours_left' => 5.6];
}

it('declares the native schedule as one standalone scheduled result', function (): void {
    expect(app(DirectOperation::class)->scheduledResultDefinitions()[SwampCredentialAlerts::Workflow])->toBe([
        'workflow' => 'swamp-credential-monitor', 'terminal_result' => ['job' => 'monitor', 'step' => 'renew'],
        'projection' => SwampCredentialAlerts::class, 'workflow_id' => SwampCredentialAlerts::Workflow,
    ]);
});

it('sends one signed alert per credential expiry and reason', function (): void {
    $result = ['alerts' => [credentialAlert('orbit-node-13-access', 'within_72h'), ['reason' => 'invented', 'kind' => 'x', 'name' => 'y']],
        'credentials' => [['name' => 'never-forwarded']]];
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), $result);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $timestamp = $request->header('X-Orbit-Timestamp')[0];

        return $request['event'] === 'swamp.credential_expiring'
            && $request['credentials'] === [credentialAlert('orbit-node-13-access', 'within_72h')]
            && ! str_contains($request->body(), 'never-forwarded') && ! str_contains($request->body(), 'invented')
            && $request->header('X-Orbit-Signature') === ['sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'fixture-signing-key')];
    });
    expect(Activity::query()->where('command', 'swamp:credential-alert')->sole()->status)->toBe('succeeded');

    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(),
        ['alerts' => [credentialAlert('orbit-node-13-access', 'within_6h')]]);
    Http::assertSentCount(2);
});

it('stays silent when no credential needs attention', function (): void {
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun(), ['alerts' => []]);

    Http::assertNothingSent();
    expect(Activity::query()->where('command', 'swamp:credential-alert')->exists())->toBeFalse();
});

it('alerts when the monitor run itself does not succeed', function (string $status): void {
    app(SwampCredentialAlerts::class)->applyScheduled(SwampCredentialAlerts::Workflow, credentialRun($status), []);

    Http::assertSent(fn (Request $request): bool => $request['event'] === 'swamp.credential_monitor_failed'
        && $request['status'] === $status && $request['root_run_id'] === credentialRun()['id']);
})->with(['failed', 'cancelled', 'interrupted']);
