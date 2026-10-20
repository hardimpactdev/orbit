<?php

declare(strict_types=1);

namespace App\Domain\Releases;

use App\Infrastructure\Releases\HttpReleaseAlertWebhook;
use Illuminate\Container\Attributes\Bind;

/**
 * Posts one alert body to the configured receiver within a bounded time. It never throws.
 */
#[Bind(HttpReleaseAlertWebhook::class)]
interface ReleaseAlertWebhook
{
    /** @param array<string, mixed> $payload */
    public function send(array $payload): ReleaseAlertStep;
}
