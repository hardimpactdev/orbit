<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use Orbit\Core\Progress\ProgressEventType;
use RuntimeException;

class GatewayOperationFollower
{
    public function __construct(
        private readonly GatewayOperationEventStreamClient $events,
        private readonly int $reconnectSleepMs = 500,
        private readonly int $maxEmptyReplays = 0,
        private readonly int $maxTransientFailures = 120,
    ) {}

    /**
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}
     */
    public function follow(string $eventsUrl, callable $onEvent): array
    {
        $lastEventId = null;
        $seenEventIds = [];
        $emptyReplays = 0;
        $transientFailures = 0;

        while (true) {
            $sawEvent = false;

            try {
                $terminal = $this->events->replay(
                    $eventsUrl,
                    $lastEventId,
                    function (ProgressEventType $type, array $payload, ?int $eventId) use (&$lastEventId, &$seenEventIds, &$sawEvent, $onEvent): void {
                        if ($eventId !== null) {
                            if (isset($seenEventIds[$eventId])) {
                                return;
                            }

                            $seenEventIds[$eventId] = true;
                            $lastEventId = max($lastEventId ?? 0, $eventId);
                        }

                        $sawEvent = true;
                        $onEvent($type, $payload);
                    },
                );
                $transientFailures = 0;
            } catch (GatewayApiException $exception) {
                if (! $this->shouldRetry($exception) || $transientFailures >= $this->maxTransientFailures) {
                    throw $exception;
                }

                $transientFailures++;
                $this->sleepBeforeReconnect();

                continue;
            }

            if ($terminal !== null) {
                return $terminal;
            }

            $emptyReplays = $sawEvent ? 0 : $emptyReplays + 1;

            if ($this->maxEmptyReplays > 0 && $emptyReplays >= $this->maxEmptyReplays) {
                throw GatewayApiException::streamClosedBeforeTerminal(
                    new RuntimeException('Operation event replay did not reach a terminal frame.'),
                );
            }

            $this->sleepBeforeReconnect();
        }
    }

    private function shouldRetry(GatewayApiException $exception): bool
    {
        if ($exception->failureKind() === GatewayApiFailureKind::Network || $exception->failureKind() === GatewayApiFailureKind::WireguardUnreachable) {
            return true;
        }

        if ($exception->failureKind() !== GatewayApiFailureKind::Http) {
            return false;
        }

        return in_array($exception->statusCode(), [502, 503, 504], true);
    }

    private function sleepBeforeReconnect(): void
    {
        if ($this->reconnectSleepMs <= 0) {
            return;
        }

        usleep($this->reconnectSleepMs * 1000);
    }
}
