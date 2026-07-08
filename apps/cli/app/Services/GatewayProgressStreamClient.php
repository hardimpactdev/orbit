<?php

declare(strict_types=1);

namespace App\Services;

use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\ProgressEventType;

interface GatewayProgressStreamClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     * @param  callable(): void|null  $onIdle
     *
     * @mago-ignore lint:excessive-parameter-list
     */
    public function streamEvents(
        string $path,
        array $payload,
        callable $onEvent,
        string $method = 'post',
        ?callable $onIdle = null,
        int $idleIntervalMicroseconds = ForkedFrameTicker::DEFAULT_INTERVAL_MICROSECONDS,
    ): int;
}
