<?php

declare(strict_types=1);

namespace App\Domain\SwampCredentials;

use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Releases\ReleaseAlertOutcome;
use App\Domain\Releases\ReleaseAlertWebhook;
use App\Domain\Settings\SettingScopeType;
use App\Models\Activity;
use App\Models\Setting;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Sends the scheduled read-only credential check's alerts through the signed release webhook. */
final readonly class SwampCredentialAlerts implements ScheduledResultProjection
{
    public const string Workflow = '393024cd-986d-46f7-bf26-2a500281d492';

    public const string StateKey = 'swamp.credential-monitor';

    private const array Reasons = ['within_72h', 'within_6h', 'expired', 'missing'];

    public function __construct(private ReleaseAlertWebhook $webhook) {}

    public function activate(): void {}

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new LogicException('Unknown Swamp credential schedule.');
        }
        $runId = Arr::string($run, 'id');
        $startedAt = Carbon::parse(Arr::string($run, 'startedAt'));
        DB::transaction(function () use ($run, $result, $runId, $startedAt): void {
            $record = $this->state();
            $state = ValidatedData::object(json_decode($record->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
            $previous = ValidatedData::object($state['terminal'] ?? []);
            // Durable replay marker: a replayed or older run, after any reader reset, changes nothing.
            if (($previous['run_id'] ?? null) === $runId || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo($startedAt))) {
                return;
            }
            $failing = ($state['failing'] ?? false) === true;
            $delivered = array_filter(ValidatedData::object($state['delivered'] ?? []), is_string(...));
            if (($run['status'] ?? null) !== 'succeeded') {
                // One failure alert per failure streak; an undelivered one is sent again by the next failed run.
                $status = is_string($run['status'] ?? null) ? $run['status'] : 'unknown';
                $failing = $failing || $this->send(['event' => 'swamp.credential_monitor_failed', 'root_run_id' => $runId, 'status' => $status,
                    'text' => sprintf('The Swamp credential monitor run %s ended %s; credential expiry is unchecked.', $runId, $status)]);
            } else {
                if ($failing) {
                    $failing = ! $this->send(['event' => 'swamp.credential_monitor_recovered', 'root_run_id' => $runId,
                        'text' => sprintf('The Swamp credential monitor run %s succeeded again.', $runId)]);
                }
                $delivered = $this->expiring($runId, $result, $delivered);
            }
            $record->value = json_encode(['terminal' => ['run_id' => $runId, 'started_at' => $startedAt->toISOString()],
                'failing' => $failing, 'delivered' => $delivered === [] ? (object) [] : $delivered], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
        });
    }

    /**
     * Keeps a delivered key only while its condition is still reported, so a cleared and returning condition alerts again.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,string>  $delivered
     * @return array<string,string>
     */
    private function expiring(string $runId, array $result, array $delivered): array
    {
        $current = [];
        foreach (array_slice(is_array($result['alerts'] ?? null) ? $result['alerts'] : [], 0, 64) as $alert) {
            if (! is_array($alert) || ! in_array($alert['reason'] ?? null, self::Reasons, true)
                || ! is_string($alert['kind'] ?? null) || ! is_string($alert['name'] ?? null)) {
                continue;
            }
            $alert = ['kind' => Str::limit($alert['kind'], 32, ''), 'name' => Str::limit($alert['name'], 128, ''),
                'reason' => $alert['reason'], 'expires_at' => is_string($alert['expires_at'] ?? null) ? Str::limit($alert['expires_at'], 40, '') : null,
                'hours_left' => is_int($alert['hours_left'] ?? null) || is_float($alert['hours_left'] ?? null) ? $alert['hours_left'] : null];
            // One delivered notification per credential, expiry and reason; a renewed credential has a new expiry.
            $current[hash('sha256', implode('|', [$alert['kind'], $alert['name'], $alert['expires_at'], $alert['reason']]))] = $alert;
        }
        $kept = array_intersect_key($delivered, $current);
        $pending = array_diff_key($current, $kept);
        if ($pending !== [] && $this->send(['event' => 'swamp.credential_expiring', 'root_run_id' => $runId, 'credentials' => array_values($pending),
            'text' => 'Swamp credentials need attention: '.implode(', ', array_map(
                static fn (array $alert): string => sprintf('%s %s (%s, expires %s)', $alert['kind'], $alert['name'], $alert['reason'], $alert['expires_at'] ?? 'unknown'),
                $pending)).'.'])) {
            $kept += array_fill_keys(array_keys($pending), $runId);
        }

        return $kept;
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

            // Only a delivered webhook ends a retry; a skipped one (not configured) does not.
            return $receipt->outcome === ReleaseAlertOutcome::Done;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => Setting::query()->where('scope_type', SettingScopeType::Gateway)
            ->where('scope_id', 0)->where('key', self::StateKey)->lockForUpdate()->firstOrCreate([
                'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
            ], ['value' => '{}', 'is_secret' => false]));
    }
}
