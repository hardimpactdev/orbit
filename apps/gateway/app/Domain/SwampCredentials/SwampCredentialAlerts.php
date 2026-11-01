<?php

declare(strict_types=1);

namespace App\Domain\SwampCredentials;

use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Models\Activity;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Sends the scheduled native credential check's alerts through the signed release webhook. Swamp decides and renews. */
final readonly class SwampCredentialAlerts implements ScheduledResultProjection
{
    public const string Workflow = '393024cd-986d-46f7-bf26-2a500281d492';

    private const array Reasons = ['within_72h', 'within_6h', 'expired', 'expired_renewed', 'renewal_failed'];

    public function __construct(private ReleaseAlertWebhook $webhook) {}

    public function activate(): void {}

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new LogicException('Unknown Swamp credential schedule.');
        }
        $runId = is_string($run['id'] ?? null) ? $run['id'] : null;
        // The shared reader may hand the same terminal run over again after a reconnect.
        if ($runId === null || ! Cache::add('swamp-credential-run:'.$runId, true, now()->addDays(30))) {
            return;
        }
        if (($run['status'] ?? null) !== 'succeeded') {
            $status = is_string($run['status'] ?? null) ? $run['status'] : 'unknown';
            $this->send(['event' => 'swamp.credential_monitor_failed', 'root_run_id' => $runId, 'status' => $status,
                'text' => sprintf('The Swamp credential monitor run %s ended %s; credential expiry is unchecked.', $runId, $status)]);

            return;
        }
        $alerts = [];
        foreach (array_slice(is_array($result['alerts'] ?? null) ? $result['alerts'] : [], 0, 64) as $alert) {
            if (! is_array($alert) || ! in_array($alert['reason'] ?? null, self::Reasons, true)
                || ! is_string($alert['kind'] ?? null) || ! is_string($alert['name'] ?? null)) {
                continue;
            }
            $alert = ['kind' => Str::limit($alert['kind'], 32, ''), 'name' => Str::limit($alert['name'], 128, ''),
                'reason' => $alert['reason'], 'expires_at' => is_string($alert['expires_at'] ?? null) ? Str::limit($alert['expires_at'], 40, '') : null,
                'hours_left' => is_int($alert['hours_left'] ?? null) || is_float($alert['hours_left'] ?? null) ? $alert['hours_left'] : null];
            // One delivered notification per credential, expiry and reason; a renewed credential has a new expiry.
            $key = 'swamp-credential-alert:'.hash('sha256', implode('|', [$alert['kind'], $alert['name'], $alert['expires_at'], $alert['reason']]));
            if (! Cache::has($key)) {
                $alerts[$key] = $alert;
            }
        }
        if ($alerts === []) {
            return;
        }
        $delivered = $this->send(['event' => 'swamp.credential_expiring', 'root_run_id' => $runId, 'credentials' => array_values($alerts),
            'text' => 'Swamp credentials need attention: '.implode(', ', array_map(
                static fn (array $alert): string => sprintf('%s %s (%s, expires %s)', $alert['kind'], $alert['name'], $alert['reason'], $alert['expires_at'] ?? 'unknown'),
                $alerts)).'.']);
        if ($delivered) {
            foreach (array_keys($alerts) as $key) {
                Cache::put($key, true, now()->addDays(30));
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function send(array $payload): bool
    {
        $payload = [...$payload, 'occurred_at' => now()->toISOString(), 'request_id' => (string) Str::uuid()];
        $activity = null;
        try {
            $activity = Activity::query()->create(['log_name' => 'swamp', 'description' => $payload['event'],
                'command' => 'swamp:credential-alert', 'request_id' => $payload['request_id'],
                'status' => 'running', 'properties' => $payload]);
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            $receipt = $this->webhook->send($payload);
            $activity?->update(['status' => $receipt->failedStep() ? 'failed' : 'succeeded',
                'error_code' => $receipt->failedStep() ? 'swamp.credential_alert_webhook_failed' : null,
                'properties' => [...$payload, 'webhook' => $receipt->toArray()]]);

            return ! $receipt->failedStep();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
