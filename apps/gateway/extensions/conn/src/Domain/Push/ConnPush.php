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

    public const string SandboxHost = 'https://api.sandbox.push.apple.com';

    public const string ProductionHost = 'https://api.push.apple.com';

    /** Apple allows up to 60 minutes; renew at 40 minutes for margin. */
    public const int JwtLifetimeSeconds = 2400;

    public const int ExpirationSeconds = 3600;

    public const int StaleEventMaxAgeSeconds = 3600;

    public const int ThreadAlertCooldownSeconds = 30;

    public const int MaxAlertsPerDevicePerHour = 30;

    public const int MaxSendAttempts = 3;

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
