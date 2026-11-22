<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use App\Domain\Shared\ResourceOperationException;
use Illuminate\Support\Facades\Config;

/** Feature switch and shared contract constants for Conn iOS push notifications. */
final class ConnPush
{
    public const int MaxEventsPerMinute = 120;

    public const int EventRetentionDays = 7;

    public const int MaxTitleLength = 64;

    /** @var list<string> */
    public const array Environments = ['sandbox', 'production'];

    /** @var list<string> */
    public const array EventTypes = ['attention', 'attention_cleared'];

    /** @var list<string> */
    public const array AttentionKinds = ['approval', 'input', 'turn_finished', 'turn_failed'];

    /** @var list<string> */
    public const array HighPriorityKinds = ['approval', 'input'];

    public static function enabled(): bool
    {
        return Config::boolean('orbit.conn.push');
    }

    public static function requireEnabled(): void
    {
        if (! self::enabled()) {
            throw new ResourceOperationException('conn.push_disabled', 'Conn push notifications are off.', 404);
        }
    }
}
