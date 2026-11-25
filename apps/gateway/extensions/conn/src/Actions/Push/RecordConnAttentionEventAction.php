<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Actions\Push;

use App\Domain\Shared\ResourceOperationException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;
use Orbit\Extensions\Conn\Jobs\SendConnPushJob;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnThread;

/**
 * Validates server/thread ownership, enforces the per-server rate limit, deduplicates by event_id, stores the attention
 * event, and queues SendConnPushJob.
 */
final readonly class RecordConnAttentionEventAction
{
    /**
     * @param  array{
     *     event_id: string,
     *     type: string,
     *     kind: ?string,
     *     environment_id: string,
     *     thread_id: string,
     *     request_id: ?string,
     *     occurred_at: CarbonImmutable,
     *     thread_title: ?string
     * }  $payload
     * @return array{event_id: string, accepted: bool, deduplicated: bool}
     */
    public function execute(ConnEnvironment $environment, array $payload): array
    {
        if ($payload['environment_id'] !== $environment->environment_id) {
            throw ValidationException::withMessages([
                'environment_id' => ['The environment_id must match the authenticated server environment.'],
            ]);
        }

        $belongsElsewhere = static fn (Builder $threads): bool => $threads
            ->where('thread_id', $payload['thread_id'])
            ->where('conn_environment_id', '!=', $environment->id)
            ->exists();
        if ($belongsElsewhere(ConnThread::query()) || $belongsElsewhere(ConnThread::hostQuery())) {
            throw ValidationException::withMessages([
                'thread_id' => ['The thread does not belong to this environment.'],
            ]);
        }

        $rateKey = 'conn:push:attention:'.$environment->id;
        if (RateLimiter::tooManyAttempts($rateKey, ConnPush::MaxEventsPerMinute)) {
            throw new ResourceOperationException(
                errorCode: 'conn.attention_rate_limited',
                message: 'Too many attention events for this server.',
                status: 429,
                details: ['retry_after' => RateLimiter::availableIn($rateKey)],
            );
        }
        RateLimiter::hit($rateKey, 60);

        $eventId = $payload['event_id'];
        if (ConnAttentionEvent::query()->where('event_id', $eventId)->exists()) {
            return ['event_id' => $eventId, 'accepted' => true, 'deduplicated' => true];
        }

        try {
            $event = ConnAttentionEvent::query()->create([
                'event_id' => $eventId,
                'conn_environment_id' => $environment->id,
                'environment_id' => $environment->environment_id,
                'thread_id' => $payload['thread_id'],
                'type' => $payload['type'],
                'kind' => $payload['kind'],
                'request_id' => $payload['request_id'],
                'thread_title' => $payload['thread_title'],
                'occurred_at' => $payload['occurred_at'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return ['event_id' => $eventId, 'accepted' => true, 'deduplicated' => true];
        }

        SendConnPushJob::dispatch($event->id)->afterCommit();

        return ['event_id' => $eventId, 'accepted' => true, 'deduplicated' => false];
    }
}
